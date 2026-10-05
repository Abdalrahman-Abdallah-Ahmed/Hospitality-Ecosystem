# Implementation Plan: Housekeeping and Maintenance

**Branch**: `005-housekeeping-maintenance` | **Date**: 2026-10-04 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/005-housekeeping-maintenance/spec.md`. It is
Phase 5 (SPEC-030 Housekeeping Lifecycle, SPEC-033 Maintenance & Out-of-Order, SPEC-035
Automatic Maintenance Requests), plus the unshipped Phase 1 parts it needs: SPEC-003
(room status split) and SPEC-004 (default teams and categories).

## Summary

**Foundation (SPEC-003, SPEC-004)**:

- **Status enums**: `RoomStatusesEnum` becomes `available` / `occupied` / `out_of_order`.
  `HousekeepingStatusesEnum` becomes `dirty` / `cleaning` / `clean` / `inspected`. One
  migration rewrites the data and the Postgres check constraints, and reports rooms it
  could not map.
- **Room edit**: room and housekeeping status are no longer writable through the generic
  room edit (breaking).
- **Default teams**: every hotel gets Housekeeping and Maintenance teams with Cleaning,
  Inspection and Maintenance categories. `HotelOperationalDefaults::ensure()` is
  idempotent and runs on hotel creation and once for existing hotels.

**Housekeeping (SPEC-030)**: a new `HousekeepingService` is the only writer of a room's
housekeeping status.

- Tasks carry a stored `housekeeping_kind` (cleaning / inspection), set from the hotel's
  categories. A partial unique index allows one open task of each kind per room.
- Starting, completing, cancelling and reopening those tasks moves the room.
- Inspection is a per-hotel flag. Its pass/fail action either marks the room inspected or
  creates a re-clean task.
- Check-out and a new hourly, per-time-zone, idempotent start-of-day job create the
  cleaning tasks. The start-of-day job replaces `MakeRoomDirtyOvernightJob`.

**Maintenance (SPEC-033, SPEC-035)**: a new `MaintenanceService` handles these actions:

- **Out of order**: take a room out of order (reason, optional expected end date and
  task). It is refused for occupied rooms and returns the future lines the room is
  assigned to.
- **Return to service**: available + dirty + a cleaning task.
- **Issue report**: a report on a housekeeping task creates a maintenance task for the
  Maintenance team, and can take the room out of order in the same transaction.
- **Maintenance task completion**: completing the task never reopens the room.

**Notifications**: a receipts table means each person is told once per task assignment.
Housekeeping and Maintenance teams get team fan-out. Admins get at most one routing alert
per hotel per day.

**Other**: a housekeeping board, a maintenance list, two new permissions, audit events
with cause and actor, and docs. Decisions R1–R20 are in [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, Sanctum, `laravel/ai` (existing tools only), Pest 4

**Storage**: PostgreSQL.
- Check constraints on the status columns.
- A partial unique index on open housekeeping tasks.
- `SELECT … FOR UPDATE` row locks, in the order room → task (the same order as
  `StayLifecycleService`).

**Testing**: Pest feature tests against real Postgres (`Hospitality_Ecosystem_testing`).
Concurrency tests use `DatabaseTruncation` and a second connection, like
`CheckInOutConcurrencyTest`.

**Target Platform**: Laravel JSON API. The frontend slice is in `ecosystem-frontend` (D13):
- housekeeping board;
- maintenance list;
- task actions (start, complete, inspect, report issue);
- out-of-order and return-to-service dialogs;
- inspection and default-team settings.

**Project Type**: Multi-tenant hospitality PMS backend (web service)

**Performance Goals**: The board loads for a 500-room hotel in under 2 s (SC-008), with a
fixed number of queries (3). The start-of-day job finishes one 500-room hotel in a single
transaction in a few seconds.

**Constraints**:
- Tenant isolation everywhere: jobs use `runForHotel`, migrations use `withoutScope`.
- Multi-record actions are all-or-nothing.
- Repeated requests and job runs are idempotent.
- Notifications go out only after commit.
- No ledger writes (D11).
- No new AI tools (Phase 9).

**Scale/Scope**: About 500 rooms per hotel. The work adds 5 migrations, about 14 new
classes and about 18 changed ones.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle / rule | Status | How |
| --- | --- | --- |
| I. PMS is system of record | ✅ | Room status, housekeeping status and out-of-order state are structured columns. The AI reads them only through `GetRoomsTool` |
| II. Preserve and evolve | ✅ | The status enums change in place (R1). Reused: `HousekeepingDefaults`, `CreationNotificationService`, `TaskAssignedNotification`, the `auditExtras` pattern, `syncRoomStatus`, `GenericQuery`. The overnight job is replaced by one that keeps its rules (R7). No existing endpoint is removed. The breaking room-edit change is justified in R2 and announced |
| III. Tenant isolation | ✅ | New routes bind under the hotel scope. `task_id` and the hotel settings are checked with `invalidRelation`. Jobs and migrations set the context explicitly. Isolation tests cover the board, list, actions and job |
| IV. Permission-based auth | ✅ | `rooms.update_housekeeping_status` and `rooms.set_out_of_order` are checked through `RoomPolicy::allows()` and are not employee defaults. Inspection and issue reporting reuse `TaskPolicy::update`. The settings are admin-only hotel fields |
| V. AI through tools | ✅ | The AI task tools go through the same `HousekeepingService` and notification hooks (R17). No new AI write paths |
| VI. Auditability | ✅ | Named room events carry the cause, task and actor (R13). The new task fields are logged. Migration conflicts are audited (R19) |
| VII. Integrity and idempotency | ✅ | One transaction per action, fixed lock order, re-read after locking. The unique index enforces one open task of each kind per room. Run rows make the job idempotent. Receipts de-duplicate notifications. Issue reports are de-duplicated on a natural key |
| VIII. Tested at domain boundary | ✅ | 11 new test files, plus additions to the CheckOut, Availability, TenantIsolation and Permission tests (see quickstart) |
| IX. Spec-driven | ✅ | Spec plus 3 clarifications → this plan |
| Rule: separate room status and housekeeping status | ✅ | Two enums and two columns, never merged |
| Rule: housekeeping MVP uses Tasks | ✅ | Cleaning, inspection and maintenance are all tasks. The issue report is a task, not a new entity |
| Rule: maintenance lifecycle (issue → task → assignment → work → completion) | ✅ | US4 and US5 |
| i18n: no language-specific logic | ✅ | Defaults are found by setting id. Names are looked up only once, at provisioning, to reuse an existing team (R8) |
| Rule: incrementally deployable | ⚠️ justified | The room edit stops accepting `status` and `housekeeping_status` (R2). See Complexity Tracking |

**Post-design re-check**: ✅. The one item marked ⚠️ is justified below.

## Project Structure

### Documentation (this feature)

```text
specs/005-housekeeping-maintenance/
├── spec.md
├── plan.md                                    # this file
├── research.md                                # decisions R1–R20
├── data-model.md
├── quickstart.md
├── contracts/housekeeping-maintenance-api.md
├── checklists/requirements.md
└── tasks.md                                   # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── RoomStatusesEnum.php                   # MAINTENANCE → OUT_OF_ORDER; outOfOrder() (R1)
│   ├── HousekeepingStatusesEnum.php           # dirty/cleaning/clean/inspected (R1)
│   ├── HousekeepingKind.php                   # NEW (R4)
│   ├── CleaningReason.php                     # NEW (R4)
│   ├── InspectionResult.php                   # NEW (R6)
│   ├── HousekeepingCause.php                  # NEW (R13)
│   └── Permission.php                         # + ROOMS_UPDATE_HOUSEKEEPING_STATUS, ROOMS_SET_OUT_OF_ORDER (R15)
├── Models/
│   ├── Room.php                               # status cast, out_of_order fields, auditExtras + eventVerbFor (R13), isOutOfOrder()
│   ├── Hotel.php                              # new settings; created → HotelOperationalDefaults::ensure (R8)
│   ├── Task.php                               # new fields + casts + logged attrs, sourceTask()
│   ├── TaskNotificationReceipt.php            # NEW (R12)
│   └── HousekeepingDayRun.php                 # NEW (R7)
├── Services/
│   ├── HousekeepingService.php                # NEW (R3–R7, R16)
│   ├── MaintenanceService.php                 # NEW (R10, R11)
│   ├── CreationNotificationService.php        # recipients, receipts, taskReassigned, routingProblem (R12)
│   ├── StayLifecycleService.php               # check-out → roomVacated; check-in warning → isReady (R16)
│   └── AvailabilityService.php                # unchanged logic; follows outOfOrder()
├── Support/
│   ├── Housekeeping/HousekeepingDefaults.php  # returns the five defaults (R8)
│   ├── Housekeeping/HotelOperationalDefaults.php  # NEW: ensure() (R8)
│   └── Reservations/ReservationCreator.php    # release path → HousekeepingService; enum comparisons; out_of_order guard (R10, R16)
├── Jobs/
│   ├── StartHousekeepingDayJob.php            # NEW (R7)
│   └── MakeRoomDirtyOvernightJob.php          # DELETED (replaced; HousekeepingOvernightTest is rewritten as StartOfDay)
├── Notifications/
│   ├── TaskAssignedNotification.php           # shouldSend(): skip closed tasks (R12)
│   └── HousekeepingRoutingNotification.php    # NEW: admin alert (FR-029)
├── Policies/RoomPolicy.php                    # + updateHousekeepingStatus, setOutOfOrder
├── Http/
│   ├── Controllers/RoomController.php         # reject status fields (R2); building
│   ├── Controllers/RoomHousekeepingController.php   # NEW: PUT housekeeping-status
│   ├── Controllers/RoomOutOfOrderController.php     # NEW: store, update, returnToService
│   ├── Controllers/TaskController.php         # team from category (R9); HousekeepingService hooks; reassignment notice; inspection guard
│   ├── Controllers/TaskInspectionController.php     # NEW
│   ├── Controllers/TaskIssueController.php    # NEW
│   ├── Controllers/HousekeepingBoardController.php  # NEW (R14)
│   ├── Controllers/MaintenanceTaskController.php    # NEW (R14)
│   ├── Controllers/HotelController.php        # validate the new settings (R8)
│   ├── Requests/UpdateHousekeepingStatusRequest.php # NEW
│   ├── Requests/OutOfOrderRequest.php         # NEW (store + update rules)
│   ├── Requests/ReturnToServiceRequest.php    # NEW
│   ├── Requests/InspectionRequest.php         # NEW
│   ├── Requests/ReportIssueRequest.php        # NEW
│   ├── Requests/HousekeepingBoardRequest.php  # NEW
│   └── Resources/{Room,Task,Hotel}Resource.php      # new fields
├── Ai/Tools/
│   ├── CreateRoomTool.php                     # drop status (R2)
│   ├── GetRoomsTool.php                       # new values, ready, out_of_order
│   ├── CreateTaskTool.php                     # team from category; HousekeepingService::taskCreated
│   ├── CreateGuestServiceRequestTool.php      # same; reuse an open cleaning task
│   └── EscalateToHumanTool.php                # notification path only

