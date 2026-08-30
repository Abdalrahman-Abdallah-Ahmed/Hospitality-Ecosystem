# Hospitality Ecosystem — Phase 2 Implementation Plan

## The SaaS Layer: Plans, Subscriptions, Metering, and Cost Control

**Document ID:** PGRIP-ECO-P2-001
**Version:** 1.0
**Date:** 24 August 2026
**Prepared for:** Backend developer (junior level)
**Prepared by:** PGRIP Intelligence Partner
**Depends on:** PGRIP-ECO-P1-001 v1.0 (Phase 1) — **must be complete and merged**
**Stack:** PHP 8.3 · Laravel 13 · PostgreSQL · Pest 4 · Sanctum · Laravel Cashier (added in this phase)

---

## 0. How to read this document

Same structure as Phase 1. Each Work Package has **Why → What you build → How to verify → Acceptance criteria → Traps**.

Work the packages in order. WP-9 cannot work without WP-6, WP-7, and WP-8 in place.

If you have not built Phase 1, stop. Phase 2 assumes `hotel_groups`, `TenantContext`, the append-only ledger pattern, and `event_log` already exist. Building the billing layer on ungoverned tenancy produces a system that bills the wrong customer for the wrong thing and cannot prove otherwise.

**Estimated total: 6–8 weeks** for one developer full time.

---

## 1. Why Phase 2 exists

After Phase 1, the system works. It does not yet **sell**.

There is currently no concept of a customer paying for anything. No plans, no subscriptions, no limits, no invoices, no record of what any tenant costs to serve. Every hotel gets unlimited everything, free, forever.

That matters for three separate reasons, and only one of them is "we need to collect money".

### Reason 1 — There is no revenue mechanism

Obvious, and the least interesting of the three.

### Reason 2 — Unlimited AI usage will destroy the margin

This is the one that quietly kills AI products.

Every call to an LLM costs real money. The system already has agents that can be triggered repeatedly: `InsightsAgent`, `RecommendationAgent`, `GuestConciergeAgent` answering WhatsApp messages. A single busy resort in high season could generate thousands of agent calls a month. A misconfigured integration or a chatty guest could generate tens of thousands.

Today the system has **no idea** what any tenant costs. Not per hotel, not per agent, not per month. You could be losing money on your largest customer and have no way to detect it.

A subscription business where you cannot compute gross margin per customer is not a business, it is a hobby with invoices.

### Reason 3 — Feature gating is what makes tiers meaningful

"Starter / Professional / Enterprise" means nothing unless the code can actually withhold something. Without an entitlements layer, every pricing page is a work of fiction.

### What Phase 2 delivers

By the end, the system can answer:

| Question | Answered by |
|---|---|
| What is this customer paying for? | WP-6, WP-7 |
| Are they allowed to use this feature? | WP-9 |
| How much have they used this month? | WP-8 |
| What did it cost us to serve them? | WP-10 |
| Are we making money on them? | WP-10 |
| Have they paid? | WP-11 |
| How do we onboard the next one without a developer? | WP-12 |

---

## 2. Scope

| WP | Name | Days (est.) |
|---|---|---|
| WP-6 | Plans and entitlements catalogue | 5–6 |
| WP-7 | Subscription lifecycle | 6–8 |
| WP-8 | Usage metering | 5–7 |
| WP-9 | Enforcement: gating, quotas, graceful degradation | 6–8 |
| WP-10 | AI cost attribution and margin visibility | 5–7 |
| WP-11 | Payment provider, invoices, dunning | 7–9 |
| WP-12 | Tenant provisioning and admin console API | 5–6 |

### Out of scope

- Frontend, pricing page, checkout UI
- Machine learning / scoring → **Phase 3**
- Complaints, reviews, housekeeping → **Phase 4**
- Multi-currency settlement, tax filing, revenue recognition accounting (see §9)
- Partner / reseller / white-label billing

---

## 3. Two decisions to confirm before you start

Take these to the product owner. Do not guess — both are expensive to change later.

### Decision 1 — What is the billing entity?

The candidates:

| Option | Meaning | Trade-off |
|---|---|---|
| Per user | Each login pays | Wrong for hotels — staff turnover is high, seats churn constantly |
| Per hotel | Each property pays | Simple, but a 6-property group gets 6 invoices and 6 logins |
| **Per hotel group (recommended)** | The group is the customer; hotels are what's counted | One contract, one invoice, properties as billable units |

**This plan assumes the hotel group is the billing entity.** Phase 1 already built `hotel_groups`, and a hotel with no group gets a single-property group of its own. That keeps one code path for everybody.

Terminology used throughout: **Account = hotel group.**

### Decision 2 — What is the pricing shape?

| Shape | Pros | Cons |
|---|---|---|
| Flat per property | Predictable for both sides | Small hotel subsidises large one; AI cost uncoupled from price |
| Pure usage-based | Fair, scales with cost | Unpredictable bills; hotels hate this |
| **Hybrid (recommended)** | Base fee per property + tiered AI allowance, overage billed or throttled | Covers cost floor, keeps bills predictable, protects margin |

The schema below supports all three. Build for the hybrid — it is the superset, and switching later costs nothing.

---

## 4. Vocabulary

Learn these before reading the work packages. SaaS billing has a lot of near-synonyms that mean different things.

