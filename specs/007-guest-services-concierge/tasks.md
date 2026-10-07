---

description: "Task list for Guest Services and Concierge on the New Domain (Phase 7)"
---

# Tasks: Guest Services and Concierge on the New Domain

**Input**: Design documents from `specs/007-guest-services-concierge/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: Included. The constitution (VIII, Definition of Done) and CLAUDE.md require
feature tests for every new behavior and a tenant-isolation test for every tenant-scoped
feature. Write each story's tests first and confirm they fail before implementing.

**Organization**: Tasks are grouped by user story, in priority order: P1 stories (US1,
US2, US3, US6), then P2 (US4, US5, US7), then P3 (US8). R-numbers refer to
[research.md](research.md).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: The user story the task belongs to (US1–US8)

## Conventions for every task

- PHP 8.3 / Laravel 13. UUID keys.
- Tests are Pest files with `uses(RefreshDatabase::class)` and the `beforeEach` API-key
  setup from CLAUDE.md. Shared fixtures go in `tests/Pest.php`, prefixed `gs` (for
  example `gsHotel()`). Tests run on real Postgres, parallel-safe.
- Tool code names `hotel_id` and `guest_id` explicitly and uses
  `withoutGlobalScope('hotel')`. The Concierge runs without tenant context.
- Run `./vendor/bin/pint` before committing.

---

## Phase 1: Setup

**Purpose**: Start from a base that already has Phase 6.

- [X] T001 Merge branch `006-activity-bookings` into `main`. Create branch `007-guest-services-concierge` from the result and run `php artisan test --parallel`; it must be green before any change (R1). Then confirm these exist: `app/Services/BookingCancellationService.php`, `app/Ai/Tools/RequestBookingCancellationTool.php`, and the `tasks.resolution` and `tasks.resolution_note` columns.
- [X] T002 [P] Add the shared fixtures to `tests/Pest.php`:
  - `gsHotel(string $timezone = 'UTC'): array` returns `[$admin, $hotel]` with default teams and categories created through `HotelOperationalDefaults`.
  - `gsGuest(Hotel $hotel, array $attributes = []): Guest`, with a phone number and email by default.
  - `gsInHouse(Hotel $hotel, Guest $guest, array $roomNumbers = ['214']): Reservation` builds a checked-in reservation, with one in-house stay per room.
  - `gsUpcoming(Hotel $hotel, Guest $guest): Reservation` builds a confirmed reservation arriving in 3 days.
  - `gsPast(Hotel $hotel, Guest $guest): Reservation` builds a checked-out reservation that departed 30 days ago.
  - `gsInbound(string $phoneDigits, CarbonInterface $at): WhatsAppInboundMessage` builds an inbound row, used to open or close the 24-hour window.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema, enums and model helpers that every story uses.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T003 [P] Add cases to `app/Enums/GuestSignal.php`:
  - `MAINTENANCE_REQUEST = 'maintenance_request'` and `ROOM_CHANGE_REQUEST = 'room_change_request'`;
  - `notifiesOnCompletion(): bool`, true only for `SERVICE_REQUEST`, `MAINTENANCE_REQUEST` and `ROOM_CHANGE_REQUEST`;
  - `isComplaint(): bool`, true for the same three (R2).
  - Update the doc comment per case.
- [X] T004 [P] Create `app/Enums/GuestNoticeStatus.php` (string-backed: `PENDING = 'pending'`, `SENT = 'sent'`, `SKIPPED = 'skipped'`, `FAILED = 'failed'`).
- [X] T005 [P] Create `app/Enums/GuestNoticeChannel.php` (`WHATSAPP = 'whatsapp'`, `EMAIL = 'email'`).
- [X] T006 [P] Create `app/Enums/GuestNoticeReason.php` (`CANCELLED = 'cancelled'`, `NO_CONTACT = 'no_contact'`, `SEND_FAILED = 'send_failed'`).
- [X] T007 [P] Add `IGNORED = 'ignored'` to `app/Enums/InboundMessageStatus.php`, with the doc comment "The sender matched no guest or staff member; no reply is generated." (R12)
- [X] T008 Create migration `database/migrations/2026_10_06_000001_add_guest_request_fields_to_tasks_table.php`, as set out in data-model.md.
  - **Columns** (all nullable): `guest_notice_status` (string), `guest_notice_channel` (string), `guest_notice_reason` (string), `guest_notice_at` (timestampTz).
  - **Checks**:
    - `CHECK (guest_notice_status IN ('pending', 'sent', 'skipped', 'failed'))`
    - `CHECK (guest_notice_channel IN ('whatsapp', 'email'))`
    - `CHECK (guest_notice_reason IN ('cancelled', 'no_contact', 'send_failed'))`
  - **Data step, before the indexes**:
    1. Per `(hotel_id, guest_id)`, keep the newest open escalation. Append the older open escalations' descriptions to it.
    2. Set the older ones to `status = 'completed'` and `guest_notice_status = 'skipped'`.
    3. Set `guest_notice_status = 'skipped'` and `guest_notice_at = now()` on every task already `completed` or `cancelled` whose `guest_signal` is `service_request` or `cancellation_request`.
  - **Indexes**:
    - `CREATE UNIQUE INDEX tasks_one_open_escalation_per_guest ON tasks (hotel_id, guest_id) WHERE guest_signal = 'escalation' AND status IN ('pending', 'in_progress') AND deleted_at IS NULL`
    - `CREATE UNIQUE INDEX tasks_one_open_room_change_per_stay ON tasks (COALESCE(stay_id, reservation_id)) WHERE guest_signal = 'room_change_request' AND status IN ('pending', 'in_progress') AND deleted_at IS NULL`
  - **`down()`**: drops both indexes, the three checks and the four columns.
- [X] T009 Update `app/Models/Task.php`:
  - **Casts**: `guest_notice_status` → `GuestNoticeStatus`, `guest_notice_channel` → `GuestNoticeChannel`, `guest_notice_reason` → `GuestNoticeReason`, `guest_notice_at` → `datetime`. Keep the four columns out of `$fillable`, with a comment that only `SendGuestRequestNoticeJob` and the migration write them.
  - **`eventLoggedAttributes()`**: add the three notice enum columns.
  - **Scope `guestRequests()`**: `guest_signal` in service, maintenance, room change, cancellation or escalation.
  - **Scope `ownedByGuest(Hotel $hotel, Guest $guest)`**: `withoutGlobalScope('hotel')` filtered on `hotel_id` and `guest_id`.
- [X] T010 [P] Add `isActive(): bool` to `app/Models/Reservation.php` (R6). It is true when `status` is `pending`, `confirmed` or `checked_in` **and** `departure_date` is on or after today in the hotel's time zone (`CarbonImmutable::now($this->hotel->timezone)`). Load the hotel without scope if it is not loaded.
- [X] T011 [P] Add to `app/Http/Resources/TaskResource.php` the read-only fields `guest_notice_status`, `guest_notice_channel`, `guest_notice_reason` and `guest_notice_at` (contracts/api-and-messaging.md §1).
- [X] T012 Create `app/Services/GuestRequestService.php`. For now it only holds the constructor (injecting `CreationNotificationService`) and a class docblock: guest requests the Concierge files, every query names the hotel, and the Concierge runs without tenant context. US1, US3 and US5 add the methods.
- [X] T013 Run `php artisan migrate` on the testing database through the suite, then `php artisan test --parallel`; it must still be green.

**Checkpoint**: Foundation ready. User stories can start.

---

## Phase 3: User Story 1 — A guest's request reaches the right team and the guest hears back (Priority: P1) 🎯 MVP

**Goal**: Requests are classified and routed. Completing one sends the guest a WhatsApp
notice inside the window. Cancelling one sends nothing and records why.

**Independent Test**: As an in-house guest, file a housekeeping, a maintenance and an
"other" request. Check each task's category and team. Complete each one and check that
one WhatsApp notice is sent. Cancel another and check that it is recorded as
`skipped / cancelled`.

### Tests for User Story 1 ⚠️ (write first, must fail)

- [X] T014 [P] [US1] Write `tests/Feature/GuestRequestRoutingTest.php`, covering:
  - an extra-towels request goes to the Housekeeping category and team;
  - a maintenance request (via `CreateGuestServiceRequestTool` with `kind = maintenance_request`) gets `guest_signal = maintenance_request`, `task_category_id = hotel.maintenance_task_category_id` and `assigned_to_team_id = hotel.maintenance_team_id`, linked to stay and room 214;
  - a taxi request with no category gets no category and no team;
  - another hotel's category id, or a deleted one, gives no category;
  - a guest in rooms 214 and 215 with no `room_number` gets the "ask which room" result and no task;
  - `add_to_request_id` naming the guest's own open request of the same kind appends the detail and creates nothing;
  - `add_to_request_id` naming another guest's request, or a different kind, is ignored and a new task is created;
  - a cleaning request while one is scheduled returns "already scheduled" (Phase 5 rule kept);
  - room status is never changed by any of these.
- [X] T015 [P] [US1] Write the US1 part of `tests/Feature/GuestRequestNoticeTest.php`. Use `Http::fake()` for `graph.facebook.com` and `gsInbound()` 3 hours ago to open the window. Cover:
  - completing a service request through `PATCH /api/task/{id}` sends exactly one WhatsApp text and sets `guest_notice_status = sent` and `guest_notice_channel = whatsapp`;
  - the text contains the kind label ("maintenance") and hotel name, and never the task title, assignee name, team name or description;
  - a guest cleaning request closed through the housekeeping flow (the inspection / housekeeping status path, not the task API) still sends its notice (H1);
  - while the job sends, `TenantContext` is restricted to the task's hotel (assert inside a faked `WhatsAppMessageService`);
  - an `ar` guest gets the Arabic text;
  - `status = cancelled` sends nothing and records `skipped` / `cancelled`;
  - completing, re-opening and completing again sends one notice in total;
  - completing an escalation, a `booking_follow_up` or a staff-created task sends nothing and leaves the notice fields null;
  - the task API response is `200` with `status = completed` even when the Graph call fails.

### Implementation for User Story 1

- [X] T016 [P] [US1] Create `lang/en/guest_notices.php` with keys `completed`, `cancellation_approved` and `cancellation_declined`, using the exact English texts in contracts/api-and-messaging.md §3 and `:first_name`, `:kind`, `:hotel`, `:item`, `:date`, `:reference` and `:note` placeholders. Add a `kinds` array with the labels `housekeeping`, `service`, `maintenance` and `room_change` ("room change"). Add `email_subject_completed`, `email_subject_cancellation_approved` and `email_subject_cancellation_declined` (`:hotel: …`).
- [X] T017 [P] [US1] Create `lang/ar/guest_notices.php` with the same keys, in Modern Standard Arabic, with the same placeholders.
- [X] T018 [US1] Add `GuestRequestService::appendDetail(Task $open, string $detail, Guest $guest, Hotel $hotel, GuestSignal $kind): ?Task` in `app/Services/GuestRequestService.php` (R14).
  - Under `lockForUpdate`, it re-reads the task by id with `ownedByGuest($hotel, $guest)`, `open()` and the same `guest_signal`.
  - It returns null when there is no match.
  - Otherwise it appends `"\n\n[" . now($hotel->timezone)->format('Y-m-d H:i') . "] " . $detail` to the description, saves, and records the `EventLogger::record($task, 'request_detail_added', changes: ['detail' => $detail])` event.
- [X] T019 [US1] Update `app/Ai/Tools/CreateGuestServiceRequestTool.php`:
  - add the optional `add_to_request_id` schema field;
  - when it is set, call `appendDetail` (kind `SERVICE_REQUEST`) and on success return `Added to your existing request (task id: {id}). Staff will see the update.`;
  - tighten the `kind` description: maintenance problems go to the maintenance tool.
  - Keep the category validation, the one-clean rule and VIP priority.
- [X] T020 [US1] Maintenance requests (*revised: merged into `CreateGuestServiceRequestTool` as `kind = maintenance_request`; the separate `CreateMaintenanceRequestTool` was built, then removed, see R3*), following contracts/concierge-tools.md.
  - **Schema**: `title` (required), `description` (required), `room_number`, `add_to_request_id`.
  - **Routing**: the category is `$hotel->maintenance_task_category_id`, the team `$hotel->maintenance_team_id` (null-safe, never rejects).
  - **Fields**: `guest_signal = MAINTENANCE_REQUEST` (forceFill), `created_by = GUEST`, priority HIGH for VIP guests, else NORMAL. Link the stay and room through the same rules as `stayFor()` / `fallbackRoomId()`; extract them into a shared private trait `app/Ai/Tools/Concerns/ResolvesGuestStay.php` used by both tools.
  - **After saving**: `markBlockingRequest()`, then `CreationNotificationService::taskCreated($task, createdByAi: true)`.
  - **Results**: `Maintenance request filed for room {n} (task id: …). The maintenance team has it.`, or the ask-which-room text.
- [X] T021 [US1] Create `app/Jobs/SendGuestRequestNoticeJob.php`, a `ShouldQueue` job taking the `string $taskId` (R7–R11).
  - **Settings**: each channel retried 3 times in the job (`retry([1000, 3000], ...)`), every failure caught and recorded, never thrown (implemented this way so a synchronous queue cannot fail the staff request).
  - **Claim**: `Task::withoutGlobalScope('hotel')->whereKey($id)->whereNull('guest_notice_status')->update(['guest_notice_status' => 'pending'])`; return if it changed 0 rows. On retry, also continue when the status is already `pending`.
  - **Load**: the task by id with `withoutGlobalScope('hotel')` (the only unscoped lookup). Run everything after that inside `TenantContext::runForHotel($task->hotel_id, fn () => ...)`, so the job sets tenant context explicitly as the constitution requires (R8).
  - **Message**:
    - `cancelled` + notifying kind → `skipped` / `cancelled`;
    - completed notifying kind → `completed`;
    - cancellation request → `cancellation_approved` or `cancellation_declined` from `resolution`.
  - **Language**: `ar` if `preferred_language === 'ar'`, else `en`.
  - **Kind label**: `housekeeping` when a `service_request`'s team is `hotel.housekeeping_team_id`, `service` for other service requests, `maintenance`, or `room_change`. Never the task title (FR-008, R11).
  - **Latency**: log at info level, with the task id, the seconds between `completed_at` (or `resolved_at`) and the send, so SC-002 can be checked.
  - **Send**: the window is open when the newest `whatsapp_inbound_messages` row for `PhoneNumber::digits($guest->phone_number)` is under 23 h 50 min old (R9). When it is open, send through `WhatsAppMessageService::send`.
  - **Finish**: set `sent` + `whatsapp`, and `guest_notice_at = now()`, with `forceFill`. Record `EventLogger::record($task, 'guest_notified', changes: [...])`.
  - **Not yet handled**: the email path and the no-phone/no-email path come in US2. For now they record `skipped` / `no_contact`.
  - **Never** change `status`.
- [X] T022 [US1] Add `queueGuestNotice(): void` to `app/Models/Task.php`, and an `updated` listener in `booted()` that calls it (R7). It calls `SendGuestRequestNoticeJob::dispatch($this->id)->afterCommit()` when all of these hold:
  - `wasChanged('status')`;
  - the new status is `completed` or `cancelled`;
  - `guest_id !== null`;
  - `guest_notice_status === null`;
  - `guest_signal?->notifiesOnCompletion()` is true, or the signal is `CANCELLATION_REQUEST`.

  Add a comment explaining why this is a model hook, unlike `CreationNotificationService`.
- [X] T023 [US1] (*Superseded by the merge: no separate tool to register.*) Register the maintenance kind in `app/Ai/Agents/GuestConciergeAgent.php::tools()`, and add its one-line bullet to `instructions()` ("maintenance problems go to the maintenance tool"). If T032 already exists, add the tool to its allowlist in the same commit.

**Checkpoint**: US1 works on its own. T014 and T015 pass.

---

## Phase 4: User Story 2 — The guest is notified even after the messaging window closed (Priority: P1)

**Goal**: Outside the 24-hour window, or without a phone number, the notice goes by email.
With no email it is recorded as skipped. A WhatsApp failure falls back to email.
Cancellation-request decisions notify the guest.

**Independent Test**: Complete three requests, one inside the window, one outside with an
email, and one outside without an email. Check one WhatsApp message, one email, and one
`skipped / no_contact`. Approve and decline a cancellation request, and check the outcome
notices.

### Tests for User Story 2 ⚠️

- [X] T024 [P] [US2] Add the US2 cases to `tests/Feature/GuestRequestNoticeTest.php`, with `Notification::fake()` and `Http::fake()`. Cover:
  - the last inbound message 30 h ago with an email address → an email via `GuestRequestNoticeNotification`, no Graph call, `sent` / `email`;
  - 30 h and no email → nothing sent, `skipped` / `no_contact`;
  - the window open but no phone → email;
  - an inbound message 23 h 55 min old counts as closed (margin);
  - an inbound message from the same digits to **another hotel** keeps the window open;
  - the Graph API returning 500 three times → email fallback, `sent` / `email`;
  - the Graph API failing with no email → `failed` / `send_failed`;
  - a mail failure after 3 tries → `failed` / `send_failed`;
  - the task's `status` is untouched in every case.
- [X] T025 [P] [US2] Write `tests/Feature/CancellationOutcomeNoticeTest.php`. Cover:
  - approving a guest's request through `POST /api/booking/{id}/cancellation-request/approve` → a `cancellation_approved` text with the item, date and reference;
  - declining with `note` "The boat is fully paid by your tour operator" → `cancellation_declined` with the note;
  - cancelling the booking directly through `POST /api/booking/{id}/status` while a request is open → the approved notice;
  - each case follows the window rule (one inside, one outside with email);
  - an approved request with no guest phone or email → `skipped` / `no_contact`.

### Implementation for User Story 2

- [X] T026 [P] [US2] Create `app/Notifications/GuestRequestNoticeNotification.php`, sent by mail only and not `ShouldQueue` (the job is already queued). Its constructor takes `Task $task`, `string $messageKey`, `string $locale` and `array $replacements`.
  - `toMail()` uses subject `__("guest_notices.email_subject_{$key}", …, $locale)`, sender name = hotel name, and a body of the same text as the WhatsApp message.
  - No staff names or descriptions.
- [X] T027 [US2] Extend `app/Jobs/SendGuestRequestNoticeJob.php` with the full channel decision (R10):
  - window closed or no phone → email when `$guest->email` is set, via `Notification::route('mail', $guest->email)->notify(...)`, then `sent` / `email`;
  - no email → `skipped` / `no_contact`;
  - catch a WhatsApp `RuntimeException`, rethrow while `attempts() < 3`, and on the last attempt fall back to email, or record `failed` / `send_failed`;
  - add `failed(Throwable)` recording `failed` / `send_failed`;
  - cancellation replacements: `:item` = `booking.item_name`, `:date` = `booking.scheduled_date`, `:reference` = `booking.reference`, `:note` = `resolution_note` (empty string if null).
- [X] T028 [US2] Audit every path that closes a task (R7), so the Task `updated` hook fires for guest requests:
  - `app/Http/Controllers/TaskController.php` (update)
  - `app/Http/Controllers/TaskInspectionController.php`
  - `app/Services/HousekeepingService.php`, including any job it runs
  - `app/Services/MaintenanceService.php`
  - `app/Services/BookingCancellationService.php` (decline)
  - `app/Services/BookingService.php` (`closeCancellationRequest`)

  Each must close through `->save()` on the model. Where a path closes tasks with a query-builder `update()`, either change it to load and save, or call `$task->queueGuestNotice()` for each closed guest request. Add a comment pointing to R7 at each changed place. The housekeeping-path case in T015 proves it.

**Checkpoint**: US1 and US2 both work. Notices are complete for every channel.

---

## Phase 5: User Story 3 — The guest asks for a human (Priority: P1)

**Goal**: One open escalation per guest. A repeat ask appends to it. Admins are told once,
and pitching is blocked.

**Independent Test**: Escalate twice, including concurrently. Check that there is one
task with both reasons, one admin email and no pitch in either reply.

### Tests for User Story 3 ⚠️

- [X] T029 [P] [US3] Write `tests/Feature/GuestEscalationTest.php`. Cover:
  - the first escalation creates a HIGH-priority task with `guest_signal = escalation` and the reason, linked to the active reservation, with `AiTaskCreatedNotification` sent to the admins (`Notification::fake()`);
  - `PitchTurn::markBlockingRequest()` is set;
  - the second escalation creates no task, appends the reason with a timestamp, sends no email and records `escalation_repeated`;
  - two escalations racing through a second DB connection (the Phase 6 concurrency pattern, `DatabaseTruncation`) still give one task;
  - a guest with only a past reservation can escalate;
  - completing the escalation sends no notice;
  - the escalation gate blocks pitching for the rest of the stay (existing behavior, regression).

### Implementation for User Story 3

- [X] T030 [US3] Add `GuestRequestService::escalate(Guest $guest, Hotel $hotel, ?Reservation $reservation, string $reason): array{task: Task, created: bool}` in `app/Services/GuestRequestService.php` (R5).
  - Inside a transaction, look for an open escalation with `ownedByGuest` + `guest_signal = ESCALATION` + `open()` + `lockForUpdate`.
  - **If one is open**: append `"\n\n[Y-m-d H:i] {reason}"`, record `escalation_repeated`, and return `created = false`.
  - **Otherwise**: create the task (HIGH priority, `created_by = AI`, title `Guest needs human assistance`, `reservation_id` only when `$reservation?->isActive()`), `forceFill` `guest_signal`, save, and dispatch `taskCreated($task, createdByAi: true)` after commit.
  - Catch `QueryException` code `23505` and return the open one with `created = false`.
- [X] T031 [US3] Rewrite `app/Ai/Tools/EscalateToHumanTool.php::handle()` to call `GuestRequestService::escalate()`. Always call `markBlockingRequest()`. Return either `A staff member has been notified and will follow up with the guest directly.` or `Staff already have this guest's request for a person; the new detail was added to it.` Keep the schema unchanged.