routes/api.php                                 # 8 new routes (contract)
routes/console.php                             # StartHousekeepingDayJob hourly; remove the overnight entry

database/migrations/
├── 2026_10_04_000001_add_out_of_order_fields_to_rooms_table.php
├── 2026_10_04_000002_split_room_statuses.php
├── 2026_10_04_000003_add_operational_defaults_to_hotels_table.php
├── 2026_10_04_000004_add_housekeeping_fields_to_tasks_table.php
└── 2026_10_04_000005_provision_operational_defaults.php
database/factories/RoomFactory.php             # status values unchanged (available/clean); add states outOfOrder(), dirty()
database/seeders/                              # any seeded maintenance/blocked values → new values

tests/Feature/
├── RoomStatusSplitTest.php                    # NEW
├── OperationalDefaultsTest.php                # NEW
├── HousekeepingLifecycleTest.php              # NEW
├── HousekeepingInspectionTest.php             # NEW
├── HousekeepingStartOfDayTest.php             # NEW (replaces HousekeepingOvernightTest)
├── OutOfOrderTest.php                         # NEW
├── RoomIssueReportTest.php                    # NEW
├── TaskNotificationTest.php                   # NEW
├── HousekeepingBoardTest.php                  # NEW
├── HousekeepingManualStatusTest.php           # NEW
├── HousekeepingConcurrencyTest.php            # NEW
├── RoomControllerTest.php                     # status fields rejected; building
├── TaskControllerTest.php                     # team from category; duplicate cleaning task 422
├── CheckInTest.php / CheckOutTest.php / MultiRoomCheckInOutTest.php   # new enum values, readiness warning, stay-over reuse
├── AvailabilityServiceTest.php / AvailabilityControllerTest.php / AvailabilityToolsTest.php   # out_of_order values
├── ReservationRoomOccupancyTest.php / StayListTest.php / ReservationStatusDeprecationTest.php # enum values
├── HotelControllerTest.php                    # new settings validation; defaults on create
├── TenantIsolationTest.php                    # + board, list, actions, job
└── PermissionAuthorizationTest.php            # + rooms.update_housekeeping_status, rooms.set_out_of_order rows

