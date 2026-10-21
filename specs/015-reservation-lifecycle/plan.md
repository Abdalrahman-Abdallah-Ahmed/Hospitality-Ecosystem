# Implementation Plan: Reservation Lifecycle and No-Show

**Branch**: `015-reservation-lifecycle` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/015-reservation-lifecycle/spec.md`. SPEC-012
from Phase 2 of the master plan. It answers the plan's open question ("exact status
transitions, and who may trigger `no_show`") and unblocks Phase 13.

**Depends on** (all on `main`): SPEC-010 reservation rooms, SPEC-023–025 stays and
check-in/out, SPEC-021 assignment rules, SPEC-011 guest lists (`014`, branch pushed).

## Summary

- **Lifecycle table (R1)**: a new `ReservationLifecycle` holds spec FR-001 as data.
  `ReservationCreator::update()` enforces it for every edit-path status change (staff API,
  `UpdateReservationTool`, `CancelReservationTool`). Check-in/out keep their own services;
  the import bypasses the table.
- **`no_show` (R2, R3)**: new `ReservationStatus::NO_SHOW`. A new
  `reservations.status_before_no_show` column remembers what to restore on undo.
- **No-show service (R4–R6)**: a new `NoShowService` marks or undoes a whole reservation
  or one stay.
  - It sets stays to `no_show` using the existing `StayService::markNoShow()`.
  - It rolls the reservation up to `no_show` when every live stay is one.
  - It locks in the same order as `StayLifecycleService` (shared `ReservationLock`).
  - Undo re-checks availability with the existing overbook override.
- **Most effects need no change**: availability, arrivals, pitching, proactive guardrails
  and the last-check-out rule already read stay status. Two queries do need changes:
  - `RoomAssignmentRules` must skip lines whose stay is `no_show`.
  - The two recommendation jobs must read `reservations.status = no_show` (R8).
- **Edit lock (R7)**: while a reservation is `no_show`, its dates, party, rooms and status
  can't be edited. Only the guest list and special requests still can (an allow-list).
- **API**: four `POST` endpoints, with an optional `reason`
  ([contract](contracts/no-show-api.md)).
- **Permission**: new `reservations.no_show`. Admins hold it; employees need it from their
  staff role.
- **Admin AI**: one `NoShowTool` (mark/undo, whole or one room). The Concierge doesn't get
  it.
- **Import (R9)**: a reservation recorded as `no_show` marks its stays no-show.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.

**Primary Dependencies**: Eloquent, Sanctum, `laravel/ai` tools, Pest 4. No new packages.

**Storage**: PostgreSQL. One migration: add nullable `reservations.status_before_no_show`.

**Testing**: Pest feature tests on real Postgres, including:
- a transition matrix over every from/to pair, through the API and the AI tool (SC-001);
- permission dataset rows and tenant-isolation cases.

**Target Platform**: Linux server: JSON API, queue worker, scheduler.

**Project Type**: Multi-tenant Laravel web service (backend only; frontend in `ecosystem-frontend`).

**Performance Goals**: A no-show or undo is one transaction over one reservation's rows
(at most `ReservationRoomSync::MAX_UNITS` stays). It adds no query to availability or the
arrivals list.

**Constraints**:
- Tenant scope on every lookup.
- Lock order reservation → lines/stays → rooms.
- No ledger writes, no guest notifications, no automatic no-shows (FR-011a).

**Scale/Scope**:
- 1 migration and 2 enum cases (`ReservationStatus::NO_SHOW`, `Permission::RESERVATIONS_NO_SHOW`).
- New code:
  - classes: `ReservationLifecycle`, `ReservationLock`, `NoShowService`;
  - `NoShowRequest`; actions on `ReservationController` / `StayController` (see tasks.md "Controller consolidation");
  - `NoShowTool`.
- About 10 classes touched; 4 endpoints.

## Constitution Check

*Gate before Phase 0 and re-checked after Phase 1. Constitution v2.1.0.*

| Principle | How this plan complies | Status |
| --- | --- | --- |
| I. PMS is the system of record | No-show is structured status on reservations and stays; the AI only acts through a tool | ✅ |
| II. Preserve and evolve | Reuses `StayService::markNoShow()`, `AvailabilityService::guard()`, `CheckInOutException`, the stay-based availability and arrivals queries. The built check-in rule (confirmed only) is kept, and the spec was corrected to it (R11). No endpoint removed | ✅ |
| III. Tenant isolation | Route-model binding plus the same-hotel policy check; the service locks by id within the reservation's hotel; isolation tests for the four endpoints and the AI tool | ✅ |
| IV. Permission-based authorization | New `reservations.no_show` case through `ChecksPermissions::allows()`. It isn't in `employeeDefaults()`. Undo with an overbook needs `reservations.overbook` too. It's added to the permission docs and the authorization dataset | ✅ |
| V. AI acts through tools | `NoShowTool` calls `NoShowService`, guarded by the same permission; the Concierge has no access | ✅ |
| VI. Auditability and observability | Model events record each status and stay change with its actor, including the AI actor. Explicit `no_show_marked` / `no_show_undone` events record the reason | ✅ |
| VII. Integrity and idempotency | One transaction per action with fixed lock order. Repeating a done action is a no-op. Undo re-checks availability | ✅ |
| VIII. Tested at the domain boundary | Service tests, the transition matrix, endpoint, AI tool, permission and isolation tests | ✅ |
| IX. Spec-driven | Spec → 2 + 4 clarifications → this plan | ✅ |

**Post-design re-check**: passes. The only deviation from the first spec draft is R11,
which aligns it with the built check-in rule.

## Project Structure

### Documentation (this feature)

```text
specs/015-reservation-lifecycle/
├── plan.md                 # This file
├── research.md             # R1–R11
├── data-model.md           # column, lifecycle table, stay transitions, edit lock
├── quickstart.md           # validation scenarios
├── contracts/
│   └── no-show-api.md      # 4 endpoints, PUT changes, AI tool
├── checklists/requirements.md
└── tasks.md                # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── ReservationStatus.php           # + NO_SHOW
│   └── Permission.php                  # + RESERVATIONS_NO_SHOW
├── Support/Reservations/
│   ├── ReservationLifecycle.php        # NEW: transition table, assertEdit()
│   ├── ReservationLock.php             # NEW: shared lock order (from StayLifecycleService)
│   ├── ReservationCreator.php          # enforce table on edit; no_show edit lock; import syncStays arm
│   └── RoomAssignmentRules.php         # skip no_show / departed stays in clash check
├── Services/
│   ├── NoShowService.php               # NEW: mark/undo, whole or stay
│   └── StayLifecycleService.php        # use ReservationLock
├── Http/
│   ├── Controllers/ReservationController.php # + checkIn/checkOut/noShow/undoNoShow (whole reservation)
│   ├── Controllers/StayController.php      # + checkIn/checkOut/noShow/undoNoShow (one room)
│   ├── Controllers/Concerns/HandlesFrontDeskActions.php # shared responses + overbook check
│   ├── Requests/NoShowRequest.php          # NEW: reason, overbook_override
│   └── Resources/ReservationResource.php   # lines gain stay_status
├── Policies/ReservationPolicy.php      # + noShow()
├── Ai/Tools/
│   ├── NoShowTool.php                  # NEW
│   └── Admin/AdminToolset.php          # register NoShowTool
├── Jobs/
│   ├── ExpireRecommendationsJob.php    # + reservation status no_show
│   └── MatchRecommendationOutcomesJob.php  # + reservation status no_show
└── Models/Reservation.php              # status_before_no_show fillable

