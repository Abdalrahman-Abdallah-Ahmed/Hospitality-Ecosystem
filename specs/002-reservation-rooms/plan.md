# Implementation Plan: Reservation Rooms

**Branch**: `002-reservation-rooms` | **Date**: 2026-09-23 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/002-reservation-rooms/spec.md` (SPEC-010, Phase 2)

## Summary

Replace the single `reservations.room_id` with **reservation rooms**: one row per booked
unit, holding a room type, an optional physical room and a status (`reserved`,
`cancelled`). The work stays inside the existing `ReservationCreator`, which becomes a
transactional create/update operation. A new `ReservationRoomSync` collaborator does line
expansion, diffing and validation (ownership, type match, 50-unit cap, capacity with a
staff-only audited override, rules per reservation status). Occupancy, the overnight
dirty job and historic occupancy switch from the stay's room to the lines. The single
stay per reservation is kept, pointing at the primary line's room. Three migrations
create the table, backfill one line per existing reservation (idempotent, with an
inactive placeholder room type where a reservation had no room), and drop
`reservations.room_id`. The API, the AI tools, the import, the dashboard and the
notification move to the new shape. The API change is breaking and documented. Details
are in [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, Sanctum, `laravel/ai` (tools), maatwebsite/excel (import), Pest 4

**Storage**: PostgreSQL. A partial unique index (Postgres-specific) backs FR-005.

**Testing**: Pest feature tests against real Postgres (`Hospitality_Ecosystem_testing`)

**Target Platform**: Laravel JSON API. The frontend slice is in `ecosystem-frontend` (D13).

**Project Type**: Multi-tenant hospitality PMS backend (web service)

**Performance Goals**: A reservation with 50 lines is created in one request and one
transaction. The index eager-loads `reservationRooms.roomType` and `reservationRooms.room`,
so there are no N+1 queries.

**Constraints**: tenant isolation; no new permissions (FR-021); breaking API change
coordinated with the frontend; the backfill must be safe to re-run and must not change
room status.

**Scale/Scope**: ≤ 50 lines per reservation; the backfill touches every existing
reservation once (in the thousands per hotel), in plain `DB::table` batches.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle / rule | Status | How |
| --- | --- | --- |
| I. PMS is system of record | ✅ | Lines are structured PMS data; AI reads them through tools |
| II. Preserve and evolve | ✅ | Extends `ReservationCreator`, `StayService`, existing tools, import, controller. No new service layer or endpoints. `room_id` is migrated, not silently lost |
| III. Tenant isolation | ✅ | `ReservationRoom` uses `BelongsToHotel`. Ownership checks name the hotel explicitly (works in queued context). Isolation tests for lines, filters and tools |
| IV. Permission-based auth | ✅ | Reuses `reservations.*` through `ReservationPolicy`. No new cases. The capacity override is a request flag, not a permission (research R7) |
| V. AI through tools | ✅ | `CreateReservationTool` calls the same `ReservationCreator`, is wrapped in `EventLogger::asAiAgent()`, and cannot override capacity |
| VI. Auditability | ✅ | `RecordsEvents` on lines plus a `reservation.capacity_overridden` event. Room moves audited |
| VII. Integrity and idempotency | ✅ | One `DB::transaction` per create/update. Partial unique index. Idempotent backfill. Import re-run safe (existing `reservation_id` check) |
| VIII. Tested at domain boundary | ✅ | Pest tests for API, domain op, tools, import, migration, isolation |
| IX. Spec-driven | ✅ | Spec clarified (4 answers) → this plan |
| Rule 4 (no room required at creation) | ✅ | `room_id` optional on every line |
| Rule 5 (shared dates) | ✅ | Lines have no dates |
| Rule 6 (Reservation ≠ Stay) | ✅ | Stay untouched structurally. SPEC-023 splits it per line |
| Rule 7 (Type ≠ Room) | ✅ | Line holds both, type match enforced |
| Rule 23 (invariants) | ✅ | ≥1 line, ≤50, type match, no duplicate room, capacity |
| Rule 25 (incrementally deployable) | ✅ | Interim room choice (clarification Q1) keeps check-in and occupancy working before SPEC-021 |

**Post-design re-check**: ✅ no violations. Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```text
specs/002-reservation-rooms/
├── spec.md
├── plan.md                      # this file
├── research.md                  # decisions R1–R15
├── data-model.md
├── quickstart.md
├── contracts/reservations-api.md
├── checklists/requirements.md
└── tasks.md                     # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/ReservationRoomStatus.php                 # NEW
├── Models/
│   ├── ReservationRoom.php                         # NEW (BelongsToHotel, SoftDeletes, RecordsEvents, inHouseRoomIds())
│   ├── Reservation.php                             # drop room_id/room(); add reservationRooms(), primaryRoomId()
│   └── Stay.php                                    # occupiedRoomsOn() counts lines
├── Support/Reservations/
│   ├── ReservationCreator.php                      # create()/update() transactional; syncRoomOccupancy(roomIds)
│   └── ReservationRoomSync.php                     # NEW: expand, diff, validate, capacity, status rules
├── Services/StayService.php                        # stay.room_id = primaryRoomId()
├── Http/
│   ├── Controllers/ReservationController.php       # new requests, update via ReservationCreator, eager loads, virtual filters
│   ├── Controllers/RoomTypeController.php          # destroy(): reservation guard (FR-028)
│   ├── Controllers/DashboardController.php         # arrivals/departures load lines
│   ├── Requests/StoreReservationRequest.php        # NEW extends GenericStoreRequest (+rooms.*, capacity_override)
│   ├── Requests/UpdateReservationRequest.php       # NEW extends GenericUpdateRequest
│   ├── Requests/ReservationIndexRequest.php        # NEW extends GenericIndexRequest (virtual filters)
│   ├── Resources/ReservationResource.php           # rooms, room_summary; drop room_id/room
│   └── Resources/ReservationRoomResource.php       # NEW
├── Ai/Tools/
│   ├── CreateReservationTool.php                   # rooms[], asAiAgent, no override
│   ├── GetReservationsTool.php                     # rooms + summary
│   ├── GetOwnReservationTool.php                   # rooms + summary
│   └── CreateGuestServiceRequestTool.php           # room from primaryRoomId()
├── Imports/ReservationsImport.php                  # build one line per row
├── Jobs/MakeRoomDirtyOvernightJob.php              # use ReservationRoom::inHouseRoomIds()
└── Notifications/WhatsAppReservationCreatedNotification.php  # list room types/numbers

