# Implementation Plan: Activity Availability and Booking Workflow

**Branch**: `006-activity-bookings` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/006-activity-bookings/spec.md`. It is
Phase 6 of the master plan: SPEC-041 (Activity Availability & Capacity) and SPEC-043
(Booking Staff Workflow & Cancellation Requests).

## Summary

**Availability (SPEC-041)**: a new `ActivityAvailabilityService` is the single source of
truth (R1). It checks the following, with ordered reason codes (R5):
- the active flag;
- past dates;
- the season;
- closure periods;
- weekday windows;
- the start time;
- the per-day capacity (spec Q1, R2).

Bookings store hotel-local `scheduled_date`, `scheduled_time` and `last_date`, so
multi-day load is a range test (R3). `scheduled_for` without an offset is now read as
hotel-local time (R4).

`BookingService::create` and the new `::update` lock the activity row, re-read the load
and assert in one transaction (R6). Pending bookings hold places (Q2). Staff with the new
`bookings.override_capacity` permission can override capacity, never closures and never
from AI, and every override is audited (Q3, R7).

A new `GET /activity/{id}/availability` endpoint (at most 31 days) feeds the calendar and
uses the same code (R16).

**Staff workflow (SPEC-043)**:
- A new `PATCH /booking/{id}` edits the date, time, party size, activity and notes,
  under the new `bookings.update` permission (R8).
- Bookings gain `reservation_id` (R9).
- The booking list gains activity, date-range and request filters (R15).

**Cancellation requests (SPEC-043)**:
- **Creating**: a new Concierge `RequestBookingCancellationTool` creates a `Task` with
  `booking_id` and `guest_signal = cancellation_request`. There is one open request per
  booking (partial unique index), and it is never assigned to a team (R12).
- **Email**: the hotel's admins get one email after commit (Q4, R14).
- **Queue**: staff approve or decline it from a queue (R13, R15).
- **Closing**: any cancellation closes the open request as approved (R13).

**Concierge**:
- `GetActivitiesTool` reports per-date availability on request (R11).
- `CreateBookingTool` enforces the reservation window (Q5), returns reasons and the
  nearest dates, never overrides, and is idempotent for 10 minutes (R10).

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, Sanctum, `laravel/ai` (tools), Pest 4

**Storage**: PostgreSQL. The schema changes:
- 3 migrations: bookings columns, task columns, backfill.
- Check constraints on `last_date >= scheduled_date` and `tasks.resolution`.
- A partial unique index on open cancellation requests.
- `SELECT … FOR UPDATE` on `activities` rows, in id order (R6).

**Testing**: Pest feature tests on real Postgres (`Hospitality_Ecosystem_testing`). The
concurrency test uses `DatabaseTruncation` and a second connection.

**Target Platform**: Laravel JSON API. The frontend slice (calendar, edit and override
dialog, cancellation queue) is built in `ecosystem-frontend` (D13, R20).

**Project Type**: Multi-tenant hospitality PMS backend (web service)

**Performance Goals**:
- A booking check is a fixed 2 queries: lock, then load.
- A 31-day availability lookup is 2 queries.
- The booking list adds no query per row (`cancellation_requested` is a sub-select).
- A desk booking responds in under 1 s.

**Constraints**:
- Tenant isolation on every path. The AI tools name the hotel explicitly; the backfill
  uses `withoutScope`.
- All-or-nothing writes.
- Idempotent AI booking and requests.
- Email only after commit.
- No ledger writes (D11).
- The Concierge never cancels (constitution: Activities and Bookings).

**Scale/Scope**: About 50 activities and hundreds of bookings a day per hotel. The work is
3 migrations, about 12 new classes and about 14 changed ones.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle / rule | Status | How |
| --- | --- | --- |
| I. PMS is system of record | ✅ | Availability is computed from structured activity and booking rows. The AI reads it only through `GetActivitiesTool` and `CreateBookingTool`, never from RAG |
| II. Preserve and evolve | ✅ | Reuses `Booking`, `BookingService` and its status machine, `Task` for requests, `CreationNotificationService`, the `InsufficientAvailabilityException` pattern, the `overbookOverride` pattern and `GenericQuery`. No endpoint removed. Stricter validation and the `scheduled_for` time-zone fix are announced as breaking (R19) |
| III. Tenant isolation | ✅ | Routes bind under the hotel scope. `invalidRelation` covers activity, guest, stay and reservation. The tools query by `hotel_id`. Isolation tests cover availability, the queue, approve, decline and edit |
| IV. Permission-based auth | ✅ | `bookings.update` and `bookings.override_capacity` are new enum cases checked through `BookingPolicy::allows()`. The override is not a default. The request actions reuse `bookings.update_status`, availability reuses `activities.view`. Both are added to the permission dataset and docs |
| V. AI through tools | ✅ | The Concierge books and requests through `BookingService` and `BookingCancellationService` under `asAiAgent`. The override is ignored for the AI actor. No cancel tool exists |
| VI. Auditability | ✅ | `RecordsEvents` on the new booking and task fields, plus `capacity_overridden`, `cancellation_requested` and `cancellation_declined` events with the actor (R17) |
| VII. Integrity and idempotency | ✅ | Lock, re-check and write in one transaction. A database-level unique index for open requests. A 10-minute natural-key de-duplication for AI bookings. Email after commit and only on insert |
| VIII. Tested at domain boundary | ✅ | 7 new test files plus the extended Booking, Permission and TenantIsolation tests ([quickstart](quickstart.md)). The AI is tested at the tool boundary |
| IX. Spec-driven | ✅ | Spec plus 5 clarifications → this plan |
| Activities: reuse the generic Booking domain | ✅ | No activity-specific booking entity |
| Activities: the Concierge must not cancel | ✅ | It can only create a request (FR-023, SC-004) |
| MVP: no ledger dependency | ✅ | Nothing writes transactions |
| Rule: incrementally deployable | ⚠️ justified | Bookings that used to be accepted can now return 422, and `scheduled_for` without an offset is read as hotel-local. See Complexity Tracking |

**Post-design re-check**: ✅. The one item marked ⚠️ is justified below.

## Project Structure

### Documentation (this feature)

```text
specs/006-activity-bookings/
├── spec.md
├── plan.md                                  # this file
├── research.md                              # decisions R1–R20
├── data-model.md
├── quickstart.md
├── contracts/activity-booking-api.md
├── checklists/requirements.md
└── tasks.md                                 # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── ActivityUnavailableReason.php        # NEW (R5)
│   ├── CancellationResolution.php           # NEW (R12)
│   ├── GuestSignal.php                      # + CANCELLATION_REQUEST
│   ├── BookingStatus.php                    # + holdingCapacity(), isEditable()
│   └── Permission.php                       # + BOOKINGS_UPDATE (default), BOOKINGS_OVERRIDE_CAPACITY
├── Exceptions/
│   └── ActivityUnavailableException.php     # NEW, extends ValidationException (R5)
├── Support/Activities/
│   └── DayAvailability.php                  # NEW value object
├── Services/
│   ├── ActivityAvailabilityService.php      # NEW (R1, R2, R3, R6)
│   ├── BookingService.php                   # create() locks and checks; NEW update(); cancel() closes request; schedule normalisation (R4)
│   ├── BookingCancellationService.php       # NEW: request / approve / decline (R12, R13)
│   └── CreationNotificationService.php      # + bookingCancellationRequested() (R14)
├── Notifications/
│   └── BookingCancellationRequestedNotification.php  # NEW (mail)
├── Models/
│   ├── Booking.php                          # + fields, reservation(), openCancellationRequest()
│   └── Task.php                             # + booking(), casts, logged attributes
├── Policies/
│   └── BookingPolicy.php                    # update() → allows(BOOKINGS_UPDATE); + overrideCapacity()
├── Http/
│   ├── Controllers/
│   │   ├── BookingController.php            # store changes, NEW update()
│   │   ├── ActivityAvailabilityController.php        # NEW (R16)
│   │   └── BookingCancellationRequestController.php  # NEW: index / approve / decline
│   ├── Requests/
│   │   ├── StoreBookingRequest.php          # + reservation_id, scheduled_date, notes, capacity_override
│   │   ├── UpdateBookingRequest.php         # NEW
│   │   ├── BookingIndexRequest.php          # NEW, extends GenericIndexRequest (R15)
│   │   ├── ActivityAvailabilityRequest.php  # NEW
│   │   └── DeclineCancellationRequest.php   # NEW
│   └── Resources/
│       ├── BookingResource.php              # + new fields, cancellation_requested
│       └── BookingCancellationRequestResource.php    # NEW
└── Ai/
    ├── Tools/
    │   ├── GetActivitiesTool.php            # + date / from / to → availability (R11)
    │   ├── CreateBookingTool.php            # window, reasons, alternatives, idempotent (R10)
    │   └── RequestBookingCancellationTool.php        # NEW
    └── Agents/GuestConciergeAgent.php       # register tool; instructions: check before offering, request (never cancel)

