# Bookings — Entity and API Documentation

Phase 1, WP-3a.

## What a booking is

A **commitment**: a slot is held and the guest is expected. It is deliberately
separate from the two events either side of it.

| Event | Meaning | Where it lives |
| --- | --- | --- |
| Acceptance | The guest said yes | The conversation (WP-5) |
| **Booking** | **A commitment exists** | `bookings` — this entity |
| Settlement | Money moved, whenever it moved | `transactions` (WP-3) |

**The booking is the conversion.** It is the moment a recommendation has done
its job. Whether money follows, when, and whether at all, is a separate fact
about a separate process.

### Why this is separate from payment

Consider what actually happens in a resort:

- A guest accepts a dinner recommendation. They eat Thursday. They pay at
  checkout on Sunday — or, on all-inclusive, never.
- A guest books a beach activity included in their package. **No money will
  ever move.** The recommendation worked perfectly.
- A guest books an excursion at the desk. Payment settles days later.

A payment-based definition of success reports every one of those as a failure.
The all-inclusive case is the clearest: revenue zero, outcome ideal.

## The three enums

### `BookingStatus`

| Value | Meaning |
| --- | --- |
| `pending` | Guest accepted, slot not yet confirmed |
| `confirmed` | Slot held, guest expected |
| `realised` | Guest attended / consumed |
| `no_show` | Confirmed, guest never came |
| `cancelled` | Cancelled before the date |

`no_show` and `cancelled` are **not** interchangeable: one is a guest who broke
a commitment, the other one who withdrew it in time. Different operational
responses, different signals.

Status only moves forward (enforced by `BookingService`):

| From | May move to |
| --- | --- |
| `pending` | `confirmed`, `realised`, `no_show`, `cancelled` |
| `confirmed` | `realised`, `no_show`, `cancelled` |
| `no_show` | `realised` — the guest turned up late |
| `realised`, `cancelled` | nothing — final |

Asking for the status a booking already has is a no-op: it succeeds and keeps
the original timestamp (`confirmed_at`, `realised_at`, `cancellation_reason`).

Bookings are **never deleted** — they are cancelled, with a reason. The history
is the point, same rule as the ledger.

### `ChargeModel`

| Value | Meaning |
| --- | --- |
| `included` | All-inclusive: **no money will ever move** |
| `pay_on_site` | Settles at the outlet |
| `folio` | Posts to the room, settles at checkout |
| `prepaid` | Already paid before the stay |

**`included` is the case this whole entity exists for.** Such a booking will
never produce a transaction, and must never be reported as unrealised,
unsettled, or failed. `ChargeModel::settles()` returns `false` for it — call
that rather than re-deriving the rule, or you will eventually publish a
fictitious 40% failure rate.

### `BookingOrigin`

| Value | Meaning |
| --- | --- |
| `recommendation` | Followed an agent recommendation |
| `guest_request` | Guest asked unprompted |
| `staff` | Staff booked it directly |
| `import` | Loaded from another system |

`origin` is what lets you compare recommended bookings against organic ones.
Without it you cannot tell whether the AI creates demand or merely records
demand that already existed — the first question any manager asks.

## The booking reference

Every booking carries an 8-character code, e.g. `DCB-4K2P`.

Outlets (restaurant, dive centre, spa) run their own tills, which have never
heard of our UUIDs. The guest quotes this code at the desk, the seller records
it on the sale, and it comes back in the day's sales file as the
`booking_reference` column — which is the **only** way a payment can ever be
matched to the booking that produced it.

The alphabet drops every character that sounds or looks like another (`0/O`,
`1/I/L`, `5/S`), because the code is read aloud across a noisy lobby, often not
in the reader's first language.

## Status is never derived from settlement

In either direction. `App\Services\BookingService` is the only writer, and no
method in it looks for money to decide a status:

- A booking can be `realised` with **no transaction** (included).
- A transaction can exist with **no booking** (a walk-up sale).

`linkSettlement()` attaches a payment to a booking by **direct reference only**.
There is no booking↔transaction inference in Phase 1 — the same temptation the
WP-3 stay-window rule exists to resist.

## How bookings are created

Two paths.

**The concierge.** `App\Ai\Tools\CreateBookingTool`, available to the WhatsApp
agent. When a guest says "yes, book me on the sunset dive", the agent records
the commitment and gives them their reference code. It sets
`origin = recommendation` when the booking follows an offer the agent made,
`guest_request` otherwise. Since 2026-10-05 it:

- books only for a guest with a reservation at the hotel, and only dates from
  today to that reservation's departure date;
- links the booking to the reservation, and to the stay once the guest is in
  house;
- goes through the same availability check as the desk (below). When a date
  is refused, it tells the guest why and offers up to 3 nearby dates that fit
  the party during their stay;
- never books past capacity, and returns the existing booking when the same
  request is retried within 10 minutes.

