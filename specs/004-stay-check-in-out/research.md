# Research: Stay Lifecycle and Check-in/out

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-09-24

The Technical Context has no open unknowns. This file records the design decisions, each
checked against the code as it stands on `main` (after SPEC-010 and SPEC-020).

## What exists today

| Piece | Today | Consequence for this feature |
| --- | --- | --- |
| `stays` | One per reservation: `unique(reservation_id)`, `room_id` = first live line's room (`Reservation::primaryRoomId()`) | Needs a per-line key and a new uniqueness rule |
| `StayService` | `syncFromReservation()` (planned side), `checkIn()`, `checkOut()`, `markNoShow()`, `markCancelled()`, `markExpected()` | Primitives are reused; the sync becomes per line |
| `ReservationCreator::syncStay()` | Drives the stay's **status from the reservation status** (PUT `checked_in` → `StayService::checkIn`) | Direction flips: reservation status now follows the stays for check-in/out |
| `ReservationCreator::syncRoomOccupancy()` | Room `occupied` ⇔ room is in `ReservationRoom::inHouseRoomIds()` | Kept, re-pointed at stays |
| `ReservationRoom::inHouseRoomIds()` | Lines joined to the reservation's single stay + in-house stays' rooms | Becomes "rooms of in-house stays" |
| `Stay::occupiedRoomsOn()` | Sums live lines per stay | Becomes a plain count of stays (FR-005) |
| `AvailabilityService::holdingNights()` | Line holds while reservation is `pending/confirmed/checked_in`; overstay rule keys on reservation status | Must drop departed lines (FR-015) and key overstay on the stay |
| Room status | `RoomStatusesEnum`: available / occupied / **maintenance** (`outOfOrder()` = maintenance). Housekeeping: clean / dirty / **blocked** | SPEC-003 (status split) is not built yet; see R8 |
| Teams / categories | No default Housekeeping team or cleaning category (SPEC-004 not built) | See R10 |
| Audit | `RecordsEvents` + `EventLogger`; `Stay::eventVerbFor()` already emits `stay.checked_in` / `stay.checked_out`; `EventLogger::asAiAgent()` marks AI writes while keeping the signed-in user as actor | Reused as is |
| `Reservation::stay()` (HasOne) | Used by pitching, bookings, outcome attribution, contact backfill, inbound WhatsApp job | See R3 |
| Tasks | No `stay_id`; `TaskController` validates relations through `invalidRelation()` | Add column + consistency rule |

## Decisions

### R1 — Stay key: `stays.reservation_room_id`

- **Decision**: Add a nullable `reservation_room_id` FK to `stays`, unique among
  non-deleted rows (partial unique index). Keep `stays.reservation_id` as a denormalised
  pointer (every existing reader, filter and index uses it). Drop `unique(reservation_id)`.
- **Rationale**: D3 — "uniqueness on the reservation room". Keeping `reservation_id`
  avoids touching the transaction, booking, pitching and dashboard queries. Nullable
  because legacy stays with no reservation (direct imports) exist and must survive.
- **Alternatives**: Replace `reservation_id` entirely — rejected, it churns ten readers
  for no gain. Put a `stay_id` on the line instead — rejected, the stay is the child.

### R2 — Line state stays on the stay, not on the line

- **Decision**: `ReservationRoomStatus` keeps `reserved` / `cancelled`. Whether a line is
  in-house or departed is read from its stay.