**Checkpoint**: US3 works on its own.

---

## Phase 6: User Story 6 — Guest limits hold even if the AI tries to break them (Priority: P1)

**Goal**: The guest tool set cannot cancel, change rooms or change statuses, and never
touches another guest's records. Every write is audited as AI.

**Independent Test**: The architecture test passes. Calling every guest tool with another
guest's ids changes nothing.

### Tests for User Story 6 ⚠️

- [X] T032 [P] [US6] Create `tests/Arch/ConciergeToolsArchTest.php`. Add a `tests/Arch` directory to the `<testsuites>` in `phpunit.xml` if it is not covered.
  - Assert that `GuestConciergeAgent::tools()` class names equal exactly an allowlist array kept at the top of the test file. Start it from the tools registered when this task runs; each later registration task (T040, T044) adds its tool in the same commit. When every story is done, it must match contracts/concierge-tools.md (checked again in T057).
  - Use `arch()->expect([...allowlisted tools...])->not->toUse([...])` with `App\Services\StayLifecycleService`, `App\Http\Controllers\StayCheckInController`, `App\Http\Controllers\StayCheckOutController`, `App\Ai\Tools\CheckInTool` and `App\Ai\Tools\CheckOutTool`.
  - Also assert that `RequestBookingCancellationTool` does not call `BookingService` directly. If `arch()` cannot express a rule, assert it with reflection on constructor and use statements, and add a comment saying so.