The concierge **cannot cancel a booking**. When a guest asks, it creates a
[cancellation request](#cancellation-requests) for staff.

**Staff, through the API below.** The walk-up at the desk, and — more
importantly — everything that happens afterwards.

## Availability at booking time

Every booking for a catalogue activity (`activity_id` set) is checked against
the activity when it is created, and again when its activity, date, time or
party changes. The rules, checked in this order, with the first failure
reported:

| `reason` | When |
| --- | --- |
| `inactive` | The activity is inactive or deleted. |
| `past_date` | The date is before today, in the hotel's timezone. |
| `out_of_season` | Outside `available_from` / `available_until`. |
| `closure_period` | Inside one of `unavailable_periods`; `closure_reason` carries its reason. |
| `closed_weekday` | `operating_hours` is set and has no window that weekday. |
| `time_required` | The weekday has windows and no time was given. |
| `outside_opening_hours` | The start time is in none of that day's windows (a start at a window's end is outside it). |
| `party_exceeds_capacity` | The party is larger than `daily_capacity`. |
| `fully_booked` | Not enough places are left that day. |

- `daily_capacity` is shared by every window on a date. Empty means unlimited.
- Pending, confirmed and realised bookings hold their places. Cancelled and
  no-show bookings free them. A pending booking holds its places from the
  start and is never released automatically.
- An activity that takes several days (`duration_days`) must be open, and have
  places, on every day it covers. The party counts against each of those days.
- A booking with no `activity_id` (free text) is never checked.
- Changing a booking's activity, date, time or party sizes never counts the
  booking's own places against it.

A refusal is a `422`:

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

`overridable` is true only for `fully_booked` and `party_exceeds_capacity`.
Staff holding `bookings.override_capacity` can then resend with
`"capacity_override": true`. The override only lifts capacity, never a closed
day or the season, and every one is audited as `booking.capacity_overridden`
with the date, capacity, booked load and party. Sending it without the
permission is a `403`; AI agents can never override.

