# Contract: No-Show and Lifecycle API

All endpoints use the standard stack (`api.key` → `auth:sanctum` → `throttle:api` →
`tenant`) and return `apiResponse()` envelopes `{ message, code, body }`.

## Endpoints

| Method | Path | Permission | Does |
| --- | --- | --- | --- |
| `POST` | `/api/reservation/{id}/no-show` | `reservations.no_show` | Mark every expected room a no-show; reservation → `no_show` |
| `POST` | `/api/reservation/{id}/undo-no-show` | `reservations.no_show` | Restore the reservation and its no-show rooms |
| `POST` | `/api/stays/{id}/no-show` | `reservations.no_show` | Mark one room a no-show |
| `POST` | `/api/stays/{id}/undo-no-show` | `reservations.no_show` | Restore one room (reservation not checked out) |

### Request body (all four)

| Field | Rules |
| --- | --- |
| `reason` | optional, string, max 500. Stored with the action in the reservation's history. |
| `overbook_override` | undo only; optional boolean. Requires `reservations.overbook` (`403` otherwise), as on create/update. |

### `200` response

```json
{
  "message": "Marked as a no-show.",
  "code": 200,
  "body": {
    "reservation": { "...ReservationResource": "status: no_show" },
    "stays": [ { "...StayResource": "status: no_show" } ],
    "warnings": []
  }
}
```

Undo responds `"No-show undone."` with the restored reservation and stays. When a
restored line's room was given to another booking for overlapping nights meanwhile, that
room is taken off the restored line and `warnings` explains it.

### Errors

| Code | When |
| --- | --- |
| `403` | Missing `reservations.no_show`, another hotel's record, or `overbook_override` without `reservations.overbook` |
| `422` `stays` | Arrival day not reached; a room is in house or departed (whole); stay is in house or departed (room); reservation is cancelled or checked out |
| `422` `status` | Undo on a reservation that isn't `no_show` |
| `422` `stays` | Room undo on a checked-out reservation: "This reservation is checked out; its no-show rooms are final." |
| `422` `rooms` | Undo doesn't fit availability any more (same message as create/update; `overbook_override` allowed) |

Repeating a mark or an undo that is already done changes nothing and returns `200`.

## Changed: `PUT /api/reservation/{id}`

- `status: no_show` → `422` on `status`: "Use POST /reservation/{id}/no-show."
- Any status change not in the lifecycle table → `422` on `status`, e.g.
  "A checked_out reservation can't be changed to pending."
- While the reservation is `no_show`, only `guests` and `special_requests` can change.
  Any other field whose value differs (e.g. `arrival_date`, `departure_date`, `adults`,
  `children`, `children_ages`, `rooms`, `status`, `reservation_id`, `source`,
  `reservation_value`, `currency`, `guest_id`) → `422` on that field: "Undo the no-show
  first." Sending a field with its current value is not a change.

## Changed: responses and filters

- `status` may be `no_show` everywhere a reservation is returned.
  `GET /api/reservation?filter[status]=no_show` works through the generic filters.
- Each reservation room line gains `stay_id` and `stay_status` (`expected`, `in_house`,
  `departed`, `no_show`, `cancelled`); both `null` for legacy lines without a stay.

## Admin AI tool: `NoShowTool`

| Argument | Rules |
| --- | --- |
| `action` | required, `mark` or `undo` |
| `reservation_id` | required, the reservation code (named like the check-in/out tools' argument) |
| `room_number` | optional; one room of the reservation. |
| `room_type` | optional; for a room with no number yet: one live room of that type (unassigned first). With neither, the whole reservation. |
| `reason` | optional |

Same service, rules and messages as the API. Guarded by `reservations.no_show`. `mark`
asks the admin to confirm first (like check-out and cancel); `undo` runs straight away.
Not available to the Guest Concierge.
