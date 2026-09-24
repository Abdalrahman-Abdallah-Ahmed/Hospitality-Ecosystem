---

description: "Task list for Phase 4 — Stay Lifecycle and Check-in/out (SPEC-023/024/025)"
---

# Tasks: Stay Lifecycle and Check-in/out

**Input**: Design documents from `specs/004-stay-check-in-out/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/stays-api.md](contracts/stays-api.md), [quickstart.md](quickstart.md)

**Tests**: Included. The constitution (VIII) and CLAUDE.md require Pest coverage, a
tenant-isolation test and an allowed/403 test for every new permission. Tests run against
Postgres (`Hospitality_Ecosystem_testing`), never SQLite.

**Organization**: Grouped by user story from spec.md. US1–US3 are P1, US4–US5 are P2 and
US6 is P3. One stay per line (the base every story needs) is in Phase 2.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1–US6 from spec.md
- Paths are repository-relative (single Laravel app)

## Conventions every task follows

- Controllers authorize, validate, delegate and return `apiResponse()`. Response bodies go
  through a Resource.
- Every query in services, jobs, tools and migrations names the hotel explicitly
  (`where('hotel_id', …)`, with `withoutGlobalScope('hotel')` where needed), because AI
  tools, the import and migrations run without tenant context.
- **Lock order** (research R6), always: reservation row → its lines and stays (`FOR
  UPDATE`, ordered by id) → the rooms involved (ordered by id). Check state **after**
  locking.
- "Hotel day" = `CarbonImmutable::now($hotel->timezone)->toDateString()` (reuse
  `AvailabilityService::today()`).
- Test files: `uses(RefreshDatabase::class)`. `beforeEach` sets
  `putenv('API_KEY=test-api-key')` and `config(['app.api_key' => 'test-api-key'])`.
  Requests send `X-API-KEY`. Build models with `Model::create([...])` (only `UserFactory`
  exists). Reservations in tests go through `ReservationCreator::create()` so their lines
  and stays exist. Run the suite serially (helpers are file-local).
- Bridge markers: code that stands in for SPEC-003 / SPEC-004 / SPEC-021 carries a
  `// SPEC-00x:` comment naming what replaces it.
- Run `./vendor/bin/pint` before any commit.

---

## Phase 1: Setup

**Purpose**: Confirm a green baseline.

- [X] T001 Create branch `004-stay-check-in-out` from `main`, start Postgres (`docker compose -f .postgres/compose.yaml up -d`), run `php artisan test` serially and record the pass count as a note under this task in `specs/004-stay-check-in-out/tasks.md`
  - Baseline 2026-09-24 on `004-stay-check-in-out` (from main b9210ef): `php artisan test` → 839 passed.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Permissions, one stay per line, audited rooms, and the shared pieces every
story uses. When this phase is done the app behaves as before (the old PUT path still
checks in through the per-line sync) and the suite is green.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

### Permissions and policy

- [X] T002 [P] Add `case STAYS_VIEW = 'stays.view';`, `case STAYS_CHECK_IN = 'stays.check_in';`, `case STAYS_CHECK_OUT = 'stays.check_out';` as their own alphabetical group (between `ROOM_TYPES_*` and `TASK_CATEGORIES_*`). Do NOT add any to `employeeDefaults()`; extend its docblock with one line: stays expose guest names, rooms and dates, and check-in/out change room status, so a hotel grants them on purpose (spec clarification Q5). File: `app/Enums/Permission.php`
- [X] T003 [P] Create `StayPolicy` with `before()` (super admin → true), `viewAny()` → `allows($user, Permission::STAYS_VIEW)`, `view(User, Stay)` → `allows(…STAYS_VIEW, $stay)`, `checkIn(User, Stay|Reservation $record)` → `allows(…STAYS_CHECK_IN, $record)`, `checkOut(User, Stay|Reservation $record)` → `allows(…STAYS_CHECK_OUT, $record)`, using `ChecksPermissions`, in `app/Policies/StayPolicy.php` (auto-discovered; confirm no manual registration is needed by checking `app/Providers/AppServiceProvider.php`)

### Schema

- [X] T004 Create migration adding `stays.reservation_room_id` — `uuid`, **nullable**, FK → `reservation_rooms.id`, `nullOnDelete` — in `database/migrations/2026_09_24_000001_add_reservation_room_id_to_stays_table.php`
- [X] T005 Create the backfill migration (research R22 steps 1–3), in one transaction, hotel scope dropped, using `DB::table()` (not models, so no audit rows): (1) each reservation's existing stay → `reservation_room_id` = its first line ordered by `created_at, id`, cancelled lines included; (2) every other line without a stay → insert a stay: line `cancelled` or reservation `cancelled` → `cancelled`; reservation `checked_in` → `in_house` with `checked_in_at` copied from the primary stay; `checked_out` → `departed` with `checked_in_at`, `checked_out_at`, `nights` copied; otherwise `expected`; `room_id` = the line's room; planned dates, guest, currency, source from the reservation; (3) for reservations with more than one live line recompute shares: `room_revenue` = `reservation_value` split evenly across live lines with the remainder cents on the first; primary stay keeps `adults`/`children`, other stays `0`/`0`. Reservations with no stay at all get one per line. `down()` (research R22): delete every stay that is not the earliest-created (`created_at, id`) non-deleted stay of its reservation, then set `reservation_room_id` to null; say in the migration docblock that this also removes stays created after the migration for extra lines. File: `database/migrations/2026_09_24_000002_backfill_stays_per_reservation_room.php`
  - Implementation note: the backfill drops `unique(reservation_id)` first (its new stays share their reservation) and its `down()` restores it; migration 3 only adds and drops the two partial indexes. Needed so the backfill can insert and a two-step rollback works.
