# API Contract: Activity Availability and Booking Workflow

All routes are under `/api`, behind `api.key → auth:sanctum → throttle:api → tenant`.
Every response uses the `apiResponse` envelope `{ message, code, body }`, except the 422
from an availability refusal, which follows the `ValidationException` shape plus an
`unavailable` object (see [Errors](#availability-refusal-422)).

Times and dates are **hotel-local**. A `scheduled_for` without an offset is read in the
hotel's time zone (R4).

---

## GET /activity/{activity}/availability: NEW

Permission: `activities.view` (`ActivityPolicy::view`). Covers FR-011, FR-012 and FR-013.

| Query | Rule |
| --- | --- |
| `from` | required, `Y-m-d` |
| `to` | optional, `Y-m-d`, `>= from`, at most 31 days inclusive. Defaults to `from` |

200 `body`:

```json
{
  "activity_id": "uuid",
  "daily_capacity": 12,
  "duration_days": 1,
  "days": [
    {
      "date": "2026-10-09",
      "open": true,
      "reason": null,
      "closure_reason": null,
      "windows": [{ "start": "17:00", "end": "19:00" }],
      "capacity": 12,
      "booked": 5,
      "remaining": 7,
      "past": false,
      "has_bookings_while_closed": false
    }
  ]
}
```

- 403: the activity belongs to another hotel (the same-hotel policy check).
- 422: a bad date, or a range longer than 31 days.

---

## POST /booking: CHANGED

Permission: `bookings.create`. `capacity_override: true` additionally needs
`bookings.override_capacity` (403 without it).

New and changed fields:

| Field | Rule |
| --- | --- |
| `reservation_id` | nullable, an existing reservation of this hotel |
| `stay_id` | nullable. When given with `reservation_id`, the stay must belong to that reservation (422) |
| `scheduled_for` | nullable datetime. No offset means hotel-local |
| `scheduled_date` | nullable `Y-m-d`. An alternative to `scheduled_for` for all-day activities |
| `pax` | integer ≥ 1 (default 1) |
| `notes` | nullable string, max 2000 |
| `capacity_override` | nullable boolean |

A catalogue booking (`activity_id` set) needs `scheduled_for` or `scheduled_date`.

Responses:
- **201**: the booking.
- **422**: an availability refusal, a cross-hotel relation, or a stay that doesn't match
  the reservation.
- **403**: an override sent without the permission.

---

## PATCH /booking/{booking}: NEW

Permission: `bookings.update` (`BookingPolicy::update`). Override as above. Covers
FR-020, FR-021 and FR-022.

Body: any of `activity_id`, `scheduled_for`, `scheduled_date`, `pax`, `notes`,
`item_name` (free-text only), `reservation_id`, `stay_id`, `capacity_override`.

These fields are rejected or ignored: guest, recommendation, origin, reference, status,
`charge_model` and creator.

Responses:
- **200**: the booking.
- **422**:
  - the booking isn't pending or confirmed;
  - an availability refusal;
  - a cross-hotel relation.
- **403**: no permission.

The availability check runs only when the activity, date, time or pax changes.

---

## POST /booking/{booking}/status: CHANGED (behaviour only)

Same contract. When the new status is `cancelled`, any open cancellation request on the
booking is closed in the same transaction as `resolution=approved` (FR-026).

---

## GET /booking: CHANGED

Permission: `bookings.view`. The `GenericIndexRequest` parameters still apply, plus:

| Query | Meaning |
| --- | --- |
| `activity_id` | only this activity |
| `reservation_id` | only this reservation |
| `scheduled_from`, `scheduled_to` | `scheduled_date` within the range, inclusive |
| `cancellation_requested` | `1` = only bookings with an open request |

Results are ordered by `scheduled_date`, then `scheduled_time`, unless `sort` is given.

`BookingResource` adds these fields:
- `reservation_id`;
- `scheduled_date`;
- `scheduled_time`;
- `last_date`;
- `notes`;
- `cancellation_requested` (bool).

---

## GET /booking/cancellation-requests: NEW

Permission: `bookings.view`. Covers FR-029.

Lists open cancellation requests, oldest first, paginated (`page`, `per_page`). Each item:

```json
{
  "id": "task-uuid",
  "status": "pending",
  "reason": "Flight changed",
  "created_at": "2026-10-05T09:12:00Z",
  "guest": { "id": "uuid", "first_name": "…", "last_name": "…" },
  "booking": { "id": "uuid", "reference": "DCB-4K2P", "item_name": "Sunset cruise",
               "status": "confirmed", "scheduled_date": "2026-10-09",
               "scheduled_time": "17:30", "pax": 4,
               "activity": { "id": "uuid", "name": "Sunset cruise" } }
}
```

---

## POST /booking/{booking}/cancellation-request/approve: NEW

Permission: `bookings.update_status`. Covers FR-026.

| Field | Rule |
| --- | --- |
| `reason` | nullable string, max 1000. Defaults to the request's reason |

Cancels the booking through `BookingService::cancel`, which closes the request as
`approved`.

Responses:
- **200**: the booking.
- **404**: there is no open request.
- **422**: the booking can no longer be cancelled. The request stays open.

## POST /booking/{booking}/cancellation-request/decline: NEW

Permission: `bookings.update_status`.

| Field | Rule |
| --- | --- |
| `note` | required string, max 1000 |

Closes the request as `declined` with the note. The booking is unchanged.

Responses:
- **200**: the request.
- **404**: there is no open request.

---

## Availability refusal (422)

```json
{
  "message": "Sunset cruise is fully booked on 2026-10-09 (0 of 12 places left).",
  "errors": { "scheduled_for": ["Sunset cruise is fully booked on 2026-10-09 (0 of 12 places left)."] },
  "unavailable": {
    "reason": "fully_booked",
    "date": "2026-10-09",
    "windows": [{ "start": "17:00", "end": "19:00" }],
    "capacity": 12,
    "booked": 12,
    "remaining": 0,
    "closure_reason": null,
    "overridable": true
  }
}
```

`overridable` is true only for `fully_booked` and `party_exceeds_capacity`. The reason
codes are listed in [data-model.md](../data-model.md#enums).

---

## AI tool contracts

### GetActivitiesTool: CHANGED (Concierge, Admin Advisor, Recommendation agents)

| Arg | Rule |
| --- | --- |
| `date` | optional `Y-m-d` |
| `from`, `to` | optional range, at most 14 days |

With no argument the output is unchanged. With a date or range, each activity gets
`availability: [{ date, open, reason, windows, remaining? }]`. `remaining` is omitted
when the capacity is unlimited. An invalid range returns an error sentence, not an
exception.

### CreateBookingTool: CHANGED (Concierge)

The arguments are unchanged: `activity_id`, `item_name`, `recommendation_id`,
`scheduled_for`, `pax`, `charge_model`.

Behaviour:
- **No reservation**: a refusal sentence, and no booking.
- **Date outside the window**: a refusal with `outside_reservation` when the date is
  outside today..departure.
- **Unavailable**: the reason sentence plus "Nearest available dates: …", up to 3 dates
  within the next 14 days and not after departure. No booking.
- **Repeated within 10 minutes**: the existing booking's reference.
- **Never**: a capacity override.
- **Links**: `reservation_id` and, when the guest is in-house, `stay_id`.

### RequestBookingCancellationTool: NEW (Concierge only)

| Arg | Rule |
| --- | --- |
| `booking_reference` or `booking_id` | required; must be the identified guest's booking in this hotel |
| `reason` | optional string |

Returns one of these sentences:
- "Your cancellation request for {ref} has been passed to the team; they will confirm."
- "A cancellation request for {ref} is already with the team."
- A refusal, for a booking that isn't the guest's, isn't found, or is not pending or
  confirmed.

It never changes the booking's status.

The Concierge has no tool that cancels a booking.
