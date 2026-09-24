# Latest Changes: 2026-09-24

## Room-Type Availability (SPEC-020, Phase 3)

### Summary

The API can now say how many rooms of each type are still free, night by night. It also
stops reservations from selling more rooms of a type than the hotel has. **This changes
reservation writes:** a booking that used to be saved even though no room of that type was
free now gets a `422`.

### What's New

#### `GET /api/availability`

Per room type and per night from `arrival_date` up to (not including) `departure_date`:
`total`, `out_of_order`, `booked`, `sellable`, `overbooked`, plus `bookable_for_stay`
(the lowest `sellable` across the range). The range can be up to 90 nights and cannot
start in the past (hotel time). Optional `room_type_ids[]`; a super admin passes
`hotel_id`. See [availability-api-documentation.md](availability-api-documentation.md).

- Every booked line uses a room of its type on each night of the stay, whether or not a
  physical room is assigned. `pending`, `confirmed` and `checked_in` reservations hold
  rooms; `checked_out` and `cancelled` do not.
- A room whose status is `maintenance` counts as out of order on every night looked up,
  until it is back in service.

#### Overbooking check on `POST /api/reservation` and `PUT /api/reservation/{id}`

- Only what a request **adds** is checked: new reservations, added lines, moved or
  extended dates, and bringing a cancelled reservation back. A reservation's own lines never count against it, and changes that only
  free rooms or add nothing are never checked.
- When a type is short: `422`, with `errors.rooms` and a new `shortfalls` array
  (`room_type_id`, `room_type_name`, `nights: [{ date, short }]`). Nothing is saved.
- New optional field `overbook_override: true` saves it anyway. It needs the new
  `reservations.overbook` permission; without it the request returns `403`. Each override
  is audited as `reservation.overbooking_overridden`. The AI can never override.
- Two bookings for the last room are handled one at a time; the second gets the `422`.
- The reservation import is not checked. Rows beyond availability are saved and show as
  overbooked nights.

#### Permissions

- `availability.view` — `GET /api/availability`. **Added to the employee defaults**:
  employees without a staff role now have it. It is read-only and needed to book safely.
- `reservations.overbook` — send `overbook_override: true`. Not a default.

Both appear in `GET /api/permissions` automatically.

#### AI

- Admin Advisor: a new tool returns the same availability grid (at most 31 nights per
  call), for users with `availability.view`.
- Guest Concierge: a new tool tells guests only whether each room type can be booked for
  their dates, never room counts.

#### Database

- New index `reservations_hotel_dates_index` on `reservations (hotel_id, arrival_date, departure_date)`.

### Breaking Changes

- **Reservation create/update can now fail with `422` + `shortfalls`** when a room type is
  short. The frontend should show the shortfall and, for users with
  `reservations.overbook`, offer to resend with `overbook_override: true`.
- Room types with **no rooms** can no longer be booked through the API or the Admin AI,
  because nothing of that type is free. Add rooms to the type first, or override.

### Frontend Slice (`ecosystem-frontend`)

- Availability grid: room types × nights, with sold-out and overbooked nights highlighted.
- Reservation form: handle the `shortfalls` 422, with an override prompt for permitted users.

---

## Stay Lifecycle and Check-in/out (SPEC-023, SPEC-024, SPEC-025, Phase 4)

### Summary

Guests are now checked in and out per room with dedicated endpoints that check the rules,
set room and reservation statuses, and hand the room to housekeeping. Every room line of a
reservation has its own stay. **This changes reservation writes:** checking in or out by
setting the reservation's `status` still works but is deprecated and now follows the
check-in and check-out rules, and a reservation with a guest in the house can no longer
be cancelled, deleted or lose that guest's room. See
[stays-api-documentation.md](stays-api-documentation.md).

### What's New

#### Endpoints

- `GET /api/stays/arrivals`, `/api/stays/departures`, `/api/stays/in-house` (`?date=`,
  default today in hotel time) — the front desk's daily lists, with `is_late`,
  `is_past_departure` and `is_overdue` flags.
- `GET /api/stays`, `GET /api/stays/{id}` — the stays index (filters, search by guest or
  reservation code) and one stay.
