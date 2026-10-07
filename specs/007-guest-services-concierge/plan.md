# Implementation Plan: Guest Services and Concierge on the New Domain

**Branch**: `007-guest-services-concierge` | **Date**: 2026-10-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/007-guest-services-concierge/spec.md`. It is
Phase 7 of the master plan: SPEC-044 (Guest Service Requests), SPEC-052 (Concierge Read
Tools), SPEC-053 (Concierge Action Tools) and SPEC-054 (Human Escalation).

**Depends on**: `006-activity-bookings` merged first (R1).

## Summary

**Requests and routing (SPEC-044)**:

- Guest requests stay `Task` rows. Two new `GuestSignal` cases (`maintenance_request`,
  `room_change_request`) make their kind explicit (R2).
- Maintenance requests always go to the hotel's Maintenance category and team (R3).
  Housekeeping and "other" requests keep the model's category choice, validated against
  the hotel.
- The Concierge checks the guest's open requests and can add to one instead of filing a
  duplicate (R14).

**Closing the loop (SPEC-044)**:

- A `Task` `updated` hook queues `SendGuestRequestNoticeJob` when a guest request closes
  (R7). It covers every path that closes one: the task API, cancellation approve or
  decline, housekeeping and maintenance.
- The job claims the task once (R8). It sends WhatsApp inside the 24-hour window,
  measured per phone number on the shared line, and email outside it (R9, R10).
- It records `sent`, `skipped` or `failed` on the task. Messages are fixed English or
  Arabic texts (R11).
- Cancelled requests and escalations send nothing. Cancellation-request decisions send
  the outcome (FR-010c).

**Escalation (SPEC-054)**: a new `GuestRequestService::escalate()` keeps one open
escalation per guest. A partial unique index enforces it, and a repeat ask appends to the
open one (R5). Pitching stays blocked by the existing gate.

**Read tools (SPEC-052)**:

- `GetOwnReservationTool` gains `is_active` and per-room stay status.
- New `GetOwnBookingsTool`, with prices (Q7), and `GetOwnRequestsTool`, guest-facing
  only (R13).

**Action tools (SPEC-053)**:

- `CreateGuestServiceRequestTool` gains `kind = maintenance_request` (routed to Maintenance by the server), and a new `RequestRoomChangeTool`. A room change is a task
  with no team, an admin email and at most one open per stay (R4).
- Service, maintenance and room change need an active reservation; escalation does not
  (R6).
- An architecture test pins the tool allowlist and forbids room, stay and booking-status
  services (R15).

**Identity**: unknown senders are marked `ignored`, with no job and no reply (R12).
De-duplication, throttling and pairing run first, unchanged.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, `laravel/ai` (agents and tools), Sanctum, Pest 4
(including `arch()`)

**Storage**: PostgreSQL. One migration on `tasks`:

- 4 notice columns and 3 check constraints;
- 2 partial unique indexes (open escalation per guest, open room change per stay or
  reservation);
- a data step that merges duplicate open escalations and marks already-closed guest
  tasks `skipped`.

**Testing**: Pest feature tests on real Postgres (`Hospitality_Ecosystem_testing`),
parallel-safe. `Http::fake` stands in for the Graph API; `Notification::fake` and
`Queue::fake` handle the notice job.

**Target Platform**: Laravel JSON API and queue workers. No frontend slice is required
(D13; the master plan says "none beyond task views").

**Project Type**: Multi-tenant hospitality PMS backend (web service)

**Performance Goals**:

- The notice is sent within 2 minutes of completion for 95% of requests inside the
  window (SC-002). It is queued after commit, and the window check is one indexed query
  on `(phone_number, created_at)`.
- Each read tool runs a fixed number of queries (at most 6), however many rooms, bookings or requests there are.
- An unknown sender costs one recognition lookup and no queue job.

**Constraints**:

- **Tenant isolation**: the tools name `hotel_id` and `guest_id` explicitly. The job loads
  the task by id, then runs inside `TenantContext::runForHotel($task->hotel_id)`.
- **No WhatsApp outside the window** (SC-003). No template messages (Q2). Email only for
  notices WhatsApp forbids (constitution v2.1.0).
- **Notices never affect task status** (FR-013).
- **At most one notice per task** (FR-012).
- **The guest AI cannot cancel, change rooms or change booking status.** This is
  enforced in code (FR-031).
- **No ledger writes** (D11).

**Scale/Scope**: Tens to hundreds of guest requests a day per hotel. The work is 1
migration, about 11 new classes (3 tools, 1 service, 1 job, 1 notification, 3 enums, 2
translation files) and about 12 changed classes.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle / rule | Status | How |
| --- | --- | --- |
| I. PMS is system of record | ✅ | Reservation, stay, booking, request and availability answers come from structured rows through tools. RAG is used only for hotel information and policy (FR-022, FR-023) |
| II. Preserve and evolve | ✅ | Reuses `Task`, `GuestSignal`, `CreationNotificationService`, `WhatsAppMessageService`, `PitchEligibilityService`, Phase 6 cancellation and availability, and the existing tools. Changed tools keep their names. The only removal is the unknown-sender reply, which the user decided (Q3) and which is announced as a breaking change |
| III. Tenant isolation | ✅ | Tools are constructed with the resolved hotel and guest and filter on both. The job uses the task's hotel. The inbound row for an unknown sender has no hotel. `TenantIsolationTest` is extended for the new task fields and filters |
| IV. Permission-based auth | ✅ | No new endpoints. Staff act on requests through the existing `tasks.*` and `bookings.update_status` policies. The guest AI has no staff permissions; what it can do is limited to its tool set |
| V. AI through tools | ✅ | Every guest action goes through a tool, then a domain service or model, inside `EventLogger::asAiAgent`, with validation in the tool. Notice texts are fixed, not AI-written. The arch test pins the allowlist |
| VI. Auditability | ✅ | `RecordsEvents` on the new task fields, plus `room_change_requested`, `escalation_repeated`, `request_detail_added` and `guest_notified` events (R16) |
| VII. Integrity and idempotency | ✅ | Partial unique indexes plus a `23505` re-read for escalations and room changes. An atomic claim for notices. Dispatched after commit. The existing at-most-once turn generation is untouched |
| VIII. Tested at domain boundary | ✅ | 8 new test files and 3 extended ([quickstart](quickstart.md)). The AI is tested at the tool boundary, and notices at the job boundary with faked transports |
| IX. Spec-driven | ✅ | Spec plus 7 clarifications → this plan |
| Concierge never cancels or changes rooms | ✅ | Request-only tools; enforced by the arch test (FR-031) |
| WhatsApp: shared number, D12 resolution order | ✅ | Unchanged. The window is computed per phone number because the number is shared (R9) |
| WhatsApp: guests contacted through their number (v2.1.0) | ✅ | WhatsApp whenever its rules allow; email only for transactional notices it forbids, as the amended constitution permits (R10) |
| Jobs establish tenant context explicitly | ✅ | `SendGuestRequestNoticeJob` loads the task by id, then runs inside `TenantContext::runForHotel()` (R8) |
| MVP: no ledger dependency | ✅ | Nothing writes transactions |
| Rule: incrementally deployable | ⚠️ justified | Unknown senders stop getting a reply, and `guest_signal` gains two values. See Complexity Tracking |

**Post-design re-check**: ✅. The one item marked ⚠️ is justified below. No unresolved
clarifications remain: research R1–R20 resolves every open technical question.

## Project Structure

### Documentation (this feature)

```text
specs/007-guest-services-concierge/
├── spec.md
├── plan.md                              # this file
├── research.md                          # decisions R1–R20
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── concierge-tools.md               # tool inputs, behavior, results
│   └── api-and-messaging.md             # task API fields, notice texts, WhatsApp inbound
├── checklists/requirements.md
└── tasks.md                             # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Ai/
│   ├── Agents/GuestConciergeAgent.php            # tool set + instructions (R15, R19)
│   └── Tools/
│       ├── GetOwnReservationTool.php             # + is_active, per-room stay_status (R13)
│       ├── GetOwnBookingsTool.php                # NEW (R13)
│       ├── GetOwnRequestsTool.php                # NEW (R13)
│       ├── CreateGuestServiceRequestTool.php     # + maintenance kind, active check, add_to_request_id (R3, R6, R14)
│       ├── RequestRoomChangeTool.php             # NEW (R4)
│       └── EscalateToHumanTool.php               # delegates to GuestRequestService (R5)
├── Enums/
│   ├── GuestSignal.php                           # + MAINTENANCE_REQUEST, ROOM_CHANGE_REQUEST, helpers (R2)
│   ├── GuestNoticeStatus.php                     # NEW
│   ├── GuestNoticeChannel.php                    # NEW
│   ├── GuestNoticeReason.php                     # NEW
│   └── InboundMessageStatus.php                  # + IGNORED (R12)
├── Http/
│   ├── Controllers/WhatsAppController.php        # unknown sender → ignored (R12)
│   └── Resources/TaskResource.php                # + guest_notice_* (R18)
├── Jobs/
│   ├── ProcessInboundWhatsAppMessageJob.php      # remove UNKNOWN_SENDER_REPLY branch (R12)
│   └── SendGuestRequestNoticeJob.php             # NEW (R8–R11)
├── Models/
│   ├── Task.php                                  # casts, scopes, updated hook (R7)
│   └── Reservation.php                           # + isActive() (R6)
├── Notifications/GuestRequestNoticeNotification.php   # NEW, mail (R10)
├── Services/
│   ├── GuestRequestService.php                   # NEW: escalate, requestRoomChange, appendDetail (R4, R5, R14)
│   └── Pitching/PitchEligibilityService.php      # complaint kinds in service-request gate (R17)
database/migrations/
└── 2026_10_06_000001_add_guest_request_fields_to_tasks_table.php   # NEW
lang/
├── en/guest_notices.php                          # NEW (R11)
└── ar/guest_notices.php                          # NEW (R11)
tests/
├── Arch/ConciergeToolsArchTest.php               # NEW
└── Feature/
    ├── GuestRequestRoutingTest.php               # NEW
    ├── GuestRequestNoticeTest.php                # NEW
    ├── CancellationOutcomeNoticeTest.php         # NEW
    ├── GuestEscalationTest.php                   # NEW
    ├── ConciergeReadToolsTest.php                # NEW
    ├── RoomChangeRequestTest.php                 # NEW
    ├── ConciergeGuestRestrictionsTest.php        # NEW
    ├── WhatsAppUnknownSenderTest.php             # NEW (or extend webhook test)
    ├── TenantIsolationTest.php                   # extended
    └── PitchEligibilityTest.php                  # extended
