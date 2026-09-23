# Availability API Documentation

**Feature**: Room-Type Availability (SPEC-020, Phase 3)

**Date**: 2026-09-24

---

## Overview

Availability answers "how many rooms of each type can the hotel still sell, night by
night?". It is worked out from the hotel's rooms and reservations every time it is asked
for, and nothing is stored.

- A **night** is a date a guest sleeps. A stay from 12 to 15 March uses the nights of 12,
  13 and 14 March. The departure date is not a night.
- A reservation room line **holds** one unit of its room type on each of its nights while
  the line is not cancelled and the reservation is `pending`, `confirmed` or `checked_in`.
  An unassigned line holds a unit just like a line with a physical room. A checked-in
  guest who stays past their departure date keeps holding tonight until checked out; a
  guest who is due out today does not, so tonight can be sold to the next arrival.
- A room is **out of order** while its status is `maintenance`. It is taken out of every
  night looked up, including future nights, until it returns to service.
- **Sellable** = rooms − out of order − booked, never below 0. **Overbooked** is how far
  booked goes past the rooms that can be used.

The same numbers stop reservations from overselling. See
[reservations-api-documentation.md](reservations-api-documentation.md#overbooking).

---

## `GET /api/availability`

**Authentication**: `X-API-KEY` header and a Bearer token.

**Authorization**: `availability.view`. Admins hold it, and employees without a staff role
get it by default.

### Query parameters

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `arrival_date` | `YYYY-MM-DD` | yes | The first night. Cannot be before today in the hotel's time zone. |
| `departure_date` | `YYYY-MM-DD` | yes | Not a night. Must be after `arrival_date`, at most 90 nights later. |
| `room_type_ids[]` | uuid[] | no | Only these room types. They are returned even when inactive. By default every active room type is returned. |
| `hotel_id` | uuid | super admin only | The hotel to look up. Ignored for other users. |

### Example

```http
GET /api/availability?arrival_date=2026-03-12&departure_date=2026-03-15
```

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
          "id": "9f1c2d7e-…",
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

- Room types are ordered by name. Each has one entry per night, with no gaps.
- `bookable_for_stay` is the lowest `sellable` across the range: how many rooms of that
  type can be booked for the whole stay.
- A room type with no rooms returns zeros, not an error.

### Errors

| Status | When |
| --- | --- |
| `403` | The user lacks `availability.view`, or a super admin did not send a valid `hotel_id` (`"You must belong to, or specify, a valid hotel."`) |
| `422` | `arrival_date` or `departure_date` missing or not `YYYY-MM-DD`; `departure_date` not after `arrival_date`; more than 90 nights; `arrival_date` in the past (errors keyed by field) |
| `422` | A `room_type_ids` entry that is not a live room type of this hotel: `"One or more of the selected room types are invalid."` The message is the same for another hotel's type, a deleted type and an unknown id. |

Lookups are not written to the audit log.

---

## AI assistants

The same numbers are available to the AI through two read tools:

- **Admin Advisor**: the full per-type, per-night grid, for at most 31 nights per question.
  The user needs `availability.view`.
- **Guest Concierge**: only whether each active room type can be booked for the guest's
  dates, with its description and capacity. It never gives room counts, room numbers or
  other guests' bookings.
