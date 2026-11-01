# Research: Physical Room Assignment & Reassignment

**Feature**: `specs/020-room-assignment` · **Date**: 2026-10-30

Every decision below was settled against the code as it is on `main` + branch 019. No
open unknowns remain.

## What exists today (baseline for every decision)

| Piece | Where | Relevant behaviour |
| --- | --- | --- |
| Clash rule | `app/Support/Reservations/RoomAssignmentRules.php` | `reason()` checks hotel, type, and overlapping nights via `holdingLines()` (live line, inventory-holding reservation, stay not `departed`/`no_show`, departure day excluded). Used by check-in (named room), no-show undo, benchmark. |
| Edit path | `ReservationCreator::update` → `ReservationRoomSync::diff` / `assertRoomFits` | Checks hotel + type + not twice in one reservation. **No cross-reservation clash check.** Moving an in-house line's room already works here: `guardInHouse()` returns vacated rooms, `markDirty()` creates the cleaning task, `syncStays()` moves the stay. No reason, no extra permission. |
| Admin AI | `AssignRoomsTool` → `ReservationCommands::assignRooms` → edit path | Gated by `reservations.update` in `AdminToolset`. Same gap as the edit path. |
| Import | `ReservationImportWriter::heldBy()` | Own clash query; clash → line imported without a room + `ROOM_CLASH` warning. |
| Stored-data guard | `reservation_rooms_reservation_room_unique` | Only within one reservation. Nothing across reservations. |
| Availability | `AvailabilityService::holdingNights` / `countByTypeAndNight` | Counts by `reservation_rooms.room_type_id` (booked type). Locks types with `lockTypes()`. |
| Locks | `ReservationLock::lock()` | Reservation → lines → stays; rooms after. |
| Audit | `RecordsEvents` on `ReservationRoom` (`room_type_id`, `room_id`, `status`) | Every model-level `room_id` change is already an audited event. |
| Room delete | `RoomController::destroy` | Hard delete; FK `nullOnDelete` clears `reservation_rooms.room_id`. |

## R1 — How the stored data guarantees no overlap (FR-009)

**Decision**: A PostgreSQL **exclusion constraint** on `reservation_rooms`:
`EXCLUDE USING gist (room_id WITH =, held_nights WITH &&) WHERE (held_nights IS NOT NULL)`,
with a new nullable `held_nights daterange` column (`[arrival, departure)`) that is
**maintained by database triggers**, plus the `btree_gist` extension (needed for `uuid =`
inside a gist index).

`held_nights` is non-null exactly when `RoomAssignmentRules::holdingLines()` would include
the line *and* it has a room: `room_id` set, line not cancelled or soft-deleted, reservation
not soft-deleted and in `pending|confirmed|checked_in`, line's stay (if any) not
`departed|no_show`. Triggers:

1. `BEFORE INSERT OR UPDATE` on `reservation_rooms` → recompute `NEW.held_nights`.
2. `AFTER UPDATE OF arrival_date, departure_date, status, deleted_at` on `reservations` →
   touch its lines so (1) recomputes.
3. `AFTER INSERT OR UPDATE OF status, deleted_at, reservation_room_id` on `stays` → touch
   that line.

**Rationale**: The invariant depends on four tables and is written by at least six paths
(create, edit, assign, check-in, no-show/undo, import, check-out, room delete via FK).
Maintaining a derived column in PHP at every path is the kind of drift this spec exists to
end; triggers keep it correct whichever path writes, including future ones (FR-001). A
date change, un-cancel or no-show undo that would clash now fails at the statement that
causes it (FR-011). Exclusion constraints are checked per row against committed and
in-flight rows, so two simultaneous assignments serialise and one fails (SC-001).

**Alternatives considered**:
- *Per-night table `room_nights (room_id, night)` with a unique index*: no extension, but
  one row per night per line, rewritten on every date change; same maintenance problem.
