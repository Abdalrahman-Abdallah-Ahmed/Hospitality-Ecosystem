# Contract: Stays, Check-in and Check-out API

**Feature**: [../spec.md](../spec.md) · **Decisions**: [../research.md](../research.md)

All routes sit in the tenant group of `routes/api.php`
(`api.key → auth:sanctum → throttle:api → tenant`). Every response is
`apiResponse(message, code, body)` → `{ "message", "code", "body" }`. A super admin must
pass `hotel_id` on list endpoints (`resolveHotel()`); single-record endpoints resolve the
hotel from the record.

## Resource: `StayResource`

```json
{
  "id": "uuid",
  "status": "expected | in_house | departed | no_show | cancelled",
  "planned_arrival_date": "2026-10-01",
  "planned_departure_date": "2026-10-04",
  "checked_in_at": "2026-10-01T14:05:00+03:00",
  "checked_out_at": null,
  "nights": null,
  "adults": 2,
  "children": 1,
  "is_late": false,
  "is_past_departure": false,
  "is_overdue": false,
  "guest": { "id": "uuid", "first_name": "…", "last_name": "…", "phone_number": "…" },
  "reservation": { "id": "uuid", "reservation_id": "RES-1001", "status": "confirmed" },
  "reservation_room_id": "uuid",
  "room_type": { "id": "uuid", "name": "Deluxe" },
  "room": { "id": "uuid", "room_number": "204", "status": "available", "housekeeping_status": "clean" }
}
```

`room` is `null` while the line is unassigned. `is_late` / `is_overdue` are computed for
the requested day (list endpoints) or today (elsewhere).

## Lists — permission `stays.view`

### `GET /api/stays`

Generic index (`GenericIndexRequest`): `filter[status]`, `filter[room_id]`,
`filter[guest_id]`, `filter[reservation_id]`, `filter[planned_arrival_date]`,
`filter[planned_departure_date]`, `search` (guest name, reservation code), `sort`
(`-planned_arrival_date`), `page`, `per_page`. → `200` paginated `StayResource[]`.

### `GET /api/stays/arrivals?date=YYYY-MM-DD`
### `GET /api/stays/departures?date=YYYY-MM-DD`
### `GET /api/stays/in-house`

`date` defaults to today in the hotel's timezone.

| Endpoint | Rows | Flag |
| --- | --- | --- |
| arrivals | `expected` stays on non-cancelled lines with `planned_arrival_date ≤ date`, reservation `confirmed` / `pending` / `checked_in` | `is_late` = arrival < date; `is_past_departure` = departure ≤ date (cannot be checked in until the dates are corrected) |
| departures | `in_house` stays with `planned_departure_date ≤ date` | `is_overdue` = departure < date |
| in-house | `in_house` stays | `is_overdue` vs today |

