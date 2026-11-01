# Implementation Plan: Physical Room Assignment & Reassignment

**Branch**: `020-room-assignment` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/020-room-assignment/spec.md`. This is
SPEC-021 from Phase 3 of the master plan, clarified on 2026-10-30 (upgrades in scope,
allowed to anyone with `reservations.assign_room`).

## Summary

- **Guarantee (R1, R2)**: new `reservation_rooms.held_nights daterange`, kept by triggers on
  `reservation_rooms`, `reservations` and `stays`, and an exclusion constraint
  `reservation_rooms_no_overlap` (`btree_gist`). A read-only `rooms:overlaps` command; the
  migration refuses to run while overlaps exist.
- **One rule set (R3)**: `RoomAssignmentRules::reason()` becomes the only rule source and
  the edit/create path uses it, closing today's cross-reservation gap. A new
  `RoomAssignmentService` (`assign`, `move`) writes through the existing
  `ReservationCommands::update()` pipeline so stays, occupancy and dirty marking stay as
  they are.
- **Upgrades (R4)**: derived, not stored. `upgrade: true` confirms a room of another type;
  availability counts each line against its *effective type* (room's type when assigned).
- **Moves (R5)**: `POST /stays/{stay}/move-room` with a required reason and a `room_moved`
  audit; the general edit and the assign endpoint no longer move in-house guests.
- **Clear errors (R6)**: `RoomHoldConflict` turns a `23P01` into the usual `422` clash
  message on every path; a renderer fallback guarantees no `500`.
- **Permissions (R7)**: `reservations.assign_room`, `reservations.move_room`, not defaults.
- **List (R8)**: `GET /reservation/unassigned-arrivals`.
- **AI (R9)**: `AssignRoomsTool` re-pointed and gated by `assign_room`; new `MoveRoomTool`.

## Technical Context

**Language/Version**: PHP 8.3+, Laravel 13.

**Primary Dependencies**: none new in Composer. PostgreSQL extension `btree_gist`
(contrib; ships with `pgvector/pgvector:pg17`).

**Storage**: PostgreSQL. One migration: extension, column, trigger function + 3 triggers,
exclusion constraint, with an overlap pre-check.

**Testing**: Pest feature tests on real Postgres (research R11), including a two-connection
concurrency test. Run with `php artisan test --parallel`.

**Target Platform**: Linux server, JSON API. No queue, job or schedule change.

**Project Type**: Multi-tenant Laravel web service (backend; frontend slice handed to
`ecosystem-frontend`).

**Performance Goals**: assignment and move feel instant at the desk; the constraint adds
one gist index probe per line write. Unassigned-arrivals query uses the existing
`(hotel_id, room_id)` / reservation date indexes over ≤ 31 days.

**Constraints**: never edit shipped migrations; tenancy via `BelongsToHotel` and
`TenantContext`; `rooms:overlaps` is the one deliberate `withoutScope()` use (platform
report); lock order reservation → lines → stays → room types → rooms.

**Scale/Scope**: ~1 migration, 1 command, 1 service, 1 support class, 2 controllers
touched + 1 new action set, 2 requests, 1 resource, 2 permissions, 2 AI tools, ~7 new test
files, ~6 existing test files adjusted, 4 docs + latest-changes + frontend handoff.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
| --- | --- | --- |
| I. PMS is system of record | Pass | Assignment is structured data; AI reads/writes it only through tools. |
| II. Preserve and Evolve | Pass | Reuses `RoomAssignmentRules`, `ReservationCommands`, the edit pipeline, `markDirty`, `RecordsEvents`. Interim room choice on create/edit kept (spec Assumptions). Breaking changes listed in latest-changes. |
| III. Tenant isolation | Pass | All new endpoints behind `tenant` + scoped binding (`404`). Constraint is global by room id (room ids are hotel-owned). Isolation tests added. Only `rooms:overlaps` crosses hotels, read-only, platform owner. |
| IV. Permission-based authorization | Pass | Two new enum cases, policy abilities via `allows()`, not in `employeeDefaults()`, dataset + docs updated, AI tools gated by the same cases. |
| V. AI through tools | Pass | Admin AI uses the same service and rules; never overbooks; Concierge unchanged. |
| VI. Auditability | Pass | Line `room_id` changes already audited; new `room_moved` event with reason; overbook override audited as today. |
| VII. Integrity and idempotency | Pass | The core of the spec: DB-level exclusion constraint, transactions with fixed lock order, re-sent assignments are no-ops. |
| VIII. Tested at the domain boundary | Pass | Per-path constraint tests, concurrency, isolation, permission allow/deny, AI tool tests. |
| IX. Spec-driven, incremental | Pass | Spec clarified; deployable alone; overlap pre-check protects the rollout. |
| Room inventory (Type vs Room separate; status vs housekeeping separate) | Pass | Upgrade derived from both; move updates status and housekeeping separately through existing services. |

**Post-design re-check (after Phase 1)**: Pass. Triggers are a deliberate use of
"database constraints SHOULD enforce invariants" (VII); they are justified in research R1
and listed under Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/020-room-assignment/
├── spec.md
├── plan.md                         # this file
├── research.md                     # R1–R11
├── data-model.md
├── quickstart.md
├── contracts/
│   └── room-assignment-api.md
├── checklists/requirements.md
└── tasks.md                        # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
database/migrations/
└── 2026_10_30_000001_add_room_overlap_guarantee_to_reservation_rooms.php   # NEW (R1, R2)

app/Console/Commands/
└── RoomOverlapsCommand.php                    # NEW rooms:overlaps (R2)

app/Enums/Permission.php                       # + RESERVATIONS_ASSIGN_ROOM, RESERVATIONS_MOVE_ROOM (R7)

app/Support/Reservations/
├── RoomAssignmentRules.php                    # reason(..., allowUpgrade), assertChangeable(), overlaps() (R3, R4)
├── RoomHoldConflict.php                       # NEW 23P01 → ValidationException (R6)
├── ReservationRoomSync.php                    # assertRoomFits → RoomAssignmentRules (R3)
└── ReservationCreator.php                     # release-before-write order (R3); guardInHouse rejects in-house room change (R5)

app/Services/
├── Reservations/RoomAssignmentService.php     # NEW assign(), move() (R3, R5)
├── Reservations/ReservationCommands.php       # assignRooms → service; RoomHoldConflict wrap
├── AvailabilityService.php                    # effective type in holdingNights/countByTypeAndNight; lockTypes incl. room types (R4)
├── StayLifecycleService.php, NoShowService.php # RoomHoldConflict wrap (R6)
└── ../Support/Import/Reservations/ReservationImportWriter.php  # heldBy → holdingLines; 23P01 → ROOM_CLASH (R3, R6)

app/Http/
├── Controllers/RoomController.php             # + assign (PUT rooms), unassignedArrivals (R3, R8)
├── Controllers/StayController.php             # + moveRoom (R5)
├── Controllers/ReservationController.php      # assign_room gate when room_id set/changed (R7)
├── Requests/AssignRoomsRequest.php            # NEW
├── Requests/MoveRoomRequest.php               # NEW
├── Requests/UnassignedArrivalsRequest.php     # NEW (extends GenericIndexRequest)
└── Resources/UnassignedArrivalResource.php    # NEW; ReservationRoomResource + is_upgrade

app/Policies/ReservationPolicy.php             # + assignRoom
app/Policies/StayPolicy.php                    # + moveRoom
bootstrap/app.php                              # 23P01 renderer fallback (R6)
routes/api.php                                 # 3 routes (unassigned-arrivals before resource)

app/Ai/Tools/
├── AssignRoomsTool.php                        # via service, upgrade, honest description (R9)
├── MoveRoomTool.php                           # NEW
└── Admin/AdminToolset.php                     # gates

tests/Feature/
├── RoomAssignmentTest.php, RoomOverlapConstraintTest.php, RoomAssignmentConcurrencyTest.php   # NEW
├── RoomMoveTest.php, RoomUpgradeTest.php, UnassignedArrivalsTest.php, RoomOverlapsCommandTest.php  # NEW
└── PermissionAuthorizationTest.php, TenantIsolationTest.php, AdminToolPermissionTest.php,
    AdminWriteToolsTest.php, ReservationRoomsTest.php, AvailabilityServiceTest.php  # adjusted
tests/Inventory/ai_tools.php                   # + MoveRoomTool

docs/
├── reservations-api-documentation.md, stays-api-documentation.md
├── staff-roles-api-documentation.md, availability docs section on upgrades
├── latest-changes-2026-10-30.md               # breaking changes (contract §4)
└── AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md  # Phase 3 "Built" note when done
specs/020-room-assignment/frontend-changes.md   # handoff: assignment dialog, move dialog, unassigned list, upgrade badge
```

**Structure Decision**: Existing Laravel layout. Business logic in a new service under
`app/Services/Reservations` next to `ReservationCommands`; the rule source stays in
`app/Support/Reservations`; thin controllers; no new top-level directories.

## Complexity Tracking

| Choice | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Database triggers maintaining `held_nights` | The overlap invariant depends on 4 tables and ≥ 6 writing paths; FR-001/FR-009 require it to hold for all of them, including future ones | Maintaining the column in PHP at each path recreates the drift this spec fixes; a per-night table has the same maintenance burden (research R1) |
| `btree_gist` extension | Gist exclusion on `uuid =` needs it | No built-in alternative for an exclusion constraint mixing equality and range overlap |