| Term | Meaning |
|---|---|
| **Account** | The paying customer. Here: a hotel group. |
| **Plan** | A named package: "Professional, €249/property/month". |
| **Feature** | A capability that can be switched on or off: `ai_advisor`, `api_access`. |
| **Limit / Quota** | A capped number: 5 properties, 10,000 AI messages/month. |
| **Entitlement** | What one specific account is actually allowed, right now. Derived from its plan, but not identical to it. |
| **Meter event** | One append-only record that something billable happened. |
| **Counter** | A cached running total, derived from meter events. Never the source of truth. |
| **Subscription** | The live relationship between an account and a plan, with a status and dates. |
| **Trial** | Time-limited full or partial access before payment. |
| **Grace period** | Time after a failed payment before access is reduced. |
| **Dunning** | The retry-and-remind process after a failed payment. |
| **Proration** | Adjusting a charge when a plan changes mid-cycle. |
| **MRR** | Monthly Recurring Revenue. |
| **COGS** | Cost of Goods Sold — here, mostly LLM tokens and infrastructure. |
| **Gross margin** | Revenue minus COGS. The number Phase 2 exists to make visible. |
| **Idempotency** | Doing the same operation twice has the same effect as once. Critical for webhooks. |

---

# WP-6 — Plans and entitlements catalogue

**Estimated: 5–6 days.**

## Why

You need a place to define what exists for sale before you can sell it.

The important design decision in this package is subtle and worth understanding properly, because getting it wrong causes a specific and embarrassing failure:

> **A plan's definition must not retroactively change what existing customers are entitled to.**

Imagine a customer signs up in January on "Professional, 5 properties". In June, marketing reduces Professional to 3 properties for new customers. If entitlements are read live from the plan, that January customer suddenly loses access to 2 properties they have been paying for — silently, in production, with no warning.

The fix: **the plan is a template; the subscription stores a snapshot.** When a subscription is created, copy the entitlements onto it. Changing the plan afterwards affects only new subscriptions. Existing customers keep what they bought until someone deliberately migrates them.

This is the same principle as Phase 1's "planned vs actual" dates: record what was agreed as a separate fact from what the catalogue currently says.

## What you build

### 6.1 Features catalogue

```php
Schema::create('features', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('code')->unique();          // 'ai_advisor', 'whatsapp_channel'
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('type');                    // App\Enums\FeatureType
    $table->string('unit')->nullable();        // 'messages', 'properties', 'rows'
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

```php
// app/Enums/FeatureType.php
enum FeatureType: string
{
    case BOOLEAN = 'boolean';   // you have it or you don't
    case QUOTA   = 'quota';     // a number per billing period, resets
    case SEAT    = 'seat';      // a number that does not reset (properties, users)
}
```

Starting catalogue:

| code | type | unit |
|---|---|---|
| `properties` | seat | properties |
| `users` | seat | users |
| `ai_advisor` | boolean | — |
| `ai_insights` | boolean | — |
| `ai_messages` | quota | messages/month |
| `whatsapp_channel` | boolean | — |
| `guest_recommendations` | quota | recommendations/month |
| `transaction_import` | boolean | — |
| `analytics_conversion` | boolean | — |
| `api_access` | boolean | — |
| `data_retention_months` | seat | months |

### 6.2 Plans

```php
Schema::create('plans', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('code')->unique();          // 'starter', 'professional'
    $table->string('name');
    $table->text('description')->nullable();

    $table->decimal('base_price', 10, 2)->default(0);
    $table->decimal('price_per_property', 10, 2)->default(0);
    $table->string('currency', 3)->default('EUR');
    $table->string('billing_interval');        // 'monthly' | 'yearly'

    $table->unsignedInteger('trial_days')->default(0);
    $table->boolean('is_public')->default(true);   // false = custom/negotiated
    $table->boolean('is_active')->default(true);
    $table->unsignedInteger('sort_order')->default(0);

    $table->string('provider_price_id')->nullable();   // Stripe price id, WP-11
    $table->timestamps();
    $table->softDeletes();
});

Schema::create('plan_features', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('feature_id')->constrained()->cascadeOnDelete();
    $table->boolean('enabled')->default(true);
    $table->unsignedBigInteger('limit')->nullable();   // null = unlimited
    $table->timestamps();

    $table->unique(['plan_id', 'feature_id']);
});
```

**`limit = null` means unlimited. `limit = 0` means enabled but nothing allowed.** These are different. Write it in a comment; someone will confuse them otherwise.

### 6.3 Versioning plans

Never edit a live plan's prices or limits in place. Add:

```php
$table->unsignedInteger('version')->default(1);
$table->foreignUuid('supersedes_plan_id')->nullable()->constrained('plans')->nullOnDelete();
```

To change Professional, create Professional v2 pointing at v1 and deactivate v1 for new signups. Existing subscriptions keep their snapshot. You now have a complete history of what was sold, when, at what price — which you will need the first time a customer disputes an invoice.

### 6.4 Seeder

Write `PlanSeeder` with three plans so the rest of the phase has something to work against:

| | Starter | Professional | Enterprise |
|---|---|---|---|
| Base / month | €99 | €249 | negotiated |
| Per property | €0 | €79 | negotiated |
| Properties | 1 | 10 | unlimited |
| Users | 5 | 50 | unlimited |
| AI messages/mo | 500 | 10,000 | 100,000 |
| AI advisor | ✗ | ✓ | ✓ |
| WhatsApp | ✓ | ✓ | ✓ |
| Conversion analytics | ✗ | ✓ | ✓ |
| API access | ✗ | ✗ | ✓ |
| Retention | 12 mo | 36 mo | unlimited |
| Trial | 14 days | 14 days | 30 days |

These numbers are **placeholders for development**. Pricing is a commercial decision, not a developer decision. Do not defend them in a meeting.

## How to verify

```php
it('returns unlimited when a feature limit is null', ...);
it('distinguishes limit zero from limit null', ...);
it('does not change an existing subscription when a plan is edited', ...);   // ← the important one
it('lists only public active plans on the public catalogue endpoint', ...);
```

## Acceptance criteria

- [ ] Features, plans, and plan_features tables exist and are seeded
- [ ] Plans are versioned; superseded plans remain readable
- [ ] `GET /api/plans` returns the public catalogue
- [ ] Editing a plan provably does not alter an existing subscription
- [ ] All Phase 1 tests still pass

## Traps

1. **Do not put prices in code.** They belong in the database, because they change without a deploy.
2. **`unsignedBigInteger` for limits**, not `integer`. Row-count limits get large.
3. **Do not add tenancy scoping to plans.** The catalogue is global, not per tenant. Applying `BelongsToHotel` here would be wrong.

---

# WP-7 — Subscription lifecycle

**Estimated: 6–8 days.**

## Why

A subscription is a **state machine**, and treating it as a simple flag is where most billing bugs come from. A subscription is not "active or not". It moves through defined states, and each transition has rules about what is allowed and what must be recorded.

```
                  ┌──────────┐
                  │ TRIALING │
                  └────┬─────┘
             pays      │      trial ends unpaid
        ┌──────────────┴───────────────┐
        ▼                              ▼
   ┌────────┐   payment fails    ┌──────────┐
   │ ACTIVE │ ─────────────────► │ PAST_DUE │
   └───┬────┘ ◄───────────────── └────┬─────┘
       │        payment succeeds      │ grace expires
       │ cancels                      ▼
       ▼                        ┌───────────┐
  ┌───────────┐                 │ SUSPENDED │
  │ CANCELLED │ ◄───────────────└─────┬─────┘
  └───────────┘   retention expires   │ pays
                                      └──────► ACTIVE
