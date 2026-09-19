# Hospitality Ecosystem — Work Package Specification

## Conversational Recommendation Pitching

**Document ID:** PGRIP-ECO-CP-001
**Version:** 1.0
**Date:** 18 September 2026
**Prepared for:** Backend developer (junior level)
**Prepared by:** PGRIP engineering — draft for owner review
**Depends on:** PGRIP-ECO-P1-001 v1.2 + P1-002 addendum (complete); Phase 2 v2.0 WP-8 (usage metering) and WP-10 (AI cost attribution), both on `main`; staff roles (15 Sep 2026), on `main`
**Stack:** PHP 8.3 · Laravel 13 · PostgreSQL + pgvector · `laravel/ai` · Pest 4 · Sanctum

---

## 0. How to read this document

Same structure as the phase plans. Each Work Package has **Why → What you build → How to verify → Acceptance criteria → Traps**.

Package numbers continue the global sequence: Phase 1 was WP-0 to WP-5 and Phase 2 was WP-6 to WP-12, so this document covers **WP-13 to WP-18**.

Work the packages in the order given in §13. WP-13, WP-14 and WP-15 deliver value on their own, even if pitching never ships: delivery becomes measurable and reception gets a list. WP-16 and WP-17 are the pitching feature. WP-18 is the reporting that proves whether pitching works.

Read §2 before anything else. It lists every place this plan differs from the brief it was written from, and why.

**Section numbers.** The document sections are numbered §1 to §16 and the work packages are WP-13 to WP-18, each with its own `13.1`-style subsections. So "§13" means the **Sequence** section, and "13.3" means a subsection of WP-13.

**Estimated total: 18–26 working days** for one developer, full time.

---

## 1. Why this exists

### The situation, in plain words

A hotel guest can send a WhatsApp message to the hotel. Our AI **concierge** (`GuestConciergeAgent`) reads it and replies: checkout time, extra towels, how to get to the beach. That part works today.

The hotel also sells **activities**: a sunset boat trip, a diving course, a spa treatment, a kids' cooking class. Guests often don't know these exist. A good human concierge who hears "the kids are bored" says "we have a kids' cooking class at four, shall I book it?" That is a **pitch**: suggesting one specific thing to one specific guest because of something they just said.

This package teaches the AI concierge to do the same, **carefully**: at most once or twice per visit, only when the guest has opened the door, never when they are upset, and always after solving whatever they actually asked for.

### Domain terms you need first

| Term | Meaning |
|---|---|
| **Reservation** | The *booking of a room*: an intention. It can be cancelled, or the guest may never turn up. Table `reservations`. |
| **Stay** | What actually happened: a person physically in a room from one date to another. One per reservation. Table `stays`. See `App\Models\Stay`. |
| **In-house** | The guest has checked in and not yet checked out (`StayStatus::IN_HOUSE`). |
| **Departure day** | The day the guest leaves. On that day they are still in-house but leaving within hours, and pitching them is pointless and irritating. |
| **Remaining nights** | Nights left before departure. A guest leaving tomorrow has one. |
| **Activity** | Something the hotel sells or includes: tour, spa, class, excursion. Table `activities`. |
| **Booking** | A guest's commitment to an activity: a slot held, the guest expected. Table `bookings`. **This is the success event** — not a payment. |
| **All-inclusive** | The guest's package already covers some activities. They book and pay nothing extra. That is still a full success. |
| **Recommendation** | A stored suggestion of one activity for one reservation. Table `recommendations`. |
| **Outcome** | What happened to a recommendation (delivered, declined, accepted, booked…). Table `recommendation_outcomes`, written only by `RecommendationOutcomeService`. |
| **Attribution / evidence level** | *How we know* an outcome happened, and how much to trust it. L1 = someone or something observed it; L2 = inferred; L4 = reached by elimination. See `AttributionMethod`. |
| **24-hour window / templates** | Meta (WhatsApp) only lets a business send free-form messages within 24 hours of the guest's last message. Outside that window, only pre-approved "template" messages are allowed. **We never initiate, so none of this applies.** |
| **Reception / front desk** | The hotel's staffed desk. Staff there talk to guests face to face. |
| **Self-selection** | Guests who message the hotel are not a random sample: they are more engaged, and more likely to buy anyway. Any conversion rate measured only on them is flattering. |

### What this package delivers

| Question | Answered by |
|---|---|
| Did this guest ever talk to us, on this stay? | WP-13 |
| Can the rules tell whether an activity suits this party and fits in the days they have left? | WP-14 |
| Did a recommendation actually reach the guest, or was it only generated? | WP-15 |
| Is this guest allowed to be pitched right now, and if not, why not? | WP-16 |
| Which activity should be pitched, and why that one? | WP-17 |
| Does pitching work, measured on the right population? | WP-18 |
| Which in-house guests has nobody talked to yet? | WP-18 |

### What does not change

The system **never starts a conversation**. It only replies. There is no outbound message, template, campaign, scheduled send or email anywhere in this plan. If you find yourself writing code that calls `WhatsAppMessageService::send()` from anywhere other than `ProcessInboundWhatsAppMessageJob`'s reply, stop: you are outside scope.

---

## 2. What the repository actually says — contradictions with the brief

The brief was written from an earlier reading of the repo. These are the points where the code says something different. The plan follows the code.

| # | The brief says | The repo says | What this plan does |
|---|---|---|---|
| C-1 | Prior purchases come from `transactions` via `master_guest_id`. | **No `master_guest_id` column exists.** (`Guest::eventLoggedAttributes()` lists it, but that is a dangling name.) Guests are per hotel; `GuestIdentityService::findExistingGuest()` reuses a guest row **within one hotel only**, deliberately. | Prior-stay history = `transactions` for the same `guest_id` on *other* stays at the same hotel. History across a hotel group is not available and is a privacy and tenancy decision (§15, D-9). |
| C-2 | Gates: "remaining nights insufficient for the activity", "no capacity on any date". | Since 18 Sep 2026 (`9886d45`) activities carry a **timeframe**: season (`available_from`/`available_until`), weekday `operating_hours` in hotel time, and `unavailable_periods`. Null = no restriction. There is still **no capacity, duration (in days) or audience data**, and bookings do not check the timeframe. | WP-16 uses the timeframe to work out which remaining days an activity is open. WP-14 adds the three missing optional columns. For those, `null` means *unknown* and never fires a gate; the unknown is recorded in provenance. |
| C-3 | Put `first_contacted_at` / `last_contacted_at` on `guests`. | Guest rows are reused across stays at the same hotel. A guest-level timestamp cannot say whether a guest who messaged in March *also* messaged during their September stay, once they message again in October. | Stamp both `guests` **and** `stays`. Segments are computed per stay (WP-13). |
| C-4 | Store provenance in the outcome's `context` JSON. | `recommendation_outcomes` holds **one row per recommendation, upserted** (`unique('recommendation_id')`). `RecommendationOutcomeService::record()` writes `'context' => $attributes['context'] ?? null`, `'evidence_quote' => … ?? null` and `'confidence' => … ?? null` on every update. `BookingService::creditRecommendation()` passes none of them. **So at the moment of conversion, the pitch's context and the guest's quoted "yes" are overwritten with null.** | Provenance goes in a new append-only `pitch_decisions` table (WP-16). WP-15 also fixes the overwrite, so earlier L1 evidence is preserved when a stronger outcome supersedes it. |
| C-5 | Attribution is `CONVERSATIONAL`, L1. | A booking carrying `recommendation_id` is credited `AttributionMethod::DIRECT` by `BookingService`, and DIRECT **outranks** CONVERSATIONAL (precedence 4 > 3). | No change needed: both are L1 and the precedence chain is correct. But say it plainly in the docs. **Converted pitches appear under `attribution.direct`**, while CONVERSATIONAL holds accepted, declined and delivered outcomes that did not become bookings. |
| C-6 | `NOT_DELIVERED` finally gets a writer. | Staff can already write it through `POST /api/recommendation/{id}/outcome`. What is missing is an **automatic** writer and a measured `delivered_at`. | WP-15 adds `delivered_at` and teaches the nightly `MatchRecommendationOutcomesJob` to write NOT_DELIVERED instead of EXPIRED when nothing was ever delivered. |
| C-7 | Pitches only at contextual openings; never appended to an unrelated reply. | The current prompt tells the agent to recommend *"when you're wrapping up a conversation about their stay"* — i.e. appended to unrelated replies. It also says *"tailor a recommendation yourself"*, which creates **no recommendation row**. Those pitches are invisible to every metric today. | WP-17 removes both paragraphs. Every suggestion now goes through one tool that writes a row. |
| C-8 | Queue jobs set tenancy via `TenantContext::runForHotel()`, "as already done". | Only `CreateAiInsightsJob` and `MatchRecommendationOutcomesJob` do. **`ProcessInboundWhatsAppMessageJob` runs unscoped**; its tools filter by `hotel_id` by hand. | New pitching code wraps *its own* queries in `runForHotel()`. **Do not wrap the whole concierge call** — see WP-16 Trap 1. |
| C-9 | "Departing today." | `SenderRecognitionService` uses `now()->toDateString()` in the app timezone (UTC). `hotels.timezone` exists. | All new date logic uses the hotel's local date (WP-16). |
| C-10 | Segment signal: nationality, market segment. | `stays.market_segment` is **never written** by any code path. `guests.nationality` is filled only when someone types it. | Recommend excluding segment from ranking entirely; record it in provenance only (§15, D-6). |
| C-11 | "Already declined anything this stay." | `UpdateRecommendationTool` sets `recommendations.status = rejected` on an explicit no. But the service's **confidence floor** can record the *outcome* as DELIVERED when the agent was unsure. | The decline gate reads `recommendations.status`, not the outcome (WP-16 Trap 4). |
| C-12 | "The conversation is a complaint." | There is no structural marker for a complaint. `EscalateToHumanTool` creates a `Task` with a fixed title and **no `reservation_id`**. `CreateGuestServiceRequestTool` is used both for "the AC is broken" *and* for "help this guest book the boat trip". | WP-16 adds `tasks.guest_signal` and a layered detection proposal (§WP-16, 16.4). |
| C-13 | `delivered_at` = "the agent mentioned it in a reply". | `WhatsAppMessageService::send()` returning means **Meta accepted the message**, not that it reached the phone. The webhook ignores Meta's delivery-status callbacks. | `delivered_at` means "sent in a reply that WhatsApp accepted". Device receipts are future work, noted in the docs. |
| C-14 | Backfill from `agent_conversations`. | Guests have **one lifelong conversation** (`continueLastConversation()`); `agent_conversations` has no `hotel_id` and no status. Messages must be mapped to stays by timestamp. | WP-13 backfill command maps each `role = 'user'` message to a stay using the same rule `SenderRecognitionService` uses live. |
| C-15 | — | `RecommendationStatus::SENT` exists and nothing writes it. | WP-15 writes it on delivery. |

