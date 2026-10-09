# Contract: Admin AI toolset

The tools `AdminAdvisorAgent::tools()` exposes, through `AdminToolset` and `GuardedTool`
([research R2](../research.md#r2-one-guard-around-every-admin-ai-tool-permission-tenancy-audit-approval)).
The model sees each tool's name, description and schema. This table is the source for the
architecture test, the permission dataset and `docs/ai-advisor-chat-api-documentation.md`.

**Permission** is the case the matching staff endpoint's policy checks. The guard requires
it at call time for the acting user. `admin-only` means admin or super admin. Those
resources stay outside the enum per CLAUDE.md. **Confirm** marks the FR-017 tools: the
system asks the admin before they run.

Every tool works only in the acting user's hotel. An id, code or room number of another
hotel gives the same "not found" result as an unknown one.

## Shared result conventions

- **Lists:** `{"total": n, "returned": m, "partial": bool, "items": [...]}`. At most 50
  items. `limit` is 1–50 and defaults to 50, except `GetTasksTool`, which defaults to 20
  without `limit` (R6).
- **Writes:** `{"ok": true, "changed": {...}, "ids": {...}}`. On refusal the result is a
  plain sentence with the business rule's own message, and nothing has changed.
- **Duplicates:** `{"ok": false, "exists": {"id": "…"}}` with the sentence *"Already exists;
  nothing was created."* (R9).
- **Permission refusal:** *"You do not have permission to <action>."*
- **Dates:** `YYYY-MM-DD` in the hotel's timezone. Times are `YYYY-MM-DD HH:MM` in hotel
  local time.

## Read tools

| Tool | Status | Permission | Purpose and main filters |
| --- | --- | --- | --- |
| `KnowledgeSearchTool` | keep | `knowledge_base_articles.view` | Hotel and global knowledge with citations (SPEC-064, unchanged) |
| `GetGuestsTool` | extend | `guests.view` | Search by name, phone or email; in-house/upcoming flag |
| `GetGuestTool` | **new** | `guests.view` | One guest: profile, stays, reservations, bookings |
| `GetReservationsTool` | extend | `reservations.view` | `arrival_from/to`, `departure_from/to`, `staying_on`, `status`, `guest`, `code`. Default without filters is today's arrivals, as today |
| `GetReservationTool` | **new** | `reservations.view` | One reservation by code: lines, room types, assigned rooms, party, stays, status |
| `GetRoomTypesTool` | **new** | `room_types.view` | Name, max occupancy, adults/children capacity, room count, active |
| `GetRoomsTool` | extend | `rooms.view` | `room_type`, `floor`, `building`, `status`, `housekeeping_status`, `room_number` |
| `GetAvailabilityTool` | keep | `availability.view` | Per type and night (SPEC-003, unchanged) |
| `GetStaysTool` | keep | `stays.view` | Arrivals, departures and in-house for a date (unchanged) |
| `GetTasksTool` | extend | `tasks.view` | `team`, `assignee`, `category`, `status`, `priority`, `room_number`, `due_from/to` |
| `GetTaskCategoriesTool` | keep | `task_categories.view` | Categories with team |
| `GetHousekeepingBoardTool` | **new** | `rooms.view` | Same as `GET /housekeeping/board` for a date |
| `GetMaintenanceTool` | **new** | `tasks.view` | Same as `GET /maintenance/tasks`, plus out-of-order rooms with reason and expected end |
| `GetActivitiesTool` | keep | `activities.view` | Activities with day availability (unchanged) |
| `GetBookingsTool` | **new** | `bookings.view` | `guest`, `activity`, `date_from/to`, `status`, `cancellation_requested` |
| `GetGuestMessagesTool` | keep | `guests.view` | Recent guest messages (unchanged; viewer is Phase 11) |
| `GetReportTool` | **new** | self-checked, per report: `dashboard` → `dashboard.view`; `occupancy` → `dashboard.view`; `conversion` → `recommendations.view`; `insights` → `ai_insights.view`; `usage` → admin-only | Report services (R4/R8). `occupancy` takes `from`/`to` (at most 31 nights) and returns one row per night. Conversion has no ledger values; usage has no cost or margin |
| `GetStaffTool` | **new** | admin-only | Users (allow-listed fields), staff roles, effective permissions (R7) |
| `GetHotelSettingsTool` | **new** | admin-only | Allow-listed hotel settings, no secrets (R7) |

## Write tools

| Tool | Status | Permission | Confirm | Operation (R4) |
| --- | --- | --- | --- | --- |
| `CreateGuestTool` | move to service | `guests.create` | – | `GuestRegistrar::register` (identity dedupe → reports existing) |
| `UpdateGuestTool` | **new** | `guests.update` | – | `GuestRegistrar::update`: name, email, phone, language, nationality, preferences, VIP |
| `CreateReservationTool` | move to service | `reservations.create` | – | `ReservationCommands::create` (never overbooks, FR-011) |
| `UpdateReservationTool` | **new** | `reservations.update` | **yes, when the room list removes rooms** | `ReservationCommands::update`: dates, adults/children/children_ages, room lines, notes. Never a lifecycle status (FR-012) |
| `CancelReservationTool` | **new** | `reservations.update` | **yes** | `ReservationCommands::cancel` |
| `AssignRoomsTool` | **new** | `reservations.update` | – | `ReservationCommands::assignRooms` (`RoomAssignmentRules`, R5): assign, change, remove |
| `CheckInTool` | keep | `stays.check_in` | – | `StayLifecycleService::checkInReservation` |
| `CheckOutTool` | keep | `stays.check_out` | **yes** | `StayLifecycleService::checkOutReservation` |
| `CreateTaskTool` | move to service | `tasks.create` | – | `TaskCommands::create` (AI duplicate pre-check, R9) |
| `UpdateTaskTool` | **new** | `tasks.update` | – | `TaskCommands::update`: assignee, team, status, priority, due date, description |
| `SetHousekeepingStatusTool` | **new** | `rooms.update_housekeeping_status` | – | `HousekeepingService::setManually` |
| `SetRoomOutOfOrderTool` | **new** | `rooms.set_out_of_order` | **yes** | `MaintenanceService::takeOutOfOrder` |
| `UpdateOutOfOrderTool` | **new** | `rooms.set_out_of_order` | – | `MaintenanceService::updateOutOfOrder` (reason, expected end) |
| `ReturnRoomToServiceTool` | **new** | `rooms.set_out_of_order` | – | `MaintenanceService::returnToService` |
| `ReportTaskIssueTool` | **new** | `tasks.update` | – | `MaintenanceService::reportIssue` |
| `CreateActivityBookingTool` | **new** (admin) | `bookings.create` | – | `BookingService::create`, never a capacity override (FR-011) |
| `UpdateBookingStatusTool` | **new** | `bookings.update_status` | **yes, when the status is `cancelled`** | `BookingService::{confirm, realise, markNoShow, cancel}` |
| `DecideBookingCancellationTool` | **new** | `bookings.update_status` | **yes, when `approve`** | `BookingCancellationService::{approve, decline}` |
| `CreateKnowledgeArticleTool` | **new** | `knowledge_base_articles.create` | – | `ArticleCommands::create`, hotel article only (R10) |
| `UpdateKnowledgeArticleTool` | **new** | `knowledge_base_articles.update` | – | `ArticleCommands::update`, hotel article only |
| `CreateRoomTool` | keep | `rooms.create` | – | unchanged |
| `CreateActivityTool` | keep | `activities.create` | – | unchanged |

`Confirm` is decided per call by `shouldRequestApproval(Request)`. For example,
`UpdateBookingStatusTool` asks only when `status = cancelled`.

## Deliberately absent (FR-018, FR-019, FR-021)

- No delete tool of any kind.
- No tool that writes `User`, `StaffRole`, permission grants or `Hotel` settings.
- No transaction or ledger tool. No cost or margin anywhere.
- No hotel policy, global knowledge or knowledge document tool.
- No override flags (`overbook_override`, `capacity_override`) in any schema.
- No reservation no-show tool, which waits for SPEC-012 (R11).

## Architecture invariants (tested)

1. Every `AdminToolset` entry declares a permission or `adminOnly`, except exactly one
   `selfChecked` entry, `GetReportTool`. Its permission depends on the `report` argument,
   so it checks each branch itself.
2. Every write entry runs under `EventLogger::asAiAgent(onBehalfOf: user, ai: {...})`.
3. `{entries with confirm}` = `{CancelReservationTool, CheckOutTool, SetRoomOutOfOrderTool,
   UpdateBookingStatusTool(cancelled), DecideBookingCancellationTool(approve),
   UpdateReservationTool(removing rooms)}`.
4. No write entry's operation persists `User`, `StaffRole` or `Hotel`.
5. No tool schema has a property named `*override*` or `hotel_id`.
6. Every write entry declares the models it changes (`writes`), and each of those models
   uses `App\Models\Concerns\RecordsEvents`, so every AI write is audited.
   `KnowledgeBaseArticle` and `Activity` gain the trait in this feature.