- [X] T033 [P] [US6] Write `tests/Feature/ConciergeGuestRestrictionsTest.php`. Cover:
  - service, maintenance and room-change tools given guest B's `add_to_request_id`, or a `room_number` B occupies, create nothing for B and never change B's records;
  - `CreateBookingTool` for a closed or full activity is refused with the Phase 6 reason and alternatives;
  - every task, booking or request created inside `EventLogger::asAiAgent` has an `event_log` row with actor kind `ai_agent`;
  - no tool output for guest A contains guest B's name, booking reference or request title.

### Implementation for User Story 6

- [X] T034 [US6] Review every tool in the allowlist (`app/Ai/Tools/*.php` used by `GuestConciergeAgent`). Every query must filter on the constructor's `hotel_id` and `guest_id`, or on the resolved reservation, with `withoutGlobalScope('hotel')`. No schema field may accept a guest, hotel, stay or room id. Fix any gap, and list the reviewed tools in the PR description.
- [X] T035 [US6] Rewrite the tool section of `app/Ai/Agents/GuestConciergeAgent.php::instructions()` for the tools registered at this point (R19). Later registration tasks (T040, T044) add their own bullet. The rewrite covers:
  - one bullet per tool in the new set;
  - check open requests (`GetOwnRequestsTool`) before filing;
  - maintenance problems use the maintenance tool;
  - a room change is a request, never a promise;
  - escalate when the guest asks, when policy requires it, or after failing to help;
  - when a tool says a current or upcoming reservation is needed, explain that and offer staff contact.

  Remove the old single task-tool wording that mixes maintenance into service requests.