### One objection to the brief itself

**"Enforced in code rather than in the agent prompt" cannot be fully true for complaint detection.** Deciding whether *"the room is fine but the pool was freezing, what else is there?"* is a complaint needs language understanding, in any of the guests' languages. This plan gets as close as it honestly can:

- a **structural** layer in code: escalations and open service requests on this stay;
- a **separate classifier** whose output code consumes as a hard gate. It is not the pitching agent's own judgment, because an agent told to sell should not also decide whether it may sell;
- a **same-turn backstop** in code.

The classifier is still an LLM. That is stated here rather than hidden. It is also *not* a scoring model: it decides whether a gate is open, never what to rank first. Ranking stays pure rules (§WP-17).

---

## 3. Scope

| WP | Name | Days (est.) |
|---|---|---|
| WP-13 | Contact stamping and backfill | 2–3 |
| WP-14 | Activity pitching attributes | 1–2 |
| WP-15 | Delivery tracking | 3–4 |
| WP-16 | Eligibility gates, complaint detection, decision provenance | 5–7 |
| WP-17 | Ranking, the pitch tool, concierge integration | 4–6 |
| WP-18 | Engagement segments and the reception list | 3–4 |

### Out of scope

- Outbound messaging of any kind, email, templates, campaigns, scheduled sends
- Consent management: guests give consent when they make the reservation. The `guests.marketing_consent` column was dropped on 18 Sep 2026 (`2026_09_18_000000_drop_marketing_consent_from_guests_table`)
- A scoring or ML model for ranking
- Payment, deposit or pricing logic
- Frontend work (the APIs below are consumed by a frontend built separately)
- A slot or per-time capacity model for activities (WP-14 adds a daily capacity only)
- Making bookings respect the activity timeframe (a separate change; see WP-17 Trap 6)
- Meta delivery and read receipts

---

## 4. Vocabulary for this package

| Term | Meaning here |
|---|---|
| **Pitch** | The concierge suggests **one specific activity** to this guest as a personal recommendation, through `PitchActivityTool`. Answering a factual question ("how much is the spa?") is not a pitch. |
| **Opening** | Something in the guest's *current* message that makes a suggestion welcome: they ask what to do, mention boredom, the kids, the weather, the evening. The list is an owner decision (§15, D-2). |
| **Explicit request** | An opening where the guest *asks for* a suggestion ("what do you recommend?"). Treated differently from a contextual opening — see 16.2. |
| **Gate** | A hard rule that blocks pitching for the whole turn. Evaluated in code. |
| **Candidate** | An active activity that survived all per-activity exclusions. |
| **Shortlist** | The top N ranked candidates (default 3) the agent may choose from. |
| **Turn** | One inbound guest message plus the one reply to it. One run of `ProcessInboundWhatsAppMessageJob`. |
| **Staged** | The agent has called the pitch tool; a recommendation row exists; the reply has **not** been sent yet. |
| **Delivered** | The reply containing the pitch was accepted by WhatsApp. `recommendations.delivered_at` is stamped. |
| **Decision** | One row in `pitch_decisions`: why this turn was or was not pitched. |

---

# WP-13 — Contact stamping and backfill

**Estimated: 2–3 days.**

## Why

Nothing in the system records, at guest or stay level, that a conversation happened. You can find it by digging through `agent_conversation_messages`, but no report can join to it cheaply, and nothing links a message to a stay.

This matters because the whole reactive model has a **reach ceiling**: it can only ever pitch guests who message first. If 70% of in-house guests never message, then no amount of prompt tuning touches 70% of the house. That number must be visible before anyone argues about conversion rates.

It also makes one operational output possible: **a list for reception of in-house guests nobody has talked to**. A receptionist chatting to a guest in the lobby is a staff conversation, not a system-initiated message. It is allowed even where outbound messaging is prohibited.

### Why stays, not only guests

A guest row is reused every time the same person returns to the same hotel. Suppose Ms Weber messaged during her March stay, stays again in September without messaging, and messages once in October about a lost item. Her `guests.last_contacted_at` now says October. Ask "did she message during her September stay?" and the guest row cannot answer. The unit of analysis for every report in WP-18 is the **stay**, so the stamp must live on the stay.

The guest-level columns stay because they are cheap and useful (e.g. "never contacted, ever").

## What you build

### 13.1 Migration

```php
// database/migrations/2026_09_19_000000_add_contact_timestamps_to_guests_and_stays.php
Schema::table('guests', function (Blueprint $table) {
    // When this person first and last messaged the hotel, on any stay.
    // Written only by GuestContactService — never by the API.
    $table->timestamp('first_contacted_at')->nullable();
    $table->timestamp('last_contacted_at')->nullable();

    $table->index(['hotel_id', 'last_contacted_at']);
});

Schema::table('stays', function (Blueprint $table) {
    // Inbound messages attributed to THIS stay, using the same rule
    // SenderRecognitionService uses to pick the reservation live.
    $table->timestamp('first_contacted_at')->nullable();
    $table->timestamp('last_contacted_at')->nullable();

    // Serves the reception list: in-house, never contacted.
    $table->index(['hotel_id', 'status', 'first_contacted_at']);
});
```

Do not add these columns to either model's `$fillable`. They are written by one service, never by a client request. `GenericUpdateRequest` derives its rules from `$fillable`, so leaving them out is what keeps `PUT /api/guest/{id}` from accepting them.

### 13.2 The service

```php
// app/Services/GuestContactService.php
class GuestContactService
{
    /**
     * Record that the guest messaged us at $at, against the stay the message
     * was resolved to (if any). Idempotent and safe out of order: first_* only
     * ever moves earlier, last_* only ever moves later.
     */
    public function recordInbound(Guest $guest, ?Stay $stay, CarbonInterface $at): void;
}
```

Write it as **one SQL statement per table**, not `$guest->update()`:

```php
DB::update(
    'UPDATE guests
        SET first_contacted_at = LEAST(COALESCE(first_contacted_at, ?), ?),
            last_contacted_at  = GREATEST(COALESCE(last_contacted_at, ?), ?)
      WHERE id = ?',
    [$at, $at, $at, $at, $guest->getKey()],
);
```

Same for the stay. (`DB::raw()` inside `->update([...])` cannot take bindings, which is why this is a plain `DB::update()`.) Two reasons for avoiding the model, both in the Traps.

### 13.3 Where it is called

In `ProcessInboundWhatsAppMessageJob::handle()`, **as the first thing** for a `SenderType::GUEST` sender, before the agent is built:

```php
if ($this->senderType === SenderType::GUEST) {
    try {
        app(GuestContactService::class)->recordInbound(
            $this->sender,
            $this->reservation?->stay,
            $this->receivedAt ? Carbon::createFromTimestamp($this->receivedAt) : now(),
        );
    } catch (\Throwable $e) {
        report($e);   // never stop the reply
    }
}
```

It must come first because the AI call can throw. For example, `AiCostContext::for()` throws `AiSpendCeilingExceededException` when the daily ceiling is hit. The guest *did* contact us whether or not we managed to answer.

Add a nullable `public ?int $receivedAt = null` constructor argument to the job. In `WhatsAppController::whatsappWebhook()`, pass Meta's `messages.0.timestamp` (Unix seconds) into it, so a queue delay does not shift the contact time.

### 13.4 Backfill command

```php
// app/Console/Commands/BackfillGuestContactTimestamps.php
// php artisan guests:backfill-contact-timestamps {--hotel=} {--dry-run}
```

For each hotel (inside `TenantContext::runForHotel()`), for each guest with at least one conversation:

1. Load the guest's `role = 'user'` messages from `agent_conversation_messages` where `participant_type` is `Guest`'s morph class and `participant_id` is the guest's id. `Guest::conversations()` shows the pattern.
2. Load the guest's stays.
3. For each message, pick the stay **with the same rule as `SenderRecognitionService::relevantReservationFor()`**, evaluated at the message's date: the in-house stay covering that date; otherwise the next upcoming one; otherwise the most recent past one.
4. Apply `GuestContactService::recordInbound()`. Because it is `LEAST`/`GREATEST`, re-running is harmless.

Write **one** summary `EventLogger::record($hotel, 'guest_contact_backfilled', changes: [...])` per hotel, the way `MatchRecommendationOutcomesJob` does, with counts of guests, stays and unmapped messages.

A migration is the wrong place for this. The mapping is per-message PHP logic over a package-owned table, and it must be re-runnable.

### 13.5 API exposure

`GuestResource` and `StayResource` add `first_contacted_at` and `last_contacted_at`, read-only. Update `docs/guest-api-documentation.md`.

## How to verify

```php
// tests/Feature/GuestContactTest.php
it('stamps first and last contact on the guest and the resolved stay', ...);
it('never moves first_contacted_at later or last_contacted_at earlier', ...);
it('stamps contact even when the AI call throws', ...);                    // ← the important one
it('does not write an event_log row per inbound message', ...);
it('still replies to the guest when contact stamping throws', ...);
it('ignores contact fields sent through the guest update endpoint', ...);
it('backfills stays using the same stay-selection rule as live recognition', ...);
it('is idempotent when the backfill runs twice', ...);
it('does not stamp another hotel\'s guest during backfill', ...);
```

## Acceptance criteria

- [ ] Both tables carry the two timestamps; neither is fillable
- [ ] Every inbound guest message stamps guest and stay before the agent runs
- [ ] Stamping failure never prevents the reply
- [ ] No `event_log` flood from contact stamping
- [ ] Backfill command is idempotent and logs one summary event per hotel
- [ ] Guest and stay resources expose the fields; docs updated

## Traps

1. **`Guest` and `Stay` both use `RecordsEvents`.** `$guest->update([...])` on every WhatsApp message writes an `event_log` row per message, forever. A plain `DB::update()` bypasses model events.
2. **Read-then-write races.** Two messages processed by two workers at once can each read "null" and write their own time. `LEAST`/`GREATEST` in one statement makes the order irrelevant.
3. **A guest phone number can exist at two hotels.** `SenderRecognitionService::resolve()` uses `Guest::where('phone_number', …)->first()`, unscoped, so which row is picked is arbitrary. Out of scope to fix here, but do not "fix" it by stamping every matching row. That writes one hotel's contact onto another hotel's guest.
4. **Messages before the job existed are not in the table.** A job that failed before the agent ran persisted no message. The backfill is a *floor*, not an exact history, and the doc must say so.