- *Denormalised `arrival/departure` + `holds_room` maintained in PHP*: drift-prone (above).
- *Application lock per room only*: not a stored-data guarantee; the master plan asks for
  "Postgres exclusion constraint or equivalent".
- *Range on `reservations` instead of lines*: the room lives on the line, so the
  constraint must be on the line.

**Extension availability**: `btree_gist` is a contrib module shipped in the official and
`pgvector/pgvector:pg17` images (local and CI). Production already runs
`CREATE EXTENSION vector`, so the same privilege covers it. The migration uses
`CREATE EXTENSION IF NOT EXISTS btree_gist`.

## R2 — Existing overlaps before the constraint (FR-012)

**Decision**: A read-only artisan command `rooms:overlaps` lists every pair of lines that
would violate R1 (hotel, room number, both reservation codes, shared nights), across all
hotels (`TenantContext::withoutScope()`, deliberate: platform-owner report). The migration
that adds the constraint runs the same query first and, if any rows come back, aborts with
a message naming the count and the command. Nothing is modified automatically.

**Rationale**: Without the pre-check the migration fails with a raw `23P01` naming no
reservation. The spec forbids silent cleanup.

**Alternatives**: auto-unassign the later line (rejected by spec); `NOT VALID` constraint
(PostgreSQL does not support `NOT VALID` for exclusion constraints).

## R3 — One rule set, one write path (FR-001–FR-008)

**Decision**: A new `App\Services\Reservations\RoomAssignmentService` owns every room
change on a line:

- `assign(Reservation, list<{line_id, room_id|null, upgrade: bool}>, overbookOverride)` —
  assign/reassign/unassign, atomic, final-state checks (swaps work).
- `move(Stay, Room, reason, upgrade, overbookOverride)` — in-house move (R5).

