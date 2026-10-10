# Implementation Plan: Recommendation Approval and Proactive Concierge

**Branch**: `010-recommendation-approval-proactive` | **Date**: 2026-10-09 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/010-recommendation-approval-proactive/spec.md`.
Phase 10 of the master plan: SPEC-071 Recommendation Approval, SPEC-073 Proactive WhatsApp,
SPEC-074 Decline Retry Rule. Governed by D9, D10, D12.

**Depends on**: Phase 6 (bookings), Phase 7 (Concierge, messaging-window notices), Phase 9
(Admin AI guard, confirmations, AI audit context) — all present on the base branch.

## Summary

**Approval (R1–R6)**:
- `RecommendationStatus` gains `pending_approval`, `approved` and `rejected_by_admin`;
  `pending` is retired and migrated to `pending_approval`.
- `RecommendationApprovalService` is the single writer of approval state: approve, reject,
  bulk decide (≤ 100), and reset-on-edit. Each decision is a conditional update, so races
  have one winner, and each is audited.
- New permission `recommendations.approve`; three endpoints; extended index filters; an
  hourly `ExpireRecommendationsJob`.
- `recommendations.source` records where each one came from.

**Pitch tool (R7)**: inspection found WP-17 of the pitching plan was never built. Nothing
writes a `PITCHED` decision, and the Concierge prompt still tells the model to "tailor a
recommendation yourself" and reads every recommendation regardless of status. This feature
builds `PitchActivityTool` with `complete()`/`abandon()` after the send, replaces the prompt
paragraph, and filters the guest recommendations tool to offered items. Without it,
approval and the retry rule cannot be enforced.

**Decline retry (R8)**: `DECLINED_THIS_STAY` becomes `RETRY_USED` / `RETRY_WINDOW_CLOSED`,
derived per guest per reservation from pitch decisions plus a new `is_retry` flag.
Declined activities are excluded from candidates. Rules version 2.0.

**Proactive (R9–R13, R15–R17)**:
- A 15-minute `EvaluateProactiveTriggersJob` inserts `proactive_messages` rows with a unique
  `(hotel, guest, trigger, event_key)` key.
- `SendProactiveMessageJob` claims each row and re-checks every guardrail at send time:
  hotel switch, trigger switch, opt-out, messaging window, guest active in the last 30
  minutes, quiet hours, daily cap, and for pitches the full pitch eligibility.
- It then sends a fixed `lang/{en,ar}/proactive.php` text (no AI), appends it to the guest's
  conversation, and records a `PitchDecision` for pitches.
- Settings live in `hotels.proactive_settings`, off by default. Admins read the log at
  `GET /api/proactive-messages`.

**Opt-out (R14)**: `guests.proactive_opted_out_at`. Exact keywords are handled in code
before the AI runs; free text goes through a Concierge tool; staff use an endpoint. It
blocks proactive messages and unsolicited pitches, but not answers or completion notices.

**Admin AI (R18)**: `GetRecommendationsForReviewTool` and `DecideRecommendationTool` (single
id, confirmation required) in `AdminToolset`.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, `laravel/ai` v0.10.3 (agents, tools, conversation
store, `Approvable` confirmations from 009), Sanctum, Pest 4. No new packages.

**Storage**: PostgreSQL + pgvector. Migrations:
- alter `recommendations` (source, review fields, index, status data migration);
- alter `pitch_decisions` (`is_retry`, `proactive_message_id`);
- alter `guests` (opt-out columns) and `hotels` (`proactive_settings`);
- create `proactive_messages`.

**Testing**: Pest 4 feature tests on real Postgres (`Hospitality_Ecosystem_testing`).
- `Carbon::setTestNow` in hotel timezones; `Http::fake` for WhatsApp sends.
- `GuestConciergeAgent::fake()` / `TurnSignalAgent::fake()` for turns; tools called directly.

**Target Platform**: Linux server: JSON API, queue worker, scheduler (`schedule:run` every
minute).

**Project Type**: Web service (backend only; frontend is `ecosystem-frontend`).

**Performance Goals**:
- Trigger sweep: one indexed query per trigger per hotel, under 1 s for 500 in-house guests.
- Sender: a guardrail check is at most ~10 indexed queries.
- Approval: bulk 100 in under 2 s.

**Constraints**:
- No AI call to send a proactive message.
- No WhatsApp template or email for proactive messages (spec Q1).
- Every counter is derived from rows (existing pitching rule).
- Jobs establish tenant context explicitly.

**Scale/Scope**:
- 4 migrations + 1 table.
- About 6 services or support classes, 3 jobs, 4 new tools and 2 changed tools.
- 5 endpoints new or changed, 2 lang files.
- Docs: reservation-recommendations, recommendation-outcome, hotel, guest, staff-roles,
  ai-advisor, latest-changes.

## Constitution Check

*Gate before Phase 0 and re-checked after Phase 1. Constitution v2.1.0.*

| Principle | How this plan complies | Status |
| --- | --- | --- |
| I. PMS is the system of record | Proactive texts are filled only from PMS records (booking, activity, stay). Candidates come from stored recommendations; no AI judgment at pitch time | ✅ |
| II. Preserve and evolve | Reuses `PitchEligibilityService`, `PitchCoordinator`, `RecommendationDeliveryService`, the outcome service, `AdminToolset`, the notice-job claim pattern and the window rule. Builds the already-designed WP-17 rather than a new design. `pending` is migrated, not dropped silently | ✅ |
| III. Tenant isolation | `proactive_messages` uses `BelongsToHotel`. Jobs run per hotel in `runForHotel`. Bulk ids of other hotels are reported as `not_found`. Isolation tests for queue, log and opt-out | ✅ |
| IV. Permission-based authorization | `recommendations.approve` through `allows()`, not in employee defaults. The Admin AI tool declares it in the guard. The log and settings are admin-only (rule 4). Contact preference uses `guests.update` | ✅ |
| V. AI acts through tools | Pitching only through `PitchActivityTool`, gated in code. The "tailor a recommendation yourself" prompt is removed. Admin approvals go through the guarded tool and the same service | ✅ |
| VI. Auditability | Approve, reject, reset, expire and opt-out are named events. Every trigger evaluation leaves a row with a reason. Pitch decisions record retry and proactive origin | ✅ |
| VII. Data integrity and idempotency | Conditional updates for decisions and delivery. The unique trigger key. Claim-then-send with stale takeover. Keyword opt-out is idempotent | ✅ |
| VIII. Tested at the domain boundary | Service-, tool- and job-level tests, plus permission and isolation datasets | ✅ |
| IX. Spec-driven | Spec → clarify (5 answers) → this plan | ✅ |
| Recommendations: admin approval before pitching; proactive respects caps, rules and audit | FR-013 enforced in `candidates()` and in the tool. Guardrails are re-checked at send time | ✅ |
| WhatsApp and email rules (v2.1.0) | Proactive messages go only inside the window. Never email (it would be promotional), never a template | ✅ |
| AI economics | Sends are metered as a count; no AI cost; no provider cost exposed | ✅ |
| i18n | English and Arabic texts and opt-out keywords | ✅ |

**Post-design re-check**: passes. The one addition beyond the phase text is building WP-17.
It is a prerequisite the spec's own rules depend on, already designed in an approved
document, and recorded as R7.

## Project Structure

### Documentation (this feature)

```text
specs/010-recommendation-approval-proactive/
├── plan.md                                  # This file
├── research.md                              # R1–R19
├── data-model.md                            # Tables, lifecycle, enums, events
├── quickstart.md                            # Validation guide
├── contracts/
│   ├── recommendation-approval-api.md       # approve / reject / bulk / index / edit rule
│   ├── proactive-messaging-api.md           # settings, log, contact preference, keywords
│   └── ai-tools.md                          # Concierge + Admin AI tool contracts
├── checklists/requirements.md
└── tasks.md                                 # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── RecommendationStatus.php             # + pending_approval, approved, rejected_by_admin; − pending
│   ├── RecommendationSource.php             # NEW
│   ├── PitchGate.php                        # − declined_this_stay; + retry_used, retry_window_closed, opted_out
│   ├── PitchOpening.php                     # + proactive
│   ├── ProactiveTrigger.php                 # NEW
│   ├── ProactiveMessageStatus.php           # NEW
│   ├── ProactiveSkipReason.php              # NEW
│   ├── MeterFeature.php                     # + proactive_messages_sent
│   └── Permission.php                       # + recommendations.approve
├── Models/
│   ├── Recommendation.php                   # casts, review relation, logged attributes
│   ├── ProactiveMessage.php                 # NEW (BelongsToHotel, HasUuids, Filterable)
│   ├── PitchDecision.php                    # is_retry, proactive_message_id
│   ├── Guest.php                            # opt-out casts, not fillable
│   └── Hotel.php                            # proactive_settings cast
├── Services/
│   ├── Recommendations/RecommendationApprovalService.php   # NEW (R2, R4)
│   ├── Pitching/PitchEligibilityService.php                # approved pool, retry gates, opted_out, declined_activity
│   ├── Pitching/PitchCoordinator.php                       # complete(turn, reply), abandon(turn)
│   ├── Proactive/ProactiveTriggerFinder.php                # NEW: candidates per trigger (R9, R10)
│   ├── Proactive/ProactiveGuardrails.php                   # NEW: send-time checks → send | defer | skip
│   ├── Proactive/ProactiveMessageRenderer.php              # NEW: lang texts + variants (R12)
│   ├── GuestContactPreferenceService.php                   # NEW (R14)
│   └── RecommendationDeliveryService.php                   # approved → sent
├── Support/
│   ├── WhatsApp/MessagingWindow.php                        # NEW, extracted from SendGuestRequestNoticeJob (R11)
│   ├── Proactive/ProactiveSettings.php                     # NEW value object with defaults (R15)
│   └── Ai/ConversationAppender.php                         # NEW (R13)
├── Ai/
│   ├── Agents/GuestConciergeAgent.php                      # pitch section, proactive-context section, tools
│   ├── Agents/RecommendationAgent.php                      # + source
│   ├── Agents/AdminAdvisorAgent.php                        # instruction line: no bulk approval
│   └── Tools/
│       ├── PitchActivityTool.php                           # NEW (R7)
│       ├── SetContactPreferenceTool.php                    # NEW (R14)
│       ├── GetRecommendationsForReviewTool.php             # NEW (R18)
│       ├── DecideRecommendationTool.php                    # NEW (R18)
│       ├── GetRecommendationsTool.php                      # offered only
│       ├── CreateRecommendationTool.php                    # pending_approval + source
│       └── Admin/AdminToolset.php                          # register the two admin tools
├── Jobs/
│   ├── EvaluateProactiveTriggersJob.php                    # NEW, every 15 min
│   ├── SendProactiveMessageJob.php                         # NEW
│   ├── ExpireRecommendationsJob.php                        # NEW, hourly
│   ├── ProcessInboundWhatsAppMessageJob.php                # keyword opt-out; complete/abandon after send
│   ├── SendGuestRequestNoticeJob.php                       # uses MessagingWindow
│   └── GenerateActivityRecommendationsJob.php              # source = staff_request
├── Http/
│   ├── Controllers/RecommendationController.php            # approve, reject, decide; index filters; edit rule
│   ├── Controllers/ProactiveMessageController.php          # NEW: index, show
│   ├── Controllers/GuestContactPreferenceController.php    # NEW
│   ├── Controllers/HotelController.php                     # proactive_settings
│   ├── Requests/{RejectRecommendation,DecideRecommendations,UpdateContactPreference}Request.php   # NEW
│   ├── Requests/UpdateHotelRequest.php (or equivalent)     # nested proactive_settings rules
│   └── Resources/{Recommendation,Guest,Hotel}Resource.php, ProactiveMessageResource.php
└── Policies/
    ├── RecommendationPolicy.php                            # approve()
    └── ProactiveMessagePolicy.php                          # NEW, admin-only

