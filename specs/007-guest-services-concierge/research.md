# Research: Guest Services and Concierge on the New Domain

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-10-06

Each decision is numbered (R1–R20) so the plan, data model and tasks can point at it.
Code references are to `main` plus the `006-activity-bookings` branch (see R1).

---

## R1 — Build on Phase 6

**Decision**: Merge `006-activity-bookings` into `main` before implementing this
feature, and branch from the result.

**Rationale**: The spec reuses Phase 6 directly: `ActivityAvailabilityService`, the
reservation window in `CreateBookingTool`, `RequestBookingCancellationTool`,
`BookingCancellationService` and the task columns `booking_id`, `resolution`,
`resolution_note`, `resolved_at`. Those exist only on the 006 branch today. The
cancellation-outcome notice (FR-010c) reads `resolution` and `resolution_note`.

**Alternatives considered**: Implementing on top of `main` and re-creating the Phase 6
pieces. Rejected: duplicates work and guarantees merge conflicts.

---

## R2 — Request kinds are `GuestSignal` cases

**Decision**: Add two cases to `App\Enums\GuestSignal`:

- `MAINTENANCE_REQUEST = 'maintenance_request'`
- `ROOM_CHANGE_REQUEST = 'room_change_request'`

The kinds of guest request are then:

| Kind | `guest_signal` | Created by |
| --- | --- | --- |
| Service | `service_request` | `CreateGuestServiceRequestTool` (housekeeping / other) |
| Maintenance | `maintenance_request` | `CreateGuestServiceRequestTool` with `kind = maintenance_request` |
| Room change | `room_change_request` | new `RequestRoomChangeTool` |
| Booking cancellation | `cancellation_request` | `RequestBookingCancellationTool` (Phase 6) |
| Escalation | `escalation` | `EscalateToHumanTool` |
| Booking follow-up | `booking_follow_up` | `CreateGuestServiceRequestTool` (unchanged, not a guest request) |

`GuestSignal` gains `notifiesOnCompletion(): bool` (true for service, maintenance and
room change, per spec Q5) and `isComplaint(): bool` (service, maintenance, room change)
for the pitching gate (R17).

**Rationale**: `guest_signal` already answers "why does this guest task exist", is
indexed with `guest_id`, is not client-writable, and is what pitching and the
cancellation queue read. A second "kind" column would say the same thing twice.

**Alternatives considered**: A new `request_kind` column. Rejected for duplication.
Reusing `service_request` for maintenance with the category deciding. Rejected: the
category is optional and editable by staff, so the kind would drift.

No backfill: existing tasks keep their values. Older maintenance-type service requests
stay `service_request`.

---

## R3 — Classification and routing

**Decision**:

- **Service request** (housekeeping or other): the Concierge picks a category with
  `GetTaskCategoriesTool`. The tool validates it against the hotel (it already does) and
  sets the team from the category. No category means no team (FR-002, FR-003).
- **Maintenance request**: always filed under the hotel's
  `maintenance_task_category_id` and `maintenance_team_id`, which
  `HotelOperationalDefaults` fills for every hotel (D7). The model does not choose. If
  either is missing, the request is filed without it; it is never rejected.
- Housekeeping cleaning requests keep the Phase 5 "one open clean per room" rule.

**Rationale**: Maintenance routing is deterministic, so it is not left to the model.
Housekeeping has several categories (cleaning, amenities, inspection), so the model's
choice is useful there.

**One tool, server-side routing** (*revised during implementation*):
`CreateGuestServiceRequestTool` takes `kind = maintenance_request`, and for that kind the
tool sets the Maintenance category and team itself, ignoring any category the model
names.

**Alternatives considered**: A separate `CreateMaintenanceRequestTool`. It was first
built that way and then merged back. The model would still choose between two tools
instead of between two `kind` values, so it added no safety. It also duplicated the
stay lookup, VIP priority, adding detail, the reservation check, pitch blocking and
notifications. What makes routing deterministic is the server setting the category and
team, and one tool does that.

---

## R4 — Room-change requests

**Decision**: A new `RequestRoomChangeTool` calls a new
`GuestRequestService::requestRoomChange()`. It creates a `Task` with:

- `guest_signal = room_change_request`, `created_by = guest`
- no team and no category (spec Q6)
- `stay_id` and `room_id` of the guest's in-house stay, or only `reservation_id` before
  arrival
- the reason and any preference in `description`

