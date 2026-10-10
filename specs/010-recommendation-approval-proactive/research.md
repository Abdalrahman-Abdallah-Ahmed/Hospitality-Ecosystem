# Research: Recommendation Approval and Proactive Concierge

Decisions for [spec.md](spec.md). Each entry: decision, rationale, alternatives considered.
"Today" refers to the code on `009-admin-ai-pms-tools` (2026-10-09).

## What exists today (inspection, constitution II)

| Area | Today | Gap for this feature |
| --- | --- | --- |
| `RecommendationStatus` | `pending, sent, accepted, rejected, purchased, ignored, expired, cancelled` | No approval states |
| `CreateRecommendationTool` | Writes `status = pending` | Must write `pending_approval` and a source |
| `PitchEligibilityService::candidates()` | Reads `pending`, undelivered, never pitched | Must read `approved` only |
| `PitchEligibilityService::evaluateOpeningGates()` | `DECLINED_THIS_STAY` blocks after any refusal | Replace with the pitch-flow rule (D10) |
| `PitchCoordinator::complete()` | Writes `NO_PITCH` / `INELIGIBLE` only | `PITCHED` is never written |
| Pitch tool | **Not built.** WP-17 of `docs/PGRIP_Ecosystem_WP_Conversational_Pitching_v1_0.md` designs `PitchActivityTool`; nothing sets `recommendations.pitch_decision_id` | Needed: decline retry and caps are derived from pitched decisions |
| `GuestConciergeAgent` prompt | "Proactively recommend activities … if nothing suitable exists yet, tailor a recommendation yourself" | Bypasses approval; must be replaced |
| `GetRecommendationsTool` (Concierge) | Returns every recommendation of the reservation, any status | Would expose unapproved suggestions |
| `RecommendationController::update` | Edits activity, reason, priority, reservation; status locked | Needs the FR-006a rule |
| Admin AI (`AdminToolset`) | No recommendation tool | FR-012 tools |
| Messaging window | `SendGuestRequestNoticeJob::windowOpen()` (per phone, any hotel, 24 h − 10 min margin) | Reuse; currently private |
| Guest notices | Fixed `lang/{en,ar}/guest_notices.php` texts, claim-then-send, never AI-written | Same pattern for proactive texts |
| Hotel settings | `hotels.ai_preferences` JSON; Hotel update is admin-only | Proactive settings |
| Guest opt-out | None | New |

## R1 — Status names and the retired `pending`

**Decision**: Add `pending_approval`, `approved` and `rejected_by_admin` to `RecommendationStatus`
and remove the `pending` case. A migration rewrites `pending` rows to `pending_approval`
(FR-011). `RecommendationDeliveryService::markDelivered()` moves `approved → sent` where it
moved `pending → sent` before. `expired` and `cancelled` keep their meaning.

**Rationale**: Keeping `pending` beside `pending_approval` invites the old meaning
("offerable") to come back through code nobody updated. Removing the case makes every
remaining reference a compile-time-visible error (`RecommendationStatus::PENDING` no longer
resolves), and there are only four.

**Alternatives**: a separate `approval_status` column next to `status`. Rejected: two
columns would have to agree (an `approved` + `rejected` row is meaningless), and every
reader would need both. One lifecycle column matches the plan (D9: "add … to the lifecycle").

## R2 — Approval writes

**Decision**: A new `App\Services\Recommendations\RecommendationApprovalService` is the only
writer of approval state: `approve()`, `reject()`, `decideMany()`, and `resetForEdit()`.
- Each decision is a conditional update (`WHERE id = ? AND status IN (...) AND delivered_at
  IS NULL AND pitch_decision_id IS NULL`), so two approvers, or an approver racing a pitch,
  cannot both win. 0 rows → "already decided" (409-style 422 message, see contract).
- The event is recorded once through `EventLogger::record($recommendation, 'approved' |
  'rejected_by_admin', …)` with status from/to and reason. Inside the Admin AI guard the
  same call carries the AI context automatically (`EventLogger::asAiAgent(onBehalfOf, ai)`,
  shipped in 009).
- Bulk: up to 100 ids, each decided in its own conditional update, results returned per id
  (`decided` / `skipped` + reason). No all-or-nothing transaction: FR-008 says ineligible
  items are skipped, not fatal.

