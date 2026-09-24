# Frontend Changes: 2026-09-24

What `ecosystem-frontend` needs to change for the backend release of 2026-09-24:
room-type availability (SPEC-020) and stay check-in/check-out (SPEC-023–025).

All endpoints need `X-API-KEY` and a Bearer token, and return `{ message, code, body }`.
The full contracts are in the backend docs listed at the end.

---

## 1. Must fix (existing screens break or change behaviour)

### 1.1 Reservation form: handle the overbooking `422`

`POST /api/reservation` and `PUT /api/reservation/{id}` now fail when a room type has no
free room for a night that the request **adds** (new reservation, added lines, moved or
longer dates, bringing back a cancelled reservation). Nothing is saved.

```json
{
  "message": "Not enough rooms available: Deluxe is short by 1 on 2026-03-13.",
  "errors": { "rooms": ["Not enough rooms available: Deluxe is short by 1 on 2026-03-13."] },
  "shortfalls": [
    {
      "room_type_id": "uuid",
      "room_type_name": "Deluxe",
      "nights": [ { "date": "2026-03-13", "short": 1 } ]
    }
  ]
}
```

To do:

- Show the `shortfalls` (room type, nights, how many short).
- If the user has `reservations.overbook`, offer "Book anyway" and resend the same body
  with `overbook_override: true`. Don't offer it otherwise: sending the flag without the
  permission returns `403`.
- Room types with no rooms can no longer be booked without an override.

### 1.2 Reservation form: stop setting `checked_in` / `checked_out` through `status`

Setting `status: checked_in` / `checked_out` on `PUT /api/reservation/{id}` (and
`checked_in` on `POST`) still works but is **deprecated and will be rejected in a later
release**. It now runs the real check-in or check-out, so it:

- needs `stays.check_in` / `stays.check_out` as well as `reservations.update` (`403`
  otherwise);
- can return `422` when a rule fails (reservation not `confirmed`, arrival date not
  reached, a line without a room, room occupied or out of order…);
- returns `Deprecation: true` and a `Link` header to the new endpoint.

`POST /api/reservation` with `status: checked_out` now returns `422`.

To do: remove `checked_in` / `checked_out` from the status dropdown, and use the check-in
and check-out actions in section 2.

### 1.3 Reservations with a guest in the house

While any room of a reservation is checked in:

- `status` can't be changed to anything else, including `cancelled`: `422` on `status`,
  "Check out the in-house rooms first."
- A line whose guest is in can't be removed: `422` "Room 204 is checked in; check it out
  first."
- `DELETE /api/reservation/{id}` returns `422` on `reservation`, "Check out the in-house
  rooms first."

To do: show these messages. Optionally, disable Cancel / Delete / Remove room when the
reservation is `checked_in`.