One open request per stay, or per reservation before arrival, is enforced by a partial
unique index (data model). Asking again returns the open request, like Phase 6
cancellation requests. Admins are emailed through the existing
`CreationNotificationService::taskCreated($task, createdByAi: true)`, which already sends
`AiTaskCreatedNotification` to the hotel's admins.

The tool never touches `reservation_rooms`, `stays.room_id` or room status. Staff carry
out the move with the Phase 3 assignment endpoints.

**Alternatives considered**: A dedicated `RoomChangeRequestedNotification`. Rejected: the
existing AI-task email already reaches the admins once, and a second email would
duplicate it.

---

## R5 — Escalation de-duplication

**Decision**: `GuestRequestService::escalate()` replaces the inline code in
`EscalateToHumanTool`:

- One open escalation per guest per hotel, enforced by a partial unique index.
- When one is open, the new reason is appended to its description
  (`"\n\n[2026-10-06 14:05] <reason>"`). No new task is created and nobody is emailed
  again. An `escalation_repeated` event is recorded.
- A new escalation sets high priority, `guest_signal = escalation`, links the active
  reservation if there is one, and emails admins as today.
- `markBlockingRequest()` is called in both cases, so the same reply never pitches.

The index catch (`23505`) re-reads the open task, as `BookingCancellationService` does.

---

## R6 — Active reservation and guest scope

**Decision**: Add `Reservation::isActive(): bool`, true when:

- the status is `pending`, `confirmed` or `checked_in`, and
- `departure_date` is today or later in the hotel's time zone.

Write tools check it before acting:

| Tool | Needs an active reservation |
| --- | --- |
| Service request, maintenance request, room change | Yes (FR-030) |
| Booking | Yes (already, Phase 6 window) |
| Cancellation request | No: it needs the guest's own editable booking (Phase 6) |
| Escalation | No (FR-014) |

`SenderRecognitionService` can resolve a guest to their latest past reservation (D12).
That is why the check is needed: today a past guest's request would be filed against a
room they no longer occupy.

---

## R7 — Where the guest notice is triggered

**Decision**: A `Task` model `updated` hook dispatches `SendGuestRequestNoticeJob`
(after commit) when all of these hold:

- `status` changed to `completed` or `cancelled`
- `guest_id` is set
- `guest_signal` is a notifying kind (service, maintenance, room change) or
  `cancellation_request`
- `guest_notice_status` is still null

**Rationale**: A guest request is closed by at least five code paths:

- `TaskController::update`
- `BookingCancellationService::decline`
- `BookingService::closeCancellationRequest` (approve, or any staff cancel)
- `HousekeepingService` and the inspection flow
- `MaintenanceService`

An explicit call in each would be easy to miss, and a missed path means a guest is never
told (FR-007). The hook only fires for guest-signalled tasks, which seeders and imports
do not close. This is a deliberate exception to the explicit-call style of
`CreationNotificationService`.

**Every closing path must save the model** so the hook fires. The paths are:

- `TaskController::update`
- `TaskInspectionController` (an inspection that completes or reopens a task)
- `HousekeepingService` (status sync, overnight and start-of-day jobs)
- `MaintenanceService`
- `BookingCancellationService::decline`
- `BookingService::closeCancellationRequest`

Any of them that closes a task through a query-builder `update()` must be changed to load
and `save()`, or must call `Task::queueGuestNotice()` for each closed guest request. A
task (T028) audits them, and a test closes a guest cleaning request through the
housekeeping flow.

**Alternatives considered**:

- An explicit service call from each path. Rejected for the reason above.
- A scheduled sweep for completed, un-notified tasks. Rejected: up to a poll interval of
  latency, against the 2-minute target (SC-002).

---

## R8 — Notify at most once

**Decision**: New task columns record the notice: `guest_notice_status`,
`guest_notice_channel`, `guest_notice_reason` and `guest_notice_at` (data model).

The job claims the task atomically:

```sql
UPDATE tasks SET guest_notice_status = 'pending' WHERE id = ? AND guest_notice_status IS NULL
```

It continues only if exactly one row changed. A request re-opened and completed again
already has a status, so it is never notified twice (FR-012), even when two workers race.

A cancelled service, maintenance or room-change request records
`skipped / cancelled` without sending (spec Q1, FR-010b).

**Tenant context**: the job has no request, so after loading the task by id it runs
everything else inside `TenantContext::runForHotel($task->hotel_id, ...)`, as the
constitution requires for jobs. Only the first lookup by primary key bypasses the scope,
because the hotel is not known until the task is loaded.

---

## R9 — The 24-hour messaging window