---

# WP-14 — Activity pitching attributes

**Estimated: 1–2 days.**

## Why

Three of the brief's rules need facts the activity catalogue does not hold:

- *"Remaining nights insufficient"* — a 3-day diving course cannot be pitched to someone leaving tomorrow. Nothing records that it takes 3 days. The timeframe says *which* days it runs, not *how many* it takes.
- *"No capacity"* — nothing records how many people an activity can take.
- *"Party composition"* — ranking a kids' class above a couples' massage for a family requires knowing which is which. Today that lives only in free-text descriptions. Reading descriptions to decide would mean the model, not rules, is ranking.

*When* an activity runs is already covered: the timeframe columns added on 18 Sep 2026 (season, weekday hours, closures) are used by WP-16 as they are. This package adds only what is still missing.

The honest options are to add the data or to drop the rules. Adding three nullable columns is cheap. **A null means "unknown", and an unknown never blocks anything.** Hotels that fill them in get sharper gates; hotels that don't get today's behaviour, and the provenance says `unknown` rather than pretending.

## What you build

### 14.1 Migration

```php
// database/migrations/2026_09_19_000001_add_pitching_attributes_to_activities_table.php
Schema::table('activities', function (Blueprint $table) {
    $table->string('audience')->nullable();                       // App\Enums\ActivityAudience; null = not stated
    $table->unsignedSmallInteger('duration_days')->nullable();     // consecutive days it takes; null = a single day
    $table->unsignedInteger('daily_capacity')->nullable();        // people per day; null = unknown, never gated
});
```

```php
// app/Enums/ActivityAudience.php
enum ActivityAudience: string
{
    case ALL = 'all';                  // suits anyone
    case FAMILY = 'family';            // designed with children in mind
    case ADULTS_ONLY = 'adults_only';  // not suitable for, or not open to, children
}
```

Add the three to `Activity::$fillable` and `$casts` (`audience` → `ActivityAudience::class`, the other two → `integer`).

### 14.2 Validation

`ActivityController` validates with `StoreActivityRequest` / `UpdateActivityRequest`. These extend the generic requests (rules derived from `$fillable` by `ModelColumnRules`) and add the timeframe rules through the `ValidatesActivityTimeframe` concern. Follow the same pattern: a `ValidatesActivityPitchingAttributes` concern, used by both requests, that adds:

- `audience`: `nullable`, `Rule::enum(ActivityAudience::class)`
- `duration_days`: `nullable|integer|min:1|max:30`
- `daily_capacity`: `nullable|integer|min:1`

`daily_capacity` starts at 1 on purpose. See Trap 1.

### 14.3 Meaning of each field

| Field | Rule it feeds | Used as |
|---|---|---|
| `duration_days` | How many **consecutive** days the activity takes (a 3-day diving course = 3). A start date is valid only if that many consecutive remaining dates, starting on it, are all open (16.3). `null` is treated as 1, i.e. a single-day activity, so only the open-dates check applies. | Per-candidate exclusion |
| `daily_capacity` | Gate: a date is full when the sum of `pax` on non-cancelled bookings for this activity that day ≥ capacity. Excluded only when **every** remaining open date (16.3) is full. | Per-candidate exclusion |
| `audience` | Ranking only. `FAMILY` ranks up for parties with children; `ADULTS_ONLY` ranks down for them. Never an exclusion: parents may want the couples' spa while the kids are at kids' club. | Ranking key |

`daily_capacity` is deliberately crude. A real timetable (slots, times, resources) is its own module and out of scope. The booking lifecycle already covers the gap: a booking is created `PENDING` ("slot not yet confirmed") and staff confirm it.

Update `docs/activity-api-documentation.md`. No new permission: `activities.update` already covers editing an activity.

## How to verify

```php
// tests/Feature/ActivityControllerTest.php (extend)
it('accepts and returns audience, duration_days and daily_capacity', ...);
it('rejects a duration_days of zero', ...);
it('rejects an unknown audience value', ...);
it('rejects a negative daily_capacity', ...);
it('leaves all three null when not supplied', ...);
```

## Acceptance criteria

- [ ] Three nullable columns exist; `null` is documented as "unknown"
- [ ] Activity create/update validates them through the generic rules
- [ ] Activity docs updated

## Traps

1. **Do not default `daily_capacity` to 0.** Zero means "full every day" and silently disables pitching for the whole catalogue. Null means unknown.
2. **Do not backfill `audience` by guessing from category names.** "Kids Club" looks obvious; "Adventure" does not. An inferred audience is an L3 guess stored in an L1-looking column.

---

# WP-15 — Delivery tracking

**Estimated: 3–4 days.**

## Why

A recommendation can be *generated* (a row exists) without ever *reaching the guest*. Today nothing measures the difference. `AnalyticsController::conversion()` assumes every recommendation was delivered unless a staff member said otherwise, and says so in its notes. So every booking rate the system reports divides by a number that is partly a guess.

`MeterFeature::RECOMMENDATIONS_DELIVERED` is listed in `awaitingSource()` for exactly this reason. Its docblock says it "needs the measured delivery timestamp from P1-002 A-1".

The rule for this package: **retrieving is not delivering**. The agent calling `GetRecommendationsTool` proves nothing. Delivery is stamped only when:

- the pitch was declared through the pitch tool **and** the reply was accepted by WhatsApp; or
- a person or a booking proves the guest was offered it.

This package also fixes the overwrite described in §2 C-4, because pitching makes that path the normal one.

## What you build

### 15.1 Migration

```php
// database/migrations/2026_09_19_000002_add_delivery_to_recommendations_table.php
Schema::table('recommendations', function (Blueprint $table) {
    // When the guest was actually offered it. Null = never delivered (or
    // delivered before tracking began — see pitching.delivery_tracking_since).
    $table->timestamp('delivered_at')->nullable()->after('recommended_at');
    $table->string('delivery_channel')->nullable()->after('delivered_at');   // App\Enums\DeliveryChannel

    $table->index(['hotel_id', 'delivered_at']);
});
```

No backfill. Existing rows stay null, because we do not know. That is the honest gap.

```php
// app/Enums/DeliveryChannel.php
enum DeliveryChannel: string
{
    case WHATSAPP = 'whatsapp';        // sent in a concierge reply
    case FACE_TO_FACE = 'face_to_face';
    case PHONE = 'phone';
}
```

Add both columns to `Recommendation::$casts` and to `eventLoggedAttributes()`. **Do not** add them to `$fillable`, so `PUT /api/recommendation/{id}` cannot set them.

### 15.2 The delivery service

```php
// app/Services/RecommendationDeliveryService.php
class RecommendationDeliveryService
{
    /**
     * Stamp delivery once. Returns true only when this call stamped it — a
     * second call is a no-op and does not meter again.
     *
     * Also moves status PENDING → SENT, and records one
     * RECOMMENDATIONS_DELIVERED meter event through MeteringService::safely().
     */
    public function markDelivered(
        Recommendation $recommendation,
        DeliveryChannel $channel,
        CarbonInterface $at,
        ActorKind $actorKind,
    ): bool;
}
```

Implement the "once" with a conditional update, not a read-then-write:

```php
$stamped = Recommendation::withoutGlobalScope('hotel')
    ->whereKey($recommendation->getKey())
    ->whereNull('delivered_at')
    ->update(['delivered_at' => $at, 'delivery_channel' => $channel->value]) === 1;
```

Then, only if `$stamped`, move the status and meter:

```php
$metering->safely(fn (MeteringService $m) => $m->recordForHotel(
    hotel: $recommendation->hotel,
    feature: MeterFeature::RECOMMENDATIONS_DELIVERED,
    source: $recommendation,
    idempotencyKey: MeterFeature::RECOMMENDATIONS_DELIVERED->value.':'.$recommendation->getKey(),
    metadata: ['channel' => $channel->value],   // no prices, no values
    actorKind: $actorKind,
));
```

Remove `RECOMMENDATIONS_DELIVERED` from `MeterFeature::awaitingSource()` and rewrite that docblock. `CONVERSATIONS_HANDLED` stays there.

### 15.3 Who calls it

| Caller | When | Channel | Actor |
|---|---|---|---|
| `PitchCoordinator::complete()` (WP-17) | After `WhatsAppMessageService::send()` returns | `WHATSAPP` | `AI_AGENT` |
| `UpdateRecommendationTool::handle()` | The guest reacted to it in chat, so they saw it | `WHATSAPP` | `AI_AGENT` |
| `RecommendationController::recordOutcome()` | A staff-recorded DELIVERED, DECLINED, ACCEPTED or BOOKED outcome | from the request's `channel` (default `FACE_TO_FACE`) | `USER` |
| `BookingService::creditRecommendation()` | A booking carrying the id exists, so someone offered it | from `booking.channel` if it maps, else `FACE_TO_FACE` | `USER` or `AI_AGENT` per context |

**Not** `MatchRecommendationOutcomesJob`. An inferred booking says nothing about whether our offer reached the guest; that is the whole point of L2.

### 15.4 The automatic NOT_DELIVERED writer

In `MatchRecommendationOutcomesJob::expireUndecided()`, split by delivery, for recommendations `recommended_at >= config('pitching.delivery_tracking_since')`:

| `delivered_at` | Outcome written | Method |
|---|---|---|
| not null | `EXPIRED` (as today) — offered, guest left undecided | `NONE` |
| null | `NOT_DELIVERED` — generated, never reached the guest | `NONE` |

Recommendations from before the cutover keep today's behaviour (EXPIRED). The cutover date is a config value set once at deploy:

```php
// config/pitching.php (created here, extended in WP-16)
'delivery_tracking_since' => env('PITCHING_DELIVERY_TRACKING_SINCE'),   // ISO date; null = not yet live
```

When it is null, nothing changes. That makes the deploy safe.

> **Implemented without the cutover (19 Sep 2026, owner decision).** The system is still an MVP with no production guests or recommendations, so there is no old data for a cutover to protect. Delivery is always measured: the nightly job writes NOT_DELIVERED whenever `delivered_at` is null, conversion analytics counts only stamped deliveries, and there is no `delivery_basis` field, `PITCHING_DELIVERY_TRACKING_SINCE` variable or `config/pitching.php` from this package (WP-16 creates the config file). Trap 4 and the "mixed" basis in 15.5 no longer apply. If this code is ever deployed onto a database that already holds recommendations, reintroduce the cutover first.

### 15.5 Conversion analytics

`AnalyticsController::conversion()` gains one field and changes one number:

