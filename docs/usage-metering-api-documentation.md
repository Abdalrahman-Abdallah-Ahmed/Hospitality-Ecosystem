# Usage Metering API Documentation

WP-8 of Phase 2 v2.0. The system now records **what each account used**, so
that a future pricing decision is made against real data rather than a guess.

This phase **observes only**. Nothing here gates, blocks, or limits any
request, and no endpoint returns 402. Enforcement is WP-9 and is deferred.

## The rule that shapes the schema

> Store facts. Never store prices.

A meter event records *what happened*, never *what it was worth*. There is no
price column and no `subscription_id` on `meter_events`, and a test asserts
their absence. Writing a charge onto the event would make every historical row
wrong the moment pricing changes, and unrecoverable, because the quantity
would be buried under a stale rate.

Keep quantity and price apart and any future pricing model — flat per
property, per AI message, per booking, tiered with overage, or a bespoke
enterprise deal — reads these same rows and works immediately, with history.

## Tables

| Table | Role |
|---|---|
| `meter_events` | Append-only. The source of truth. No update or delete path — the model throws on both. |
| `usage_counters` | A derived cache of period totals. Disposable: delete it and the nightly job rebuilds it. |

`period_start` is the first day of the calendar month. **This is a
placeholder, not a decision** — when subscriptions exist, periods come from
the subscription's own dates and stop being calendar-aligned.

## What is metered

Feature codes are permanent: once written to `meter_events`, a code is never
renamed, or the history stops adding up. They live in `App\Enums\MeterFeature`.

### Cost drivers — what we pay for

| Code | Recorded at |
|---|---|
| `ai_messages` | `ProcessInboundWhatsAppMessageJob`, `AiAdvisorController@chat` |
| `ai_insights_generated` | `CreateAiInsightsJob` |
| `recommendations_generated` | `GenerateActivityRecommendationsJob` |
| `embeddings_generated` | `SyncKnowledgeChunksJob` |

`embeddings_generated` is the one people forget: no visible output, real
money, and re-run in full every time an article is edited.

### Value signals — what the customer gets

| Code | Recorded at |
|---|---|
| `bookings_created` | `BookingService::create()` |
| `bookings_realised` | `BookingService::realise()` |
| `transaction_rows_imported` | `TransactionsImport` |

Nothing bills or gates on these. They accumulate so the eventual "volume or
bookings?" pricing question has evidence behind it.

### Scale — what exists right now

`properties`, `users`, `guests`, `stays`.

**Seats are recounted, never incremented.** They are read back from the tables
that own them, so a deleted hotel actually leaves the count. `properties` and
`users` recount on create and delete; all four are recounted nightly.
Calling `record()` with a seat feature throws.

## Not measured, and why

Two features are declared in the catalogue but never written. They are named
rather than omitted so the endpoint can return an explicit gap: **a meter
reading zero and a meter that does not exist are different facts.**

- **`recommendations_delivered`** — delivery is *inferred* today, not
  measured. `/api/analytics/conversion` treats a recommendation as delivered
  unless an outcome explicitly records `not_delivered`, and labels that
  assumption in its notes. That is fine in a report that says so; it is not
  fine in a usage table, where an estimated number becomes a disputed invoice
  later. Needs the measured delivery timestamp from P1-002 A-1.
- **`conversations_handled`** — needs a definition of when a conversation
  ends. `agent_conversations` has no status column and `ConversationStatus`
  is unused, so no such moment exists yet.

Until the first is measured, the generated-vs-delivered pair — the one that
separates "our agent is weak" from "the hotel's staff never passed it on" —
cannot be completed.

## Endpoint

`GET /api/admin/usage?from=2026-08-01&to=2026-08-31`

Both parameters optional: `from` defaults to the start of the current month,
`to` to now. Filters on the event's `occurred_at`.

Headers: `X-API-KEY`, `Authorization: Bearer …`, `Accept: application/json`.

**Super admin only** (`super_admin` middleware). It reports across every
account, so a per-model policy is the wrong shape — there is no single model
to authorise against. Anyone else gets 403.

Totals are summed from `meter_events`, not read from the counters: counters
are keyed by whole periods and this endpoint answers about an arbitrary range.
Seats come from the counter, since they are not event-derived.

### Response

```json
{
  "message": "Usage fetched successfully.",
  "code": 200,
  "body": {
    "from": "2026-08-01",
    "to": "2026-08-31",
    "accounts": [
      {
        "hotel_group_id": "01a07d8e-...",
        "account": "Domina Group",
        "seats": { "properties": 3, "users": 24, "guests": 1180, "stays": 402 },
        "features": {
          "ai_messages": { "used": 8241, "unit": "messages" },
          "recommendations_generated": { "used": 412, "unit": "recommendations" },
          "bookings_created": { "used": 96, "unit": "bookings" },
          "embeddings_generated": { "used": 1340, "unit": "chunks" }
        },
        "recommendations": {
          "generated": 412,
          "delivered": null,
          "delivered_basis": "not measured",
          "delivered_reason": "Delivery is inferred, not measured: …"
        }
      }
    ],
    "not_measured": {
      "recommendations_delivered": "…",
      "conversations_handled": "…"
    }
  }
}
```

`delivered` is an explicit `null` with a reason, never a zero.

## Recording usage in code

```php
$metering->safely(fn (MeteringService $m) => $m->recordForHotel(
    hotel: $hotel,
    feature: MeterFeature::AI_MESSAGES,
    source: $guest,
    idempotencyKey: 'whatsapp:'.$messageId,
));
```

Three rules:

1. **Always go through `safely()`.** It catches and reports, never rethrows.
   If metering fails, the guest's WhatsApp message must still be answered: a
   lost meter event costs cents, an unanswered guest costs a customer.
   Keeping the try/catch inside the service means no new call site can forget
   it.
2. **One event per batch, never one per row.** A 10,000-row import records a
   single event with `quantity = 10000`. `meter_events` will become one of
   the largest tables in the system.
3. **Pass an `idempotencyKey` where a replay is possible.** A repeat returns
   `null` and neither the event nor the counter moves. Uniqueness is
   `(hotel_group_id, idempotency_key)`; Postgres treats NULL keys as distinct,
   so events without one are unaffected.

## Counters and the nightly rebuild

Counters are maintained two ways, and both are needed:

- **Incrementally** — each event bumps its counter in the same transaction,
  via an atomic `ON CONFLICT … used = used + excluded.used`, so two concurrent
  events both land.
- **Nightly** — `RebuildUsageCountersJob` (04:00) recomputes every counter
  from the events behind it and overwrites it, then recounts seats.

The rebuild is the safety net, and it **logs any discrepancy it finds** rather
than quietly correcting it. Drift between the two is a bug worth knowing
about. A counter with no events left behind it is reset to zero rather than
keeping its last value.

The job is idempotent: it overwrites with a computed total rather than
adjusting, so running it twice is the same as running it once.