Sorted by room number (nulls last), then guest name. Not paginated (a day's list).
→ `200 { "date": "2026-10-01", "stays": StayResource[] }`. `422` for a malformed date.

(Pending reservations appear in arrivals so the desk sees them, but cannot be checked
in until confirmed — the check-in returns the `reservation_status` reason.)

### `GET /api/stays/{stay}` → `200 StayResource` · `403` another hotel's stay (same-hotel policy check, as for every resource).

## Check-in — permission `stays.check_in`

### `POST /api/stays/{stay}/check-in`

```json
{ "room_id": "uuid (optional; only for an unassigned line)",
  "checked_in_at": "ISO-8601 (optional; current hotel day, not future)" }
```

### `POST /api/reservation/{reservation}/check-in`

```json
{ "rooms": [ { "stay_id": "uuid", "room_id": "uuid" } ],   // optional, per unassigned line
  "checked_in_at": "ISO-8601 (optional)" }
```

Checks in every `expected` stay of the reservation, or none. In-house stays are skipped.

**200** (also for an idempotent repeat):

```json
{
  "message": "Checked in.",
  "code": 200,
  "body": {
    "reservation": { "id": "uuid", "reservation_id": "RES-1001", "status": "checked_in" },
    "stays": [ StayResource ],
    "warnings": [ { "stay_id": "uuid", "message": "Room 204 is dirty." } ]
  }
}
```

**422** — nothing saved; one entry per failing line (R7):

```json
{
  "message": "2 rooms cannot be checked in.",
  "code": 422,
  "errors": {
    "stays.<uuid-1>.room": ["Assign or name a room first."],
    "stays.<uuid-2>.room": ["Room 205 is occupied."],
    "stays.<uuid-2>.reservation_status": ["Reservation is pending; only confirmed reservations can be checked in."],
    "checked_in_at": ["The time must be today (hotel time) and not in the future."]
  }
}
```

A named room that fails assignment (R9) reports under `stays.<uuid>.room_id`, e.g.
"Room 204 is not of the line's room type." or "This line already has room 203; reassign
it first."

**403** missing permission, or another hotel's stay / reservation · **422** another hotel's room named at check-in ("The selected room is not available.").

## Check-out — permission `stays.check_out`

### `POST /api/stays/{stay}/check-out`
### `POST /api/reservation/{reservation}/check-out`

```json
{ "checked_out_at": "ISO-8601 (optional; current hotel day, ≥ checked_in_at, not future)" }
```

Whole-reservation form checks out every `in_house` stay.

**200** (also for an idempotent repeat of a departed stay):

```json
{
  "message": "Checked out.",
  "code": 200,
  "body": {
    "reservation": { "id": "uuid", "status": "checked_out" },
    "stays": [ StayResource ],
    "cleaning_tasks": [ { "id": "uuid", "room_id": "uuid", "stay_id": "uuid", "assigned_to_team_id": "uuid|null" } ]
  }
}
```

**422**: stay `expected` / `cancelled` (`stays.<uuid>.stay_status`); last in-house line
while expected lines remain (`stays.expected` → "Cancel the rooms that did not arrive
first: RES-1001 Deluxe (unassigned)." — FR-012a); bad `checked_out_at`.

## Changed endpoints

### `PUT /api/reservation/{id}` — deprecated status values

| `status` sent | Behavior |
| --- | --- |
| `checked_in` (from another status) | Needs `reservations.update` **and** `stays.check_in`. Other fields saved, then a whole-reservation check-in (same rules, no room naming). Response headers `Deprecation: true`, `Link: </api/reservation/{id}/check-in>; rel="successor-version"`; `message` gains the deprecation sentence. |
| `checked_out` | Same with `stays.check_out` and the check-out endpoint. |
| `cancelled` with an in-house stay | **422** `status`: "Check out the in-house rooms first." |
| `rooms` removing/cancelling an in-house line | **422** `rooms.{i}`: "Room {n} is checked in; check it out first." |

### `POST /api/reservation`

`status: checked_in` → created `confirmed`, then deprecated whole check-in (same headers).
`status: checked_out` → **422** (only the import may record past stays).

### `DELETE /api/reservation/{id}`

**422** while any stay is in-house; otherwise its stays are soft-deleted with it.

### `PUT /api/hotel/{hotel}` — housekeeping defaults (admin-only, existing endpoint)

New optional fields `housekeeping_team_id` and `cleaning_task_category_id` (uuid or
null). `403` "The selected teams does not belong to you." for another hotel's
team/category (same `invalidRelation()` check `TaskController` uses — added to
`HotelController::update`); `422` when the team is inactive or the category does not
belong to the chosen team.
`HotelResource` gains both fields.

### Tasks (`/api/task`)

`stay_id` accepted on create/update and filterable (`filter[stay_id]`). `422` when the
task's `room_id` / `reservation_id` differ from the stay's; `403` (existing
`invalidRelation()` behavior) for another hotel's stay. `TaskResource` gains `stay_id`.

## Admin AI tools (not HTTP)

| Tool | Input | Output |
| --- | --- | --- |
| `GetStaysTool` | `list`: arrivals / departures / in_house; `date?` | Compact rows: reservation code, guest, room type, room number or "unassigned", dates, flags |
| `CheckInTool` | `reservation_id` (code); `room_numbers?` (subset of lines to check in, by assigned room); `assign?` [{`room_type`, `room_number`}] for unassigned lines; `checked_in_at?` | Same result / messages as the endpoint, as text |
| `CheckOutTool` | `reservation_id`; `room_numbers?`; `checked_out_at?` | Same |

Each refuses with "You don't have permission to …" when the acting user lacks the
permission. The optional times follow the same rule as the endpoints (FR-010a); the
instructions tell the AI to pass one only when the admin states the actual time.
