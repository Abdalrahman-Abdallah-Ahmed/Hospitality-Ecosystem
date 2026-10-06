# Quickstart: Activity Availability and Booking Workflow

How to prove the feature works. The endpoint shapes are in
[contracts/activity-booking-api.md](contracts/activity-booking-api.md), and the fields in
[data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d   # Postgres + pgvector (tests need it)
php artisan migrate                              # applies the 2026_10_05_* migrations
```

Tests run against `Hospitality_Ecosystem_testing` (see `phpunit.xml`). Don't use
`--env=testing`: there is no `.env.testing`, so it would hit the dev database.

## Automated validation

```bash
php artisan test --filter=ActivityAvailability
php artisan test --filter=BookingController
php artisan test --filter=BookingUpdate
php artisan test --filter=BookingCapacityConcurrency
php artisan test --filter=BookingCancellationRequest
php artisan test --filter=ConciergeBookingTools
php artisan test --filter=BookingScheduleBackfill
php artisan test --filter=PermissionAuthorization
php artisan test --filter=TenantIsolation
php artisan test                                 # full suite must stay green
./vendor/bin/pint --test
```

| Test file | Proves |
| --- | --- |
| `ActivityAvailabilityServiceTest` (new) | Every refusal reason, in R5 order. Multi-day load. Unlimited capacity. Hotel time zone. The lookup and the check agree (SC-003) |
| `ActivityAvailabilityControllerTest` (new) | The range at most 31 days, `has_bookings_while_closed`, past days flagged, 403 without `activities.view` |
| `BookingControllerTest` (extended) | Store refusals with the `unavailable` payload. Free text is unchecked. Reservation and stay consistency. Override 403 vs. allowed and audited |
| `BookingUpdateTest` (new) | Edits within and beyond capacity (own load excluded). Closed-day move refused. A notes-only edit skips the check. Terminal bookings refused. Immutable fields |
| `BookingCapacityConcurrencyTest` (new) | Two connections race for the last place; exactly one wins (SC-002). Uses `DatabaseTruncation`, like `CheckInOutConcurrencyTest` |
| `BookingCancellationRequestTest` (new) | One request per booking. One email per admin and none on a repeat. Approving through the request or the status endpoint closes it. Decline keeps the booking. Queue order. Another guest's booking refused |
| `ConciergeBookingToolsTest` (new) | `CreateBookingTool` refusals and alternatives, reservation window, no override, 10-minute idempotency, AI actor audit. `GetActivitiesTool` availability output. `RequestBookingCancellationTool` never cancels (SC-004) |
| `BookingScheduleBackfillTest` (new) | Wall clock to local date and time, UTC instant rewritten for a non-UTC hotel, `last_date` from duration, `reservation_id` from the stay |
| `PermissionAuthorizationTest` (dataset) | `bookings.update`, `bookings.override_capacity`, the cancellation-request routes, the availability route |
| `TenantIsolationTest` (extended) | Availability, the queue, approve and decline, and edit across hotels |

## Manual walk-through (API)

Set `X-API-KEY` and a bearer token for a hotel admin.

1. **Set up**: create an activity with these settings.
   - `daily_capacity: 4`
   - `operating_hours: { "friday": [{ "start": "17:00", "end": "19:00" }] }`
   - a closure period covering next week's Wednesday
2. **Look up**: `GET /api/activity/{id}/availability?from=<next Fri>&to=<next Fri + 6>`.
   The Friday is open with 4 places, the other weekdays are `closed_weekday`, and the
   Wednesday is `closure_period`.
3. **Fill the date**: `POST /api/booking` for 3 people on Friday at 17:30 → 201. A second
   booking for 2 → 422 `fully_booked`, `remaining: 1`.
4. **Override**: repeat the second booking with `capacity_override: true` → 201. As an
   employee without `bookings.override_capacity` → 403.
5. **Edit**: `PATCH /api/booking/{first}` with `pax: 4` → 422. With `notes` only → 200.
6. **Cancellation request**: run the Concierge request tool for the guest's booking. The
   request appears in `GET /api/booking/cancellation-requests`, the admin gets one email,
   and the booking is unchanged.
7. **Approve**: `POST /api/booking/{id}/cancellation-request/approve`. The booking is
   cancelled, the queue is empty, and the Friday regains places.

## Done when

- Every test above is green and Pint is clean.
- The docs are updated:
  - `docs/booking-entity-documentation.md`
  - `docs/activity-api-documentation.md`
  - `docs/staff-roles-api-documentation.md`
  - `docs/latest-changes-2026-10-05.md`
- The frontend slice (R20) is handed to `ecosystem-frontend`.

## Frontend handoff (ecosystem-frontend, D13)

The backend for these screens is ready. API shapes are in
`docs/booking-entity-documentation.md` and `docs/activity-api-documentation.md`.

- **Booking calendar and list.** Pick an activity and a week or month. Days come from
  `GET /api/activity/{id}/availability?from=&to=` (at most 31 days): show open or closed
  with its `reason`, `booked` against `capacity`, and flag `has_bookings_while_closed`.
  Bookings come from `GET /api/booking?activity_id=&scheduled_from=&scheduled_to=`.
- **Booking form and edit dialog.** `POST /api/booking` and `PATCH /api/booking/{id}`.
  Send times as hotel-local `YYYY-MM-DD HH:MM` with no offset. On a `422` with
  `unavailable`, show its message. When `unavailable.overridable` is true and the user
  holds `bookings.override_capacity` (from `GET /api/user` permissions), offer "Book
  anyway", which resends with `capacity_override: true`.
- **Cancellation-request queue.** `GET /api/booking/cancellation-requests`, oldest first,
  with Approve (optional reason) and Decline (required note) buttons. Bookings in lists
  carry `cancellation_requested`, so a badge can link to the request.
