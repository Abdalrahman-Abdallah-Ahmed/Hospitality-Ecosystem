# Data Model: Activity Availability and Booking Workflow

**Feature**: [spec.md](spec.md) · Decisions referenced as R# are in [research.md](research.md).

Two migrations change existing tables, and a third backfills them. No new tables.

## Booking (`bookings`, existing): changed

New columns:

| Column | Type | Null | Set by | Notes |
| --- | --- | --- | --- | --- |
| `reservation_id` | uuid FK → reservations, null on delete | yes | client or derived | FR-018, R9. Indexed |
| `scheduled_date` | date | yes | `BookingService` only | Hotel-local start date (R3, R4) |
| `scheduled_time` | time | yes | `BookingService` only | Hotel-local start time. Null for all-day activities |
| `last_date` | date | yes | `BookingService` only | `scheduled_date + (duration_days ?? 1) - 1`, fixed when the booking is saved (R3) |
| `notes` | text | yes | client | Staff notes. Editable (FR-020) |

Index: `(activity_id, scheduled_date, last_date)`, used for the booked load.

`scheduled_date`, `scheduled_time` and `last_date` are not fillable from requests. The
service writes them with `forceFill`.

**Invariants**:
- `last_date >= scheduled_date` whenever both are set. This is a check constraint.
- `scheduled_for`, when set, equals `scheduled_date` + `scheduled_time` in the hotel time
  zone, as a UTC instant (R4).
- The hotel of `reservation_id`, `stay_id` and `guest_id` equals `bookings.hotel_id`.
  A linked stay belongs to the linked reservation (FR-018). Enforced in the service and
  the requests.
- A catalogue booking in pending or confirmed has a `scheduled_date` once created or
  edited after this feature.

**Holds capacity**: status is in `BookingStatus::holdingCapacity()`, a new helper that
returns `[PENDING, CONFIRMED, REALISED]` (FR-005).

**Editable** (FR-020) when the status is pending or confirmed. Editable fields:
`activity_id`, schedule, `pax` (≥ 1), `notes`, and `item_name` (free-text only).

**State transitions**: unchanged, forward only, in `BookingService::nextStatuses`. One
side effect is added: `cancel()` closes any open cancellation request as approved (R13).

```text
pending ──► confirmed ──► realised
   │            │  └────► no_show ──► realised
   │            └───────► cancelled ── closes open cancellation request (approved)
   ├──► realised / no_show
   └──► cancelled ─────── closes open cancellation request (approved)
```

`eventLoggedAttributes()` adds `reservation_id`, `scheduled_date`, `scheduled_time`,
`last_date` and `notes` (R17).

New relations:
- `reservation(): BelongsTo`.
- `cancellationRequests(): HasMany<Task>`, filtered on the `CANCELLATION_REQUEST`
  signal.
- `openCancellationRequest(): HasOne<Task>`.

## Task (`tasks`, existing): changed

New columns:

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `booking_id` | uuid FK → bookings, null on delete | yes | Set by the cancellation flow only. Indexed |
| `resolution` | string (`CancellationResolution`) | yes | `approved` / `declined`. Check constraint |
| `resolution_note` | text | yes | Required when declined |
| `resolved_by_user_id` | uuid FK → users, null on delete | yes | Null when no human resolved it |
| `resolved_at` | timestamp | yes | |

None of these is fillable. They are set by `BookingCancellationService` and
`BookingService` with `forceFill`, like `guest_signal`.

Partial unique index (FR-024):

```text
UNIQUE (booking_id)
WHERE guest_signal = 'cancellation_request'
  AND status IN ('pending','in_progress')
  AND deleted_at IS NULL
```

`eventLoggedAttributes()` adds `booking_id`, `resolution` and `resolution_note`.

### Cancellation request (a Task with `guest_signal = cancellation_request`)

