# Research: Room-Type Availability

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-09-23

Starting point, confirmed in the code on 2026-09-23: SPEC-010 is in the working tree.
`reservation_rooms` exists, `ReservationCreator::create()/update()` write lines in one
transaction, and `ReservationRoomSync` holds the line rules. Nothing works out availability
today. `rooms.status` is still `available / occupied / maintenance`, because SPEC-003 (status
split) has not landed. `hotels.timezone` exists (default `UTC`). `reservations` has no index
on its dates.

---

## R1 — Where the logic lives

- **Decision**: New `App\Services\AvailabilityService`. It has four callers: the
  availability endpoint, two AI tools and `ReservationCreator`.
- **Rationale**: CLAUDE.md puts business logic that has more than one caller in
  `app/Services`. Every caller uses the same method, which is what FR-005 (same numbers
  everywhere) needs.
- **Alternatives**: Putting the logic on `ReservationRoomSync` was rejected. That class
  holds the rules for one reservation's lines, and availability is a hotel-wide read. A
  query scope on `RoomType` was rejected because the result is a grid, not a set of models.

## R2 — How it is worked out

- **Decision**: Three queries per call, whatever the range length:
  1. Room types in scope (id, name, capacities, `is_active`).
  2. Room counts per type: `count(*)` and `count(*) FILTER (WHERE status IN out-of-order
     statuses)`, for rooms that are not soft-deleted, grouped by `room_type_id`.
  3. Booked lines per type and night. Holding lines are joined to their reservation, and
     each line is expanded into nights with a lateral `generate_series(greatest(arrival,
     :from), least(effective_departure, :to) - 1, '1 day')`, grouped by `(room_type_id,
     night)`.

  PHP then fills the full type × night grid (missing cells are 0) and works out
  `sellable`, `overbooked` and each type's minimum over the range.
- **Rationale**: Postgres does the date expansion, so the work grows with the number of
  lines, not rooms × nights. A 500-room hotel over 90 nights has about 15k line-nights,
  which one grouped query handles well within SC-005 (under 2 s).
- **Alternatives**: Loading lines and counting them in PHP was rejected (more memory,
  more data sent from the database). A stored per-night inventory table was rejected
  because the spec says availability is not stored (it would go stale, and every write
  path would have to keep it in sync).

## R3 — What counts as a holding line

- **Decision**: A line holds inventory when all of these are true:
  - `reservation_rooms.status <> 'cancelled'`
  - the line and its reservation are not soft-deleted
  - `reservations.status` is one of `ReservationStatus::holdingInventory()`, a new static
    list: `pending`, `confirmed`, `checked_in`

  Its nights run from `arrival_date` up to, but not including, the effective departure.
  For a `checked_in` reservation whose `departure_date` is before the hotel's today, the
  effective departure is `hotel_today + 1`, so a guest who stays past their departure date
  keeps holding tonight. A guest due out today does not, so tonight can go to the next
  arrival (fixed after code review; the first version used
  `greatest(departure_date, hotel_today + 1)`, which also held tonight for guests due out
  today).
- **Rationale**: Covers FR-003 and clarification Q2 (pending holds rooms). When SPEC-012
  adds `no_show`, it will not be on the list, so it releases rooms automatically.
- **Alternatives**: Using the stay's status for in-house was rejected: the stay still
  mirrors the reservation 1:1 until SPEC-023, and the reservation status is enough.

## R4 — What counts as out of order

- **Decision**: A new `RoomStatusesEnum::outOfOrder()` list that today returns
  `[MAINTENANCE]`. SPEC-003 changes it to `[OUT_OF_ORDER]` when it renames the status (D5),
  and nothing else has to change. A room counts as out of order on every night looked up
  (clarification Q3).
- **Rationale**: SPEC-020 does not have to wait for SPEC-003, and there is only one place
  to update.
- **Alternatives**: Waiting for SPEC-003 was rejected because it is not on the critical
  path for availability.

## R5 — "Today" and nights

- **Decision**: `today = now($hotel->timezone)->toDateString()`, computed in PHP and passed
  into the query. Nights are plain dates, matching the `date` columns.
- **Rationale**: FR-007 and the spec's assumptions use the hotel's local date. The column
  already exists.

## R6 — The overbooking check

- **Decision**: `AvailabilityService::guard(Reservation, array $before, bool $override)`,
  called at the end of the `ReservationCreator::create()/update()` transaction, after the
  lines and dates are written.
  1. `footprint(Reservation)` returns this reservation's holding count per
     `(room_type_id, night)`. Take it before the write (empty for a new reservation) and
     again after.
  2. Cells that **increased** are the ones to check. If none increased, the change only
     freed rooms or was neutral (FR-009), and nothing is checked.
  3. Work out availability for those types and nights with the same R2 queries. The data
     already includes this reservation's new lines, so a cell fails when
     `total − out_of_order − booked < 0`.
  4. Failing cells become shortfalls, grouped by type: nights with the amount short.