```

## What you build

### 7.1 Subscriptions

```php
Schema::create('subscriptions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('hotel_group_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('plan_id')->constrained();

    $table->string('status');                         // App\Enums\SubscriptionStatus
    $table->string('currency', 3);
    $table->decimal('agreed_base_price', 10, 2);      // snapshot, not read from plan
    $table->decimal('agreed_price_per_property', 10, 2);
    $table->string('billing_interval');

    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamp('current_period_start');
    $table->timestamp('current_period_end');
    $table->timestamp('cancelled_at')->nullable();
    $table->timestamp('ends_at')->nullable();          // access truly stops
    $table->timestamp('grace_ends_at')->nullable();

    $table->string('provider')->nullable();            // 'stripe'
    $table->string('provider_subscription_id')->nullable()->unique();

    $table->text('cancellation_reason')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['hotel_group_id', 'status']);
    $table->index('current_period_end');
});
```

```php
enum SubscriptionStatus: string
{
    case TRIALING  = 'trialing';
    case ACTIVE    = 'active';
    case PAST_DUE  = 'past_due';    // payment failed, still has access
    case SUSPENDED = 'suspended';   // access reduced
    case CANCELLED = 'cancelled';
    case EXPIRED   = 'expired';
}
```

### 7.2 The entitlement snapshot

This is the table WP-9 will read on every request.

```php
Schema::create('subscription_entitlements', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('subscription_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('feature_id')->constrained();
    $table->string('feature_code');            // denormalised on purpose — see below
    $table->boolean('enabled')->default(true);
    $table->unsignedBigInteger('limit')->nullable();
    $table->boolean('is_override')->default(false);
    $table->text('override_reason')->nullable();
    $table->timestamps();

    $table->unique(['subscription_id', 'feature_code']);
});
```

**Why `feature_code` is duplicated here:** this table is read on nearly every authenticated request. Storing the code avoids a join to `features` every time. Denormalising for read performance is legitimate when the duplicated value never changes — feature codes are immutable once published. Add that as a rule: **feature codes are permanent.**

`is_override` handles the sales reality that someone will negotiate "Professional, but with 20,000 AI messages". Record the override and the reason rather than inventing a bespoke plan for one customer.

### 7.3 The service

```php
// app/Services/Billing/SubscriptionService.php
public function startTrial(HotelGroup $account, Plan $plan): Subscription;
public function activate(Subscription $s): Subscription;
public function changePlan(Subscription $s, Plan $newPlan, bool $immediate = true): Subscription;
public function markPastDue(Subscription $s): Subscription;
public function suspend(Subscription $s): Subscription;
public function cancel(Subscription $s, string $reason, bool $atPeriodEnd = true): Subscription;
public function resume(Subscription $s): Subscription;
public function syncEntitlements(Subscription $s): void;   // plan → snapshot
```

Rules to enforce inside the service, not in controllers:

- **One active subscription per account.** Enforce with a partial unique index in Postgres:
  ```sql
  CREATE UNIQUE INDEX one_live_subscription_per_account
  ON subscriptions (hotel_group_id)
  WHERE status IN ('trialing','active','past_due','suspended') AND deleted_at IS NULL;
  ```
  A database constraint is worth more than a check in PHP, because it holds even when two requests race.

- **Every transition writes to `event_log`** (Phase 1, WP-4) with the actor and the reason. When a customer asks "why was I downgraded on the 14th", you must be able to answer.

- **Cancellation defaults to end-of-period.** A customer who cancels on day 3 of a paid month keeps access until day 30. Immediate cancellation is a separate, deliberate call.

### 7.4 Scheduled job

```php
// app/Jobs/Billing/ProcessSubscriptionLifecycleJob.php — hourly
// - trials whose trial_ends_at has passed → activate (if payment method) or suspend
// - past_due whose grace_ends_at has passed → suspend
// - cancelled whose ends_at has passed → expired
// - roll current_period_start / end forward for renewals
```

## How to verify

```php
it('allows only one live subscription per account', ...);
it('keeps access until period end when cancelled normally', ...);
it('snapshots entitlements at creation and ignores later plan edits', ...);
it('moves a trial with no payment method to suspended, not cancelled', ...);
it('writes an event_log entry for every status change', ...);
it('applies an override without altering the plan', ...);
```

## Acceptance criteria

- [ ] All six statuses implemented with the transitions above
- [ ] Database-level constraint prevents two live subscriptions per account
- [ ] Entitlements snapshotted at creation; plan edits do not leak through
- [ ] Every transition appears in `event_log` with actor and reason
- [ ] Hourly lifecycle job runs and is idempotent

## Traps

1. **Timezones and month ends.** A monthly subscription starting 31 January renews on… 28 February. Use `Carbon::addMonthNoOverflow()`, and write a test for it. This bug ships to production constantly.
2. **The lifecycle job will run twice.** Make every operation idempotent and guard on current status.
3. **Never delete a subscription.** Cancel it. Billing history is legally required in most jurisdictions.

---

# WP-8 — Usage metering

**Estimated: 5–7 days.**

## Why

You cannot enforce a quota you are not counting, and you cannot bill for usage you did not record.

The design rule is the same one Phase 1 applied to the transaction ledger:

> **Meter events are append-only. Counters are derived and disposable.**

The tempting shortcut is a single `ai_messages_used` integer that you increment. Do not do this. When a customer disputes their bill, an integer tells you nothing — you cannot show *what* was used, *when*, or *by which property*. And if the increment fails once, the number is permanently wrong with no way to detect or repair it.

Store events. Derive totals. If a counter is wrong, delete it and rebuild it from the events.

## What you build

### 8.1 Meter events

```php
Schema::create('meter_events', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('hotel_group_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();

    $table->string('feature_code');                 // 'ai_messages'
    $table->unsignedBigInteger('quantity')->default(1);
    $table->string('unit');

    $table->nullableUuidMorphs('source');           // the record that caused it
    $table->nullableUuidMorphs('actor');
    $table->string('actor_kind');                   // user | system | ai_agent | import

    $table->date('billing_period_start');           // denormalised for fast grouping
    $table->timestamp('occurred_at');
    $table->string('idempotency_key')->nullable();
    $table->json('metadata')->nullable();

    $table->timestamps();                            // no softDeletes — append only

    $table->unique(['hotel_group_id', 'idempotency_key']);
    $table->index(['hotel_group_id', 'feature_code', 'billing_period_start']);
    $table->index(['hotel_id', 'occurred_at']);
});
```

### 8.2 Counters (cache, not truth)

```php
Schema::create('usage_counters', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('hotel_group_id')->constrained()->cascadeOnDelete();
    $table->string('feature_code');
    $table->date('billing_period_start');
    $table->unsignedBigInteger('used')->default(0);
    $table->timestamp('recomputed_at')->nullable();
    $table->timestamps();

    $table->unique(['hotel_group_id', 'feature_code', 'billing_period_start']);
});
```

Two ways to keep it current, and you need both:

- **Incremental:** every meter event bumps the counter in the same transaction.
- **Rebuild:** a nightly job recomputes each counter from `meter_events` and overwrites it.

The nightly rebuild is the safety net. Log any discrepancy it finds — a drift between the two is a bug you want to know about, not silently paper over.

### 8.3 The recording API

```php
// app/Services/Billing/MeteringService.php
public function record(
    HotelGroup $account,
    string $featureCode,
    int $quantity = 1,
    ?Model $source = null,
    ?string $idempotencyKey = null,
    array $metadata = [],
): ?MeterEvent;
```

Call it from:

| Where | Feature |
|---|---|
| `ProcessInboundWhatsAppMessageJob` | `ai_messages` |
| `AiAdvisorController@chat` | `ai_messages` |
| `CreateAiInsightsJob` | `ai_insights_generated` |
| `GenerateActivityRecommendationsJob` | `guest_recommendations` |
| `TransactionsImport` | `transaction_rows_imported` |
| Hotel created | `properties` (seat, recount not increment) |
| User created | `users` (seat, recount not increment) |

**Seats are recounted, quotas are incremented.** A seat is "how many exist right now" — recount it from the table. A quota is "how many happened this period" — sum the events. Mixing these up produces a property count that only ever goes up, even after a hotel is deleted.

### 8.4 Never let metering break the product

```php
try {
    $this->metering->record(...);
} catch (\Throwable $e) {
    report($e);   // log it, do not rethrow
}
```

If the metering service fails, the guest's WhatsApp message must still be answered. Losing a billing event costs cents. Failing to answer a guest costs a customer.

Under-billing is recoverable. A broken product is not.

## How to verify

```php
it('does not double count when the same idempotency key is submitted twice', ...);
it('rebuilds a deliberately corrupted counter correctly from events', ...);
it('recounts seats rather than incrementing them', ...);
it('still answers the guest when metering throws', ...);
it('assigns events to the correct billing period at a period boundary', ...);
```

## Acceptance criteria

- [ ] `meter_events` append-only with a working idempotency key
- [ ] Counters maintained incrementally and rebuilt nightly
- [ ] Rebuild discrepancies are logged, not hidden
- [ ] Every AI entry point records usage
- [ ] Metering failure never propagates to the caller
- [ ] `GET /api/usage` returns current-period usage per feature

## Traps

1. **Period boundaries.** An event at 23:59:59 on the last day belongs to the closing period. Compute the period from the subscription's dates, not from `now()->startOfMonth()`.
2. **Volume.** `meter_events` will become the largest table in the system. Indexes from day one; note partitioning by month as future work.
3. **Do not meter inside a loop over 10,000 import rows.** Record one event with `quantity = 10000`.

---

# WP-9 — Enforcement: gating, quotas, and graceful degradation

**Estimated: 6–8 days.**

## Why

This package decides what happens when a customer hits a limit or stops paying. Get it wrong in either direction and you have a serious problem: too soft and the tiers are meaningless; too hard and you break a hotel's operations.

Understand the environment you are building for. A hotel front desk runs 24 hours a day. Guests arrive at 03:00. If a card expires on a Friday and your system locks the front office out on Saturday morning, you have not enforced a payment policy — you have caused an operational incident at a business that will remember it forever and tell the industry.

**The rule: degrade the intelligence, never the operations.**

### The degradation ladder

| Level | Trigger | What happens |
|---|---|---|
| 0 — Normal | Within limits | Everything works |
| 1 — Warn | ≥80% of quota | Everything works; warning in response headers and to admins |
| 2 — Soft limit | 100% of quota | New AI generation blocked; existing data readable; core operations untouched |
| 3 — Past due | Payment failed | Level 2 plus billing banner; grace period runs (14 days recommended) |
| 4 — Suspended | Grace expired | Read-only for everything except check-in, check-out, and data export |
| 5 — Expired | Retention elapsed | Access ends; data exported and archived per retention policy |

**Never blocked at any level:** authentication, check-in, check-out, viewing existing reservations, data export.

Data export stays available at every level on purpose. Holding a customer's own data hostage over an unpaid invoice is unlawful in several jurisdictions and indefensible in all of them.

## What you build

### 9.1 The entitlement resolver

```php
// app/Support/Billing/Entitlements.php
class Entitlements
{
    public function allows(string $featureCode): bool;
    public function limit(string $featureCode): ?int;      // null = unlimited
    public function used(string $featureCode): int;
    public function remaining(string $featureCode): ?int;
    public function wouldExceed(string $featureCode, int $qty = 1): bool;
    public function degradationLevel(): int;
}
```

Resolve once per request in the tenancy middleware from Phase 1 and cache it on `TenantContext`. Do not query entitlements repeatedly inside a request.

Cache the resolved entitlement set in Redis for ~60 seconds, keyed by subscription id, and **flush it explicitly** on any subscription or entitlement change. A stale entitlement cache means a customer who just upgraded still gets refused — which generates a support ticket within minutes of them paying you more money.

### 9.2 Middleware

```php
Route::post('/ai-advisor/chat', [AiAdvisorController::class, 'chat'])
    ->middleware('feature:ai_advisor,ai_messages');
