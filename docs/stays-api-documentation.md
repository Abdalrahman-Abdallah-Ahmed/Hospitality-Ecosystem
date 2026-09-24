# Stays, Check-in and Check-out API Documentation

**Feature**: Stay Lifecycle and Check-in/out (SPEC-023, SPEC-024, SPEC-025, Phase 4)

**Date**: 2026-09-24

---

## Overview

A **stay** is a guest being physically in one room. Every room line of a reservation has
exactly one stay, so a reservation for 2 Deluxe rooms has 2 stays.

| Stay status | Meaning |
| --- | --- |
| `expected` | Booked, not arrived yet |
| `in_house` | Checked in, in the room now |
| `departed` | Checked out |
| `cancelled` | The room line or the reservation was cancelled before arrival |
| `no_show` | Reserved for a later release (SPEC-012) |

Stays are created and kept in step with the reservation automatically. Guests are checked
in and out with the endpoints below, which also move the room and reservation statuses:

- **Check-in**: stay → `in_house`, room → `occupied`, reservation → `checked_in` as soon as
  its **first** room checks in.
- **Check-out**: stay → `departed` (with the nights actually stayed), room → `available`
  and housekeeping `dirty`, one **cleaning task** for the room, reservation →
  `checked_out` once its **last** room is out.

Every check-in and check-out is audited (`stay.checked_in` / `stay.checked_out`), and so
are the room and reservation status changes they cause.

Nothing here writes to the transaction ledger.

All endpoints need the `X-API-KEY` header and a Bearer token. Responses use the usual
`{ "message", "code", "body" }` shape.

---

## The stay object

```json
{
  "id": "uuid",
  "hotel_id": "uuid",
  "guest_id": "uuid",
  "reservation_id": "uuid",
  "reservation_room_id": "uuid",
  "room_id": "uuid or null",
  "planned_arrival_date": "2026-10-01",
  "planned_departure_date": "2026-10-04",
  "checked_in_at": "2026-10-01T14:05:00+00:00",
  "checked_out_at": null,
  "status": "in_house",
  "adults": 2,
  "children": 1,
  "nights": null,
  "room_revenue": "150.00",
  "currency": "USD",
  "room_type": { "id": "uuid", "name": "Deluxe" },
  "guest": { "...": "GuestResource" },
  "reservation": { "...": "ReservationResource" },
  "room": { "...": "RoomResource, or null while no room is assigned" }
}
```

- `adults` / `children`: the reservation's party is kept on the first room's stay; other
  stays show 0. The reservation holds the real totals.
- `room_revenue`: the reservation value split evenly across its rooms.
- On the front-desk lists only: `is_late`, `is_past_departure`, `is_overdue` (see below).

---

## Front-desk lists

**Authorization**: `stays.view` (not an employee default).

A super admin must add `?hotel_id=`.

### `GET /api/stays/arrivals?date=YYYY-MM-DD`

Stays still `expected` whose arrival is the day or earlier, on live room lines of
`pending`, `confirmed` or `checked_in` reservations.

- `is_late`: the arrival date is before the day.
- `is_past_departure`: the departure date has also passed. The stay cannot be checked in
  until the reservation's dates are corrected or the room is cancelled.
- Pending reservations are listed so the desk sees them, but must be confirmed before
  check-in.

### `GET /api/stays/departures?date=YYYY-MM-DD`

Stays `in_house` whose departure is the day or earlier. `is_overdue`: the departure date
is before the day.

### `GET /api/stays/in-house`

Every stay `in_house`, with `is_overdue`.

`date` defaults to today in the hotel's timezone. Each list is sorted by room number
(unassigned rooms last), then guest name, and is not paginated.

```json
{
  "message": "Stays fetched successfully.",
  "code": 200,
  "body": { "date": "2026-10-01", "stays": [ "stay objects" ] }
}
```

`422` for a malformed `date`.

### `GET /api/stays`

The generic index: `filter[status]`, `filter[room_id]`, `filter[guest_id]`,
`filter[reservation_id]`, `filter[planned_arrival_date]`,
`filter[planned_departure_date]`, `search` (guest name or phone, reservation code),
`sort`, `page`, `per_page`.

### `GET /api/stays/{stay}`

One stay. Another hotel's stay returns `403`.

---

## Check-in

**Authorization**: `stays.check_in` (not an employee default).

### `POST /api/stays/{stay}/check-in` — one room

```json
{
  "room_id": "uuid (optional)",
  "checked_in_at": "2026-10-01T13:00:00+03:00 (optional)"
}
```

### `POST /api/reservation/{reservation}/check-in` — every room waiting

```json
{
  "rooms": [ { "stay_id": "uuid", "room_id": "uuid" } ],
  "checked_in_at": "optional"
}
```

A whole-reservation check-in checks in **every** room still waiting, or **none**: if one
room fails a rule, nothing is saved and every failing room is listed. Rooms already in the
house are left as they are.

### Rules

A room can be checked in when all of these hold:

