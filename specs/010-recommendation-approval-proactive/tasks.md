---

description: "Task list for Recommendation Approval and Proactive Concierge (Phase 10: SPEC-071, SPEC-073, SPEC-074)"
---

# Tasks: Recommendation Approval and Proactive Concierge

**Input**: Design documents from `specs/010-recommendation-approval-proactive/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: Included. The constitution (§VIII) and CLAUDE.md require feature tests for every
new behaviour, a tenant-isolation test for every tenant-scoped feature, and an allowed/403
test for every permission. Write each story's tests first and confirm they fail before
implementing.

**Organization**: Grouped by user story in spec order: US1, US2, US3 (P1), US4, US5 (P2),
US6 (P3). US5 (opt-out) is built before US4 (proactive) because US4's guardrails read the
opt-out state; both are P2. R-numbers refer to [research.md](research.md).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: The user story the task belongs to (US1–US6, numbered as in spec.md)

## Conventions for every task

- PHP 8.3 / Laravel 13. Controllers are thin: authorize, validate, delegate, and return
  `apiResponse()`. Every body goes through a Resource.
- New models use UUIDs (`HasUuids`, `$keyType = 'string'`, `$incrementing = false`),
  `BelongsToHotel`, and explicit `$fillable` and `$casts`. Columns marked "not fillable" in
  [data-model.md](data-model.md) are written with `forceFill` or conditional query updates
  in their one service.
- Jobs run per hotel inside `TenantContext::runForHotel()`. They never rely on the request
  scope.
- **Pitching rule**: every counter is derived from rows, never stored (see the
  `PitchEligibilityService` docblock).
- Tests are Pest files with `uses(RefreshDatabase::class)` and the `beforeEach` API-key
  setup from CLAUDE.md.
  - Call tools with `(string) $tool->handle(new Laravel\Ai\Tools\Request($args))`.
  - Fake WhatsApp with `Http::fake()` and agents with `::fake()`.
  - Use `Carbon::setTestNow()` in the hotel's timezone.
  - Shared fixtures go in `tests/Pest.php`, prefixed `rap` (recommendation approval/proactive).
- Migrations: `database/migrations/2026_10_10_00000N_<description>.php`. Never edit a
  shipped one.
- Run `./vendor/bin/pint` before committing.

---

## Phase 1: Setup

**Purpose**: Branch and shared fixtures.

- [X] T001 Confirm the current branch is `010-recommendation-approval-proactive`, based on `009-admin-ai-pms-tools`, and run `php artisan test` to record the green baseline.
- [X] T002 Add shared fixtures to tests/Pest.php. Each builds rows with `Model::create([...])`, as the repo has only `UserFactory`:
  - `rapHotel(string $timezone = 'Asia/Riyadh'): Hotel`;
  - `rapAdmin(Hotel $h): User`;
  - `rapEmployee(Hotel $h, array $permissions = []): User`, which creates a `StaffRole` with those permissions;
  - `rapInHouseStay(Hotel $h, array $overrides = []): Stay`, which creates the guest (with a phone number and `preferred_language`), reservation, reservation room, room and an `in_house` stay checked in yesterday, departing in 4 days;
  - `rapActivity(Hotel $h, string $name = 'Snorkeling'): Activity`;
  - `rapRecommendation(Reservation $r, Activity $a, string $status = 'approved', array $overrides = []): Recommendation`;
  - `rapInbound(Guest $g, Carbon $at): WhatsAppInboundMessage`, which records a guest message at that time.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Schema, enums and the status rename that every story reads. After this phase
the suite is green again with `approved` doing the job `pending` used to.

- [X] T003 Create migration database/migrations/2026_10_10_000001_add_approval_to_recommendations_table.php:
  - adds `source` (string, nullable), `reviewed_by_user_id` (uuid FK → users, nullable, `nullOnDelete`), `reviewed_at` (timestamp, nullable) and `review_reason` (string 500, nullable);
  - adds index `(hotel_id, status, recommended_at)`;
  - data step: `UPDATE recommendations SET status = 'pending_approval' WHERE status = 'pending'` and `source = 'legacy'` for all existing rows;
  - `down()`: `pending_approval` / `approved` → `pending`, `rejected_by_admin` → `cancelled`, then drop the columns.
- [X] T004 [P] Create migration database/migrations/2026_10_10_000002_create_proactive_messages_table.php with the columns in [data-model.md §3](data-model.md):
  - `event_key` is string(100);
  - `status` and `reason` are strings;
  - `attempts` is smallint, default 0;
  - `locale` is string(5);
  - `conversation_id` is string(36) with no FK;
  - unique `(hotel_id, guest_id, trigger, event_key)`;
  - indexes `(status, due_at)` and `(hotel_id, guest_id, sent_at)`;
  - no soft deletes.
- [X] T005 Create migration database/migrations/2026_10_10_000003_add_retry_and_proactive_to_pitch_decisions_table.php. It adds `is_retry` (boolean, default false) and `proactive_message_id` (uuid FK → proactive_messages, nullable, `nullOnDelete`). Depends on T004.
- [X] T006 [P] Create migration database/migrations/2026_10_10_000004_add_proactive_opt_out_to_guests_table.php adding `proactive_opted_out_at` (timestamp, nullable) and `proactive_opt_out_source` (string, nullable).
- [X] T007 [P] Create migration database/migrations/2026_10_10_000005_add_proactive_settings_to_hotels_table.php adding `proactive_settings` (json, nullable).
- [X] T008 Update app/Enums/RecommendationStatus.php:
  - add `PENDING_APPROVAL = 'pending_approval'`, `APPROVED = 'approved'` and `REJECTED_BY_ADMIN = 'rejected_by_admin'`;
  - remove `PENDING`;
  - add `isFinal(): bool`, true for `accepted, rejected, purchased, ignored, expired, cancelled, rejected_by_admin`.
- [X] T009 [P] Create app/Enums/RecommendationSource.php (`STAFF_REQUEST = 'staff_request'`, `CONVERSATION = 'conversation'`, `LEGACY = 'legacy'`).
- [X] T010 [P] Update app/Enums/PitchGate.php:
  - replace `DECLINED_THIS_STAY` with `RETRY_USED = 'retry_used'` and `RETRY_WINDOW_CLOSED = 'retry_window_closed'`;
  - add `OPTED_OUT = 'opted_out'`, an opening gate evaluated before `PITCH_CAP` (T059);
  - update the docblock comments.
- [X] T011 [P] Add `PROACTIVE = 'proactive'` to app/Enums/PitchOpening.php. Its docblock says the classifier never produces it. Check that `isExplicitRequest()` returns false for it, and that the classifier's allowed list (`TurnSignalClassifier`) excludes it.
- [X] T012 [P] Create app/Enums/ProactiveTrigger.php (`FIRST_MORNING`, `MID_STAY`, `UPCOMING_ACTIVITY`, `RECOMMENDATION_APPROVED`) with `isPitch(): bool`, false only for `UPCOMING_ACTIVITY`.
- [X] T013 [P] Create app/Enums/ProactiveMessageStatus.php (`SCHEDULED`, `SENDING`, `SENT`, `SKIPPED`, `FAILED`).
- [X] T014 [P] Create app/Enums/ProactiveSkipReason.php with the values in [data-model.md §3](data-model.md):
  - terminal: `disabled`, `trigger_disabled`, `opted_out`, `already_pitched`, `outside_window`, `no_whatsapp`, `not_in_house`, `departing`, `escalated`, `open_complaint`, `pitching_disabled`, `pitch_cap`, `retry_used`, `retry_window_closed`, `no_candidate`, `booking_not_confirmed`, `recommendation_not_offerable`, `missing_data`, `expired`, `send_failed`;
  - deferring: `quiet_hours`, `daily_cap`, `guest_active`;
  - add `defers(): bool`.
- [X] T015 [P] Add `PROACTIVE_MESSAGES_SENT = 'proactive_messages_sent'` to app/Enums/MeterFeature.php, plus any label or description map that enum keeps.
- [X] T016 Add `RECOMMENDATIONS_APPROVE = 'recommendations.approve'` to app/Enums/Permission.php, grouped after `RECOMMENDATIONS_RECORD_OUTCOME`. Do **not** add it to `employeeDefaults()`; extend that docblock: "Approving recommendations decides what the hotel tells guests, so employees only get it through a staff role."
- [X] T017 Update app/Models/Recommendation.php:
  - casts: `source` → `RecommendationSource`, `reviewed_at` → datetime;
  - `reviewedBy(): BelongsTo` (User, `reviewed_by_user_id`);
  - add `source` to `$fillable`, but **not** `reviewed_*`;
  - add `source`, `reviewed_by_user_id`, `reviewed_at`, `review_reason` to `eventLoggedAttributes()`;
  - add `scopeOfferable($q)`: `status = approved`, `delivered_at` null, `pitch_decision_id` null, active activity.
- [X] T018 [P] Create app/Models/ProactiveMessage.php (`BelongsToHotel`, `HasUuids`, `Filterable`):
  - casts for the enums, `due_at`, `valid_until`, `sent_at`;
  - relations `guest`, `reservation`, `stay`, `booking`, `recommendation`;
  - `$fillable` covers the scheduling columns only; `status`, `reason`, `attempts`, `body`, `locale`, `conversation_id` and `sent_at` are written only by `SendProactiveMessageJob`.
- [X] T019 [P] Update app/Models/PitchDecision.php: cast `is_retry` to boolean, add the `proactiveMessage(): BelongsTo` relation, and make `proactive_message_id` fillable.
- [X] T020 [P] Update app/Models/Guest.php:
  - cast `proactive_opted_out_at` to datetime, keep both opt-out columns out of `$fillable`;
  - add `isProactiveOptedOut(): bool`.
- [X] T021 [P] Create app/Support/Proactive/ProactiveSettings.php, an immutable value object:
  - `fromArray(?array)` fills the defaults in [data-model.md §5](data-model.md): `enabled` false, all four triggers true, quiet hours `21:00`–`09:00`, `daily_cap` 1, `milestone_time` `10:00`, reminder `morning_cutoff` `12:00` / `evening_before_at` `18:00` / `hours_before` 4;
  - `toArray()`, `triggerEnabled(ProactiveTrigger)`;
  - `inQuietHours(CarbonInterface $local)` (handles windows crossing midnight), `quietHoursEnd(CarbonInterface $local)`;
  - `static rules(): array` for validation: times `H:i`, `daily_cap` integer 1–3, `hours_before` integer 1–24, unknown keys rejected.
- [X] T022 Update app/Models/Hotel.php: add `proactive_settings` to `$fillable` and `proactiveSettings(): ProactiveSettings`. Keep the raw cast as `array`. Depends on T021.
- [X] T023 Update every remaining reference to `RecommendationStatus::PENDING`:
  - app/Ai/Tools/CreateRecommendationTool.php → `PENDING_APPROVAL`;
  - app/Services/RecommendationDeliveryService.php → `APPROVED` → `SENT`, including the event's `from` value and the docblock;
  - app/Services/Pitching/PitchEligibilityService.php → `pendingRecommendations()` renamed `offerableRecommendations()` using `Recommendation::offerable()`;
  - fix the existing tests that seed `pending` (tests/Feature/PitchEligibilityTest.php, RecommendationDeliveryTest.php, RecommendationOutcomeTest.php, RecommendationMatchingTest.php) to seed `approved`.

  Then run `php artisan test`. `RefreshDatabase` migrates the testing database from `phpunit.xml`. Never run `artisan migrate:fresh --env=testing`: there is no `.env.testing`, so it would hit the dev database.
- [X] T024 [P] Write tests/Feature/RecommendationStatusMigrationTest.php:
  - running T003's data step converts `pending` rows to `pending_approval` and backfills `source = legacy`;
  - every other status is unchanged (FR-011).

**Checkpoint**: Schema in place, suite green, `approved` is the offerable state.

---

## Phase 3: User Story 1 — An admin reviews AI recommendations before any guest sees them (Priority: P1) 🎯 MVP

**Goal**: Every recommendation starts pending; permitted users approve or reject it; each
decision is audited; edits after approval re-open review; stale items expire.

**Independent Test**: Generate recommendations; confirm all are `pending_approval`; approve
and reject some; check statuses, audit rows, the 403 path and that only approved ones are
offerable ([quickstart](quickstart.md) steps 1–4).

### Tests for User Story 1

- [X] T025 [P] [US1] Write tests/Feature/RecommendationApprovalTest.php covering [contracts/recommendation-approval-api.md](contracts/recommendation-approval-api.md):
  - approve `pending_approval` → `approved` with `reviewed_by` and `reviewed_at` set, and one `approved` event_log row;
  - reject with a reason → `rejected_by_admin`, reason stored, one event row;
  - reject an approved, never-offered item → `rejected_by_admin`;
  - approve or reject an item that is offered (`delivered_at` or `pitch_decision_id` set), final or already decided → 422 with the contract message and no change;
  - a reason over 500 characters → 422;
  - two approvals of the same id, both in the test → exactly one succeeds and one event row exists;
  - recommendations created by `CreateRecommendationTool` are `pending_approval`, with `source` from the agent (`staff_request` through `GenerateActivityRecommendationsJob`, `conversation` through `PitchRecommendationGenerator`).
- [X] T026 [P] [US1] Write tests/Feature/RecommendationEditApprovalTest.php (FR-006a):
  - changing `reason`, `activity_id` or `reservation_id` on an approved item → `pending_approval`, review fields null, an `approval_reset` event;
  - changing `priority` only → still `approved`;
  - changing `reason` on a delivered item → 422 `An offered or closed recommendation cannot change its activity, reason or reservation.`;
  - `status` / `reviewed_*` in the payload are ignored.
- [X] T027 [P] [US1] Write tests/Feature/ExpireRecommendationsJobTest.php:
  - `pending_approval` and `approved` items expire when the reservation is `cancelled` or `checked_out`, when all stays are `departed`, `no_show` or `cancelled`, or when the planned departure date is before today in the hotel's timezone;
  - delivered or pitched items never expire;
  - one `expired` event per row;
  - the job leaves another hotel's rows correct when run for all hotels.
- [X] T028 [P] [US1] Add the approve and reject endpoints to the dataset in tests/Feature/PermissionAuthorizationTest.php (`recommendations.approve`: an employee with it → 200, without it → 403). Add recommendation approve/reject to tests/Feature/TenantIsolationTest.php: another hotel's admin → 404.

### Implementation for User Story 1

- [X] T029 [US1] Create app/Services/Recommendations/RecommendationApprovalService.php (R2):
  - `approve(Recommendation $r, User $by): Recommendation` — conditional update `WHERE id = ? AND status = 'pending_approval' AND delivered_at IS NULL AND pitch_decision_id IS NULL`, setting `status`, `reviewed_by_user_id`, `reviewed_at` and `review_reason = null`;
  - `reject(Recommendation $r, User $by, ?string $reason): Recommendation` — same, from `pending_approval` or `approved`;
  - on 0 rows, throw `RuntimeException("This recommendation can no longer be {approved|rejected} (status: {status}).")`;
  - on success, refresh and call `EventLogger::record($r, 'approved' | 'rejected_by_admin', changes: [status from/to, review_reason])`;
  - no proactive dispatch here; T070 adds it once the job exists.
- [X] T030 [US1] Add `resetForEdit(Recommendation $r, array $validated): array` to RecommendationApprovalService:
  - throws `RuntimeException` (contract message) when content fields change on an offered or final item;
  - for an `approved` item with content changes, `forceFill` status `pending_approval`, clears review fields and records `approval_reset` after the save;
  - returns the attributes to save.
- [X] T031 [US1] Add `approve(User, Recommendation)` to app/Policies/RecommendationPolicy.php, using `$this->allows($user, Permission::RECOMMENDATIONS_APPROVE, $recommendation)`.
- [X] T032 [P] [US1] Create app/Http/Requests/RejectRecommendationRequest.php (`reason`: `nullable|string|max:500`).
- [X] T033 [US1] In app/Http/Controllers/RecommendationController.php add `approve()` and `reject()`:
  - authorize `approve`, call the service, return `RecommendationResource` with 200;
  - map a `RuntimeException` to 422.

  Change `update()` to call `resetForEdit()` before `$recommendation->update()` and map its `RuntimeException` to 422.
- [X] T034 [US1] Register `POST /recommendation/{recommendation}/approve` and `POST /recommendation/{recommendation}/reject` in routes/api.php next to the existing recommendation routes, before the resource route.
- [X] T035 [US1] Update app/Http/Resources/RecommendationResource.php:
  - add `source`, `reviewed_by` (`{id, name}` or null), `reviewed_at`, `review_reason`;
  - add `offerable` (approved ∧ not delivered ∧ not pitched ∧ activity active);
  - eager-load `reviewedBy` in the controller's index and show.
- [X] T036 [US1] Add a `source` constructor argument (`RecommendationSource`) to app/Ai/Agents/RecommendationAgent.php and pass it to app/Ai/Tools/CreateRecommendationTool.php, which writes it. Then:
  - app/Jobs/GenerateActivityRecommendationsJob.php passes `STAFF_REQUEST`;
  - app/Services/Pitching/PitchRecommendationGenerator.php passes `CONVERSATION`;
  - update its docblock: generated recommendations are pending review and never pitched in the same turn (FR-014).
- [X] T037 [US1] Create app/Jobs/ExpireRecommendationsJob.php (R6):
  - iterates active hotels and, inside `runForHotel`, finds `pending_approval` / `approved` items with `delivered_at` and `pitch_decision_id` null whose reservation matches the expiry rule;
  - updates each conditionally to `expired` and records an `expired` event.

  Schedule it `hourly()` in routes/console.php with a one-line comment.

**Checkpoint**: US1 independently testable. Pitching still reads only `approved` (T023), so
nothing unapproved can be candidates.

---

## Phase 4: User Story 2 — The Concierge offers only approved recommendations (Priority: P1)

**Goal**: The Concierge can pitch only through a code-gated tool that offers one approved
candidate. The prompt no longer invents suggestions, and the guest recommendations tool
shows only offered items. Builds WP-17 (R7).

**Independent Test**: Seed pending, approved and rejected items, run a guest turn with a
faked Concierge, and confirm the shortlist and the staged pitch use only approved items.
A reservation with none generates pending items and makes no pitch.

### Tests for User Story 2

- [X] T038 [P] [US2] Write tests/Feature/PitchFromApprovedPoolTest.php:
  - `candidates()` includes only approved, unoffered items with an active activity;
  - `pending_approval`, `rejected_by_admin`, expired and delivered items are never shortlisted;
  - a reservation with no recommendations triggers `ensureGenerated()`, the new rows are `pending_approval`, the decision records the `no_candidates` gate, and no pitch tool is offered;
  - app/Ai/Tools/GetRecommendationsTool.php returns only items with `delivered_at` or `pitch_decision_id` set;
  - `GuestConciergeAgent::instructions()` no longer contains "tailor a recommendation yourself";
  - the pitching section names the single candidate when eligible, and uses the "do not suggest activities the guest did not ask about" text otherwise.
- [X] T039 [P] [US2] Write tests/Feature/PitchActivityToolTest.php against [contracts/ai-tools.md](contracts/ai-tools.md):
  - stages the turn's single candidate, sets `recommendations.pitch_decision_id`, and returns the staged sentence;
  - refuses when `guest_words` is not in the current message, when the turn is ineligible or already staged, when `markBlockingRequest()` was called, when the candidate was rejected by an admin or expired after shortlisting (FR-015), or when the cap fails under the stay lock;
  - `PitchCoordinator::complete($turn, $reply)` after a staged pitch marks the recommendation `sent` (`delivered_at`, `WHATSAPP`), writes a `DELIVERED` outcome, sets `result = pitched`, `recommendation_id`, `chosen_rank` and `mention_verified`;
  - `abandon()` sets `reply_failed` and leaves `delivered_at` null;
  - in tests/Feature/ProcessInboundWhatsAppMessageJobTest.php (or the existing job test), a failed WhatsApp send leaves the decision `reply_failed`.

### Implementation for User Story 2

- [X] T040 [US2] Extend app/Support/Pitching/PitchTurn.php with a mutable staged state:
  - `stage(Candidate $c, int $rank, bool $isRetry)`, `staged(): ?Candidate`, `stagedRank()`, `isRetry()`;
  - `mayPitch()` also returns false once something is staged;
  - add `bool $isRetryTurn` (set by the coordinator from the eligibility report, used in US3; default false).
- [X] T041 [US2] Create app/Ai/Tools/PitchActivityTool.php per WP-17.2 (docs/PGRIP_Ecosystem_WP_Conversational_Pitching_v1_0.md) and [contracts/ai-tools.md](contracts/ai-tools.md):
  - constructor `(PitchTurn $turn, Stay $stay, string $currentMessage)`;
  - schema `guest_words` (required string);
  - in `DB::transaction` with `Stay::whereKey()->lockForUpdate()`:
    - re-check the opening gates via `PitchEligibilityService::evaluateOpeningGates()`;
    - re-check `Recommendation::offerable()->whereKey($candidate->recommendationId)->exists()`;
    - set `pitch_decision_id` with a conditional update (`WHERE pitch_decision_id IS NULL`);
    - set `pitch_decisions.is_retry = $turn->isRetryTurn`;
  - stage it on the turn;
  - never stamp delivery.
- [X] T042 [US2] In app/Services/Pitching/PitchCoordinator.php:
  - change `complete(PitchTurn $turn, string $replyText)` per WP-17.3: if staged, `RecommendationDeliveryService::markDelivered(…, WHATSAPP, now(), AI_AGENT)`, a `DELIVERED` outcome through `RecommendationOutcomeService::record(…, AttributionMethod::CONVERSATIONAL, …)` with channel `whatsapp`, `mention_verified` = activity name in the reply (case-insensitive), `result = PITCHED`, `recommendation_id`, `chosen_rank`, `completed_at`; otherwise the current `NO_PITCH` / `INELIGIBLE` behaviour;
  - add `abandon(PitchTurn $turn)` → `REPLY_FAILED`;
  - both never throw.
- [X] T043 [US2] In app/Jobs/ProcessInboundWhatsAppMessageJob.php:
  - move the `PitchCoordinator` completion out of the reply-generation step to after `WhatsAppMessageService::send()` succeeds, passing the reply text;
  - call `abandon()` when the send throws, then rethrow (existing failure behaviour unchanged).

  Keep the turn object available to `handle()`, for example by returning it alongside the reply.
- [X] T044 [US2] Update app/Ai/Agents/GuestConciergeAgent.php:
  - `tools()` adds `new PitchActivityTool(...)` only when `$this->pitchTurn?->mayPitch()` and the shortlist is non-empty;
  - `instructions()` removes the "Proactively recommend activities … tailor a recommendation yourself … don't recommend something you can't confirm is currently offered." paragraph and adds `{$this->pitchingInstructions()}`, a new protected method returning the WP-17.4 eligible-with-candidate text (activity name and the recommendation's reason) or the "Do not suggest activities the guest did not ask about…" text;
  - keep the reaction-recording paragraph, but say it applies to recommendations "you offered (from the recommendations tool)".
- [X] T045 [US2] Update app/Ai/Tools/GetRecommendationsTool.php: restrict the query to `whereNotNull('delivered_at')->orWhereNotNull('pitch_decision_id')` (grouped) for the reservation, and update its description to "recommendations already offered to this guest".
- [X] T046 [US2] In app/Services/Pitching/PitchCoordinator.php `decide()`, update the comment around `ensureGenerated()`: generated items are pending review, so this turn shortlists only what was approved before it. When the `NO_CANDIDATES` gate blocks, set its detail from the reservation's counts (FR-016):
  - `Nothing approved; {n} pending approval.` when pending items exist;
  - `No recommendation to offer.` otherwise.

  Assert both details, and that no turn ever shortlists a just-generated row, in T038.

**Checkpoint**: Approval is enforced end to end for conversational pitching.

---

## Phase 5: User Story 3 — One more try after a "no", then stop (Priority: P1)

**Goal**: The D10 pitch-flow rule replaces "declined this stay", per guest per reservation.

**Independent Test**: Seed a pitched-and-declined first pitch, then run turns at +2 h and
+25 h with different and identical activities, and an explicit request
([quickstart](quickstart.md) step 6).

### Tests for User Story 3

- [X] T047 [P] [US3] Write tests/Feature/DeclineRetryTest.php:
  - after a declined first pitch at t0, a contextual opening at t0+2h is eligible, the turn is a retry, and the staged decision has `is_retry = true`;
  - a further opening after the retry → blocked `retry_used`;
  - no retry and an opening at t0+24h+1m → blocked `retry_window_closed`;
  - the declined activity is excluded with reason `declined_activity`, including on explicit requests (FR-019);
  - an explicit request after the flow closed is eligible and does not set `is_retry`;
  - the retry does not count toward `max_unsolicited_per_stay` (cap 1: first pitch declined, retry still allowed);
  - an accepted first pitch with cap 1 → blocked `pitch_cap`, not a retry;
  - multi-room: two stays on one reservation share the flow (the decline on stay A closes stay B's turn);
  - new decisions record `rules_version = 2.0`.

### Implementation for User Story 3

- [X] T048 [US3] In app/Services/Pitching/PitchEligibilityService.php replace the `DECLINED_THIS_STAY` logic in `evaluateOpeningGates()` with a private `pitchFlow(Stay $stay, CarbonInterface $now): PitchFlow` (new readonly app/Support/Pitching/PitchFlow.php holding `declined`, `firstPitchAt`, `retryUsed`, `closed`, `isRetryNext`), derived as in [data-model.md §2](data-model.md):
  - unsolicited pitched decisions across all stays where `stays.reservation_id = $stay->reservation_id`, with `result` null or `pitched` and `explicit_request = false`, joined to recommendations;
  - the earliest such decision whose recommendation status is `rejected` defines the declined flow;
  - `t0` is the first unsolicited pitch at or before it within 24 h;
  - a retry exists when there is an `is_retry` decision after it.

  Gates: `RETRY_USED`, or `RETRY_WINDOW_CLOSED` when `now >= t0 + 24h`. Explicit requests pass both with the existing "explicit request" detail.
- [X] T049 [US3] Change `unsolicitedPitchesThisStay()` to count per reservation (same join as T048) and to exclude `is_retry` decisions. When `isRetryNext` is true, pass the cap gate with the detail "retry after a decline" instead of blocking.
- [X] T050 [US3] In `candidates()`, exclude activities that have a `rejected` recommendation on the same reservation, with reason `declined_activity` (new private const next to `OUTSIDE_INTEREST`), for every opening.
- [X] T051 [US3] In app/Services/Pitching/PitchCoordinator.php, set `PitchTurn::$isRetryTurn` from the report's flow when the turn passes. Bump `rules_version` to `'2.0'` in config/pitching.php and update the comment there on `max_unsolicited_per_stay` ("the one retry after a decline does not count").

**Checkpoint**: US1–US3 complete; the P1 scope (approval + approved pool + retry) is shippable.

---

## Phase 6: User Story 5 — Guests can stop proactive messages and offers (Priority: P2)

**Goal**: Opt-out by keyword, free text or staff, blocking proactive messages and
unsolicited pitches, never answers or completion notices (R14, spec Q2).

**Independent Test**: Send `STOP`; check the flag, the fixed reply, no AI call, the
`opted_out` gate on the next turn; resume via the staff endpoint ([quickstart](quickstart.md) step 9).

### Tests for User Story 5

- [X] T052 [P] [US5] Write tests/Feature/ProactiveOptOutTest.php:
  - an inbound `STOP`, ` stop ` or `توقف` from a guest sets `proactive_opted_out_at`, source `guest_message`, replies with `lang/{locale}/proactive.php` `opt_out.confirmed`, makes no agent call (`GuestConciergeAgent::fake()` asserts nothing prompted) and records one `opted_out` event;
  - a second `STOP` → no second event;
  - `START` resumes;
  - "please stop sending me offers" with the faked Concierge calling `SetContactPreferenceTool(opt_out)` → opted out;
  - an opted-out guest's turn → decision gate `opted_out` blocks, while explicit requests still pass and stay eligible;
  - the Phase 7 completion notice (`SendGuestRequestNoticeJob`) is still sent to an opted-out guest;
  - a message containing STOP inside a sentence does not opt out through the keyword path.
- [X] T053 [P] [US5] Write tests/Feature/GuestContactPreferenceTest.php for `PUT /api/guest/{guest}/contact-preference`:
  - 200 and an event on change; 200 and no event when the state is unchanged;
  - `guests.update` allowed / 403 (add it to the tests/Feature/PermissionAuthorizationTest.php dataset);
  - other hotel → 404 (add it to tests/Feature/TenantIsolationTest.php);
  - `GuestResource` exposes `proactive_opted_out`, `proactive_opted_out_at` and `proactive_opt_out_source`.

### Implementation for User Story 5

- [X] T054 [P] [US5] Create config/proactive.php with:
  - `opt_out_keywords` (`stop`, `unsubscribe`, `stop all`, `cancel messages`, `توقف`, `إيقاف`, `الغاء`);
  - `opt_in_keywords` (`start`, `subscribe`, `ابدأ`);
  - `sweep_minutes` (15), `guest_active_minutes` (30), `max_send_attempts` (2), `stale_claim_minutes` (5).

  Comment each entry.
- [X] T055 [P] [US5] Create lang/en/proactive.php and lang/ar/proactive.php with the `opt_out.confirmed`, `opt_out.resumed` and `opt_out.already` texts (filled further in T075).
- [X] T056 [US5] Create app/Services/GuestContactPreferenceService.php:
  - `optOut(Guest $g, string $source): bool` and `optIn(Guest $g, string $source): bool`, each a conditional update returning whether the state changed, recording `opted_out` / `opted_in` events through `EventLogger::record` with the source;
  - `matchKeyword(string $text): ?string` normalizes (trim, lowercase, strip punctuation and tatweel) and compares the **whole** message to the config lists.
- [X] T057 [US5] In app/Jobs/ProcessInboundWhatsAppMessageJob.php, for guest senders only and before generation, call `matchKeyword()`. On a match, apply the change, reply with the fixed text in the guest's language (`preferred_language` `ar` else `en`) through the existing reply path, skip the agent, the pitch coordinator and AI metering, and still record the inbound message and contact time as today.
- [X] T058 [P] [US5] Create app/Ai/Tools/SetContactPreferenceTool.php per [contracts/ai-tools.md](contracts/ai-tools.md):
  - constructor `(Guest $guest)`; args `preference` (`opt_out`|`resume`, required) and `guest_words` (string, required);
  - calls the service with source `guest_message` and returns a confirmation sentence.

  Register it in `GuestConciergeAgent::tools()`. Add one instruction line: "If the guest asks you to stop sending messages or offers, call the contact-preference tool."
- [X] T059 [US5] In app/Services/Pitching/PitchEligibilityService.php `evaluateOpeningGates()`, add the `OPTED_OUT` gate before the cap gate:
  - blocks unsolicited openings when the guest is opted out;
  - passes for explicit requests with detail "Explicit request: opt-out does not stop answers.";
  - it is an opening gate, not a cheap gate, because an opted-out guest's explicit request must still be classified and answered.

  Add `opted_out` to `GuestConciergeAgent::pitchingInstructions()` so the model is told not to offer unprompted.
- [X] T060 [P] [US5] Create app/Http/Requests/UpdateContactPreferenceRequest.php (`proactive_opted_out`: `required|boolean`) and app/Http/Controllers/GuestContactPreferenceController.php (`update(UpdateContactPreferenceRequest, Guest)`: authorize `update` on the guest, call the service with source `staff`, return `GuestResource`).
- [X] T061 [US5] Register `PUT /guest/{guest}/contact-preference` in routes/api.php before the guest resource route, and add the three opt-out fields to app/Http/Resources/GuestResource.php.

**Checkpoint**: Opt-out works for conversational pitching; US4 will read it.

---

## Phase 7: User Story 4 — The Concierge reaches out at the right moment (Priority: P2)

**Goal**: Scheduled, deduplicated proactive messages from fixed texts under every
guardrail, recorded per evaluation (R9–R13, R15–R17).

**Independent Test**: Proactive on for one hotel; run the trigger job at chosen hotel-local
times for guests in each situation; check exactly which rows are `sent`, `scheduled`
(deferred) or `skipped`, and why ([quickstart](quickstart.md) steps 7–8).

### Tests for User Story 4

- [X] T062 [P] [US4] Write tests/Feature/ProactiveTriggersTest.php for `EvaluateProactiveTriggersJob` and `ProactiveTriggerFinder`:
  - proactive off (default) → no rows;
  - first morning: a guest checked in yesterday → one row, due today 10:00 local, `valid_until` 13:00;
  - mid-stay: a 4-night reservation → a row on day 2; a 3-night one → none;
  - upcoming activity: a confirmed booking at 08:00 tomorrow → due today 18:00; at 15:00 → due 11:00, valid until 14:00; a pending booking → none;
  - recommendation approved: approving an item for an in-house guest dispatches evaluation and creates a row; a guest already pitched unprompted this reservation (cap raised to 2) gets no row;
  - running the job twice creates no duplicates (unique key);
  - with 500 in-house guests seeded in one hotel, one sweep issues a constant number of queries (assert with `DB::enableQueryLog()`, at most 2 per trigger plus inserts), so it cannot grow per guest (plan performance goal);
  - a multi-room reservation → one milestone row;
  - a disabled trigger → no rows for it;
  - other hotels unaffected.
- [X] T063 [P] [US4] Write tests/Feature/ProactiveGuardrailsTest.php for `SendProactiveMessageJob` with `Http::fake()` for WhatsApp:
  - sends inside the window → `sent`, `body`, `locale`, `sent_at`, and one `proactive_messages_sent` meter event;
  - quiet hours (22:00) → stays `scheduled`, reason `quiet_hours`, `due_at` = 09:00 next morning;
  - guest's last message 30 h ago → `skipped/outside_window`, no HTTP call, no email (`Notification::fake()` asserts none);
  - guest wrote 10 minutes ago → deferred `guest_active`;
  - daily cap reached → deferred to the next day, or skipped if past `valid_until`;
  - a reminder and a pitch due the same day → the reminder is sent and the pitch deferred;
  - opted out → `skipped/opted_out`;
  - pitches: departing today, escalated this stay, open complaint, pitching disabled, pitch cap, retry used, no offerable recommendation → each skipped with its reason;
  - a cancelled booking → `booking_not_confirmed`;
  - past `valid_until` → `expired`;
  - a send that throws → retried once, then `failed/send_failed`;
  - two workers claiming the same row → one send.
- [X] T064 [P] [US4] Write tests/Feature/ProactivePitchTest.php:
  - a sent proactive pitch creates a `PitchDecision` (`opening = proactive`, `explicit_request = false`, `result = pitched`, `proactive_message_id` set), sets `recommendations.pitch_decision_id`, and marks the recommendation `sent` with channel `whatsapp` only after the send succeeds;
  - it counts toward the cap;
  - the guest's "no" through `UpdateRecommendationTool` starts the retry rule (FR-023);
  - the message is appended to the guest's latest conversation as an assistant message;
  - the next guest turn's `GuestConciergeAgent::instructions()` lists it with its recommendation id;
  - a rendered text never contains an unfilled `:placeholder`;
  - a missing activity name → `skipped/missing_data`;
  - an Arabic guest gets the `ar` text.
- [X] T065 [P] [US4] Write tests/Feature/ProactiveSettingsTest.php:
  - `PUT /api/hotel/{id}` with partial `proactive_settings` merges over the stored settings;
  - bad times, `daily_cap` 0 or 4, `hours_before` 25 or unknown keys → 422;
  - an employee → 403;
  - `HotelResource` returns the settings with defaults filled.
- [X] T066 [P] [US4] Write tests/Feature/ProactiveMessageLogTest.php:
  - `GET /api/proactive-messages` as admin lists rows with the filters `trigger`, `status`, `reason`, `guest_id`, `reservation_id`, `sent_from` and `sent_to`, sorted `-due_at` by default;
  - show works; an employee with any permission → 403;
  - another hotel's rows are invisible (add it to tests/Feature/TenantIsolationTest.php).

### Implementation for User Story 4

- [X] T067 [P] [US4] Create app/Support/WhatsApp/MessagingWindow.php by extracting `SendGuestRequestNoticeJob::windowOpen()` and its `WINDOW_MINUTES` margin:
  - `isOpen(string $phoneDigits, ?CarbonInterface $at = null): bool` and `lastInboundAt(string $phoneDigits): ?CarbonInterface`, both per phone across hotels;
  - update app/Jobs/SendGuestRequestNoticeJob.php to use it, with behaviour unchanged (its existing tests must pass untouched).
- [X] T068 [US4] In app/Http/Controllers/HotelController.php `update()`:
  - when `proactive_settings` is present, merge it over `$hotel->proactiveSettings()->toArray()`;
  - validate the merged array with `Validator::make($merged, ProactiveSettings::rules())`;
  - on failure return `apiResponse(first error, 422)`;
  - otherwise store it.

  Follow the existing `invalidHousekeepingDefaults` pattern. Add `proactive_settings` (defaults filled) to app/Http/Resources/HotelResource.php.
- [X] T069 [US4] Create app/Services/Proactive/ProactiveTriggerFinder.php (R9, R10). It runs inside `runForHotel` and returns rows to insert, one method per trigger, each a single indexed query using the hotel timezone and `ProactiveSettings`:
  - `firstMorning(Hotel, CarbonInterface $now)`: in-house stays checked in on the previous local date → one per reservation, keyed `first_morning:{reservation_id}`, for the primary guest, due at `milestone_time`, valid +3 h;
  - `midStay(Hotel, $now)`: reservations of ≥ 4 nights where the local date = arrival + `floor(nights / 2)` → `mid_stay:{reservation_id}`;
  - `upcomingActivity(Hotel, $now)`: confirmed bookings scheduled in the next 36 h → `booking:{booking_id}`, due per the reminder settings, valid until 1 h before `scheduled_for`;
  - `recommendationApproved(Hotel, $now, ?string $reservationId = null)`: offerable recommendations on reservations with an in-house stay **and no unsolicited pitch yet** (no pitch decision for the reservation with `explicit_request = false` and `result` null or `pitched`) → `recommendation:{recommendation_id}`, due now, valid until the planned departure date's local start of day (FR-025).
- [X] T070 [US4] Create app/Jobs/EvaluateProactiveTriggersJob.php:
  - `handle()` iterates active hotels whose settings have `enabled` true;
  - for each enabled trigger it calls the finder and `ProactiveMessage::insertOrIgnore()` rows with status `scheduled` and `hotel_id` set explicitly;
  - it then dispatches `SendProactiveMessageJob` for this hotel's rows with `status = scheduled` and `due_at <= now`.

  Add a static `forReservation(string $reservationId)` that dispatches a job instance limited to `recommendationApproved` for that reservation. Call it from `RecommendationApprovalService::approve()` after a successful approval. Schedule the job `everyFifteenMinutes()->withoutOverlapping()` in routes/console.php.
- [X] T071 [US4] Create app/Services/Proactive/ProactiveGuardrails.php. `check(ProactiveMessage $m, CarbonInterface $now): GuardrailResult` (new readonly app/Support/Proactive/GuardrailResult.php: `send`, `defer(reason, until)` or `skip(reason)`) evaluates in this order:
  1. hotel disabled → `disabled`; trigger disabled → `trigger_disabled`;
  2. past `valid_until` → `expired`;
  3. opted out → `opted_out`;
  4. no phone → `no_whatsapp`;
  5. window closed (`MessagingWindow::isOpen`) → `outside_window`;
  6. reminder: booking not confirmed → `booking_not_confirmed`; stay not in house or expected before the activity → `not_in_house`;
  7. pitch: for `recommendation_approved`, re-check at send time that the reservation still has no unsolicited pitch, else skip with `already_pitched`. Then run `PitchEligibilityService::evaluateCheapGates()` and the opening gates with `PitchOpening::PROACTIVE`, mapping gates to reasons (`not_in_house`, `departing`, `escalated`, `open_complaint`, `pitching_disabled`, `pitch_cap`, `retry_used`, `retry_window_closed`, `opted_out`); then candidate check: a recommendation trigger needs its own recommendation offerable, and a milestone takes the top `candidates()` entry, else `no_candidate` / `recommendation_not_offerable`;
  8. quiet hours → defer to `quietHoursEnd`;
  9. guest active within `guest_active_minutes` → defer +30 min;
  10. daily cap (sent rows for this guest today, local) → defer to the next day's `milestone_time`; same-day priority: when a reminder for this guest is due today and this is a pitch, defer the pitch.

  A deferral past `valid_until` becomes `skip(expired)`.
- [X] T072 [US4] Create app/Services/Proactive/ProactiveMessageRenderer.php (R12): `render(ProactiveMessage $m, ?Recommendation $r, ?Booking $b): ?RenderedMessage`.
  - locale: `ar` if the guest's `preferred_language` is `ar`, else `en`;
  - placeholders: guest first name, hotel name, activity name, description (≤ 160 characters, word-safe), date and time in the hotel timezone;
  - picks the variant key without optional placeholders that are empty;
  - returns null when a required value is missing (→ `missing_data`);
  - asserts no `:` placeholder remains.
- [X] T073 [P] [US4] Create app/Support/Ai/ConversationAppender.php (R13): `appendAssistantMessage(Guest $guest, string $text): string`.
  - finds the guest's latest conversation through `GuestConciergeAgent`'s participant (the same lookup `continueLastConversation` uses), or creates one via the configured `ConversationStore`;
  - inserts an `assistant` row with empty tool calls and results, matching the package's columns;
  - returns the conversation id.
- [X] T074 [US4] Create app/Jobs/SendProactiveMessageJob.php (`$tries = 3`, `$timeout = 120`), modelled on SendGuestRequestNoticeJob:
  1. Claim: conditional update `scheduled → sending` where `due_at <= now`, or a `sending` row older than `stale_claim_minutes`.
  2. Inside `runForHotel`, run `ProactiveGuardrails::check`:
     - defer → back to `scheduled` with the new `due_at` and reason;
     - skip → `skipped` with the reason.
  3. Render; on null → `skipped/missing_data`.
  4. For pitches, in a transaction create the `PitchDecision` (`eligible = true`, `gates` from the guardrail report, `opening = proactive`, `explicit_request = false`, `candidates`, `rules_version`, `decided_at`, `proactive_message_id`, `is_retry` from the flow) and set `recommendations.pitch_decision_id` conditionally; if that update affects 0 rows → `skipped/recommendation_not_offerable`.
  5. Send through `WhatsAppMessageService::send`.
  6. On success:
     - `sent`, `body`, `locale`, `sent_at`, `conversation_id` from `ConversationAppender`;
     - for pitches: `markDelivered(WHATSAPP, now(), SYSTEM)`, a `DELIVERED` outcome (`AttributionMethod::CONVERSATIONAL`, channel `whatsapp`), decision `result = pitched`, `recommendation_id`, `chosen_rank`, `mention_verified = true`, `completed_at`;
     - meter `PROACTIVE_MESSAGES_SENT` with key `proactive:{id}`, `ActorKind::SYSTEM`, through `MeteringService::safely`.
  7. On a send exception: increment `attempts`; below `max_send_attempts` → `scheduled` with `due_at` +5 min; else `failed/send_failed`. A pitch decision becomes `reply_failed`.

  Implement `failed()` like the notice job. No AI call anywhere in this job.
- [X] T075 [US4] Fill lang/en/proactive.php and lang/ar/proactive.php with the keys `first_morning.with_recommendation`, `mid_stay.with_recommendation`, `upcoming_activity.with_time`, `upcoming_activity.without_description` and `recommendation_approved.default`. Use the placeholders `:guest`, `:hotel`, `:activity`, `:description`, `:date` and `:time`. Each pitch text ends with an invitation to reply; each text is at most 2 short sentences plus the invitation. Arabic is written natively, not transliterated.
- [X] T076 [US4] In app/Ai/Agents/GuestConciergeAgent.php add `proactiveContextInstructions()`. It lists this guest's `proactive_messages` sent in the last 24 h (trigger, sent time in the hotel timezone, body, and `recommendation_id` for pitches) with the line "If the guest replies to one of these offers, record their reaction with the update-recommendation tool using that id." Include it in `instructions()` only when non-empty.
- [X] T077 [US4] Allow the Concierge to record reactions to proactive pitches. app/Ai/Tools/GetRecommendationsTool.php already returns pitched items (T045). Verify `UpdateRecommendationTool` accepts a recommendation that has a `pitch_decision_id` from a proactive decision (same reservation check). Add a test case to T064.
- [X] T078 [P] [US4] Create app/Policies/ProactiveMessagePolicy.php (`viewAny` / `view`: admin or super admin of the same hotel, following the pattern used for other admin-only resources such as staff roles; everything else false). Create app/Http/Resources/ProactiveMessageResource.php with the fields in [contracts/proactive-messaging-api.md](contracts/proactive-messaging-api.md).
- [X] T079 [US4] Create app/Http/Controllers/ProactiveMessageController.php (`index(GenericIndexRequest)` through `GenericQuery::apply()`, plus `show`). The extra filters `sent_from` and `sent_to` apply as date bounds on `sent_at`. Register `GET /proactive-messages` and `GET /proactive-messages/{proactiveMessage}` in routes/api.php.

**Checkpoint**: Proactive messaging works for a pilot hotel; every evaluation is visible.

---

## Phase 8: User Story 6 — Approval queue shows what needs attention (Priority: P3)

**Goal**: Filters, sorts and bulk decisions in the queue, plus single-item Admin AI
approvals with confirmation (R18).

**Independent Test**: Seed items across dates; filter, sort, bulk-approve with mixed
eligibility; ask the faked advisor to approve one named item and to "approve everything"
([quickstart](quickstart.md) step 2).

### Tests for User Story 6

- [X] T080 [P] [US6] Write tests/Feature/RecommendationQueueTest.php:
  - index filters `status` (comma list), `source`, `guest_id`, `arrival_from` / `arrival_to`, `reservation_id` and `activity_id`;
  - sorts `arrival_date`, `-priority` and `predicted_confidence`;
  - `POST /api/recommendations/decide`:
    - approve with 3 eligible ids, 1 already decided and 1 from another hotel → `decided` has 3; `skipped` has `already_decided` and `not_found`; 3 events;
    - 101 ids or duplicate ids → 422;
    - reject with a reason applies the reason to each;
    - 100 eligible ids finish in under 2 seconds (plan performance goal);
    - without `recommendations.approve` → 403 (add it to the tests/Feature/PermissionAuthorizationTest.php dataset).
- [X] T081 [P] [US6] Write tests/Feature/AdminRecommendationToolsTest.php:
  - `GetRecommendationsForReviewTool` returns at most 50 pending items with `total`, and filters by guest name, room number and activity;
  - it is refused without `recommendations.view`;
  - `DecideRecommendationTool` is registered in `AdminToolset` as a write declaring `[Recommendation::class]` and `RECOMMENDATIONS_APPROVE`, and implements `ConfirmsBeforeRunning` with a summary naming the activity and guest;
  - through `AdvisorTurn` with a faked pending approval: confirm → approved, with the audit row carrying the AI context and the acting admin; decline → unchanged;
  - an employee without the permission → refused;
  - the tool schema has no array property (no bulk);
  - the existing `AdminToolsetArchTest` invariants still pass.

### Implementation for User Story 6

- [X] T082 [US6] Add `decideMany(array $ids, string $action, User $by, ?string $reason): array` to app/Services/Recommendations/RecommendationApprovalService.php:
  - loads the ids within the hotel scope; missing ids → `not_found`;
  - calls `approve` / `reject` for each, catching `RuntimeException` → `already_decided`;
  - returns `['decided' => [...], 'skipped' => [['id', 'reason'], ...]]`.
- [X] T083 [P] [US6] Create app/Http/Requests/DecideRecommendationsRequest.php: `action` `required|in:approve,reject`; `ids` `required|array|min:1|max:100`; `ids.*` `uuid|distinct`; `reason` `nullable|string|max:500`, prohibited unless `action = reject`.
- [X] T084 [US6] Add `decide(DecideRecommendationsRequest)` to app/Http/Controllers/RecommendationController.php: authorize `approve` against the hotel (policy `approve` with no model, so add a nullable model parameter to `approve()` in RecommendationPolicy), call `decideMany`, and return 200. Register `POST /recommendations/decide` in routes/api.php.
- [X] T085 [US6] Extend `RecommendationController::index()`:
  - `guest_id` → `whereHas('reservation', guest_id)`;
  - `arrival_from` / `arrival_to` → `whereHas('reservation')` on the arrival date column;
  - sort `arrival_date` → join reservations, with the `-` prefix for descending;
  - `status` accepts a comma list.

  Keep `GenericQuery::apply()` for everything else and validate the new params in the request rules.
- [X] T086 [P] [US6] Create app/Ai/Tools/GetRecommendationsForReviewTool.php per [contracts/ai-tools.md](contracts/ai-tools.md):
  - constructor `(Hotel $hotel)`; optional args `status` (default `pending_approval`), `guest`, `room_number`, `reservation_id`, `activity`, `arrival_from`, `arrival_to`;
  - returns `ListResult` (≤ 50) with id, guest, room, activity, reason, predicted confidence, status and source.
- [X] T087 [P] [US6] Create app/Ai/Tools/DecideRecommendationTool.php: constructor `(Hotel $hotel, User $user)`; args `recommendation_id` (uuid, required), `action` (`approve`|`reject`, required) and `reason` (string ≤ 500).
  - implements `ConfirmsBeforeRunning::confirmationSummary()` in the admin's locale (`Approve "{activity}" for {guest} (room {n})` / `Reject …`);
  - `handle()` calls `RecommendationApprovalService` and returns its message or the `RuntimeException` text.
- [X] T088 [US6] Register both tools in app/Ai/Tools/Admin/AdminToolset.php:
  - `self::read(new GetRecommendationsForReviewTool($hotel), [Permission::RECOMMENDATIONS_VIEW])`;
  - `self::write(new DecideRecommendationTool($hotel, $user), [Permission::RECOMMENDATIONS_APPROVE], [Recommendation::class])` with confirmation.

  Add to the app/Ai/Agents/AdminAdvisorAgent.php instructions: "Approve or reject recommendations one at a time, only ones the user named. If asked to approve or reject many at once, decline and point them to the approval queue."
**Checkpoint**: All six stories complete.

---

## Phase 9: Polish & Cross-Cutting

- [X] T089 [P] Update docs/reservation-recommendations-api-documentation.md:
  - new statuses and lifecycle diagram, `source` and the review fields, `offerable`;
  - approve, reject and bulk decide endpoints, index filters and sorts;
  - the edit rule and its 422.
- [X] T090 [P] Update docs/recommendation-outcome-api-documentation.md: proactive pitches produce the same `DELIVERED` outcome and attribution, and a decision now records `opening = proactive`.
- [X] T091 [P] Update docs/hotel-api-documentation.md (`proactive_settings`, defaults, validation), docs/guest-api-documentation.md (contact preference endpoint and fields), and add docs/proactive-messages-api-documentation.md (the log endpoint, triggers, reasons, guardrail order, keywords).
- [X] T092 [P] Update docs/staff-roles-api-documentation.md: add `recommendations.approve` to the permission reference with its endpoints and Admin AI tool, and note it is not an employee default. Update docs/ai-advisor-chat-api-documentation.md with the two new tools.
- [X] T093 [P] Update docs/usage-metering-api-documentation.md with the `proactive_messages_sent` feature.
- [X] T094 Write docs/latest-changes-<date>.md. Breaking changes:
  - `pending` removed; `pending_approval`, `approved` and `rejected_by_admin` added;
  - the edit rule;
  - `GetRecommendationsTool` (guest) narrowed.

  Also cover the new endpoints, the proactive rollout steps (off by default; enable per hotel; requires `PITCHING_ENABLED` for pitches), and the frontend slice: approval queue with bulk actions and status filters, proactive settings form, proactive log, opt-out badge and toggle.
- [X] T095 [P] Update docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md §5 "Questions left to each spec": record the SPEC-073 answer (no templates; proactive messages only inside the 24 h window).
- [X] T096 Run `./vendor/bin/pint`, then `php artisan test` (full suite on Postgres). Fix failures without weakening isolation or permission tests.
- [X] T097 Walk through [quickstart.md](quickstart.md) steps 1–10 locally and tick each expected outcome. Record any deviation in this file under "Implementation notes".

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (1)** → **Foundational (2)**, which blocks every story.
- **US1 (3)** comes after Foundational. It is the MVP and needs nothing else.
- **US2 (4)** comes after Foundational. It can run beside US1, but T046's check is cleanest after T036.
- **US3 (5)** comes after US2, because it needs the pitch tool, `PitchTurn` staging and `PITCHED` decisions.
- **US5 (6)** comes after US2 (it shares `GuestConciergeAgent` and the inbound job edits). It is independent of US3.
- **US4 (7)** comes after US3 and US5: its guardrails use the retry flow and opt-out. It also needs US1 (approval dispatch).
- **US6 (8)** comes after US1. Its Admin AI tools need 009's `AdminToolset` (present).
- **Polish (9)** comes last.

### Within each story

Tests first (failing) → enums/models → services → controllers/tools → routes/docs.
Shared files edited by several tasks, which must not run in parallel:
- `PitchEligibilityService.php`: T023, T048–T050, T059;
- `GuestConciergeAgent.php`: T044, T058, T059, T076;
- `ProcessInboundWhatsAppMessageJob.php`: T043, T057;
- `RecommendationController.php`: T033, T084, T085;
- `routes/api.php`: T034, T061, T079, T084.

### Parallel opportunities

- Foundational: T004, T006, T007, T009–T015, T018–T021 and T024 touch different files.
- Each story's test files ([P]) can be written together.
- US4: T067, T073 and T078 are independent of the finder, guardrails and renderer chain.
- US6: T083, T086 and T087 run alongside each other.
- Polish docs T089–T093 and T095.

## Parallel example: Foundational

```text
T004 proactive_messages migration   T006 guests opt-out migration   T007 hotels settings migration
T009 RecommendationSource           T012–T014 proactive enums       T021 ProactiveSettings
```

## Parallel example: User Story 4

```text
Tests:  T062 triggers   T063 guardrails   T064 proactive pitch   T065 settings   T066 log
Impl:   T067 MessagingWindow   T073 ConversationAppender   T078 policy + resource
then    T069 finder → T070 evaluate job → T071 guardrails → T072 renderer → T074 send job
```

## Implementation Strategy

### MVP first

Phases 1–3 (US1): approval exists and is enforced at the candidate query (T023). Stop,
run the US1 tests and the full suite, and demo the queue with single decisions.

### Incremental delivery

1. US1 → approval queue and audit (MVP).
2. US2 → the Concierge pitches only approved items through the gated tool. This is the
   first point where `PITCHING_ENABLED=true` is safe for a pilot hotel.
3. US3 → the D10 retry rule replaces "one no ends the stay".
4. US5 → guests can stop outreach and offers.
5. US4 → proactive messaging, enabled per pilot hotel.
6. US6 → bulk queue and Admin AI approvals.

Each increment keeps the suite green and is deployable on its own; proactive messaging
stays off until a hotel turns it on.

---

## Implementation notes (2026-10-10)

Deviations from the plan, each covered by tests:

- **`RecommendationStatus::PENDING` is kept, marked retired** (T008). The shipped
  `create_recommendations_table` migration names it as the old column default, so deleting
  the case would break fresh migrations. Nothing writes or reads it; the column default is now
  `pending_approval` and existing rows were migrated.
- **Another hotel's recommendation returns 403, not 404** on approve/reject, matching this
  repo's convention for bound records (TenantIsolationTest). Contract updated.
- **The staged pitch is completed from the database after the send**, not from the in-memory
  turn: the inbound job stores the reply before sending and retries sends without the turn
  object. `PitchCoordinator::delivered()` / `abandon()` find the guest's open staged decision
  (T042–T043).
- **`proactive_messages.recommendation_id` is filled at send time for milestone offers**, so
  the Concierge's "messages you sent first" context can name the recommendation (T076).
- **A deferral that would land past `valid_until` is skipped with the deferring reason**
  (`quiet_hours`, `daily_cap`, `guest_active`) rather than `expired`, which tells an admin
  why (T071).
- **Deferral times are normalised to the app timezone** in `GuardrailResult::defer()`; a
  hotel-local time was otherwise stored as wall time, three hours late in UTC+3 (T074).
- **The proactive log uses the generic `filter[...]` syntax** with top-level `sent_from` /
  `sent_to`, like other list endpoints (T079).
- **T097 (manual walkthrough)**: each quickstart step is exercised by an automated test
  (RecommendationApprovalTest, PitchFromApprovedPoolTest, PitchActivityToolTest,
  DeclineRetryTest, ProactiveTriggersTest, ProactiveGuardrailsTest, ProactivePitchTest,
  ProactiveOptOutTest, ProactiveMessageLogTest). It was not run by hand against a live
  WhatsApp number.