```

First argument: the boolean feature required. Second (optional): the quota to check.

Refusals return **HTTP 402 Payment Required** — not 403. 403 means "you are not permitted"; 402 means "your plan does not include this". The distinction matters to the frontend, which should show an upgrade prompt rather than an error.

```json
{
  "message": "Your plan's monthly AI message allowance is used up.",
  "error": "quota_exceeded",
  "feature": "ai_messages",
  "limit": 10000,
  "used": 10000,
  "resets_at": "2026-09-01T00:00:00Z",
  "upgrade_available": true
}
```

Add usage headers to every authenticated response:

```
X-Usage-Feature: ai_messages
X-Usage-Limit: 10000
X-Usage-Used: 8241
X-Usage-Resets-At: 2026-09-01T00:00:00Z
```

### 9.3 Seat enforcement

Check before creating a hotel or a user:

```php
if ($entitlements->wouldExceed('properties')) {
    return apiResponse('Your plan allows '.$entitlements->limit('properties').' properties.', 402, [...]);
}
```

**Never retroactively disable existing records** when a downgrade takes an account over its seat limit. Block new creation and notify the admin to choose what to remove. Silently deactivating a hotel because a card was declined is the kind of behaviour that ends a contract.

### 9.4 Warnings

A daily job checks each active subscription and notifies admins at 80% and 100% of quota. Record a notification event so nobody gets the same warning daily for two weeks.

## How to verify

```php
it('returns 402 with an upgrade payload when a boolean feature is missing', ...);
it('blocks the request that would cross the quota, not the one after it', ...);
it('still allows check-in and check-out while suspended', ...);
it('still allows data export while suspended', ...);
it('does not deactivate existing hotels when a downgrade breaches the seat limit', ...);
it('reflects an upgrade immediately, without waiting for cache expiry', ...);
it('treats a null limit as unlimited', ...);
```

The third and fourth tests are the ones that protect the business relationship. Write them first.

## Acceptance criteria

- [ ] `feature:` middleware in place on all gated routes
- [ ] 402 responses carry limit, usage, reset time, and upgrade flag
- [ ] Usage headers on authenticated responses
- [ ] Degradation ladder implemented; operational endpoints never blocked
- [ ] Export available at every level including suspended
- [ ] Entitlement cache invalidated on change
- [ ] 80% and 100% warnings sent once each per period

## Traps

1. **Check before, not after.** `wouldExceed()` runs before the work, not after.
2. **Race conditions.** Two simultaneous requests at 9,999 of 10,000 can both pass. Accept small overage, or use a database-level atomic increment. Do not build distributed locking for this — the business cost of one extra message is zero.
3. **Never gate a webhook route.** Inbound WhatsApp from Meta must always return 200, or Meta will retry, then disable your endpoint.
4. **Never gate authentication.** A locked-out admin cannot pay you.

---

# WP-10 — AI cost attribution and margin visibility

**Estimated: 5–7 days.**

## Why

WP-8 counts *what customers use*. This package counts *what it costs you*. They are not the same number, and the gap between them is your business.

Right now that gap is invisible. `config/ai.php` shows the system can call OpenAI, Anthropic, Gemini, Bedrock, and more. Different models cost different amounts, by factors of thirty or more. A tenant on a €249 plan whose agents make heavy calls to a frontier model can cost more than they pay, indefinitely, and nothing in the system would ever say so.

Additionally: `GuestConciergeAgent` is driven by inbound guest messages. That is an externally-triggered cost path. Anyone who can message the hotel's WhatsApp number can spend your money. That needs to be measured before it needs to be limited.

## What you build

### 10.1 AI usage log

```php
Schema::create('ai_usage_logs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('hotel_group_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignUuid('hotel_id')->nullable()->constrained()->nullOnDelete();

    $table->string('agent');                    // 'GuestConciergeAgent'
    $table->string('provider');                 // 'openai'
    $table->string('model');                    // 'gpt-4o'
    $table->string('operation');                // 'chat' | 'embedding' | 'rerank'

    $table->unsignedInteger('input_tokens')->default(0);
    $table->unsignedInteger('output_tokens')->default(0);
    $table->unsignedInteger('cached_tokens')->default(0);
    $table->unsignedInteger('tool_calls')->default(0);

    $table->decimal('cost_usd', 12, 6);         // six decimals — costs are fractions of a cent
    $table->unsignedInteger('latency_ms')->nullable();
    $table->boolean('succeeded')->default(true);
    $table->string('failure_reason')->nullable();

    $table->nullableUuidMorphs('trigger');
    $table->string('trigger_kind');             // guest_message | admin_request | scheduled_job
    $table->timestamp('occurred_at');
    $table->timestamps();

    $table->index(['hotel_group_id', 'occurred_at']);
    $table->index(['model', 'occurred_at']);
});
```

`decimal(12,6)` because a single call may cost €0.000420. Rounding to two decimals turns your entire cost base into zero.

### 10.2 Price book

```php
Schema::create('ai_model_prices', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('provider');
    $table->string('model');
    $table->decimal('input_price_per_million', 12, 4);
    $table->decimal('output_price_per_million', 12, 4);
    $table->decimal('cached_input_price_per_million', 12, 4)->nullable();
    $table->string('currency', 3)->default('USD');
    $table->date('effective_from');
    $table->date('effective_to')->nullable();
    $table->timestamps();

    $table->index(['provider', 'model', 'effective_from']);
});
```

Dated prices, not a constant. Providers change prices; historical cost analysis must use the price that applied on the day. Same supersession principle as everywhere else in this system.

### 10.3 Capturing usage

`laravel/ai` returns token usage on responses. Capture it centrally — a middleware or decorator around agent execution — rather than adding logging code to each of the four agents. Centralising it means the fifth agent someone writes next year is logged automatically.

If token counts are unavailable for some provider, estimate and set a flag marking the row as estimated. Then **use the Phase 1 evidence discipline**: an estimated cost is not a measured cost, and any report mixing them must say so.

### 10.4 The margin view

```
GET /api/admin/margin?from=2026-08-01&to=2026-08-31
```

```json
{
  "accounts": [{
    "account": "Domina Group",
    "plan": "professional",
    "revenue_eur": 1042.00,
    "ai_cost_eur": 318.44,
    "gross_margin_eur": 723.56,
    "gross_margin_pct": 69.4,
    "ai_messages": 8241,
    "cost_per_message_eur": 0.0386,
    "cost_basis": "measured",
    "estimated_rows_pct": 0.0
  }],
  "portfolio_margin_pct": 71.2,
  "accounts_below_target_margin": 1,
  "notes": "FX at month-end rate 1.09 USD/EUR."
}
```

Always report `cost_basis` and `estimated_rows_pct`. A margin figure without its provenance is exactly the kind of confident-but-unverifiable number this platform exists to eliminate.

### 10.5 Circuit breakers

A daily job flags any account whose AI cost exceeds a configurable share of its revenue (start at 50%). **Alert only — do not auto-throttle.** A margin problem is a commercial conversation, possibly a pricing correction, possibly a misconfiguration. Automatically degrading a paying customer's service because a threshold tripped is a decision no cron job should make alone.

Also add a hard per-account daily cost ceiling as an abuse stop, set well above normal use. That one *can* trigger automatically, because its purpose is stopping runaway loops and hostile traffic, not managing margin.

## How to verify

```php
it('logs cost for every agent call including failures', ...);
it('uses the price effective on the date of the call, not today\'s price', ...);
it('attributes a guest-triggered call to the right hotel group', ...);
it('marks estimated costs and reports the estimated percentage', ...);
it('flags an account whose AI cost exceeds half its revenue', ...);
it('does not lose the AI response when cost logging fails', ...);
```

## Acceptance criteria

- [ ] Every AI call logged with tokens, model, cost, trigger, and account
- [ ] Prices dated; historical costs use historical prices
- [ ] Margin endpoint returns per-account revenue, cost, margin, and basis
- [ ] Estimated costs distinguishable from measured ones
- [ ] Margin alerts notify; they do not auto-throttle
- [ ] Hard daily ceiling exists as an abuse stop

## Traps

1. **Never let cost logging break an AI response.** Same rule as metering: catch, report, continue.
2. **Currency.** Providers bill in USD; plans are priced in EUR. Store cost in USD, convert for reporting with a recorded rate, and state the rate. Do not convert at write time with a hardcoded number.
3. **Embeddings are easy to forget.** The knowledge-base sync job embeds documents and costs real money. Log it.
4. **Retries multiply cost.** A failed call that retries three times cost you four calls. Log all four.

---

# WP-11 — Payment provider, invoices, dunning

**Estimated: 7–9 days.**

## Why

Everything so far models the commercial relationship. This package collects the money.

**Use Laravel Cashier. Do not hand-roll payment handling.** Card data must never touch your servers — PCI-DSS compliance for storing card numbers is a months-long audit process that no startup should attempt. Cashier plus Stripe keeps you entirely out of scope by ensuring your database only ever holds opaque tokens.

```bash
composer require laravel/cashier
```

## What you build

### 11.1 Cashier on the account

Cashier's `Billable` trait goes on `HotelGroup`, not `User`. The account is the customer.

```php
class HotelGroup extends Model
{
    use Billable, HasUuids, SoftDeletes;
}
```

Keep your own `subscriptions` table as the source of truth for entitlements. Cashier's tables handle payment state; yours handle product state. They are linked by `provider_subscription_id`. **Do not read entitlements from Stripe** — a network problem at Stripe must never lock every customer out of your product.

### 11.2 Invoices

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('hotel_group_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();

    $table->string('number')->unique();            // sequential, never reused
    $table->string('status');                      // draft|open|paid|void|uncollectible
    $table->decimal('subtotal', 12, 2);
    $table->decimal('tax_amount', 12, 2)->default(0);
    $table->decimal('total', 12, 2);
    $table->decimal('amount_paid', 12, 2)->default(0);
    $table->string('currency', 3);

    $table->date('period_start');
    $table->date('period_end');
    $table->timestamp('issued_at')->nullable();
    $table->timestamp('due_at')->nullable();
    $table->timestamp('paid_at')->nullable();

    $table->string('provider_invoice_id')->nullable()->unique();
    $table->string('pdf_url')->nullable();
    $table->timestamps();
});

Schema::create('invoice_lines', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
    $table->string('description');
    $table->string('kind');                        // base|per_property|overage|credit|adjustment
    $table->string('feature_code')->nullable();
    $table->unsignedInteger('quantity')->default(1);
    $table->decimal('unit_price', 12, 2);
    $table->decimal('line_total', 12, 2);
    $table->timestamps();
});
```