- [X] T006 Create the uniqueness migration: first query rooms with more than one `in_house`, non-deleted stay and, if any, throw a `RuntimeException` listing `room_id` and stay ids (research R22 step 4); then drop `unique(reservation_id)`, add partial unique index `stays_reservation_room_id_unique` on `(reservation_room_id) WHERE deleted_at IS NULL AND reservation_room_id IS NOT NULL` and `stays_one_in_house_per_room` on `(room_id) WHERE status = 'in_house' AND deleted_at IS NULL` (raw `DB::statement`). `down()`: refuse with a `RuntimeException` if any reservation has more than one non-deleted stay, else drop both indexes and restore `unique(reservation_id)`. File: `database/migrations/2026_09_24_000003_replace_stay_uniqueness.php`

### Models

- [X] T007 [P] In `app/Models/Stay.php`: add `'reservation_room_id'` to `$fillable` and `eventLoggedAttributes()`; add `reservationRoom(): BelongsTo`; rewrite `occupiedRoomsOn()` to `count(*)` of stays with status `in_house`/`departed` whose `planned_arrival_date <= $date < planned_departure_date` (one room per stay, FR-005 / R16) and update its docblock
- [X] T008 [P] In `app/Models/Reservation.php`: add `stays(): HasMany`; redefine `stay(): HasOne` as the primary stay — `hasOne(Stay::class)->ofMany(['created_at' => 'min', 'id' => 'min'], fn ($q) => $q->where('status', '!=', StayStatus::CANCELLED->value))` — with a docblock explaining its guest-level callers (research R3). Keep `primaryRoomId()` (still used by the Concierge tool until T060)
  - Implementation note: `stay()` is an ordered `hasOne` (`created_at, id`) filtered to non-cancelled, not `ofMany` — Postgres has no `min()` for uuid.
- [X] T009 [P] In `app/Models/ReservationRoom.php`: add `stay(): HasOne`; rewrite `inHouseRoomIds()` to select `room_id` from non-deleted `stays` with status `in_house` and `room_id` not null (research R16), and update its docblock
- [X] T010 [P] In `app/Models/Room.php`: add `RecordsEvents` with `eventLoggedAttributes()` returning `['room_number', 'room_type_id', 'floor', 'status', 'housekeeping_status']`; add `stays(): HasMany`; add `isOutOfOrder(): bool` = `in_array($this->status, array_column(RoomStatusesEnum::outOfOrder(), 'value'))` **or** `housekeeping_status === HousekeepingStatusesEnum::BLOCKED`, with a `// SPEC-003:` comment (research R8)

### Stay sync (one stay per line)

- [X] T011 In `app/Services/StayService.php`: replace `syncFromReservation()` with `syncForReservation(Reservation $reservation): Collection` (research R4). For every line (cancelled included): `firstOrCreate` a stay by `reservation_room_id` (status `expected`); for stays that are `expected` copy the planned side (`guest_id`, `room_id` = line room, planned dates, currency, `source_channel`, revenue share, party share); for `in_house` stays copy only `room_id` and `planned_departure_date`; never touch `departed` stays. Line cancelled → its `expected` stay becomes `cancelled`; live line whose stay is `cancelled` → `expected`. Revenue: even split across live lines, remainder cents on the first line (by `created_at, id`). Party: first live line's stay gets reservation `adults ?? 1` / `children ?? 0`, others 0/0. Idempotent. Keep `checkIn()`, `checkOut()`, `markNoShow()`, `markCancelled()`, `markExpected()` unchanged
  - Implementation note: in-house stays copy the whole planned side too (party, value, room, dates — FR-003 only freezes the actuals). A reservation with no lines (legacy rows) keeps its single line-less stay. A cancelled line that never had a stay gets none.