**Checkpoint**: Restrictions are enforced in code and pinned by tests.

---

## Phase 7: User Story 4 — The guest asks about their own stay, bookings and requests (Priority: P2)

**Goal**: The guest can read their own reservation (with per-room stay status), their
bookings with prices, and their open requests.

**Independent Test**: A guest with a two-room reservation (one room in-house), two
bookings and one open request gets correct answers from the three tools. Another guest's
reference returns not found.

### Tests for User Story 4 ⚠️

- [X] T036 [P] [US4] Write `tests/Feature/ConciergeReadToolsTest.php`. Cover:
  - `GetOwnReservationTool` JSON has `is_active = true` and per-room `room_number` and `stay_status` (`in_house` for 214, `expected` for the unassigned line);
  - a past guest gets `is_active = false`;
  - `GetOwnBookingsTool` lists upcoming bookings soonest first, each with `reference`, `activity`, `date`, `time`, `party_size`, `status`, `price`, `currency` and `cancellation_requested`, and caps past bookings at 10;
  - a guest with no bookings gets the "no activity bookings" text;
  - `GetOwnRequestsTool` lists open requests only, with `status` `received` / `in progress`, and its JSON has no keys `assigned_to_team_id`, `assigned_to_user_id`, `description`, `priority` or `resolution_note`;
  - another guest's open request is not listed;
  - each tool makes a fixed number of queries, at most 6 (`DB::enableQueryLog`).