config/proactive.php                                        # keywords, sweep interval, attempts
lang/{en,ar}/proactive.php                                  # texts + confirmations
routes/api.php, routes/console.php
database/migrations/2026_10_10_00000{1..5}_*.php

tests/Feature/
├── RecommendationApprovalTest.php           # transitions, races, bulk, edit reset, expiry, audit
├── PitchFromApprovedPoolTest.php            # candidates, inline generation pending, guest tool filter, prompt
├── PitchActivityToolTest.php                # staging, refusals, complete/abandon, PITCHED
├── DeclineRetryTest.php                     # D10 rules, multi-room, explicit requests
├── ProactiveTriggersTest.php                # each trigger; dedupe; timing; settings
├── ProactiveGuardrailsTest.php              # quiet hours, cap, window, active guest, eligibility
├── ProactiveOptOutTest.php                  # keywords, tool, endpoint, scope of block
├── ProactiveMessageLogTest.php              # admin-only, filters, isolation
├── AdminRecommendationToolsTest.php         # permission, confirmation, single id, audit
├── PermissionAuthorizationTest.php          # + approve endpoints
├── TenantIsolationTest.php                  # + queue, log, opt-out
└── (existing pitching/recommendation tests updated for the new statuses)
```

**Structure Decision**: The existing single Laravel backend. Approval logic goes beside the
other recommendation services, pitching stays in `app/Services/Pitching`, and proactive
logic gets its own `app/Services/Proactive` folder.

The frontend slice is tracked in `tasks.md` for `ecosystem-frontend`:
- approval queue with bulk actions and status filters;
- proactive settings on the hotel page;
- proactive message log;
- opt-out badge and toggle on the guest page.

## Delivery order

Each step leaves the suite green.

1. **Statuses and approval** (US1): migration + enum, `RecommendationApprovalService`,
   permission, endpoints, edit rule, source, expiry job. Update `CreateRecommendationTool`,
   `RecommendationDeliveryService` and existing tests for `approved`.
2. **Approved pool and pitch tool** (US2): `candidates()` on `approved`; guest tool filter;
   `PitchActivityTool`; `complete()`/`abandon()` moved after the send; prompt rewrite.
3. **Decline retry** (US3): gates, `is_retry`, declined-activity exclusion, rules 2.0.
4. **Opt-out** (US5): columns, service, keyword path, Concierge tool, staff endpoint,
   `OPTED_OUT` gate.
5. **Proactive** (US4): settings, `MessagingWindow` extraction, table, finder, guardrails,
   renderer, jobs, conversation append, proactive `PitchDecision`, metering, log endpoint.
6. **Queue ergonomics and Admin AI** (US6): index filters and sorts, the two admin tools.
7. **Docs**: API docs, permission reference, latest-changes (breaking: status values, edit
   rule). Then the quickstart walkthrough.

## Complexity Tracking

No constitution violations. Deliberate choices recorded for review:

| Choice | Why needed | Simpler alternative rejected because |
| --- | --- | --- |
| Build WP-17 pitch tool here | Retry, caps and FR-015 are derived from `PITCHED` decisions, which nothing writes today | Prompt-only rules are what let the Concierge offer unapproved, self-invented suggestions now |
| `proactive_messages` table + sweep instead of delayed jobs | Unique key gives dedupe; send-time re-check handles deferrals, cancellations and opt-outs | Delayed jobs lose messages silently and need rescheduling on every deferral or settings change |
| Instructions section for recent proactive messages | `RemembersWholeTurns` drops a leading assistant message, so the Concierge would not see its own proactive message | Changing the trait would alter history for every turn and every agent |