database/migrations/
└── 2026_10_21_000001_add_status_before_no_show_to_reservations_table.php

routes/api.php                          # 4 routes next to check-in/out

tests/Feature/
├── NoShowTest.php                      # NEW: service + endpoints (US1, US2, US4)
├── ReservationLifecycleTest.php        # NEW: transition matrix, edit lock (US3)
├── NoShowToolTest.php                  # NEW: Admin AI (US5)
├── PermissionAuthorizationTest.php     # + 4 endpoints
├── TenantIsolationTest.php             # + no-show endpoints and tool
├── ExpireRecommendationsJobTest.php     # + no_show reservation expires its recommendations
└── RecommendationOutcomeTest.php       # + outcome matching reads reservation no_show
                                        # (no-show line frees its room: covered in NoShowTest)
```

**Structure Decision**: the existing Laravel monolith layout. `NoShowService` sits next to
`StayLifecycleService` in `app/Services`, and the lifecycle table next to
`ReservationCreator`, which owns the reservation write path.

### Documentation to update

- `docs/reservations-api-documentation.md`: the lifecycle table, `no_show`, the four
  endpoints, the edit lock and `stay_status` on lines.
- `docs/stays-api-documentation.md`: the room no-show endpoints, and a note on FR-012a
  (no-show rooms no longer block check-out).
- `docs/staff-roles-api-documentation.md`: `reservations.no_show` in the permission
  reference.
- `docs/latest-changes-2026-10-21.md`: there is no breaking change for valid clients. The
  newly rejected status edits are listed.
- `docs/frontend-changes-2026-10-21.md` (append) and
  `specs/015-reservation-lifecycle/frontend-changes.md`:
  - the no-show and undo actions, with a reason;
  - the status badge and filter;
  - the room no-show on the stays screen;
  - the edit lock messages.

## Complexity Tracking

No constitution violations; nothing to justify.