A checked-in reservation **may now remove a line whose guest never arrived** (it
couldn't before). Allow that on the form.

### 1.4 Numbers that change without a code change

- Dashboard occupied rooms and the `stays` usage counter now count **one stay per room**,
  so multi-room reservations count each room.

---

## 2. New screens and actions

### 2.1 Front-desk board (arrivals / departures / in-house)

Permission: `stays.view`.

| Endpoint | Returns | Flags |
| --- | --- | --- |
| `GET /api/stays/arrivals?date=` | `expected` stays arriving on or before the day | `is_late`, `is_past_departure` |
| `GET /api/stays/departures?date=` | `in_house` stays leaving on or before the day | `is_overdue` |
| `GET /api/stays/in-house` | every `in_house` stay | `is_overdue` |

- `date` is optional (defaults to today in hotel time). A super admin adds `?hotel_id=`.
- Body: `{ "date": "2026-10-01", "stays": [stay, ...] }`. Not paginated, already sorted
  by room number (unassigned last), then guest name.
- `is_past_departure`: can't be checked in until the reservation dates are fixed or the
  room is cancelled. Show it as an error, not an action.
- Arrivals include `pending` reservations. They must be confirmed before check-in.

Also available: `GET /api/stays` (generic index; `filter[status]`, `filter[room_id]`,
`filter[guest_id]`, `filter[reservation_id]`, dates, `search` by guest name/phone or
reservation code) and `GET /api/stays/{id}`.

**Stay object** (new): `id`, `reservation_id`, `reservation_room_id`, `room_id` (nullable),
`planned_arrival_date`, `planned_departure_date`, `checked_in_at`, `checked_out_at`,
`status` (`expected` | `in_house` | `departed` | `cancelled` | `no_show`), `adults`,
`children`, `nights`, `room_revenue`, `currency`, `room_type { id, name }`, `guest`,
`reservation`, `room` (null while unassigned).

A reservation now has **one stay per room line**. The party (`adults`, `children`) is on
the first room's stay only, and the others show 0, so read the party from the reservation.

### 2.2 Check-in dialog

Permission: `stays.check_in`.

- One room: `POST /api/stays/{id}/check-in` with `{ room_id?, checked_in_at? }`
- Whole reservation: `POST /api/reservation/{id}/check-in` with
  `{ rooms?: [{ stay_id, room_id }], checked_in_at? }`

UI needs:

- **Room picker** for lines with no room (`room_id` / `rooms[]`). It must be a room of the
  line's type that isn't booked on those nights. `GET /api/availability` or the room list
  can drive it.
- **Optional earlier time** (`checked_in_at`): earlier today in hotel time, not in the
  future.
- **Warnings**: success can carry `body.warnings: [{ stay_id, message }]`, for example
  "Room 204 is dirty." Show them. The check-in has still happened.
- **Errors**: a whole-reservation check-in saves all rooms or none. A `422` lists every
  failing room, with keys `stays.{stay_id}.{rule}` (`reservation_status`, `stay_status`,
  `dates`, `room`, `room_id`), plus `checked_in_at` for a bad time. Map each key to its
  room row.
- Success body: `{ reservation, stays, warnings }`. Repeating a check-in returns `200`
  and changes nothing.

### 2.3 Check-out action

Permission: `stays.check_out`.

- One room: `POST /api/stays/{id}/check-out`
- Whole reservation: `POST /api/reservation/{id}/check-out`
- Body: `{ checked_out_at? }` (earlier today, not before the check-in).

Success body: `{ reservation, stays, cleaning_tasks }`. The room becomes `available` +
`dirty`, and a cleaning task is created. You could show "Cleaning task created" and link
to it.

Errors:

- `stays.expected`: "Cancel the rooms that did not arrive first: RES-1001 Deluxe
  (unassigned)." The last room can't check out while another room is still `expected`.
  Offer to remove that line (`PUT /api/reservation/{id}` without it), then retry.
- `stays.{id}.stay_status`: the stay isn't in house.
- `checked_out_at`: bad time.

### 2.4 Availability grid

Permission: `availability.view` (employees without a role have it by default).

`GET /api/availability?arrival_date=&departure_date=&room_type_ids[]=` (super admin adds
`hotel_id`). The range is at most 90 nights and can't start in the past.

```json
{
  "arrival_date": "2026-03-12", "departure_date": "2026-03-15", "nights": 3,
  "room_types": [
    {
      "room_type": { "id": "uuid", "name": "Deluxe", "max_occupancy": 3, "adult_capacity": 2, "child_capacity": 1, "is_active": true },
      "bookable_for_stay": 2,
      "nights": [ { "date": "2026-03-12", "total": 5, "out_of_order": 1, "booked": 0, "sellable": 4, "overbooked": 0 } ]
    }
  ]
}
```

Grid of room types × nights. Highlight `sellable = 0` (sold out) and `overbooked > 0`.
`bookable_for_stay` is useful in the reservation form, next to each room type.

### 2.5 Hotel settings: housekeeping defaults

`PUT /api/hotel/{id}` (admins) accepts `housekeeping_team_id` and
`cleaning_task_category_id` (uuid or `null`). Both are returned on the hotel.

- Team picker: the hotel's **active** teams. Category picker: categories of the chosen
  team.
- Errors: `422` "The housekeeping team must be active." / "The cleaning task category
  must belong to the housekeeping team."; `403` for another hotel's team or category.
- While they're unset, cleaning tasks are created unassigned. Consider a hint prompting
  admins to set them.

### 2.6 Tasks: `stay_id`

- Tasks now have `stay_id` (nullable). It is filterable (`filter[stay_id]=`) and can be
  set on create or update.
- When `stay_id` is set, `room_id`, `reservation_id` and `guest_id` are filled in from the
  stay. Sending a different room or reservation returns `422` "The task's room_id does not
  match its stay."
- A cross-hotel relation error can now name `stays`: "The selected stays does not belong
  to you."
- A task can keep a `stay_id` whose stay was deleted (its reservation was deleted). It
  still updates normally. Handle a missing stay when showing the task.

---

## 3. Permissions (role editor)

New cases, picked up automatically from `GET /api/permissions`:

| Permission | Grants | Employee default |
| --- | --- | --- |
| `availability.view` | `GET /api/availability` | **yes** |
| `reservations.overbook` | `overbook_override: true` | no |
| `stays.view` | stays lists, index, show | no |
| `stays.check_in` | check-in endpoints (and the deprecated `status: checked_in`) | no |
| `stays.check_out` | check-out endpoints (and the deprecated `status: checked_out`) | no |

Use them to show or hide the board, the check-in/out buttons and the override prompt.
Front-desk roles need the three `stays.*` permissions.

---

## Backend references

- `docs/availability-api-documentation.md`
- `docs/stays-api-documentation.md`
- `docs/reservations-api-documentation.md` (Overbooking, check-in/out deprecation)
- `docs/hotel-api-documentation.md` (housekeeping defaults)
- `docs/task-management-api-documentation.md` (`stay_id`)
- `docs/staff-roles-api-documentation.md` (permission reference)
- `docs/latest-changes-2026-09-24.md` (full release notes)