| Field | Value |
| --- | --- |
| `hotel_id`, `guest_id`, `reservation_id`, `stay_id` | from the booking |
| `booking_id` | the booking |
| `title` | `Cancellation request: {item_name} on {scheduled_date} ({reference})` |
| `description` | the guest's reason |
| `created_by` | `guest` |
| team / user / category | none (spec Q4) |
| `status` | `pending` |

Lifecycle:

```text
pending (open) ──approve──► completed, resolution=approved   (booking cancelled in same transaction)
       │
       └──decline──► completed, resolution=declined, resolution_note   (booking untouched)
```

A request can only be created for a booking that is pending or confirmed and belongs to
the identified guest (FR-023).

## Activity (`activities`, existing): unchanged schema

These columns are read by `ActivityAvailabilityService`:
- `is_active`;
- `available_from`, `available_until`;
- `operating_hours`;
- `unavailable_periods`;
- `duration_days`;
- `daily_capacity`.

Meaning (R2):

| Column | Null / empty means |
| --- | --- |
| `available_from` / `available_until` | no season bound on that side |
| `operating_hours` = null | open every day, all day; no time needed |
| `operating_hours[weekday]` missing or `[]` | closed that weekday |
| `unavailable_periods` = null / `[]` | no closures |
| `duration_days` = null | a single day |
| `daily_capacity` = null | unlimited; never refused for capacity |

## Derived: DayAvailability (not stored)

`App\Support\Activities\DayAvailability` is a readonly value object. One is produced per
activity and date:

| Field | Type | Meaning |
| --- | --- | --- |
| `date` | Y-m-d | the hotel-local date |
| `open` | bool | true if a booking of 1 for this date passes rules 1–5 (R5) |
| `reason` | `ActivityUnavailableReason`, nullable | why it is closed or full |
| `closure_reason` | string, nullable | from the matching closure period |
| `windows` | list of {start, end} | that weekday's windows; `[]` = all day when the hours are null |
| `capacity` | int, nullable | `daily_capacity` |
| `booked` | int | booked load (FR-005) |
| `remaining` | int, nullable | `capacity - booked`, which can be ≤ 0 when capacity was lowered; null when unlimited |
| `past` | bool | the date is before the hotel's today |
| `has_bookings_while_closed` | bool | `!open && booked > 0` (FR-012) |

## Enums

| Enum | Change |
| --- | --- |
| `ActivityUnavailableReason` | **New**: `inactive`, `past_date`, `out_of_season`, `closure_period`, `closed_weekday`, `time_required`, `outside_opening_hours`, `party_exceeds_capacity`, `fully_booked`, `outside_reservation` (AI only) |
| `CancellationResolution` | **New**: `approved`, `declined` |
| `GuestSignal` | **Add** `CANCELLATION_REQUEST = 'cancellation_request'` |
| `BookingStatus` | **Add** helper `holdingCapacity()` and `isEditable()` |
| `Permission` | **Add** `BOOKINGS_UPDATE = 'bookings.update'` (an employee default, R8) and `BOOKINGS_OVERRIDE_CAPACITY = 'bookings.override_capacity'` (not a default) |

## Migrations (this repo's `YYYY_MM_DD_NNNNNN` sequence)

1. `2026_10_05_000001_add_schedule_and_reservation_fields_to_bookings_table`: the
   columns, the index and the check constraint above.
2. `2026_10_05_000002_add_booking_cancellation_fields_to_tasks_table`: the task columns,
   the resolution check and the partial unique index.
3. `2026_10_05_000003_backfill_booking_schedule_dates`: runs under
   `TenantContext::withoutScope()`, in chunks per hotel (R4):
   - reads the old wall clock from `scheduled_for` into `scheduled_date` and
     `scheduled_time`;
   - rewrites `scheduled_for` as the true UTC instant in the hotel's time zone;
   - sets `last_date` from `activity.duration_days`;
   - sets `reservation_id` from the stay.

   The down step clears the derived columns. It is idempotent: only rows with a null
   `scheduled_date` are touched.
