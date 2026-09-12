# AI Cost Attribution API Documentation

WP-10 of Phase 2 v2.0. The system now records **what serving each account
costs us**, so that the gap between what a customer pays and what they cost
can be computed for the first time.

WP-8 counts what the customer used. This counts what we paid a provider to
give it to them. They are not the same number, and the gap between them is the
business.

Like WP-8, this phase **observes**. The one exception is the hard daily
ceiling — see [The two limits](#the-two-limits), which explains why it is an
exception and what it is not.

## The rule, and its one exception

> Store facts. Never store prices.

`meter_events` obeys that absolutely: no price column, and a test asserts its
absence.

`ai_usage_logs.cost_usd` is the deliberate exception, and it is worth being
precise about why it is not a violation. That column holds **what we paid a
supplier**, on a date, at a rate that applied then — a historical fact about a
completed transaction. What the rule forbids is writing **what a customer
owes** onto a usage row, because a customer charge goes stale the moment our
pricing changes and cannot be recomputed once quantity is buried under a rate.
Supplier cost has no such problem: it never changes retroactively.

## Tables

| Table | Role |
|---|---|
| `ai_usage_logs` | Append-only. One row per provider call. The model throws on update and delete. |
| `ai_model_prices` | The dated price book. Superseded, never overwritten. |
| `hotel_groups.contract_value_monthly` | A hand-filled monthly figure, so margin is computable before billing exists. |

### Token columns mean something specific

`input_tokens` **excludes** anything served from cache. `laravel/ai`
normalises every provider that way, and cached tokens are counted separately
in `cached_tokens` and priced at the cached rate. Adding the two together
would overstate the bill.

Cache *writes* are folded into `input_tokens` at the full input rate. Providers
that charge a premium for a cache write (Anthropic, at 1.25×) are therefore
slightly understated — and are zero today, because nothing enables prompt
caching. Reasoning tokens are a subset of output tokens, not an addition to
them, so they are not added again.

## Capture is central

Cost is captured by listening to the AI package's own events, in
`App\Listeners\RecordAiUsage`, wired in `AppServiceProvider`:

| Event | What it records |
|---|---|
| `PromptingAgent` | Starts the latency clock, counts the attempt |
| `AgentPrompted` | A completed chat call (covers streaming — `AgentStreamed` extends it) |
| `AgentFailedOver` | A failed attempt that fell over to another provider |
| `GeneratingEmbeddings` / `EmbeddingsGenerated` | Embedding calls |
| `Reranked` | Reranking calls |

**Nothing was added to any agent.** That is the requirement, not a preference:
the fifth agent someone writes next year is costed the day it is written,
because it goes through the same package code these events come from. There is
nothing to remember, and so nothing to forget.

### What a call site must declare

The events carry the model, the tokens and the latency. They know nothing
about hotels — the package has no idea our accounts exist. That half comes
from `AiCostContext`, the same shape as `EventLogger::asAiAgent()`:

```php
AiCostContext::for(
    kind: AiTriggerKind::GUEST_MESSAGE,
    hotel: $hotel,
    trigger: $guest,
    callback: fn () => $agent->prompt($message),
);
```

Contexts nest, and the innermost wins. This matters more than it looks: a
knowledge-base search inside a guest conversation embeds a query, and because
that embedding happens inside the conversation's context it is attributed to
the guest rather than filed as an anonymous system cost.

A call made with no context is **still logged**, as `unattributed`. It is not
guessed into whichever kind looked likely — guessing would put an invented
fact in the table that exists to hold measured ones. In practice that count
should always be zero; a non-zero one means a new call site skipped the
context, and the endpoint reports it explicitly.

### Where context is declared today

| Call site | Trigger kind |
|---|---|
| `ProcessInboundWhatsAppMessageJob` (guest sender) | `guest_message` |
| `ProcessInboundWhatsAppMessageJob` (admin sender) | `staff_request` |
| `AiAdvisorController@chat` | `staff_request` |
| `GenerateActivityRecommendationsJob` | `staff_request` |
| `CreateAiInsightsJob` | `scheduled_job` |
| `SyncKnowledgeChunksJob` | `scheduled_job` |

**`trigger_kind` is the field that earns its keep.** It separates cost we
control from cost guests drive. `GuestConciergeAgent` answers anyone who can
message the hotel's number, so anyone who can message the hotel's number can
cause spend — and only one side of that split is bounded by anything we
decide.

## The price book

Prices are **dated, not constant**. A cost report for August must use August's
rates, or it produces a number nobody can reconcile against the invoice that
was actually paid.

Never edit a price row. Supersede it:

```php
AiModelPrice::supersede(
    provider: 'openai',
    model: 'gpt-5.4',
    inputPerMillion: 4.00,
    outputPerMillion: 10.00,
    cachedInputPerMillion: 0.40,
    effectiveFrom: now(),
);
```

That closes the old row the day before the new one starts, so every cost
already reported against the old price still recomputes to the same figure.

**Gemini rows are real published rates.** Gemini is what this deployment
actually bills against — `AI_DEFAULT_PROVIDER` and `default_for_embeddings`
both resolve there — so those are the numbers the cost report stands on.

> ⚠ **The OpenAI, Anthropic and Cohere rows are still placeholders.** They
> exist so that switching provider does not immediately produce unpriced
> calls; they are not published rates. Replace them before believing any
> figure computed from them. The spread across the providers in
> `config/ai.php` is more than thirtyfold, so a wrong rate does not produce a
> slightly wrong report — it produces a confidently wrong one.

### Promotional rates have an end date, and it is recorded

The Gemini 3.x flash models (`3.6`, `3.7`, `3.8`) are on a promotional rate of
$0.75 / $3.75 per million **through 31 December 2026**. Those rows carry an
`effective_to` of that date, and no row follows them, because the standard
rate that replaces them has not been published.

That is deliberate. Leaving the rate open-ended would mean that on 1 January
2027 every call keeps being costed at a discount that no longer exists, and
the report would quietly understate what we pay — the exact confidently-wrong
number this phase exists to prevent. Closing it means those calls become
**unpriced** instead: `cost_is_estimated = true`, named in `unpriced_models`,
and logged as a warning. A visible gap demanding an action is the right kind
of wrong.

**Action required before 1 January 2027:** supersede those three models with
whatever Google publishes as the standard rate. A test
(`it stops applying a promotional rate once it expires…`) pins the behaviour.

A model with no price on file is **not free, it is unpriced**. Its calls are
logged with `cost_is_estimated = true` and a cost of zero, and the endpoint
names them under `unpriced_models` so that a zero meaning "not priced" is
never read as a zero meaning "free".

## Endpoint

```
GET /api/admin/ai-cost?from=2026-08-01&to=2026-08-31
```

Behind `super_admin`, like `/api/admin/usage`: it reports across every account,
so there is no single model to authorise against.

Both parameters are optional and default to the current calendar month.

### Response

```json
{
  "message": "AI cost fetched successfully.",
  "code": 200,
  "body": {
    "from": "2026-08-01",
    "to": "2026-08-31",
    "currency_note": {
      "costs_stored_in": "USD",
      "usd_per_eur": 1.09,
      "converted_at": "read time",
      "source": "Month-end reference rate, recorded manually."
    },
    "accounts": [{
      "hotel_group_id": "9d1f…",
      "account": "Domina Group",
      "ai_cost_usd": 347.10,
      "ai_cost_eur": 318.44,
      "calls": 9104,
      "cost_per_call_eur": 0.0350,
      "by_trigger": {
        "guest_message": 271.20,
        "staff_request": 38.10,
        "scheduled_job": 9.14,
        "unattributed": 0.0
      },
      "by_model": { "gpt-5.4": 289.30, "gemini-embedding-2": 29.14 },
      "by_hotel": { "9a2c…": 318.44 },
      "retry_cost_eur": 12.80,
      "failed_calls": 37,
      "contract_value": 1042.00,
      "contract_currency": "EUR",
      "gross_margin": 723.56,
      "gross_margin_pct": 69.4,
      "margin_basis": "manually recorded contract value; not invoiced",
      "margin_unavailable_reason": null,
      "cost_basis": "measured",
      "estimated_rows_pct": 0.0
    }],
    "unattributed": { "calls": 0, "ai_cost_usd": 0, "ai_cost_eur": 0, "note": "…" },
    "unpriced_models": [],
    "notes": "FX at 1.09 USD/EUR (…). Contract value is manually recorded, not invoiced …"
  }
}
```

### Three reporting rules, enforced rather than left to the reader

1. **Every figure carries its basis.** `cost_basis` (`measured` / `mixed` /
   `estimated` / `no data`) and `estimated_rows_pct` are always present. A
   margin without provenance is exactly the confident-but-unverifiable number
   this framework exists to prevent.
2. **The FX rate is always stated.** Costs are stored in USD as charged and
   converted at read time. Converting at write time would bake one day's rate
   into a permanent row and make history unreproducible. The rate lives in
   `config/ai_cost.php` as `usd_per_eur` — named for its direction, because a
   rate whose direction has to be guessed will eventually be applied upside
   down.
3. **Margin is null where it is unknown** — never zero, never a guess, and
   always with `margin_unavailable_reason` saying which of the two reasons
   applies:
   - no contract value is recorded for the account, or
   - the requested range is not a whole calendar month, and prorating a
     monthly figure would invent a precision nobody agreed to.

`retry_cost_eur` is the part of the bill that bought nothing: a call that
retried three times cost four calls, and all four are logged with an `attempt`
number.

## The two limits

They are different mechanisms with different purposes, and conflating them is
the mistake this section exists to prevent.

| | Margin alert | Daily ceiling |
|---|---|---|
| Config | `alert_margin_threshold` (0.50) | `daily_ceiling_usd` (50.00) |
| Runs | `FlagAiCostOverrunsJob`, daily 04:30 | `AiSpendCeiling`, on every AI call |
| Asks | Is this account still profitable? | Is something running away? |
| Acts | **Never.** Logs and returns the flagged accounts. | Yes — throws `AiSpendCeilingExceededException`. |

The alert never throttles, and must not be extended to. A thin margin is a
commercial conversation or a misconfiguration, and neither is a decision a
cron job should make about a paying customer's service. Accounts with no
recorded contract value are skipped rather than defaulted: alerting on a
guessed denominator trains people to ignore the alert.

The ceiling *may* act, because its purpose is stopping runaway loops and
hostile traffic rather than managing margin. It is set well above any
plausible day of real use, so crossing it should mean something is broken, not
that business was good. It pairs with the per-guest limits in P1-002 A-3:
those bound one conversation, this bounds a whole account. It is checked once
per outermost context, so it never stops a conversation halfway through.

## Failure never propagates

Same rule as metering, for the same reason:

```php
$this->recorder->safely(fn ($recorder) => $recorder->record(...));
```

If cost logging throws, the AI response has already been produced and must
still reach the caller. **A lost cost row is a line in a report. A lost answer
is a customer.** A test asserts the advisor still replies with the cost
recorder failing outright.

## Verified by

`tests/Feature/AiCostAttributionTest.php` — 15 tests covering every acceptance
criterion, including that historical prices are used rather than today's, that
cached input is priced separately from fresh input, that an undeclared call is
recorded as `unattributed` rather than guessed, that the logs are append-only,
and that the schema stores no converted currency.

## Deferred, and what would change

| Item | Status |
|---|---|
| `contract_value_monthly` | Hand-filled. Superseded cleanly when subscriptions (WP-7) arrive. |
| Real provider prices | **Gemini: done** (published rates). OpenAI / Anthropic / Cohere still placeholders. |
| Gemini 3.x promo expiry | **Outstanding, dated.** Supersede the three flash models before 1 Jan 2027. |
| Rerank pricing | Priced per search, not per token. Seeded at zero; needs its own treatment if it becomes material. |
| Per-call ceiling behaviour on the guest path | The exception propagates and the job fails. If a stopped account should instead send the guest a fallback message, catch `AiSpendCeilingExceededException` in `ProcessInboundWhatsAppMessageJob`. |
