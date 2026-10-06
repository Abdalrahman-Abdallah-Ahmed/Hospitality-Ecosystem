# Research: Activity Availability and Booking Workflow

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-10-05

There was no open NEEDS CLARIFICATION in the Technical Context. The spec's five
clarifications fixed the product decisions. The decisions below settle how the feature
fits the existing code, and each was checked against it.

---

## R1 — One service owns activity availability

**Decision**: A new `App\Services\ActivityAvailabilityService` is the only place that
decides whether an activity can take a party on a date. These callers use it:

- the staff booking endpoints (create and edit);
- the new availability endpoint;
- `GetActivitiesTool`;
- `CreateBookingTool`;
- `BookingService`.

It has four public methods:
- `day(Activity, date)` gives one date's availability.
- `range(Activity, from, to)` gives every date in a range.
- `assertBookable(Activity, date, ?time, pax, ?ignoreBookingId)` throws
  `ActivityUnavailableException`.
- `nearestOpenDates(Activity, from, pax, limit, until)`.

**Rationale**: This mirrors `AvailabilityService` for rooms ("staff endpoint, both AI
tools and ReservationCreator all read the same numbers from here, so they can never
disagree"). It is what makes SC-003, "the lookup and the check never disagree", testable.

**Alternatives rejected**:
- Validation rules in `StoreBookingRequest`: the AI paths would bypass them.
- Model methods on `Activity`: the lock and the booked-load query don't belong on the model.

## R2 — Capacity per day; opening windows only bound the start time

**Decision** (spec clarification Q1):
- `operating_hours[weekday]` windows decide whether a time is allowed.
- `daily_capacity` is shared by every window on that date.
- If `operating_hours` is null, the activity is open every day, all day, and takes a
  date only.
- If `operating_hours` is set but has no windows for a weekday (or an empty list), the
  activity is closed that weekday.
- A start time is valid when `start <= time < end`.

**Rationale**: No new activity attributes, and it follows the existing column meanings
documented in `ValidatesActivityTimeframe` and `GetActivitiesTool`.

**Alternatives rejected**: Capacity per window, or fixed bookable slots. Both are deferred
to post-MVP by Q1.

## R3 — Store the hotel-local date range on the booking

**Decision**: Add three columns to `bookings`:
- `scheduled_date` (date): the hotel-local start date.
- `scheduled_time` (time, nullable): the hotel-local start time.
- `last_date` (date): `scheduled_date + (activity.duration_days ?? 1) - 1`.

They are set only by `BookingService`. A date's booked load is the sum of `pax` over
bookings where:
- `activity_id = ?`;
- the status is pending, confirmed or realised;
- `scheduled_date <= D <= last_date`.

An index `(activity_id, scheduled_date, last_date)` supports the query. `scheduled_for`
stays and is still returned, so nothing that reads it breaks.

**Rationale**:
- `scheduled_for` is a UTC timestamp, so a date taken from it depends on the time zone
  (see R4).
- Storing `last_date` means a later change to `duration_days` doesn't silently move the
  load of existing bookings.
- Multi-day activities (FR-006) become a single range test.

**Alternatives rejected**:
- Compute dates from `scheduled_for` in SQL with `AT TIME ZONE`: the query is fragile
  and can't use the index.
- A per-day load table: an extra write path to keep in sync, for a load that is cheap
  to count.

## R4 — How `scheduled_for` is read

**Decision**: Today `Carbon::parse($input)` runs in the app time zone (UTC), so a
wall-clock time a guest gives in hotel-local time is stored as if it were UTC. From this
feature on:
- `BookingService` reads an input with no offset as **hotel-local** time.
- It stores `scheduled_date` and `scheduled_time` as that local wall clock.
- It stores `scheduled_for` as the real UTC instant.
- An input with an explicit offset is converted to hotel-local time.
- The API also accepts `scheduled_date` without a time, for activities that have no
  opening windows.

**Backfill**: existing rows get these values:
- `scheduled_date` and `scheduled_time` from the stored wall clock, which was the local
  time entered.
- `scheduled_for` rewritten to the real instant in the hotel's time zone.
- `last_date` from the activity's `duration_days`.

Hotels on UTC see no change. Rows with no `scheduled_for` keep null dates. They hold no
capacity, and the edit path asks for a date before any other change to a catalogue
booking.

**Rationale**: FR-002 and FR-003 depend on the hotel-local weekday and time. The current
behaviour is a latent bug for any hotel that isn't on UTC.

**Alternatives rejected**: Leaving `scheduled_for` as it is. Every later reader (the
calendar, reminders) would carry the time-zone offset error.

## R5 — Order of checks and reason codes

**Decision**: There is a new enum `App\Enums\ActivityUnavailableReason`. The checks run
in this order, and the first failure wins:

| Order | Reason code | Rule |
| --- | --- | --- |
| 1 | `inactive` | The activity is inactive or deleted (FR-002) |
| 2 | `past_date` | The date is before today (FR-007) |
| 3 | `out_of_season` | Outside the season (FR-002) |
| 4 | `closure_period` | Inside a closure period; the message carries its reason (FR-002) |
| 5 | `closed_weekday` | No window on that weekday while opening hours are set (FR-002) |
| 6 | `time_required` | The time is missing (FR-003) |
| 7 | `outside_opening_hours` | The time is outside every window (FR-003) |
| 8 | `party_exceeds_capacity` | The party is larger than the daily capacity (edge case) |
| 9 | `fully_booked` | Not enough places left (FR-004) |

For multi-day activities, checks 3–5 and 8–9 run on every covered date, and the first
failing date is reported.

`ActivityUnavailableException` extends `ValidationException`, the same pattern as
`InsufficientAvailabilityException`. It answers 422 with:
- `errors.scheduled_for`;
- a structured `unavailable` object: `reason`, `date`, `windows`, `capacity`, `booked`,
  `remaining`, `closure_reason`.

The AI tools turn the same object into a sentence.

**Rationale**: One structure is enough for the frontend override prompt and for the
Concierge's explanation (FR-010). Putting the cheap date checks before the capacity query
keeps it to one query per check.

## R6 — Concurrency: lock the activity row

**Decision**: `BookingService::create` and `::reschedule` run inside `DB::transaction`.
They:
1. lock the activity row with `lockForUpdate()`;
2. re-read the booked load;
3. assert the booking fits;
4. write.

A move between two activities locks both, in id order. This is the same pattern as
`AvailabilityService::lockTypes`.

**Rationale**: FR-009 and SC-002. A row lock on the activity serializes only bookings for
that activity, so unrelated bookings are not blocked.

**Alternatives rejected**:
- Advisory locks: one more mechanism in the codebase.
- `SERIALIZABLE` isolation: retry handling at every caller.

## R7 — Capacity override

**Decision**:
- **Permission**: a new `Permission::BOOKINGS_OVERRIDE_CAPACITY` (`bookings.override_capacity`),
  checked through `BookingPolicy::overrideCapacity()`. It is not in
  `employeeDefaults()`.
- **Request**: the store and update requests take `capacity_override: true`. The
  controller authorizes it only when it is sent, the same as
  `ReservationController::overbookOverride`, so a missing permission is a 403.
- **What it bypasses**: only reasons 8 and 9. It is ignored whenever
  `EventLogger::currentActorKind()` is `AI_AGENT`, as in `AvailabilityService::guard`.
- **Audit**: the override is recorded as `booking.capacity_overridden` with the date,
  capacity and booked load.

**Rationale**: Spec clarification Q3, and consistency with `reservations.overbook`.

## R8 — Editing booking details

**Decision**:
- **Route**: new `PATCH /api/booking/{booking}`, the `update` action.
- **Permission**: a new `Permission::BOOKINGS_UPDATE` (`bookings.update`).
  `BookingPolicy::update` changes from a hard `false` to `allows(BOOKINGS_UPDATE)`.
- **Editable fields**: `activity_id`, `scheduled_for` / `scheduled_date`, `pax`, `notes`,
  `item_name` (free-text bookings only), `capacity_override`.
- **Rules**: `BookingService::update()` refuses any booking that is not pending or
  confirmed (FR-020). It re-runs the availability check only when the activity, date,
  time or pax changed (FR-001), excluding the booking's own load (FR-004).
- **Immutable fields**: guest, recommendation, origin, reference, creator, status and
  `charge_model` are not accepted (FR-021).
- **Default access**: `bookings.update` **is** added to `employeeDefaults()`. Employees
  without a role can already create bookings and cancel them. Changing the date or party
  size of a booking they took is a smaller act than cancelling it, and it is still
  checked against availability. The override stays out of the defaults.

**Rationale**: Without editing, staff cancel and re-create, which breaks the
recommendation credit. Delete, restore and force-delete stay a hard `false`.

## R9 — Reservation link

**Decision**: add `bookings.reservation_id`, a nullable foreign key with null on delete,
indexed.

| Path | How the link is set |
| --- | --- |
| Staff | May send `reservation_id` and/or `stay_id` |
| Stay sent without a reservation | The reservation is taken from the stay |
| Concierge | Links its resolved reservation, and the stay when it is in-house |
| Backfill | Fills `reservation_id` from `stays.reservation_id` |

Validation:
- `invalidRelation()` covers `reservations`.
- A stay whose `reservation_id` differs from the given reservation → 422 (FR-018).
- The reservation's primary guest is not enforced to equal the booking guest, because a
  companion guest may book.

## R10 — Concierge booking rules and idempotency

**Decision**: `CreateBookingTool`:
- Refuses when the agent has no reservation (FR-016, US3-6).
- Refuses dates outside `[hotel today, reservation.departure_date]`: the reason code
  `outside_reservation`, an AI-only case of the R5 enum (spec Q5).
- Calls `BookingService::create` with no override.
- Catches `ActivityUnavailableException` and returns the reason plus
  `nearestOpenDates(..., limit: 3, until: min(today + 14, departure))` as text. No booking
  is saved.

Inside the activity lock, before inserting, the service looks for a booking with the same
guest, activity, date, time and pax, status pending or confirmed, created in the last 10
minutes. If it finds one, it returns that booking (FR-017). This check is used only when
the actor is AI, so a desk clerk entering two identical walk-ups gets two bookings.

The `recommendation_id` handling, `origin` and `channel` stay unchanged.

**Rationale**: The lock from R6 already serializes the race. Matching on a natural key
needs no idempotency-key plumbing through `laravel/ai`.

## R11 — Concierge activity listing with availability

**Decision**: `GetActivitiesTool` takes optional `date`, or `from` + `to` (at most 14
days for AI). With a date, each activity gets an `availability` list from
`ActivityAvailabilityService::range`:
- `open` and `reason`;
- that day's `windows`;
- `remaining`, only when the capacity is set (US2-3).

Without a date the output is as today, so `RecommendationAgent` and `AdminAdvisorAgent`
are unaffected unless they ask. The description tells the model to check before offering.

## R12 — Cancellation request = a task with a booking link

**Decision**: a cancellation request is a `Task`:

| Field | Value |
| --- | --- |
| `booking_id` | New nullable foreign key |
| `guest_id`, `reservation_id`, `stay_id` | From the booking |
| `guest_signal` | New case `GuestSignal::CANCELLATION_REQUEST` |
| `created_by` | `CreatedBy::GUEST` |
| Team / user | None |
| Category | None |
| `title` | "Cancellation request: {item} on {date} ({reference})" |
| `description` | The guest's reason |

New task columns record the decision:
- `resolution` (`approved` | `declined`), a new `CancellationResolution` enum;
- `resolution_note`;
- `resolved_by_user_id`;
- `resolved_at`.

A partial unique index on `tasks(booking_id)` covers rows where:
- `guest_signal = 'cancellation_request'`;
- `status` is pending or in_progress;
- `deleted_at` is null.

That index enforces FR-024 at the database. The tool also checks first and returns the
open request, and it catches a unique violation as "already open".

The spec says it is "held as a staff task". The task list, `TaskPolicy` and the audit
already exist. `PitchEligibilityService` only looks at `ESCALATION` and `SERVICE_REQUEST`,
so the new signal doesn't pause pitching. That is correct: a cancellation is not a
complaint.

**Alternatives rejected**: A separate `booking_cancellation_requests` table. It would
duplicate the task lifecycle and need its own queue UI, and the spec chose the task.

## R13 — Approving and declining

**Decision**:
- `BookingService::cancel()` closes any open request in the same transaction: the task
  is `completed` with `resolution=approved`, the resolver is the current actor, and
  `resolved_at` is now. This applies whether staff use the request endpoint or the
  status endpoint (FR-026).
- `POST /api/booking/{booking}/cancellation-request/approve` (optional `reason`, which
  defaults to the guest's reason) calls `cancel()`.
- `POST /api/booking/{booking}/cancellation-request/decline` (required `note`) completes
  the task with `resolution=declined`. The booking is untouched.
- Both need `bookings.update_status`. Approving a request on a booking that can no
  longer be cancelled (it was realised meanwhile) returns 422 and leaves the request open.

## R14 — Admin email (spec Q4)

**Decision**: a new `BookingCancellationRequestedNotification` (mail), sent through a new
`CreationNotificationService::bookingCancellationRequested(Task)`:
- **Recipients**: the hotel's admins (`Hotel::admins()`).
- **Content**: the guest, booking reference, activity, date, party and reason.
- **When**: `DB::afterCommit`, so a rolled-back request sends nothing.
- **De-duplication**: the request is unique per booking, and the email is sent only when
  a new task row is created. A repeat request returns the existing task and sends no
  email.
- **No other emails**: the tool does **not** call `taskCreated(…, createdByAi: true)`,
  which would send a second, generic `AiTaskCreatedNotification` to the same admins.
- **No admin**: if the hotel has no admin, a warning is logged and the request still
  appears in the queue.

## R15 — Queue and calendar data

**Decision**:
- **Queue**: `GET /api/booking/cancellation-requests` lists open request tasks, oldest
  first, each with its booking, guest and activity, paginated. It needs `bookings.view`.
- **Booking list**: `GET /api/booking` keeps `GenericQuery` and adds explicit
  `scheduled_from`, `scheduled_to` (on `scheduled_date`), `activity_id`, `reservation_id`
  and `cancellation_requested` parameters through a `BookingIndexRequest` that extends
  `GenericIndexRequest`. Sorting is by `scheduled_date`, then `scheduled_time`.
- **Response**: `BookingResource` adds `reservation_id`, `scheduled_date`,
  `scheduled_time`, `last_date`, `notes` and `cancellation_requested` (bool, from an
  `exists` sub-select so a list doesn't run one query per row).

## R16 — Availability endpoint

**Decision**:
- **Route**: `GET /api/activity/{activity}/availability?from=YYYY-MM-DD&to=YYYY-MM-DD`.
  `to` is optional (a single day) and the range is at most 31 days (FR-011). It needs
  `activities.view`, through `ActivityPolicy::view`.
- **Past dates**: allowed in the lookup, flagged `past: true`, so the calendar can show
  history.
- **Each day returns**: `date`, `open`, `reason`, `closure_reason`, `windows`,
  `capacity`, `booked`, `remaining`, `past`, and `has_bookings_while_closed` (FR-012).
- **Query cost**: one query for the load over the range, whatever its length.

## R17 — Audit

**Decision**: `Booking::eventLoggedAttributes()` adds `reservation_id`,
`scheduled_date`, `scheduled_time`, `last_date` and `notes`. Edits and status changes are
already logged through `RecordsEvents`.

These events are added with `EventLogger::record`:

| Event | Recorded against |
| --- | --- |
| `booking.capacity_overridden` | The booking (R7) |
| `booking.cancellation_requested` | The booking, with the task id |
| `booking.cancellation_declined` | The booking, with the note |

Approval is already logged as `booking.cancelled`. The Concierge runs under
`EventLogger::asAiAgent`, as it does today, so the AI actor is recorded (FR-032).

## R18 — Rules that stay as they are

- Bookings stay valid when an activity is changed after they were made. A capacity cut,
  a new closure or a shorter season does not cancel them, and R16 flags any dates
  affected.
- Free-text bookings (no `activity_id`) skip R5 but still get R3's dates when a date is
  given.
- Status changes (`realised`, `no_show`) on past bookings are not re-checked.
- Nothing touches the transaction ledger (D11). `linkSettlement` is unchanged and unused
  here.

## R19 — Documentation and breaking changes

**Documentation**:
- `docs/booking-entity-documentation.md` gets the new fields, the edit endpoint, the
  availability errors, the override and the cancellation requests.
- `docs/activity-api-documentation.md` gets the availability endpoint.
- `docs/staff-roles-api-documentation.md` gets the two new permissions.

**Breaking changes**, summarized in `docs/latest-changes-2026-10-05.md`:
- Catalogue bookings that used to be accepted for closed or full dates now return 422.
- `scheduled_for` without an offset is now read as hotel-local time.
- Existing `scheduled_for` values are rewritten for hotels not on UTC.

## R20 — Frontend slice (ecosystem-frontend, D13)

- A booking calendar and list by activity and date, using the R15 filters and the R16
  availability.
- A booking edit dialog, with an override prompt when the response carries
  `unavailable.reason = fully_booked` and the user holds `bookings.override_capacity`.
- A cancellation-request queue with approve and decline actions.

The frontend tasks run in the frontend repo. This repo provides the endpoints and docs.