database/migrations/
├── YYYY_MM_DD_000000_create_reservation_rooms_table.php
├── YYYY_MM_DD_000001_backfill_reservation_rooms.php
└── YYYY_MM_DD_000002_drop_room_id_from_reservations_table.php

tests/
├── Pest.php                                        # shared helper: reservation with lines
└── Feature/
    ├── ReservationRoomsTest.php                    # NEW: create/update/lines/status rules/capacity/audit
    ├── ReservationRoomsMigrationTest.php           # NEW: backfill, idempotency, placeholder, occupancy unchanged
    ├── ReservationControllerTest.php               # updated to rooms[]
    ├── ReservationRoomOccupancyTest.php            # multi-room occupancy, room move, cancel
    ├── StayServiceTest.php, DashboardControllerTest.php, ReservationImportTest.php,
    │   CreateReservationToolTest.php, SenderRecognitionServiceTest.php, PitchEligibilityTest.php,
    │   FilterableTest.php, GenericCrudTest.php, EventLogTest.php     # fixtures off room_id
    ├── RoomTypeControllerTest.php                  # deletion guard
    └── TenantIsolationTest.php                     # reservation rooms + filters

docs/
├── reservations-api-documentation.md               # object, create/update, filters, errors
├── room-types-api-documentation.md                 # new deletion rejection
└── latest-changes-<date>.md                        # breaking change summary
```

**Structure Decision**: Single Laravel app, existing layers. Domain logic stays in
`app/Support/Reservations`, next to `ReservationCreator`, because that is where the shared
creation path already lives. Moving it to `app/Services` is a separate refactor and is not
needed for this spec.

## Implementation order (for /speckit-tasks)

1. **Foundation**: enum, model, relation, create-table migration, `ReservationRoom` tenant
   isolation test.
2. **Domain operation**: `ReservationRoomSync` and transactional `ReservationCreator::create/update`.
   Occupancy and stay switched to lines (`inHouseRoomIds`, `primaryRoomId`,
   `occupiedRoomsOn`, overnight job).
3. **Backfill and drop** migrations, with a migration test that seeds legacy rows before the
   backfill runs.
4. **API**: requests, controller, resources, virtual filters, room-type deletion guard.
5. **AI tools and import**: create tool (AI actor), read tools, guest-service tool, import,
   notification, dashboard.
6. **Tests and fixtures**: move every existing `room_id` fixture to lines through a
   shared helper in `tests/Pest.php`.
7. **Docs**: reservations and room-types docs, latest-changes. Pint, then the full suite.

**Deploy note**: migrations 1–3 run in one release together with the code. The frontend
reservation form must ship in the same release window, because the API break is
intentional (D13).

## Risks

| Risk | Mitigation |
| --- | --- |
| Many existing tests build reservations with `room_id` | One shared fixture helper; do step 6 early enough to keep the suite green per commit |
| Legacy data where a reservation's room belongs to another hotel | Backfill takes the room only when `rooms.hotel_id` matches, otherwise uses the placeholder type and reports it |
| Occupancy regressions for single-room reservations | Existing `ReservationRoomOccupancyTest` must stay green unchanged except for fixtures |
| Frontend not ready at deploy | Hold the release; there is no compatibility shim by design (spec Assumptions) |

## Complexity Tracking

No constitution violations to justify.
