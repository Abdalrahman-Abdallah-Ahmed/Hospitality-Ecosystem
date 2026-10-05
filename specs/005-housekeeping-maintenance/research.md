# Research: Housekeeping and Maintenance

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-10-04

The spec has no open clarifications. This file records the design decisions made while
reading the code. Each entry gives the decision, the reason, and the alternatives that
were rejected.

---

## R1. Status split: change the existing enums in place

**Decision**: Keep the two enum classes, `RoomStatusesEnum` and `HousekeepingStatusesEnum`,
and change their cases:

- `RoomStatusesEnum`: `available`, `occupied`, `out_of_order`. `maintenance` is removed.
- `HousekeepingStatusesEnum`: `dirty`, `cleaning`, `clean`, `inspected`. `blocked` is
  removed.

The columns were created with `$table->enum(...)`, which on Postgres is a `varchar` with a
check constraint (`rooms_status_check`, `rooms_housekeeping_status_check`). One migration
does the whole change:

1. Drops both check constraints.
2. Rewrites the data (FR-038):
   - A room that is `maintenance` or `blocked` with no in-house stay becomes
     `out_of_order` + `dirty`, with `out_of_order_reason = 'Migrated from previous status'`
     and `out_of_order_since = now()`.
   - A room in that state with an in-house stay becomes `occupied` + `dirty`. It goes into
     the release report (R21).
3. Adds the new check constraints.

`down()` reverses the mapping: `out_of_order` → `maintenance`, `cleaning` → `dirty`,
`inspected` → `clean`.

`Room` gains a `status` cast to `RoomStatusesEnum`. Today `status` is an uncast string and
the code compares it to `->value`. Every comparison is updated. They are listed in the plan.
`RoomStatusesEnum::outOfOrder()` becomes `[OUT_OF_ORDER]`, so availability
(`AvailabilityService`) follows without other changes, as SPEC-020 intended.
`Room::isOutOfOrder()` becomes a single status check.

**Rationale**: Principle II, preserve and evolve. Every caller already imports these
classes, and the SPEC-020 and SPEC-025 code was written with this switch point in mind.

**Alternatives**: New enum classes (`RoomStatus`, `HousekeepingStatus`) and then deleting
the old ones. Rejected: it touches every import for no behavior gain. A Postgres native
`ENUM` type. Rejected: the repo uses check constraints everywhere.

## R2. Room and housekeeping status are no longer writable through the generic room edit

**Decision**: `POST /room` and `PUT /room/{id}` reject `status` and `housekeeping_status`
with a 422. The message names the dedicated actions and the new values:

- *Room status* comes from stays (occupied) or from out-of-order and return to service.
- *Housekeeping status* comes from tasks, or from `PUT /room/{id}/housekeeping-status`.

New rooms start as `available` + `clean`. The `CreateRoomTool` AI tool drops its `status`
parameter and creates the room as available. `GetRoomsTool` describes the new values.

The data migration already moved the old values (`maintenance`, `blocked`). A client that
still sends them gets the same 422, which satisfies FR-039.

**Rationale**: FR-002 needs a single writer for housekeeping status. Out of order needs a
reason, an actor and an occupancy check (FR-016, FR-017). The generic edit can do neither.
This is a breaking API change, so it goes into `latest-changes`.

**Alternatives**: Keep accepting the fields with a deprecation notice, as D4 did for
check-in. Rejected: unlike reservation status, writing room status directly today
corrupts the occupancy that stays drive, and there is no safe way to "route" a bare
`status=available` value.

## R3. Two domain services, not model events

**Decision**:

- `App\Services\HousekeepingService` is the only writer of `rooms.housekeeping_status`.
  It has these methods:

  | Method | Called when |
  | --- | --- |
  | `taskCreated` | a task is created |
  | `taskChanged` | a task's status, category or room changes |
  | `taskRemoved` | a task is deleted |
  | `roomVacated` | a guest checks out |
  | `roomNeedsCleaning` | a room returns to service (set dirty and ensure a cleaning task) |
  | `ensureCleaningTask` | a cleaning task is needed |
  | `recordInspection` | an inspection is completed |
  | `setManually` | staff correct the status by hand |
  | `startDay` | the start-of-day process runs |
  | `isReady` | a caller needs to know if a room is ready |

