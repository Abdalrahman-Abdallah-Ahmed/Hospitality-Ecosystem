# Latest Changes: 2026-10-05

## Activity Availability and Booking Workflow (SPEC-041, SPEC-043, Phase 6)

### Summary

Bookings now respect an activity's real availability: its season, closure
periods, opening hours per weekday and daily capacity. This applies to every
path: the desk, and the WhatsApp concierge. Staff can look up what is free per
date, correct a live booking, and override capacity on purpose. A guest who asks
the concierge to cancel now creates a request for staff, who approve or decline
it from a queue.

**This changes the booking API** (see Breaking changes).

### Breaking changes

1. **Catalogue bookings are checked.** `POST /api/booking` with an `activity_id`
   returns `422` when the activity is inactive, the date is past, out of season,
   in a closure period, on a closed weekday, outside that day's opening hours,
   or full. The body carries an `unavailable` object (`reason`, `date`,
   `windows`, `capacity`, `booked`, `remaining`, `closure_reason`,
   `overridable`). Free-text bookings (no `activity_id`) are unchanged.
2. **A catalogue booking needs a date.** Send `scheduled_for` (date and time)
   or, for an activity with no opening hours, `scheduled_date`.
3. **`scheduled_for` without an offset is hotel-local time.**
   `"2026-10-09 17:30"` means 17:30 at the hotel. An explicit offset
   (`…T13:30:00Z`) is still an instant. The booking now also returns
   `scheduled_date`, `scheduled_time` and `last_date` in hotel-local terms.
4. **Existing `scheduled_for` values were corrected** for hotels not on UTC.
   They had been stored as if the local time were UTC; they now hold the real
   instant. Hotels on UTC see no change.
5. **New default permission.** Employees without a staff role now also have
   `bookings.update`.
6. **An open cancellation request can't be closed or deleted through
   `PUT`/`DELETE /api/task/{id}`** (`422`); answer it with approve or decline.

### What's new

- `GET /api/activity/{id}/availability?from=&to=` — per date: open or closed
  and why, windows, capacity, booked, remaining, past, and whether a closed day
  still has bookings. At most 31 days (`activities.view`).
- `PATCH /api/booking/{id}` — correct a pending or confirmed booking's
  activity, date, time, party, notes or reservation, re-checked against
  availability (`bookings.update`).
- `capacity_override: true` on create and edit — book past capacity on
  purpose; never past a closure or the season, and audited as
  `booking.capacity_overridden` (`bookings.override_capacity`, never a
  default; AI can never use it).
- `reservation_id` and `notes` on bookings. A booking given only a stay takes
  its reservation.
- `GET /api/booking` filters: `activity_id`, `reservation_id`,
  `scheduled_from`, `scheduled_to`, `cancellation_requested`. Bookings carry
  `cancellation_requested`.
- `GET /api/booking/cancellation-requests` (`bookings.view`), and
  `POST /api/booking/{id}/cancellation-request/approve` and `/decline`
  (`bookings.update_status`). Cancelling a booking any other way also closes
  its open request as approved.
- The concierge checks availability before offering an activity, books only
  dates from today to the guest's departure, offers up to 3 nearby dates when
  one is full, never duplicates a retried booking, and creates a cancellation
  request instead of cancelling. Each request emails the hotel's admins once.

### Docs

- [booking-entity-documentation.md](booking-entity-documentation.md)
- [activity-api-documentation.md](activity-api-documentation.md#6-activity-availability)
- [staff-roles-api-documentation.md](staff-roles-api-documentation.md)
