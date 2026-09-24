# Implementation Plan: Stay Lifecycle and Check-in/out

**Branch**: `004-stay-check-in-out` | **Date**: 2026-09-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/004-stay-check-in-out/spec.md` (Phase 4:
SPEC-023 Stay per Room, SPEC-024 Check-in, SPEC-025 Check-out)

## Summary

Stays move from one per reservation to **one per reservation line**:

- `stays` gains `reservation_room_id`.
- The unique rule moves from the reservation to the line.
- A partial unique index allows only one in-house stay per room.
- A data migration links each existing stay to its reservation's first line and creates
  stays for the other lines.

A new `StayLifecycleService` owns check-in and check-out, for one line or the whole
reservation:

- It locks in a fixed order, checks the prerequisites after locking, and can assign a
  room to an unassigned line at check-in.
- Check-in sets the stay in-house, the room occupied, and the reservation checked in on
  its first room.
- Check-out sets the stay departed, the room available and dirty, creates one cleaning
  task, and marks the reservation checked out on its last room.
- Everything happens in one transaction and is audited through the existing
  `RecordsEvents` / `EventLogger` path.

**Direction flip**: until now, the reservation's status drove the stay. From now on the
stays drive the reservation's status. The general reservation edit keeps accepting
`checked_in` / `checked_out` for this release, but routes it through the service and
marks it deprecated.

**Other changes**:
- Availability stops counting departed lines.
- Occupancy counts stays.
- Tasks gain `stay_id`.
- The Admin AI gets check-in, check-out and stay-list tools. The Concierge gets none.

SPEC-003 (status split) and SPEC-004 (default teams) are **not built yet**. The feature
works without them through two small adapters (R8, R10) that those specs will later
replace. Decisions R1–R24 are in [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, Sanctum, `laravel/ai` (tools), Pest 4

**Storage**: PostgreSQL. The plan uses partial unique indexes and `SELECT … FOR UPDATE`.
The existing availability query uses a lateral `generate_series`.

**Testing**: Pest feature tests against real Postgres (`Hospitality_Ecosystem_testing`).
The concurrency test uses a second DB connection, like SPEC-020's.

**Target Platform**: Laravel JSON API. The frontend slice (arrivals/departures board,
check-in/out actions, in-house list) is built in `ecosystem-frontend` (D13).

**Project Type**: Multi-tenant hospitality PMS backend (web service)

**Performance Goals**: Arrivals, departures and in-house lists for a 500-room hotel in
under 2 s (SC-008), with a fixed query count per list. A check-in or check-out of a
50-line reservation completes in one transaction.

**Constraints**:
- Tenant isolation everywhere, including in the AI tools.
- All-or-nothing check-in and check-out.
- Repeating a check-in or check-out does nothing (idempotent).
- No transaction-ledger writes (D11).
- The import still records history without checks.
- The deprecated reservation-edit path stays backward compatible.

**Scale/Scope**: Hotels of up to about 500 rooms and up to 50 lines per reservation
(SPEC-010). 5 migrations, about 10 new classes and about 12 changed ones.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle / rule | Status | How |
| --- | --- | --- |
| I. PMS is system of record | ✅ | Presence lives in `stays`. The AI reads it only through `GetStaysTool` |
| II. Preserve and evolve | ✅ | Reuses the `StayService` primitives, `ReservationCreator`'s occupancy sync, `RecordsEvents`, `Stay::eventVerbFor()` and the `ReservationRoomSync` room rules. `Reservation::stay()` keeps its callers (R3). The old PUT path is deprecated, not removed (R12). One new service, because there are 4 callers (R5) |
| III. Tenant isolation | ✅ | Route-model binding runs under the hotel scope. Tools are built with the hotel. Rooms named at check-in are resolved within the hotel. The migration drops the scope explicitly. Isolation tests are included |
| IV. Permission-based auth | ✅ | `stays.view`, `stays.check_in` and `stays.check_out` go through `StayPolicy::allows()` and are not in the employee defaults (Q5). The PUT path checks them on top of `reservations.update` |
| V. AI through tools | ✅ | The Admin AI tools call `StayLifecycleService`, run inside `asAiAgent()` and check permissions. The Concierge cannot check in or out (FR-025) |
| VI. Auditability | ✅ | `stay.checked_in` / `stay.checked_out`, reservation status, room status (Room gains `RecordsEvents`, R23), room assignment and the cleaning task are all audited, with the AI actor where it applies |
| VII. Integrity and idempotency | ✅ | One transaction per action, a fixed lock order, checks after locking, and repeats are no-ops. DB indexes guarantee one stay per line and one in-house stay per room (R6) |
| VIII. Tested at domain boundary | ✅ | Tests for the migration, sync, check-in, check-out, concurrency, lists, deprecation, AI tools, task linking, availability, isolation and the permission dataset |
| IX. Spec-driven | ✅ | Spec plus 8 clarifications → this plan |
| Rule: reservation ≠ stay | ✅ | Kept separate. The reservation status is now derived from its stays |
| Rule: check-out triggers housekeeping | ✅ | Room set dirty and a cleaning task created. Notifications come in SPEC-030 |
| Rule: no payments at check-in/out | ✅ | Nothing touches transactions or folios |
| i18n: no language-specific logic | ✅ | The cleaning task's team and category come from hotel settings by id, never by name (R10, fixed after `/speckit-analyze` D1) |
| Rule: incrementally deployable | ✅ | The PUT path stays compatible. New fields are additions. Works before SPEC-003, SPEC-004 and SPEC-021 (R8–R10) |

**Post-design re-check**: ✅ no violations.

Rejecting the deletion of a reservation with an in-house stay (R13) and the creation-time
rules (R12) are now in the spec as FR-021 and FR-020a (after `/speckit-analyze`).

## Project Structure

### Documentation (this feature)

```text
specs/004-stay-check-in-out/
├── spec.md
├── plan.md                      # this file
├── research.md                  # decisions R1–R24
├── data-model.md
├── quickstart.md
├── contracts/stays-api.md
├── checklists/requirements.md
└── tasks.md                     # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/Permission.php                          # + STAYS_VIEW, STAYS_CHECK_IN, STAYS_CHECK_OUT (not defaults)
├── Models/
│   ├── Stay.php                                  # + reservationRoom(), tasks(); occupiedRoomsOn() counts stays (R16)
│   ├── Reservation.php                           # + stays(); stay() = primary stay (R3); drop primaryRoomId() use in stays
│   ├── ReservationRoom.php                       # + stay(); inHouseRoomIds() from stays (R16)
│   ├── Hotel.php                                 # + housekeeping_team_id, cleaning_task_category_id (R10)
│   ├── Room.php                                  # + RecordsEvents, isOutOfOrder(), stays() (R8, R23)
│   └── Task.php                                  # + stay_id fillable/logged, stay()
├── Services/
│   ├── StayService.php                           # syncFromReservation() → syncForReservation() per line (R4)
│   ├── StayLifecycleService.php                  # NEW: checkIn/checkInReservation/checkOut/checkOutReservation (R5–R7, R11, R17)
│   └── AvailabilityService.php                   # holdingNights(): exclude departed lines, overstay on stay status (R15)
├── Support/
│   ├── Reservations/ReservationCreator.php       # syncStay → syncForReservation; in-house guards (R13); import history (R14); model-based room updates (R23)
│   ├── Reservations/RoomAssignmentRules.php      # NEW: assertAssignable() — type, hotel, overlap (R9; reused by SPEC-021)
│   └── Housekeeping/HousekeepingDefaults.php     # NEW: reads hotels.housekeeping_team_id / cleaning_task_category_id (R10; SPEC-004 fills them)
├── Exceptions/CheckInOutException.php            # NEW: 422 with per-stay reasons (R7)
├── Policies/StayPolicy.php                       # NEW: viewAny, view, checkIn, checkOut
├── Http/
│   ├── Controllers/StayController.php            # NEW: index, show, arrivals, departures, inHouse
│   ├── Controllers/StayCheckInController.php     # NEW: stay(), reservation()
│   ├── Controllers/StayCheckOutController.php    # NEW: stay(), reservation()
│   ├── Controllers/ReservationController.php     # deprecated status path + headers (R12); destroy guard (R13)
│   ├── Controllers/HotelController.php           # validate housekeeping defaults (same hotel, active team, category in team)
│   ├── Controllers/TaskController.php            # stay_id relation + consistency check (FR-018)
│   ├── Requests/StayDayRequest.php               # NEW: date
│   ├── Requests/CheckInRequest.php               # NEW: room_id | rooms[], checked_in_at
│   ├── Requests/CheckOutRequest.php              # NEW: checked_out_at
│   └── Resources/StayResource.php                # NEW; TaskResource + stay_id
├── Imports/ReservationsImport.php                # unchanged call; history path lives in ReservationCreator
└── Ai/
    ├── Tools/GetStaysTool.php                    # NEW
    ├── Tools/CheckInTool.php                     # NEW
    ├── Tools/CheckOutTool.php                    # NEW
    ├── Tools/CreateReservationTool.php           # status limited to pending/confirmed (R12)
    ├── Tools/CreateGuestServiceRequestTool.php   # stay + room resolution, optional room_number (R21)
    ├── Agents/AdminAdvisorAgent.php              # register 3 tools + instruction lines
    └── Agents/GuestConciergeAgent.php            # "cannot check in/out — front desk" line

