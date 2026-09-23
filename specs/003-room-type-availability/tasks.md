---

description: "Task list for SPEC-020 Room-Type Availability"
---

# Tasks: Room-Type Availability

**Input**: Design documents from `specs/003-room-type-availability/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/availability-api.md](contracts/availability-api.md), [quickstart.md](quickstart.md)

**Tests**: Included. The constitution (VIII) and CLAUDE.md require Pest coverage, a
tenant-isolation test and an allowed/403 test for every new permission. Tests run against
Postgres (`Hospitality_Ecosystem_testing`), never SQLite.

**Organization**: Grouped by user story from spec.md. US1 and US2 are P1; US3 and US4 are
P2. The calculation all four stories use is in Phase 2.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1–US4 from spec.md
- Paths are repository-relative (single Laravel app)

## Conventions every task follows

- Controllers authorize, validate, delegate and return `apiResponse()`. Response bodies go
  through a Resource.
- Every availability query names the hotel explicitly (`where('hotel_id', …)`, with
  `withoutGlobalScope('hotel')` where needed), because AI tools and the import run without
  tenant context.
- Test files: `uses(RefreshDatabase::class)`. `beforeEach` sets
  `putenv('API_KEY=test-api-key')` and `config(['app.api_key' => 'test-api-key'])`.
  Requests send `X-API-KEY`. Build models with `Model::create([...])` (only `UserFactory`
  exists).
- Terms: a *night* is `[arrival_date, departure_date)`. A *holding line* is a
  non-cancelled, non-deleted `reservation_rooms` row whose reservation is not deleted and
  has status `pending`, `confirmed` or `checked_in` (research R3).
- Run `./vendor/bin/pint` before any commit.

---

## Phase 1: Setup

**Purpose**: Confirm a green baseline on top of SPEC-010.

- [X] T001 Confirm SPEC-010 (reservation rooms) is migrated and committed or stashed apart from this branch's work. Run `php artisan test` against Postgres and record any failures before starting, in `specs/003-room-type-availability/tasks.md` (a note under this task)
  - Baseline 2026-09-24 on `003-room-type-availability` (branched from SPEC-010 commit a6d0ea4): `php artisan test` → 759 passed. `--parallel` shows 23 failures from helpers defined in other test files (`adminWithHotel()`, `apiHeaders()`), unrelated to this feature; run the suite serially.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The enum helpers, permissions, index and the calculation every story uses.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 [P] Add `public static function holdingInventory(): array` returning `[self::PENDING, self::CONFIRMED, self::CHECKED_IN]` with a docblock (no_show from SPEC-012 must not be added) in `app/Enums/ReservationStatus.php`
- [X] T003 [P] Add `public static function outOfOrder(): array` returning `[self::MAINTENANCE]` with a docblock saying SPEC-003 changes it to `[OUT_OF_ORDER]` in `app/Enums/RoomStatusesEnum.php`
- [X] T004 [P] Add `case AVAILABILITY_VIEW = 'availability.view';` (own group) and `case RESERVATIONS_OVERBOOK = 'reservations.overbook';` (next to the `reservations.*` cases). Add `self::AVAILABILITY_VIEW` to `employeeDefaults()` with a comment: read-only, reveals nothing sensitive to staff, needed to book safely (spec clarification Q5). `RESERVATIONS_OVERBOOK` is NOT a default. File: `app/Enums/Permission.php`
- [X] T005 [P] Create migration `database/migrations/2026_09_23_000007_add_date_index_to_reservations_table.php` (use the next free sequence number) adding an index on `reservations (hotel_id, arrival_date, departure_date)`, with a `down()` that drops it
- [X] T006 Create `app/Services/AvailabilityService.php` with `forHotel(Hotel $hotel, string $arrivalDate, string $departureDate, ?array $roomTypeIds = null): array`. It runs exactly three queries (research R2):
  1. Room types of the hotel: all non-deleted types when `$roomTypeIds` is given, otherwise `is_active = true` only, ordered by name.
  2. Room counts per type: `count(*)` and `count(*) FILTER (WHERE status IN RoomStatusesEnum::outOfOrder())` for non-deleted rooms.
  3. Booked lines per `(room_type_id, night)`: holding lines joined to `reservations`, expanded with a lateral `generate_series(greatest(arrival_date, :from), least(effective_departure, :to) - interval '1 day', interval '1 day')`, where `effective_departure = departure_date` except for `checked_in`, where it is `:today_plus_one` when `departure_date < :today` (a guest past their departure date holds tonight; one due out today does not).

  Build the full type × night grid in PHP. Each cell is `{date, total, out_of_order, booked, sellable = max(0, total − out_of_order − booked), overbooked = max(0, booked − (total − out_of_order))}`, and each type gets `bookable_for_stay = min(sellable)`. "Today" is `now($hotel->timezone)->toDateString()`.
- [X] T007 Add `assertRange(Hotel $hotel, string $arrivalDate, string $departureDate): void` to `app/Services/AvailabilityService.php`. It throws `ValidationException` when `departure_date` is not after `arrival_date`, when the range is longer than 90 nights, or when `arrival_date` is before the hotel's today (`now($hotel->timezone)`). Messages are keyed `arrival_date` / `departure_date`
- [X] T008 Create `tests/Feature/AvailabilityServiceTest.php` with a file-local helper that builds a hotel, room types, rooms and reservations with lines. Cover:
  - the basic formula (US1 scenarios 1–3)
  - departure night not counted and back-to-back stays (US1-4)
  - cancelled lines, and cancelled, checked-out and soft-deleted reservations, counting 0 (US1-5)
  - pending and confirmed counting the same (clarification Q2)
  - a checked-in overstay still booked tonight
  - a `maintenance` room counted out of order on every night, including future nights
  - deleted rooms excluded
  - an inactive type excluded by default but returned when named
  - the placeholder "Unspecified (migrated)" type not affecting real types
  - `assertRange()` rejections at 91 nights, departure ≤ arrival, and yesterday in the hotel's timezone
  - `forHotel()` runs exactly 3 queries for both a 1-night and a 90-night lookup (`DB::enableQueryLog()`)

**Checkpoint**: The calculation is correct and tested; stories can start.

---

## Phase 3: User Story 1 - Staff check how many rooms of each type are free (Priority: P1) 🎯 MVP

**Goal**: `GET /api/availability` returns the per-type, per-night availability to authorized
staff.

**Independent Test**: With 5 Deluxe, 1 in maintenance and 2 Deluxe lines on 13 March,
`GET /api/availability?arrival_date=…-12&departure_date=…-15` shows sellable 4/2/4 and
`bookable_for_stay` 2.

### Tests for User Story 1

- [X] T009 [P] [US1] Create `tests/Feature/AvailabilityControllerTest.php`. Cover:
  - the response shape exactly as in `contracts/availability-api.md`
  - `room_type_ids[]` limiting the result
  - a named inactive type returned
  - a 422 for `departure_date` not after `arrival_date`, more than 90 nights, or `arrival_date` before today
  - a 422 with the same message for an unknown id, another hotel's id and a deleted type id
  - a super admin without `hotel_id` → 403, and with `hotel_id` → 200
  - an employee with no staff role → 200 (default permission)
  - an employee whose role lacks `availability.view` → 403
  - a lookup writes no audit entry (the `event_logs` count is unchanged, FR-022)
- [X] T010 [P] [US1] Add an availability case to `tests/Feature/TenantIsolationTest.php`: hotel B's rooms and lines never appear in hotel A's lookup, and hotel B's `room_type_ids` are rejected for hotel A
- [X] T011 [P] [US1] Update `tests/Feature/PermissionAuthorizationTest.php`:
  - add `'availability' => ['/api/availability?arrival_date=<today+30>&departure_date=<today+31>', Permission::AVAILABILITY_VIEW]` to the dataset. Using dates 30 days out keeps the test from failing if it runs across midnight
  - add the same URL to the `$allowed` loop of the employee-defaults test
  - add a test that `employeeDefaults()` contains `AVAILABILITY_VIEW` and not `RESERVATIONS_OVERBOOK`

### Implementation for User Story 1

- [X] T012 [P] [US1] Add `viewAvailability(User $user): bool` returning `$this->allows($user, Permission::AVAILABILITY_VIEW)` to `app/Policies/RoomTypePolicy.php`. The existing `before()` super-admin bypass stays
- [X] T013 [P] [US1] Create `app/Http/Requests/AvailabilityRequest.php` with these rules:
  - `arrival_date`: `required|date_format:Y-m-d`
  - `departure_date`: `required|date_format:Y-m-d|after:arrival_date`
  - `room_type_ids`: `sometimes|array`
  - `room_type_ids.*`: `uuid|distinct`
  - `hotel_id`: `sometimes|uuid`
- [X] T014 [P] [US1] Create `app/Http/Resources/AvailabilityResource.php`. It wraps the `forHotel()` array into `{arrival_date, departure_date, nights, room_types: [{room_type: {id, name, max_occupancy, adult_capacity, child_capacity, is_active}, bookable_for_stay, nights: [{date, total, out_of_order, booked, sellable, overbooked}]}]}`
- [X] T015 [US1] Create `app/Http/Controllers/AvailabilityController.php` with `index(AvailabilityRequest $request)`:
  1. `$this->authorize('viewAvailability', RoomType::class)`
  2. `resolveHotel($request->user(), $request->validated('hotel_id'))`; 403 "You must belong to, or specify, a valid hotel." when null
  3. Reject `room_type_ids` that are not live types of that hotel with a 422 and one generic message
  4. `AvailabilityService::assertRange()`
  5. `forHotel()`
  6. Return `apiResponse('Availability retrieved successfully.', 200, AvailabilityResource::make(...))`
- [X] T016 [US1] Register `Route::get('/availability', [AvailabilityController::class, 'index']);` inside the `auth:sanctum` / `throttle:api` / `tenant` group in `routes/api.php`
- [X] T017 [P] [US1] Create `docs/availability-api-documentation.md` from `contracts/availability-api.md` (endpoint, parameters, response, errors, permission)
- [X] T018 [P] [US1] Add `availability.view` (`GET /api/availability`, marked as an employee default) to the permission reference in `docs/staff-roles-api-documentation.md`

**Checkpoint**: Staff can look up availability; US1 is shippable on its own.

---

## Phase 4: User Story 2 - Reservations cannot oversell a room type (Priority: P1)

**Goal**: Reservation create/update is rejected with structured shortfalls when it would
oversell. Staff with `reservations.overbook` can override, the override is audited, and the
AI never can. The import is exempt. Competing bookings run one at a time.

**Independent Test**: With 2 Deluxe sellable on 13 March, `POST /api/reservation` for 3
Deluxe (12–15 March) returns 422 with `shortfalls: [{Deluxe, [{2026-03-13, short: 1}]}]`.
The same request with `overbook_override: true` from an admin returns 201 and writes a
`reservation.overbooking_overridden` audit entry.

### Tests for User Story 2

- [X] T019 [P] [US2] Create `tests/Feature/ReservationAvailabilityGuardTest.php`. Cover:
  - create within availability → 201
  - create short → 422 with `shortfalls`, and no reservation or lines saved
  - adding a line, extending dates, or moving dates onto a full night → 422; the reservation's own current lines are not counted against it
  - re-creating a soft-deleted reservation (same `reservation_id`) onto a full night → 422, the reservation stays deleted, and its old lines stay as they were (US2-7)
  - removing lines, shortening dates or cancelling → saved without a check
  - a special-requests-only edit and `pending` → `confirmed` on an already-overbooked night → saved
  - `overbook_override: true` from an employee without `reservations.overbook` → 403 with nothing saved
  - from an employee with the permission → 201 plus an audit entry with the actor and `changes.shortfalls`
  - from an admin → 201
  - `shortfalls` lists only the request's own room types
- [X] T020 [P] [US2] Add to `tests/Feature/ReservationAvailabilityGuardTest.php`:
  - `CreateReservationTool` over availability returns the shortfall message and saves nothing
  - `ReservationsImport` over availability saves the rows, and `forHotel()` then reports `overbooked > 0`
- [X] T021 [US2] Create `tests/Feature/ReservationAvailabilityConcurrencyTest.php` using `DatabaseTruncation`, not `RefreshDatabase`. The data must be committed so a second connection can see it. Register a second connection (`config(['database.connections.pgsql_b' => config('database.connections.pgsql')])`). With 1 Deluxe sellable:
  1. On `pgsql_b`, begin a transaction, `SELECT … FROM room_types WHERE id = :deluxe FOR UPDATE`, and insert a holding line for the last room.
  2. On the default connection, inside `DB::transaction`, run `SET LOCAL lock_timeout = '1s'` and call `ReservationCreator::create()` for 1 Deluxe. Expect a `QueryException` with SQLSTATE `55P03` (lock not available). That proves the lock serializes bookings.
  3. Commit `pgsql_b`, then call `ReservationCreator::create()` again. Expect `InsufficientAvailabilityException`.
  4. Assert exactly one holding Deluxe line exists for the night (SC-003).

### Implementation for User Story 2

- [X] T022 [P] [US2] Create `app/Exceptions/InsufficientAvailabilityException.php` extending `Illuminate\Validation\ValidationException`:
  - holds `array $shortfalls` (`[{room_type_id, room_type_name, nights: [{date, short}]}]`)
  - builds the `rooms` message, e.g. "Not enough rooms available: Deluxe is short by 1 on 2026-03-13."
  - `render()` returns a 422 JSON body `{message, errors: {rooms: [...]}, shortfalls}`
- [X] T023 [US2] Add three methods to `app/Services/AvailabilityService.php`:
  - `lockTypes(string $hotelId, array $roomTypeIds): void`: `RoomType::withoutGlobalScope('hotel')->withTrashed()->where('hotel_id', …)->whereIn('id', …)->orderBy('id')->lockForUpdate()->get()`
  - `footprint(Reservation $reservation): array`: `(room_type_id, date) → count` of the reservation's own holding lines, using the same night rules as T006
  - `guard(Reservation $reservation, array $before, bool $override): void`: finds cells where the new footprint is greater than `$before`. If there are none it returns. Otherwise it runs `forHotel()` for those types over the reservation's nights and collects cells with `total − out_of_order − booked < 0`. With no failures it returns. If `$override` is set and `EventLogger::currentActorKind() !== ActorKind::AI_AGENT`, it records `EventLogger::record($reservation, 'overbooking_overridden', changes: ['shortfalls' => …])`. Otherwise it throws `InsufficientAvailabilityException`
- [X] T024 [US2] Update `app/Support/Reservations/ReservationCreator.php`:
  - add a `bool $overbookOverride = false` parameter to `create()` and `update()`
  - rename `create()`'s `$skipCapacity` to `$recordAsIs` (skips both capacity and the guard)
  - at the start of each transaction, call `lockTypes()` with the reservation's live line types plus the requested new types
  - take `footprint()` before the write (`[]` for a new reservation or a restored trashed one)
  - after the lines, `syncStay` and the capacity check, call `guard()` unless `$recordAsIs`
- [X] T025 [US2] Update `app/Imports/ReservationsImport.php` to pass `recordAsIs: true` instead of `skipCapacity: true`. Fix any other caller or test that uses the old parameter name (`grep -rn skipCapacity app tests`)
- [X] T026 [P] [US2] Add `overbook(User $user): bool` returning `$this->allows($user, Permission::RESERVATIONS_OVERBOOK)` to `app/Policies/ReservationPolicy.php`
- [X] T027 [P] [US2] Add `'overbook_override' => ['sometimes', 'boolean']` to `app/Http/Requests/StoreReservationRequest.php` and `app/Http/Requests/UpdateReservationRequest.php`
- [X] T028 [US2] Update `store()` and `update()` in `app/Http/Controllers/ReservationController.php`:
  - add `'overbook_override'` to the `unsetAttributes()` list
  - when `$request->boolean('overbook_override')`, call `$this->authorize('overbook', Reservation::class)` before any write
  - pass `$request->boolean('overbook_override')` to `ReservationCreator::create()/update()`
- [X] T029 [US2] Confirm `app/Ai/Tools/CreateReservationTool.php` exposes no override input and still catches `ValidationException` (which now includes shortfalls). Update the class docblock: it cannot override the availability check either
- [X] T030 [P] [US2] Update `docs/reservations-api-documentation.md` with the `overbook_override` field, the 422 shortfall body, the 403 for an override without permission, and the list of changes that are not checked
- [X] T031 [P] [US2] Add `reservations.overbook` (`overbook_override` on `POST`/`PUT /api/reservation`, not a default) to the permission reference in `docs/staff-roles-api-documentation.md`

**Checkpoint**: Overselling is blocked; US1 + US2 make up the P1 release.

---

## Phase 5: User Story 3 - Staff see availability as a grid across dates (Priority: P2)

**Goal**: The endpoint works as a planning grid over many types and nights, and flags
overbooked nights.

**Independent Test**: A 14-night lookup with 4 active types returns 56 cells. An overbooked
night shows `sellable: 0` and `overbooked > 0`. A type with no rooms shows zeros.

- [X] T032 [P] [US3] Add grid tests to `tests/Feature/AvailabilityControllerTest.php`:
  - 14 nights × 4 active types = 56 cells, types ordered by name
  - an overbooked night (created with an override) shows `sellable` 0 and `overbooked` 1
  - a type with no rooms returns all zeros with a 200
  - nights are contiguous with no gaps
- [X] T033 [US3] Fix anything T032 exposes in `app/Services/AvailabilityService.php` (for example, missing zero cells or ordering). No new endpoint is needed
  - Nothing to fix: the T032 grid tests passed against the Phase 2 service as written.

**Checkpoint**: The grid view is fully backed by the API.

---

## Phase 6: User Story 4 - The AI answers availability questions from live data (Priority: P2)

**Goal**: The Admin AI gets the full grid; the Concierge gets only yes/no per type for the
guest's own hotel.

**Independent Test**: For the same hotel and dates, the Admin AI tool returns the same
numbers as the endpoint, and the Concierge tool returns `available: true/false` with no
counts.

### Tests for User Story 4

- [X] T034 [P] [US4] Create `tests/Feature/AvailabilityToolsTest.php`. Cover:
  - `GetAvailabilityTool` output equals `AvailabilityService::forHotel()` for the same input
  - a user without `availability.view` gets a refusal message
  - an unknown `room_type` name gets a clear message
  - a range error returns the `assertRange()` message
  - `GetGuestAvailabilityTool` returns `[{name, description, max_occupancy, adult_capacity, child_capacity, available}]` for active types only
  - its JSON contains none of the keys `total`, `booked`, `sellable`, `out_of_order`, `overbooked`, `room_number`
  - for a sold-out named type it returns `available: false` first, and every other active type is still listed
  - both tools reject a 32-night range with the 31-night message
  - hotel B's types never appear in a hotel A tool
- [X] T035 [P] [US4] Add to `tests/Feature/TenantIsolationTest.php`: both tools, built with hotel A, return nothing about hotel B's room types or lines

### Implementation for User Story 4

- [X] T036 [P] [US4] Create `app/Ai/Tools/GetAvailabilityTool.php` (`implements Laravel\Ai\Contracts\Tool`, constructor `Hotel $hotel, User $user`):
  - schema: `arrival_date` (required, `YYYY-MM-DD`), `departure_date` (required), `room_type` (optional name, matched case-insensitively among the hotel's live types)
  - `handle()` refuses unless `$user->hasPermission(Permission::AVAILABILITY_VIEW)`, catches `ValidationException` from `assertRange()` and returns the message, and otherwise returns the `forHotel()` grid JSON
  - it also refuses ranges longer than **31 nights**, with the message "I can check at most 31 nights at a time; ask again for the rest." (keeps token cost bounded; FR-015)
  - Implemented through `assertRange(..., AvailabilityService::AI_MAX_NIGHTS)`, so the message is the shared "Availability can be checked for at most 31 nights at a time." for both tools.
- [X] T037 [P] [US4] Create `app/Ai/Tools/GetGuestAvailabilityTool.php` (constructor `Hotel $hotel`). It has the same schema and the same 31-night limit. It returns JSON `[{name, description, max_occupancy, adult_capacity, child_capacity, available: bookable_for_stay > 0}]` for **every** active type, whether or not `room_type` is given. A named type (matched case-insensitively) comes first, and an unknown name adds a note that there is no such room type. It never returns counts, room numbers or reservation data. Its description says it answers whether a room type can be booked for the dates
- [X] T038 [US4] Register `new GetAvailabilityTool($this->user->hotel, $this->user)` in the Read section of `tools()`. Add one instruction line: live room availability comes only from the availability tool; never guess it or take it from knowledge documents. File: `app/Ai/Agents/AdminAdvisorAgent.php`
- [X] T039 [US4] Register `new GetGuestAvailabilityTool($this->hotel)` in `tools()`. Add one instruction line: check room availability only with the availability tool, never share room counts, and offer other available room types when the one asked for is not available. File: `app/Ai/Agents/GuestConciergeAgent.php`

**Checkpoint**: All four stories are complete.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T040 [P] Create `docs/latest-changes-<date>.md` for the date of implementation. It covers the new `GET /api/availability`, the new permissions (`availability.view` is an employee default), the new `overbook_override` field, and the behavior change that reservations which used to oversell now get a 422 with `shortfalls`
  - Written as `docs/latest-changes-2026-09-24.md`.
- [X] T041 [P] Add the availability request and the override example to the Postman collection under `docs/postman/`, if it lists reservation endpoints
  - Not applicable: no collection in `docs/postman/` lists reservation endpoints.
- [X] T042 Run the manual performance check from `quickstart.md` (500 rooms, about 15k lines, 90 nights, under 2 s) and record the result under this task
  - 2026-09-24, test DB, 500 rooms / 6 types / 15k reservations: `GET /api/availability` over 90 nights took 52 ms warm (1.45 s on the first, cold request including app boot); `forHotel()` alone 45 ms. Target under 2 s: met.
- [X] T043 Run `./vendor/bin/pint` and `php artisan test`. Walk through every row of the `quickstart.md` scenario table and confirm each passes
  - `./vendor/bin/pint --test` passed; `vendor/bin/pest` → 825 tests, 0 failures (serial run).

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)** → **Foundational (Phase 2)** → the user stories.
- **US1 (Phase 3)** needs only Phase 2.
- **US2 (Phase 4)** needs Phase 2. It does not need US1: its tests call the reservation
  endpoints and the service directly. Ship it with US1 as the P1 release.
- **US3 (Phase 5)** needs US1 (the endpoint). T032's overbooked case needs US2's override
  path, or can seed an overbooked night through the import.
- **US4 (Phase 6)** needs only Phase 2.
- **Polish (Phase 7)** comes after all the stories.

### Within phases

- T006 → T007 → T008 (same file / tests the service).
- T012–T014 are parallel, then T015 → T016.
- T022, T026 and T027 are parallel, then T023 → T024 → T025 → T028 → T029.
- T036 and T037 are parallel, then T038 and T039.

### Parallel opportunities

- Phase 2: T002, T003, T004 and T005 together.
- US1: T009, T010, T011, T012, T013, T014, T017 and T018 together.
- US2: T019, T020, T022, T026, T027, T030 and T031 together.
- US4: T034, T035, T036 and T037 together.
- After Phase 2, US1, US2 and US4 can be built by different people at the same time.
  They share only `tests/Feature/TenantIsolationTest.php` and
  `docs/staff-roles-api-documentation.md`, so merge those files with care.

## Parallel Example: User Story 2

```text
Task: "T019 guard tests in tests/Feature/ReservationAvailabilityGuardTest.php"
Task: "T022 InsufficientAvailabilityException in app/Exceptions/InsufficientAvailabilityException.php"
Task: "T026 ReservationPolicy::overbook() in app/Policies/ReservationPolicy.php"
Task: "T027 overbook_override rule in Store/UpdateReservationRequest"
Task: "T030 reservations API docs"
```

## Implementation Strategy

### MVP first

1. Phases 1–2 (the calculation, tested).
2. Phase 3 (US1): staff can look up availability. Stop and validate with quickstart
   rows 1–4 and 12–13.

### P1 release

3. Phase 4 (US2): the overbooking guard. Validate quickstart rows 5–11. Ship US1 and US2
   together, with the `latest-changes` note (T040), because US2 changes how reservation
   writes behave.

### Incremental

4. Phase 5 (US3) for the grid, then Phase 6 (US4) for the AI tools, each validated on its
   own.
5. Phase 7 polish. The frontend slice (grid, override prompt) follows in
   `ecosystem-frontend` (D13).