- `App\Services\MaintenanceService` handles `takeOutOfOrder`, `updateOutOfOrder`,
  `returnToService` and `reportIssue`. It calls `HousekeepingService` for the dirty status
  and the cleaning task.

Every method:

1. Runs in one DB transaction.
2. Locks the room `FOR UPDATE` first, and the task second. This is the same order
   `StayLifecycleService` uses, room before task, so the two cannot deadlock.
3. Re-reads the state after locking.

Callers:

- `TaskController` (`store`, `update`, `destroy`)
- `CreateTaskTool`, `CreateGuestServiceRequestTool` and `EscalateToHumanTool`, through the
  shared after-create hook (R12)
- `StayLifecycleService::checkOut`
- `ReservationCreator`'s room-release path
- the new controllers and the start-of-day job

**Rationale**: The plan explicitly says task status drives housekeeping status "through a
service, not model events scattered around the code". There are more than four callers,
which meets the constitution's bar for a service.

**Alternatives**: A `Task::updated` observer. Rejected: hidden coupling. It also fires
inside imports and seeders, and it cannot return the warnings the API must report (FR-009,
FR-019, FR-024).

## R4. Which tasks drive a room: a stored `housekeeping_kind`

**Decision**: `tasks` gains `housekeeping_kind` (`cleaning` | `inspection` | null).
`HousekeepingService` sets it on create, and again whenever `task_category_id` changes:

- `cleaning` when the category is the hotel's cleaning category.
- `inspection` when the category is the hotel's inspection category.
- Otherwise null.

Only tasks with a kind and a room move a room. Other categories under the Housekeeping
team, such as "Turndown" or "Amenity delivery", stay ordinary tasks.

`tasks` also gains `cleaning_reason`, a new enum `CleaningReason`: `check_out`,
`stay_over`, `re_clean`, `return_to_service` or `manual`. It is null for tasks that are
not cleaning tasks.

FR-007 (one open task of each kind per room) is enforced in two places:

- **Partial unique index** `tasks_one_open_housekeeping_kind_per_room` on
  `(room_id, housekeeping_kind)`, applied where `housekeeping_kind is not null and
  status in ('pending','in_progress') and deleted_at is null`.
- **Service**: it checks first and reuses the open task. A staff-created duplicate gets a
  422 that names the existing task.

**Rationale**:

- Classifying at write time keeps old tasks stable when an admin later changes the
  hotel's default category.
- It allows the unique index.
- It avoids treating every Housekeeping-team category as "cleaning", which would wrongly
  move rooms on turndown or amenity tasks.

**Alternatives**: Derive the kind at read time from the hotel settings. Rejected: there
would be no index, and the result shifts when settings change. Any category under the
Housekeeping team counts. Rejected for the reason above.

## R5. Task-driven transitions and stale tasks (FR-003, FR-005, FR-008)

**Decision**: `taskChanged($task, $before)` acts only when `housekeeping_kind` is set and
`room_id` is set.

| Task change | Room becomes (when FR-008 allows) |
| --- | --- |
| cleaning → `in_progress` | `cleaning` |
| cleaning → `completed` | `clean`; if the hotel requires inspection, ensure an inspection task |
| cleaning `in_progress` → `pending` / `cancelled` / deleted | `dirty` (only if the room is in `cleaning`) |
| completed cleaning reopened (`in_progress` / `pending`) | `cleaning` / `dirty` |
| inspection → `completed` | only through the inspection action (R6) |

**FR-008 (stale tasks)**: a change moves the room only if one of these is true:

- The task is the room's current open task of its kind.
- It is the task being completed right now.
- It is a reopened task and the room has no other open housekeeping task.

The check is cheap because the R4 index guarantees at most one open task of each kind.

**Concurrency**: `taskChanged` does nothing when the status did not actually change, so a
repeated request makes no second update. The room row lock makes two concurrent starts
serialize. The second finds the room already in `cleaning` and changes nothing.

## R6. Inspection: hotel flag and a dedicated action

**Decision**:

- `hotels` gains `inspection_required` (bool, default false) and
  `inspection_required_since` (timestamp, set whenever the flag turns on).