- `delivered`: for recommendations on or after the cutover, count `delivered_at IS NOT NULL`; before it, keep the assumption.
- new `delivery_basis`: `"measured"`, `"assumed"` or `"mixed"`, depending on which side of the cutover the period falls.
- `notes`: replace the fixed sentence *"Delivery is assumed unless…"* with one that states the basis and, when mixed, the share that was measured.

Additive for clients. But the **denominator of `booking_rate` changes** for new data: it now excludes recommendations that never reached anyone. Record this in `docs/latest-changes-<date>.md`.

### 15.6 Fix: stronger outcomes must not erase weaker evidence

In `RecommendationOutcomeService::record()`, when `$existing` is being overwritten by an equal-or-stronger method, carry the superseded row's evidence into `context` before the update:

```php
$payload['context'] = [
    ...($attributes['context'] ?? []),
    'superseded' => [
        ...($existing->context['superseded'] ?? []),   // keep the chain
        [
            'outcome' => $existing->outcome->value,
            'attribution_method' => $existing->attribution_method->value,
            'evidence_quote' => $existing->evidence_quote,
            'confidence' => $existing->confidence,
            'decline_reason' => $existing->decline_reason,
            'occurred_at' => $existing->occurred_at?->toIso8601String(),
        ],
    ],
];
```

Why not simply keep the old `evidence_quote` on the new row? A guest who said *"no, too expensive"* and later booked anyway would carry a refusal quote on a BOOKED row. The chain keeps each statement next to the classification it supported.

Also use the delivery time for `minutes_to_outcome`: `delivered_at ?? recommended_at`. "How long after they saw it" is the question; for pre-generated recommendations the difference can be days. Note this change in the latest-changes doc.

Update `docs/recommendation-outcome-api-documentation.md`, `docs/conversion-analytics-api-documentation.md` and `docs/usage-metering-api-documentation.md`.

## How to verify

```php
// tests/Feature/RecommendationDeliveryTest.php
it('stamps delivery once and meters it once', ...);
it('moves a pending recommendation to sent on delivery', ...);
it('does not stamp delivery when the agent only read the recommendations', ...);   // ← the important one
it('stamps delivery when staff record a face-to-face decline', ...);
it('stamps delivery when a booking carries the recommendation id', ...);
it('does not stamp delivery for an inferred booking', ...);
it('still saves the booking when delivery metering throws', ...);
it('rejects delivered_at sent through the recommendation update endpoint', ...);

// tests/Feature/RecommendationMatchingTest.php (extend)
it('writes not_delivered for an undelivered recommendation after departure', ...);
it('writes expired for a delivered but undecided recommendation after departure', ...);
it('keeps expired for recommendations made before delivery tracking began', ...);

// tests/Feature/RecommendationOutcomeTest.php (extend)
it('keeps the guest\'s quoted acceptance when a booking supersedes it', ...);
it('measures minutes to outcome from delivery, not generation', ...);

// tests/Feature/ConversionAnalyticsTest.php (extend)
it('reports measured delivery after the cutover and says so', ...);
it('reports a mixed delivery basis across the cutover', ...);

// tests/Feature/UsageMeteringTest.php (extend)
it('no longer lists recommendations_delivered as awaiting a source', ...);
```

## Acceptance criteria

- [ ] `delivered_at` and `delivery_channel` exist, are not client-writable, and are event-logged
- [ ] One service stamps delivery; it is idempotent and meters exactly once
- [ ] Every observed path stamps; the inference path does not
- [ ] Nightly job writes NOT_DELIVERED after the cutover, EXPIRED before it
- [ ] Conversion analytics reports `delivery_basis`
- [ ] A superseding outcome preserves the earlier quote in `context.superseded`
- [ ] `RECOMMENDATIONS_DELIVERED` is recorded, not awaiting a source; docs updated

## Traps

1. **Retrieval is not delivery.** Nothing in `GetRecommendationsTool` may stamp anything. Write the test that proves it first.
2. **The meter must not carry money.** No `expected_value`, no price in `metadata`. Meter events are counts.
3. **Metering must never break a booking or a reply.** Always through `MeteringService::safely()`.
4. **The cutover is a date, not a boolean.** Reports over old periods must keep reading the old rule. A flag flipped at deploy would rewrite history.
5. **`superseded` grows only on overwrite.** A same-method, same-outcome re-record (e.g. the nightly job running twice) must not append a duplicate entry. Compare before appending.

---

# WP-16 — Eligibility gates, complaint detection, decision provenance

**Estimated: 5–7 days.**

## Why

This package answers "may we pitch this guest right now?" **in code**, before the agent is even built. It records the answer and the reasons, whether it was yes or no.

Why in code: a prompt that says "don't pitch more than once" works in testing and drifts in production. The model forgets, gets talked round, or decides this case is special. A counter in a `WHERE` clause does not.

Why the answer is recorded even when it is "no": WP-18 must tell apart *"the agent had an opening and stayed silent"* (a missed opportunity) from *"the rules blocked it"* (restraint working). Without a row per turn, both look the same: no pitch.

## What you build

### 16.1 Configuration

```php
// config/pitching.php
return [
    // Master switch. Ships false: the feature is built dark and turned on
    // for the pilot hotel by environment. See §15 D-8 for per-hotel control.
    'enabled' => (bool) env('PITCHING_ENABLED', false),

    // Unsolicited pitches per stay. Explicit requests do not count — §15 D-1.
    'max_unsolicited_per_stay' => (int) env('PITCHING_MAX_PER_STAY', 1),

    'shortlist_size' => 3,

    // Pitch guests whose stay is EXPECTED (not yet arrived) — §15 D-5.
    'allow_pre_arrival' => false,

    'complaint' => [
        'escalation_blocks_rest_of_stay' => true,     // §15 D-3
        'open_service_request_lookback_hours' => 24,  // §15 D-3
        'classifier_history_messages' => 4,
    ],

    // Bumped whenever a gate or ranking rule changes, and written into every
    // decision row, so outcomes can be compared across rule versions.
    'rules_version' => '1.0',

    'delivery_tracking_since' => env('PITCHING_DELIVERY_TRACKING_SINCE'),
];
```

### 16.2 Openings

```php
// app/Enums/PitchOpening.php
enum PitchOpening: string
{
    // Explicit — the guest asked for a suggestion.
    case ASKS_WHAT_TO_DO = 'asks_what_to_do';
    case ASKS_ABOUT_ACTIVITIES = 'asks_about_activities';

    // Contextual — the guest opened a door without asking.
    case BEACH_OR_POOL = 'beach_or_pool';
    case EVENING_PLANS = 'evening_plans';
    case BOREDOM = 'boredom';
    case CHILDREN = 'children';
    case WEATHER = 'weather';

    public function isExplicitRequest(): bool
    {
        return in_array($this, [self::ASKS_WHAT_TO_DO, self::ASKS_ABOUT_ACTIVITIES], true);
    }
}
```

The list is the brief's, and the final list is the **owner's decision** (§15, D-2). Build it as an enum so adding a case is a one-line, reviewed change.

**Why explicit requests are treated differently.** The cap and the one-refusal rule exist to stop the system *pushing*. A guest who was pitched on Monday and asks on Wednesday *"any ideas for tonight?"* is not being pushed; refusing to suggest anything would be bad service, and "service comes before selling". So:

| Gate | Contextual opening | Explicit request |
|---|---|---|
| Pitch cap | applies | does **not** apply |
| Already declined this stay | applies | does **not** apply |
| Every other gate | applies | applies |

Both kinds go through the same tool and are recorded the same way, so nothing becomes invisible. WP-18 reports them separately.

### 16.3 Gates

```php
// app/Enums/PitchGate.php — every reason a turn can be blocked
enum PitchGate: string
{
    case FEATURE_DISABLED = 'feature_disabled';
    case NO_STAY = 'no_stay';
    case NOT_IN_HOUSE = 'not_in_house';
    case DEPARTING = 'departing';                       // departure day, or already departed
    case PITCH_CAP = 'pitch_cap';                       // contextual openings only
    case DECLINED_THIS_STAY = 'declined_this_stay';     // contextual openings only
    case ESCALATED_THIS_STAY = 'escalated_this_stay';
    case OPEN_SERVICE_REQUEST = 'open_service_request';
    case CLASSIFIER_FAILED = 'classifier_failed';
    case COMPLAINT_THIS_TURN = 'complaint_this_turn';
    case NO_OPENING = 'no_opening';
    case NO_CANDIDATES = 'no_candidates';
}
```

Where each check lives and what it reads. "Today" is **always** `now($hotel->timezone)->toDateString()`.

| Gate | Stage | Reads | Blocks when |
|---|---|---|---|
| `FEATURE_DISABLED` | cheap | `config('pitching.enabled')` | false |
| `NO_STAY` | cheap | `$reservation?->stay` | no stay (fail closed) |
| `NOT_IN_HOUSE` | cheap | `stays.status` | not `IN_HOUSE` (or `EXPECTED` when pre-arrival is allowed) |
| `DEPARTING` | cheap | `stays.planned_departure_date`, `checked_out_at` | departure date ≤ today, or checked out |
| `PITCH_CAP` | after classifier | `recommendations` joined to `pitch_decisions` | unsolicited pitches this stay ≥ cap |
| `DECLINED_THIS_STAY` | after classifier | `recommendations.status` for this reservation | any `REJECTED` |
| `ESCALATED_THIS_STAY` | cheap | `tasks.guest_signal = escalation` | any since the stay's arrival date |
| `OPEN_SERVICE_REQUEST` | cheap | `tasks.guest_signal = service_request` | open (`pending`, `in_progress`) and created within the lookback |
| `CLASSIFIER_FAILED` | classifier | exception from `TurnSignalClassifier` | it threw — fail closed |
| `COMPLAINT_THIS_TURN` | classifier | `TurnSignal::$complaint` | true |
| `NO_OPENING` | classifier | `TurnSignal::$opening` | null |
| `NO_CANDIDATES` | candidates | `CandidateList` | shortlist empty |

The cap and decline gates run *after* the classifier only because they depend on whether the opening is explicit. They are still pure database reads.

**Counters are derived, never stored.** "Pitches this stay" is a `COUNT(*)` over recommendations linked to a `pitch_decisions` row for this stay whose opening was contextual. There is no `pitch_count` column to drift. This is the same rule the Phase 2 plan applied to usage: *events are the truth, totals are derived*.

Per-activity exclusions (applied when building candidates, recorded per activity):

