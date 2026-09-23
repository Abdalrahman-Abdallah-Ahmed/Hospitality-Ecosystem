# Contract: Availability API and overbooking guard

All endpoints use the standard stack (`api.key → auth:sanctum → throttle:api → tenant`) and
the standard envelope `{ message, code, body }`, except where a 422 validation body is
described below.

---

## `GET /api/availability`

**Permission**: `availability.view` (admins hold it; employees without a staff role get it by
default).

### Query

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `arrival_date` | `YYYY-MM-DD` | yes | First night. Not before the hotel's today. |
| `departure_date` | `YYYY-MM-DD` | yes | Not a night. After `arrival_date`, at most 90 nights later. |
| `room_type_ids[]` | uuid[] | no | Limit to these types (returned even if inactive). Default: every active type. |
| `hotel_id` | uuid | super admin only | Ignored for other users. |

### 200

```json
{
  "message": "Availability retrieved successfully.",
  "code": 200,
  "body": {
    "arrival_date": "2026-03-12",
    "departure_date": "2026-03-15",
    "nights": 3,
    "room_types": [
      {
        "room_type": {
          "id": "9f1c…",
          "name": "Deluxe",
          "max_occupancy": 3,
          "adult_capacity": 2,
          "child_capacity": 1,
          "is_active": true
        },
        "bookable_for_stay": 2,
        "nights": [
          { "date": "2026-03-12", "total": 5, "out_of_order": 1, "booked": 0, "sellable": 4, "overbooked": 0 },
          { "date": "2026-03-13", "total": 5, "out_of_order": 1, "booked": 2, "sellable": 2, "overbooked": 0 },
          { "date": "2026-03-14", "total": 5, "out_of_order": 1, "booked": 0, "sellable": 4, "overbooked": 0 }
        ]
      }
    ]
  }
}
```

Room types are ordered by name. A type with no rooms returns zeros, not an error.

### Errors

| Status | When |
| --- | --- |
| 401 / 403 (API key) | Standard |
| 403 | No `availability.view`; or a super admin sent no valid `hotel_id` |
| 422 | Missing or invalid dates; `departure_date` not after `arrival_date`; more than 90 nights; `arrival_date` before today (hotel time); a `room_type_ids` entry that is not a live room type of this hotel (same message for another hotel's, deleted or unknown) |

---

## Changes to `POST /api/reservation` and `PUT /api/reservation/{id}`

### New request field

| Field | Type | Notes |
| --- | --- | --- |
| `overbook_override` | boolean | Optional, default `false`. Save even if a room type is short. Needs `reservations.overbook` on top of `reservations.create` / `reservations.update`. |

### New failure: room type short (422)

Returned when the change adds booked lines to a type and night that has no sellable room
left, and no valid override was sent. Nothing is saved.

```json
{
  "message": "Not enough rooms available: Deluxe is short by 1 on 2026-03-13.",
  "errors": {
    "rooms": ["Not enough rooms available: Deluxe is short by 1 on 2026-03-13."]
  },
  "shortfalls": [
    {
      "room_type_id": "9f1c…",
      "room_type_name": "Deluxe",
      "nights": [ { "date": "2026-03-13", "short": 1 } ]
    }
  ]
}
```

`shortfalls` only lists room types from this request (FR-020).

### New failure: override not allowed (403)

`overbook_override: true` from a user without `reservations.overbook`. Nothing is saved.

### Not checked

- Changes that only free rooms: removing lines, shortening dates, cancelling.
- Changes that don't add to any type-and-night: special requests, party size, or
  `pending` → `confirmed`.
- The reservation import (records legacy data as it is).

### Audit

A successful override writes `reservation.overbooking_overridden` with
`changes.shortfalls` and the actor.

---

## AI tools

| Tool | Agent | Input | Output |
| --- | --- | --- | --- |
| `GetAvailabilityTool` | Admin Advisor | `arrival_date`, `departure_date`, `room_type?` (name) | The same `room_types[]` grid as the endpoint. Refuses without `availability.view`. |
| `GetGuestAvailabilityTool` | Guest Concierge | `arrival_date`, `departure_date`, `room_type?` (name) | `[{ name, description, max_occupancy, adult_capacity, child_capacity, available }]` for every active type, with the named type first. No counts, room numbers or reservation data. |

Both tools cover at most 31 nights per call; the endpoint allows 90. Range errors are
returned to the model as a plain message. `CreateReservationTool` has no
override input, and a shortfall comes back to the model as the readable message.