- **Rationale**: One source of truth for presence (constitution: "occupancy is derived
  from stays"). Two status columns that must agree would drift.
- **Alternatives**: Add `checked_in` / `checked_out` cases to the line — rejected.

### R3 — `Reservation::stay()` becomes "the primary stay"; add `stays()`

- **Decision**: Add `Reservation::stays()` (HasMany). Keep `stay()` for its five existing
  callers, redefined as the earliest-created non-cancelled stay
  (a `hasOne` ordered by `created_at, id` and filtered to non-cancelled; `ofMany` cannot
  be used because Postgres has no `min()` for uuid).
- **Rationale**: Those callers (pitching, booking attribution, outcome attribution,
  contact timestamps, inbound WhatsApp) are guest-level: any live stay of the reservation
  is correct, and the pitching rules (D10) are per guest stay. Changing them to per-room
  semantics is Phase 7/10 work.
- **Alternatives**: An `is_primary` flag — rejected, it needs maintenance when the first
  line is cancelled.

### R4 — Stay sync: `StayService::syncForReservation()` replaces `syncFromReservation()`

- **Decision**: One idempotent method called wherever `ReservationCreator` calls
  `syncStay()` today. For every line: create the stay if missing (`expected`); for live
  lines with an expected stay, copy the planned side (dates, guest, room, revenue share,
  party share); cancelled line → expected stay becomes `cancelled`; reinstated line →
  cancelled stay becomes `expected`. In-house stays also copy the planned side (room move,
  extension, corrected party) but never their check-in time; departed stays are never
  touched. A reservation with no lines (legacy rows) keeps its one line-less stay.
- **It no longer reads the reservation status to check guests in or out.** That now only
  happens in `StayLifecycleService` (R5) — except for the import (R14).
- **Revenue share**: `reservation_value` split evenly across live lines, remainder cents
  on the first line (spec assumption). **Party share**: the primary stay carries the
  reservation's `adults` / `children`; other stays carry 0 / 0, so sums across stays
  still equal the reservation (D8 keeps the party on the reservation).

### R5 — New `App\Services\StayLifecycleService` for check-in / check-out

- **Decision**: One service with `checkIn(Stay, ?roomId, ?at)`,
  `checkInReservation(Reservation, array $roomsByStay, ?at)`, `checkOut(Stay, ?at)`,
  `checkOutReservation(Reservation, ?at)`. It uses `StayService::checkIn()/checkOut()`
  for the stay write and `ReservationCreator::syncRoomOccupancy()` for room status.
- **Callers**: `StayCheckInController` / `StayCheckOutController` (single and whole
  reservation), the two Admin AI tools, and the deprecated reservation-edit path (R12).
  More than one caller → a service (CLAUDE.md layers).
- **Alternatives**: Put it inside `ReservationCreator` — rejected, that class is already
  420 lines and check-in is not a reservation write.

### R6 — Atomicity and concurrency

- **Decision**: Each action runs in one `DB::transaction`. Lock order, always the same:
  reservation row → its lines/stays (`FOR UPDATE`, ordered by id) → the room rows
  involved (ordered by id). State checks run **after** the locks, so a second concurrent
  check-in sees `in_house` and becomes the idempotent no-op (FR-010).
- **DB guarantee**: partial unique index `stays(room_id) WHERE status = 'in_house' AND
  deleted_at IS NULL` — one in-house stay per room, whatever path writes.
- **Rationale**: Mirrors SPEC-020's lock-then-check pattern. The index turns a missed
  check into an error rather than two guests in one room.

### R7 — Check-in prerequisites and messages

Evaluated per line, all reasons collected (so a whole-reservation check-in can list
every failing line — FR-009):

| Check | Failure key / message |
| --- | --- |
| Reservation `confirmed` or `checked_in` | `reservation_status` — "Reservation is {status}; only confirmed reservations can be checked in." |
| Line not cancelled / stay `expected` | `stay_status` — "This room is {status}." (in-house → idempotent no-op instead) |
| Hotel day ≥ arrival and < departure | `dates` — "Arrival is {date}." / "Departure date has passed; correct the reservation dates first." |
| Room assigned or named (R9) | `room` — "Assign or name a room first." |
| Room not out of order (R8) | `room` — "Room {n} is out of order." |
| No other in-house stay in the room | `room` — "Room {n} is occupied." |

Housekeeping status is never a failure: a room that is not `clean` adds a
`warnings[]` entry "Room {n} is {status}." (clarification Q2).

Rejections are a `CheckInOutException extends ValidationException` (422), shaped like
`InsufficientAvailabilityException`: `errors` keyed by `stays.{stay_id}.{key}`.

### R8 — Bridging SPEC-003 (room status split) — not built yet

- **Decision**: Add `Room::isOutOfOrder()`: status in `RoomStatusesEnum::outOfOrder()`
  **or** housekeeping status `blocked`. Check-out sets housekeeping `dirty` unless it is
  `blocked` (which today *is* the out-of-order marker; the overnight job does the same).
  Check-out sets room status to `available` only if it is `occupied` (existing
  `syncRoomStatus()` rule), so `maintenance` survives.
- **Rationale**: D5 migrates `blocked` → `out_of_order` and `maintenance` →
  `out_of_order`; when SPEC-003 lands, `isOutOfOrder()` becomes one comparison and the
  `blocked` special-case disappears. No behavior here depends on SPEC-003's migration.

### R9 — Assigning a room at check-in (clarification Q1), bridging SPEC-021

- **Decision**: New `App\Support\Reservations\RoomAssignmentRules::assertAssignable(line,
  room)`: same hotel, `room.room_type_id === line.room_type_id`, not deleted, and no
  other live line on a holding reservation has that room on an overlapping night
  (`arrival < other.departure AND other.arrival < departure`). The line's `room_id` is
  written through the model (audited as `reservation_room.updated`). Rejected when the
  line already has a different room.
- **Rationale**: SPEC-021 will own assignment and add the DB exclusion constraint; it
  reuses this class instead of duplicating it. The room row lock (R6) serializes two
  desks naming the same room.

### R10 — Housekeeping defaults are hotel settings (bridges SPEC-004)

- **Decision**: Add two nullable columns to `hotels`: `housekeeping_team_id` (FK →
  `teams`, `nullOnDelete`) and `cleaning_task_category_id` (FK → `task_categories`,
  `nullOnDelete`). Admins set them through the existing `PUT /api/hotel/{id}` (hotel
  settings stay admin-only, CLAUDE.md rule 4); both must belong to that hotel, the team
  must be active, and the category must belong to the chosen team.
  `App\Support\Housekeeping\HousekeepingDefaults::for(Hotel)` reads the two columns and
  returns each model or null (a deleted/inactive choice counts as null). The cleaning task
  is created without them when null (FR-013). SPEC-004 fills the columns when it creates
  the default teams for every hotel.
- **Rationale**: The constitution forbids language-specific business logic in domain
  services and requires Arabic support. A name lookup ("Housekeeping" / "Cleaning")
  silently fails for a hotel that names its team in Arabic. Ids are language-neutral and
  make the choice explicit.
- **Alternatives**: Find the team by English name — rejected (constitution). A
  `system_key` column on teams/categories — rejected here, that is SPEC-004's model to
  design. Build SPEC-004 inside this feature — rejected, it is its own spec with a
  backfill for every hotel.

### R11 — Cleaning task shape

`created_by = system`, `status = pending`, `priority = normal`, `title` "Clean room {n}
after check-out", `room_id`, `reservation_id`, `guest_id`, `stay_id`, team/category from
R10, `due_date` null. Created inside the check-out transaction, so it exists exactly
when the check-out does (FR-014, SC-006). **No notification** is sent: housekeeping
notifications with de-duplication are SPEC-030 (Phase 5).

### R12 — Deprecated path: `PUT /reservation/{id}` with `checked_in` / `checked_out`

- **Decision**: `ReservationController::update` detects a status change to `checked_in`
  / `checked_out`, removes it from the attributes, authorizes `stays.check_in` /
  `stays.check_out` on top of `reservations.update`, applies the other attributes through
  `ReservationCreator::update`, then calls `StayLifecycleService::checkInReservation()` /
  `checkOutReservation()` — all in one transaction. The response adds a `Deprecation:
  true` header, a `Link: </api/reservation/{id}/check-in>; rel="successor-version"`
  header, and appends "Setting status to checked_in here is deprecated; use POST
  /reservation/{id}/check-in." to `message`.
- **Create**: `POST /reservation` with `checked_in` → created `confirmed`, then the same
  deprecated whole check-in; with `checked_out` → 422 (only the import records history).
- **AI**: `CreateReservationTool` only accepts `pending` / `confirmed`.
- **Rationale**: D4, clarification Q3 (accept now, reject later). `apiResponse()` has no
  meta slot, so the notice rides in headers + message.

### R13 — Guards on the reservation edit (FR-021)

`ReservationCreator::update` rejects (422, key `status` / `rooms.{i}`) cancelling the
reservation, or cancelling/removing a line, while any affected stay is `in_house`.
`ReservationController::destroy` rejects deleting a reservation with an in-house stay
and otherwise soft-deletes its stays in the same transaction (spec edge case "its stays
are deleted with it"). Moving an in-house line to another room stays allowed (SPEC-010
already permits it); the stay's room follows and the vacated room is marked `dirty`.

### R14 — Import records history directly

`ReservationCreator::create(..., fromImport: true)` keeps calling the `StayService`
primitives from the imported status for every live line (`checked_in` → in-house,
`checked_out` → departed), with no room checks and no cleaning task (FR-022). This is the
only place the old status-to-stay direction survives.

### R15 — Availability: departed lines stop holding (FR-015)

`holdingNights()` left-joins `stays` on `reservation_room_id` and excludes lines whose
stay is `departed` (or `cancelled` / `no_show`). The overstay rule ("still holds
tonight") keys on `stays.status = 'in_house'` instead of the reservation status, so an
expected line on a checked-in reservation does not hold past its departure.

### R16 — Occupancy and in-house rooms

`ReservationRoom::inHouseRoomIds()` → `select room_id from stays where status =
'in_house' and room_id is not null and deleted_at is null` (every stay now carries its
line's room). `Stay::occupiedRoomsOn()` → `count(*)` of in-house/departed stays covering
the night (FR-005). `MakeRoomDirtyOvernightJob` needs no change beyond the new source.

### R17 — Actual times (clarification Q4)

- **Decision**: Optional `checked_in_at` / `checked_out_at` in the request. Valid when it
  is on the current hotel day (`hotel.timezone`), ≤ now, and for check-out ≥ the stay's
  `checked_in_at`. Otherwise 422. Nights = calendar days between the local dates of the
  two times (existing `StayService::checkOut()` logic, fed the hotel-local dates).
- **Audit**: the given time is in the stay event's `changes`; the entry time is the
  event's `occurred_at`. When a time was given, the same row also records
  `changes.entered_at`. Mechanism: `Stay` gets a public, non-persisted
  `array $auditExtras = []`; `Stay::loggedChangeSet()` is overridden to merge it into the
  parent's result; the service sets it just before the status `update()` and clears it
  after. One `stay.checked_in` / `stay.checked_out` row, never a second `EventLogger::record()`.

### R18 — Lists

- `GET /stays` — `GenericIndexRequest` + `GenericQuery` (filter by `status`, `room_id`,
  `guest_id`, `reservation_id`, planned dates; search on guest name / reservation code).
- `GET /stays/arrivals|departures|in-house?date=` — dedicated queries (the "late",
  "past departure" and "overdue" flags and the `≤ day` rules don't fit generic filters). Eager-load guest,
  reservation, line→room type, room: fixed query count, one index-backed scan on
  `(hotel_id, status, planned_arrival_date|planned_departure_date)` (existing indexes).
- **Rationale**: SC-008 (500 rooms, < 2 s) — at most a few hundred rows per day.

### R19 — Permissions

`STAYS_VIEW`, `STAYS_CHECK_IN`, `STAYS_CHECK_OUT` in `Permission`, **not** in
`employeeDefaults()` (clarification Q5). New `StayPolicy` (`viewAny`, `view`, `checkIn`,
`checkOut`) through `ChecksPermissions::allows()`; super-admin `before()` like the
others. Whole-reservation actions authorize against the reservation's hotel.

### R20 — Admin AI tools

`CheckInTool`, `CheckOutTool` (reservation code + optional room numbers; optional room
to assign per line; optional actual time under the R17 rule) and `GetStaysTool` (arrivals / departures / in-house for a date).
Built with `(Hotel, User)`, check `$user->hasPermission(...)` like `GetAvailabilityTool`,
run writes inside `EventLogger::asAiAgent()`, and return the service's messages verbatim
on failure. The Concierge gets no check-in tool; its instructions say to refer the guest
to the front desk (FR-025).

### R21 — Concierge task → stay (FR-019)

`CreateGuestServiceRequestTool` gains an optional `room_number`. Stay resolution: the
guest's in-house stays on the conversation's reservation; exactly one → link it and its
room; several → the one whose room matches `room_number`, else link reservation + guest
only (`room_id` null). Replaces today's `primaryRoomId()`.

### R22 — Migrating existing data (FR-004, SC-005)

One data migration, in a transaction, hotel scope dropped:

1. Each reservation's existing stay → `reservation_room_id` = its first line (by
   `created_at, id`, cancelled included — SPEC-010's backfill created exactly that line).
2. Every other line without a stay → new stay with the state the reservation implies:
   line cancelled or reservation cancelled → `cancelled`; reservation `checked_in` →
   `in_house` (copy `checked_in_at` from the primary stay); `checked_out` → `departed`
   (copy times and nights); else `expected`.
3. Recompute revenue and party shares (R4) for reservations with more than one line.
0. The backfill migration first drops `unique(reservation_id)` (the new stays share their
   reservation); its `down()` restores it.
4. Migration 3, before creating the one-in-house-per-room index, detect rooms with two in-house stays;
   if any exist, abort with the list (room, stays) so the data is fixed by hand. None
   are expected: SPEC-010 already forbids one room on two lines of a reservation, and
   `syncRoomStatus()` never allowed it across reservations.

Backfill `down()`: delete every stay but the earliest (`created_at, id`, live ones first)
of each reservation, set `reservation_room_id` to null, and restore
`unique(reservation_id)`. This also removes stays created after the migration for extra
lines — acceptable for a rollback, and stated in the migration docblock. Migration 3's
`down()` only drops its two indexes.

### R23 — Room status changes become audited (FR-028)

`Room` has no `RecordsEvents` today, and `ReservationCreator::syncRoomStatus()` writes
through the query builder, which fires no model events. Add `RecordsEvents` to `Room`
(`eventLoggedAttributes`: `room_number`, `room_type_id`, `floor`, `status`,
`housekeeping_status`) and change `syncRoomStatus()` and the check-out housekeeping
update to load the model and `update()` it, so every change is one `room.updated` row.
`MakeRoomDirtyOvernightJob` keeps its bulk update (a nightly system sweep, not a
check-in/out effect) — noted, not changed.

### R24 — Metering

`MeterFeature::STAYS` counts stay rows, so a multi-room reservation now counts one stay
per room. That matches the new meaning of a stay; announced in `latest-changes`.

## Implementation notes (2026-09-24)

Changes to the decisions above, found while building:

- **R3**: `stay()` is an ordered `hasOne`, not `ofMany` (Postgres has no `min()` for uuid).
- **R4**: in-house stays copy the whole planned side too, not just room and departure.
  A reservation with no lines (legacy rows) keeps one line-less stay.
- **R13 / FR-012a**: SPEC-010's rule "no line may be removed from a checked-in
  reservation" blocked cancelling a room that never arrived. `ReservationRoomSync::diff()`
  now allows removing a line whose guest never checked in. A status change away from
  `checked_in` while a guest is in is rejected, since it would be an un-check-in (out of
  scope, Q3).
- **R14**: the status-to-stay mirror stays for callers that record history (the import and
  fixtures), and only when the call sets or changes the status. Without that limit, any
  edit of a checked-in reservation would check its waiting rooms in.
- **R15**: cancellation is read from the line, not its stay (the guard runs before the
  stays sync). Only `departed` / `no_show` stays stop holding. A line with no stay falls
  back to the reservation status for the overstay rule.
- **R22**: the backfill migration drops `unique(reservation_id)` first, and its `down()`
  restores it. Migration 3 only adds and drops the two partial indexes.
- **FR-027**: another hotel's stay or reservation answers `403` (the repo-wide
  same-hotel policy check), not `404`.