- [X] T012 In `app/Support/Reservations/ReservationCreator.php`: rename `syncStay()` → `syncStays()` calling `StayService::syncForReservation()`; keep the status mirror **for now** (PUT `checked_in`/`checked_out` → `StayService::checkIn/checkOut` on every live line's stay; `cancelled` → `markCancelled` on expected stays; `pending`/`confirmed` → nothing beyond the sync) so behavior is unchanged until US3 replaces it. Change `syncRoomStatus()` to load the `Room` model and call `update()` (so `RecordsEvents` fires; research R23) instead of the builder update
- [X] T013 [P] Update every caller of `syncFromReservation()` / `syncStay()` found by `grep -rn "syncFromReservation\|syncStay" app tests` to the new names

### Shared pieces

- [X] T014 [P] Create `CheckInOutException extends ValidationException` with `static for(array $reasons, string $summary): self` where `$reasons` is `['stays.{stay_id}.{key}' => [messages]]` plus top-level keys like `checked_in_at`, and a `render()` returning the same `{message, code, errors}` shape as `app/Exceptions/InsufficientAvailabilityException.php`, in `app/Exceptions/CheckInOutException.php`
- [X] T015 [P] Create `StayResource` with exactly the fields in `contracts/stays-api.md` § Resource (`room` null while unassigned; `room_type` from the line; `is_late` / `is_overdue` from an optional `day` passed via `additional` or a static setter, default today), in `app/Http/Resources/StayResource.php`
  - Extended the existing `app/Http/Resources/StayResource.php` (kept its fields; added `reservation_room_id`, `room_type`, and the flags via `StayResource::forDay()`).
- [X] T016 Create `StayLifecycleService` skeleton with constructor-injected `StayService` and `AvailabilityService`, private helpers `hotelDay(Hotel)`, `lockReservation(Reservation): Collection` (locks reservation, then its lines and stays ordered by id, returns fresh stays keyed by id), `lockRooms(array $roomIds)` (ordered by id), and `resolveTime(?string $given, Hotel, ?CarbonInterface $notBefore, string $field)` implementing research R17 (on current hotel day, ≤ now, ≥ `$notBefore`; else `CheckInOutException` on `$field`), in `app/Services/StayLifecycleService.php`

### Foundational tests

- [X] T017 [P] Write `StayMigrationTest` (research R22). Approach: in each test, drop the two partial indexes and restore `unique(reservation_id)` with `DB::statement`, insert legacy-shaped rows with `DB::table()` (`stays.reservation_room_id = null`, one stay per reservation), then `$m = require database_path('migrations/2026_09_24_000002_backfill_stays_per_reservation_room.php'); $m->up();` and likewise for `..._000003_...`. Assert: single-line reservation's stay linked to its line; 2-line `checked_in` reservation → 2 `in_house` stays with the same `checked_in_at`; cancelled line → `cancelled` stay; revenue 100.01 over 2 lines → 50.01 / 50.00; party on the primary stay only; a reservation with no stay gets one per line; two in-house stays in one room make `000003` `up()` throw listing the room; `000003` `down()` refuses with 2 stays on one reservation; `000002` `down()` leaves one stay per reservation with `reservation_room_id` null. File: `tests/Feature/StayMigrationTest.php`
- [X] T018 [P] Write `StaySyncTest`: create with 2 lines → 2 `expected` stays linked to their lines; add a line → +1 stay; cancel a line → its stay `cancelled`; cancel reservation → all expected stays `cancelled`; reactivate → `expected` again; move dates → expected stays follow; assign a room on a line → stay `room_id` follows; `in_house` stay keeps `checked_in_at` when dates change but follows `planned_departure_date`; calling sync twice creates nothing new; `Reservation::stay` returns a non-cancelled stay. File: `tests/Feature/StaySyncTest.php`
- [X] T019 Run the full suite; fix fallout from the changed `occupiedRoomsOn()` (expect `tests/Feature/DashboardControllerTest.php`), `inHouseRoomIds()` (`MakeRoomDirtyOvernightJob` tests) and the removed `unique(reservation_id)`; record results under this task
  - Checkpoint 2026-09-24: 854 passed (839 + 15 new). Interim: `ReservationCreator::syncStays()` still mirrors the reservation status onto stays until T043.

**Checkpoint**: Every reservation line has exactly one stay; the suite is green.

---

## Phase 3: User Story 1 — Front desk checks a guest into their room (Priority: P1) 🎯 MVP

**Goal**: Check in one line: rules R7, optional room assignment (Q1), not-clean warning
(Q2), idempotent, optional earlier time (Q4), audited.

**Independent Test**: Confirmed one-line reservation arriving today with an assigned room
→ `POST /api/stays/{stay}/check-in` → stay `in_house` with check-in time, room `occupied`,
reservation `checked_in`, one `stay.checked_in` audit row naming the actor.

### Tests for User Story 1

- [X] T020 [P] [US1] Write `CheckInTest` covering spec US1 scenarios 1–10: happy path (stay, room, reservation, audit actor); no room and no `room_id` → 422 `stays.{id}.room`, nothing changed; named room assigned + checked in (audit has `reservation_room.updated`); named room of another type / another hotel (404) / overlapping another reservation's line → 422 `stays.{id}.room_id`; line already has a different room + `room_id` → 422; out-of-order room (status `maintenance`, and housekeeping `blocked`) → 422; room occupied by another in-house stay → 422; reservation `pending`/`cancelled`/`checked_out` and cancelled line → 422; arrival tomorrow → 422; arrived yesterday, departs tomorrow → 200; departure today or past → 422; repeat → 200 with unchanged `checked_in_at` and exactly one `stay.checked_in` row; dirty room → 200 with `warnings[0].message` "Room {n} is dirty."; `checked_in_at` earlier today → stored and audit `changes.entered_at` present; yesterday or future → 422 `checked_in_at`; nothing written to `transactions`. File: `tests/Feature/CheckInTest.php`
- [X] T021 [P] [US1] Write `CheckInOutConcurrencyTest` with `DatabaseTruncation` and a second DB connection (copy the setup from `tests/Feature/ReservationAvailabilityConcurrencyTest.php`): (a) connection B holds the reservation lock while A's check-in waits (`SET lock_timeout`), proving serialization; (b) two check-ins of the same stay leave one `stay.checked_in` row; (c) two different reservations naming the same free room → exactly one succeeds; (d) connection B holds the reservation lock while A's check-out waits, then two check-outs of the same stay leave one `departed` transition, one `stay.checked_out` row and exactly one cleaning task (SC-006, FR-014). File: `tests/Feature/CheckInOutConcurrencyTest.php`
- [X] T022 [P] [US1] Add the check-in endpoint to `tests/Feature/PermissionAuthorizationTest.php`'s dataset (`stays.check_in` → `POST /api/stays/{stay}/check-in`) and a case to `tests/Feature/TenantIsolationTest.php`: another hotel's admin gets 404 on check-in of this hotel's stay and cannot name this hotel's room
  - Another hotel's stay/reservation answers **403**, not 404: the repo convention (policy same-hotel check before any tenant scope, e.g. `CreateUserTest`). Spec FR-027 and the contract were aligned. Isolation cases for all stays endpoints and tools are one test in `TenantIsolationTest`.

### Implementation for User Story 1

- [X] T023 [US1] Create `RoomAssignmentRules::assertAssignable(ReservationRoom $line, Room $room, string $key)` (research R9): room in `line.hotel_id`, not deleted, `room.room_type_id === line.room_type_id` ("Room {n} is not of the line's room type."), and no other live line on a non-deleted reservation with status in `ReservationStatus::holdingInventory()` holding this `room_id` on an overlapping night (`arrival < other.departure AND other.arrival < departure`) ("Room {n} is booked for {code} on overlapping nights."). Throws `ValidationException` on `$key`. `// SPEC-021:` comment: reused for staff assignment. File: `app/Support/Reservations/RoomAssignmentRules.php`
- [X] T024 [US1] Implement `StayLifecycleService::checkIn(Stay $stay, ?string $roomId = null, ?string $at = null): array` → `['reservation' => …, 'stays' => [...], 'warnings' => [...]]` in `app/Services/StayLifecycleService.php`: in `DB::transaction`, lock (T016); if stay already `in_house` return current state (no writes); collect R7 reasons for this stay (reservation `confirmed`/`checked_in`; line not cancelled; stay `expected`; hotel day ≥ arrival and < departure; room assigned or `$roomId` given — if given and line has a different room → reason; if given, lock the room and run `RoomAssignmentRules`; `Room::isOutOfOrder()`; no other `in_house` stay in the room); throw `CheckInOutException` with all reasons; else: set `line.room_id` via model `update()` when assigning, then `StayService::syncForReservation()`, `StayService::checkIn($stay, resolved time)`, when a time was given, put `entered_at` (now, ISO-8601) on the same audit row via `Stay::$auditExtras` (research R17: add `public array $auditExtras = []` to `app/Models/Stay.php`, override `loggedChangeSet()` to merge it into the parent result as `['entered_at' => ['to' => …]]`, set it right before the status `update()` and reset it after; never call `EventLogger::record()` for it), `ReservationCreator::syncRoomOccupancy([$roomId])`, reservation `update(['status' => CHECKED_IN])` if not already; warning when `housekeeping_status !== CLEAN`
  - Implemented together with T040/T033/T041 as one `StayLifecycleService` (single-room calls are the whole-reservation path with one target).
- [X] T025 [P] [US1] Create `CheckInRequest`: `room_id` nullable uuid; `rooms` nullable array, `rooms.*.stay_id` required uuid, `rooms.*.room_id` required uuid; `checked_in_at` nullable ISO-8601 date, in `app/Http/Requests/CheckInRequest.php`
- [X] T026 [US1] Create `StayCheckInController::stay(CheckInRequest, Stay)`: `authorize('checkIn', $stay)`, call the service, return `apiResponse('Checked in.', 200, [...])` with `StayResource` collection and `warnings` (contract § Check-in), in `app/Http/Controllers/StayCheckInController.php`
- [X] T027 [US1] Register `POST /stays/{stay}/check-in` in the tenant group of `routes/api.php`

**Checkpoint**: A single room can be checked in end to end; T020–T022 pass.

---

## Phase 4: User Story 2 — Front desk checks a guest out (Priority: P1)

**Goal**: Check out one line: stay departed with nights, room available + dirty, one
cleaning task, reservation checked out when no line is in-house, idempotent, availability
freed.

**Independent Test**: Check out an in-house one-line stay → stay `departed` with nights,
room `available` + `dirty`, exactly one cleaning task (room, reservation, guest, stay,
Housekeeping team) and reservation `checked_out`.

### Tests for User Story 2

- [X] T028 [P] [US2] Write `CheckOutTest` covering spec US2 scenarios 1–8: happy path (stay, nights by calendar date, room, reservation, audit); cleaning task fields (`created_by = system`, `status = pending`, `priority = normal`, title "Clean room {n} after check-out", `room_id`, `reservation_id`, `guest_id`, `stay_id`, `assigned_to_team_id` / `task_category_id` = the hotel's `housekeeping_team_id` / `cleaning_task_category_id`, including a team named in Arabic); hotel without them set, or with the chosen team deactivated or deleted → task with null team/category and check-out still 200; `PUT /api/hotel/{id}` accepts both defaults, rejects another hotel's team (403), an inactive team or a category outside the team (422), and an employee cannot change them; leaving 2 days early → nights actual; overstay past departure → 200; `expected` / `cancelled` stay → 422 `stays.{id}.stay_status`; repeat on `departed` → 200, no second task, no second audit row; room `maintenance` stays `maintenance` and gets `dirty`; housekeeping `blocked` stays `blocked`; `checked_out_at` 07:00 today → stored, audit `entered_at`; yesterday / future / before check-in → 422; no `transactions` rows. File: `tests/Feature/CheckOutTest.php`
- [X] T029 [P] [US2] Add to `tests/Feature/AvailabilityServiceTest.php`: a 2-line reservation with one line departed two days early frees that line's remaining nights; an `expected` line on a `checked_in` reservation past its departure does not hold tonight, an `in_house` one does
- [X] T030 [P] [US2] Add `stays.check_out` → `POST /api/stays/{stay}/check-out` to the dataset in `tests/Feature/PermissionAuthorizationTest.php` and a 404 case to `tests/Feature/TenantIsolationTest.php`

### Implementation for User Story 2

- [X] T031 [US2] Housekeeping defaults as hotel settings (research R10, analysis D1 — never look teams or categories up by name): migration `database/migrations/2026_09_24_000005_add_housekeeping_defaults_to_hotels_table.php` adding `housekeeping_team_id` (uuid, nullable, FK → `teams.id`, `nullOnDelete`) and `cleaning_task_category_id` (uuid, nullable, FK → `task_categories.id`, `nullOnDelete`); add both to `$fillable` plus `housekeepingTeam()` / `cleaningTaskCategory()` BelongsTo in `app/Models/Hotel.php` and to `app/Http/Resources/HotelResource.php`; in `app/Http/Controllers/HotelController.php` `update()` reject another hotel's team/category with the `invalidRelation()` 403 used by `TaskController`, and with 422 an inactive team or a category whose `team_id` differs from the chosen team; create `HousekeepingDefaults::for(Hotel): array{team: ?Team, category: ?TaskCategory}` reading the two columns (inactive team → null) with a `// SPEC-004: fills these columns for every hotel` comment in `app/Support/Housekeeping/HousekeepingDefaults.php`
- [X] T032 [US2] Add `stay_id` to the tasks table **now** (needed for the cleaning task): migration `database/migrations/2026_09_24_000004_add_stay_id_to_tasks_table.php` — `uuid`, nullable, FK → `stays.id`, `nullOnDelete`, indexed; add `'stay_id'` to `$fillable` and `eventLoggedAttributes()` and a `stay()` BelongsTo in `app/Models/Task.php`; add `tasks(): HasMany` in `app/Models/Stay.php`
- [X] T033 [US2] Implement `StayLifecycleService::checkOut(Stay $stay, ?string $at = null): array` → `['reservation', 'stays', 'cleaning_tasks']` in `app/Services/StayLifecycleService.php`: in `DB::transaction`, lock; `departed` → return current state; not `in_house` → `CheckInOutException` `stays.{id}.stay_status` "This room is {status}."; resolve time (not before `checked_in_at`); `StayService::checkOut()` fed hotel-local dates for nights; room: load model, `status = available` only if `occupied` (via `ReservationCreator::syncRoomOccupancy`), then `housekeeping_status = dirty` unless `blocked`, via model `update()`; create the cleaning task (research R11) with `Task::create`, team/category from `HousekeepingDefaults::for()`, no notification; reservation `update(['status' => CHECKED_OUT])` when none of its stays is `in_house` (FR-012a's expected-lines rule is added in US3); `entered_at` through `Stay::$auditExtras` like T024
- [X] T034 [US2] In `app/Services/AvailabilityService.php` `holdingNights()` (research R15): left-join `stays` on `stays.reservation_room_id = reservation_rooms.id and stays.deleted_at is null`; exclude rows whose stay status is `departed`, `cancelled` or `no_show`; change the overstay `case` to key on `stays.status = 'in_house'` instead of `reservations.status = 'checked_in'` (keep binding order correct — lateral join bindings lead); update the docblock
  - Implementation note: cancellation is read from the line, not its stay (the guard runs before stays sync), so only `departed`/`no_show` stays are excluded; a line with no stay (legacy fixtures) falls back to the reservation status for the overstay rule.
- [X] T035 [P] [US2] Create `CheckOutRequest` (`checked_out_at` nullable ISO-8601) in `app/Http/Requests/CheckOutRequest.php`
- [X] T036 [US2] Create `StayCheckOutController::stay(CheckOutRequest, Stay)` (`authorize('checkOut', $stay)`, service, `apiResponse('Checked out.', 200, …)` with `cleaning_tasks`) in `app/Http/Controllers/StayCheckOutController.php` and register `POST /stays/{stay}/check-out` in `routes/api.php`

**Checkpoint**: One room can be checked in and out; the freed room is dirty with a
cleaning task and sellable again.

---

## Phase 5: User Story 3 — Multi-room reservations room by room (Priority: P1)

**Goal**: Whole-reservation check-in/out (all-or-nothing), reservation status rules (first
in / last out), FR-012a, the reservation-edit guards, the deprecated PUT/POST path and the
import history path.

**Independent Test**: 2-line reservation: check in one line, then the other, then check
out the whole reservation — each line has its own stay, audit rows and cleaning task; the
reservation is `checked_in` after the first and `checked_out` only after both.

### Tests for User Story 3

- [X] T037 [P] [US3] Write `MultiRoomCheckInOutTest` covering spec US3 scenarios 1–10: 2 lines → 2 stays; whole check-in of 2 assigned lines → both `in_house` in one call; one unassigned → 422 listing that stay, nothing changed; whole check-in with `rooms[]` naming the unassigned line's room → 200; one in-house + one expected → whole check-in checks in only the expected one; first line checked in → reservation `checked_in`; check out one of two in-house → reservation stays `checked_in`; second → `checked_out`; whole check-out → 2 departed, 2 dirty rooms, 2 cleaning tasks; one in-house + one expected → single or whole check-out → 422 `stays.expected` listing the expected line, nothing changed; cancel the expected line then check out → 200 and reservation `checked_out`. File: `tests/Feature/MultiRoomCheckInOutTest.php`
  - Found and fixed: SPEC-010's rule "no line may be removed from a checked-in reservation" blocked FR-012a (cancel the room that never arrived). `ReservationRoomSync::diff()` now allows removing a line whose guest never checked in; lines whose guests are in or departed stay protected.
- [X] T038 [P] [US3] Write `ReservationStatusDeprecationTest`: `PUT /api/reservation/{id}` `status: checked_in` on a confirmed, assigned reservation → 200, stays `in_house`, headers `Deprecation: true` and `Link: </api/reservation/{id}/check-in>; rel="successor-version"`, message contains the deprecation sentence; same with an unassigned line → 422 (no room naming on this path); employee with `reservations.update` but without `stays.check_in` → 403; `checked_out` likewise; `POST /api/reservation` with `checked_in` → created then checked in (headers present), with `checked_out` → 422; `cancelled` with an in-house stay → 422 `status` "Check out the in-house rooms first."; `rooms` removing an in-house line → 422 `rooms.{i}`; `DELETE` with an in-house stay → 422; `DELETE` otherwise → its stays soft-deleted; import of a `checked_out` row → stays `departed`, no cleaning task, no room checks; `CreateReservationTool` with `status: checked_in` → returns a message, nothing created. File: `tests/Feature/ReservationStatusDeprecationTest.php`
- [X] T039 [P] [US3] Add the two reservation-level endpoints (`stays.check_in` → `POST /api/reservation/{reservation}/check-in`, `stays.check_out` → `POST /api/reservation/{reservation}/check-out`) to the dataset in `tests/Feature/PermissionAuthorizationTest.php` and 404 cases to `tests/Feature/TenantIsolationTest.php`

### Implementation for User Story 3

- [X] T040 [US3] Refactor `StayLifecycleService` so reasons are collected per stay by a private `checkInReasons(Stay, ?Room, …)` used by both `checkIn()` and new `checkInReservation(Reservation $reservation, array $roomsByStayId = [], ?string $at = null): array`: lock once, skip `in_house` stays, evaluate every `expected` stay on a live line, throw one `CheckInOutException` with every failing stay's reasons (summary "{n} rooms cannot be checked in."), else apply all in the same transaction; a `stay_id` in `$roomsByStayId` that is not an expected stay of this reservation → reason on `rooms`. File: `app/Services/StayLifecycleService.php`
- [X] T041 [US3] Add `checkOutReservation(Reservation, ?string $at)` (all `in_house` stays) and the FR-012a rule to both check-out methods in `app/Services/StayLifecycleService.php`: if after this check-out no stay would be `in_house` and any live line's stay is `expected`, throw `CheckInOutException` on `stays.expected` "Cancel the rooms that did not arrive first: {code} {type} ({room number or 'unassigned'}), …"; a check-out that leaves another line in-house is not affected
- [X] T042 [US3] Add `reservation(CheckInRequest, Reservation)` to `app/Http/Controllers/StayCheckInController.php` and `reservation(CheckOutRequest, Reservation)` to `app/Http/Controllers/StayCheckOutController.php` (`$this->authorize('checkIn', [Stay::class, $reservation])` / `'checkOut'`, which calls `StayPolicy` with the reservation as the record; map `rooms[]` to `[$stayId => $roomId]`); register `POST /reservation/{reservation}/check-in` and `/check-out` in `routes/api.php` **before** the `Route::resource('/reservation', …)` line
- [X] T043 [US3] In `app/Support/Reservations/ReservationCreator.php`: remove the status mirror from `syncStays()` for non-import callers — `pending`/`confirmed`/`cancelled` handled by `syncForReservation()` only; add `bool $fromImport` handling so `create(..., fromImport: true)` still applies `StayService::checkIn/checkOut` to every live line's stay from the imported status with no room checks and no cleaning task (research R14); in `update()` before writing, reject with `ValidationException` (a) `status → cancelled` while any stay is `in_house` ("Check out the in-house rooms first." on `status`), (b) a plan that cancels/removes a line whose stay is `in_house` ("Room {n} is checked in; check it out first." on `rooms.{i}`); when an `in_house` line moves room, mark the vacated room `dirty` (unless `blocked`) via the model; add `delete(Reservation)` that rejects with an in-house stay and otherwise soft-deletes the reservation and its stays in one transaction (research R13)
  - Implementation note: the status mirror stays for callers that record history (import, fixtures) but only when the call sets or changes the status (`syncStays(recordStatus:)`); otherwise editing a checked-in reservation would check in its waiting rooms. A status change away from `checked_in` while a guest is in is also rejected (it would be an un-check-in, out of scope per Q3). Updated superseded tests in `ReservationRoomOccupancyTest`, `ReservationRoomsTest` and `StayServiceTest` to the new rules; full suite 886/886.
- [X] T044 [US3] In `app/Http/Controllers/ReservationController.php` (research R12): in `update()`, when `status` changes to `checked_in`/`checked_out`, `authorize('checkIn'|'checkOut', …)` on top of `update`, remove `status` from the attributes, run `ReservationCreator::update()` for the rest and then `StayLifecycleService::checkInReservation()` / `checkOutReservation()` inside one `DB::transaction`, and return the normal response with headers `Deprecation: true` and `Link: </api/reservation/{id}/check-in>; rel="successor-version"` (or `/check-out`) and the message suffix " Setting status to checked_in here is deprecated; use POST /reservation/{id}/check-in."; in `store()`, `checked_in` → create as `confirmed` then the same deprecated check-in, `checked_out` → 422 "Only the reservation import can record a checked-out reservation."; `destroy()` → `ReservationCreator::delete()`
- [X] T045 [P] [US3] In `app/Ai/Tools/CreateReservationTool.php`: reject any `status` other than `pending`/`confirmed` with the message "I can only create pending or confirmed reservations; check guests in with the check-in tool." and update the `status` schema description

**Checkpoint**: All P1 stories work; reservation status follows stays; the PUT path is
deprecated but compatible.

---

## Phase 6: User Story 4 — Arrivals, departures and in-house lists (Priority: P2)

**Goal**: Front-desk lists for a chosen hotel day plus the generic stays index and show.

**Independent Test**: Expected, in-house and departed stays across several dates — each
list returns exactly the stays matching its rule for the chosen day, with late/overdue
flags and room details.

### Tests for User Story 4

- [X] T046 [P] [US4] Write `StayListTest` covering spec US4 scenarios 1–7: arrivals include today's and yesterday's expected stays (`is_late` true for yesterday) and exclude cancelled lines and cancelled reservations; an expected stay whose departure has passed is listed with `is_past_departure` true; departures include today's and overdue in-house stays (`is_overdue`); in-house lists every in-house stay; unassigned lines show `room: null`; `?date=` switches the day; malformed date → 422; super admin must pass `hotel_id`; other hotel's stays never appear; `GET /api/stays` filters (`status`, `reservation_id`, `room_id`) and search by reservation code; `GET /api/stays/{id}`; a 500-room seeded hotel (500 rooms, ~450 stays across today's arrivals, departures and in-house) — each list uses ≤ 6 queries (`DB::getQueryLog()`) and responds in under 2 s (`microtime` around the request; SC-008). File: `tests/Feature/StayListTest.php`
- [X] T047 [P] [US4] Add `stays.view` → `GET /api/stays`, `GET /api/stays/arrivals` rows to the dataset in `tests/Feature/PermissionAuthorizationTest.php` and list/show cases to `tests/Feature/TenantIsolationTest.php`

### Implementation for User Story 4

- [X] T048 [P] [US4] Add `Filterable` search config for stays (guest name via relation, reservation code) following how `Reservation` does it, and ensure `stays` columns are filterable, in `app/Models/Stay.php`
  - Implemented as a `scopeSearch()` override on `app/Models/Stay.php` (guest name/phone, reservation code).
- [X] T049 [P] [US4] Create `StayDayRequest` (`date` nullable `date_format:Y-m-d`; `hotel_id` for super admin like other index requests) in `app/Http/Requests/StayDayRequest.php`
- [X] T050 [US4] Create `StayController` with `index(GenericIndexRequest)` (`authorize('viewAny', Stay::class)`, `GenericQuery::apply`), `show(Stay)`, `arrivals(StayDayRequest)`, `departures(StayDayRequest)`, `inHouse(StayDayRequest)` using the rules in `contracts/stays-api.md` § Lists (arrivals: `expected`, live line, reservation `pending`/`confirmed`/`checked_in`, `planned_arrival_date ≤ day`, with `is_past_departure` when `planned_departure_date ≤ day`; departures: `in_house`, `planned_departure_date ≤ day`; in-house: `in_house`), eager-loading `guest`, `reservation`, `reservationRoom.roomType`, `room`, sorted by room number nulls last then guest name, response `{date, stays}`; `resolveHotel()` for super admin. File: `app/Http/Controllers/StayController.php`
- [X] T051 [US4] Register `GET /stays`, `/stays/arrivals`, `/stays/departures`, `/stays/in-house` (before `/stays/{stay}`) and `GET /stays/{stay}` in the tenant group of `routes/api.php`

**Checkpoint**: The front desk's daily lists work.

---

## Phase 7: User Story 5 — The Admin AI checks guests in and out (Priority: P2)

**Goal**: Three Admin AI tools on the same service and permissions; Concierge refuses.

**Independent Test**: Through the tools, check a reservation in and out — results match the
endpoints and audit rows have `actor_kind = ai_agent` with the admin as `actor_id`.

### Tests for User Story 5

- [X] T052 [P] [US5] Write `StayAiToolsTest`: `CheckInTool` on a ready reservation → stays `in_house`, `event_log` rows with `actor_kind = ai_agent` and `actor_id` = the user; with `assign` naming a room for an unassigned line → assigned + checked in; a failing rule → returns the same message text as the endpoint and changes nothing; user without `stays.check_in` → "You don't have permission …" and nothing changed; `CheckOutTool` likewise (cleaning task created); `GetStaysTool` arrivals/departures/in_house match `StayListTest` data; `GuestConciergeAgent::tools()` contains no check-in/out tool and its instructions mention the front desk; `AdminAdvisorAgent::tools()` contains the three tools. File: `tests/Feature/StayAiToolsTest.php`

### Implementation for User Story 5

- [X] T053 [P] [US5] Create `GetStaysTool(Hotel, User)`: permission `STAYS_VIEW`; params `list` (arrivals | departures | in_house, required), `date` (YYYY-MM-DD, optional); reuses the `StayController` queries (extract them to a small query object `app/Support/Stays/FrontDeskLists.php` first and make `StayController` use it); returns compact JSON rows (reservation code, guest name, room type, room number or "unassigned", planned dates, `is_late`/`is_overdue`). File: `app/Ai/Tools/GetStaysTool.php`
  - Queries live in `app/Support/Stays/FrontDeskLists.php`, used by both `StayController` and `GetStaysTool`. Shared tool helpers in `app/Ai/Tools/Concerns/FindsReservationStays.php`.
- [X] T054 [P] [US5] Create `CheckInTool(Hotel, User)`: permission `STAYS_CHECK_IN`; params `reservation_id` (code, required), `room_numbers` (optional array — check in only lines with these assigned rooms, via `checkIn` per stay inside one transaction; omitted → `checkInReservation`), `assign` (optional array of `{room_type, room_number}` for unassigned lines, matched to the first unassigned expected line of that type), `checked_in_at` (optional); resolve everything within `$hotel`; run inside `EventLogger::asAiAgent()`; catch `ValidationException` and return its messages joined; on success return reservation status, rooms checked in and warnings. File: `app/Ai/Tools/CheckInTool.php`
- [X] T055 [P] [US5] Create `CheckOutTool(Hotel, User)`: permission `STAYS_CHECK_OUT`; params `reservation_id`, `room_numbers` (optional), `checked_out_at` (optional); same error/audit handling; returns status, rooms checked out and cleaning task ids. File: `app/Ai/Tools/CheckOutTool.php`
- [X] T056 [US5] Register the three tools in `app/Ai/Agents/AdminAdvisorAgent.php` (`GetStaysTool` under "Read", the two others under "Write") and add instruction lines: arrivals/departures/in-house come only from the stays tool; check in/out only when the admin clearly asks; never pick a room unless the admin names it; pass an actual time only when the admin states it; report a refusal as given, never retry another way
- [X] T057 [P] [US5] Add to `app/Ai/Agents/GuestConciergeAgent.php` instructions: the Concierge cannot check guests in or out; direct the guest to the front desk

**Checkpoint**: Admin AI parity with staff actions; Concierge refuses.

---

## Phase 8: User Story 6 — Tasks are linked to their stay (Priority: P3)

**Goal**: `stay_id` on staff-created tasks with consistency rules and filtering; the
Concierge links its requests to the right stay.

**Independent Test**: Check out a stay and create a Concierge request for another in-house
guest — both tasks carry the right stay; `GET /api/task?filter[stay_id]=…` returns them.

### Tests for User Story 6

- [X] T058 [P] [US6] Write `TaskStayLinkTest`: cleaning task has `stay_id`; `POST /api/task` with `stay_id` of this hotel → saved, `guest_id` derived from the stay; with another hotel's `stay_id` → 403 (existing `invalidRelation()` message); `room_id` or `reservation_id` differing from the stay's → 422; `PUT` changing `stay_id` follows the same rules; `filter[stay_id]` works; `TaskResource` includes `stay_id`; Concierge `CreateGuestServiceRequestTool` with one in-house stay → task linked to that stay and its room; with two in-house stays and `room_number` of one → that stay; without `room_number` → `stay_id` and `room_id` null, reservation and guest set. File: `tests/Feature/TaskStayLinkTest.php`

### Implementation for User Story 6

- [X] T059 [US6] In `app/Http/Controllers/TaskController.php` `store()` and `update()`: add `'stays' => $validated['stay_id'] ?? null` to the `invalidRelation()` map; when a stay is given, return `apiResponse(…, 422)` if a given `room_id` / `reservation_id` differs from the stay's, else default `room_id`, `reservation_id` and `guest_id` from the stay. Confirm `hotel->stays()` exists for `invalidRelation()` (`app/Models/Hotel.php`) and that `GenericStoreRequest` picks up the new column (`app/Support/RequestRules/`); add `stay_id` to `app/Http/Resources/TaskResource.php`
- [X] T060 [US6] In `app/Ai/Tools/CreateGuestServiceRequestTool.php` (research R21): add optional `room_number` to the schema; resolve the guest's `in_house` stays on `$this->reservation`: exactly one → set `stay_id` and `room_id` from it; several → the one whose room number matches `room_number`, else `stay_id`/`room_id` null; none → keep today's `primaryRoomId()` fallback for `room_id` with `stay_id` null

**Checkpoint**: All six stories complete.

---

## Phase 9: Polish & Cross-Cutting Concerns

- [X] T061 [P] Create `docs/stays-api-documentation.md` from `contracts/stays-api.md` (endpoints, request/response examples, errors, permissions, the list rules, Admin AI tools)
- [X] T062 [P] Update `docs/reservations-api-documentation.md`: deprecated `checked_in`/`checked_out` on PUT and POST (headers, permissions, planned rejection in a later release), `checked_out` on create → 422, cancel/line-removal/delete guards with in-house stays, `stays` on the reservation response if added
- [X] T063 [P] Update `docs/hotel-api-documentation.md` (`housekeeping_team_id`, `cleaning_task_category_id`, validation), `docs/task-management-api-documentation.md` (`stay_id`, rules, filter, cleaning task created by check-out) and `docs/usage-metering-api-documentation.md` (stays now count one per room — research R24)
- [X] T064 [P] Update the permission reference in `docs/staff-roles-api-documentation.md` with `stays.view`, `stays.check_in`, `stays.check_out` and their endpoints; note none is an employee default
- [X] T065 Append to `docs/latest-changes-2026-09-24.md`: new endpoints; deprecation of status changes through the reservation edit (and that unassigned lines now block it); reservation cannot be cancelled/deleted with in-house rooms; stays are per room (metering, dashboard occupancy); admins should set the hotel's housekeeping team and cleaning category (otherwise cleaning tasks are unassigned); creating a reservation as `checked_out` is now rejected; availability frees rooms on early departure; frontend actions needed (arrivals board, check-in dialog with room picker, check-out)
- [ ] T066 Run `./vendor/bin/pint`, then `php artisan test` serially; all green. Walk through `specs/004-stay-check-in-out/quickstart.md` § Manual walk-through against the dev DB and tick its outcomes checklist
  - 2026-09-24: Pint clean; `php artisan test` → 942 passed (2918 assertions). **Open:** the manual walk-through needs the dev DB migrated (`php artisan migrate`), which was left to the repo owner.
- [ ] T067 Run a production-like data snapshot through migrations 1–5 (quickstart prerequisite) and record the result of migration 3's conflict check (`database/migrations/2026_09_24_000003_replace_stay_uniqueness.php`) as a note under this task in `specs/004-stay-check-in-out/tasks.md`
  - 2026-09-24: read-only version of the conflict check run against the dev DB (`Hospitality_Ecosystem`, 1 reservation, not yet migrated): 0 rooms would hold two in-house stays. **Open:** still needs a production-like snapshot.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)** → **Foundational (Phase 2)** → user stories.
- **US1 (Phase 3)** needs Phase 2.
- **US2 (Phase 4)** needs Phase 2 and T016/T024's lock/time helpers (reuses them); can
  start once T024 is merged.
- **US3 (Phase 5)** needs US1 and US2 (it generalizes both service methods).
- **US4 (Phase 6)** needs Phase 2 only (reads stays) — can run in parallel with US1–US3.
- **US5 (Phase 7)** needs US3 (tools call the reservation-level methods) and US4 (T053
  extracts the list queries).
- **US6 (Phase 8)** needs T032 (US2) for the column; otherwise independent.
- **Polish** after all stories.

```text
Setup → Foundational ─┬─ US1 ─┬─ US3 ─┬─ US5 ─┐
                      │  US2 ─┘       │       ├─ Polish
                      ├─ US4 ─────────┘       │
                      └─ (T032) ─ US6 ────────┘
```

### Within each story

Tests first (they fail), then helpers → service → request → controller → routes.

### Parallel opportunities

- Phase 2: T002, T003 together; T007–T010 together (different models); T014, T015 together; T017, T018 together.
- US1: T020, T021, T022 together; T025 alongside T023/T024.
- US2: T028–T030 and T035 together; T031 alongside them (different files) but before T033.
- US3: T037–T039 together; T045 alongside T040–T044.
- US4: whole phase in parallel with US1–US3 by another developer (T046–T049 together).
- US5: T053–T055 together after T053's query extraction lands; T057 anytime.
- Polish: T061–T064 together.

### Parallel example: User Story 1

```text
Task: "T020 Write CheckInTest in tests/Feature/CheckInTest.php"
Task: "T021 Write CheckInOutConcurrencyTest in tests/Feature/CheckInOutConcurrencyTest.php"
Task: "T022 Add check-in rows to PermissionAuthorizationTest / TenantIsolationTest"
Task: "T025 Create CheckInRequest in app/Http/Requests/CheckInRequest.php"
```

## Implementation Strategy

### MVP first (User Story 1)

1. Phase 1 → Phase 2 (stay per line; app unchanged for users).
2. Phase 3 (single-room check-in) → validate with T020–T022 → demo.

### Incremental delivery

1. + US2 → single-room check-out with housekeeping turnover (first release-worthy slice:
   one-room reservations fully served by the new endpoints).
2. + US3 → multi-room and the deprecation / guards (**behavior change**, announce in
   `latest-changes`; coordinate the frontend switch).
3. + US4 → front-desk lists (frontend board).
4. + US5 → Admin AI.
5. + US6 → task attribution.

Each checkpoint leaves the suite green and the app deployable.