| Rule | Error key | Message |
| --- | --- | --- |
| The reservation is `confirmed` (or already `checked_in`) | `stays.{id}.reservation_status` | "Reservation is pending; only confirmed reservations can be checked in." |
| The room line is not cancelled and the stay is `expected` | `stays.{id}.stay_status` | "This room is cancelled." |
| Today (hotel time) is on or after the arrival date… | `stays.{id}.dates` | "Arrival is 2026-10-02." |
| …and before the departure date | `stays.{id}.dates` | "The departure date has passed; correct the reservation dates first." |
| A room is assigned, or named in the request | `stays.{id}.room` | "Assign or name a room first." |
| The room is not out of order (`maintenance` status or `blocked` housekeeping) | `stays.{id}.room` | "Room 204 is out of order." |
| No other guest is in the room | `stays.{id}.room` | "Room 204 is occupied." |

**Naming a room.** For a line with no room assigned, `room_id` (or `rooms[]`) puts the
guest in that room and checks them in as one action. The room must belong to the hotel,
be of the line's room type, and not be booked by another reservation on any of the same
nights. Otherwise the error is under `stays.{id}.room_id`, e.g. "Room 204 is not of the
line's room type." or "The selected room is not available." (also for another hotel's
room). A room cannot be swapped on a line that already has one: "This line already has
room 203; reassign it first."

**A room that is not clean** is still checked in, with a warning.

**An earlier time.** `checked_in_at` records a check-in entered late. It must be earlier
today (hotel time), not in the future; otherwise `422` on `checked_in_at`. The audit row
keeps both the given time and `entered_at`.

**Repeats** change nothing and return `200` with the current state. Two desks checking in
the same room at the same moment get one check-in.

### Response

```json
{
  "message": "Checked in.",
  "code": 200,
  "body": {
    "reservation": { "...": "ReservationResource", "status": "checked_in" },
    "stays": [ "stay objects" ],
    "warnings": [ { "stay_id": "uuid", "message": "Room 204 is dirty." } ]
  }
}
```

Failure (`422`, nothing saved):

```json
{
  "message": "2 rooms cannot be checked in.",
  "code": 422,
  "errors": {
    "stays.<uuid-1>.room": ["Assign or name a room first."],
    "stays.<uuid-2>.room": ["Room 205 is occupied."]
  }
}
```

---

## Check-out

**Authorization**: `stays.check_out` (not an employee default).

### `POST /api/stays/{stay}/check-out` — one room

### `POST /api/reservation/{reservation}/check-out` — every room in the house

```json
{ "checked_out_at": "optional: earlier today, not before the check-in" }
```

For each room:

- The stay becomes `departed`, with `nights` counted by calendar date from check-in to
  check-out (hotel time). A guest who leaves early frees the rest of their nights for
  sale right away.
- The room becomes `available` (a `maintenance` room stays `maintenance`) and its
  housekeeping status `dirty` (a `blocked` room stays `blocked`).
- One cleaning task: `created_by: system`, `status: pending`, `priority: normal`,
  title "Clean room 204 after check-out", linked to the room, reservation, guest and
  stay, assigned to the hotel's **housekeeping team** and filed under its **cleaning
  category** (hotel settings, see below). Not set → the task is created unassigned. No
  notification is sent yet (that comes with the housekeeping release).

The reservation becomes `checked_out` when none of its rooms is in the house any more.

**Rooms that never arrived.** The last room of a reservation cannot be checked out while
another of its rooms is still `expected`: `422` on `stays.expected`, "Cancel the rooms
that did not arrive first: RES-1001 Deluxe (unassigned)." Remove that room from the
reservation (`PUT /api/reservation/{id}` without it), then check out.

**Errors**: a stay that is `expected` or `cancelled` → `422` on `stays.{id}.stay_status`;
a bad `checked_out_at` → `422` on `checked_out_at`.

**Repeats** of a departed stay change nothing: no second cleaning task, no second audit
row.

```json
{
  "message": "Checked out.",
  "code": 200,
  "body": {
    "reservation": { "...": "ReservationResource", "status": "checked_out" },
    "stays": [ "stay objects" ],
    "cleaning_tasks": [ { "...": "TaskResource" } ]
  }
}
```

---

## Housekeeping defaults (hotel settings)

`PUT /api/hotel/{hotel}` (admins) accepts `housekeeping_team_id` and
`cleaning_task_category_id`. The team must be one of the hotel's active teams, and the
category must belong to that team. They are chosen by id, so the team can be named in any
language. See [hotel-api-documentation.md](hotel-api-documentation.md).

---

## Admin AI

The Admin Advisor has three new tools, with the same rules and messages as the endpoints
and the same permissions for the admin it acts for. Its writes are audited with
`actor_kind: ai_agent`.

| Tool | What it does |
| --- | --- |
| Stays list | Arrivals, departures or in-house for a day |
| Check-in | Every waiting room of a reservation, or only named room numbers; can put a guest in a named room for an unassigned line |
| Check-out | Every room in the house, or only named room numbers |

The Guest Concierge cannot check guests in or out, and tells guests to contact the front
desk.

---

## Permissions

| Permission | Endpoints |
| --- | --- |
| `stays.view` | `GET /api/stays`, `GET /api/stays/{id}`, `GET /api/stays/arrivals`, `GET /api/stays/departures`, `GET /api/stays/in-house` |
| `stays.check_in` | `POST /api/stays/{id}/check-in`, `POST /api/reservation/{id}/check-in`, and `status: checked_in` on the reservation edit |
| `stays.check_out` | `POST /api/stays/{id}/check-out`, `POST /api/reservation/{id}/check-out`, and `status: checked_out` on the reservation edit |

None of them is an employee default: grant them to front-desk roles.