docs/
├── task-management-api-documentation.md          # new fields, signals, filters
├── whatsapp-device-api-documentation.md          # unknown senders ignored
├── booking-entity-documentation.md               # guest told cancellation outcome
└── latest-changes-<date>.md                      # breaking: unknown senders, new guest_signal values
```

**Structure Decision**: The existing single Laravel application, in the layers CLAUDE.md
sets out:

- tools in `app/Ai/Tools`;
- shared business logic in `app/Services` (`GuestRequestService` has three callers);
- queued work in `app/Jobs`;
- enums for every new status or channel column.

`tests/Arch` is new. If Pest's `arch()` cannot express the dependency rule, the test
moves into `tests/Feature`.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Behavior change: unknown WhatsApp senders get no reply | The user decided it (Q3). It removes a reply that told any number the platform is a hotel line, and spends nothing on unrecognised senders | Keeping the fixed reply contradicts the clarified spec. An information-only assistant has no hotel to answer for on a shared number |
| Model `updated` hook on `Task` to queue notices (CreationNotificationService uses explicit calls) | At least five code paths close guest requests; missing one means a guest is never told (FR-007) | Explicit calls in every path are easy to miss in future code. A polling sweep adds latency against SC-002 |
| `guest_signal` gains two values that existing clients may not expect | Maintenance and room change must be distinct for routing, pitching and notices (R2) | Overloading `service_request` makes the kind depend on an editable, optional category |