### Implementation for User Story 4

- [X] T037 [P] [US4] Update `app/Ai/Tools/GetOwnReservationTool.php`. Add `is_active` (`$reservation->isActive()`) and per room line `stay_status` from the line's stay (eager-load `reservationRooms.stay` without scope). Do this in the tool or a new `Reservation::roomsForGuest()`; do not change `roomsForAi()`, which other tools use.
- [X] T038 [P] [US4] Create `app/Ai/Tools/GetOwnBookingsTool.php` (R13).
  - Constructor: `Hotel`, `Guest`.
  - Query: `Booking::withoutGlobalScope('hotel')->where('hotel_id')->where('guest_id')`, with upcoming bookings (`scheduled_date >= hotel today`) in ascending order and past ones in descending order with a limit of 10.
  - `cancellation_requested` comes from the Phase 6 open-request relation, with no N+1.
  - `price` = `expected_value`.
  - Output follows contracts/concierge-tools.md.
- [X] T039 [P] [US4] Create `app/Ai/Tools/GetOwnRequestsTool.php` (R13).
  - Constructor: `Hotel`, `Guest`.
  - Query: `Task::ownedByGuest()->guestRequests()->open()->with('room')`.
  - Output keys only: `id`, `kind` (the `guest_signal` value), `title`, `status` (mapped), `created_at` (hotel time zone, ISO-8601) and `room_number`.