```php
// app/Enums/CandidateExclusion.php
enum CandidateExclusion: string
{
    case ALREADY_BOOKED = 'already_booked';             // non-cancelled booking for this activity, this stay
    case NOT_ENOUGH_DAYS = 'not_enough_days';           // no run of duration_days consecutive open dates before departure
    case CLOSED_ON_ALL_DATES = 'closed_on_all_dates';   // timeframe: no remaining date is open — see below
    case NO_CAPACITY = 'no_capacity';                   // full on every remaining open date (known capacity only)
    case CLASHES_ON_ALL_DATES = 'clashes_on_all_dates'; // clashes on every remaining open date — see below
    case OUTSIDE_INTEREST = 'outside_interest';         // guest asked about a different category
}
```

**Open dates, from the activity timeframe.** The *remaining dates* are today through the day before `planned_departure_date`, in the hotel's timezone. A remaining date is **open** for an activity when all of these hold:

1. it is inside the season: on or after `available_from`, and on or before `available_until` (a null bound is no limit);
2. it is not inside any `unavailable_periods` range (both ends inclusive);
3. `operating_hours` is null, or that weekday has at least one slot;
4. for **today** only: `operating_hours` is null, or at least one of today's slots ends after the current local time.

An activity with no open date is excluded as `CLOSED_ON_ALL_DATES`. The capacity and clash checks then look only at the open dates.

**Multi-day activities.** When `duration_days` is greater than 1, a date counts as a possible **start date** only if it and the following `duration_days − 1` dates are all remaining dates and all open, with capacity, and without a clash. No start date → `NOT_ENOUGH_DAYS`. For single-day activities every surviving open date is a start date. The candidate's `open_dates` is the list of start dates. Put the calculation in one pure method, `ActivityTimeframe::openDates(Activity $activity, CarbonInterface $from, CarbonInterface $until, CarbonInterface $now): array`, in `app/Support/Pitching/`, with no queries, so it can be tested case by case.

**Clash, without durations.** Activities now have weekday hours but still no length, and a booking's `scheduled_for` has a start and no end, so a true time clash still cannot be computed. Default rule, pending the owner (§15, D-7): a remaining date *clashes* for an activity when the guest already holds a non-cancelled booking **in the same activity category** on that date. A booking with a null `scheduled_for` clashes with nothing and is counted in provenance as `unscheduled_bookings`. An activity is excluded only when every remaining date clashes.

### 16.4 Complaint detection — the proposal

This is the hardest gate, so it has three layers. Each catches what the one before misses.

**Layer 1 — structural, in code, free.** Facts already in the database.

Add a column that says *why* a guest-related task exists:

```php
// database/migrations/2026_09_19_000003_add_guest_signal_to_tasks_table.php
Schema::table('tasks', function (Blueprint $table) {
    $table->string('guest_signal')->nullable();   // App\Enums\GuestSignal
    $table->index(['guest_id', 'guest_signal', 'created_at']);
});

// Backfill what can be identified with certainty from existing rows.
DB::table('tasks')->where('created_by', CreatedBy::GUEST->value)->whereNotNull('guest_id')
    ->update(['guest_signal' => GuestSignal::SERVICE_REQUEST->value]);
DB::table('tasks')->where('created_by', CreatedBy::AI->value)
    ->where('title', 'Guest needs human assistance')->whereNotNull('guest_id')
    ->update(['guest_signal' => GuestSignal::ESCALATION->value]);
```

```php
// app/Enums/GuestSignal.php
enum GuestSignal: string
{
    case ESCALATION = 'escalation';            // EscalateToHumanTool
    case SERVICE_REQUEST = 'service_request';  // something is needed or broken
    case BOOKING_FOLLOW_UP = 'booking_follow_up'; // staff to help an interested guest book — a positive signal
}
```

Then:

- `EscalateToHumanTool` sets `guest_signal = ESCALATION`. It also receives the reservation and sets `reservation_id`, which it does not do today.
- `CreateGuestServiceRequestTool` gains a `kind` parameter (`service_request` | `booking_follow_up`, default `service_request`) and stores it. The default is the conservative one: an unlabelled task blocks pitching.

**Layer 2 — a separate turn classifier.** Only runs when every Layer 1 gate and every other cheap gate has passed, so its cost is bounded. Once a guest is capped, it stops running for them.

```php
// app/Ai/Agents/TurnSignalAgent.php — implements Agent, HasStructuredOutput (see InsightsAgent)
// Input: the current guest message + the last N messages of the conversation
//        + this hotel's activity category names and ids.
// Output schema:
{
  "complaint": bool,             // unhappy, reporting a problem, or asking for a fix
  "opening": string|null,        // one PitchOpening value, or null
  "interest_category_id": string|null,  // one of the provided ids, or null
  "evidence_quote": string       // the guest's words the classification rests on
}
```

```php
// app/Services/Pitching/TurnSignalClassifier.php
class TurnSignalClassifier
{
    /**
     * @throws \Throwable on any failure — the caller records CLASSIFIER_FAILED and does not pitch
     */
    public function classify(Guest $guest, Hotel $hotel, string $messageText): TurnSignal;
}
```

Code validates the output before trusting it:

- `interest_category_id` must be one of the ids provided, else it is treated as null.
- `evidence_quote` must appear in the guest's message (case-insensitive, whitespace-normalised). If not, the classifier is treated as failed.

It runs **inside** the job's existing `AiCostContext::for(GUEST_MESSAGE, …)` callback, so its cost lands on `ai_usage_logs` against the guest message automatically. No separate meter event: `ai_messages` counts replies, not internal calls.

Why a separate agent rather than asking the concierge? Separation of duties. The agent whose prompt now includes "you may suggest an activity" is the wrong judge of whether this guest is too unhappy to be sold to.

Why not a keyword list? Guests write in Arabic, German, Russian, Italian, English, often mixed. A lexicon is either too narrow to catch complaints or so broad it blocks everything, and it breaks silently in every language nobody tested.

**Layer 3 — same-turn backstop, in code.** If the concierge calls `EscalateToHumanTool`, or `CreateGuestServiceRequestTool` with `kind = service_request`, during this turn, the shared `PitchTurn` object is marked. `PitchActivityTool` then refuses for the rest of the turn. The prompt tells the agent to resolve the request first and pitch last, so this ordering normally holds. When it doesn't, provenance records the order.

**What this cannot catch.** A guest who is quietly annoyed and says nothing negative. No system catches that. The staff follow-up path and the one-refusal rule limit the damage.

### 16.5 Decision provenance — `pitch_decisions`

```php
// database/migrations/2026_09_19_000004_create_pitch_decisions_table.php
Schema::create('pitch_decisions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('hotel_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('stay_id')->nullable()->constrained()->nullOnDelete();
    $table->string('conversation_id', 36)->nullable();   // agent_conversations.id — package table, no FK

    // ── Decision: written once, before the agent runs. Never updated. ──
    $table->boolean('eligible');
    $table->json('gates');                               // [{gate, passed, detail}] in evaluation order
    $table->boolean('classifier_ran')->default(false);
    $table->boolean('complaint')->nullable();            // null = classifier did not run
    $table->string('opening')->nullable();               // App\Enums\PitchOpening
    $table->boolean('explicit_request')->nullable();
    $table->text('opening_quote')->nullable();           // guest's words — see Trap 6
    $table->foreignUuid('interest_category_id')->nullable()
        ->constrained('activity_categories')->nullOnDelete();
    $table->json('candidates')->nullable();              // shortlist + exclusions, shape below
    $table->json('signals')->nullable();                 // inputs used: party, prior purchases, segment
    $table->string('rules_version');
    $table->timestamp('decided_at');

    // ── Completion: written once, after the reply. ──
    $table->string('result')->nullable();                // App\Enums\PitchResult; null = job died mid-turn
    $table->foreignUuid('recommendation_id')->nullable()->constrained()->nullOnDelete();
    $table->unsignedSmallInteger('chosen_rank')->nullable();
    $table->boolean('mention_verified')->nullable();     // activity name found in the reply text
    $table->timestamp('completed_at')->nullable();

    $table->timestamps();                                // no softDeletes — a record of decisions

    $table->index(['hotel_id', 'decided_at']);
    $table->index(['stay_id', 'result']);
});

Schema::table('recommendations', function (Blueprint $table) {
    // Set only for recommendations delivered by a pitch. Null = generated
    // for staff (RecommendationAgent) and never pitched in chat.
    $table->foreignUuid('pitch_decision_id')->nullable()->constrained()->nullOnDelete();
});
```

```php
// app/Enums/PitchResult.php
enum PitchResult: string
{
    case PITCHED = 'pitched';            // tool called, reply sent
    case NO_PITCH = 'no_pitch';          // eligible, agent chose not to — restraint or missed opening
    case INELIGIBLE = 'ineligible';      // a gate blocked it
    case REPLY_FAILED = 'reply_failed';  // staged, but the send threw
}
```

`candidates` JSON shape. Write it exactly like this; WP-18 reads it.

```json
{
  "considered": 14,
  "unscheduled_bookings": 1,
  "shortlist": [
    {
      "rank": 1,
      "activity_id": "9d1c…",
      "name": "Sunset Catamaran",
      "reasons": ["category_booked_this_stay", "audience_fits_party"],
      "sort_key": [1, 0, 1, 1, 0.0],
      "open_dates": ["2026-09-19", "2026-09-20"],
      "capacity": "unknown"
    }
  ],
  "excluded": [
    { "activity_id": "4ab2…", "name": "PADI Open Water", "reason": "not_enough_days", "detail": "needs 3 consecutive open days, longest run is 2" }
  ]
}
```

**Why a table and not the outcome's `context`** (§2 C-4). The outcome row changes as the recommendation moves from delivered to accepted to booked, and the booking path replaces its `context`. A decision is a fact about one moment. It gets its own write-once row, and the recommendation points to it. It also exists for turns with *no* pitch, which is the only way to answer "restraint or missed opening?".

`PitchDecision` model: `BelongsToHotel`, `HasUuids`, explicit `$fillable` and `$casts`. **No** `SoftDeletes` and **no** `RecordsEvents`: it is itself the record. Guard the decision columns against updates the way `Transaction` guards the ledger. Only the completion columns may be written, and only while `completed_at` is null.

### 16.6 The service

```php
// app/Services/Pitching/PitchEligibilityService.php
class PitchEligibilityService
{
    /** Cheap gates only: no AI call. Queries run inside TenantContext::runForHotel($hotel->id). */
    public function evaluateCheapGates(Guest $guest, Hotel $hotel, ?Stay $stay, CarbonInterface $now): GateReport;

    /** Cap and decline, which depend on whether the opening is explicit. */
    public function evaluateOpeningGates(Stay $stay, PitchOpening $opening, GateReport $report): GateReport;

    /** Per-activity exclusions, then ranking (WP-17). */
    public function candidates(Guest $guest, Hotel $hotel, Stay $stay, ?string $interestCategoryId, CarbonInterface $now): CandidateList;
}
```