**Invoices are immutable once issued.** A mistake is corrected with a credit note — a new document referencing the original. Identical to the Phase 1 ledger rule, and for identical reasons.

Invoice numbers must be sequential with no gaps. Generate them in a database transaction with a lock, never with `count() + 1`.

### 11.3 Webhooks

Stripe tells you what happened by calling your endpoint. This is where correctness matters most.

```php
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->middleware('stripe.signature');   // NOT auth, NOT feature-gated
```

Four non-negotiable rules:

1. **Verify the signature.** An unverified billing webhook lets anyone grant themselves a free Enterprise plan.
2. **Be idempotent.** Stripe retries. Store every `event_id` in a `processed_webhooks` table and skip duplicates.
3. **Return 200 fast.** Acknowledge, then dispatch a queued job to do the work. Slow responses trigger retries and eventually endpoint suspension.
4. **Never trust webhook order.** `invoice.paid` can arrive before `subscription.created`. Handle out-of-order events by re-fetching current state from the provider rather than assuming sequence.

Events to handle: `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`, `payment_method.attached`, `charge.dispute.created`.

### 11.4 Dunning

| Day | Action |
|---|---|
| 0 | Payment fails → `past_due`, `grace_ends_at = +14 days`, email admin |
| 3 | Retry, reminder email |
| 7 | Retry, warning email |
| 12 | Final notice |
| 14 | Retry; if it fails → `suspended` (degradation level 4) |
| 44 | 30 days suspended → export data, notify, then `expired` |

