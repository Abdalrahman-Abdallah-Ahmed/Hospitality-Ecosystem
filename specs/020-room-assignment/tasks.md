---

description: "Task list for physical room assignment & reassignment (SPEC-021)"
---

# Tasks: Physical Room Assignment & Reassignment

**Input**: Design documents from `specs/020-room-assignment/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/room-assignment-api.md](contracts/room-assignment-api.md),
[quickstart.md](quickstart.md)

**Tests**: Required (constitution VIII). Pest feature tests on real Postgres; run with
`php artisan test --parallel`. Each test file uses
`uses(RefreshDatabase::class)`, sets the API key in `beforeEach`, and builds models with
`Model::create([...])` (only `UserFactory` exists).

**Organization**: grouped by user story. Phase 2 (the stored-data guarantee and the single
rule set) blocks everything; after it, US1 → US2 share the assignment service, US3, US4 and
US5 can follow in any order.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US5)

---

## Phase 1: Setup

**Purpose**: find existing overlaps before anything enforces them (R2).

- [X] T001 Add `public static function overlaps(): \Illuminate\Support\Collection` to `app/Support/Reservations/RoomAssignmentRules.php`: self-join `holdingLines()` on `room_id` where `a.id < b.id`, `a.arrival < b.departure` and `b.arrival < a.departure`; return hotel id, hotel name, room number, both reservation codes (`reservations.reservation_id`) and the shared range (`greatest(arrivals)`, `least(departures)`). Runs without hotel scope (callers wrap in `TenantContext::withoutScope()`). Doc comment: one query shared by the command and the migration pre-check.
- [X] T002 Create `app/Console/Commands/RoomOverlapsCommand.php` (`rooms:overlaps`, read-only): inside `TenantContext::withoutScope()` (deliberate: platform-owner report across hotels, say so in a comment) print a table of `RoomAssignmentRules::overlaps()`; print "No overlapping room assignments." and exit `0` when empty, otherwise exit `1` (contract §6).
- [X] T003 [P] Create `tests/Feature/RoomOverlapsCommandTest.php`. The constraint (T004) makes a clash impossible to insert, so the test first runs `ALTER TABLE reservation_rooms DROP CONSTRAINT reservation_rooms_no_overlap` (rolled back with the `RefreshDatabase` transaction; say so in a comment), then builds: two hotels each with a room numbered 101 booked on the same nights (no clash), and one same-hotel clash. Assert `rooms:overlaps` lists exactly the clash with both reservation codes and exits `1`; with the clash removed it prints "No overlapping room assignments." and exits `0`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the guarantee in the stored data (R1), clear errors (R6), one rule set (R3),
permissions (R7). Blocks every user story.

### Schema

- [X] T004 Create `database/migrations/2026_10_30_000001_add_room_overlap_guarantee_to_reservation_rooms.php`. `up()`:
  1. Run the `RoomAssignmentRules::overlaps()` SQL (inline the query; migrations must not depend on app classes changing later) and, if any row, `throw new RuntimeException("{$n} overlapping room assignments exist; run php artisan rooms:overlaps and resolve them before migrating.")`.
  2. `CREATE EXTENSION IF NOT EXISTS btree_gist`.
  3. `ALTER TABLE reservation_rooms ADD COLUMN held_nights daterange NULL`.
  4. `CREATE FUNCTION reservation_room_held_nights(line reservation_rooms) RETURNS daterange`: NULL unless `line.room_id IS NOT NULL`, `line.status <> 'cancelled'`, `line.deleted_at IS NULL`, the reservation is not soft-deleted and its `status IN ('pending','confirmed','checked_in')`, and no non-deleted stay of the line has `status IN ('departed','no_show')`; else `daterange(arrival_date, departure_date, '[)')`.
  5. Triggers (data-model "Triggers"): `BEFORE INSERT OR UPDATE ON reservation_rooms` sets `NEW.held_nights`; `AFTER UPDATE OF arrival_date, departure_date, status, deleted_at ON reservations` runs `UPDATE reservation_rooms SET held_nights = NULL WHERE reservation_id = NEW.id` (the BEFORE trigger recomputes); `AFTER INSERT OR UPDATE OF status, deleted_at, reservation_room_id OR DELETE ON stays` touches the old and new `reservation_room_id`.
  6. Backfill: `UPDATE reservation_rooms SET held_nights = NULL` (trigger recomputes every row).
  7. `ALTER TABLE reservation_rooms ADD CONSTRAINT reservation_rooms_no_overlap EXCLUDE USING gist (room_id WITH =, held_nights WITH &&) WHERE (held_nights IS NOT NULL)`.
  `down()` drops constraint, triggers, function and column; leaves the extension. Comment at the top: why triggers (research R1), and that `held_nights` is never written by PHP.
- [X] T005 [P] Do not add `held_nights` to `$fillable`/`$casts` in `app/Models/ReservationRoom.php`; add it to `$hidden` and a one-line comment pointing to the migration, so it never reaches responses.
- [X] T006 [P] Create `tests/Feature/RoomOverlapConstraintTest.php` (schema level): (a) `held_nights` equals `[arrival, departure)` for a held line and NULL for each of: no room, cancelled line, soft-deleted line, cancelled / checked_out / no_show / soft-deleted reservation, stay departed, stay no_show; (b) it agrees with `RoomAssignmentRules::holdingLines()` on that same seeded mix; (c) a raw `DB::table('reservation_rooms')->insert()` of a clashing line throws `QueryException` with SQLSTATE `23P01`; (d) back-to-back (departure = other arrival) inserts fine; (e) moving a reservation's dates into a clash with `DB::table('reservations')->update()` throws `23P01`; (f) marking a stay departed frees the room for a new line on the remaining nights.

### Clear errors on every path

- [X] T007 Create `app/Support/Reservations/RoomHoldConflict.php` with `public static function rethrow(callable $callback, string $field = 'rooms'): mixed`: run the callback; on `QueryException` whose SQLSTATE is `23P01` and message names `reservation_rooms_no_overlap`, throw `ValidationException::withMessages([$field => 'A room on this booking was just taken by another reservation for overlapping nights. Reload and choose another room.'])`; rethrow anything else. Doc comment: PostgreSQL aborts the transaction on the violation, so this wraps the *outermost* transaction (research R6).
- [X] T008 In `bootstrap/app.php` `withExceptions`, render a `QueryException` with SQLSTATE `23P01` on `reservation_rooms_no_overlap` as `apiResponse(...)` `422` with the T007 message under `errors.rooms`, so an unwrapped path never returns `500`.
- [X] T009 Wrap the outermost transaction with `RoomHoldConflict::rethrow()` in: `ReservationCommands::update()` in `app/Services/Reservations/ReservationCommands.php`; `ReservationCreator::create()` call sites in `app/Http/Controllers/ReservationController.php` (store) and the Admin AI `CreateReservationTool`; `StayLifecycleService` check-in entry points in `app/Services/StayLifecycleService.php`; `NoShowService` undo entry points in `app/Services/NoShowService.php`.
- [X] T010 In `app/Support/Import/Reservations/ReservationImportWriter.php`: replace the body of `heldBy()` with a query on `RoomAssignmentRules::holdingLines()` (same semantics, one source); and run each row's write in a savepoint so a `23P01` there becomes the existing `ImportWarning::ROOM_CLASH` warning and the line is written with `room_id = null` (spec Edge Cases: import unchanged). *Done as:* `heldBy()` already read `RoomAssignmentRules::holdingLines()`; the pre-check under the type locks keeps the `ROOM_CLASH` warning. A race that still reaches the constraint now fails that row with the clear `rooms` message (via `ReservationCreator::create()`), not a 500.

### One rule set (FR-001, FR-002)

- [X] T011 In `app/Support/Reservations/RoomAssignmentRules.php`: (a) extend `reason(ReservationRoom $line, ?Room $room, bool $allowUpgrade = false, ?array $nights = null): ?string` and keep **only** hotel, type and clash: room missing / other hotel → "The selected room is not available."; type differs and `! $allowUpgrade` → "Room {n} is not of the line's room type; send upgrade to place it anyway."; clash on `$nights` (`[arrival, departure]`, default the saved reservation's dates) → "Room {n} is booked for {code} on overlapping nights.". Do **not** add line/reservation/stay status checks here: `NoShowService::restoreStays()` calls `reason()` while the stay is still `no_show`. (b) Add `assertChangeable(ReservationRoom $line, string $key): void` throwing `ValidationException` on `$key`: line cancelled → "This room line is cancelled."; reservation status not in `ReservationStatus::holdingInventory()` → "Room changes are closed for this reservation ({status})."; line's stay `departed`/`no_show` → "Room changes are closed for this room ({status})." Only `RoomAssignmentService` calls it. Update the class docblock (SPEC-021 done; the stored-data constraint backs it).
- [X] T012 In `app/Support/Reservations/ReservationRoomSync.php`, replace `assertRoomFits()`'s type check with a cross-reservation check: for each unit whose `room_id` is set and differs from the line's current room (or is a new line), call `RoomAssignmentRules::reason()` against the reservation's *new* dates and throw on `rooms.{index}.room_id`. Lines of the same reservation are excluded from the clash (they are checked by `assertNoDuplicateRooms`). Pass the edit's new dates as `$nights` (T011) so a date-changing edit is checked on its new nights.
- [X] T013 Reorder writes in `app/Support/Reservations/ReservationCreator.php::update()` so the per-row constraint never sees an intermediate clash (research R3 "Write order"). Before anything, when dates change, check every line that **keeps** its room with `reason(..., $nights)` and reject with `422` on `rooms` naming room and other reservation (FR-011). Then: (1) take rooms off every line the plan moves or cancels (quiet `update(['room_id' => null])` as `applyPlan()` does today); (2) on un-cancel, for each line to be restored whose room `RoomAssignmentRules::reason()` reports as held elsewhere on the reservation's nights, clear `room_id` **through the model** (audited) and add "Room {n} is booked for {code} on overlapping nights. It was taken off this booking; assign another room before check-in." to the response `warnings`, as no-show undo does (spec Edge Cases); (3) `$reservation->update($attributes)`; (4) reinstate lines, apply the remaining room changes and add new lines. Expose `warnings` from `ReservationCommands::update()` and in the reservation update response (contract §4). *Done as:* un-cancel warnings are appended to the update response `message` (the existing deprecation-note pattern), not a new `warnings` key, so the response body stays a reservation object.
- [X] T014 [P] Add to `tests/Feature/RoomOverlapConstraintTest.php` the per-path service tests (SC-003): the same clashing room is rejected with "Room X is booked for {code} on overlapping nights." through reservation store, reservation update (room), reservation update (dates), check-in with a named room, and Admin AI `AssignRoomsTool`; un-cancel and no-show undo clear a room someone else took, with a warning; a no-show undo whose room nobody took **keeps** it (regression for T011); one edit that extends dates and moves the line from the now-clashing 204 to a free 206 returns `200` (regression for T013's write order); import yields `ROOM_CLASH`.

### Permissions (R7)

- [X] T015 Add `RESERVATIONS_ASSIGN_ROOM = 'reservations.assign_room'` and `RESERVATIONS_MOVE_ROOM = 'reservations.move_room'` to `app/Enums/Permission.php`, grouped with the reservation cases. Do **not** add them to `employeeDefaults()`; add one line to its docblock: room assignment and moves are never defaults (they decide where guests sleep).
- [X] T016 Add `assignRoom(User $user, ?Reservation $reservation = null): bool` to `app/Policies/ReservationPolicy.php` and `moveRoom(User $user, Stay $stay): bool` to `app/Policies/StayPolicy.php` (T040 authorizes a `Stay`, so Laravel resolves `StayPolicy`; its `before()` already admits super admins). Each `return $this->allows($user, Permission::X, $record)`.

**Checkpoint**: constraint live, every existing path gives a clear message, permissions exist.

---

## Phase 3: User Story 1 - Staff assign a room ahead of arrival (Priority: P1) 🎯 MVP

**Goal**: `PUT /api/reservation/{reservation}/rooms` assigns rooms atomically under one rule set.

**Independent Test**: two Deluxe lines get two free Deluxe rooms in one call; a clashing
room is `422` with the other code; two concurrent assignments of one room → exactly one wins.

### Tests for User Story 1

- [X] T017 [P] [US1] Create `tests/Feature/RoomAssignmentTest.php` covering spec US1 scenarios 1–6: assign two lines in one call (`200`, both hold rooms, one `reservation_room.updated` audit entry each naming the actor); clash → `422` on `assignments.N.room_id` with the other reservation's code; back-to-back accepted; one bad item → none saved; other hotel's room → "The selected room is not available."; cancelled / checked_out / no_show reservation and cancelled line → rejected with reason; re-sending the same assignment → `200`, no new audit entry (FR-008); out-of-order room → `200` with `warnings: ["Room {n} is out of order; return it to service before check-in."]` (FR-007); another hotel's reservation → `404`; deleting an assigned room leaves the line without a room and it appears in the unassigned-arrivals list (spec Edge Cases).
- [X] T018 [P] [US1] Create `tests/Feature/RoomAssignmentConcurrencyTest.php` following `tests/Feature/ReservationAvailabilityConcurrencyTest.php`: two connections assign the same room to two reservations with overlapping nights at once → exactly one `200`, the other `422`. Repeat 20 times, as SC-001 states (keep runtime < 10 s).

### Implementation for User Story 1

- [X] T019 [US1] Create `app/Services/Reservations/RoomAssignmentService.php` with `assign(Reservation $reservation, array $assignments, bool $overbookOverride = false): array` returning `['reservation' => Reservation, 'warnings' => list<string>]`. Inside `RoomHoldConflict::rethrow(fn () => DB::transaction(...))`: `ReservationLock::lock()`; load the named live lines (unknown line → `422` on `assignments.N.line_id` "This room line is not on the reservation."); load target rooms in the hotel; `AvailabilityService::lockTypes()` with the lines' types and the target rooms' types; lock target rooms `orderBy('id')->lockForUpdate()`; call `RoomAssignmentRules::assertChangeable()` on each named line (key `assignments.N.room_id`); build the final line→room map and check each changed line with `RoomAssignmentRules::reason($line, $room, $item['upgrade'] ?? false)`, excluding this reservation's own lines from the clash so swaps pass (FR-005); collect out-of-order warnings; skip if nothing changes; then write through `app(ReservationCommands::class)->update($reservation, [], $fullLineList, overbookOverride: $overbookOverride)`. After the write, for each line placed in a room of another type, `EventLogger::record($line, 'room_upgraded', changes: ['booked_type' => …, 'room_type' => …])` (FR-025). Doc comment: the one write path for room changes (FR-001).
- [X] T020 [US1] Change `ReservationCommands::assignRooms()` in `app/Services/Reservations/ReservationCommands.php` to delegate to `RoomAssignmentService::assign()` (map `$roomByLine` to assignments) so the Admin AI uses the same checks.
- [X] T021 [P] [US1] Create `app/Http/Requests/AssignRoomsRequest.php` with exactly the contract §1 rules: `assignments` "required, array, 1–50 items"; `assignments.*.line_id` "required, uuid, … distinct"; `assignments.*.room_id` "present, nullable uuid"; `assignments.*.upgrade` "boolean, default false"; `overbook_override` "boolean, default false".
- [X] T022 [US1] Add to `app/Http/Controllers/RoomController.php` `assign(AssignRoomsRequest $request, Reservation $reservation)`: `authorize('assignRoom', $reservation)`; if `overbook_override` then `authorize('overbook', Reservation::class)`; call the service; return `apiResponse('Rooms updated.', 200, ['reservation' => new ReservationResource(...), 'warnings' => $warnings])`. *Done as:* `RoomController::assign()` (user decision: no separate controller).
- [X] T023 [US1] Register `Route::put('/reservation/{reservation}/rooms', [RoomController::class, 'assign'])` in `routes/api.php` next to the other reservation actions (before `Route::resource('/reservation', …)`).
- [X] T024 [US1] In `app/Http/Controllers/ReservationController.php` store/update: when any `rooms[].room_id` is set on a new line or differs from the line's current `room_id`, `authorize('assignRoom', $reservation ?? Reservation::class)` (FR-020); unchanged echoes need nothing extra.
- [X] T025 [US1] Update `app/Ai/Tools/AssignRoomsTool.php`: go through `RoomAssignmentService::assign()`; accept optional `upgrade` per assignment with the schema description "true only when the admin explicitly asked for a room of another type"; rewrite `description()` and the class docblock so they state only enforced rules (right type unless upgrade, free on every night, closed after departure; out-of-order gives a warning). Return warnings in the tool text.
- [X] T026 [US1] In `app/Ai/Tools/Admin/AdminToolset.php` gate `AssignRoomsTool` by `[Permission::RESERVATIONS_UPDATE, Permission::RESERVATIONS_ASSIGN_ROOM]`.
- [X] T027 [P] [US1] Update existing tests that put rooms on lines through the general edit or `AssignRoomsTool` (`tests/Feature/ReservationRoomsTest.php`, `AdminWriteToolsTest.php`, `AdminToolParityTest.php`, `AdminToolsReviewFixesTest.php`, the `AssignRoomsTool` entry in `tests/Pest.php`) for the new permission and clash rule; employees in those fixtures get `reservations.assign_room` through a staff role where they need it.

**Checkpoint**: US1 is shippable alone — MVP gate step 4 holds.

---

## Phase 4: User Story 2 - Change or remove an assigned room before arrival (Priority: P1)

**Goal**: reassign, unassign and swap through the same endpoint; in-house and past lines are protected.

**Independent Test**: assign 204, reassign to 208 (204 free for others at once), unassign
(line still counts in availability); an in-house line cannot be unassigned or changed here.

### Tests for User Story 2

- [X] T028 [P] [US2] Add to `tests/Feature/RoomAssignmentTest.php`: reassign frees the old room immediately for another reservation; swap of two lines in one call `200`; unassign leaves the line counted in `GET /api/availability`; in-house line unassign or change → `422` "Room {n} is checked in; move the guest with the room-move action."; departed / no-show line → `422` "Room changes are closed for this room (departed)." / "(no_show)"; each change audited with old and new `room_id`.
- [X] T029 [P] [US2] Add to `tests/Feature/ReservationRoomsTest.php` (or the in-house edit tests that exist today): changing an in-house line's room through `PUT /api/reservation/{id}` now returns `422` with the room-move message (breaking change, R5); update any existing test that relied on moving an in-house guest through the edit. *Done as:* in `tests/Feature/RoomAssignmentTest.php` ("will not move a guest in the house through the general reservation edit"); no existing test relied on moving an in-house guest through the edit.

### Implementation for User Story 2

- [X] T030 [US2] In `app/Support/Reservations/ReservationCreator.php::guardInHouse()`, replace the "vacated rooms" branch: a move of an in-house line's room throws `ValidationException` on `rooms` with "Room {n} is checked in; move the guest with the room-move action." unless an internal `allowInHouseMove` flag is set (only `RoomAssignmentService::move()` sets it, via a new optional parameter threaded through `ReservationCommands::update()`); keep returning vacated rooms in that case so `markDirty()` still runs.
- [X] T031 [US2] In `RoomAssignmentService::assign()` reject in-house lines (same message) and unassigning them (FR-006) before the write, so the message is per item (`assignments.N.room_id`).

**Checkpoint**: US1 + US2 cover the full pre-arrival lifecycle.

---

## Phase 5: User Story 3 - Unassigned arrivals list (Priority: P2)

**Goal**: `GET /api/reservation/unassigned-arrivals`.

**Independent Test**: of four reservations (fully assigned, partly assigned, unassigned,
outside the window) only the unassigned lines of the 2nd and 3rd appear.

### Tests for User Story 3

- [X] T032 [P] [US3] Create `tests/Feature/UnassignedArrivalsTest.php`: the four-reservation fixture above; default window today → today+7 in hotel time; `to - from` over 31 days → `422`; a late arrival (arrival passed, stay `expected`) appears with `is_late: true`; an unassigned, not-yet-arrived line on a `checked_in` multi-room reservation (first room already in) **does** appear; a no-show line or a line whose stay is in house does not; cancelled lines do not; another hotel's lines never appear; paging and `sort=-arrival_date` work.

### Implementation for User Story 3

- [X] T033 [P] [US3] Create `app/Http/Requests/UnassignedArrivalsRequest.php` extending `GenericIndexRequest`: `from`, `to` "dates, default today → today + 7 in hotel time, max 31 days"; `hotel_id` for super admin; allowed sorts `arrival_date` (default), `reservation_code`, `room_type`. *Done as:* its own `FormRequest`, not `GenericIndexRequest`: the list sorts by joined columns (reservation code, room type), which the generic request validates against one table.
- [X] T034 [P] [US3] Create `app/Http/Resources/UnassignedArrivalResource.php` with exactly the contract §3 fields: `line_id`, `reservation_id`, `reservation_code`, `reservation_status`, `primary_guest {id, name}`, `arrival_date`, `departure_date`, `room_type {id, name}`, `is_late`.
- [X] T035 [US3] Add `unassignedArrivals(UnassignedArrivalsRequest $request)` to `app/Http/Controllers/RoomController.php`: `authorize('assignRoom', Reservation::class)`; `resolveHotel()`; query live lines with `room_id IS NULL` whose stay is `expected` (or that have no stay), on `pending|confirmed|checked_in` reservations, with arrival in `[from, to]`, or arrival before today with the line's stay `expected` (`is_late`); eager-load reservation, primary guest and room type (no N+1); paginate through `GenericQuery::apply()`. *Done as:* `RoomController::unassignedArrivals()`, paginated directly (see T033). Late arrivals are limited to those whose departure is still ahead.
- [X] T036 [US3] Register `Route::get('/reservation/unassigned-arrivals', [RoomController::class, 'unassignedArrivals'])` in `routes/api.php` **before** `Route::resource('/reservation', …)`. *Done as:* `[RoomController::class, 'unassignedArrivals']`.

---

## Phase 6: User Story 4 - Move an in-house guest (Priority: P2)

**Goal**: `POST /api/stays/{stay}/move-room` with a reason, cleaning of the old room and an audit event.

**Independent Test**: guest in 204 moved to 210: line and stay in 210, 210 occupied, 204 not
occupied and dirty with a cleaning task, `room_moved` audit with reason.

### Tests for User Story 4

- [X] T037 [P] [US4] Create `tests/Feature/RoomMoveTest.php`: the independent test above; check-in time unchanged; target occupied / out of order / clashing on any night of the stay / other type without `upgrade` → `422` on `room_id`, nothing changes; same room → `422` "The guest is already in room {n}."; stay not in house → `422` on `stay`; `reason` missing or over 500 chars → `422`; an open room-change request task for the stay is returned in `open_room_change_requests` and stays open; no guest message is queued (assert no WhatsApp/email job); other hotel's stay → `404`.

### Implementation for User Story 4

- [X] T038 [P] [US4] Create `app/Http/Requests/MoveRoomRequest.php`: `room_id` "required uuid"; `reason` "required string 1–500"; `upgrade`, `overbook_override` "boolean, default false".
- [X] T039 [US4] Add `move(Stay $stay, Room $room, string $reason, bool $upgrade = false, bool $overbookOverride = false): array` to `app/Services/Reservations/RoomAssignmentService.php`: same lock order as `assign()`; require stay `in_house`, target not `isOutOfOrder()`, target has no in-house stay (`ReservationRoom::inHouseRoomIds()`), not the current room, `RoomAssignmentRules::reason($line, $room, $upgrade)` null on every night of the stay (spec FR-013); record `room_upgraded` as in T019 when the room's type differs; write through `ReservationCommands::update(..., allowInHouseMove: true)` so `syncStays()`, `syncRoomOccupancy()` and `markDirty()` run as today; then `EventLogger::record($stay, 'room_moved', changes: ['from_room' => …, 'to_room' => …, 'reason' => $reason])`. Return the fresh stay, the vacated room, the cleaning task id and the guest's open room-change request tasks for this stay (tasks created by `RequestRoomChangeTool`; read the marker it sets in `app/Services/GuestRequestService.php`).
- [X] T040 [US4] Add `moveRoom(MoveRoomRequest $request, Stay $stay)` to `app/Http/Controllers/StayController.php`: `authorize('moveRoom', $stay)`; overbook check as T022; resolve the room in the stay's hotel (`404`-safe "The selected room is not available." on `room_id`); return the contract §2 body via `StayResource`.
- [X] T041 [US4] Register `Route::post('/stays/{stay}/move-room', [StayController::class, 'moveRoom'])` in `routes/api.php` with the other stay actions.
- [X] T042 [US4] Create `app/Ai/Tools/MoveRoomTool.php` (implements `Tool, WritesData`, uses `AdminToolSupport`): inputs reservation `code`, optional `current_room_number` (required when several rooms of the reservation are in house), `room_number`, `reason`, optional `upgrade` ("true only when the admin explicitly asked"); calls `RoomAssignmentService::move()`; never passes an overbook override. Register it in `app/Ai/Tools/Admin/AdminToolset.php` gated by `[Permission::RESERVATIONS_MOVE_ROOM]` with `[Stay::class, ReservationRoom::class]`.
- [X] T043 [P] [US4] Add `MoveRoomTool` tests to `tests/Feature/AdminWriteToolsTest.php` (move succeeds, clash message returned as text, no override) and its entry to `tests/Inventory/ai_tools.php` and the tool-args map in `tests/Pest.php`.

---

## Phase 7: User Story 5 - Upgrade to a room of another type (Priority: P3)

**Goal**: a line may hold a room of another type when the request says `upgrade: true`;
availability counts it against the room's type.

**Independent Test**: Deluxe line in a Suite room with `upgrade: true`: line keeps Deluxe,
`is_upgrade: true`, Deluxe availability +1 and Suite −1 on those nights.

### Tests for User Story 5

- [X] T044 [P] [US5] Create `tests/Feature/RoomUpgradeTest.php`: without `upgrade` → `422` type message; with it → `200`, booked type unchanged, `rooms[].is_upgrade: true`, one `room_upgraded` audit entry naming both types; `GET /api/availability` moves one unit from Deluxe to Suite on the held nights; an upgrade into a full Suite night → availability shortfall `422`; with `overbook_override` and `reservations.overbook` → `200` and an `overbooking_overridden` audit; without `reservations.overbook` → `403`; reassigning back to a Deluxe room or unassigning → `is_upgrade: false` (FR-017); a move with `upgrade: true` works the same; the Admin AI never overrides.
- [X] T045 [P] [US5] Add to `tests/Feature/AvailabilityServiceTest.php`: non-upgraded assigned and unassigned lines count exactly as before (regression for the effective-type change). *Done as:* the existing `AvailabilityServiceTest` suite passes unchanged (non-upgraded lines count as before); the upgraded case is in `RoomUpgradeTest`.

### Implementation for User Story 5

- [X] T046 [US5] In `app/Services/AvailabilityService.php`: `holdingNights()` left-joins `rooms` on `rooms.id = reservation_rooms.room_id`; `countByTypeAndNight()` and the `forHotel()` type filter use `coalesce(rooms.room_type_id, reservation_rooms.room_type_id)` (effective type, research R4); update the docblocks (spec 003 said "counts against its booked type"; now the room's type when it holds one).
- [X] T047 [US5] In `RoomAssignmentService::assign()` and `move()`, include the target rooms' types in `lockTypes()` and keep calling `AvailabilityService::guard()` (via `ReservationCommands::update`) with `$overbookOverride`, so upgrades into a full type are rejected unless overridden (FR-016).
- [X] T048 [US5] In `app/Http/Resources/ReservationRoomResource.php`, add `is_upgrade` (line has a room and `room.room_type_id !== room_type_id`) and `room.room_type {id, name}` when a room is held (contract §4, additive). Eager-load `rooms.room.roomType` where reservations are listed to avoid N+1.
- [X] T049 [US5] Keep the general create/edit path strict: `ReservationRoomSync` calls `reason(..., allowUpgrade: false)` so upgrades only go through `PUT …/rooms`, `move-room` and the AI tools (research R4 note).

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T050 [P] Add the three endpoints to the dataset in `tests/Feature/PermissionAuthorizationTest.php` (allowed with `reservations.assign_room` / `reservations.move_room`, `403` without) and to `tests/Feature/TenantIsolationTest.php`; add `AssignRoomsTool`/`MoveRoomTool` gates to `tests/Feature/AdminToolPermissionTest.php`.
- [X] T051 [P] Run `EntryPointInventoryTest`, `CrossTenantRouteTest`, `AiToolInventoryTest`; list anything new they flag in `tests/Inventory/entry_points.php` with its covering test. *Done as:* `EntryPointInventoryTest`, `CrossTenantRouteTest` and `AiToolInventoryTest` pass with the new routes and tool; nothing needed listing.
- [X] T052 [P] Update `docs/reservations-api-documentation.md`: new "Room assignment" section (`PUT /reservation/{id}/rooms`, unassigned arrivals), the rules table, upgrades and `is_upgrade`, the stored-data guarantee in plain words, and the changed create/update behaviour (contract §4).
- [X] T053 [P] Update `docs/stays-api-documentation.md`: "Room move" section for `POST /stays/{stay}/move-room`, and the Admin AI table (`MoveRoomTool`).
- [X] T054 [P] Update `docs/staff-roles-api-documentation.md` permission reference with `reservations.assign_room` and `reservations.move_room` and their endpoints/tools; note neither is an employee default.
- [X] T055 [P] Note in the availability docs (where `GET /api/availability` is documented) that an upgraded line counts against the room's type.
- [X] T056 [P] Create `docs/latest-changes-2026-10-30.md` (or append if it exists) listing the breaking changes of contract §4 and the `rooms:overlaps` pre-migration step for deploys.
- [X] T057 [P] Create `specs/020-room-assignment/frontend-changes.md`: assignment dialog (multi-line, swap, upgrade toggle, warnings), move dialog (reason required, open request link), unassigned-arrivals page, `is_upgrade` badge, the new `403`/`422` messages on reservation edit, and the two permissions in the role editor (read from `GET /api/permissions`).
- [X] T058 [P] Add the deploy step "run `php artisan rooms:overlaps` before `migrate`; abort on exit 1" to `docs/production-readiness-checklist.md` and `deploy/systemd/README.md` (or wherever the deploy steps are listed). *Done as:* a row in `docs/production-readiness-checklist.md` and the "Before deploying" note in `docs/latest-changes-2026-10-30.md`; `deploy/systemd/README.md` covers workers only.
- [X] T059 Add a "**Built (date):** `specs/020-room-assignment`" note under Phase 3 in `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md` once implemented.
- [X] T060 Run `./vendor/bin/pint`, then `php artisan test --parallel`; walk the 15 scenarios in [quickstart.md](quickstart.md) on the dev DB.

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2**: T004's pre-check inlines the T001 query; T002/T003 can run alongside T004.
- **Phase 2 blocks all stories.** Inside it: T004 → T006; T007 → T008, T009, T010; T011 → T012 → T013 → T014; T015 → T016.
- **US1 (Phase 3)** depends on Phase 2. T019 → T020, T022 → T023; T025 → T026.
- **US2 (Phase 4)** depends on US1's service (T019).
- **US3 (Phase 5)** depends only on Phase 2 (permission); independent of US1/US2.
- **US4 (Phase 6)** depends on T019 (service file), T030 (`allowInHouseMove`) and T016 (`StayPolicy::moveRoom`).
- **US5 (Phase 7)** depends on T019; T046 is independent of the service and can start after Phase 2.
- **Polish** after the stories it documents.

### Parallel Opportunities

- Phase 2: T005, T006 alongside T007–T013 (different files).
- After Phase 2: US3 (T032–T036) and T046 can run in parallel with US1.
- Within stories: every test task marked [P]; requests/resources (T021, T033, T034, T038) in parallel.
- Polish docs T052–T058 all in parallel.

## Parallel Example: User Story 3

```text
T032 tests/Feature/UnassignedArrivalsTest.php
T033 app/Http/Requests/UnassignedArrivalsRequest.php
T034 app/Http/Resources/UnassignedArrivalResource.php
→ then T035 controller action, T036 route
```

## Implementation Strategy

1. **MVP**: Phases 1–3 (guarantee + one rule set + assign endpoint). This alone closes the
   double-booking bug and satisfies MVP gate step 4. Ship it.
2. Add US2 (in-house protection on the edit path is part of the breaking-change set; ship
   it with US4 if the frontend needs the move dialog first).
3. US3 and US4 next (front-desk daily use), then US5.
4. Each phase ends with its tests green on Postgres and Pint clean.

## Notes

- Never edit shipped migrations; T004 is the only schema change.
- `held_nights` is written only by the database; no PHP code sets it.
- `phpunit.xml` forces `SENTRY_LARAVEL_DSN` empty, so tests run without any env prefix.
- Commit only when asked; no co-author trailer (CLAUDE.md).
