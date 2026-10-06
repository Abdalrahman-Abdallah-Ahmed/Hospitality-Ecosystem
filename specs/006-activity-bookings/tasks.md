---

description: "Task list for Activity Availability and Booking Workflow (006)"
---

# Tasks: Activity Availability and Booking Workflow

**Input**: Design documents from `specs/006-activity-bookings/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/activity-booking-api.md](contracts/activity-booking-api.md),
[quickstart.md](quickstart.md)

**Tests**: The constitution (VIII) and CLAUDE.md require them:
- Pest feature tests run against real Postgres.
- Every new permission needs a test that it allows the action and a test for the 403
  without it.
- Every tenant-scoped feature needs an isolation test.

Test tasks come before the implementation in each story.

**Organization**: Tasks are grouped by user story (US1–US7 from spec.md), so each story
can be built and tested on its own. R# refers to the decisions in research.md.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: the user story the task belongs to (US1…US7)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Get the environment ready. No new packages are needed.

- [X] T001 Start Postgres with `docker compose -f .postgres/compose.yaml up -d`. Run `php artisan test` and record the green baseline before any change. Don't use `--env=testing`: it hits the dev database.
- [X] T002 Read `app/Services/BookingService.php`, `app/Services/AvailabilityService.php` (`lockTypes`, `guard`), `app/Exceptions/InsufficientAvailabilityException.php` and `app/Http/Controllers/ReservationController.php::overbookOverride`. These are the patterns R1, R5, R6 and R7 copy.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The schema, enums, models and the availability service that every story uses.

**⚠️ CRITICAL**: No user-story work can start until this phase is complete.

### Schema

- [X] T003 Create `database/migrations/2026_10_05_000001_add_schedule_and_reservation_fields_to_bookings_table.php`.
  - Add `reservation_id` (nullable uuid FK → reservations, `nullOnDelete`, indexed), `scheduled_date` (date, nullable), `scheduled_time` (time, nullable), `last_date` (date, nullable) and `notes` (text, nullable).
  - Add the index `(activity_id, scheduled_date, last_date)`.
  - Add a Postgres check `last_date >= scheduled_date` when both are not null.
  - Write a down migration that drops all of them.
- [X] T004 Create `database/migrations/2026_10_05_000002_add_booking_cancellation_fields_to_tasks_table.php`.
  - Add `booking_id` (nullable uuid FK → bookings, `nullOnDelete`, indexed), `resolution` (nullable string, check `IN ('approved','declined')`), `resolution_note` (nullable text), `resolved_by_user_id` (nullable uuid FK → users, `nullOnDelete`) and `resolved_at` (nullable timestamp).
  - Add a partial unique index `UNIQUE (booking_id) WHERE guest_signal = 'cancellation_request' AND status IN ('pending','in_progress') AND deleted_at IS NULL`.
- [X] T005 Create `database/migrations/2026_10_05_000003_backfill_booking_schedule_dates.php` (R4). Run it under `TenantContext::withoutScope()`, chunked per hotel, and only touch rows where `scheduled_date IS NULL` so it is idempotent.
  - Read the stored `scheduled_for` wall clock (UTC components) as the hotel-local `scheduled_date` / `scheduled_time`.
  - Rewrite `scheduled_for` as that local wall clock in `hotels.timezone`, converted to a UTC instant.
  - Set `last_date = scheduled_date + (activities.duration_days ?? 1) - 1`.
  - Set `reservation_id` from `stays.reservation_id` where `stay_id` is set.
  - Make the down migration null the derived columns.

### Enums

- [X] T006 [P] Create `app/Enums/ActivityUnavailableReason.php`.
  - Cases, in this order: `inactive`, `past_date`, `out_of_season`, `closure_period`, `closed_weekday`, `time_required`, `outside_opening_hours`, `party_exceeds_capacity`, `fully_booked`, `outside_reservation`.
  - Add `overridable(): bool`, true only for `fully_booked` and `party_exceeds_capacity`.
- [X] T007 [P] Create `app/Enums/CancellationResolution.php` with cases `approved` and `declined`.
- [X] T008 [P] Add `CANCELLATION_REQUEST = 'cancellation_request'` to `app/Enums/GuestSignal.php`, with a comment saying it comes from RequestBookingCancellationTool and does not pause pitching.
- [X] T009 [P] In `app/Enums/BookingStatus.php`, add `holdingCapacity(): array` (PENDING, CONFIRMED, REALISED) and `isEditable(): bool` (PENDING or CONFIRMED).
- [X] T010 [P] In `app/Enums/Permission.php`, add `BOOKINGS_UPDATE = 'bookings.update'` and `BOOKINGS_OVERRIDE_CAPACITY = 'bookings.override_capacity'`, grouped with the other bookings cases.
  - Add `BOOKINGS_UPDATE` to `employeeDefaults()`, extending the docblock: changing the date or party size is checked against availability and is a smaller act than the cancel employees can already do (R8).
  - Leave `BOOKINGS_OVERRIDE_CAPACITY` out of the defaults, and say so in the docblock next to `reservations.overbook`.

### Models

- [X] T011 Update `app/Models/Booking.php`:
  - Add `reservation_id` and `notes` to `$fillable`. Do **not** add `scheduled_date`, `scheduled_time` or `last_date`; the service sets them with forceFill.
  - Add casts `scheduled_date` / `last_date` → `date`.
  - Add the relations `reservation(): BelongsTo`, `cancellationRequests(): HasMany` (Task where `guest_signal = cancellation_request`) and `openCancellationRequest(): HasOne` (the same, filtered to pending or in_progress).
  - Add `reservation_id`, `scheduled_date`, `scheduled_time`, `last_date` and `notes` to `eventLoggedAttributes()`.
- [X] T012 Update `app/Models/Task.php`:
  - Add the relations `booking(): BelongsTo` and `resolvedBy(): BelongsTo` (User, `resolved_by_user_id`).
  - Add casts `resolution` → `CancellationResolution` and `resolved_at` → datetime, with none of these fields fillable (the comment says "set only by BookingCancellationService / BookingService (forceFill)").
  - Add `booking_id`, `resolution` and `resolution_note` to `eventLoggedAttributes()`.

### Availability core (R1–R6)

- [X] T013 [P] Create `app/Support/Activities/DayAvailability.php`, a readonly value object with these fields: `date` (Y-m-d), `open` (bool), `reason` (?ActivityUnavailableReason), `closure_reason` (?string), `windows` (list<array{start,end}>), `capacity` (?int), `booked` (int), `remaining` (?int; may be ≤ 0; null when unlimited), `past` (bool), `has_bookings_while_closed` (bool, `!open && booked > 0`). Add `toArray()`.
- [X] T014 [P] Create `app/Exceptions/ActivityUnavailableException.php`, extending `ValidationException` like `InsufficientAvailabilityException`.
  - Add a static `for(Activity, ActivityUnavailableReason, string $date, ?DayAvailability)`. It builds a human sentence (for example "Sunset cruise is fully booked on 2026-10-09 (0 of 12 places left).") into `errors.scheduled_for`.
  - Expose the public properties `reason` and `unavailable` (array: reason, date, windows, capacity, booked, remaining, closure_reason, overridable).
  - Make `render()` return the JSON `{message, errors, unavailable}` for API requests.
- [X] T015 Create `app/Services/ActivityAvailabilityService.php` (R1, R2, R3, R5, R6). Every query names `hotel_id` explicitly, because the AI tools run without tenant context.
  - `today(Hotel)`.
  - `lock(array $activityIds)`: `lockForUpdate` in id order.
  - `bookedLoad(Activity, from, to, ?ignoreBookingId): array<date,int>`. It is one query that sums `pax` where `activity_id`, status in `BookingStatus::holdingCapacity()`, and `scheduled_date <= to AND last_date >= from`, spread over the covered dates.
  - `range(Activity, from, to): list<DayAvailability>`, two queries at most.
  - `day(Activity, date)`.
  - `assertBookable(Activity, string $date, ?string $time, int $pax, ?string $ignoreBookingId, bool $override)`. It applies the R5 order: inactive or deleted → past_date (hotel today) → per covered day (`duration_days`): out_of_season, closure_period (with its reason), closed_weekday → time_required / outside_opening_hours (`start <= time < end`, start day only) → party_exceeds_capacity → fully_booked. It throws `ActivityUnavailableException`. Only the two capacity reasons are skipped, and only when `$override && EventLogger::currentActorKind() !== ActorKind::AI_AGENT`.
  - `nearestOpenDates(Activity, string $from, int $pax, int $limit, string $until): list<string>`.
  - Weekday names come from `ValidatesActivityTimeframe::WEEKDAYS`.
- [X] T016 Update `app/Services/BookingService.php` with schedule normalisation (R4):
  - A private `normaliseSchedule(array $data, Hotel, ?Activity)`. It reads `scheduled_for` without an offset as `hotels.timezone` local time and converts one with an offset to local. It accepts `scheduled_date` alone. It forceFills `scheduled_date`, `scheduled_time` and `last_date` (`duration_days ?? 1`), and stores `scheduled_for` as the UTC instant (null when only a date is given).
  - `create()` runs inside `DB::transaction`. For catalogue bookings it calls `ActivityAvailabilityService::lock` → `assertBookable(..., override: $data['capacity_override'] ?? false)` → create. When the override was actually used, it records `EventLogger::record($booking, 'capacity_overridden', changes: [date, capacity, booked])`.
  - Metering and recommendation crediting stay after the insert, unchanged.
- [X] T017 [P] Write `tests/Feature/ActivityAvailabilityServiceTest.php` (`uses(RefreshDatabase::class)`, file-local fixtures). Cover:
  - every reason in R5 order;
  - null `operating_hours` = open all day with no time required;
  - an empty weekday list = closed;
  - the window boundary (`start` ok, `end` refused);
  - a 3-day activity loading each day and refused when its middle day is closed;
  - cancelled and no_show bookings not counting, pending counting;
  - null capacity never refused;
  - "today" for a hotel in `Asia/Dubai` vs UTC;
  - `nearestOpenDates` respecting limit and until;
  - SC-003: for each open day with N remaining, N passes and N+1 is refused.
- [X] T018 [P] Write `tests/Feature/BookingScheduleBackfillTest.php`. For a `Europe/Berlin` hotel, a row stored with wall clock `2026-10-09 17:30` (UTC components) becomes `scheduled_date=2026-10-09`, `scheduled_time=17:30`, `scheduled_for=2026-10-09 15:30 UTC`, with `last_date` from a 2-day activity and `reservation_id` from the stay. Also check that re-running changes nothing, and that a UTC hotel keeps `scheduled_for`.
- [X] T019 Run `php artisan migrate` and `php artisan test --filter="ActivityAvailabilityService|BookingScheduleBackfill"` until they are green.

**Checkpoint**: The availability service and schema are ready. The user stories can start.

---

## Phase 3: User Story 1 - A booking is refused when the activity is not available (Priority: P1) 🎯 MVP

**Goal**: Staff bookings for catalogue activities are checked against the season, opening times, closure periods and daily capacity, and refused with a clear reason. No overselling under concurrency.

**Independent Test**: An activity with a season, weekday windows, a closure and capacity 10. Bookings that break each rule get 422 with `unavailable.reason`, the valid one gets 201, and free-text bookings are unchecked.

### Tests for User Story 1

- [X] T020 [P] [US1] Extend `tests/Feature/BookingControllerTest.php`. Cover:
  - US1 scenarios 1–9: each refusal returns 422 with `unavailable.reason` (`fully_booked`, `closed_weekday`, `outside_opening_hours` with windows, `out_of_season`, `closure_period` with `closure_reason`, `inactive`);
  - a free-text booking unchecked;
  - a catalogue booking without a date → 422;
  - `scheduled_for` without an offset read as hotel-local (check `scheduled_date` / `scheduled_time`);
  - `reservation_id` from another hotel → 422;
  - a stay that doesn't belong to the given reservation → 422;
  - `reservation_id` derived from `stay_id` when omitted.
- [X] T021 [P] [US1] Write `tests/Feature/BookingCapacityConcurrencyTest.php` with `DatabaseTruncation` and a second DB connection, following `tests/Feature/CheckInOutConcurrencyTest.php`. Two bookings race for the last place: exactly one is saved and the load never exceeds capacity (SC-002).

### Implementation for User Story 1

- [X] T022 [US1] Update `app/Http/Requests/StoreBookingRequest.php`. Add:
  - `reservation_id` (`nullable|string|exists:reservations,id`);
  - `scheduled_date` (`nullable|date_format:Y-m-d`);
  - `notes` (`nullable|string|max:2000`);
  - `capacity_override` (`nullable|boolean`);
  - `pax` (`nullable|integer|min:1`, unchanged);
  - a catalogue booking requires `scheduled_for` or `scheduled_date`: `required_with:activity_id` through `required_without` on each other.
- [X] T023 [US1] Update `BookingController::store` in `app/Http/Controllers/BookingController.php`:
  - add `'reservations' => $validated['reservation_id'] ?? null` to `invalidRelation()`;
  - derive `reservation_id` from the stay when it is missing;
  - return 422 "The selected stay does not belong to the selected reservation." on a mismatch;
  - pass `reservation_id`, `scheduled_for` / `scheduled_date`, `notes` and the hotel to `BookingService::create`;
  - keep `apiResponse`. `ActivityUnavailableException` renders itself.
  - The override handling is added in US7 (T060). Until then, strip `capacity_override` with `unsetAttributes`.
- [X] T024 [US1] Update `app/Http/Resources/BookingResource.php` to add `reservation_id`, `scheduled_date` (Y-m-d), `scheduled_time` (H:i), `last_date` and `notes`.
- [X] T025 [US1] Run `php artisan test --filter="BookingController|BookingCapacityConcurrency|BookingTest"` and fix any regressions in existing booking tests caused by the new required date for catalogue bookings.

**Checkpoint**: Desk bookings can no longer oversell or book closed dates.

---

## Phase 4: User Story 2 - Staff and the Concierge see what is free before offering it (Priority: P1)

**Goal**: A per-activity availability lookup for a date or a range of up to 31 days, using the same rules as the check.

**Independent Test**: A range covering an open day, a closed weekday, a closure, an out-of-season date and a full date. Each reports the correct `open`, `reason` and `remaining`.

### Tests for User Story 2

- [X] T026 [P] [US2] Write `tests/Feature/ActivityAvailabilityControllerTest.php`. Cover:
  - US2 scenarios 1, 2 and 4;
  - `to` defaults to `from`;
  - a range of 32 days → 422;
  - past days have `past: true`;
  - `has_bookings_while_closed` true after adding a closure over a booked date;
  - another hotel's activity → 404;
  - an employee without `activities.view` → 403.

### Implementation for User Story 2

- [X] T027 [P] [US2] Create `app/Http/Requests/ActivityAvailabilityRequest.php`: `from` `required|date_format:Y-m-d`, `to` `nullable|date_format:Y-m-d|after_or_equal:from`, plus an `after()` check that the inclusive range is at most 31 days.
- [X] T028 [US2] Create `app/Http/Controllers/ActivityAvailabilityController.php` (an invokable or `show` action). It does `$this->authorize('view', $activity)`, then `ActivityAvailabilityService::range`, and returns `apiResponse('Activity availability fetched successfully.', 200, ['activity_id', 'daily_capacity', 'duration_days', 'days' => [...DayAvailability::toArray()]])`.
- [X] T029 [US2] Register `Route::get('/activity/{activity}/availability', ActivityAvailabilityController::class)` in `routes/api.php`, inside the same tenant group as `/activity`.
- [X] T030 [US2] Update `app/Ai/Tools/GetActivitiesTool.php` (R11).
  - Add optional schema args `date`, `from` and `to` (Y-m-d; range ≤ 14 days).
  - When given, add `availability: [{date, open, reason, windows, remaining?}]` per activity, leaving out `remaining` when the capacity is null.
  - An invalid input returns an error sentence instead of throwing.
  - Without args, the output is byte-for-byte unchanged.
  - Update `description()` to tell the model to pass a date and check availability before offering an activity.
- [X] T031 [US2] Add a case to `tests/Feature/ActivityAvailabilityControllerTest.php` (or a `GetActivitiesTool` section in `ConciergeBookingToolsTest`, T036) checking that the tool's availability equals the endpoint's for the same dates (US2-3). Run `php artisan test --filter=ActivityAvailability`.

**Checkpoint**: The calendar and the Concierge can read live availability.

---

## Phase 5: User Story 3 - The Concierge books only what is available (Priority: P1)

**Goal**: `CreateBookingTool` goes through the same check, stays inside the guest's reservation window, never overrides, offers alternatives and is idempotent.

**Independent Test**: The tool against a full date, a closed date and an open date. Only the open date produces a booking, and the others return a reason plus up to 3 dates.

### Tests for User Story 3

- [X] T032 [P] [US3] Write `tests/Feature/ConciergeBookingToolsTest.php` (a CreateBookingTool section). Cover:
  - US3 scenarios 1–6:
    - an open date → pending booking linked to the reservation, and to the stay when in-house;
    - full → no booking, with the reason and ≤ 3 alternatives within 14 days and ≤ departure;
    - a date after departure → `outside_reservation`;
    - the same call twice within 10 minutes → one booking and the same reference;
    - another hotel's activity → refused;
    - no reservation → refused.
  - A `capacity_override`-like input has no effect.
  - The audit actor is the AI agent (`EventLogger::asAiAgent`).

### Implementation for User Story 3

- [X] T033 [US3] Add idempotency to `app/Services/BookingService.php::create` (R10). Inside the activity lock, when `EventLogger::currentActorKind() === ActorKind::AI_AGENT`, return an existing booking with the same `guest_id`, `activity_id`, `scheduled_date`, `scheduled_time` and `pax`, status in (pending, confirmed), `created_at >= now() - 10 minutes`, instead of inserting.
- [X] T034 [US3] Rewrite `handle()` in `app/Ai/Tools/CreateBookingTool.php` (R10):
  - Refuse when `$this->reservation` is null.
  - Resolve the hotel-local date from `scheduled_for`.
  - Refuse with an `outside_reservation` sentence when the date is before hotel today or after `reservation.departure_date`.
  - Pass `reservation_id` and `stay_id` (the in-house stay of the reservation for this guest, via the existing stay relation) to `BookingService::create` with no override.
  - Catch `ActivityUnavailableException` and return its message plus "Nearest available dates: …" from `nearestOpenDates(activity, max(today, date), pax, 3, min(today+14, departure))`, or "No other dates are available during your stay."
  - Keep the recommendation, origin, channel and charge_model behaviour.
  - Update `description()` and the `scheduled_for` schema text: it is the hotel-local time, and the activities tool should be checked first.
- [X] T035 [US3] Update the instructions in `app/Ai/Agents/GuestConciergeAgent.php`. Before offering or booking an activity, check its availability for the date with the activities tool. Never promise a place the tool did not confirm. When a booking is refused, offer the alternatives it returned.
- [X] T036 [US3] Run `php artisan test --filter=ConciergeBookingTools` and `--filter=GuestConcierge` and fix any regressions.

**Checkpoint**: MVP gate step 7 (the guest asks → live availability → books) works.

---

## Phase 6: User Story 4 - Staff confirm, edit and cancel bookings (Priority: P1)

**Goal**: `PATCH /booking/{id}` edits the date, time, party size, activity and notes, re-checking availability only when it matters. Terminal bookings can't be edited.

**Independent Test**: Confirm a pending booking. Raise the party size within capacity (its own load excluded) and beyond it. Move it to a closed day. Make a notes-only edit. Cancel it, and check that the places are freed.

### Tests for User Story 4

- [X] T037 [P] [US4] Write `tests/Feature/BookingUpdateTest.php`. Cover:
  - US4 scenarios 1–8:
    - 2 → 3 people with 1 place left → 200, remaining 0;
    - 2 → 4 people → 422 and the booking unchanged;
    - a move to a closed day → 422;
    - a notes-only edit on a now-full or closed date → 200 (no check);
    - cancelling frees the places in the availability lookup;
    - editing a cancelled, realised or no_show booking → 422;
    - the audit records the changed fields and the actor.
  - `guest_id`, `recommendation_id`, `origin`, `reference`, `status` and `charge_model` in the body are ignored or rejected.
  - Changing `activity_id` checks the new activity.
  - Employee with `bookings.update` → 200; employee whose role lacks it → 403.

### Implementation for User Story 4

- [X] T038 [P] [US4] Create `app/Http/Requests/UpdateBookingRequest.php`:
  - `activity_id` (`sometimes|nullable|string|exists:activities,id`);
  - `scheduled_for` (`sometimes|nullable|date`);
  - `scheduled_date` (`sometimes|nullable|date_format:Y-m-d`);
  - `pax` (`sometimes|integer|min:1`);
  - `notes` (`sometimes|nullable|string|max:2000`);
  - `item_name` (`sometimes|string|max:255`);
  - `reservation_id` / `stay_id` (`sometimes|nullable|string|exists:…`);
  - `capacity_override` (`nullable|boolean`);
  - immutable fields are prohibited: `guest_id`, `recommendation_id`, `origin`, `reference`, `status`, `charge_model`, `created_by_user_id` → `prohibited`.
- [X] T039 [US4] Add `update(Booking $booking, array $data, Hotel $hotel): Booking` to `app/Services/BookingService.php` (R8):
  - Throw a `RuntimeException` unless `$booking->status->isEditable()`.
  - Allow `item_name` only when `activity_id` is null.
  - In a `DB::transaction`, when the activity, date, time or pax changes: lock the old and new activity ids (`ActivityAvailabilityService::lock`, id order), `normaliseSchedule`, and `assertBookable(..., ignoreBookingId: $booking->id, override)`.
  - Save, and record `capacity_overridden` when it was used.
- [X] T040 [US4] In `app/Policies/BookingPolicy.php`, change `update()` from `return false` to `return $this->allows($user, Permission::BOOKINGS_UPDATE, $booking);` and update the comment. Delete, restore and forceDelete stay `false`.
- [X] T041 [US4] Add `update(UpdateBookingRequest $request, Booking $booking)` to `app/Http/Controllers/BookingController.php`:
  - `authorize('update', $booking)`;
  - run `invalidRelation` for the activity, stay and reservation;
  - check the stay and reservation are consistent;
  - strip `capacity_override` until US7;
  - call `BookingService::update`;
  - map a `RuntimeException` to 422;
  - return `apiResponse('Booking updated successfully.', 200, BookingResource::make(...))`.
  - Register `Route::patch('/booking/{booking}', [BookingController::class, 'update'])` in `routes/api.php`.
- [X] T042 [US4] Update the class docblock in `BookingController` ("There is no update…") to describe the edit rules. Run `php artisan test --filter="BookingUpdate|BookingController"`.

**Checkpoint**: Staff can keep bookings accurate without cancelling and re-creating them.

---

## Phase 7: User Story 5 - A guest asks to cancel and staff decide (Priority: P1)

**Goal**: The Concierge creates a cancellation request (a task with no team, one open request per booking), each hotel admin gets one email, and staff approve or decline. Any cancellation closes the open request.

**Independent Test**: Through the tool, request cancellation of the guest's own booking. A request task exists, the booking is still active, and each admin got one email. Approve: the booking is cancelled and the request closed. Decline: the booking is untouched.

### Tests for User Story 5

- [X] T043 [P] [US5] Write `tests/Feature/BookingCancellationRequestTest.php` with `Notification::fake()`. Cover:
  - US5 scenarios 1–8;
  - a repeat request → no second task and no second email;
  - another guest's booking → refused;
  - a cancelled, realised or no_show booking → refused;
  - each admin notified exactly once with `BookingCancellationRequestedNotification`, and **no** `AiTaskCreatedNotification`;
  - cancelling through `POST /booking/{id}/status` closes the request with `resolution=approved` and `resolved_by_user_id`;
  - `approve` with no reason uses the guest's reason;
  - `approve` on a realised booking → 422 and the request stays open;
  - `decline` without a note → 422; with a note → `resolution=declined` and the booking unchanged;
  - no request on the booking → 404;
  - an employee without `bookings.update_status` → 403;
  - a hotel with no admins → the request is still created and a warning is logged;
  - the database rejects a second open request (unique index).

### Implementation for User Story 5

- [X] T044 [P] [US5] Create `app/Notifications/BookingCancellationRequestedNotification.php` (`via` → `['mail']`), following `app/Notifications/AiTaskCreatedNotification.php`. The mail shows the guest's name, booking reference, activity or item name, scheduled date and time, party and reason.
- [X] T045 [US5] Add `bookingCancellationRequested(Task $task): void` to `app/Services/CreationNotificationService.php`. It sends to `$this->admins($task->hotel_id)` and logs a warning when that is empty. Its docblock says it replaces `taskCreated(..., createdByAi: true)` for this signal, so admins get one email, not two.
- [X] T046 [US5] Create `app/Services/BookingCancellationService.php` (R12, R13). Every query names the hotel.
  - `request(Booking, Guest, ?string $reason): array{task: Task, created: bool}`:
    - refuse unless `booking.guest_id === guest.id` and the status is editable;
    - in `DB::transaction`, return the open request if one exists;
    - otherwise create a Task with `hotel_id`, `guest_id`, `reservation_id` and `stay_id` from the booking, `booking_id`, title `Cancellation request: {item_name} on {scheduled_date} ({reference})`, description = reason, `created_by = CreatedBy::GUEST`, status pending, no team, user or category, and `forceFill(['guest_signal' => GuestSignal::CANCELLATION_REQUEST])`;
    - catch a unique violation and return the existing request;
    - `EventLogger::record($booking, 'cancellation_requested', changes: ['task_id' => …])`;
    - only when created, `DB::afterCommit(fn () => CreationNotificationService::bookingCancellationRequested($task))`.
  - `approve(Booking, ?string $reason): Booking` calls `BookingService::cancel($booking, $reason ?? $open->description ?? 'Cancelled at guest request')`.
  - `decline(Booking, string $note): Task` completes the task with `forceFill(['resolution' => declined, 'resolution_note' => $note, 'resolved_by_user_id' => auth id, 'resolved_at' => now()])` and records `cancellation_declined`.
- [X] T047 [US5] Update `cancel()` in `app/Services/BookingService.php` to run in a `DB::transaction`. After `moveTo`, close the booking's open cancellation request as `status=completed`, `resolution=approved`, `resolved_by_user_id` = the current user id (null for AI or system) and `resolved_at=now()`.
- [X] T048 [US5] Create `app/Ai/Tools/RequestBookingCancellationTool.php` with constructor `(Hotel, Guest, ?Reservation)`.
  - Args: `booking_reference` or `booking_id` (one required), and `reason`.
  - Resolve the booking by `hotel_id` and `guest_id` only.
  - Call `BookingCancellationService::request`.
  - Return the contract sentences: passed to the team / already with the team / refusal.
  - It never changes the booking's status.
- [X] T049 [US5] Register `new RequestBookingCancellationTool($this->hotel, $this->guest, $this->reservation)` in `app/Ai/Agents/GuestConciergeAgent.php`. Add an instruction: you cannot cancel a booking; when a guest asks, create a cancellation request and tell them staff will confirm.
- [X] T050 [P] [US5] Create `app/Http/Requests/DeclineCancellationRequest.php` (`note` `required|string|max:1000`) and `app/Http/Requests/ApproveCancellationRequest.php` (`reason` `nullable|string|max:1000`).
- [X] T051 [US5] Create `app/Http/Controllers/BookingCancellationRequestController.php` with `approve(ApproveCancellationRequest, Booking)` and `decline(DeclineCancellationRequest, Booking)`.
  - Both `authorize('updateStatus', $booking)`.
  - 404 via `apiResponse` when there is no open request.
  - `RuntimeException` → 422.
  - Register `POST /booking/{booking}/cancellation-request/approve` and `/decline` in `routes/api.php`.
- [X] T052 [US5] Run `php artisan test --filter=BookingCancellationRequest` and add the `RequestBookingCancellationTool` cases (SC-004: no tool path cancels) to `tests/Feature/ConciergeBookingToolsTest.php`.

**Checkpoint**: Guests can ask to cancel. Only staff cancel, and admins are emailed.

---

## Phase 8: User Story 6 - Booking list, calendar and cancellation queue (Priority: P2)

**Goal**: The booking list filters by activity, reservation and date range, and has a request flag. The cancellation-request queue lists open requests, oldest first.

**Independent Test**: Seed bookings across activities and dates. Filter by one activity and a week, and check the list and the availability totals match. The queue shows only open requests, oldest first.

### Tests for User Story 6

- [X] T053 [P] [US6] Add list and queue cases to `tests/Feature/BookingControllerTest.php`. Cover:
  - US6 scenarios 1–3: filters `activity_id`, `reservation_id`, `scheduled_from` / `scheduled_to` and `cancellation_requested=1`;
  - order by `scheduled_date`, `scheduled_time`;
  - `cancellation_requested` in the resource;
  - booked load from the availability endpoint equals the sum of `pax` of the listed holding bookings;
  - the queue is ordered oldest first and excludes approved and declined requests;
  - a constant query count for the list (no N+1), via `DB::enableQueryLog`.

### Implementation for User Story 6

- [X] T054 [P] [US6] Create `app/Http/Requests/BookingIndexRequest.php`, extending `App\Http\Requests\Generic\GenericIndexRequest`. Add the rules `activity_id`, `reservation_id` (`nullable|string`), `scheduled_from` / `scheduled_to` (`nullable|date_format:Y-m-d`; `scheduled_to` `after_or_equal:scheduled_from`) and `cancellation_requested` (`nullable|boolean`).
- [X] T055 [US6] Update `BookingController::index` to accept `BookingIndexRequest`.
  - Apply the explicit filters before `GenericQuery::apply`.
  - Add `withExists(['openCancellationRequest as cancellation_requested'])`.
  - Order by `scheduled_date`, then `scheduled_time`, when no `sort` is given.
  - Add `cancellation_requested` (bool) to `app/Http/Resources/BookingResource.php`, using `$this->whenHas` / the attribute.
- [X] T056 [P] [US6] Create `app/Http/Resources/BookingCancellationRequestResource.php` with the task `id`, `status`, `reason` (description), `created_at`, `guest {id, first_name, last_name}` and `booking {id, reference, item_name, status, scheduled_date, scheduled_time, pax, activity {id, name}}`.
- [X] T057 [US6] Add `index(GenericIndexRequest)` to `BookingCancellationRequestController`.
  - `authorize('viewAny', Booking::class)`.
  - Query Tasks where `guest_signal = cancellation_request` and status in (pending, in_progress), with `booking.activity` and `guest`, `orderBy('created_at')`, paginated.
  - Register `GET /booking/cancellation-requests` in `routes/api.php` **before** `/booking/{booking}`.
- [X] T058 [US6] Run `php artisan test --filter=BookingController`.

**Checkpoint**: The frontend slice has all its data.

---

## Phase 9: User Story 7 - Authorized staff override capacity on purpose (Priority: P3)

**Goal**: An explicit `capacity_override` needs `bookings.override_capacity`. It bypasses capacity only, is audited, and is never available to AI.

**Independent Test**: A full date. An employee without the permission sends an override → 403. With the permission → 201 plus a `booking.capacity_overridden` audit. A closed day with an override → still 422.

### Tests for User Story 7

- [X] T059 [P] [US7] Add override cases to `tests/Feature/BookingControllerTest.php` and `tests/Feature/BookingUpdateTest.php`. Cover:
  - US7 scenarios 1–4 on store and update;
  - the audit row `booking.capacity_overridden` with `date`, `capacity` and `booked`;
  - an override on `closed_weekday`, `closure_period` or `out_of_season` → 422;
  - an override on `party_exceeds_capacity` → allowed;
  - `capacity_override: false` with no permission → no 403.

### Implementation for User Story 7

- [X] T060 [US7] Add `overrideCapacity(User $user): bool` → `$this->allows($user, Permission::BOOKINGS_OVERRIDE_CAPACITY)` to `app/Policies/BookingPolicy.php`.
- [X] T061 [US7] In `app/Http/Controllers/BookingController.php`, add a private `capacityOverride(Request): bool`, mirroring `ReservationController::overbookOverride`: when `capacity_override` is true, `authorize('overrideCapacity', Booking::class)`. Use it in `store` and `update` in place of the US1 and US4 stripping, and pass the result to `BookingService`.
- [X] T062 [US7] Run `php artisan test --filter="BookingController|BookingUpdate"`.

**Checkpoint**: Real-world exceptions are possible, gated and audited.

---

## Phase 10: Polish & Cross-Cutting Concerns

- [X] T063 Add these endpoints to the dataset in `tests/Feature/PermissionAuthorizationTest.php`, each allowed with the permission and 403 without:
  - `PATCH /api/booking/{id}` → `bookings.update`;
  - `GET /api/activity/{id}/availability` → `activities.view`;
  - `GET /api/booking/cancellation-requests` → `bookings.view`;
  - `POST /api/booking/{id}/cancellation-request/approve` and `/decline` → `bookings.update_status`;
  - the override → `bookings.override_capacity`.
  Also assert `bookings.update` is in `employeeDefaults()` and `bookings.override_capacity` is not.
- [X] T064 Extend `tests/Feature/TenantIsolationTest.php`. A user of hotel B can't read hotel A's activity availability, the cancellation queue, approve or decline, or PATCH hotel A's bookings, and A's booked load never includes B's bookings (FR-031).
- [X] T065 [P] Update `docs/booking-entity-documentation.md`:
  - the new fields;
  - the `scheduled_for` local-time rule;
  - `PATCH /booking/{id}`;
  - the availability refusal 422 shape and reason codes;
  - `capacity_override`;
  - list filters;
  - the cancellation-request queue, approve and decline;
  - cancel closing the open request.
- [X] T066 [P] Update `docs/activity-api-documentation.md` with `GET /activity/{id}/availability`, and with how `operating_hours`, `daily_capacity` and `duration_days` now gate bookings.
- [X] T067 [P] Update the permission reference in `docs/staff-roles-api-documentation.md`: add `bookings.update` (an employee default, with the reason) and `bookings.override_capacity` (never a default), with their endpoints.
- [X] T068 [P] Create `docs/latest-changes-2026-10-05.md`, summarising the breaking changes (R19):
  - catalogue bookings now refused for closed or full dates (422 `unavailable`);
  - a catalogue booking now requires a date;
  - `scheduled_for` without an offset is hotel-local;
  - existing `scheduled_for` values rewritten for hotels not on UTC;
  - new `bookings.update` default.
- [X] T069 [P] Add a frontend handoff note for `ecosystem-frontend` (R20) at the end of `specs/006-activity-bookings/quickstart.md`: the calendar, the edit dialog with an override prompt on `unavailable.overridable`, and the cancellation queue with approve and decline.
- [X] T070 Run `./vendor/bin/pint`, then the full `php artisan test`. Walk through the manual steps in `specs/006-activity-bookings/quickstart.md` and confirm every quickstart test file is green.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup and **blocks every story**. T003–T005 come before T011 and T012, and T006–T014 come before T015, then T016.
- **US1 (Phase 3)**: Foundational. This is the MVP.
- **US2 (Phase 4)**: Foundational. Can run in parallel with US1.
- **US3 (Phase 5)**: Foundational and T016, so the service path is the same as US1's. T030 (US2) is recommended first so the Concierge can check before offering, but it is not required for the tool to refuse correctly.
- **US4 (Phase 6)**: Foundational and T024 (the resource fields from US1).
- **US5 (Phase 7)**: Foundational. T047 touches `BookingService::cancel`, so run it after T039 (US4) when both are in flight to avoid conflicts in the same file.
- **US6 (Phase 8)**: US5 for the queue (T057 needs the requests and controller from T046 and T051). The list filters alone (T054, T055) need only Foundational.
- **US7 (Phase 9)**: US1 (T023) and US4 (T041), because it replaces their override stripping.
- **Polish (Phase 10)**: all stories.

### Shared-file sequencing (same file, so not [P])

| File | Tasks, in order |
| --- | --- |
| `app/Services/BookingService.php` | T016 → T033 → T039 → T047 |
| `app/Http/Controllers/BookingController.php` | T023 → T041/T042 → T055 → T061 |
| `routes/api.php` | T029 → T041 → T051 → T057 |
| `app/Ai/Agents/GuestConciergeAgent.php` | T035 → T049 |
| `tests/Feature/BookingControllerTest.php` | T020 → T053 → T059 |

### Within each story

The tests are written first and must fail. Then requests and resources, then services, then controllers and routes, then the story's test run.

---

## Parallel Examples

### Foundational

```text
T006 ActivityUnavailableReason   T007 CancellationResolution   T008 GuestSignal case
T009 BookingStatus helpers       T010 Permission cases          T013 DayAvailability
T014 ActivityUnavailableException
then: T017 service test  ‖  T018 backfill test   (after T015/T005)
```

### User Story 1

```text
T020 BookingControllerTest additions  ‖  T021 BookingCapacityConcurrencyTest
```

### User Story 2 (alongside US1)

```text
T026 controller test  ‖  T027 ActivityAvailabilityRequest
```

### User Story 5

```text
T043 BookingCancellationRequestTest  ‖  T044 Notification  ‖  T050 Approve/Decline requests
```

### Polish

```text
T065 booking docs  ‖  T066 activity docs  ‖  T067 staff-roles docs  ‖  T068 latest-changes  ‖  T069 frontend note
```

---

## Implementation Strategy

### MVP first (User Story 1 only)

1. Phases 1 and 2 (Setup, Foundational).
2. Phase 3 (US1): desk bookings can no longer oversell or land on closed dates.
3. **Stop and validate**: `php artisan test --filter="BookingController|BookingCapacityConcurrency|ActivityAvailabilityService"`.

### Incremental delivery

| Step | Adds | Delivers |
| --- | --- | --- |
| 1 | US1 + US2 | Safe desk booking plus the availability lookup (the calendar's data) |
| 2 | US3 | The Concierge books against live availability (MVP gate step 7) |
| 3 | US4 | Staff edit bookings |
| 4 | US5 | Guest cancellation requests plus the admin email (constitution: the Concierge never cancels) |
| 5 | US6 | List filters and the queue for the frontend slice |
| 6 | US7 | Audited capacity override |
| 7 | Polish | Permission and isolation datasets, docs, breaking-change note |

Each step ends with Pint plus the full suite green, per CLAUDE.md.