- **Rationale**: The reservation's own current lines never count against it. Legacy nights
  that are already overbooked do not block edits that don't add to them (such as changing
  special requests or confirming a pending booking). Every trigger in FR-009 (new
  reservation, added lines, extended or moved dates, a trashed reservation restored by
  `create()`) is covered by one rule instead of a case per trigger.

  Bringing a cancelled reservation back reinstates its lines (SPEC-010 review fix, merged
  alongside this spec): its "before" footprint is empty and the "after" one holds the
  reinstated lines, so the same guard checks it with no special case. `update()` locks
  the types of all the reservation's lines, cancelled ones included, for that reason.
  `diff()` still rejects room-type changes. Re-creating a deleted reservation is a trigger: `create()` treats its old lines
  as cancelled, so the "before" footprint is empty.
- **Alternatives**: Checking only the new lines' nights was rejected because it misses date
  extensions. A per-trigger check was rejected because it drifts as triggers are added.

## R7 — Two bookings for the last room at once (FR-014, SC-003)

- **Decision**: At the start of the `create()/update()` transaction, before the
  "before" footprint is taken, lock the `room_types` rows the change could touch (the
  reservation's current live line types plus the requested new types) with
  `SELECT … FOR UPDATE`, in id order. Postgres READ COMMITTED gives each later statement a
  fresh snapshot, so the counts read after the lock include anything committed by the
  transaction that held it.
- **Rationale**: Bookings for the same room type in the same hotel run one at a time, and
  other types are not affected. Locking in id order avoids deadlocks between multi-type
  reservations. No retry loop is needed.
- **Alternatives**:
  - SERIALIZABLE isolation: rejected. It needs retry handling in every caller, including
    the AI tool and the import.
  - Postgres advisory locks on a hash: rejected. Hash collisions are possible and it is
    less clear than locking the rows themselves.
  - An exclusion constraint: rejected. It only fits physical-room overlap (SPEC-021), not
    counts.
- **How it is tested**: `RefreshDatabase` keeps each test's data in one transaction that
  is never committed, so a second connection cannot see it. A single PHP process also
  cannot wait on one connection while committing another. So the concurrency test lives in
  its own file using `DatabaseTruncation`, so its data is committed. It works like this:
  1. Connection B takes the Deluxe row lock and books the last room.
  2. Connection A sets `SET LOCAL lock_timeout = '1s'` and tries to book the same type.
     It must fail with a lock timeout, which proves it waited.
  3. B commits, and A retries. A must now fail with `InsufficientAvailabilityException`.

  This proves the serialization SC-003 needs without real parallel processes.
- **Known gap (accepted)**: A room being set out of order or deleted does not take the
  lock. A booking made at the same moment can therefore overbook that type by one. This is
  rare, done by staff, and shows up as overbooked in the grid.

## R8 — The override

- **Decision**:
  - Request flag `overbook_override` (boolean), named like `capacity_override`.
  - A new `ReservationPolicy::overbook()` ability checks `Permission::RESERVATIONS_OVERBOOK`
    through `allows()`.
  - The controller authorizes it only when the flag is `true`. Without the permission the
    request gets a 403 before anything is written (FR-011).
  - The service ignores the override when `EventLogger::currentActorKind()` is
    `AI_AGENT`, as `assertCapacity()` already does (FR-012). `CreateReservationTool` does
    not expose the flag.
  - When an override is used, an `EventLogger::record($reservation,
    'overbooking_overridden', changes: ['shortfalls' => …])` entry is written with the
    actor.
- **Rationale**: Clarification Q1. It follows the SPEC-010 capacity override pattern plus
  a real permission (the spec requires one).

## R9 — How a failure is reported

- **Decision**: `App\Exceptions\InsufficientAvailabilityException extends
  ValidationException`. It carries structured shortfalls. The message goes under the
  `rooms` key, like the other line errors. Its `render()` returns the standard 422
  validation body with an added `shortfalls` array.
- **Rationale**: Every caller already handles `ValidationException`.
  `CreateReservationTool` catches it and hands the AI the readable message (US2-5), the
  transaction rolls back, and the frontend gets structured data for its override prompt.
  A 409 status was rejected: the frontend and tools already treat line problems as 422.

## R10 — The lookup endpoint

- **Decision**: `GET /api/availability` in the tenant group of `routes/api.php`, handled
  by `AvailabilityController@index`.
  - Validation: `AvailabilityRequest` (dates, `room_type_ids[]` as UUIDs, `hotel_id` for
    super admins).
  - Range rules that need the hotel (`arrival_date` not before the hotel's today, at most
    90 nights) are checked by `AvailabilityService::assertRange()`, which throws
    `ValidationException`.
  - The response goes through `AvailabilityResource` and `apiResponse()`.
  - Authorization: `$this->authorize('viewAvailability', RoomType::class)`, a new
    `RoomTypePolicy` ability backed by `Permission::AVAILABILITY_VIEW`.
  - `room_type_ids` that are not the hotel's (another hotel's, deleted, or unknown) get a
    422 with the same message whichever it is (FR-021, no hint that they exist). Types
    named in `room_type_ids` are returned even when inactive. Without `room_type_ids`,
    only active types are returned (FR-006).
