# Contract: Room Assignment API

**Feature**: `specs/020-room-assignment` · **Date**: 2026-10-30

All endpoints: `X-API-KEY` + Sanctum bearer, `tenant` middleware, response envelope
`{ message, code, body }`. Another hotel's reservation or stay → `404`. Validation and
rule failures → `422` with `errors` keyed by field.

## 1. Assign, reassign, unassign — `PUT /api/reservation/{reservation}/rooms` (`RoomController::assign`)

**Permission**: `reservations.assign_room`; `overbook_override: true` additionally needs
`reservations.overbook`.

```json
{
  "assignments": [
    { "line_id": "9f1c…", "room_id": "4b2e…" },
    { "line_id": "a73d…", "room_id": null },
    { "line_id": "c0aa…", "room_id": "77de…", "upgrade": true }
  ],
  "overbook_override": false
}
```

| Field | Rule |
| --- | --- |
| `assignments` | required, array, 1–50 items |
| `assignments.*.line_id` | required, uuid, a live line of this reservation, distinct |
| `assignments.*.room_id` | present, nullable uuid; `null` unassigns |
| `assignments.*.upgrade` | boolean, default `false`; required `true` when the room's type differs |
| `overbook_override` | boolean, default `false` |

Lines not named keep their room. All items apply together or none (checked on the final
state, so swaps work).

**`200`**: `body = { "reservation": ReservationResource, "warnings": ["Room 305 is out of order; return it to service before check-in."] }`.
Nothing changed (all already in place) → `200`, same body, no audit entries.

**`422` cases** (field `assignments.N.room_id` unless noted):

| Case | Message |
| --- | --- |
| room missing / other hotel | `The selected room is not available.` |
| type differs, no `upgrade` | `Room 305 is not of the line's room type; send upgrade to place it anyway.` |
| clash | `Room 204 is booked for RES-0042 on overlapping nights.` |
| guest in house on that line | `Room 204 is checked in; move the guest with the room-move action.` |
| stay departed / no-show | `Room changes are closed for this room (departed).` |
| reservation cancelled / checked_out / no_show (`reservation`) | `Room changes are closed for this reservation (cancelled).` |
| upgrade oversells (`rooms`) | existing availability shortfall shape |

`403` without the permission (or without `reservations.overbook` when overriding).

## 2. Move an in-house guest — `POST /api/stays/{stay}/move-room`

**Permission**: `reservations.move_room` (+ `reservations.overbook` with override).

```json
{ "room_id": "77de…", "reason": "Air conditioning failed in 204", "upgrade": false, "overbook_override": false }
```

| Field | Rule |
| --- | --- |
| `room_id` | required uuid |
| `reason` | required string 1–500 |
| `upgrade`, `overbook_override` | as above |

**`200`**:

```json
{
  "stay": "StayResource (room = new room)",
  "vacated_room": { "id": "…", "room_number": "204", "housekeeping_status": "dirty" },
  "cleaning_task_id": "…",
  "open_room_change_requests": [ { "id": "…", "title": "Room change request", "status": "open" } ],
  "warnings": []
}
```

**`422`**: stay not `in_house` (`stay`); reason missing (`reason`); target out of order,
occupied, of another type without `upgrade`, or clashing on any night of the stay
(`room_id`); same room as now (`room_id`: `The guest is already in room 204.`).

No guest message is sent.

## 3. Unassigned arrivals — `GET /api/reservation/unassigned-arrivals`

**Permission**: `reservations.assign_room`.

Query: `from`, `to` (dates, default today → today + 7 in hotel time, max 31 days),
`hotel_id` (super admin), plus `GenericIndexRequest` paging/sort (`sort=arrival_date`
default; also `reservation_code`, `room_type`).

Includes lines whose guest has not arrived yet on `pending`, `confirmed` and `checked_in`
reservations (a multi-room reservation is checked in once its first room is).

**`200`** (paginated):

```json
{
  "data": [
    {
      "line_id": "…",
      "reservation_id": "…",
      "reservation_code": "RES-0042",
      "reservation_status": "confirmed",
      "primary_guest": { "id": "…", "name": "Lina Haddad" },
      "arrival_date": "2026-11-02",
      "departure_date": "2026-11-05",
      "room_type": { "id": "…", "name": "Deluxe" },
      "is_late": false
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 1 }
}
```

## 4. Changed existing endpoints

| Endpoint | Change | Breaking? |
| --- | --- | --- |
| `POST /api/reservation`, `PUT /api/reservation/{id}` with `rooms[].room_id` | Cross-reservation clash now rejected (`422`); setting or changing a room needs `reservations.assign_room` (`403`); unchanged `room_id` echoes need nothing extra. | **Yes** |
| `PUT /api/reservation/{id}` changing the room of an in-house line | Now `422` → use `move-room`. | **Yes** |
| `PUT /api/reservation/{id}` moving dates into a clash | `422` on `rooms` naming the room and other reservation. | **Yes** (was silently accepted) |
| `PUT /api/reservation/{id}` un-cancelling when a room was given away meanwhile | `200`; that room comes off the restored line, and the response `message` says to assign another (same rule as no-show undo). | **Yes** (room no longer kept) |
| `POST /api/stays/{stay}/check-in`, `POST /api/reservation/{id}/check-in` with a named room | Unchanged contract; constraint-backed. | No |
| `POST /api/reservation/{id}/undo-no-show` | Unchanged (clashing room taken off with warning). | No |
| Reservation import | Unchanged (`ROOM_CLASH` warning). | No |
| `ReservationResource.rooms[]` | adds `is_upgrade` (bool) and `room.room_type` when a room is held. | No (additive) |
| `GET /api/availability` | Upgraded lines count against the room's type. | No (values only) |
| `GET /api/permissions` | Lists the two new cases. | No (additive) |

## 5. Admin AI tools

| Tool | Gate | Behaviour |
| --- | --- | --- |
| `AssignRoomsTool` (changed) | `reservations.update` + `reservations.assign_room` | Via `RoomAssignmentService::assign`; `upgrade` per item only when the admin asked; never overbooks; description lists only enforced rules. |
| `MoveRoomTool` (new) | `reservations.move_room` | Reservation code (+ current room number if several in house), target room number, reason. Never overbooks. |

## 6. Platform command

`php artisan rooms:overlaps` — read-only, all hotels. Prints hotel, room number, both
reservation codes and shared nights; exit code `1` when any exist, `0` otherwise.