All intervals in config. Every dunning action logged in `event_log`.

### 11.5 Tax — read this before writing any tax code

VAT and sales tax are jurisdiction-specific, they change, and getting them wrong creates legal liability. Egypt VAT, EU B2B reverse charge, and UK VAT all behave differently and all could apply to this customer base.

**Do not implement tax logic from your own reasoning.** Use Stripe Tax or an equivalent service, store what it returns, and escalate the policy question to the product owner and an accountant. Store `tax_amount` and a `tax_treatment` string; do not compute rates in application code.

Flag this explicitly in your PR as requiring commercial sign-off.

## How to verify

```php
it('rejects a webhook with an invalid signature', ...);
it('processes the same webhook event id only once', ...);
it('handles invoice.paid arriving before subscription.created', ...);
it('issues sequential invoice numbers with no gaps under concurrency', ...);
it('creates a credit note instead of editing an issued invoice', ...);
it('moves to suspended only after the grace period ends', ...);
it('never stores a raw card number anywhere', ...);
```

## Acceptance criteria

- [ ] Cashier integrated; `HotelGroup` is the billable entity
- [ ] No card data in the database — verified by schema review
- [ ] Webhook signature verified; duplicates ignored; 200 returned fast
- [ ] Invoices immutable; corrections via credit note
- [ ] Invoice numbering sequential and concurrency-safe
- [ ] Dunning schedule implemented and configurable
- [ ] Tax delegated to a provider, not hand-computed
- [ ] Entitlements never read live from Stripe