routes/api.php                                    # stays routes (arrivals/departures/in-house before {stay}), check-in/out routes

database/migrations/
├── 2026_09_24_000001_add_reservation_room_id_to_stays_table.php
├── 2026_09_24_000002_backfill_stays_per_reservation_room.php
├── 2026_09_24_000003_replace_stay_uniqueness.php
├── 2026_09_24_000004_add_stay_id_to_tasks_table.php
└── 2026_09_24_000005_add_housekeeping_defaults_to_hotels_table.php

tests/Feature/
├── StayMigrationTest.php                         # NEW: backfill states, shares, conflict abort, down() refusal
├── StaySyncTest.php                              # NEW: create/add/cancel/reinstate/date move; in-house keeps actuals
├── CheckInTest.php                               # NEW: each R7 rule, assignment at check-in, warnings, whole-or-nothing, idempotent, times
├── CheckOutTest.php                              # NEW: room/housekeeping effects, cleaning task (+ no team), FR-012a, early/late, times
├── CheckInOutConcurrencyTest.php                 # NEW: DatabaseTruncation + 2nd connection
├── StayListTest.php                              # NEW: arrivals/departures/in-house, late/overdue, other day, super admin
├── ReservationStatusDeprecationTest.php          # NEW: PUT/POST paths, headers, permissions, cancel/delete guards
├── StayAiToolsTest.php                           # NEW: tools = endpoint results, refusal, ai_agent audit, concierge has no tool
├── TaskStayLinkTest.php                          # NEW: stay_id rules, filter, concierge single/multi-room
├── AvailabilityServiceTest.php                   # + departed line frees nights; expected line on checked-in res. no overstay
├── DashboardControllerTest.php (existing)        # occupancy per stay
├── TenantIsolationTest.php                       # + stays list/show/check-in/out, tools
└── PermissionAuthorizationTest.php               # + stays.view / check_in / check_out rows