Value objects live in `app/Support/Pitching/`: `GateReport`, `GateResult`, `TurnSignal`, `CandidateList`, `Candidate`, `PitchTurn`. They are plain readonly classes with a `toArray()` for the JSON columns.

**Evaluate every cheap gate even after one fails.** Recording that five of six passed is information: it tells you which rule is doing the blocking. Stop only before the classifier, which costs money.

> **As implemented (19 Sep 2026).** Where the code differs from the text above:
> - Migrations are `2026_09_19_000005` (guest signal) and `000006` (pitch decisions); `000000`–`000004` were already taken.
> - `candidates()` takes no `Guest` yet; WP-17 adds it when ranking needs prior purchases. Until then the shortlist is the first N candidates by name.
> - `PitchCoordinator` exists from WP-16 with `begin()` and `complete()`. The job calls `begin()` inside the cost context before the concierge runs, and `complete()` right after the reply is generated, recording `ineligible` or `no_pitch`. WP-17 moves completion of pitched turns to after the send and adds `abandon()`.
> - Gates that need a stay are not recorded when there is none: the row shows `feature_disabled` and a failed `no_stay` only.
> - The `PitchTurn` is set on the concierge after its conversation is resumed, so the escalation and service-request tools can mark it (Layer 3). `PitchTurn` is the one mutable value object, for that flag.
> - The pitch cap counts pitches whose turn is still running or ended `pitched`; a `reply_failed` pitch never reached the guest and does not count.
> - `tasks.guest_signal` is not fillable, so the tasks API cannot relabel a complaint.

## How to verify

```php
// tests/Feature/PitchEligibilityTest.php
it('blocks a guest on their departure day in the hotel\'s timezone', ...);   // ← the timezone test
it('blocks a guest who has already checked out', ...);
it('blocks when there is no stay', ...);
it('blocks a second unsolicited pitch in the same stay', ...);
it('allows a suggestion on explicit request after the cap is reached', ...);
it('blocks after any refusal this stay, including a low-confidence one', ...);
it('blocks for the rest of the stay after an escalation', ...);
it('blocks while a service request from the last 24 hours is open', ...);
it('does not block on a booking follow-up task', ...);
it('does not run the classifier when a cheap gate fails', ...);
it('fails closed when the classifier throws', ...);
it('treats a classifier quote not found in the message as a failure', ...);
it('ignores an interest category id from another hotel', ...);
it('excludes a multi-day activity with too few consecutive open days before departure', ...);
it('treats a null duration as a single day', ...);
it('excludes an activity closed on every remaining date by season, closure or weekday hours', ...);
it('does not count today as open once today's last slot has ended', ...);
it('treats a null timeframe as open every day', ...);
it('excludes an activity full on every remaining open date but not one with unknown capacity', ...);
it('excludes an activity already booked this stay', ...);
it('records every gate result, not just the first failure', ...);
it('writes a decision row for an ineligible turn', ...);
it('refuses to update decision columns once written', ...);

// tests/Feature/TenantIsolationTest.php (extend)
it('does not show one hotel\'s pitch decisions to another', ...);

// tests/Feature/WhatsAppWebhookTest.php (extend)
it('still replies to the guest when the eligibility service throws', ...);
```

## Acceptance criteria

- [ ] Every gate in 16.3 is implemented in code, with a test that trips it
- [ ] Explicit requests bypass the cap and the decline gate, and nothing else
- [ ] All date logic uses the hotel's timezone
- [ ] `tasks.guest_signal` exists, is backfilled where certain, and is set by both concierge tools
- [ ] Classifier runs only after the cheap gates pass; any failure blocks the pitch
- [ ] One `pitch_decisions` row per guest turn with a stay, eligible or not
- [ ] Decision columns are write-once
- [ ] Isolation test for `pitch_decisions`
- [ ] Any failure in this package still lets the guest's reply go out

## Traps

1. **Do not wrap the whole concierge call in `TenantContext::runForHotel()`.** `KnowledgeChunk` uses `BelongsToHotel`, and the global knowledge base is stored with `hotel_id = null`. Under a one-hotel scope the `whereIn` excludes nulls, so the concierge silently loses every global article, and no test fails unless one checks it. Wrap only the pitching service's own queries.
2. **"Today" is local.** A resort in UTC+3 is on tomorrow's date from 21:00 UTC. `now()` without a timezone gets departure day wrong for three hours every night, which is exactly when guests message about the evening.
3. **Fail closed, everywhere.** No stay, classifier error, unexpected null: the answer is *no pitch*. A missed pitch costs a small sale; a pitch to a complaining guest costs a review.
4. **Read refusals from `recommendations.status`, not from the outcome.** The confidence floor records a hesitant "no thanks" as DELIVERED. The status is REJECTED either way.
5. **Never block the reply.** Eligibility, classification and provenance are all wrapped: on any exception, `report()`, treat the turn as ineligible, and answer the guest.
6. **`opening_quote` is the guest's own words.** Same care as `recommendation_outcomes.evidence_quote`: no copying into `event_log`, and include it in any future data-export or deletion work.
7. **Do not add `guest_signal` to `CreatedBy`.** `tasks.created_by` is a Postgres enum column; changing its values needs a constraint migration, and "who created it" is a different question from "why it exists".

---

# WP-17 — Ranking, the pitch tool, concierge integration

**Estimated: 4–6 days.**

## Why

WP-16 decides *whether* to pitch. This package decides *what*, gives the agent a controlled way to do it, and records delivery only once the reply has actually been sent.

## What you build

### 17.1 Ranking: rules, in order

Ranking is a **lexicographic sort**: compare on the first key; only on a tie look at the second, and so on. There are no weights and no scores to add up. Every position can be explained in one sentence ("ranked first because they booked something in this category yesterday").

The brief's signals, as implemented:

| Order | Signal | Source | How it is used |
|---|---|---|---|
| 1 | **What the guest just said** | `TurnSignal::$interestCategoryId` | **Filter**, not a sort key. If the guest asked about the spa, only spa activities are candidates. If none survive, no pitch; we do not answer a spa question with a boat trip. |
| 2 | **Booked this stay** | `bookings` (non-cancelled, this `stay_id`) | Key: activity is in a category they already booked this stay → 1, else 0 |
| 3 | **Bought on prior stays** | `transactions` for this `guest_id`, `stay_id` ≠ current, `activity_id` not null, not reversed | Key: same activity bought before → 2; same category → 1; else 0 |
| 4 | **Party composition** | `stays.adults`, `stays.children` × `activities.audience` | Key: children > 0 and `FAMILY`, or no children and `ADULTS_ONLY` → 1; `ALL` or null → 0; children > 0 and `ADULTS_ONLY` → −1 |
| 5 | **Segment** | `guests.nationality`, `stays.market_segment` | **Not used for ranking in v1.** Recorded in `signals` only. See §15 D-6. |
| tie | Pre-generated recommendation | `recommendations` for this reservation, `pitch_decision_id` null, status `PENDING` | Key: exists → 1, then its `predicted_confidence` |
| tie | Stable order | `activities.name` | So the same inputs always give the same list |

```php
// app/Services/Pitching/PitchRanker.php — pure: no queries, fully unit-testable
class PitchRanker
{
    /**
     * @param  Collection<int, Candidate>  $candidates  already filtered
     * @return Collection<int, Candidate>  ranked, each with reasons[] and sortKey[]
     */
    public function rank(Collection $candidates, RankingSignals $signals): Collection;
}
```

`RankingSignals` is built by `PitchEligibilityService::candidates()` with all the queries done up front. That keeps the ranker free of the database and trivial to test.

### 17.2 How the agent gets candidates — the trade-off

| Option | For | Against |
|---|---|---|
| **A. Agent chooses freely** from `GetActivitiesTool` | Best at matching nuance ("something quiet, my husband hates crowds") | Unexplainable; ignores prior purchases it cannot see; drifts; no way to tell what it passed over |
| **B. Code picks one**, agent just phrases it | Fully explainable and deterministic | Code cannot read nuance in the live message; will pitch rank 1 even when the guest's words point at rank 2 |
| **C. Code shortlists and ranks N; agent picks one from the shortlist** | Rules decide *what may be pitched* and in what order; the agent contributes only the live-language judgment; its choice is recorded as `chosen_rank` | Slightly more moving parts |

**Recommendation: C, with N = 3.** The rules own everything they can own: gates, exclusions, history, party. The agent owns the one thing rules cannot do, reading the current sentence. And because `chosen_rank` is recorded, we can measure how often the agent overrides rule 1, and whether its overrides convert better or worse. That is exactly the evidence a later scoring model would need.

The shortlist goes into the **system instructions for that turn**, not behind a tool call. It is short, it saves a round trip, and the agent cannot "forget" to look.

### 17.3 The pitch tool

```php
// app/Ai/Tools/PitchActivityTool.php
class PitchActivityTool implements Tool
{
    public function __construct(
        private readonly PitchTurn $turn,          // shared with the job and the other tools
        private readonly Hotel $hotel,
        private readonly Reservation $reservation,
    ) {}

    // schema:
    //   activity_id  (required) — must be on this turn's shortlist
    //   guest_words  (required) — the words in the guest's CURRENT message that make this welcome
}
```

`handle()` in order, refusing with a plain sentence the agent can act on at each failure:

1. The turn is eligible, nothing has been staged yet this turn, and no escalation or service request has happened this turn (16.4 Layer 3).
2. `activity_id` is on `$turn->shortlist()`.
3. `guest_words` appears in the current message (same normalisation as the classifier). This is what makes "never appended to an unrelated reply" enforceable: the pitch must cite the sentence that invited it.
4. Inside `DB::transaction()`, lock the stay row (`Stay::whereKey(...)->lockForUpdate()->first()`) and **re-check the cap**. Two messages processed by two workers at once must not both pitch.
5. Reuse an existing `PENDING`, undelivered recommendation for this reservation and activity if there is one. Otherwise create one through `EventLogger::asAiAgent()`:
   - `reason`: from the shortlist entry's reasons, in words
   - `evidence_level`: `L3`
   - `evidence_sources`: the stay, the activity, and any booking or transaction ids used as signals
   - `recommended_at`: now
   - `status`: `PENDING`
6. Set `pitch_decision_id` on the recommendation. Stage it on `$turn`, with its rank.
7. Return: *"Staged recommendation {id} for {name}. Mention it in this reply, briefly, after answering the guest. If they agree, pass recommendation_id {id} to the booking tool. Record their reaction with the update-recommendation tool."*

