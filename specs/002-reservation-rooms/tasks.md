---

description: "Task list for SPEC-010 Reservation Rooms"
---

# Tasks: Reservation Rooms

**Input**: Design documents from `specs/002-reservation-rooms/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/reservations-api.md](contracts/reservations-api.md), [quickstart.md](quickstart.md)

**Tests**: Included. The constitution (VIII) requires Pest coverage, a tenant-isolation test
for new tenant-scoped data, and tests at the domain/tool boundary. Tests run against
Postgres (`Hospitality_Ecosystem_testing`), never SQLite.

**Organization**: Grouped by user story from spec.md. US1 and US2 are both P1 and ship in
the same release, because the API break and the column drop are one change (plan §Deploy
note).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1–US5 from spec.md
- Paths are repository-relative (single Laravel app)

## Conventions every task follows

- Controllers return `apiResponse()`; bodies go through Resources; models use `HasUuids`,
  `$keyType = 'string'`, `$incrementing = false`, `SoftDeletes`, explicit `$fillable`/`$casts`.
- Queries that run without tenant context (migrations, jobs, occupancy) name the hotel or
  use `withoutGlobalScope('hotel')` on purpose.
- Test files: `uses(RefreshDatabase::class)`, `beforeEach` sets `putenv('API_KEY=test-api-key')`
  and `config(['app.api_key' => 'test-api-key'])`, requests send `X-API-KEY`.
- Run `./vendor/bin/pint` before any commit.

---

## Phase 1: Setup

**Purpose**: Confirm a green baseline and fix the migration filenames.

- [X] T001 Start Postgres (`docker compose -f .postgres/compose.yaml up -d`), run `php artisan test`, and record any tests that already fail under "Baseline" in the Notes section of specs/002-reservation-rooms/tasks.md, so later failures can be attributed
- [X] T002 Pick the migration date prefix for this feature (implementation date, sorting after `2026_09_23_000003_migrate_room_type_column_to_foreign_key.php`) and use it for the three files named `<prefix>_000000_create_reservation_rooms_table.php`, `<prefix>_000001_backfill_reservation_rooms.php` and `<prefix>_000002_drop_room_id_from_reservations_table.php` in database/migrations/

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The reservation-room data layer every story uses.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T003 [P] Create string-backed enum `ReservationRoomStatus` with cases `RESERVED = 'reserved'` and `CANCELLED = 'cancelled'` in app/Enums/ReservationRoomStatus.php; docblock: "later specs (assignment, check-in/out, no-show) add cases"
- [X] T004 [P] Create migration database/migrations/<prefix>_000000_create_reservation_rooms_table.php: `uuid('id')->primary()`; `foreignUuid('hotel_id')->constrained()->cascadeOnDelete()`; `foreignUuid('reservation_id')->constrained()->cascadeOnDelete()`; `foreignUuid('room_type_id')->constrained('room_types')->restrictOnDelete()`; `foreignUuid('room_id')->nullable()->constrained()->nullOnDelete()`; `string('status')->default('reserved')`; `timestamps()`; `softDeletes()`; indexes `(reservation_id, status)`, `(hotel_id, room_id)`, `(hotel_id, room_type_id)`; plus `DB::statement` partial unique index `reservation_rooms_reservation_room_unique ON reservation_rooms (reservation_id, room_id) WHERE room_id IS NOT NULL AND status <> 'cancelled' AND deleted_at IS NULL`; down() drops the table
- [X] T005 Create model app/Models/ReservationRoom.php (depends on T003, T004): traits `BelongsToHotel, HasUuids, RecordsEvents, SoftDeletes`; `$fillable = ['hotel_id','reservation_id','room_type_id','room_id','status']`; `$casts = ['status' => ReservationRoomStatus::class]`; `$attributes = ['status' => 'reserved']`; `eventLoggedAttributes()` returns `['room_type_id','room_id','status']`; relations `reservation()`, `roomType()` (`->withTrashed()`), `room()` (`->withTrashed()`); `scopeActive()` (status ≠ cancelled); static `inHouseRoomIds(): Builder` that returns a `withoutGlobalScope('hotel')` query selecting `reservation_rooms.room_id` from active, non-deleted lines with non-null `room_id` joined to `stays` on `stays.reservation_id = reservation_rooms.reservation_id` where `stays.status = StayStatus::IN_HOUSE` (usable as a `whereIn` subquery)
- [X] T006 Add to app/Models/Reservation.php: `reservationRooms(): HasMany` ordered by `created_at` then `id`, and `primaryRoomId(): ?string` returning the `room_id` of the first active line that has one (research R10). Leave `room_id`/`room()` in place; they are removed in US2 (T031)
- [X] T007 [P] Create app/Http/Resources/ReservationRoomResource.php returning `id, reservation_id, room_type_id, room_id, status, room_type (RoomTypeResource whenLoaded), room (RoomResource whenLoaded), created_at, updated_at`
- [X] T008 Add a fixture helper to tests/Pest.php, next to the existing `roomTypeIdFor()` (reuse that helper for room types; if a test needs specific capacities, extend `roomTypeIdFor()` with an optional `array $overrides = []` rather than adding a second helper): `createReservationWithRooms(Hotel $hotel, array $lines, array $attributes = []): Reservation`, which creates the reservation with `Reservation::create` and each line with `ReservationRoom::create` (fixture path; bypasses the domain operation). `$lines` items are `['room_type_id' => …, 'room_id' => ?, 'status' => ?]`
- [X] T009 [P] Add a `ReservationRoom` case to tests/Feature/TenantIsolationTest.php following its existing pattern: a line created for Hotel A is not returned by `ReservationRoom::query()` for a Hotel B user, and `hotel_id` is stamped on create

**Checkpoint**: The table, model, relations and fixtures exist. The existing suite is still green because nothing reads lines yet.

---

## Phase 3: User Story 1 - Staff book several rooms of different types in one reservation (Priority: P1) 🎯 MVP

**Goal**: Create reservations from room-type lines with quantities. Occupancy, stay and the overnight job read lines.

**Independent Test**: POST "2 × Deluxe, 1 × Suite" with no rooms → three `reserved` unassigned lines, `room_summary` Deluxe 2 / Suite 1 (quickstart 1–6, 17–18).

### Tests for User Story 1 ⚠️ write first, confirm they fail

- [X] T010 [P] [US1] Create tests/Feature/ReservationRoomsTest.php with the create scenarios: US1-1 (three lines, all `room_id` null, `room_summary`); US1-2 (show returns lines with type/room/status); US1-3 `rooms: []` → 422 `errors.rooms`; US1-4 inactive, soft-deleted and other-hotel type → 422 on `rooms.0.room_type_id` with message "The selected room type is not available." and no reservation row; US1-5 5 adults vs total `max_occupancy` 4 → 422 capacity message mentioning `capacity_override`; US1-6 same with `capacity_override: true` → 201 plus EventLog `reservation.capacity_overridden` with actor_kind `user` and `changes` `{adults, children, max_occupancy, adult_capacity}`; Σ quantity 51 → 422; `quantity: 2` with `room_id` → 422; top-level `room_id` → 422 "Use rooms[] instead."; one `reservation_room.created` event per line; a forced failure after the reservation insert leaves no reservation and no lines (FR-013)
- [X] T011 [P] [US1] Rewrite tests/Feature/ReservationRoomOccupancyTest.php onto lines (fixtures via T008 helper, API payloads via `rooms[]`) keeping every existing single-room assertion, and add: checked-in 3-room reservation → all 3 rooms `occupied`; status → `cancelled` releases them; `MakeRoomDirtyOvernightJob` dirties all 3; `Stay::occupiedRoomsOn()` returns 3 for a covered date; `stay.room_id` equals `primaryRoomId()`
- [X] T012 [P] [US1] Add filter tests to tests/Feature/ReservationRoomsTest.php: `filter[room_type_id]` and `filter[room_id]` return only reservations with an active line of that type/room (cancelled lines don't match); `sort=room_type_id` → 422

### Implementation for User Story 1

- [X] T013 [US1] Create app/Support/Reservations/ReservationRoomSync.php with: `expand(array $items): array` (quantity default 1, range 1–50; `room_id` only when quantity is 1; returns unit arrays `['room_type_id','room_id']`); `validateUnits(string $hotelId, array $units, int $offset = 0)` checks with explicit `hotel_id` and `withoutGlobalScope('hotel')` that each type is active, not soft-deleted and belongs to the hotel, and each `room_id` is a non-deleted room of the hotel whose `room_type_id` equals the unit's; rejects duplicate `room_id` among units; enforces "at most 50 non-cancelled room units"; `assertCapacity(int $adults, int $children, Collection $activeTypes, bool $override): bool` rejects when `adults + children > Σ max_occupancy` or `adults > Σ adult_capacity`, returns true when an override was applied, and ignores `$override` when `EventLogger::currentActorKind() === ActorKind::AI_AGENT`. All failures throw `ValidationException::withMessages()` keyed `rooms` or `rooms.{i}.{field}` with the messages in contracts/reservations-api.md §Errors
- [X] T014 [US1] Change `ReservationCreator::create(array $attributes, array $rooms, bool $capacityOverride = false, bool $skipCapacity = false): Reservation` in app/Support/Reservations/ReservationCreator.php: run everything in `DB::transaction()`; `unset($attributes['room_id'])`; expand and validate via ReservationRoomSync; on the soft-deleted-restore branch cancel the restored reservation's existing active lines before adding new ones; create one `ReservationRoom` per unit; run the capacity check unless `$skipCapacity` is true (used by the import only); when an override was applied call `EventLogger::record($reservation, 'capacity_overridden', changes: […])`; then `syncStay()` and `syncRoomOccupancy()` with the lines' room ids
- [X] T015 [US1] In app/Support/Reservations/ReservationCreator.php change `syncRoomOccupancy(Reservation $reservation)` to `syncRoomOccupancy(array $roomIds)` (unique, non-null) and make `syncRoomStatus()` decide "someone in-house" with `ReservationRoom::inHouseRoomIds()` instead of `stays.room_id`, keeping the rule "only an occupied room is ever released"; remove the `wasChanged('room_id')` logic; add `roomIdsOf(Reservation $reservation): array` (all lines, including cancelled)
- [X] T016 [P] [US1] In app/Services/StayService.php set `'room_id' => $reservation->primaryRoomId()` in `syncFromReservation()` and update the docblock (one stay per reservation until SPEC-023)
- [X] T017 [P] [US1] In app/Jobs/MakeRoomDirtyOvernightJob.php replace the `Stay`-based `$sleptInRoomIds` with `ReservationRoom::inHouseRoomIds()`, keeping the existing housekeeping-status exclusion and the docblock rules
- [X] T018 [P] [US1] In app/Models/Stay.php rewrite `occupiedRoomsOn()` to count active reservation lines (**with or without** a `room_id`, so an unassigned single-room booking still counts 1 as it does today) whose reservation's stay has status IN_HOUSE or DEPARTED and whose planned window covers `$date` (same `<=` arrival / strict `>` departure semantics), scoped to `$hotel->id`; update the docblock
- [X] T019 [P] [US1] Create app/Http/Requests/StoreReservationRequest.php extending `GenericStoreRequest`, merging `parent::rules()` with: `rooms` required|array|min:1|max:50; `rooms.*.room_type_id` required|uuid; `rooms.*.quantity` nullable|integer|min:1|max:50; `rooms.*.room_id` nullable|uuid; `capacity_override` sometimes|boolean; `room_id` prohibited with message "Use rooms[] instead."
- [X] T020 [P] [US1] Create app/Http/Requests/ReservationIndexRequest.php extending `GenericIndexRequest` so `filter.room_type_id` and `filter.room_id` are accepted as virtual filter keys but still rejected as `sort` columns (override `withValidator()`, or split the filter/sort column lists)
- [X] T021 [US1] Update app/Http/Controllers/ReservationController.php: `store()` uses `StoreReservationRequest`, passes `rooms` and `capacity_override` to `ReservationCreator::create()`, drops `rooms` from `guardHotelScopedReferences()` (ownership is now checked in ReservationRoomSync) and stops calling `syncRoomOccupancy()` (done inside create); `index()` uses `ReservationIndexRequest`, pulls `room_type_id`/`room_id` out of `filter`, applies `whereHas('reservationRooms', fn ($q) => $q->active()->where(...))`, then `GenericQuery::apply()`; index/store/show/update eager-load `['hotel', 'guest', 'reservationRooms.roomType', 'reservationRooms.room']`; **interim until T040**: in `update()` replace `ReservationCreator::syncRoomOccupancy($reservation)` with `ReservationCreator::syncRoomOccupancy(ReservationCreator::roomIdsOf($reservation))` so the endpoint keeps working after T015's signature change
- [X] T022 [US1] Update app/Http/Resources/ReservationResource.php: add `rooms` (`ReservationRoomResource::collection($this->whenLoaded('reservationRooms'))`) and `room_summary` (when loaded: active lines grouped by type → `[{room_type_id, room_type_name, quantity}]`). Keep `room_id`/`room` until T032
- [X] T023 [US1] Move the other `ReservationCreator::create()` callers to the new signature so the suite stays green, and delete their separate `ReservationCreator::syncRoomOccupancy($reservation)` calls (create() now syncs occupancy itself). In app/Imports/ReservationsImport.php (research R14, final behavior): a row with `room_number` → one line of that room's type with that room; a row with only `room_type` → one unassigned line of `RoomType::resolveFor($hotelId, $row['room_type'])`; neither → one line of `RoomType::resolveFor($hotelId)`; pass `skipCapacity: true`. In app/Ai/Tools/CreateReservationTool.php (interim, finished in T048): the legacy `room_number` becomes one line of that room's type; with no room, return "Which room type should I book?" and create nothing
- [X] T024 [US1] Update tests/Feature/ReservationControllerTest.php store/show/index cases to send `rooms[]` and assert `rooms`/`room_summary`; check the reservation store payload in tests/Feature/PermissionAuthorizationTest.php's dataset and switch it to `rooms[]`; move the reservation fixtures in tests/Feature/StayServiceTest.php and tests/Feature/HousekeepingOvernightTest.php onto `createReservationWithRooms()` now, because T016 and T017 make them read lines

**Checkpoint**: Multi-type reservations can be created and listed, and occupancy follows the lines. `php artisan test` is green, including the update endpoint (interim call in T021), the AI tool and the import. Fixtures that still set `reservations.room_id` directly keep passing until the column is dropped in US2 (T034).

---

## Phase 4: User Story 2 - Existing reservations keep working after the change (Priority: P1)

**Goal**: Backfill every legacy reservation into one line, drop `reservations.room_id`, and move every reader to lines.

**Independent Test**: Seed legacy reservations, run the backfill → one line each with the old room and type, the placeholder type where there was no room, room statuses unchanged, a re-run adds nothing (quickstart 7–8).

### Tests for User Story 2 ⚠️ write first, confirm they fail

- [X] T025 [P] [US2] Create tests/Feature/ReservationRoomsMigrationTest.php. Load the drop migration file (`require` returns the anonymous class) and call `down()` to restore `reservations.room_id`, seed legacy rows through `DB::table`, run the backfill migration's `up()` and assert; then call the drop migration's `up()` again. Postgres DDL is transactional, so `RefreshDatabase` rolls it back. Cases: US2-1 room 101 (Deluxe) → one line with Deluxe + 101; US2-2 soft-deleted room → line keeps it; US2-3 no room → line with the hotel's inactive "Unspecified (migrated)" type, created once per hotel; US2-5 room of another hotel → placeholder type, no room, counted in the report; soft-deleted and cancelled reservations get lines (cancelled → line `cancelled`); US2-4 second `up()` creates 0 lines; US2-6 every `rooms.status` identical before and after

### Implementation for User Story 2

- [X] T026 [US2] Create database/migrations/<prefix>_000001_backfill_reservation_rooms.php (research R11): with `DB::table` only (no models, scopes or events), `chunkById(500)` over **all** reservations, including soft-deleted, that have no row in `reservation_rooms`; left-join `rooms` on `rooms.id = reservations.room_id AND rooms.hotel_id = reservations.hotel_id` (trashed rooms included); type = that room's `room_type_id`, otherwise the hotel's placeholder type created on demand with `name 'Unspecified (migrated)'`, description "Created by the reservation-rooms migration for reservations without a room.", `max_occupancy 2, adult_capacity 2, child_capacity 0, base_price 0, is_active false`; line `status` = `cancelled` when the reservation's status is `cancelled`, else `reserved`; generated UUIDs and timestamps; after the loop `Log::info()` the per-hotel counts of reservations given the placeholder, and echo them when running in console; `down()` is a documented no-op (the create-table migration's `down()` removes the rows)
- [X] T027 [US2] Create database/migrations/<prefix>_000002_drop_room_id_from_reservations_table.php: `up()` drops the `room_id` foreign key and column from `reservations`; `down()` re-adds `foreignUuid('room_id')->nullable()->constrained()->nullOnDelete()` and fills it from each reservation's first active line with a room (ordered by `created_at`, `id`)
- [X] T028 [US2] In app/Models/Reservation.php remove `room_id` from `$fillable` and `eventLoggedAttributes()`, and delete the `room()` relation (FR-027)
- [X] T029 [P] [US2] Create app/Http/Requests/UpdateReservationRequest.php extending `GenericUpdateRequest` with `room_id` prohibited ("Use rooms[] instead."), and use it in `ReservationController::update()` in app/Http/Controllers/ReservationController.php. Keep `room_id` prohibited in StoreReservationRequest, since the schema-derived rules no longer mention it
- [X] T030 [P] [US2] In app/Http/Controllers/DashboardController.php change today's arrivals/departures eager-load from `room.roomType` to `reservationRooms.roomType` and `reservationRooms.room`; update tests/Feature/DashboardControllerTest.php fixtures and assertions
- [X] T031 [P] [US2] In app/Notifications/WhatsAppReservationCreatedNotification.php replace the single "Room" line with one line per room type ("2 × Deluxe — 101, unassigned"); update tests/Feature/CreationNotificationTest.php
- [X] T032 [US2] In app/Http/Resources/ReservationResource.php remove `room_id` and `room`; remove `'room'` from every eager-load in app/Http/Controllers/ReservationController.php
- [X] T033 [P] [US2] Move the read tools off `reservation->room`. app/Ai/Tools/GetReservationsTool.php and app/Ai/Tools/GetOwnReservationTool.php eager-load `reservationRooms.roomType`/`.room` and return `rooms: [{room_type, room_number|null, status}]` plus `room_summary: ["2 × Deluxe", …]` in place of `room_number`/`room_type`. app/Ai/Tools/CreateGuestServiceRequestTool.php uses `$this->reservation?->primaryRoomId()`
- [X] T034 [US2] Move every remaining test fixture that sets `reservations.room_id` onto `createReservationWithRooms()`: tests/Feature/AdminCreateToolsTest.php, tests/Feature/ReservationImportTest.php, tests/Feature/TransactionAttributionTest.php, tests/Feature/TransactionImportTest.php (only where `room_id` is on the reservation; `room_id` on stays, tasks and transactions stays as is)
- [X] T035 [US2] Search app/, routes/ and database/seeders/ for any remaining reservation `room_id` or `->room` use (`Grep "room_id|->room\b"`) and fix or confirm each hit belongs to stays, tasks or transactions; then run `php artisan migrate:fresh --seed` on the dev DB and the full suite

**Checkpoint**: The `room_id` column is gone, legacy data has lines, every reader uses lines, and the suite is green. US1 and US2 together are the shippable P1 increment.

---

## Phase 5: User Story 3 - Staff change the rooms on an existing reservation (Priority: P2)

**Goal**: Edit lines with desired-state semantics on `PUT /api/reservation/{id}`, following the rules for each reservation status.

**Independent Test**: A reservation with 2 Deluxe (one on room 101): add a Suite and drop the unassigned Deluxe → the 101 line is untouched, the new Suite line is added, and the dropped line is `cancelled` (quickstart 9–11, 16).

### Tests for User Story 3 ⚠️ write first, confirm they fail

- [X] T036 [P] [US3] Add update scenarios to tests/Feature/ReservationRoomsTest.php: US3-1 add line (existing lines keep id and room); US3-2 omitted line → `cancelled` and its room released; US3-3 removing the last line → 422 "A reservation needs at least one room; cancel the reservation instead."; US3-4 `rooms` on `checked_out`/`cancelled` → 422 naming the status; US3-5 checked-in add/cancel/type change → 422; US3-6 date change leaves lines as they are; `rooms` absent → lines unchanged; unknown or already-cancelled line `id` → 422 `rooms.{i}.id`; `room_type_id` differing from the existing line's → 422; status → `cancelled` cancels every line (FR-012); changing `adults` re-runs the capacity check; `reservation_room.updated` events for cancelled lines

### Implementation for User Story 3

- [X] T037 [US3] Add `diff(Reservation $reservation, array $items, ReservationStatus $statusBefore): array` to app/Support/Reservations/ReservationRoomSync.php, returning `['keep' => [...], 'create' => [...units], 'cancel' => [...lines]]`. Items with `id` must match an active line of this reservation, and any `room_type_id` sent must equal the line's. Items without `id` are expanded and validated like create (offset indexes for error keys). Rules follow data-model.md §"Line state rules": `pending`/`confirmed` allow add and cancel (not the last active line); `checked_in` rejects add, cancel and type change; `checked_out`/`cancelled` reject any `rooms` key. The resulting active count must be between 1 and 50
- [X] T038 [US3] Add `ReservationCreator::update(Reservation $reservation, array $attributes, ?array $rooms, bool $capacityOverride = false): Reservation` to app/Support/Reservations/ReservationCreator.php: one `DB::transaction()`; capture `$statusBefore`; update attributes; when `$rooms !== null`, apply the diff (create lines, set cancelled status); when the new status is `cancelled`, cancel all active lines; re-run the capacity check when lines, `adults` or `children` changed (audit an override as in T014); `syncStay()`; `syncRoomOccupancy()` with the rooms of every touched and cancelled line
- [X] T039 [US3] Extend app/Http/Requests/UpdateReservationRequest.php with `rooms` sometimes|array|min:1|max:50; `rooms.*.id` nullable|uuid; `rooms.*.room_type_id` required_without:rooms.*.id|uuid; `rooms.*.quantity` nullable|integer|min:1|max:50; `rooms.*.room_id` nullable|uuid; `capacity_override` sometimes|boolean
- [X] T040 [US3] Make `ReservationController::update()` in app/Http/Controllers/ReservationController.php call `ReservationCreator::update($reservation, $validated-without-rooms, $request->has('rooms') ? $request->input('rooms') : null, $request->boolean('capacity_override'))` and remove the direct `$reservation->update()`/`syncStay()`/`syncRoomOccupancy()` calls. Pass the raw `rooms` input so a `room_id: null` stays distinguishable from a missing key
- [X] T041 [US3] Update the update cases in tests/Feature/ReservationControllerTest.php to the new payload and response

**Checkpoint**: Lines can be edited safely; US1–US3 all pass.

---

## Phase 6: User Story 4 - Physical room on a line before dedicated assignment exists (Priority: P2)

**Goal**: Set, change or clear a room on a line (pending/confirmed), and room moves for checked-in guests (clarifications Q1, Q3).

**Independent Test**: Create a Deluxe line on room 101 → saved. Check in, move to Deluxe 105 → 101 `available`, 105 `occupied`, the move audited (quickstart 12–15).

### Tests for User Story 4 ⚠️ write first, confirm they fail

- [X] T042 [P] [US4] Add to tests/Feature/ReservationRoomsTest.php: US4-1 create with a matching `room_id`; US4-2 edit an unassigned line to set room 102; clear a room with `room_id: null` on a confirmed reservation; US4-3 room of another type → 422 `rooms.{i}.room_id`; room of another hotel or soft-deleted → 422; US4-4 checked-in 101 → 105 moves occupancy and logs `reservation_room.updated` with `room_id` old/new; US4-5 checked-in move to a Suite room → 422; clearing a room on a checked-in line → 422; US4-6 same room on two lines → 422; a direct `ReservationRoom::create` duplicating an active (reservation, room) pair throws `QueryException` (partial unique index)

### Implementation for User Story 4

- [X] T043 [US4] Extend `diff()` in app/Support/Reservations/ReservationRoomSync.php for `room_id` on kept items, using `array_key_exists('room_id', $item)` to tell "clear" from "unchanged": `pending`/`confirmed` may set, change or clear; `checked_in` may only change to a non-null room (room move); each new room must be a non-deleted room of the hotel whose type equals the line's; reject duplicates across the resulting active set
- [X] T044 [US4] In `ReservationCreator::update()` in app/Support/Reservations/ReservationCreator.php, add both the old and the new room id of every moved line to the `syncRoomOccupancy()` set, and save moved lines through the model so `RecordsEvents` logs `reservation_room.updated`

**Checkpoint**: The front desk can put guests in rooms and move them. Occupancy stays correct.

---

## Phase 7: User Story 5 - AI and imports create reservations the same way (Priority: P2)

**Goal**: The Admin AI books by room type through the same operation and appears as the AI actor in the audit log. Guest tools show only the guest's own rooms. The import stays idempotent.

**Independent Test**: Equivalent requests via the API, `CreateReservationTool` and the import produce identical lines and identical rejections (quickstart 21–22, SC-003).

### Tests for User Story 5 ⚠️ write first, confirm they fail

- [X] T045 [P] [US5] Extend tests/Feature/CreateReservationToolTest.php: `rooms: [{room_type: 'deluxe', quantity: 2}]` → two Deluxe lines (case-insensitive name); `reservation_room.created` events have actor_kind `ai_agent`; unknown type → returned message lists the hotel's active type names and nothing is created; inactive type → rejected; over capacity → rejected (no override possible); `room_number` with quantity 2 → rejected; legacy top-level `room_number` → one line of that room's type; neither `rooms` nor `room_number` → returns "Which room type should I book?" and creates nothing
- [X] T046 [P] [US5] Create tests/Feature/ReservationRoomsAiToolsTest.php: `GetOwnReservationTool` returns the guest's own `rooms` and `room_summary` only (US5-2); `GetReservationsTool` returns `rooms` per reservation; neither exposes another hotel's lines
- [X] T047 [P] [US5] Extend tests/Feature/ReservationImportTest.php: `room_number` row → one line of that room's type; `room_type`-only row → one unassigned line; neither → hotel default type; 9 adults into one double imports (no capacity check); importing the same file twice → second run skips every row, no duplicate lines (US5-4)

### Implementation for User Story 5

- [X] T048 [US5] Finish app/Ai/Tools/CreateReservationTool.php (research R13): schema `rooms` = array of objects `{room_type: string (required), quantity: integer (1–50, default 1), room_number: string (only when quantity is 1)}`; resolve `room_type` case-insensitively among the hotel's **active, non-deleted** types, never auto-creating (unlike `RoomType::resolveFor`); an unknown name returns "Unknown room type X. Available: …"; resolve `room_number` within the hotel; keep the legacy top-level `room_number`; with neither `rooms` nor `room_number`, return "Which room type should I book?" and create nothing (no silent default type); add a class docblock noting the tool has no permission check of its own and relies on `AdminAdvisorAgent` being admin-only, so any future non-admin agent must add a `reservations.create` check before using it; wrap the whole write in `EventLogger::asAiAgent()`; never pass a capacity override; catch `ValidationException` and return its first message; return JSON of the reservation with `reservationRooms.roomType` and `reservationRooms.room`; update `description()` to mention booking by room type and quantity
- [X] T049 [P] [US5] Update the reservation-tool wording in the instructions of app/Ai/Agents/AdminAdvisorAgent.php (around the "create a reservation" lines): the tool books room types with quantities; ask for the room type when it's missing; don't invent room numbers

**Checkpoint**: All five stories pass independently.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T050 [P] Room-type deletion guard (FR-028, research R12): in `destroy()` in app/Http/Controllers/RoomTypeController.php, after the rooms check, count distinct reservations with an active line of this type where the reservation is not `cancelled` and `departure_date >= today`; if the count is above 0, return 422 "Cannot delete room type: N current or upcoming reservations still use it. Deactivate it instead." with body `['error' => 'deletion_blocked_by_reservations', 'reservations' => N]`. Add tests to tests/Feature/RoomTypeControllerTest.php: blocked by an upcoming reservation, allowed when only past or cancelled lines use it
- [X] T051 [P] Extend tests/Feature/TenantIsolationTest.php: a Hotel B user cannot read Hotel A reservation lines through `GET /api/reservation/{id}`, `filter[room_type_id]`/`filter[room_id]` with Hotel A ids returns nothing, and create/update with a Hotel A room type or room → 422 with no hint that the record exists
- [X] T052 [P] Update docs/reservations-api-documentation.md from contracts/reservations-api.md: reservation object (`rooms`, `room_summary`, no `room_id`/`room`), create and update bodies and rules, virtual filters, the error table, the occupancy side-effect section rewritten for lines and room moves, import behavior, and the Summary for the Frontend
- [X] T053 [P] Update docs/room-types-api-documentation.md with the `deletion_blocked_by_reservations` rejection
- [X] T054 [P] Create docs/latest-changes-<implementation date>.md summarizing the breaking change: `room_id`/`room` → `rooms[]`/`room_summary`, the update desired-state semantics, `capacity_override`, the migration placeholder type, and the frontend slice (reservation form with room-type lines × quantity and an optional room per line; room move on checked-in reservations) for `ecosystem-frontend`
- [X] T055 Performance check: in tests/Feature/ReservationRoomsTest.php assert that the reservation index with 10 reservations × 3 lines runs a query count that doesn't grow per reservation (`DB::enableQueryLog()`), and that a 50-unit create succeeds in one request
- [X] T056 Run `./vendor/bin/pint` and `php artisan test`, walk through every scenario in specs/002-reservation-rooms/quickstart.md, and tick the spec's success criteria SC-001–SC-007

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)** → **Foundational (Phase 2)** → user stories.
- **US1 (Phase 3)** depends on Foundational.
- **US2 (Phase 4)** depends on US1 (T014, T015 and T022 must exist before the column drop, and every reader moves to lines here).
- **US3 (Phase 5)** depends on US1. It can run in parallel with US2 except for shared files (`ReservationController.php`, `UpdateReservationRequest.php` is created in T029).
- **US4 (Phase 6)** depends on US3 (it extends `diff()` and `update()`).
- **US5 (Phase 7)** depends on US2 (read tools use lines, T033) and US1 (T023 interim tool).
- **Polish (Phase 8)**: T050 and T051 can start after US1; the docs after US3–US4 are settled; T056 is last.

### User Story Dependencies

```text
Foundational ─► US1 ─┬─► US2 ─► US5
                     └─► US3 ─► US4
