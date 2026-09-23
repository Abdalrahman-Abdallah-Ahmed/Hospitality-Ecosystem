# Contract: Reservations API (reservation rooms)

**Feature**: [../spec.md](../spec.md) · Changes to `docs/reservations-api-documentation.md`.
All responses keep the `{ message, code, body }` envelope. Routes, middleware and
permissions are unchanged (`reservations.view/create/update/delete`).

**Breaking**: `room_id` and `room` are removed from reservation requests and responses.
They are replaced by `rooms` and `room_summary`.

## Reservation object (changed fields only)

```json
{
  "id": "…",
  "guest_id": "…",
  "reservation_id": "RES-DOC0001",
  "arrival_date": "2026-09-01T00:00:00.000000Z",
  "departure_date": "2026-09-04T00:00:00.000000Z",
  "status": "confirmed",
  "adults": 4,
  "children": 1,
  "rooms": [
    {
      "id": "…",
      "room_type_id": "…",
      "room_id": "…",
      "status": "reserved",
      "room_type": { "id": "…", "name": "Deluxe", "…": "…" },
      "room": { "id": "…", "room_number": "101", "…": "…" }
    },
    {
      "id": "…",
      "room_type_id": "…",
      "room_id": null,
      "status": "reserved",
      "room_type": { "id": "…", "name": "Deluxe", "…": "…" },
      "room": null
    }
  ],
  "room_summary": [
    { "room_type_id": "…", "room_type_name": "Deluxe", "quantity": 2 }
  ]
}
```

- `rooms` lists every line, including `cancelled` ones (for history), ordered by creation.
- `room_summary` counts **non-cancelled** lines per type.
- `rooms[].room` is `null` when unassigned; it can be a soft-deleted room (history).

## POST /api/reservation

```json
{
  "guest_id": "…",
  "reservation_id": "RES-1001",
  "arrival_date": "2026-10-01",
  "departure_date": "2026-10-04",
  "status": "confirmed",
  "adults": 4,
  "children": 1,
  "rooms": [
    { "room_type_id": "<deluxe>", "quantity": 2 },
    { "room_type_id": "<suite>", "room_id": "<room 501>" }
  ],
  "capacity_override": false
}
```

| Field | Rule |
| --- | --- |
| `rooms` | required, array, 1–50 items |
| `rooms.*.room_type_id` | required, uuid, active room type of this hotel |
| `rooms.*.quantity` | optional integer 1–50, default 1 |
| `rooms.*.room_id` | optional uuid, room of this hotel whose type equals `room_type_id`; only when `quantity` is 1 |
| `capacity_override` | optional boolean, default false. Ignored for AI actors |
| total units | Σ quantity ≤ 50 |
| capacity | `adults + children ≤ Σ max_occupancy` and `adults ≤ Σ adult_capacity`, unless `capacity_override` |
| `room_id` (top level) | **rejected**: `422` "Use rooms[] instead" |

## PUT /api/reservation/{id}

`rooms` is optional. If it is absent, the lines are not changed. If it is present, it is
the **full desired list of non-cancelled lines**:

```json
{
  "rooms": [
    { "id": "<existing line A>" },
    { "id": "<existing line B>", "room_id": "<room 105>" },
    { "room_type_id": "<suite>", "quantity": 1 }
  ]
}
```

- Items with `id` keep that line. `room_id` is optional: send it to set or change the
  room, `null` to clear it, or leave it out to keep the current room. `room_type_id`, if
  sent, must equal the line's type.
- Items without `id` are new lines (same rules as create).
- Non-cancelled lines left out of the list become `cancelled`.
- Allowed changes depend on the reservation's status **before** the update (see
  [data-model.md](../data-model.md#line-state-rules-by-reservation-status-status-before-the-update)).
- Setting `status` to `cancelled` cancels every line.
- Changing `adults`/`children` re-runs the capacity check against the resulting lines.

## GET /api/reservation

- `filter[room_type_id]=<uuid>` and `filter[room_id]=<uuid>` match reservations with **any
  non-cancelled line** of that type or room. Neither can be used for `sort`.
- `search` no longer covers `room_id`.

## DELETE /api/room-types/{id} (changed)

New rejection, in addition to `deletion_blocked_by_rooms`:

```json
{
  "message": "Cannot delete room type: 3 current or upcoming reservations still use it. Deactivate it instead.",
  "code": 422,
  "body": { "error": "deletion_blocked_by_reservations", "reservations": 3 }
}
```

## Errors

| Case | Status | Shape |
| --- | --- | --- |
| No lines / more than 50 units | 422 | `errors.rooms` |
| Room type inactive, deleted, or of another hotel | 422 | `errors["rooms.{i}.room_type_id"]` "The selected room type is not available." (no hint that another hotel's record exists) |
| Room of another hotel / wrong type / deleted | 422 | `errors["rooms.{i}.room_id"]` |
| Same room on two lines | 422 | `errors["rooms.{i}.room_id"]` |
| Unknown or already-cancelled line `id` | 422 | `errors["rooms.{i}.id"]` |
| Removing the last line | 422 | `errors.rooms` "A reservation needs at least one room; cancel the reservation instead." |
| Line change not allowed for status | 422 | `errors.rooms` names the status |
| Over capacity without override | 422 | `errors.rooms` states party vs capacity and mentions `capacity_override` |

## Audit events (EventLog)

| Event | When |
| --- | --- |
| `reservation_room.created` | Each new line (including expansion of a quantity) |
| `reservation_room.updated` | Room set, changed or cleared (room move); status → cancelled |
| `reservation.capacity_overridden` | Staff saved an over-capacity reservation; `changes` = `{adults, children, max_occupancy, adult_capacity}` |

The actor kind is `user` for staff and `ai_agent` for `CreateReservationTool`. Imports keep
their single summary event.

## AI tools

- **`CreateReservationTool`** (Admin AI): new `rooms` array of
  `{ room_type (name), quantity?, room_number? }`. `room_number` is only allowed when
  quantity is 1. The legacy top-level `room_number` still works and becomes one line of
  that room's type. With neither, the tool asks which room type to book and creates
  nothing. There is no `capacity_override`. An unknown type returns the hotel's
  active type names.
- **`GetReservationsTool`**, **`GetOwnReservationTool`**: `room_number`/`room_type` are
  replaced by `rooms: [{ room_type, room_number|null, status }]` and
  `room_summary: ["2 × Deluxe", …]`.

## Import (`POST /api/reservation/import`)

The columns are unchanged. `room_number` produces one line of that room's type.
`room_type` alone produces one unassigned line of that type. Neither produces one line of
the hotel's default type. No capacity check.
