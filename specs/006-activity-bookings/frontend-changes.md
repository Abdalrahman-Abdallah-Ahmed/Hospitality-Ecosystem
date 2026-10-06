# Frontend Changes: Activity Availability and Bookings (2026-10-05)

Backend branch: `006-activity-bookings` (SPEC-041, SPEC-043). Not yet merged to `main`.

All endpoints are under `/api`, with the usual headers (`X-API-KEY`, `Authorization: Bearer …`, `Accept: application/json`). Responses use the `{ message, code, body }` envelope, except availability refusals (see section 2).

---

## 1. Breaking changes to handle first

1. **A catalogue booking needs a date.** `POST /api/booking` with an `activity_id` now requires `scheduled_for` (date and time) or `scheduled_date` (date only, for activities without opening hours). Without one: `422`, error on `scheduled_for`.
2. **Times are hotel-local.** Send `scheduled_for` as `"YYYY-MM-DD HH:MM"` with **no offset or `Z`**; it is read in the hotel's timezone. Don't convert to UTC in the browser. An explicit offset is still accepted, as an instant.
3. **Bookings can be refused.** Catalogue bookings are checked against the activity's season, closures, opening hours and daily capacity. A refusal is a `422` with an `unavailable` object (section 2). Show its `message`; don't treat it as a generic error.
4. **Use the new display fields.** Bookings now return `scheduled_date` (`YYYY-MM-DD`), `scheduled_time` (`HH:MM` or `null`) and `last_date` in hotel-local terms. Show those. `scheduled_for` is a UTC instant now.
5. **An open cancellation request can't be closed as a task.** `PUT /api/task/{id}` with `status: completed|cancelled`, or `DELETE /api/task/{id}`, on a cancellation-request task returns `422`. Use approve or decline (section 6). Moving it to `in_progress` or reassigning still works.
6. **ID fields must be UUIDs.** `guest_id`, `activity_id`, `stay_id`, `reservation_id` and `recommendation_id` on bookings, and the list filters, return `422` for non-UUID values.

---

## 2. Availability refusal (422)

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

| `reason` | Meaning | Suggested UI |
| --- | --- | --- |
| `inactive` | Activity not offered | Disable the activity |
| `past_date` | Date before hotel today | Block past dates in the picker |
| `out_of_season` | Outside the season | Grey out those dates |
| `closure_period` | Closed that day (`closure_reason` may say why) | Show the reason |
| `closed_weekday` | Doesn't run that weekday | Grey out the weekday |
| `time_required` | The day has time slots and no time was sent | Show a time picker from `windows` |
| `outside_opening_hours` | Time not inside a slot | Offer the `windows` |
| `party_exceeds_capacity` | Party bigger than a whole day's capacity | Show capacity |
| `fully_booked` | Not enough places left | Show `remaining` |

Time slots bound the **start** time only: a start from `start` up to, but not including, `end`.

**Override prompt.** When `unavailable.overridable === true` and the user has `bookings.override_capacity` (in `GET /api/user` → `permissions`), offer "Book anyway", which resends the same request with `"capacity_override": true`. Sending it without the permission returns `403`. The override never applies to closed days or the season.

---

## 3. New: activity availability (booking calendar)

`GET /api/activity/{id}/availability?from=YYYY-MM-DD&to=YYYY-MM-DD`

- Permission: `activities.view`.
- `to` is optional (defaults to `from`) and the range is at most **31 days**; a longer one returns `422` on `to`.
- Past dates are allowed and flagged.
- Another hotel's activity returns `403`.

```json
{
  "body": {
    "activity_id": "…",
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
}
```

How to render a day:
- **Closed:** `open: false`. Show `reason`.
- **Full:** `open: true` with `reason: "fully_booked"` (`remaining <= 0`).
- **Bookable:** open with `remaining > 0`, or with `remaining: null`, which means unlimited capacity. When `remaining` is null, show no count.
- **History:** `past: true`. Render read-only.
- **Warning:** `has_bookings_while_closed: true`. The day was closed after bookings were taken, and staff should contact those guests.

The figures match the booking check exactly: a day showing `remaining: N` accepts a booking of `N` people.

---

## 4. Booking list changes

`GET /api/booking` accepts new query parameters:

| Query | Meaning |
| --- | --- |
| `activity_id` | One activity (UUID) |
| `reservation_id` | One reservation (UUID) |
| `scheduled_from`, `scheduled_to` | `scheduled_date` range, inclusive |
| `cancellation_requested` | `1`: only bookings with an open request; `0`: only those without |

- The existing `filter[...]`, `search`, `sort`, `page` and `per_page` still work.
- Without `sort`, results come in `scheduled_date`, then `scheduled_time` order.