```

US1 and US2 ship together (release unit). US3–US5 can follow in the same or a later release.

### Within Each User Story

- Test tasks first; confirm they fail.
- ReservationRoomSync → ReservationCreator → requests → controller/resource → callers.
- Tasks on the same file run in listed order (no [P]).

### Parallel Opportunities

- Phase 2: T003, T004 and T007 together; T009 after T005.
- US1: T010, T011 and T012 (tests) together; T016, T017, T018, T019 and T020 together after T015.
- US2: T025 alongside T026–T027; T029, T030, T031 and T033 together after T028.
- US5: T045, T046 and T047 together; T049 alongside T048.
- Polish: T050–T054 together.

---

## Parallel Example: User Story 1

```bash
# Tests first:
Task: "Create tests/Feature/ReservationRoomsTest.php create scenarios (T010)"
Task: "Rewrite tests/Feature/ReservationRoomOccupancyTest.php onto lines (T011)"

# After ReservationCreator changes (T014–T015):
Task: "StayService primaryRoomId (T016)"
Task: "MakeRoomDirtyOvernightJob inHouseRoomIds (T017)"
Task: "Stay::occupiedRoomsOn counts lines (T018)"
Task: "StoreReservationRequest (T019)"
Task: "ReservationIndexRequest (T020)"
```

---

## Implementation Strategy

### MVP (release unit)

1. Phase 1 and Phase 2.
2. US1: create by room type. Validate independently (quickstart 1–6, 17–18).
3. US2: backfill, drop and readers. Validate (quickstart 7–8), full suite green.
4. **Stop and validate**, coordinate the frontend slice (D13), deploy US1 + US2 together.

### Incremental delivery

5. US3: line edits.
6. US4: room on line and room moves. This is what keeps check-in working before SPEC-021, so ship it before staff rely on multi-room check-in.
7. US5: AI booking by type and the audited AI actor.
8. Polish: deletion guard, isolation sweep, docs, quickstart.

---

## Notes

- Baseline (T001): 697 tests, all passing (2026-09-23). No pre-existing failures.
- Migration prefix (T002): `2026_09_23_000004` / `000005` / `000006`, continuing the room-types sequence.
- Occupancy deviation (T015, T017, T018): stays can exist without a reservation (legacy rows, direct imports, fixtures), so `ReservationRoom::inHouseRoomIds()` is the **union** of rooms on in-house reservations' live lines and in-house stays' own `room_id`, and `Stay::occupiedRoomsOn()` counts each qualifying stay as `max(1, live lines)`. Single-room results are unchanged; multi-room reservations count every room.
- `CreateReservationTool` was built in its final form (T048) during T023, since both touched the same file.
- The manual smoke test in quickstart.md was not run; every quickstart scenario is covered by an automated test.
- Deviation found during implementation: `Room` has **no SoftDeletes**; deleting a room hard-deletes it and `nullOnDelete` clears `reservations.room_id`. So "room later deleted → line keeps it" (spec US2-2, FR-024, research R11 "trashed rooms") cannot occur: such reservations already have no room and get the placeholder type. `ReservationRoom::room()` does not use `withTrashed()`, and "soft-deleted room" validation cases reduce to "room does not exist".
- `ReservationsImport` runs inside `EventLogger::withoutRecording()`; line events are not expected there.
- Never edit a shipped migration; the three new files are the only schema changes.
- Commit after each phase checkpoint. There is no co-author trailer (CLAUDE.md).