**Rationale**: Same shape as `RecommendationDeliveryService` (single writer, conditional
update, named event), which already solved the same race for delivery.

**Alternatives**: policy + controller writes. Rejected: the Admin AI tool must use the same
rules (FR-012); 009's parity rule puts shared rules in a service.

## R3 — Permission

**Decision**: One case, `Permission::RECOMMENDATIONS_APPROVE = 'recommendations.approve'`,
grouped with the other `recommendations.*` cases. `RecommendationPolicy::approve()` uses
`allows($user, Permission::RECOMMENDATIONS_APPROVE, $recommendation)`. Not in
`employeeDefaults()`; the enum docblock says why (deciding what the hotel tells guests).

**Rationale**: Spec assumption: one permission covers approve, reject and bulk.

## R4 — Edits after approval (FR-006a)

**Decision**: `RecommendationController::update` calls
`RecommendationApprovalService::resetForEdit($recommendation, $validated)` before saving:
- if the recommendation is delivered, pitched, or in a final status and the payload changes
  `activity_id`, `reason` or `reservation_id` → 422, nothing saved;
- if it is `approved` and one of those fields changes → status back to `pending_approval`,
  review fields cleared, `approval_reset` event;
- priority / predicted confidence changes never touch approval.

## R5 — Generation source

**Decision**: New column `recommendations.source` (`App\Enums\RecommendationSource`):
`staff_request` (`GenerateActivityRecommendationsJob`), `conversation`
(`PitchRecommendationGenerator`), `legacy` (backfill). `RecommendationAgent` gains a
`source` constructor argument passed to `CreateRecommendationTool`.

**Rationale**: FR-009 filters by source, and the queue needs to show why an item appeared.
The Admin AI has no generation tool today, so no `admin_ai` value is added (YAGNI).

## R6 — Expiry (FR-010)

**Decision**: `ExpireRecommendationsJob`, scheduled hourly in `routes/console.php`. Moves
`pending_approval` / `approved` rows that were never delivered or pitched to `expired` when
the reservation is `cancelled` or `checked_out`, or every stay of the reservation is
`departed`, `no_show` or `cancelled`, or its planned departure date is past in the hotel's
timezone. (Reservations have no `no_show` status yet — SPEC-012 — so a no-show is read
from the stays.) Plain conditional updates, one
`expired` event per row; runs per hotel inside `TenantContext::runForHotel()`.

**Alternatives**: expire lazily when read. Rejected: the queue would show stale items and
counts would drift.

## R7 — Build the pitch tool (WP-17) as part of this feature

**Decision**: Implement WP-17 as designed, adjusted to approval:
- `PitchActivityTool(PitchTurn)`, offered to the Concierge only when the turn may pitch. It
  takes the guest's words, re-checks eligibility under a row lock on the stay, stages the
  turn's single candidate (`recommendations.pitch_decision_id`), and refuses if the
  recommendation is no longer `approved` (FR-015).
- `PitchCoordinator::complete(PitchTurn, string $replyText)` after the WhatsApp send
  succeeds: `markDelivered(WHATSAPP)`, `DELIVERED` outcome, `mention_verified`,
  `result = PITCHED`. `abandon()` on send failure: `REPLY_FAILED`. The job currently
  completes the turn inside `process()`, before the send; the call moves after the send.
- `GuestConciergeAgent::instructions()` loses the "proactively recommend … tailor a
  recommendation yourself" paragraph and gains a turn-built pitching section (WP-17.4) in
  its own method.
- `GetRecommendationsTool` (guest) returns only recommendations the guest has been offered
  (delivered or pitched) — never `pending_approval`, `approved`-but-unoffered, or
  `rejected_by_admin`. It exists so the Concierge can record reactions.

**Rationale**: Without a pitch tool there is no `PITCHED` decision, so "first pitch",
"retry" and the cap cannot be computed, and the Concierge keeps free-styling suggestions
from the prompt. The design is already written and reviewed; this feature is where it
becomes necessary.

**Alternatives**: infer pitches from `UpdateRecommendationTool` calls. Rejected: a pitch
the guest ignores leaves no reaction, so it would never count toward the cap.

## R8 — Decline retry (D10)

