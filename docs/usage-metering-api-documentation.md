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

`properties`, `users`, `guests`, `stays`. `properties` counts rows in the
`hotels` table and is **published as `hotels`** — see
[Reading the response](#reading-the-response).

**Seats are recounted, never incremented.** They are read back from the tables
that own them, so a deleted hotel actually leaves the count. Calling
`record()` with a seat feature throws.

`properties` and `users` recount on create, delete, **and reassignment** —
a user or hotel moving between accounts recounts *both* sides, the one gained
and the one left. The last part matters more than it sounds: a user is
normally attached to their hotel after both already exist, so recounting only
on `created` left every account reading zero users until the nightly job
caught up. All four seats are recounted nightly regardless, which is the
safety net rather than the mechanism.

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

## Endpoints

There are two, answering the same question for different readers. They share
one aggregation (`App\Services\Metering\UsageReport`) so their numbers can
never disagree — if a hotel's own figure differed from the figure we quote
them, the argument that follows is not one anybody wins.

| Route | Reader | Scope | Declared in |
|---|---|---|---|
| `GET /api/admin/usage` | Super admin | Every account | `routes/admin.php` |
| `GET /api/usage` | Hotel-group admin | Their own account only | `routes/api.php` |

Cross-account routes live in their own file, registered in
`bootstrap/app.php` with the `super_admin` guard applied to the whole file
rather than to a group inside it — so a route added there is protected by
construction and cannot be left exposed by forgetting to nest it.

### `GET /api/admin/usage?from=2026-08-01&to=2026-08-31`

Both parameters optional: `from` defaults to the start of the current month,
`to` to now. Filters on the event's `occurred_at`.

Headers: `X-API-KEY`, `Authorization: Bearer …`, `Accept: application/json`.

**Super admin only** (`super_admin` middleware). It reports across every
account, so a per-model policy is the wrong shape — there is no single model
to authorise against. Anyone else gets 403.

Totals are summed from `meter_events`, not read from the counters: counters
are keyed by whole periods and this endpoint answers about an arbitrary range.
Seats come from the counter, since they are not event-derived.

#### Response

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
        "seats": {
          "hotels": { "code": "properties", "label": "Hotels", "used": 3, "unit": "hotels", "measured": true },
          "users":  { "code": "users", "label": "Users", "used": 24, "unit": "users", "measured": true },
          "guests": { "code": "guests", "label": "Guests", "used": 1180, "unit": "guests", "measured": true },
          "stays":  { "code": "stays", "label": "Stays", "used": 402, "unit": "stays", "measured": true }
        },
        "features": {
          "ai_messages": { "code": "ai_messages", "label": "AI messages", "category": "cost_driver", "used": 8241, "unit": "messages", "measured": true },
          "ai_insights_generated": { "code": "ai_insights_generated", "label": "AI insights", "category": "cost_driver", "used": 0, "unit": "insights", "measured": true },
          "recommendations_generated": { "code": "recommendations_generated", "label": "Recommendations generated", "category": "cost_driver", "used": 412, "unit": "recommendations", "measured": true },
          "embeddings_generated": { "code": "embeddings_generated", "label": "Knowledge base indexing", "category": "cost_driver", "used": 1340, "unit": "chunks", "measured": true },
          "recommendations_delivered": { "code": "recommendations_delivered", "label": "Recommendations delivered", "category": "value_signal", "used": null, "unit": "recommendations", "measured": false },
          "bookings_created": { "code": "bookings_created", "label": "Bookings created", "category": "value_signal", "used": 96, "unit": "bookings", "measured": true }
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

### `GET /api/usage?from=2026-09-01&to=2026-09-30`

The tenant-facing view: what **this** account used. Same parameters, same
defaults, same shape for `seats` and `features`, same `not_measured` gaps —
one account instead of an `accounts[]` array, so `hotel_group_id`, `seats` and
`features` sit at the top level of the body.

Open to a hotel-group **admin** or a holder of a `group_role`. An employee
gets 403: they work in a hotel, they do not represent the customer, and
account-level consumption is commercial information about the account. A user
belonging to no account gets 403 as well, with a message saying so.

Two properties of this route matter more than its shape, and both are tested:

**The account comes from the token, never from the request.** There is no
`hotel_group_id` parameter, and supplying one changes nothing — a filter that
can be supplied is a filter that can be changed, and the first person to try a
different UUID would be reading another hotel's numbers.

**It never returns cost.** `ai_usage_logs` is not read here at all. Provider
cost is our cost of goods, and an account that can see what it costs to serve
can compute our margin on its own contract — that belongs in a commercial
conversation, not a dashboard field. A test asserts the response body contains
no mention of cost, margin, or contract.

It also returns **no plan and no limit**. There are no plans yet (WP-6 is
deferred), and showing a limit that nothing enforces teaches people to trust a
number that is not load-bearing. A usage figure with a quota beside it is a
WP-9 conversation.

#### Response

```json
{
  "message": "Usage fetched successfully.",
  "code": 200,
  "body": {
    "from": "2026-09-01",
    "to": "2026-09-30",
    "hotel_group_id": "01a08cc8-9b49-7130-aff8-62eb0342df2a",
    "account": "Grand Harbor Hotel",
    "seats": {
      "hotels": { "code": "properties", "label": "Hotels", "used": 1, "unit": "hotels", "measured": true },
      "users":  { "code": "users", "label": "Users", "used": 4, "unit": "users", "measured": true },
      "guests": { "code": "guests", "label": "Guests", "used": 128, "unit": "guests", "measured": true },
      "stays":  { "code": "stays", "label": "Stays", "used": 61, "unit": "stays", "measured": true }
    },
    "features": {
      "ai_messages": { "code": "ai_messages", "label": "AI messages", "category": "cost_driver", "used": 8241, "unit": "messages", "measured": true },
      "bookings_created": { "code": "bookings_created", "label": "Bookings created", "category": "value_signal", "used": 96, "unit": "bookings", "measured": true }
    },
    "recommendations": {
      "generated": 142,
      "delivered": null,
      "delivered_basis": "not measured",
      "delivered_reason": "…"
    },
    "not_measured": { "recommendations_delivered": "…", "conversations_handled": "…" }
  }
}
```

## Reading the response

Both endpoints return `seats` and `features` in the same shape, built by
`App\Services\Metering\UsageReport`. Several of these fields exist purely to
stop a number being misread.

| Field | Meaning |
|---|---|
| *(the key)* | The public name. Usually the feature code; see below. |
| `code` | The permanent code, exactly as stored in `meter_events.feature_code`. |
| `label` | A display name. Safe to reword — nothing is keyed on it. |
| `category` | `cost_driver` or `value_signal` (features only). |
| `used` | The figure, or `null` when nothing is counting it. |
| `unit` | What is being counted: messages, chunks, bookings. |
| `measured` | `false` means no source exists yet — see below. |

### `properties` is published as `hotels`

`properties` is the hospitality word for a single hotel, and it counts rows in
the `hotels` table and nothing else. Outside the industry it reads as real
estate, so the response publishes it under the key **`hotels`**.

The stored code does **not** change and cannot: it is written into every
`meter_events` row, and renaming it would orphan the history. So both names
travel — `hotels` as the key, `properties` as `code` — and a figure on a
dashboard can still be traced back to the meter behind it. A rename that hides
its own provenance is how a reporting layer stops being reconcilable against
its own data.

It is the only feature whose public name differs today. The mapping lives in
`MeterFeature::publicCode()`.

### Every meter is present, including the unused ones

A feature with no activity returns `"used": 0` rather than being left out.
Omitting it would force every consumer to carry its own copy of the catalogue
to render a complete list, and would make *used nothing* indistinguishable
from *we do not track that* — different answers to give a customer.

### `used: null` with `measured: false` is not zero

Two features have no source recording them yet — `recommendations_delivered`
and `conversations_handled`. They return `null`, never `0`, because zero is a
claim nobody can support: nothing is counting, so nobody knows the number.
Render these as "not tracked", never as an empty bar.

The same applies to a seat that has never been recounted. In practice the
nightly rebuild writes every seat, so `measured: false` on a seat should only
appear on data loaded with model events suppressed (as `DatabaseSeeder` does).

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