Both run inside `DB::transaction` with the lock order `ReservationLock::lock()` →
`AvailabilityService::lockTypes()` (booked types + target rooms' types) → target rooms by
id. They call `RoomAssignmentRules::reason()` (extended for upgrades, R4) for a clear
message first; the constraint is the race safety net (R6).

`RoomAssignmentRules::reason()` becomes the single rule source. `ReservationRoomSync::
assertRoomFits()` is replaced by a call to it for lines that change room, so the edit and
create paths get the cross-reservation check too. `ReservationImportWriter::heldBy()` is
re-pointed at `RoomAssignmentRules::holdingLines()` (same semantics; import keeps its
warning-not-error behaviour).

**Write order under the constraint**: the exclusion constraint is checked per row, so
`ReservationCreator::update()` must release rooms before anything can make them clash:
first take rooms off the lines the plan moves or cancels (and, when un-cancelling, off
restored lines whose room was given away), then update the reservation's dates and
status, then apply the remaining room changes. Otherwise one valid edit that extends dates
and moves 204 → 206 fails on the intermediate state.

**Rationale**: Today's `assignRooms()` already reuses the edit pipeline so stays, occupancy
and dirty marking stay correct; the new service keeps that by delegating the write to
`ReservationCommands::update()` with the full line list, and adds the checks and
permissions in front. One pipeline, not two.

**Alternatives**: a separate write path bypassing `ReservationCreator::update` (would
duplicate stay sync, occupancy and dirty logic — rejected, constitution II).

## R4 — Upgrades (FR-015–FR-017, clarification C)

**Decision**: No new column. A line is *upgraded* when it holds a room whose
`room_type_id` differs from the line's booked `room_type_id`; the response derives
`is_upgrade`. Requests must send `upgrade: true` to place a room of another type (a
deliberate confirmation, not a stored flag). Same permission as assignment.

Availability counts each holding line against its **effective type**:
`coalesce(rooms.room_type_id, reservation_rooms.room_type_id)` (left join `rooms`). That is
the booked type for every non-upgraded line, so existing results are unchanged; an
upgraded line moves its unit from the booked type to the room's type. `footprint()` and
`guard()` use the same expression, so an upgrade into a full type is rejected unless
`overbook_override` + `reservations.overbook` (unchanged mechanics, AI never overrides).

`RoomAssignmentRules::reason($line, $room, allowUpgrade: bool)` returns "not of the line's
room type; send upgrade to place it anyway" when the types differ and `allowUpgrade` is
false. When an upgrade is placed, the service records `room_upgraded` on the line
(`booked_type`, `room_type`) so the audit tells an upgrade from a mistake (FR-025).

`reason()` stays limited to hotel, type and clash. Whether a line may change at all
(cancelled line, closed reservation, departed or no-show stay) is a separate
`RoomAssignmentRules::assertChangeable()`, called only by `RoomAssignmentService`: the
no-show undo calls `reason()` while the stay is still `no_show`, so a status check inside
`reason()` would wrongly strip every restored room.

**Rationale**: A stored flag duplicates what the data already says and can contradict it
(e.g. reassigning back to the booked type must clear it — FR-017 holds automatically when
derived). Counting against the effective type is the only way neither type is oversold.

**Alternatives**: `is_upgrade` boolean column (redundant, drift); count against both types
(double counts).

**Note**: The edit path (`rooms[].room_id` on reservation create/update) keeps requiring
the room's type to equal the line's type; upgrades go only through the assignment and move
endpoints and the Admin AI tool. Keeps the general edit simple.

## R5 — Moving an in-house guest (FR-013, FR-014)

**Decision**: `POST /api/stays/{stay}/move-room` → `RoomAssignmentService::move()`. Extra
checks beyond R3: stay `in_house`; reason required (max 500); target room not out of order
and has no in-house stay; clash checked for **every night of the stay** (arrival →
departure), the same range R1's `held_nights` covers (spec FR-013).

The write reuses the existing edit-path behaviour (stay follows the line's room,
`markDirty()` on the vacated room with `CleaningReason::CHECK_OUT`, occupancy sync). The
audit gets one explicit `room_moved` event on the stay via `EventLogger::record()` with
`from_room`, `to_room`, `reason`; the line's `room_id` change is audited as today.

The response includes `open_room_change_requests`: the guest's open tasks created by
`RequestRoomChangeTool` for this stay (FR / US4 scenario 3). They are not closed.

**Edit path change (breaking)**: `ReservationCreator::guardInHouse()` stops accepting a
room change on an in-house line from the general edit and the assign endpoint: `422` on
`rooms.N.room_id` "Room X is checked in; move the guest with the room-move action." This
forces a reason and the move permission.

**Alternatives**: keep moves through the edit path with an optional reason (no permission
separation, no required reason — fails FR-013/FR-019).

## R6 — Turning a constraint violation into a clear message (FR-010)

**Decision**: `App\Support\Reservations\RoomHoldConflict::rethrow(callable)` wraps the
outermost transaction of each writing path (assignment service, `ReservationCommands`,
`ReservationController` store/update, `StayLifecycleService`, `NoShowService`, import
writer per row). On `QueryException` with SQLSTATE `23P01` and constraint
`reservation_rooms_no_overlap`, it looks up the clash with `RoomAssignmentRules` (after
rollback) and throws `ValidationException` on `rooms` with the usual "Room X is booked for
RES-… on overlapping nights." A fallback renderer in `bootstrap/app.php` maps any
un-wrapped `23P01` on that constraint to the same `422` shape, never a `500`.

The import writer runs each row in a savepoint; a `23P01` there becomes the existing
`ROOM_CLASH` warning and the line is written without a room.

**Rationale**: PostgreSQL aborts the transaction on the violation, so the translation must
happen outside it; a central renderer alone cannot serve the AI tools, which turn
`ValidationException` into tool text.

## R7 — Permissions and gates (FR-019–FR-021, FR-023)

**Decision**:

| Case | Value | Gates |
| --- | --- | --- |
| `RESERVATIONS_ASSIGN_ROOM` | `reservations.assign_room` | `PUT /reservation/{r}/rooms`; `GET /reservation/unassigned-arrivals`; setting/changing `rooms[].room_id` on reservation store/update; `AssignRoomsTool` |
| `RESERVATIONS_MOVE_ROOM` | `reservations.move_room` | `POST /stays/{stay}/move-room`; new `MoveRoomTool` |

Neither in `employeeDefaults()`. Policy abilities via `allows()`:
`ReservationPolicy::assignRoom` and `StayPolicy::moveRoom` (the move authorizes a `Stay`,
so Laravel resolves `StayPolicy`; its `before()` already admits super admins). Check-in with a named room keeps `stays.check_in` only (FR-021).
`AdminToolset` gates `AssignRoomsTool` by `[RESERVATIONS_UPDATE, RESERVATIONS_ASSIGN_ROOM]`
(it edits the reservation) and `MoveRoomTool` by `[RESERVATIONS_MOVE_ROOM]`.

On store/update the extra check runs only when a request's `room_id` differs from the
line's current one (or is set on a new line), so existing clients that echo rooms back are
not broken.

## R8 — Unassigned arrivals list (FR-018)

**Decision**: `GET /api/reservation/unassigned-arrivals?from=&to=` (registered before the
resource route). Query: live lines with `room_id IS NULL` whose stay is `expected` (or
that have no stay yet), on `pending|confirmed|checked_in` reservations (a multi-room
reservation is `checked_in` once its first room is) with `arrival_date` in `[from, to]` (default today → today+7, hotel time,
max 31 days) **or** `arrival_date < today` with the line's stay still `expected` (late,
`is_late: true`). Uses `GenericIndexRequest` paging/sort (`arrival_date` default).
Resource: `UnassignedArrivalResource`. Tenant-scoped by the global scope + `resolveHotel()`.