- [X] T040 [US4] Register `GetOwnBookingsTool` and `GetOwnRequestsTool` in `app/Ai/Agents/GuestConciergeAgent.php::tools()`. Add both to the T032 allowlist and a one-line bullet each to `instructions()` (check open requests before filing a new one).

**Checkpoint**: The read tools work on their own.

---

## Phase 8: User Story 5 — The guest asks to change rooms (Priority: P2)

**Goal**: A room-change request becomes a hotel-wide task with an admin email, at most one
open per stay or reservation. Room assignment never changes.

**Independent Test**: An in-house guest asks twice. Check one task with no team, one admin
email and room assignment unchanged.

### Tests for User Story 5 ⚠️

- [X] T041 [P] [US5] Write `tests/Feature/RoomChangeRequestTest.php`. Cover:
  - in-house → a task with `guest_signal = room_change_request`, `stay_id`, `room_id`, `assigned_to_team_id = null`, `task_category_id = null`, and a description containing the reason and preference; `AiTaskCreatedNotification` goes to the admins; `reservation_rooms.room_id` and `stays.room_id` are unchanged;
  - asking again → no new task, with the "already with staff" result;
  - an upcoming reservation → a task on `reservation_id` with null `stay_id`;
  - a past-only guest → refused with the shared text, and no task;
  - a guest in two rooms naming 215 → linked to 215's stay;
  - completing it sends the `completed` notice (relies on US1);
  - two concurrent asks → one task (the `23505` path).

### Implementation for User Story 5

- [X] T042 [US5] Add `GuestRequestService::requestRoomChange(Guest $guest, Hotel $hotel, Reservation $reservation, ?Stay $stay, string $reason, ?string $preference): array{task: Task, created: bool}` in `app/Services/GuestRequestService.php` (R4).
  - Inside a transaction, look for an open request keyed by stay (or by reservation when there is no stay); if one exists, return it with `created = false`.
  - Otherwise create the task:
    - title `Room change request` + ` (room {n})` when there is a room;
    - description `"{reason}"` + `"\nPreference: {preference}"`;
    - `created_by = GUEST`, no team, no category;
    - `forceFill` `guest_signal = ROOM_CHANGE_REQUEST`.
  - Record `room_change_requested`, then call `taskCreated($task, createdByAi: true)` after commit.
  - Catch `23505` and return the open one.