- An inspection task is completed through `POST /task/{task}/inspection`, with
  `{ result: pass|fail, note }`. The note is required when the result is `fail`. Results:
  - **pass**: task completed, `inspection_result = pass`, room `inspected`.
  - **fail**: task completed, `inspection_result = fail` and `inspection_note` stored, room
    `dirty`, and a new cleaning task (`re_clean`) whose description carries the note.
- Setting `status=completed` on an inspection task through `PUT /task` is rejected (422,
  "use the inspection action"). Cancelling one is allowed, and the room stays `clean`.

Readiness (FR-006) is computed in `HousekeepingService::isReady(Room, Hotel)`. A room is
ready when either holds:

- it is `inspected`;
- it is `clean`, and either inspection is off, or the room's
  `housekeeping_status_changed_at` is earlier than `inspection_required_since`.

`rooms` gains `housekeeping_status_changed_at` to support this. The check-in warning
(SPEC-024) now fires when the room is "not ready", not just "not clean".

When inspection is turned off, open inspection tasks keep working, and no new ones are
created (US2 scenario 5). Nothing extra is needed for this.

**Alternatives**: Store `ready` as a column. Rejected: it duplicates state and goes stale
when the setting changes.

## R7. Start-of-day replaces the overnight job

**Decision**: `StartHousekeepingDayJob` replaces `MakeRoomDirtyOvernightJob`. It is
scheduled **hourly**. For each active hotel:

1. Compute the hotel's local date from `hotels.timezone`.
2. Insert a `housekeeping_day_runs` row `(hotel_id, day)` under a unique index, in the
   same transaction as the work. If the insert conflicts, skip the hotel (FR-013).
3. Inside `TenantContext::runForHotel()`, find the stay-over rooms: in-house stays whose
   `departure_date > day`, room not `out_of_order`.
4. For each room, call `HousekeepingService::startDay`, which:
   - sets the room `dirty` unless it is already `dirty` or `cleaning`;
   - calls `ensureCleaningTask(reason: stay_over, due: end of that hotel day)`.

Rooms departing that day are skipped, because check-out creates their cleaning task
(US3 scenario 3). Each hotel is its own transaction, so one hotel's failure doesn't block
the others. The failure is reported, and the job is retryable.

A late run (for example the queue was down) still uses the hotel's current local date. A
run that misses the whole day does not backfill it, because yesterday's stay-over clean
is no longer useful.

**Rationale**:

- Hourly runs give every time zone its local day within an hour of midnight.
- The run row makes the job idempotent under concurrency and retries.
- It keeps the existing "only in-house rooms, never out-of-order" rules (R16 from
  SPEC-025).