**Decision**: The window is measured from the newest `whatsapp_inbound_messages` row for
the guest's phone digits (`PhoneNumber::digits`), across all hotels. It is checked
**when the job runs**, not when the task completes. It counts as open when that message
is less than 23 h 50 min old; the 10-minute margin covers queue delay and clock skew.

**Rationale**: WhatsApp's window belongs to the phone number and the business number. The
platform shares one number (D12), so a message the guest sent to another hotel opens the
same window. `guests.last_contacted_at` is per hotel record and would under-count. The
existing `(phone_number, created_at)` index makes the lookup one indexed query.

---

## R10 — Choosing the channel and handling failure

**Decision**:

| Situation | Action |
| --- | --- |
| Window open and guest has a phone | WhatsApp free-form text through `WhatsAppMessageService::send` |
| Window closed, or no phone | Email if the guest has an address, else `skipped / no_contact` |
| WhatsApp send fails after 3 attempts in the job (pauses 1 s, 3 s) | Email if available, else `failed / send_failed` |
| Email send fails after 3 attempts | `failed / send_failed` |

Each channel is retried inside the job (`retry([1000, 3000], …)`), not through
queue retries: with a synchronous queue the job runs inside the staff request,
so a failure must be caught and recorded there and never reach the response
(FR-013). Every failure is recorded on the task.

Email is sent through an on-demand route
(`Notification::route('mail', $guest->email)`) with a new
`GuestRequestNoticeNotification`. Its subject and sender name show the hotel's name.
Template messages are never used (spec Q2). Email for notices is allowed by constitution
v2.1.0 ("Guest Identity and WhatsApp"): WhatsApp stays the primary channel, and email is
used only when WhatsApp rules forbid the message.

**Latency**: the job logs the time between the task's `completed_at` (or `resolved_at`)
and the send, so SC-002 can be checked from the logs.

The job never changes the task's `status`, so a notice failure cannot block or undo
completion (FR-013).

---

## R11 — Wording and language

**Decision**: Fixed messages in new translation files `lang/en/guest_notices.php` and
`lang/ar/guest_notices.php`, with three keys: `completed`, `cancellation_approved` and
`cancellation_declined`.

- **Language**: `guests.preferred_language` if it is `ar`, otherwise English. The column
  is NOT NULL with a default, so "unset" only occurs as an unsupported value.
- **Placeholders**: the guest's first name, the hotel name, a fixed label for the kind of
  request (completed), and the booking's item name, date and reference (cancellation).
  The task title is **not** used: staff can edit it, so it could carry a staff name
  (FR-008). Labels: `housekeeping` (a service request under the hotel's housekeeping
  team), `service`, `maintenance` and `room change`, each translated.
- **Decline note**: a declined cancellation also includes `resolution_note`, which
  Phase 6 already labels as the text for the guest.

Staff names, team names and `description` are never included (FR-008). The AI does not
write the notice.

**Note**: Hotels have no language setting today, so English is the default (spec FR-007
was aligned with this). A hotel-language setting is out of scope.

---

## R12 — Unknown senders get no reply

**Decision**: In `WhatsAppController::handleInboundMessage`, after `identify()`, a
`SenderType::UNKNOWN` sender is marked with a new status `InboundMessageStatus::IGNORED`
and the method returns. No job is dispatched, no AI runs and nothing is sent.
`ProcessInboundWhatsAppMessageJob::UNKNOWN_SENDER_REPLY` and its branch are removed.

These steps are unchanged and still run first:

- **wamid de-duplication**, so a redelivery stops before recognition;
- **the per-sender rate limit** (FR-035a);
- **pairing**: a staff member pairing a new device sends a token from a number that may
  not be recognised yet.

**Rationale**: Ignoring a sender before the queue costs one indexed lookup and creates no
hotel-scoped record. The inbound row has `hotel_id = null`.

**Stored data**: the inbound row (phone number, message type, wamid, status) is kept only
so a redelivered message is recognised and the rate limit can count. It follows the
existing retention of `whatsapp_inbound_messages`. No message text is stored for ignored
senders, and nothing is written to any hotel's tables.

---

## R13 — Guest read tools

**Decision**:

- **`GetOwnReservationTool` (changed)**: adds `is_active` and, per room line, the stay
  status from `stays`. It still returns a past reservation for a past guest, which is
  their own data. Actions are gated by R6, not by what this tool shows.