**Decision**:
- `PitchGate::DECLINED_THIS_STAY` is replaced by `RETRY_USED` and `RETRY_WINDOW_CLOSED`;
  `OPTED_OUT` is added (R14). `rules_version` → `2.0`. Old decision rows keep their stored
  gate names (they are JSON strings).
- New column `pitch_decisions.is_retry` (bool, default false), set by the pitch tool when
  the turn stages a retry.
- Derivation, per guest per **reservation** (multi-room reservations share one budget; the
  join goes through `stays.reservation_id`):
  - *declined flow*: the earliest unsolicited `PITCHED` decision whose recommendation is
    `rejected`, plus its first pitch time `t0`;
  - if a declined flow exists and (a retry decision exists after it, or `now ≥ t0 + 24 h`)
    → `RETRY_USED` / `RETRY_WINDOW_CLOSED`, closed for the stay;
  - if a declined flow exists and neither holds → the next pitch is a retry; it does not
    count toward `max_unsolicited_per_stay` (FR-021).
- Explicit requests skip both gates (as today) but still exclude declined activities.
- `candidates()` excludes every activity with a `rejected` recommendation in the
  reservation, with reason `declined_activity` (FR-019), next to the existing
  `outside_interest`.

**Rationale**: Every counter stays derived from rows (the service's existing rule); one
boolean makes "was this the retry" explicit instead of re-deriving it from timestamps.

## R9 — Proactive delivery architecture

**Decision**: Two jobs and one table.
- `EvaluateProactiveTriggersJob` — scheduled every 15 minutes. For each active hotel with
  proactive messaging on, inside `runForHotel`, it finds candidates per enabled trigger and
  inserts a `proactive_messages` row per event with `insertOrIgnore` on the unique key
  `(hotel_id, guest_id, trigger, event_key)` (FR-029). Approving a recommendation also
  dispatches an evaluation for that reservation, so the opportunity trigger does not wait
  for the sweep.
- `SendProactiveMessageJob(id)` — claims the row (`status scheduled → sending`, stale-claim
  takeover after 5 minutes, same as `SendGuestRequestNoticeJob`), re-checks every guardrail
  against current data, then sends, defers (new `due_at`) or skips with a reason.
- Event keys: `first_morning:{reservation_id}`, `mid_stay:{reservation_id}`,
  `booking:{booking_id}`, `recommendation:{recommendation_id}`. Keyed by reservation, not
  stay, so a guest with three rooms gets one milestone message.

**Rationale**: The insert-or-ignore key makes duplicates impossible however often the sweep
or a retry runs; checking guardrails at send time (not at scheduling) handles cancellations,
check-outs, opt-outs and approvals withdrawn in between.

**Alternatives**: delayed jobs dispatched at the exact due time. Rejected: deferrals and
setting changes would need rescheduling, and a lost job loses the message silently.

## R10 — Trigger timing defaults

**Decision** (all hotel-local, configurable in proactive settings):
- *First morning*: the first day after check-in, due at 10:00, valid until 13:00.
- *Mid-stay*: reservations of ≥ 4 nights, on day `floor(nights / 2)` after arrival, 10:00–13:00.
- *Upcoming activity*: confirmed booking; activities scheduled before 12:00 → 18:00 the
  previous evening; otherwise 4 hours before; valid until 1 hour before start.
- *Approved opportunity*: any time outside quiet hours, valid until the departure-day gate.
- Quiet hours 21:00–09:00; daily cap 1; a reminder due the same day as a pitch goes first.

## R11 — Messaging window and "mid-conversation"

**Decision**: Extract `App\Support\WhatsApp\MessagingWindow` from
`SendGuestRequestNoticeJob::windowOpen()` (per phone, any hotel, 24 h − 10 min) and use it
from both jobs. Add `lastInboundAt(phone)` for the 30-minute "guest is mid-conversation"
deferral (FR-031). Outside the window → `skipped / outside_window`, terminal (spec Q1).

## R12 — Message texts

**Decision**: `lang/en/proactive.php` and `lang/ar/proactive.php`, one key per trigger with
variants for optional placeholders (`with_time`, `without_time`…). Placeholders: guest first
name, hotel name, activity name, activity description (truncated to 160 characters), date,
time. Language from `guests.preferred_language` (`ar` else `en`), same as guest notices.
- Activities have no meeting-point field today, so the reminder uses the variant without it;
  no new activity column is added for this feature.
- A missing required value (activity name, booking date/time) → `skipped / missing_data`.

## R13 — Proactive messages in the conversation

**Decision**:
- The sent text is appended to the guest's latest `laravel/ai` conversation as an
  `assistant` message (a new conversation is created if none exists), through a small
  `ConversationAppender` that writes the package's table the same way the store does.
- `RemembersWholeTurns` drops a leading assistant message, and a proactive message is
  usually exactly that. So `GuestConciergeAgent::instructions()` also gets a short
  "messages you sent first in the last 24 hours" section built from `proactive_messages`,
  including the recommendation id of a proactive pitch, so the Concierge can record the
  guest's reply with `UpdateRecommendationTool`.
- A proactive pitch writes a `PitchDecision` (`opening = proactive`, a new `PitchOpening`
  case, `explicit_request = false`, `result = PITCHED`) and sets the recommendation's
  `pitch_decision_id`; delivery is stamped only after WhatsApp accepts the send. Caps,
  decline retry and conversion attribution then treat it like any other pitch (FR-023, FR-038).

## R14 — Opt-out

**Decision**:
- Columns on `guests`: `proactive_opted_out_at` (nullable timestamp) and
  `proactive_opt_out_source` (`guest_message` | `staff`). Guests are hotel-scoped, so this
  is per guest per hotel.
- **Keyword path, in code**: before the Concierge runs, the inbound job matches the whole
  normalized message against a fixed list (`STOP`, `UNSUBSCRIBE`, `توقف`, `إيقاف`, …,
  in `config/proactive.php`). A match opts out and replies with a fixed confirmation; no AI
  call. `START` resumes.
- **Natural language**: a Concierge tool `SetContactPreferenceTool` (`opt_out` / `resume`)
  for "please stop sending me offers".
- **Staff**: `PUT /api/guest/{guest}/contact-preference`, permission `guests.update`, for a
  resume (or opt-out) the guest asked for at the desk.
- Every change is a named `EventLogger` event. Opted-out guests get the `OPTED_OUT` gate on
  every unsolicited pitch (spec Q2); explicit requests are still answered.

## R15 — Proactive settings storage

**Decision**: New JSON column `hotels.proactive_settings`, cast through a value object
`App\Support\Proactive\ProactiveSettings` that fills defaults (off; triggers; quiet hours;
daily cap; lead times). Written through the existing admin-only hotel update endpoint with
nested validation.

**Alternatives**: inside `ai_preferences`. Rejected: that blob is free-form and shown by the
Admin AI hotel-settings tool; proactive settings need a schema and defaults.

## R16 — Proactive log visibility and permissions

**Decision**: `GET /api/proactive-messages` (and `/{id}`) — hotel admins and super admins
only, like hotel settings (CLAUDE.md rule 4: it exposes and is configured by admin-only
settings). No new permission case for it.

## R17 — Metering

**Decision**: `MeterFeature::PROACTIVE_MESSAGES_SENT`, one event per sent message,
idempotency key `proactive:{id}`, `actorKind = system`. No AI cost context: sending makes
no model call (spec Q3). The guest's reply is metered as any guest turn.

## R18 — Admin AI tools (FR-012)

**Decision**: Two tools registered in `AdminToolset`:
- `GetRecommendationsForReviewTool` (read, `recommendations.view`): pending/approved
  recommendations filtered by guest, room, reservation or activity, bounded at 50.
- `DecideRecommendationTool` (write, `recommendations.approve`, confirm before running):
  one `recommendation_id`, `action` approve | reject, optional reason. It calls
  `RecommendationApprovalService`. No list argument, so bulk is impossible by schema; the
  instructions tell the model to point bulk requests to the queue.

## R19 — Breaking changes

- Status values: `pending` disappears from API responses; `pending_approval`, `approved`,
  `rejected_by_admin` appear. Clients that filter `status=pending` must switch.
- `PUT /recommendation/{id}` may now return 422 for content edits of an offered
  recommendation, and may move an approved one back to `pending_approval`.
- Summarized in `docs/latest-changes-<date>.md`.