## Traps

1. **Test with Stripe test mode and the CLI** (`stripe listen --forward-to`). Never point at live keys from a development machine.
2. **Webhooks arrive before your redirect does.** Do not depend on the browser returning to complete a subscription.
3. **Refunds and disputes need handling** even if rare. A dispute should alert a human, not silently change state.
4. **Do not store `provider_customer_id` on `User`.** It belongs on the account.

---

# WP-12 — Provisioning and admin console API

**Estimated: 5–6 days.**

## Why

Two gaps close here.

**Self-service signup.** Today, creating a tenant requires a developer. Every SaaS that requires a developer to onboard a customer has a growth ceiling equal to that developer's calendar.

**Internal operations.** Your own team needs to see subscriptions, apply an override, extend a trial, investigate a bill, and suspend an abusive account — without database access. Handing out production database credentials as an operational tool is how customer data leaks.

## What you build

### 12.1 Signup flow

```
POST /api/signup
  → create HotelGroup (account)
  → create first Hotel
  → create owner User, attach via hotel_user, group_role = 'group_admin'
  → start trial subscription on the chosen plan
  → snapshot entitlements
  → seed defaults (activity categories, task categories, sample KB article)
  → return token
```

Wrap the whole thing in a database transaction. A half-created tenant — an account with no subscription, or a user with no hotel — is worse than a failed signup, because it fails later, mysteriously, in a different part of the system.

### 12.2 Admin API

Guarded by the existing `UserRole::SUPER_ADMIN`, and **explicitly outside tenant scoping** — this is the deliberate, audited use of `TenantContext::withoutScope()` promised in Phase 1.

```
GET    /api/admin/accounts
GET    /api/admin/accounts/{id}
POST   /api/admin/accounts/{id}/entitlement-override
POST   /api/admin/accounts/{id}/extend-trial
POST   /api/admin/accounts/{id}/suspend
POST   /api/admin/accounts/{id}/resume
GET    /api/admin/accounts/{id}/usage
GET    /api/admin/accounts/{id}/ai-cost
GET    /api/admin/metrics
```

`/api/admin/metrics` returns MRR, active accounts, trials, trial→paid conversion, churn, portfolio gross margin, and accounts below target margin.