- **`GetOwnBookingsTool` (new)**: the guest's bookings at the hotel. Upcoming first
  (`scheduled_date >= hotel today`, ascending), then the 10 most recent past ones. Each
  shows activity or item name, date, time, party size, status, reference,
  `expected_value` and currency (spec Q7).
- **`GetOwnRequestsTool` (new)**: the guest's open guest-request tasks. Each shows kind,
  title, a guest-facing status (`pending` → `received`, `in_progress` → `in progress`)
  and created time. It never shows team, assignee, description or notes (FR-021).
- **Unchanged**: `GetActivitiesTool` (Phase 6 availability), `GetGuestAvailabilityTool`
  and `KnowledgeSearchTool`.

Every query filters on `hotel_id` and `guest_id` from the constructor. A reference or id
belonging to anyone else returns "not found" (FR-024).

---

## R14 — Avoiding duplicate requests

**Decision**: `CreateGuestServiceRequestTool` (every kind) accepts
an optional `add_to_request_id`. When it is set and names an open request of the same
kind that belongs to the guest, the new detail is appended to that request and no task is
created. Any other id is ignored and a new request is filed.

The Concierge instructions say to call `GetOwnRequestsTool` before filing. Escalations
and room changes are de-duplicated by the system (R4, R5), not by the model.

**Rationale**: FR-006 depends on judgement ("the same problem"), which only the model can
make. The tool makes appending safe and owner-checked.

---

## R15 — Guest restrictions enforced in code

**Decision**:

- Every guest tool takes `Guest`, `Hotel` and `Reservation` from its constructor, which
  `SenderRecognitionService` resolved. No tool takes a guest, hotel, room or stay id as
  input.
- No guest tool calls `BookingService::cancel` or `updateStatus`, room assignment,
  `StayLifecycleService` or room status writes.
- An architecture test (Pest `arch()`) pins `GuestConciergeAgent::tools()` to an
  allowlist and asserts that no class in that list depends on those services.

This covers FR-031 to FR-033 and SC-004.

---

## R16 — Audit

**Decision**: The guest turn already runs inside `EventLogger::asAiAgent` in
`ProcessInboundWhatsAppMessageJob`, so task creation through `RecordsEvents` is recorded
with the AI actor (FR-034). New explicit events on the task:

- `room_change_requested`
- `escalation_repeated`
- `request_detail_added` (R14)
- `guest_notified` (channel, or reason when not sent)

`guest_notified` runs in a queued job without an actor, so it is recorded as the system
actor.

---

## R17 — Pitching

**Decision**: `PitchEligibilityService::serviceRequestGate` counts every
`GuestSignal::isComplaint()` kind (service, maintenance, room change), not only
`service_request`. The new tools call `PitchTurn::markBlockingRequest()` like the
existing ones. The escalation gate is unchanged (FR-016).

---

## R18 — Permissions and endpoints

**Decision**: No new endpoints and no new `Permission` cases:

- Guest requests are tasks, handled through the existing `tasks.*` permissions.
- Cancellation decisions use Phase 6's `bookings.update_status`.

`TaskResource` gains the four `guest_notice_*` fields. The task index can filter by
`guest_signal` and `guest_notice_status` through `GenericQuery`.
`PermissionAuthorizationTest` needs no new rows.

**Rationale**: CLAUDE.md asks for permission cases only for resources with endpoints.
This feature adds AI tools and a job.

---

## R19 — Concierge instructions

**Decision**: Rewrite the tool section of `GuestConciergeAgent::instructions()`:

- one line per tool, matching the new set;
- check open requests before filing;
- maintenance problems use `kind = maintenance_request`;
- a room change is a request, never a promise;
- escalate when policy says so, when the guest asks, or after failing to help;
- tell a guest without an active reservation what needs one.

Instructions are guidance only. Every rule they state is also enforced by a tool (R15).

---

## R20 — Documentation and frontend

**Decision**:

- **Docs**:
  - `docs/task-management-api-documentation.md`: new fields, guest signals and filters.
  - `docs/whatsapp-device-api-documentation.md`: unknown senders are ignored.
  - `docs/booking-entity-documentation.md`: the guest is told the cancellation outcome.
  - `docs/latest-changes-<date>.md`: unknown senders no longer get a reply, and new
    `guest_signal` values appear in task lists.
  - `docs/staff-roles-api-documentation.md`: unchanged (no new permissions).
- **Frontend**: none required (master plan: "none beyond task views"). Optionally, the
  task detail can show the notice status and the task list can filter by kind. Recorded
  in `frontend-changes.md` at implementation time.