- [X] T043 [US5] Create `app/Ai/Tools/RequestRoomChangeTool.php`, following contracts/concierge-tools.md.
  - **Schema**: `reason` (required), `preference`, `room_number`.
  - **Steps**: check `isActive()` and return the shared refusal if not; resolve the stay through the `ResolvesGuestStay` trait (ask which room when ambiguous); call the service; `markBlockingRequest()`.
  - **Results**: the two texts in the contract.
- [X] T044 [US5] Register `RequestRoomChangeTool` in `app/Ai/Agents/GuestConciergeAgent.php::tools()`. Add it to the T032 allowlist and a bullet to `instructions()` (a room change is a request, never a promise).

**Checkpoint**: Room changes work on their own.

---

## Phase 9: User Story 7 — An unknown number gets no reply (Priority: P2)

**Goal**: Unknown senders are ignored before any job, AI call or send. Throttling,
de-duplication and pairing still work.

**Independent Test**: Message from an unknown number. Check that no job is queued, there
is no Graph call, and the inbound row has `status = ignored` and `hotel_id = null`.

### Tests for User Story 7 ⚠️

- [X] T045 [P] [US7] Write `tests/Feature/WhatsAppUnknownSenderTest.php`, or extend the existing WhatsApp webhook test file if one covers `handleInboundMessage`. Sign the payloads as `whatsapp.signature` expects and use `Queue::fake()` and `Http::fake()`. Cover:
  - an unknown number → `ProcessInboundWhatsAppMessageJob` not pushed, `SendWhatsAppMessageJob` not pushed, the inbound `status = ignored`, `hotel_id` null, and no rows in `tasks` or `bookings`;
  - the same wamid redelivered → stops at de-duplication;
  - a 31st message within the limit → `throttled`;
  - a pairing token from an unknown number still gets the pairing reply;
  - a known guest and a paired admin still get a job;
  - a number with guest records in two hotels (in-house at hotel B, past stay at hotel A) is routed to hotel B (D12 order, FR-036 regression);
  - the inbound row of an ignored sender stores no message text (R12).
  - Remove or update any existing test that asserts `UNKNOWN_SENDER_REPLY`.

### Implementation for User Story 7

- [X] T046 [US7] In `app/Http/Controllers/WhatsAppController.php::handleInboundMessage()`, after `identify()`, add: when `$recognition->type === SenderType::UNKNOWN`, `$inbound->update(['status' => InboundMessageStatus::IGNORED])` and `return`. Keep the order: de-duplication, throttle, pairing, recognition. Add a comment citing spec Q3.
- [X] T047 [US7] In `app/Jobs/ProcessInboundWhatsAppMessageJob.php`, remove the `UNKNOWN_SENDER_REPLY` constant and the `SenderType::UNKNOWN` branch in `replyFor()`. Grep for any other references (`grep -rn UNKNOWN_SENDER_REPLY app tests`) and update them.

**Checkpoint**: Unknown senders are silent.

---

## Phase 10: User Story 8 — Guests with only past stays get limited help (Priority: P3)

**Goal**: A past guest can read information and escalate, but cannot file service,
maintenance or room-change requests or bookings.

**Independent Test**: A guest with only a past reservation tries each action. Check that
only escalation succeeds.

### Tests for User Story 8 ⚠️

- [X] T048 [P] [US8] Add a `describe('past-only guest')` block to `tests/Feature/ConciergeGuestRestrictionsTest.php`. Cover:
  - `CreateGuestServiceRequestTool` (`kind = service_request` and `maintenance_request`) and `RequestRoomChangeTool` each return the shared "needs a current or upcoming reservation" text and create no task;
  - `CreateBookingTool` is refused (Phase 6 window);
  - `EscalateToHumanTool` creates an escalation with `reservation_id = null`;
  - `GetActivitiesTool` and `KnowledgeSearchTool` still answer.

### Implementation for User Story 8

- [X] T049 [US8] Add the active-reservation guard (R6) at the start of `handle()` in `app/Ai/Tools/CreateGuestServiceRequestTool.php` (only when `kind = service_request`), `app/Ai/Tools/CreateMaintenanceRequestTool.php` and `app/Ai/Tools/RequestRoomChangeTool.php`.
  - It returns the exact text: `This needs a current or upcoming reservation at {hotel}. The guest has none, so nothing was filed. Offer to connect them with staff instead.`
  - Put the guard in one place, a `requiresActiveReservation(): ?string` method on the `ResolvesGuestStay` trait.

**Checkpoint**: All user stories work on their own.

---

## Phase 11: Polish & Cross-Cutting Concerns

- [X] T050 [P] Update `app/Services/Pitching/PitchEligibilityService.php::serviceRequestGate()` to query `whereIn('guest_signal', [...GuestSignal cases where isComplaint()])` instead of only `SERVICE_REQUEST` (R17). Extend `tests/Feature/PitchEligibilityTest.php`: an open maintenance request, or an open room-change request, blocks with `PitchGate::OPEN_SERVICE_REQUEST`.
- [X] T051 [P] Extend `tests/Feature/TenantIsolationTest.php`. Hotel B's admin:
  - cannot `GET /api/task/{id}` a hotel A room-change request (`404` or `403`, matching the existing pattern);
  - gets no hotel A rows from `GET /api/task?filter[guest_signal]=maintenance_request` or `filter[guest_notice_status]=failed`;
  - cannot complete hotel A's request, so no notice is sent.