**Every admin action requires a `reason` and writes to `event_log` with the acting super-admin's id.** No exceptions, including read-only access to a specific customer's data. Internal access to customer accounts must be as auditable as customer actions — more so.

### 12.3 Data export and retention

```
POST /api/account/export      → queued job, produces a downloadable archive
```

Available at **every** degradation level, including suspended. Covers guests, stays, reservations, transactions, and recommendations in CSV or JSON.

Retention: `data_retention_months` is already a feature in the catalogue. A monthly job deletes data older than the account's entitlement and logs what it removed. Notify before deleting, never after.

## How to verify

```php
it('rolls back the entire signup when any step fails', ...);
it('blocks a non-super-admin from every admin route', ...);
it('requires a reason on every admin mutation', ...);
it('logs the acting super admin for every admin action', ...);
it('allows export while the account is suspended', ...);
it('does not let an admin route leak across tenants unintentionally', ...);
```

## Acceptance criteria

- [ ] Self-service signup works end to end, transactionally
- [ ] Admin API restricted to super admins, fully audited
- [ ] Metrics endpoint returns MRR, churn, conversion, margin
- [ ] Export available at every degradation level
- [ ] Retention job runs, notifies first, logs what it deleted

## Traps

1. **`withoutScope()` in admin code is a loaded gun.** Use it only in `/api/admin`, never in shared services that tenant requests also call.
2. **Email uniqueness is global.** One person may legitimately hold roles at two accounts. Decide the policy deliberately.
3. **Do not let a super admin impersonate silently.** If you add impersonation, log it loudly and show a banner.

---

## 5. Sequence

```
Week 1     WP-6  ██████ ───────────────────────── plans + entitlements
Week 2     WP-7  ░░░░░░████████ ───────────────── subscription lifecycle   ← CHECKPOINT 1
Week 3     WP-8  ░░░░░░░░░░░░░░███████ ────────── usage metering
Week 4     WP-9  ░░░░░░░░░░░░░░░░░░░░░████████ ─ enforcement               ← CHECKPOINT 2
Week 5     WP-10 ░░░░░░░░░░░░░░░░░░░░░░░░░░░████ AI cost + margin          ← CHECKPOINT 3
Week 6-7   WP-11 ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░██ payments + invoices       ← CHECKPOINT 4
Week 8     WP-12 ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░█ provisioning + admin
```

One WP per branch, one PR per WP, merged before the next begins.

---

## 6. Definition of Done — Phase 2

On a clean database:

- [ ] `php artisan migrate:fresh --seed && php artisan test` — green
- [ ] All Phase 1 acceptance criteria still hold
- [ ] A new customer can sign up, trial, subscribe, and be invoiced without developer involvement
- [ ] Editing a plan provably does not alter existing subscriptions
- [ ] Quota and seat limits enforced; refusals return 402 with an upgrade path
- [ ] A suspended account can still check guests in and out, and still export its data
- [ ] Every AI call is attributed to an account with a measured or explicitly-estimated cost
- [ ] Gross margin per account is visible with its cost basis stated
- [ ] Webhooks verified, idempotent, order-independent
- [ ] No card data anywhere in the schema
- [ ] Every subscription and admin action appears in `event_log` with an actor and a reason
- [ ] `./vendor/bin/pint` clean; `/docs` updated for every endpoint

---

## 7. Principles carried forward from Phase 1

Every Phase 2 rule is one of the Phase 1 five, applied to billing:

| Phase 1 principle | Phase 2 application |
|---|---|
| Record facts, never overwrite them | Entitlements snapshotted; plans versioned; invoices immutable |
| An honest gap beats a confident guess | Estimated AI costs flagged; `cost_basis` always reported |
| Enforce in the framework, not by memory | `feature:` middleware and database constraints, not developer discipline |
| Ledgers are append-only | `meter_events` and `ai_usage_logs`; counters are disposable caches |
| Label how much you trust each claim | Measured vs estimated cost; margin reported with provenance |

Plus one new principle, specific to this phase:

> **6. Degrade the intelligence, never the operations.**
> A billing dispute must never stop a hotel checking a guest in at three in the morning.

---

## 8. Assumptions

| # | Assumption | If wrong |
|---|---|---|
| A-1 | Hotel group is the billing entity | WP-7 and WP-11 restructure significantly |
| A-2 | Stripe is the provider | Cashier variant changes; the rest holds |
| A-3 | EUR is the base pricing currency | Multi-currency pricing is a substantial addition |
| A-4 | Self-service signup is wanted | WP-12 reduces to admin provisioning only |
| A-5 | Tax delegated to a provider | Tax engine becomes its own work package |
| A-6 | Phase 1 is merged and stable | Do not start — Phase 2 has no foundation without it |

---

## 9. Decisions required before WP-11

These are commercial, not technical. Do not resolve them yourself.

1. **Pricing** — final base, per-property, and per-tier limits. Placeholders in §6.4 are for development only.
2. **Trial** — length, and whether a card is required up front. Requiring a card raises quality and lowers volume; both are defensible.
3. **Overage** — bill it, throttle it, or block it? Billing surprises generate churn; blocking generates support calls.
4. **Grace period** — 14 days is proposed. Confirm.
5. **Tax jurisdictions** — which countries will be invoiced in year one? Determines Stripe Tax configuration and needs accountant sign-off.
6. **Data retention on expiry** — how long is data kept after an account expires, and who authorises final deletion?
7. **Target gross margin** — sets the alert threshold in WP-10. Without a number, the alert cannot be configured.

**Confidence:** High for WP-6 through WP-10 — these are standard patterns and depend only on Phase 1, which is verified. Moderate for WP-11, because tax and dunning policy are commercial decisions that shape the implementation. WP-12 is straightforward once the preceding packages exist.

---

*End of document — PGRIP-ECO-P2-001 v1.0.*