The tool **does not** stamp delivery. Nothing has been sent yet.

### 17.4 Wiring it into the job and the agent

```php
// app/Services/Pitching/PitchCoordinator.php
class PitchCoordinator
{
    /** Cheap gates → classifier → opening gates → candidates → decision row. Never throws. */
    public function begin(Guest $guest, Hotel $hotel, ?Reservation $reservation, string $messageText, ?string $conversationId): PitchTurn;

    /** After a successful send: stamp delivery, verify mention, complete the decision. Never throws. */
    public function complete(PitchTurn $turn, string $replyText): void;

    /** The send threw: complete the decision as REPLY_FAILED. Never throws. */
    public function abandon(PitchTurn $turn): void;
}
```

In `ProcessInboundWhatsAppMessageJob::handle()`, guest branch:

```php
$turn = $coordinator->begin(...);          // inside the AiCostContext callback — the classifier is an AI call

$agent = GuestConciergeAgent::make(
    guest: $this->sender, hotel: $this->hotel, reservation: $this->reservation, pitchTurn: $turn,
);
// ... prompt as today ...

try {
    $whatsApp->send($this->phoneNumber, $response->text);
} catch (\Throwable $e) {
    $coordinator->abandon($turn);
    throw $e;                                 // the job's existing failure behaviour is unchanged
}

$coordinator->complete($turn, $response->text);
```

`complete()`:

1. For the staged recommendation: `RecommendationDeliveryService::markDelivered(..., WHATSAPP, now(), AI_AGENT)`.
2. Record a `DELIVERED` outcome through `RecommendationOutcomeService::record(..., AttributionMethod::CONVERSATIONAL, ...)` with `channel = 'whatsapp'`. **Reuse the service; no second path.**
3. `mention_verified`: the activity's name appears in `$replyText` (case-insensitive). Stored, never used to block. The agent may reasonably translate a name.
4. Write the completion columns: `result = PITCHED` (or `NO_PITCH` if eligible and nothing staged, or `INELIGIBLE`), `recommendation_id`, `chosen_rank`, `completed_at`.

### 17.5 Agent changes

`GuestConciergeAgent`:

- Constructor gains `public ?PitchTurn $pitchTurn = null`. Null means no pitching: the existing tests and the admin path are unaffected.
- `tools()` adds `PitchActivityTool` **only when** `$this->pitchTurn?->eligible()`. An ineligible turn does not merely tell the agent not to pitch; it removes the ability to.
- `EscalateToHumanTool` and `CreateGuestServiceRequestTool` receive the `PitchTurn` so they can mark it (16.4 Layer 3).
- `instructions()`:
  - **Remove** the paragraph beginning *"Proactively recommend activities when it's natural…"*, including *"wrapping up a conversation about their stay"* and *"tailor a recommendation yourself"*.
  - **Keep** the paragraph on recording reactions with the update-recommendation tool, and the booking-follow-up task rule (now with `kind = booking_follow_up`).
  - **Add** a section built from the turn:

    When eligible:
    > First, fully answer what the guest asked. Then, only if it fits naturally, suggest **one** activity from this list by calling the pitch tool with its id and the guest's own words that invited it. Mention only that activity, once, briefly. If the guest seems unhappy about anything, do not suggest anything.
    > 1. {name} — {reason in words}. Open on: {open_dates, as weekday and date}. Only propose one of these days.
    > 2. …

    When not eligible:
    > Do not suggest activities the guest did not ask about. If they ask what is available, answer factually from the activities tool without singling one out as a personal recommendation.

  Keep the shortlist section in its own method, like `vipInstructions()`, so a test can assert on it.

- **Keep `GetRecommendationsTool`.** The agent needs recommendation ids to record reactions. Pre-generated recommendations now reach the guest **only** through the shortlist, where they win ties.

## How to verify

```php
// tests/Feature/PitchRankerTest.php
it('ranks an activity in a category booked this stay above one that is not', ...);
it('ranks a previously bought activity above a previously bought category', ...);
it('ignores reversed transactions as purchase history', ...);
it('ranks family activities up for a party with children', ...);
it('does not use nationality or market segment to rank', ...);
it('restricts candidates to the category the guest asked about', ...);
it('breaks ties with a pre-generated recommendation, then by name', ...);
it('returns the same order for the same inputs', ...);

// tests/Feature/PitchActivityToolTest.php
it('refuses an activity that is not on this turn\'s shortlist', ...);
it('refuses when the quoted guest words are not in the current message', ...);   // ← the important one
it('refuses a second pitch in the same turn', ...);
it('refuses after the agent escalated earlier in the same turn', ...);
it('reuses a pending pre-generated recommendation rather than duplicating it', ...);
it('does not stamp delivery when the pitch is staged', ...);
it('does not let two concurrent turns exceed the cap', ...);

// tests/Feature/ConversationalPitchingTest.php — end to end through the webhook
it('stamps delivery and records a delivered outcome after the reply is sent', ...);
it('leaves the recommendation undelivered when the send fails', ...);
it('credits a later booking to the pitched recommendation as direct', ...);
it('gives the agent no pitch tool on an ineligible turn', ...);
it('no longer tells the agent to recommend while wrapping up a conversation', ...);
it('still answers the guest when the whole pitching layer throws', ...);
```

## Acceptance criteria

- [ ] Ranking is a pure, deterministic, lexicographic rule set with a reason per key
- [ ] Segment data is recorded but not used to rank
- [ ] The agent sees at most N shortlisted activities and can pitch only one of them
- [ ] A pitch must cite words from the current message
- [ ] Delivery is stamped only after `send()` returns
- [ ] Every suggestion creates or reuses a recommendation row: no invisible pitches
- [ ] Old proactive-pitching prompt text is gone
- [ ] Turned off (`PITCHING_ENABLED=false`), the concierge behaves exactly as before except for the removed paragraph

## Traps

1. **The model will try to pitch in text without the tool.** The prompt forbids it, and the tool is the only thing that writes a row. Track it anyway: count replies on ineligible turns that contain an active activity name the guest did not mention. Report the count in the logs; do not block on it.
2. **Queue retries resend the message.** If `complete()` throws after the send, a retried job sends the reply twice and pitches twice. `complete()` must never throw: catch, report, return.
3. **`predicted_confidence` is 0 on rule-created recommendations.** The column is non-null with default 0. Exclude `pitch_decision_id IS NOT NULL` rows from any analysis of predicted confidence, and say so in the recommendation docs.
4. **Do not let the ranker read the database.** Once it does, tests need full fixtures, and "why was this ranked first" needs a debugger.
5. **The agent's `maxConversationMessages()` is 10.** The shortlist belongs in the instructions, which are rebuilt every turn, not in message history, where it would go stale.
6. **Bookings do not check the activity timeframe yet.** `CreateBookingTool` will record a booking for a closed day if the agent offers one. The shortlist carries `open_dates` and the prompt restricts the agent to them, but that is prompt-level. Making `BookingService::create()` reject a time outside the timeframe is a separate change, and it needs thought first, because staff override closures in real life. Until it ships, pitched bookings stay `PENDING` and staff confirm the slot.

---

# WP-18 — Engagement segments and the reception list

**Estimated: 3–4 days.**

## Why

A single conversion rate would be misleading here, in a specific way.

Only guests who message us can be pitched. Guests who message a hotel are more engaged, more curious and more likely to buy anyway. A 25% booking rate among pitched guests says very little about what pitching would do for the house, because the other 70% who never message were never in the sample. Blending them into one hotel-wide rate produces a number that looks like success and does not generalise.

So the report keeps three populations apart, always:

| Segment | Definition (per stay) | What it tells you |
|---|---|---|
| **Never contacted** | `stays.first_contacted_at IS NULL` | The reach ceiling. Nothing reactive can touch these guests. |
| **Contacted, not pitched** | contacted, and no delivered pitch this stay | Whether the rules are doing the blocking (restraint) or the agent had an opening and stayed silent (missed opening) |
| **Contacted and pitched** | at least one recommendation with `pitch_decision_id` and `delivered_at` this stay | The only population where a pitch conversion rate means anything |

The operational half is simpler. Uncontacted in-house guests become a **list for reception**, so a person can say hello. That is a staff conversation, which is allowed even where system-initiated messages are not.

## What you build

### 18.1 Permissions

Add to `App\Enums\Permission`, grouped with the others:

```php
case GUEST_ENGAGEMENT_VIEW = 'guest_engagement.view';                  // the segment report
case GUEST_ENGAGEMENT_CONTACT_LIST = 'guest_engagement.contact_list';  // the reception list
```

Gate both through a policy using `$this->allows($user, Permission::X, …)` from `ChecksPermissions`. No `isAdmin()` checks.

`employeeDefaults()`: proposed to include `GUEST_ENGAGEMENT_CONTACT_LIST`, because reception staff usually have no role and the list is useless if they cannot see it. It exposes nothing beyond what `guests.view`, already a default, shows. **This is the owner's call** (§15, D-8); add it only once confirmed, with the reason in the docblock.

### 18.2 The segment report

```
GET /api/analytics/engagement?from=2026-09-01&to=2026-09-30
```

**Population:** stays with status `IN_HOUSE` or `DEPARTED` whose planned window overlaps the period.

```json
{
  "from": "2026-09-01",
  "to": "2026-09-30",
  "stays": 412,
  "segments": {
    "never_contacted": {
      "stays": 250,
      "share_of_stays": 0.6068,
      "stays_with_any_booking": 31
    },
    "contacted_not_pitched": {
      "stays": 110,
      "stays_with_any_booking": 22,
      "why": {
        "never_eligible": { "departing": 18, "escalated_this_stay": 6, "pitch_cap": 0, "…": 0 },
        "eligible_no_opening": 41,
        "eligible_opening_not_pitched": 9,
        "incomplete_decisions": 0
      }
    },
    "contacted_and_pitched": {
      "stays": 52,
      "pitches": 57,
      "unsolicited": 44,
      "on_request": 13,
      "mention_verified_share": 0.9474,
      "outcomes": { "delivered": 20, "declined": 12, "accepted": 6, "booked": 19 },
      "booking_rate": 0.3333,
      "booking_rate_unsolicited": 0.2727,
      "booking_rate_on_request": 0.5385,
      "agent_chose_rank_1_share": 0.8246
    }
  },
  "evidence_level": "L1",
  "notes": "…"
}
```

Rules for this endpoint:

