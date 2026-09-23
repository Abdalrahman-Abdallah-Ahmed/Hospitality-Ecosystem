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

- Only what a request **adds** is checked: new reservations, added lines, and moved or
  extended dates. A reservation's own lines never count against it, and changes that only
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