- **Rationale**: The endpoint has no model of its own, and availability is about room
  types, so the ability sits on the room type policy (as `import` does on
  `ReservationPolicy`). Keeping it inside a policy means the check goes through
  `ChecksPermissions`.
- **Alternatives**: A `Gate::define` in a service provider was rejected: CLAUDE.md wants
  permission checks to go through `allows()`.

## R11 — AI tools

- **Decision**:
  - `GetAvailabilityTool(Hotel, User)` for `AdminAdvisorAgent`. It takes
    `arrival_date`, `departure_date` and an optional `room_type` name. It refuses if the
    user lacks `availability.view` (FR-015). It returns the per-type, per-night grid plus
    `bookable_for_stay`, for at most **31 nights** per call. 10 types × 31 nights is about
    300 cells, which keeps the token cost bounded. The model can call it again for longer
    periods.
  - `GetGuestAvailabilityTool(Hotel)` for `GuestConciergeAgent`. It takes the same inputs
    and returns only `[{name, description, max_occupancy, adult_capacity, child_capacity,
    available: bool}]` for **every** active type, with a named type first (FR-016,
    clarification Q4). It uses the same 31-night limit.
  - Both use `AvailabilityService` and `assertRange()`. Range errors come back as the
    message string, like the other tools do.
  - The instructions for both agents get one line: availability comes only from this tool
    and must never be guessed or taken from knowledge documents (FR-017).
  - Reads are not audited (FR-022).
- **Rationale**: Each tool is built with a hotel, as every existing tool is, so the model
  cannot choose a hotel (FR-021).

## R12 — Permissions

- **Decision**:
  - Add `AVAILABILITY_VIEW = 'availability.view'`, in its own group, and
    `RESERVATIONS_OVERBOOK = 'reservations.overbook'`, next to the other `reservations.*`
    cases.
  - Add `AVAILABILITY_VIEW` to `employeeDefaults()`, with a comment giving the reason
    (read-only, needed to book safely; clarification Q5). `RESERVATIONS_OVERBOOK` is not a
    default.
  - `GET /api/permissions` picks both up automatically.
- **Tests**: Add `availability` → `['/api/availability?…', AVAILABILITY_VIEW]` to the
  dataset in `PermissionAuthorizationTest`. The overbook permission is tested in
  `ReservationAvailabilityGuardTest` (allowed with it, 403 without it), because it guards
  a flag on an existing endpoint, not a route of its own.

## R13 — The import

- **Decision**: Rename `ReservationCreator::create()`'s `$skipCapacity` to `$recordAsIs`.
  It now skips both the capacity check and the inventory guard (FR-013).
  `ReservationsImport` is the only caller that passes it. The room-type locks are still
  taken, so imports and live bookings run one at a time.
- **Rationale**: Both checks are skipped for the same reason ("legacy data is recorded as
  it is"). One flag keeps the signature short.

## R14 — Indexes and performance

- **Decision**: A new migration adds an index on
  `reservations (hotel_id, arrival_date, departure_date)`. The existing
  `reservation_rooms (hotel_id, room_type_id)` and `(reservation_id, status)` indexes cover
  the line side.
  - A test fixes the number of queries: `forHotel()` runs exactly 3 for both a 1-night
    and a 90-night lookup.
  - The 2-second target (SC-005) is checked by hand with the quickstart seed, not in CI,
    because timing tests are flaky.
- **Rationale**: Without a date index, the booked-lines query scans every reservation of
  the hotel.

## R15 — Not in this spec

- Physical-room overlap across reservations, the DB-level constraint, and upgrades
  (SPEC-021).
- Dated out-of-order periods (SPEC-033).
- Changing housekeeping or room status.
- Rates, prices and stop-sell rules.
- The frontend grid and override prompt (in `ecosystem-frontend`, D13).