docs/
├── stays-api-documentation.md                    # NEW
├── reservations-api-documentation.md             # deprecated status values, cancel/delete guards, create with checked_in
├── task-management-api-documentation.md          # stay_id
├── hotel-api-documentation.md                    # housekeeping_team_id, cleaning_task_category_id
├── staff-roles-api-documentation.md              # permission reference: stays.*
├── usage-metering-api-documentation.md           # stays count per room (R24)
└── latest-changes-2026-09-24.md                  # append: new endpoints, deprecation, behavior changes
```

**Structure Decision**: This is a single Laravel backend and it follows the existing
layers:
- Controllers only authorize, validate, delegate and respond.
- The new service holds the multi-caller check-in/out logic.
- The SPEC-003 and SPEC-004 bridges are isolated in one method and one class, so those
  specs change only those spots.
- Frontend work lives in `ecosystem-frontend` (D13).

## Delivery order

Each step leaves the suite green.

1. **Stay per line (US3, part of FR-001–005)**: migrations 1–3, `StayService::syncForReservation()`,
   `Reservation::stays()`/`stay()`, `inHouseRoomIds()`, `occupiedRoomsOn()`,
   `ReservationCreator` wiring. At this point the old PUT path still drives
   status through the per-line sync, and existing tests pass.
2. **Check-in (US1)**: permissions, `StayPolicy`, `RoomAssignmentRules`, `Room::isOutOfOrder()`,
   `Room` audit, `StayLifecycleService::checkIn*`, `CheckInOutException`, endpoints, the concurrency test.
3. **Check-out (US2)**: hotel housekeeping defaults (migration 5, hotel update), `HousekeepingDefaults`, cleaning task,
   availability change (R15), endpoints.
4. **Deprecated path and guards (FR-020–022)**: `ReservationController` update, store and
   destroy, the import history path, and `CreateReservationTool` statuses. This is a
   **behavior change** and is announced in `latest-changes`.
5. **Lists (US4)**: `StayController`, `StayResource`, and the performance check with 500
   seeded rooms.
6. **Admin AI (US5)**: three tools, agent instructions, Concierge instruction.
7. **Tasks and stays (US6)**: migration 4, `TaskController`, Concierge tool linking.
8. Docs, Pint, full suite.

## Risks

| Risk | Mitigation |
| --- | --- |
| Legacy data has two in-house stays in one room, so the index can't be created | Migration 3 checks first and aborts with the list (R22). Run it on a production snapshot before deploying |
| Frontend still checks guests in with PUT and hits the new rules (a room must be assigned) | The response keeps the old shape. The 422 explains what's missing. Called out in `latest-changes` and the frontend guide |
| SPEC-003 or SPEC-004 lands and changes the status and team model | The SPEC-003 bridge is one method with a TODO (R8). SPEC-004 only has to fill the two hotel columns (R10) |
| A hotel never sets its housekeeping defaults | Cleaning tasks are still created, unassigned (FR-013); `latest-changes` tells admins to set them; SPEC-004 fills them |
| `Reservation::stay()` meaning changes for pitching | Callers are guest-level; the existing pitching and booking tests guard the behavior (R3) |

## Complexity Tracking

No violations.