- `POST /api/stays/{id}/check-in`, `POST /api/reservation/{id}/check-in` — one room or
  every room waiting. Optional `room_id` / `rooms[]` put the guest in a named room when
  the line has none. Optional `checked_in_at` for an earlier time today. All-or-nothing
  for a whole reservation; repeats do nothing. A room that isn't clean is checked in with
  a warning.
- `POST /api/stays/{id}/check-out`, `POST /api/reservation/{id}/check-out` — one room or
  every room in the house. The room becomes `available` and `dirty`, and a cleaning task
  is created for it. The reservation becomes `checked_out` when its last room is out.
  Optional `checked_out_at`.

#### Stays

- **One stay per room line** (was one per reservation). A migration links each existing
  stay to its reservation's first line and creates stays for the other lines. The
  reservation's value is split evenly across its stays, and its party stays on the first
  room's stay.
- A room can have only one guest in the house at a time (enforced by the database).
- Stay responses gain `reservation_room_id` and `room_type`.

#### Hotels and tasks

- Hotels gain `housekeeping_team_id` and `cleaning_task_category_id` (admins set them with
  `PUT /api/hotel/{id}`). **Set them**, or cleaning tasks are created unassigned.
- Tasks gain `stay_id` (filterable). A task linked to a stay takes its room, reservation
  and guest.

#### Permissions

- `stays.view`, `stays.check_in`, `stays.check_out` — **none is an employee default**.
  Grant them to front-desk roles. They appear in `GET /api/permissions` automatically.

#### AI

- Admin Advisor: new tools to list arrivals/departures/in-house and to check guests in
  and out, with the same rules and permissions as the endpoints, audited as the AI.
- Admin Advisor's reservation tool only creates `pending` or `confirmed` reservations.
- Guest Concierge: cannot check guests in or out, and sends guests to the front desk. A
  guest's service request is linked to their room's stay when the Concierge knows which
  room it is.

#### Database

- `stays.reservation_room_id` (with a partial unique index), a partial unique index
  allowing one in-house stay per room, `tasks.stay_id`, and the two hotel columns. The old
  unique index on `stays.reservation_id` is dropped.
- **Before deploying**, run the migrations on a copy of production: the uniqueness
  migration stops, listing the rooms, if any room already has two guests in the house.

### Breaking Changes

- **Deprecated: `status: checked_in` / `checked_out` on `PUT /api/reservation/{id}`**
  (and `checked_in` on `POST /api/reservation`). It now runs the real check-in or
  check-out: it needs `stays.check_in` / `stays.check_out` as well as
  `reservations.update` (`403` otherwise), and it can return `422` when a rule fails. In
  particular, **every line needs an assigned room** (a room can't be named on this path),
  the reservation must be `confirmed`, and today must be within its dates. Responses carry
  `Deprecation: true` and a `Link` to the new endpoint. **A later release will reject it.**
- `POST /api/reservation` with `status: checked_out` → `422` (only the import records
  past stays).
- **While a guest is in the house**, `PUT /api/reservation/{id}` rejects `status:
  cancelled` (or any change away from `checked_in`), and removing that guest's room line;
  `DELETE /api/reservation/{id}` is rejected too. Check the guest out first.
- A checked-in reservation **may now drop a room whose guest never arrived** (it could not
  before). The last room can't check out until such rooms are dropped.
- The dashboard's occupied-room count and the `stays` usage counter now count one stay per
  room, so multi-room reservations count each room.
- A room's status and housekeeping status changes are now written to the audit history
  (`room.updated`).

### Frontend Slice (`ecosystem-frontend`)

- Arrivals / departures / in-house board with late, past-departure and overdue flags.
- Check-in dialog: per room or whole reservation, with a room picker for unassigned
  lines, the not-clean warning, and the per-room errors from a `422`.
- Check-out action per room or whole reservation, with the "cancel rooms that didn't
  arrive first" message.
- Move off `status: checked_in` / `checked_out` on the reservation form to the new
  endpoints before it is removed.
- Hotel settings: pick the housekeeping team and cleaning category.