docs/
├── room-api-documentation.md                  # statuses, building, housekeeping-status, out-of-order, board
├── task-management-api-documentation.md       # new fields, inspection, issues, team from category, maintenance list, notifications
├── hotel-api-documentation.md                 # new default settings, inspection_required
├── staff-roles-api-documentation.md           # permission reference: two new rows + reused permissions
├── stays-api-documentation.md                 # readiness warning; stay-over task reuse on check-out
└── latest-changes-2026-10-04.md               # NEW: breaking room-edit change, status values, team-from-category, notifications
```

**Structure Decision**: This is a single Laravel backend, following the existing layers.
Controllers authorize, validate, delegate and respond. Two new services hold the
multi-caller logic: `HousekeepingService` for room cleanliness and `MaintenanceService` for
room serviceability. The SPEC-025 bridges (`isOutOfOrder()`, `HousekeepingDefaults`) are
the switch points this feature turns, as that plan intended. Frontend work lives in
`ecosystem-frontend` (D13).

## Delivery order

Each step leaves the suite green.

1. **Status split (US9 part, FR-037–039)**: migrations 1 and 2, enum changes, `Room` cast, every
   enum comparison (`ReservationCreator`, `StayLifecycleService`, `DashboardController`,
   AI room tools, factory), the room edit rejecting status fields, and existing tests moved
   to the new values. This is the **breaking change**: announce it in `latest-changes`.
2. **Default teams (US9 part, FR-040–042)**: migrations 3 and 5 (provisioning only),
   `HotelOperationalDefaults`, the `Hotel` created hook, `HousekeepingDefaults`, and
   `HotelController` validation.
3. **Lifecycle (US1, FR-001–003, 007, 008, 010)**: migration 4, the new enums,
   `HousekeepingService` (task hooks, `roomVacated`, `ensureCleaningTask`), and the
   TaskController and AI task tool hooks. Check-out goes through the service. Team from
   category (R9).
4. **Out of order and return (US5, FR-016–021)**: `MaintenanceService` (its columns came in migration 1), the
   routes, `RoomPolicy`, permissions, and the `room_ready_to_return` hint.
5. **Issue report (US4, FR-022–025)**: `TaskIssueController`, `reportIssue`.
6. **Inspection (US2, FR-004–006)**: the setting, the inspection action, readiness, and
   the check-in warning.
7. **Start of day (US3, FR-011–014)**: `StartHousekeepingDayJob`, run rows, schedule;
   remove the overnight job.
8. **Notifications (US6, FR-026–029)**: receipts, reassignment, team fan-out, routing
   alert.
9. **Manual correction and views (US8, US7, FR-009, FR-030, FR-031)**: the board timed
   with 500 seeded rooms, and the maintenance list.
10. **Concurrency, isolation and permission tests, then docs, Pint, and the full suite.**

## Risks

| Risk | Mitigation |
| --- | --- |
| The frontend still sends `status` or `housekeeping_status` on the room edit and gets 422 | The message names the new action. The change is announced in `latest-changes`. The frontend slice ships in the same phase (D13) |
| Legacy data has duplicate open cleaning tasks, so the unique index can't be created | Migration 5 classifies only the newest one and reports the others (R18, R19). Try it on a production snapshot first |
| Every hotel now gets a "Housekeeping" team, but one already exists under that name | `ensure()` reuses the existing team (restoring it if soft-deleted) instead of colliding with `unique(hotel_id, name)` (R8) |
| Team fan-out sends too much email | Limited to the Housekeeping and Maintenance teams. Receipts prevent repeats (R12) |
| The hourly job misses a day while the queue is down | It doesn't backfill a missed day. Failures are reported. Check-out tasks are created independently (R7) |
| Lock order deadlocks with check-out | Locks are always taken room → task, the same order as `StayLifecycleService`. The concurrency test covers check-out against out of order |
| Hotels in other time zones get stay-over tasks on the wrong date | The job computes each hotel's local date. Tested with two time zones |

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Breaking change: `POST`/`PUT /room` reject `status` and `housekeeping_status` (R2) | FR-002 needs a single writer for housekeeping status. Out of order needs a reason, an actor and an occupancy check (FR-016/017). Writing `status` directly today already corrupts stay-driven occupancy | A deprecation window that keeps accepting the fields: there is no safe meaning for a bare `status=available` or `housekeeping_status=clean` without a reason or a task. The old values no longer exist after the migration anyway |