database/migrations/
├── 2026_10_05_000001_add_schedule_and_reservation_fields_to_bookings_table.php
├── 2026_10_05_000002_add_booking_cancellation_fields_to_tasks_table.php
└── 2026_10_05_000003_backfill_booking_schedule_dates.php

routes/api.php                               # availability, PATCH booking, cancellation-request routes
                                             # (register /booking/cancellation-requests before /booking/{booking})

tests/Feature/
├── ActivityAvailabilityServiceTest.php      # NEW
├── ActivityAvailabilityControllerTest.php   # NEW
├── BookingUpdateTest.php                    # NEW
├── BookingCapacityConcurrencyTest.php       # NEW
├── BookingCancellationRequestTest.php       # NEW
├── ConciergeBookingToolsTest.php            # NEW
├── BookingScheduleBackfillTest.php          # NEW
├── BookingControllerTest.php                # extended
├── PermissionAuthorizationTest.php          # dataset
└── TenantIsolationTest.php                  # extended

docs/
├── booking-entity-documentation.md
├── activity-api-documentation.md
├── staff-roles-api-documentation.md
└── latest-changes-2026-10-05.md             # NEW: breaking changes (R19)
```

**Structure Decision**: This is the existing single Laravel app, using the layers in
CLAUDE.md:
- controllers stay thin;
- the business logic lives in `app/Services`;
- the value object lives in `app/Support/Activities`.

No new top-level directories.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Booking creation that used to succeed now returns 422 for closed or full dates | That is the feature: FR-001 to FR-010, and the master-plan Phase 6 item 1 | Warning-only checks would keep overselling, which the spec exists to stop. The override covers real exceptions |
| `scheduled_for` without an offset is now hotel-local, and existing rows are rewritten for non-UTC hotels (R4) | The weekday and opening-hours checks need the correct local time. The old value was wrong for any non-UTC hotel | Keeping UTC wall clocks would make every availability check off by the hotel's time-zone offset. The backfill is idempotent and announced in `latest-changes-2026-10-05.md` |
| Three nullable resolution columns on the generic `tasks` table | The spec keeps a request as a task, but its outcome (approved or declined, note, resolver) must be recorded for Phase 7 guest notification | A separate requests table would duplicate the task lifecycle, the queue and the audit |