**Alternatives**: One job at 00:01 server time (today's behavior). Rejected: wrong day for
hotels in other time zones. A per-hotel cron. Rejected: the scheduler has no per-tenant
entries.

## R8. Default teams and categories (SPEC-004)

**Decision**: `App\Support\Housekeeping\HotelOperationalDefaults::ensure(Hotel)` is
idempotent and runs in one transaction. For each default setting that is empty, it
creates the item and fills the setting:

| Setting | Created item |
| --- | --- |
| `housekeeping_team_id` | Team "Housekeeping" |
| `cleaning_task_category_id` | Category "Cleaning" (Housekeeping team) |
| `inspection_task_category_id` | Category "Inspection" (Housekeeping team) |
| `maintenance_team_id` | Team "Maintenance" |
| `maintenance_task_category_id` | Category "Maintenance" (Maintenance team) |

- Settings an admin already filled are never overwritten (FR-041).
- `teams` has `unique(hotel_id, name)`. If a team with the default name already exists,
  it is reused rather than duplicated, and restored if it was soft-deleted. This is the
  only name lookup, and it runs once at provisioning time. Everything after that reads the
  ids.
- Called from `Hotel::booted()` `created`, so every creation path is covered (API,
  seeders, admin), and from a data migration for existing hotels. That migration runs
  `withoutScope()` and is idempotent (FR-042).
- `HousekeepingDefaults::for()` is extended to return the five items. Inactive teams and
  deleted categories still come back as null (FR-013 behavior from SPEC-025).
- `HotelController::update` validation is extended to the new settings:
  - same hotel;
  - team is active;
  - category belongs to its team;
  - the inspection category belongs to the housekeeping team;
  - the maintenance category belongs to the maintenance team.

**Rationale**: D7. The `created` hook is a one-time provisioning step, not a status
transition, so R3's "no model events" rule doesn't apply.

## R9. A task's team comes from its category (FR-015)

**Decision**: In `TaskController::store`/`update` and the AI task tools: when
`task_category_id` is set and no team is given (or the task has none), the team becomes
the category's team.

Today that request fails with "category does not belong to the chosen team" (403). The
existing check stays when a team *is* given. This covers both maintenance and housekeeping
routing without a special case. It is a relaxation, so no client breaks.

## R10. Out of order (FR-016–FR-021)

**Decision**: `rooms` gains these columns. All are cleared on return to service:

- `out_of_order_reason` (text)
- `out_of_order_since` (timestamp)
- `out_of_order_until` (date, information only)
- `out_of_order_by_user_id` (FK users, null on delete)
- `out_of_order_task_id` (FK tasks, null on delete)

Endpoints:

| Endpoint | Body | Action |
| --- | --- | --- |
| `POST /room/{room}/out-of-order` | `{ reason, expected_end_date?, task_id? }` | `takeOutOfOrder` |
| `PATCH /room/{room}/out-of-order` | `{ reason?, expected_end_date? }` | `updateOutOfOrder` |
| `POST /room/{room}/return-to-service` | `{ note? }` | `returnToService` |

`MaintenanceService::takeOutOfOrder`:

- Locks the room.
- Rejects an in-house stay (422, names the stay).
- A room that is already out of order returns 200 with `changed: false`.
- Sets the status and fields.
- Returns `affected_lines`: live reservation lines assigned to the room with
  `departure_date > hotel today`.

`returnToService`:

- Rejects a room that is not out of order (422).
- Sets `available`, clears the fields, and calls
  `HousekeepingService::roomNeedsCleaning(reason: return_to_service)`, which sets the room
  `dirty` and ensures a cleaning task.

Completing a maintenance task that `out_of_order_task_id` points to adds
`room_ready_to_return: true` to the task update response (FR-019). The room status itself
does not change.

`ReservationCreator::syncRoomStatus` never turns `out_of_order` into `available`. This
guard is kept explicitly and tested.

## R11. Reporting a room issue (SPEC-035)

**Decision**: `POST /task/{task}/issues` with
`{ description, priority?, room_unsellable: bool }`.

The request is allowed when the caller passes `update` on the source task (`tasks.update`
plus same hotel) and the source task:

- has a `housekeeping_kind` or is in a Housekeeping-team category;
- has a room;
- is open, or was completed on the current hotel day.

`MaintenanceService::reportIssue` runs in one transaction. It locks the room, then the
source task, and creates the maintenance task with:

- the hotel's maintenance category and maintenance team;
- the source task's room, stay and reservation;
- `source_task_id` set to the source task;
- `created_by_user_id` set to the reporter;
- `created_by = staff`, or `ai` when run inside `asAiAgent`;
- title "Room {n}: {first 60 chars}" and the full description.

When `room_unsellable` is true:

- If the reporter has `rooms.set_out_of_order` and the room has no in-house stay, it calls
  `takeOutOfOrder` with the description as the reason and the new task linked.
- Otherwise it skips that step and returns `out_of_order: { applied: false, reason }`.

**Idempotency (FR-025)**: if an open maintenance task already exists with the same
`source_task_id` and the same normalized description (trimmed, lower-cased), it is
returned (200 with `created: false`), and no new task is created.

There is no separate "room issue" table. The issue is the maintenance task, and
`source_task_id` is the link, as the spec's Key Entities say.

**Alternatives**: An `Idempotency-Key` header. Rejected: nothing else in the repo uses
one, and the natural key is enough here.

## R12. Notifications without duplicates (FR-026–FR-029)

**Decision**:

- New table `task_notification_receipts (task_id, user_id, notified_at)`, `unique(task_id,
  user_id)`.
- `CreationNotificationService::taskCreated` and a new `taskReassigned($task, $before)`
  resolve the recipients:
  - the assigned user;
  - if no user is assigned and the team is the hotel's housekeeping or maintenance team,
    the team's members (`users.team_id`, hotel-scoped). Other teams keep today's behavior,
    which notifies the assignee only.
- For each recipient, `insertOrIgnore` a receipt. Only rows that were actually inserted
  get the notification. Resaves, retries and repeated requests therefore never notify the
  same person twice for the task.
- When the assignment moves away from a person (a different user, or a team they are not
  in), their receipt is deleted. If the task comes back to them later, it counts as a new
  assignment and they are notified again.
- Narrowing from the team to one member of it keeps that member's receipt. They are not
  notified again (FR-027).
- Notifications stay `afterCommit()` and keep `deleteWhenMissingModels`. In addition,
  `TaskAssignedNotification::shouldSend()` returns false for a task that is cancelled or
  completed (FR-028).
- Routing alerts (FR-029): when an automatic task has no team, or its team has no members,
  `CreationNotificationService::routingProblem($hotel, $cause)` notifies the hotel's
  admins. A `Cache::add("hk-routing:{hotel}:{day}:{cause}", ttl to the end of the hotel
  day)` guard means they are notified at most once per hotel per day per cause.

`TaskController::update` calls `taskReassigned` only when `assigned_to_user_id` or
`assigned_to_team_id` actually changed.

**Alternatives**: De-duplicating in the queue with `ShouldBeUnique`. Rejected: it doesn't
survive a resave after the job ran. Fanning out to all teams. Rejected: it would change
behavior for F&B and other teams the spec doesn't cover.

## R13. Audit: one named event per change, with its cause

**Decision**: Reuse the `auditExtras` pattern from `Stay`. `Room` gains:

- an `auditExtras` property;
- a `loggedChangeSet()` override that merges in `cause`, `task_id` and `reason`;
- `eventVerbFor()` that maps the change to a named event:

| Change | Event |
| --- | --- |
| housekeeping status changed | `room.housekeeping_changed` |
| status set to `out_of_order` | `room.taken_out_of_order` |
| status changed from `out_of_order` | `room.returned_to_service` |
| out-of-order fields edited | `room.out_of_order_updated` |

`cause` is one of `task`, `check_out`, `start_of_day`, `inspection`, `issue`,
`return_to_service`, `manual` or `migration`.

Task `eventLoggedAttributes` gains `housekeeping_kind`, `cleaning_reason`,
`inspection_result`, `inspection_note` and `source_task_id`.

Actor kind comes from `EventLogger` as today:

| Who acted | Actor kind |
| --- | --- |
| staff member | user |
| start-of-day job | system |
| inside `asAiAgent` | ai_agent |

`HousekeepingService` writes the room through `$room->update()` so the trait fires. It
never uses a query-builder `update()`, which would skip the audit. The current overnight
job does exactly that and is replaced (R7).

## R14. Board and maintenance list

**Decision**:

- `GET /housekeeping/board`, permission `rooms.view`. Filters: `housekeeping_status`,
  `status`, `floor`, `building`, `team_id`. It returns:
  - `counts` per housekeeping status;
  - `rooms[]`, each with:
    - room status and housekeeping status;
    - `ready`;
    - out-of-order reason and expected end date;
    - `departure_date` of the in-house stay;
    - the open housekeeping task (id, kind, reason, status, assignee).

  It runs in a fixed number of queries: rooms with room type, one query for in-house stays
  keyed by room, and one for open housekeeping tasks keyed by room. Under 2 s for 500
  rooms (SC-008).
- `GET /maintenance/tasks`, permission `tasks.view`. It lists tasks whose category belongs
  to the maintenance team, or whose team is the maintenance team, through
  `GenericIndexRequest`/`GenericQuery`. Extra filter: `room_out_of_order=true|false`.
  Default sort: open first, then priority (urgent first), then oldest. Each row includes:
  - the room;
  - the reporter (`created_by_user_id`);
  - `source_task_id`;
  - age;
  - the room's out-of-order reason and expected end date;
  - `out_of_order_overdue` (expected end date earlier than hotel today).

`building` is a filter only if the column exists. D6 (`rooms.building`) was assigned to
SPEC-003 and is not in the code, so this feature adds it as a nullable string with the
status split (plan, migration 2). It is a small part of SPEC-003 that the board needs.

## R15. Permissions

**Decision**: Add these cases to `Permission`, grouped with `ROOMS_*`:

- `ROOMS_UPDATE_HOUSEKEEPING_STATUS = 'rooms.update_housekeeping_status'`
- `ROOMS_SET_OUT_OF_ORDER = 'rooms.set_out_of_order'`

Neither is added to `employeeDefaults()` (FR-033). `RoomPolicy` gains
`updateHousekeepingStatus` and `setOutOfOrder` through `allows()`.

Existing permissions cover the rest:

| Action | Permission |
| --- | --- |
| inspection, issue report | `tasks.update` on the source task (`TaskPolicy::update`) |
| board | `rooms.view` |
| maintenance list | `tasks.view` |

The inspection setting and the default team and category settings live on the hotel, and
hotel settings are admin-only (CLAUDE.md rule 4). Both new permissions get rows in the
`PermissionAuthorizationTest` dataset.

## R16. Check-out and room release use the service

**Decision**:

- `StayLifecycleService::checkOut` replaces its inline "set dirty unless blocked" and
  `cleaningTask()` with `HousekeepingService::roomVacated($room, $stay)`. That call sets
  the room `dirty` and calls `ensureCleaningTask(reason: check_out)`. If a stay-over task
  is open, it is reused and its `cleaning_reason` becomes `check_out` (spec edge case 1).
  The response's `cleaning_tasks` stays the same shape.
- `ReservationCreator`'s release path (line ~476, "dirty unless blocked") calls the same
  service.
- `StayLifecycleService::checkIn`'s warning uses `isReady()` (R6).

## R17. AI

**Decision**: No new AI tools. Admin AI housekeeping tools come in Phase 9 (SPEC-055).
Existing tools are updated:

- `CreateRoomTool`: drops `status` (R2).
- `GetRoomsTool`: new value descriptions, plus `ready` and the out-of-order reason.
- `CreateTaskTool`, `CreateGuestServiceRequestTool`, `EscalateToHumanTool`: after create,
  they call `HousekeepingService::taskCreated` and the notification service. The team
  comes from the category (R9).

A concierge "clean my room" request in the hotel's cleaning category therefore follows
FR-007: it reuses an open cleaning task instead of duplicating it, and the tool tells the
guest a clean is already scheduled.

## R18. Migration order and data safety

**Decision**: There are five migrations, dated `2026_10_04_00000N`:

| # | Migration | What it does |
| --- | --- | --- |
| 1 | `add_out_of_order_fields_to_rooms` | R10 columns |
| 2 | `split_room_statuses` | R1 data mapping, check constraints, `rooms.building`, `rooms.housekeeping_status_changed_at` (backfilled from `updated_at`) |
| 3 | `add_operational_defaults_to_hotels` | new setting FKs, `inspection_required`, `inspection_required_since` |
| 4 | `add_housekeeping_fields_to_tasks` | `housekeeping_kind`, `cleaning_reason`, `inspection_result`, `inspection_note`, `source_task_id`, partial unique index, `task_notification_receipts`, `housekeeping_day_runs` |
| 5 | `provision_operational_defaults` | runs `HotelOperationalDefaults::ensure` for each hotel; backfills `housekeeping_kind` on existing open tasks in the hotel's cleaning category |

Migration 5 has to handle existing duplicates before it backfills. If more than one open
cleaning task exists for a room, the newest keeps the kind and the older ones stay
unclassified. They are listed in the release report, so the R4 index can't fail.

Migrations 2 and 5 follow SPEC-025's R22: they check before writing, and they log the
report.

## R19. Release report

**Decision**: Data migrations collect conflicts (rooms left `occupied` instead of
`out_of_order`, older duplicate cleaning tasks). For each conflict they:

- write a `Log::warning` with the ids;
- print it to the console;
- record an `EventLog` entry `room.status_migration_conflict` with `cause = migration` on
  the room, so the hotel can see it in its audit trail.

That covers "listed in the release report" (FR-038, SC-010) without a new table.

## R20. Concurrency test strategy

**Decision**: Follow `CheckInOutConcurrencyTest`, which uses `DatabaseTruncation` and a
second connection. The test cases are:

- two starts of the same cleaning task;
- check-out against take-out-of-order on the same room;
- two concurrent start-of-day runs for one hotel;
- a duplicated issue report.

Each one asserts one state change, one audit row and one task.