- **There is no hotel-wide conversion rate, and there must never be one.** Not in the body, not in the notes.
- `booking_rate` is pitches with a BOOKED outcome ÷ pitches delivered, within the pitched segment only. It is split by unsolicited and on-request, because the two are different animals.
- `stays_with_any_booking` in the first two segments counts bookings of any origin. It is there for context, not comparison, and the notes say so.
- A stay whose most recent decision row has `result = null` counts under `incomplete_decisions`, never silently dropped.
- `notes` always includes the self-selection sentence: *"Contacted guests chose to message the hotel; their booking rates are not an estimate of what pitching would achieve across all guests."*
- `evidence_level`: the weakest level among the outcomes used, the same `weakestEvidenceLevel()` logic as conversion analytics. Extract it to a shared helper rather than copying it.

### 18.3 The reception list

```
GET /api/guest-engagement/uncontacted?per_page=50
```

In-house stays today (hotel's local date) with `first_contacted_at IS NULL` and departure after today, ordered by fewest remaining nights first, since those are the ones about to be missed.

```json
{
  "data": [
    {
      "stay_id": "…",
      "guest": { "id": "…", "first_name": "Anna", "last_name": "Weber", "preferred_language": "de", "is_vip": false },
      "room_number": "214",
      "planned_arrival_date": "2026-09-16",
      "planned_departure_date": "2026-09-20",
      "remaining_nights": 2,
      "adults": 2,
      "children": 1,
      "has_phone_number": true
    }
  ],
  "meta": { "total": 38, "as_of": "2026-09-18" }
}
```

Build the body through an `UncontactedStayResource`, returned through `apiResponse()`.

**Deliberately absent:** any "send message" action, and any field implying one. The list is for people to walk over and say hello. If a frontend developer asks for a "WhatsApp this guest" button, that is outbound messaging and needs the owner.

### 18.4 Documentation

- New `docs/guest-engagement-api-documentation.md` covering both endpoints.
- Permission reference in `docs/staff-roles-api-documentation.md`.
- Both endpoints added to the dataset in `tests/Feature/PermissionAuthorizationTest.php`.
- `docs/latest-changes-<date>.md` summarising WP-13 to WP-18 for the frontend.

## How to verify

```php
// tests/Feature/GuestEngagementReportTest.php
it('puts each stay in exactly one segment', ...);
it('never returns a blended conversion rate', ...);              // ← assert the key is absent
it('splits pitched booking rates into unsolicited and on request', ...);
it('explains contacted-not-pitched stays by their blocking gate', ...);
it('separates eligible-with-opening-but-no-pitch from no opening', ...);
it('counts incomplete decisions instead of dropping them', ...);
it('includes the self-selection note', ...);

// tests/Feature/UncontactedStaysTest.php
it('lists in-house stays with no contact, fewest nights first', ...);
it('excludes guests departing today in the hotel\'s timezone', ...);
it('excludes a stay once the guest messages', ...);

// tests/Feature/TenantIsolationTest.php (extend)
it('does not include another hotel\'s stays in the engagement report', ...);
it('does not include another hotel\'s guests on the reception list', ...);

// tests/Feature/PermissionAuthorizationTest.php — dataset rows for both endpoints
```

## Acceptance criteria

- [ ] Three segments, per stay, mutually exclusive, never blended
- [ ] Pitched conversion split by unsolicited and on-request
- [ ] "Restraint vs missed opening" answerable from the response
- [ ] Reception list works in the hotel's local date
- [ ] Two new permissions, gated through `allows()`, in the docs and the authorization dataset
- [ ] Isolation tests for both endpoints

## Traps

1. **Someone will ask for "the conversion rate".** The answer is the pitched segment's rate, with the self-selection note attached, and never the hotel-wide number.
2. **Stays are the unit, not guests.** A returning guest is two stays and may fall in two different segments. That is correct.
3. **Pre-cutover stays have no decision rows.** Contact stamps are backfilled, but decisions cannot be. Report periods before the pitching go-live date as contacted-not-pitched with `why` all zero, and add a note, rather than inventing reasons.

---

## 13. Sequence

```
Week 1     WP-13 ████████ ─────────────────────────── contact stamping + backfill
           WP-14 ░░░░████ ─────────────────────────── activity attributes (parallel)
Week 1–2   WP-15 ░░░░░░░░████████ ─────────────────── delivery tracking          ← CHECKPOINT 1
Week 2–3   WP-16 ░░░░░░░░░░░░░░░░██████████ ───────── gates + complaints + provenance
Week 3–4   WP-17 ░░░░░░░░░░░░░░░░░░░░░░░░░░████████── ranking + tool + agent     ← CHECKPOINT 2
Week 4–5   WP-18 ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░████ segments + reception list  ← CHECKPOINT 3
```

- **Checkpoint 1:** delivery is measured and conversion analytics reports its basis. Worth merging on its own, even if pitching is never switched on.
- **Checkpoint 2:** pitching works end to end **with `PITCHING_ENABLED=false` in every environment**. Switch it on for the pilot hotel only after the owner decisions in §15 are recorded.
- **Checkpoint 3:** a first report can be pulled after about two weeks of pilot traffic.

One WP per branch, one PR per WP, merged before the next begins. Run `./vendor/bin/pint` and `php artisan test` (Postgres container up) before each PR.

---

## 14. Definition of Done

On a clean database:

- [ ] `php artisan migrate:fresh --seed && php artisan test` — green
- [ ] Every Phase 1 and Phase 2 v2.0 acceptance criterion still holds
- [ ] With `PITCHING_ENABLED=false`, no guest is ever pitched and no classifier call is made
- [ ] No code path added here sends a WhatsApp message other than the reply to an inbound message
- [ ] Every inbound guest message stamps contact on the guest and the resolved stay
- [ ] Every guest turn with a stay writes exactly one `pitch_decisions` row
- [ ] Every pitch creates or reuses a recommendation row; delivery is stamped only after a successful send
- [ ] `RECOMMENDATIONS_DELIVERED` is metered, once per recommendation, with no monetary value
- [ ] NOT_DELIVERED is written automatically after the cutover
- [ ] A converted pitch keeps the guest's quoted acceptance in `context.superseded`
- [ ] Each gate in 16.3 has a test that trips it; the departure gate is tested across a timezone boundary
- [ ] Failure anywhere in contact stamping, eligibility, classification, provenance, delivery or metering never prevents the guest's reply
- [ ] Every AI call added (the classifier) appears in `ai_usage_logs` as `guest_message`, never `unattributed`
- [ ] Isolation tests for `pitch_decisions`, the engagement report and the reception list
- [ ] New permissions in the enum, the policy, the staff-roles doc and `PermissionAuthorizationTest`
- [ ] Engagement report returns no blended rate
- [ ] `./vendor/bin/pint` clean; every changed endpoint's doc updated; latest-changes doc written

---

## 15. Open decisions for the owner

These are product, commercial or legal decisions. **Do not resolve them in code.** Each has a safe default so development is not blocked, but none should reach a live guest unconfirmed.

| # | Decision | Default built | Recommendation |
|---|---|---|---|
| D-1 | **Pitch cap.** How many *unsolicited* pitches per stay? Should explicit requests be exempt? | 1; explicit requests exempt | 1 for the pilot. Revisit when WP-18 shows decline rates by pitch number. |
| D-2 | **What counts as an opening,** and which openings are explicit requests? | The seven in 16.2; two explicit | Confirm the list. Consider whether "children" alone is an opening or needs a stated need ("the kids are bored"). |
| D-3 | **Complaint handling.** Accept the three-layer design, including one extra small-model call per eligible message? Does an escalation block for the rest of the stay or for a cooling-off period? How far back does an open service request count? | Three layers; rest of stay; 24 hours | Accept. Escalation for the rest of the stay is conservative and cheap to relax later; the reverse is not true. |
| D-4 | ~~Marketing consent~~ — **resolved by the owner (18 Sep 2026).** Guests give consent when they make the reservation, so there is no consent gate. The `guests.marketing_consent` column has been dropped. | — | — |
| D-5 | **Pre-arrival guests.** Pitch guests whose stay has not started? | No | No for v1: capacity and clash checks are about dates they are not yet present for. |
| D-6 | **Segment signals.** Use nationality or market segment for ranking? | Recorded, not used | Keep out. `market_segment` is never populated, and ranking on nationality without evidence is an L3 guess that is also a profiling risk. |
| D-7 | **Clash rule.** Activities have weekday hours but no duration, so a real time clash can't be computed | Same category, same date | Accept for v1. A `duration_minutes` column would allow a real time clash check later. |
| D-8 | **Who sees what.** Reception list for employees without a role? Per-hotel on/off switch, or global? | Not in defaults until confirmed; global switch | Add the reception list to defaults. Add a per-hotel switch before a second hotel goes live. |
| D-9 | **Group-level guest history.** Should prior purchases at a sister property count? | No (not possible today) | No, until a group-level identity and consent model exists. |
| D-10 | **Capacity depth.** Is people-per-day enough, or are slots needed? | People per day, optional | Enough for a pilot; slots are a separate module. |

---

## 16. Assumptions

| # | Assumption | If wrong |
|---|---|---|
| A-1 | Every reservation has a stay (`StayService::syncFromReservation()` runs on creation) | Guests without a stay are never pitched (fail closed); pitch coverage looks low |
| A-2 | `laravel/ai` structured output works for a small agent, as it does for `InsightsAgent` | The classifier returns free text; parse defensively or swap providers for it |
| A-3 | `WhatsAppMessageService::send()` throws on any failure | Delivery could be stamped for a message that never left; add a response check |
| A-4 | One inbound message = one job = one reply | If replies are ever split or batched, "turn" must be redefined and `PitchTurn` with it |
| A-5 | Guests message in several languages | If English only, a lexicon could replace the classifier (cheaper, still brittle) |
| A-6 | Pilot traffic is a few hundred stays a month | Report queries are computed live; at larger volume, materialise per-stay segments nightly |
| A-7 | Staff record face-to-face outcomes through the existing outcome endpoint | Otherwise face-to-face deliveries become NOT_DELIVERED after the cutover; add a note to the rollout checklist |
| A-8 | Pitching is enabled only after §15 decisions are recorded | A pitch reaches guests under rules nobody approved |
| A-9 | Every guest consents to recommendations when making the reservation (owner, 18 Sep 2026) | A consent gate must be added to 16.3 before go-live |

**Confidence:** high for WP-13, WP-14 and WP-15 — they extend patterns the repo already uses (derived counts, idempotent metering, write-once fields, precedence). Moderate for WP-16 and WP-17: the gates and ranking are straightforward, but classifier accuracy can only be judged on real pilot traffic. Plan a manual review of the first 100 decision rows before trusting the segment report.

---

*End of document — PGRIP-ECO-CP-001 v1.0.*