`GET /api/activity/{id}/availability` returns the same figures per date for
the booking calendar. See
[activity-api-documentation.md](/D:/Hospitality%20Ecosystem/docs/activity-api-documentation.md#6-activity-availability).

## When a booking happens

Times are the **hotel's local time**.

- `scheduled_for` without an offset (`"2026-10-09 17:30"`) is read in the
  hotel's timezone. With an offset (`"2026-10-09T13:30:00Z"`) it is an instant
  and is converted.
- `scheduled_date` alone (`"2026-10-09"`) books an activity that has no
  opening hours.
- A catalogue booking needs one of them.

The booking returns `scheduled_date`, `scheduled_time` (`HH:MM`) and
`last_date` (the last day a multi-day activity covers) in hotel-local terms.
`scheduled_for` is the real instant, in UTC.

## The API

```
GET   /api/booking                                       list (and calendar filters)
GET   /api/booking/{id}                                  one booking
POST  /api/booking                                       take a booking
PATCH /api/booking/{id}                                  correct a live booking
POST  /api/booking/{id}/status                           move it through its lifecycle
GET   /api/booking/cancellation-requests                 open guest cancellation requests
POST  /api/booking/{id}/cancellation-request/approve     cancel it as the guest asked
POST  /api/booking/{id}/cancellation-request/decline     keep it, with a note
```

Headers as everywhere else: `X-API-KEY`, `Authorization: Bearer …`,
`Accept: application/json`.

**There is no delete.** A booking is cancelled, with a reason.

### Who can call it

`App\Policies\BookingPolicy`: **`admin` or `employee`**, own hotel only (super
admin bypasses). Deliberately wider than most write endpoints here — the person
at the dive centre is the one who knows whether the guest turned up, and an
attendance instrument only admins can reach will not record attendance.

Since 2026-09-15 this is the default for employees without a
[staff role](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md).
A role replaces it: `bookings.view` (list, show, cancellation queue),
`bookings.create`, `bookings.update` (correct a live booking),
`bookings.update_status` (lifecycle, approve or decline a cancellation
request), and `bookings.override_capacity` (never a default). No role can grant
deleting a booking.

### `GET /api/booking`

The generic `filter`, `search`, `sort` and pagination parameters, plus:

| Query | Meaning |
| --- | --- |
| `activity_id` | only this activity |
| `reservation_id` | only this reservation |
| `scheduled_from`, `scheduled_to` | `scheduled_date` in the range, inclusive (`YYYY-MM-DD`) |
| `cancellation_requested` | `1`: only bookings with an open cancellation request; `0`: only those without |

Without `sort`, bookings come in `scheduled_date`, then `scheduled_time` order.
Each booking carries `cancellation_requested` (bool).

### `POST /api/booking`

```json
{
  "guest_id": "01a00c93-…",
  "activity_id": "01a00c93-…",
  "reservation_id": "01a00c93-…",
  "recommendation_id": null,
  "charge_model": "pay_on_site",
  "scheduled_for": "2026-10-09 17:30",
  "pax": 2,
  "notes": "Vegetarian lunch",
  "channel": "desk"
}
```

| Field | Rules |
| --- | --- |
| `guest_id` | **required**, must belong to your hotel. |
| `activity_id` | optional, must belong to your hotel. |
| `item_name` | **required when there is no `activity_id`** — a guest can book something not in the catalogue yet. |
| `scheduled_for` / `scheduled_date` | **one is required with `activity_id`.** See [When a booking happens](#when-a-booking-happens). |
| `charge_model` | **required** — `included`, `pay_on_site`, `folio`, `prepaid`. |
| `recommendation_id` | optional. When present the booking is credited to that recommendation immediately, as a directly observed (L1) conversion. |
| `origin` | optional — `staff` (default) or `guest_request`. **`recommendation` is not accepted**: it is derived from `recommendation_id`, so a booking can never claim a credit the link does not support. |
| `reservation_id`, `stay_id` | optional, must belong to your hotel. A stay given without a reservation brings its reservation; a stay of a different reservation is a `422`. |
| `pax` | optional, at least 1 (default 1). |
| `notes` | optional, up to 2000 characters. |
| `capacity_override` | optional; see [Availability at booking time](#availability-at-booking-time). |
| `expected_value`, `currency`, `channel` | optional. `expected_value`/`currency` default to the activity's price. |

`201` with the booking, including its `reference` — give that code to the guest.
`422` with `unavailable` when the activity cannot take it.

### `PATCH /api/booking/{id}`

Corrects a **pending or confirmed** booking. Send only what changes:

| Field | Rules |
| --- | --- |
| `activity_id` | another activity of your hotel; checked like a new booking. |
| `scheduled_for` / `scheduled_date` | a new date and time; checked. |
| `pax` | at least 1; checked when it changes. |
| `notes` | up to 2000 characters; never checked. |
| `item_name` | only for a booking with no activity. |
| `reservation_id`, `stay_id` | as on create. |
| `capacity_override` | as on create. |

`guest_id`, `recommendation_id`, `origin`, `reference`, `status`,
`charge_model` and `created_by_user_id` are refused (`422`): who a booking is
for and how it came about never change, and its status moves only through the
status endpoint.

- A cancelled, realised or no-show booking cannot be changed: `422`
  `A cancelled booking can no longer be changed.`
- A booking whose date has passed can still have its party corrected; moving
  it to a past date is refused.
- A catalogue booking with no date (taken before dates were recorded) must be
  given one before anything else changes.

`200` with the booking. Every change is in the audit trail as
`booking.updated`.

### `POST /api/booking/{id}/status`

```json
{ "status": "realised", "realised_at": "2026-09-10T18:40:00Z" }
```

| Field | Rules |
| --- | --- |
| `status` | **required** — `confirmed`, `realised`, `no_show`, `cancelled`. `pending` is not accepted: a booking starts there and only moves forward. |
| `reason` | **required when `cancelled`.** |
| `realised_at` | optional; defaults to now. |

**This is the route the conversion report depends on.** Nothing else in the
system can observe attendance — not the agent, not the ledger (an included
activity produces no transaction), not the importer. Without it,
`realisation_rate` has nothing to measure.

A move the [transition table](#bookingstatus) does not allow returns `422` with
a message such as `A realised booking cannot be marked confirmed.` — a cancelled
booking cannot be reopened, and a realised one cannot be marked anything else.
Letting a stale process rewrite what happened is worse than making someone
create a new booking.

Cancelling a booking also closes any open cancellation request for it as
approved.

## Cancellation requests

A guest who asks the WhatsApp concierge to cancel a booking gets a
**cancellation request**, never a cancellation. The request is a task
(`guest_signal = cancellation_request`, no team) linked to the booking, and
each admin of the hotel is emailed once. The booking keeps its status and
places until staff answer. A booking has at most one open request; asking
again returns it.

Answer it on the booking, not as a plain task: the task endpoints refuse to
complete, cancel or delete an open request (`422`), since the guest would get
no answer.

### `GET /api/booking/cancellation-requests`

Open requests, oldest first, paginated (`page`, `per_page`):

```json
{
  "id": "task-uuid",
  "status": "pending",
  "reason": "Flight changed",
  "resolution": null,
  "created_at": "2026-10-05T09:12:00Z",
  "guest": { "id": "…", "first_name": "Sara", "last_name": "Haddad" },
  "booking": {
    "id": "…", "reference": "DCB-4K2P", "item_name": "Sunset cruise", "status": "confirmed",
    "scheduled_date": "2026-10-09", "scheduled_time": "17:30", "pax": 4,
    "activity": { "id": "…", "name": "Sunset cruise" }
  }
}
```

### `POST /api/booking/{id}/cancellation-request/approve`

```json
{ "reason": "Guest's flight changed" }
```

Cancels the booking; `reason` defaults to the guest's. The request closes with
`resolution = approved`, who decided and when. `404` when the booking has no
open request; `422` (request left open) when the booking can no longer be
cancelled, for example because it was realised meanwhile.

### `POST /api/booking/{id}/cancellation-request/decline`

```json
{ "note": "Non-refundable within 24 hours" }
```

`note` is required. The booking stays as it is; the request closes with
`resolution = declined` and the note, so the guest can be told why. `200` with
the request; `404` when there is none open.

## Related Docs

- `docs/conversion-analytics-api-documentation.md` — the report built on these.
- `docs/transaction-api-documentation.md` — the ledger and the
  `booking_reference` import column.