New fields on each booking: `reservation_id`, `scheduled_date`, `scheduled_time`, `last_date`, `notes`, and `cancellation_requested` (bool). Use `cancellation_requested` for a badge.

---

## 5. Booking form and new edit dialog

### Create: `POST /api/booking`

New optional fields:
- `reservation_id`: if only `stay_id` is sent, the reservation is filled from the stay. A stay from a different reservation returns `422`.
- `notes`: up to 2000 characters.
- `scheduled_date`
- `capacity_override`

The form should:
- load the day's slots from the availability endpoint;
- send `scheduled_for: "YYYY-MM-DD HH:MM"` when the day has `windows`, or `scheduled_date` when the activity has no hours;
- send `pax` of at least 1.

### Edit: `PATCH /api/booking/{id}` (new)

- Permission: `bookings.update`. Employees without a staff role have it by default.
- Only **pending** or **confirmed** bookings can be edited. Others return `422` (`A cancelled booking can no longer be changed.`); hide the Edit button for them.
- Send only the fields that changed: `activity_id`, `scheduled_for` / `scheduled_date`, `pax`, `notes`, `item_name` (free-text bookings only), `reservation_id`, `stay_id`, `capacity_override`.
- Never send `guest_id`, `recommendation_id`, `origin`, `reference`, `status` or `charge_model`; they return `422`. Status still goes through `POST /api/booking/{id}/status`.
- What is checked against availability:
  - **Checked:** a change of activity, date or time, or a larger party. A refusal is the same `422 unavailable` shape, and the same override prompt applies.
  - **Not checked:** notes, and a smaller party.
- A booking with no date (old data) must be given one before any other change.
- Show `notes` on the booking detail.

---

## 6. New: cancellation-request queue

Guests who ask the WhatsApp concierge to cancel now create a **request**; the concierge never cancels. Each hotel admin is emailed once. The booking keeps its status and places until staff answer.

### List: `GET /api/booking/cancellation-requests`

- Permission: `bookings.view`.
- Open requests, oldest first, paginated with `page` and `per_page`.

```json
{
  "id": "task-uuid",
  "status": "pending",
  "reason": "Flight changed",
  "resolution": null,
  "resolution_note": null,
  "resolved_by_user_id": null,
  "resolved_at": null,
  "created_at": "2026-10-05T09:12:00Z",
  "guest": { "id": "…", "first_name": "Sara", "last_name": "Haddad" },
  "booking": {
    "id": "…", "reference": "DCB-4K2P", "item_name": "Sunset cruise", "status": "confirmed",
    "scheduled_date": "2026-10-09", "scheduled_time": "17:30", "pax": 4,
    "activity": { "id": "…", "name": "Sunset cruise" }
  }
}
```

### Actions (permission: `bookings.update_status`)

| Action | Body | Result |
| --- | --- | --- |
| `POST /api/booking/{bookingId}/cancellation-request/approve` | `{ "reason"?: string }` (defaults to the guest's reason) | Booking cancelled; returns the booking. `422` if it can no longer be cancelled (e.g. realised); the request stays open. |
| `POST /api/booking/{bookingId}/cancellation-request/decline` | `{ "note": string }` (**required**, up to 1000 characters) | Booking unchanged; returns the request with `resolution: "declined"`. |

- Both return `404` when there is no open request, for example when someone else already answered it. Refresh the queue on `404`.
- Cancelling a booking from the normal status endpoint also closes its open request as approved.

---

## 7. Permissions and role editor

`GET /api/permissions` now includes two new booking permissions, so the role editor picks them up automatically:

| Permission | Label | Default for employees without a role |
| --- | --- | --- |
| `bookings.update` | Update | **Yes** |
| `bookings.override_capacity` | Override Capacity | No (never) |

Gate the UI:

| UI | Needs |
| --- | --- |
| Calendar | `activities.view` |
| Queue | `bookings.view` |
| Edit booking | `bookings.update` |
| Approve and Decline | `bookings.update_status` |
| "Book anyway" | `bookings.override_capacity` |

---

## 8. Suggested screens

1. **Booking calendar.** Pick an activity, then a week or month. Days come from section 3 and bookings from section 4. Each cell shows `booked` against `capacity`, closed or full states, and a warning for `has_bookings_while_closed`.
2. **Booking form and edit dialog.** A time picker from `windows`, a party size of at least 1, inline display of the `unavailable` message, and the override prompt.
3. **Cancellation queue.** A table from section 6 with Approve (optional reason) and Decline (required note), plus a badge on bookings where `cancellation_requested` is true.

Backend reference docs: `docs/booking-entity-documentation.md`, `docs/activity-api-documentation.md` (section 6), `docs/staff-roles-api-documentation.md`, `docs/latest-changes-2026-10-05.md`.