- [X] T052 [P] Update `docs/task-management-api-documentation.md`: the new `guest_signal` values, the four `guest_notice_*` fields and their values, the filter examples, and the completion and cancellation side effects (contracts/api-and-messaging.md §1).
- [X] T053 [P] Update `docs/whatsapp-device-api-documentation.md` to say that unknown senders are ignored (status `ignored`, no reply), and give the inbound handling order (contracts/api-and-messaging.md §4).
- [X] T054 [P] Update `docs/booking-entity-documentation.md`: approving, declining or a staff cancel with an open request now notifies the guest (WhatsApp inside the window, email outside), and the decline `note` is shown to the guest.
- [X] T055 [P] Create `docs/latest-changes-2026-10-06.md` (or the implementation date). It covers:
  - **Breaking**: unknown WhatsApp senders no longer get a reply;
  - `guest_signal` gains `maintenance_request` and `room_change_request`;
  - `TaskResource` gains the `guest_notice_*` fields;
  - guests are notified on completion and on cancellation decisions;
  - no new permissions.
- [X] T056 [P] Create `specs/007-guest-services-concierge/frontend-changes.md`. All of it is optional for `ecosystem-frontend`:
  - task detail shows the notice status, channel and reason;
  - the task list can filter by `guest_signal` (new labels "Maintenance request" and "Room change request") and by `guest_notice_status = failed`;
  - no new screens.
- [ ] T057 Check that the T032 allowlist matches contracts/concierge-tools.md exactly. Run `./vendor/bin/pint` and `php artisan test --parallel` until both are green. Then walk through manual steps 1–6 in [quickstart.md](quickstart.md) on a local stack and tick its "Done when" list.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: T001 first; it blocks everything.
- **Foundational (Phase 2)**: depends on T001 and blocks all stories. T003–T007, T010 and T011 can run in parallel. T008 comes before T009, and T013 runs last.
- **US1 (Phase 3)**: after Phase 2. MVP.
- **US2 (Phase 4)**: after US1, because it extends the notice job from T021.
- **US3 (Phase 5)**: after Phase 2. Independent of US1 and US2.
- **US6 (Phase 6)**: after US1. T032 and T035 cover the tools registered so far; T040 and T044 extend the allowlist and instructions as their tools land, and T057 checks the final set. T033 can start after US1.
- **US4 (Phase 7)**: after Phase 2. Independent.
- **US5 (Phase 8)**: after Phase 2 and the `ResolvesGuestStay` trait (T020). Its completion-notice test case relies on US1.
- **US7 (Phase 9)**: after Phase 2 (T007). Fully independent of every other story.
- **US8 (Phase 10)**: after US1 and US5, because it guards their tools.
- **Polish (Phase 11)**: after the stories it touches. T050 after T003.

### Story completion graph

```text
T001 → Phase 2 ─┬─► US1 ─► US2
                ├─► US3
                ├─► US4
                ├─► US7
                └─► US5 (needs T020 trait) ─┐
                                            ├─► US8 ─► US6 (final arch/instructions) ─► Polish
                US1 ────────────────────────┘
```

### Within each story

- Tests first; confirm they fail.
- Enums and models before services, services before tools, and tools before agent
  registration.
- Commit after each task or logical group.

---

## Parallel Examples

**Phase 2**: T003, T004, T005, T006, T007, T010 and T011 touch different files and can
run together. T008 and then T009 follow.

**US1**: write T014 and T015 together. T016 and T017 (translation files) run in
parallel. Then T018 → T019, and T020, then T021 → T022 → T023.

**Across stories, once Phase 2 is done**, four developers can each take one:

- US3: T029–T031
- US4: T036–T040
- US7: T045–T047
- US1 → US2

**Polish**: T050–T056 are separate files and all run in parallel. T057 runs last.

---

## Implementation Strategy

### MVP (User Story 1)

1. Phase 1 → Phase 2 → Phase 3 (US1).
2. **Stop and validate**: run T014 and T015 and quickstart steps 1–2. Guests get routed
   requests and a WhatsApp notice on completion.

### Incremental delivery

1. **US2**: notices outside the window (email), and cancellation outcomes.
2. **US3**: escalation de-duplication.
3. **US7**: silence for unknown senders. Small and isolated, so it can ship any time.
4. **US4**: read tools.
5. **US5**: room changes.
6. **US8**: then US6's final arch test and instructions.
7. **Polish**: pitching gate, isolation tests, docs.

Each step leaves `php artisan test --parallel` green and can be merged on its own.

---

## Notes

- `[P]` means a different file with no dependency on an unfinished task.
- Never edit a shipped migration. T008 is a new one.
- The four `guest_notice_*` columns are never client-writable. T009 keeps them out of
  `$fillable`.
- Do not add `Permission` cases: there are no new endpoints (R18).
- `PermissionAuthorizationTest` needs no new rows.
- Commits: no co-author or "Generated with" lines (CLAUDE.md).