**Alternatives**: a filter on `GET /stays/arrivals` (single day, stay-centric, different
permission) — kept unchanged; the new list is a planning view across days.

## R9 — Admin AI (FR-022–FR-024)

- `AssignRoomsTool` → calls `RoomAssignmentService::assign()`; accepts `upgrade` per item
  only when the admin asked; description rewritten to the enforced rules; never overrides
  overbooking (existing `guard()` rule for AI actors).
- New `MoveRoomTool` (code or room number of the in-house guest, target room number,
  reason). Listed in `tests/Inventory/ai_tools.php` with its tests.
- Guest Concierge unchanged (`RequestRoomChangeTool` only).

## R10 — Out-of-order target (FR-007)

**Decision**: assignment to a room with `isOutOfOrder()` succeeds and adds
`warnings: ["Room X is out of order; return it to service before check-in."]`. Moves
reject it (FR-013). Check-in already rejects it.

## R11 — Testing approach

New Pest files, all on Postgres:

- `RoomAssignmentTest` — US1/US2 scenarios, swaps, idempotency, out-of-order warning,
  cross-hotel `404`.
- `RoomOverlapConstraintTest` — writes clashing rows directly (bypassing services) and
  through each path (create, edit, assign, check-in named room, no-show undo, date change,
  un-cancel, import) → always rejected with the clear message (SC-003).
- `RoomAssignmentConcurrencyTest` — two connections assign one room concurrently
  (pattern of `ReservationAvailabilityConcurrencyTest`) → exactly one succeeds (SC-001).
- `RoomMoveTest` — US4, cleaning task, occupancy, audit, open request pointer.
- `RoomUpgradeTest` — US5, availability counts effective type, overbook override.
- `UnassignedArrivalsTest` — US3, window default, late flag, isolation.
- `RoomOverlapsCommandTest` + migration pre-check.
- Additions to `PermissionAuthorizationTest` dataset (4 endpoints), `TenantIsolationTest`,
  `AdminToolPermissionTest`, `AiToolInventoryTest` / `tests/Inventory/`.
- Existing tests that move an in-house guest through the general edit change to expect
  `422` (breaking change, R5).
