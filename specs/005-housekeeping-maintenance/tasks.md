---

description: "Task list for Phase 5 — Housekeeping and Maintenance (SPEC-003/004 parts, SPEC-030/033/035)"
---

# Tasks: Housekeeping and Maintenance

**Input**: Design documents from `specs/005-housekeeping-maintenance/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/housekeeping-maintenance-api.md](contracts/housekeeping-maintenance-api.md), [quickstart.md](quickstart.md)

**Tests**: Included. Constitution principle VIII and CLAUDE.md require:
- Pest coverage for every story;
- a tenant-isolation test;
- an allowed/403 test for every new permission.

Tests run against Postgres (`Hospitality_Ecosystem_testing`), never SQLite.

**Branch**: Work stays on the current branch. The user asked not to create one.

**Organization**: Tasks are grouped by user story from spec.md.
- **US9** (default teams and the status split) blocks every other story, so its work is in
  Phase 2 (Foundational) and has no story label there.
- **P1 stories**: US1, US4, US5. **P2 stories**: US2, US3, US6, US7. **P3 story**: US8.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel: different files, and no dependency on an unfinished task.
- **[Story]**: US1–US8 from spec.md.
- Paths are repository-relative (single Laravel app).

## Conventions every task follows

- **Controllers**: authorize, validate, delegate, then return `apiResponse()`. Response
  bodies go through a Resource.
- **Writers**: `HousekeepingService` is the **only** writer of `rooms.housekeeping_status`
  (R3). `MaintenanceService` is the only writer of `rooms.status = out_of_order` and of the
  `out_of_order_*` columns.
  - Write rooms with `$room->update()` so `RecordsEvents` fires. Never use a query-builder
    `update()`.
- **Lock order** (R3): always the room row first (`lockForUpdate()`), then the task row.
  Re-read state after locking. Every multi-record action runs inside `DB::transaction()`.
- **Tenant scoping**: queries in services, jobs, tools and migrations name the hotel
  explicitly, with `withoutGlobalScope('hotel')` where they run without tenant context.
  The job uses `TenantContext::runForHotel()`.
- **Hotel day**: `CarbonImmutable::now($hotel->timezone)->toDateString()`. Reuse
  `AvailabilityService::today()`.
- **Test files**:
  - `uses(RefreshDatabase::class)`, except the concurrency file (`DatabaseTruncation`).
  - `beforeEach` sets `putenv('API_KEY=test-api-key')` and
    `config(['app.api_key' => 'test-api-key'])`. Requests send `X-API-KEY`.
  - Build models with `Model::create([...])`. `UserFactory` and `RoomFactory` exist.
  - Reservations go through `ReservationCreator::create()`.
  - Check guests in and out through `StayLifecycleService`.
- **Before any commit**: run `./vendor/bin/pint`.

---

## Phase 1: Setup

**Purpose**: Confirm a green baseline.

- [X] T001 Start Postgres (`docker compose -f .postgres/compose.yaml up -d`), run `php artisan test` on the current branch (do NOT create a branch), and record the pass count as a note under this task in `specs/005-housekeeping-maintenance/tasks.md`
  - Baseline 2026-10-04 on `main` (b922e1a): `php artisan test` → 942 passed.

---

## Phase 2: Foundational (Blocking Prerequisites) — delivers US9

**Purpose**: This phase delivers:
- the status split (SPEC-003, FR-037–039);
- default teams and categories (SPEC-004, FR-040–042);
- the new task, hotel and room columns;
- the enums, permissions and the `HousekeepingService` and `MaintenanceService` skeletons.

When it is done, check-in and check-out behave as before with the new status values, and
the suite is green.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

### Enums and permissions

- [X] T002 [P] Change `RoomStatusesEnum`:
  - Cases become `AVAILABLE = 'available'`, `OCCUPIED = 'occupied'` and `OUT_OF_ORDER = 'out_of_order'`. Remove `MAINTENANCE`.
  - `outOfOrder()` returns `[self::OUT_OF_ORDER]`.
  - Update the docblock to say the split is done.

  File: `app/Enums/RoomStatusesEnum.php`
- [X] T003 [P] Change `HousekeepingStatusesEnum` cases to `DIRTY = 'dirty'`, `CLEANING = 'cleaning'`, `CLEAN = 'clean'` and `INSPECTED = 'inspected'`. Remove `BLOCKED`. Rewrite the docblock: out of order is now a room status, not a housekeeping status. File: `app/Enums/HousekeepingStatusesEnum.php`
- [X] T004 [P] Create four backed enums:
  - `HousekeepingKind`: `cleaning`, `inspection`, in `app/Enums/HousekeepingKind.php`
  - `CleaningReason`: `check_out`, `stay_over`, `re_clean`, `return_to_service`, `manual`, in `app/Enums/CleaningReason.php`
  - `InspectionResult`: `pass`, `fail`, in `app/Enums/InspectionResult.php`
  - `HousekeepingCause`: `task`, `check_out`, `start_of_day`, `inspection`, `issue`, `return_to_service`, `manual`, `migration`, in `app/Enums/HousekeepingCause.php`
- [X] T005 [P] Add `case ROOMS_UPDATE_HOUSEKEEPING_STATUS = 'rooms.update_housekeeping_status';` and `case ROOMS_SET_OUT_OF_ORDER = 'rooms.set_out_of_order';` to the `ROOMS_*` group. Do NOT add either to `employeeDefaults()`. Add one line to its docblock: both change what rooms can be sold, so a hotel grants them on purpose (FR-033). File: `app/Enums/Permission.php`
- [X] T006 [P] Add `updateHousekeepingStatus(User, Room)` → `allows($user, Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS, $room)` and `setOutOfOrder(User, Room)` → `allows($user, Permission::ROOMS_SET_OUT_OF_ORDER, $room)` to `app/Policies/RoomPolicy.php`

### Migrations

- [X] T007 Create `database/migrations/2026_10_04_000001_add_out_of_order_fields_to_rooms_table.php` with these `rooms` columns:
  - `out_of_order_reason` text, nullable
  - `out_of_order_since` timestampTz, nullable
  - `out_of_order_until` date, nullable
  - `out_of_order_by_user_id` uuid, nullable, FK `users` `nullOnDelete`
  - `out_of_order_task_id` uuid, nullable, FK `tasks` `nullOnDelete`

  It runs before T008, because T008 writes `out_of_order_reason` and `out_of_order_since`.
- [X] T008 Create `database/migrations/2026_10_04_000002_split_room_statuses.php` (R1, R18, R19). It does these steps, in order:
  1. Add `rooms.building` (`string(100)`, nullable, after `floor`) and `rooms.housekeeping_status_changed_at` (`timestampTz`, nullable). Backfill the latter from `updated_at`.
  2. `DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_status_check')` and the same for `rooms_housekeeping_status_check`.
  3. Rooms with `status = 'maintenance'` OR `housekeeping_status = 'blocked'`, and NO stay with `status = 'in_house'` and the same `room_id`: set `status = 'out_of_order'`, `housekeeping_status = 'dirty'`, `out_of_order_reason = 'Migrated from previous status'` and `out_of_order_since = now()`.
  4. The same rooms WITH an in-house stay: set `status = 'occupied'`, `housekeeping_status = 'dirty'`, and collect them as conflicts.
  5. Add check constraints `rooms_status_check CHECK (status IN ('available','occupied','out_of_order'))` and `rooms_housekeeping_status_check CHECK (housekeeping_status IN ('dirty','cleaning','clean','inspected'))`.
  6. Report every conflict with `Log::warning`, console output, and an `event_log` row (`event_type = 'room.status_migration_conflict'`, `actor_kind = 'system'`, `changes` holding the old values).

  `down()` maps `out_of_order` → `maintenance`, `cleaning` → `dirty` and `inspected` → `clean`, clears the `out_of_order_*` values, restores the old constraints, and drops the two columns it added.
- [X] T009 Create `database/migrations/2026_10_04_000003_add_operational_defaults_to_hotels_table.php` with these `hotels` columns:
  - `inspection_task_category_id` uuid, nullable, FK `task_categories` `nullOnDelete`
  - `maintenance_team_id` uuid, nullable, FK `teams` `nullOnDelete`
  - `maintenance_task_category_id` uuid, nullable, FK `task_categories` `nullOnDelete`
  - `inspection_required` boolean, default false
  - `inspection_required_since` timestampTz, nullable
- [X] T010 Create `database/migrations/2026_10_04_000004_add_housekeeping_fields_to_tasks_table.php`.

  New `tasks` columns:
  - `housekeeping_kind` string, nullable, `CHECK (housekeeping_kind IN ('cleaning','inspection'))`
  - `cleaning_reason` string, nullable, `CHECK` on the 5 `CleaningReason` values
  - `inspection_result` string, nullable, `CHECK IN ('pass','fail')`
  - `inspection_note` text, nullable
  - `source_task_id` uuid, nullable, FK `tasks` `nullOnDelete`, plus an index on it

  Partial unique index, created with `DB::statement`:
  `CREATE UNIQUE INDEX tasks_one_open_housekeeping_kind_per_room ON tasks (room_id, housekeeping_kind) WHERE housekeeping_kind IS NOT NULL AND status IN ('pending','in_progress') AND deleted_at IS NULL`

  New tables:
  - `task_notification_receipts`: `id` uuid, `task_id` FK cascade, `user_id` FK cascade, `notified_at` timestampTz, unique (`task_id`, `user_id`).
  - `housekeeping_day_runs`: `id` uuid, `hotel_id` FK cascade, `day` date, `rooms_dirtied` int default 0, `tasks_created` int default 0, `created_at`, unique (`hotel_id`, `day`).

### Models

- [X] T011 Update `app/Models/Room.php`:
  - **Casts**: add `'status' => RoomStatusesEnum::class`, `housekeeping_status_changed_at` (datetime) and `out_of_order_since` (datetime), plus `out_of_order_until` (date).
  - **`$fillable`**: add `building`. The `out_of_order_*` columns stay out of `$fillable`; only the services set them, through `forceFill`.
  - **`eventLoggedAttributes`**: add `building`, `out_of_order_reason`, `out_of_order_until` and `out_of_order_task_id`.
  - **`isOutOfOrder()`**: becomes `$this->status === RoomStatusesEnum::OUT_OF_ORDER`.
  - **Audit**: add a public `array $auditExtras = []`, and a `loggedChangeSet()` override that merges `auditExtras` (`cause`, `task_id`, `reason`). Copy the pattern from `app/Models/Stay.php`.
  - **`eventVerbFor('updated')`** (R13):
    - status changed to `out_of_order` → `taken_out_of_order`;
    - status changed from `out_of_order` → `returned_to_service`;
    - `housekeeping_status` changed → `housekeeping_changed`;
    - only `out_of_order_*` changed → `out_of_order_updated`;
    - otherwise `updated`.
  - **Relations**: `outOfOrderBy()` and `outOfOrderTask()`.
- [X] T012 [P] Update `app/Models/Hotel.php`:
  - Add the five new columns to `$fillable` and add casts (`inspection_required` boolean, `inspection_required_since` datetime).
  - Add relations `maintenanceTeam()`, `inspectionTaskCategory()` and `maintenanceTaskCategory()`.
  - In `booted()`, extend the `created` closure to also call `HotelOperationalDefaults::ensure($hotel)` (T016). Comment that `DatabaseSeeder` uses `WithoutModelEvents`, so seeders call `ensure()` themselves (T019).
- [X] T013 [P] Update `app/Models/Task.php`:
  - Add casts for `housekeeping_kind` (`HousekeepingKind`), `cleaning_reason` (`CleaningReason`) and `inspection_result` (`InspectionResult`). Keep these OUT of `$fillable`; the services set them with `forceFill`. Add `source_task_id` and `inspection_note` to `$fillable` only if a controller path writes them; otherwise also force-fill them.
  - Add all five fields to `eventLoggedAttributes()`.
  - Add relations `sourceTask()` and `maintenanceTasks()` (hasMany on `source_task_id`).
  - Add a scope `open()` (status in pending / in_progress).
- [X] T014 [P] Create `app/Models/TaskNotificationReceipt.php` (`HasUuids`, fillable `task_id`, `user_id`, `notified_at`, `$timestamps = false`) and `app/Models/HousekeepingDayRun.php` (`BelongsToHotel`, `HasUuids`, fillable `hotel_id`, `day`, `rooms_dirtied`, `tasks_created`, `UPDATED_AT = null`, `day` cast to `date`)

### Default teams (SPEC-004)

- [X] T015 Extend `app/Support/Housekeeping/HousekeepingDefaults.php` so `for(Hotel)` returns `{housekeepingTeam, cleaningCategory, inspectionCategory, maintenanceTeam, maintenanceCategory}`.
  - Each item is read by id with `withoutGlobalScope('hotel')`, `where('hotel_id', $hotel->id)`.
  - A team must be active; a missing, deleted or inactive item is null.
  - Keep the old `team` and `category` keys as aliases for `StayLifecycleService` until T030 switches it over.
- [X] T016 Create `app/Support/Housekeeping/HotelOperationalDefaults.php` with `ensure(Hotel $hotel): void` (R8). It runs in `DB::transaction` and is idempotent.
  - For each EMPTY setting, create or reuse the item and fill the setting:
    - `housekeeping_team_id` → team "Housekeeping"
    - `cleaning_task_category_id` → category "Cleaning" (Housekeeping team)
    - `inspection_task_category_id` → category "Inspection" (Housekeeping team)
    - `maintenance_team_id` → team "Maintenance"
    - `maintenance_task_category_id` → category "Maintenance" (Maintenance team)
  - **Reuse**: before creating a team, look up `Team::withTrashed()->withoutGlobalScope('hotel')->where('hotel_id', …)->where('name', 'Housekeeping')`. If it is trashed, restore it and set `is_active = true` (`teams` has `unique(hotel_id, name)`). Reuse an existing category of the same name under that team the same way.
  - Never overwrite a filled setting.
  - Save the hotel with `saveQuietly()`, so the `created` hook does not recurse.
- [X] T017 Create `database/migrations/2026_10_04_000005_provision_operational_defaults.php` (R18, R19):
  1. For every hotel (`withoutGlobalScope`, chunked), call `HotelOperationalDefaults::ensure($hotel)`.
  2. Backfill `housekeeping_kind = 'cleaning'` and `cleaning_reason = 'check_out'` on open, non-deleted tasks that have a room and whose `task_category_id` equals the hotel's `cleaning_task_category_id`. Where one room has several such open tasks, classify only the newest (`created_at` desc). Report the older ones as conflicts the same way as T008 (`event_log` `room.status_migration_conflict` with `cause = migration` and the task ids).

  `down()` is a no-op, with a comment.
- [X] T018 Update `app/Http/Controllers/HotelController.php` update validation, which today covers only the housekeeping team and cleaning category (R8). Extend it to:
  - accept `inspection_task_category_id`, `maintenance_team_id`, `maintenance_task_category_id` and `inspection_required`;
  - check the same hotel through `invalidRelation`;
  - require each team to be active;
  - require the inspection category to belong to the housekeeping team;
  - require the maintenance category to belong to the maintenance team.

  When `inspection_required` changes from false to true, set `inspection_required_since = now()`. Expose the new fields in `app/Http/Resources/HotelResource.php`.
- [X] T019 [P] In `database/seeders/` (every seeder that creates hotels while model events are off), call `HotelOperationalDefaults::ensure($hotel)`. Replace any seeded `maintenance` or `blocked` status with `out_of_order` (and the out-of-order fields) or with `dirty`.

### Status split: callers

- [X] T020 Update every comparison and write that used the old values (R1). Grep `RoomStatusesEnum`, `HousekeepingStatusesEnum`, `'maintenance'` and `'blocked'` in `app/`:
  - **`app/Support/Reservations/ReservationCreator.php`**:
    - `syncRoomStatus` compares enums now that `status` is cast.
    - It must NEVER change an `out_of_order` room.
    - The release path that sets dirty unless blocked stays inline for now, rewritten as "unless out of order"; T031 moves it to the service.
  - **`app/Services/StayLifecycleService.php`**: line ~306, "unless BLOCKED" → "unless out of order". Line ~156, the warning still compares to `CLEAN`; T053 changes it.
  - **`app/Http/Controllers/DashboardController.php`**: compare `status` to the enum.
  - **`app/Jobs/MakeRoomDirtyOvernightJob.php`**: exclude `status = out_of_order` instead of `BLOCKED`. The job is replaced in US3.
- [X] T021 Update `app/Http/Controllers/RoomController.php` `store` and `update` (R2):
  - If the request has `status` or `housekeeping_status`, return `apiResponse(…, 422)` naming the new actions and values: "Room status is set by check-in/check-out and by POST /room/{id}/out-of-order and /return-to-service; housekeeping status by tasks or PUT /room/{id}/housekeeping-status." Check this BEFORE `GenericStoreRequest`/`GenericUpdateRequest` validation messages, or strip both fields from the generic rules for `Room`.
  - New rooms are created with `status = available`, `housekeeping_status = clean` and `housekeeping_status_changed_at = now()`.
  - `building` is accepted.

  Update `app/Http/Resources/RoomResource.php` with `building`, `housekeeping_status_changed_at`, `ready` (call `HousekeepingService::isReady`, T026) and the `out_of_order` object (contract: reason, since, expected_end_date, overdue, by_user, task_id; null when the room is not out of order).
- [X] T022 [P] Update the AI room tools:
  - `app/Ai/Tools/CreateRoomTool.php`: remove the `status` schema parameter and the enum read. Create the room as `available` + `clean`.
  - `app/Ai/Tools/GetRoomsTool.php`: the description lists `available/occupied/out_of_order` and `dirty/cleaning/clean/inspected`. Each room includes `ready` and `out_of_order_reason`.
- [X] T023 [P] Add `outOfOrder()` (`status = out_of_order`, reason "Test", since now) and `dirty()` states to `database/factories/RoomFactory.php`. The defaults stay `available` + `clean`.

### Service skeletons

- [X] T024 Create `app/Services/HousekeepingService.php`, constructor-injected, with these private helpers (R3):
  - `lockRoom(string $roomId): Room`: `withoutGlobalScope('hotel')->lockForUpdate()->findOrFail`.
  - `setStatus(Room $room, HousekeepingStatusesEnum $to, HousekeepingCause $cause, ?Task $task = null, ?string $reason = null): bool`:
    - Returns false and writes nothing when the status is unchanged.
    - Otherwise sets `auditExtras = ['cause' => …, 'task_id' => …, 'reason' => …]`, then `$room->forceFill(['housekeeping_status' => $to, 'housekeeping_status_changed_at' => now()])->save()`, then clears `auditExtras` in a `finally`.
  - `kindFor(Hotel $hotel, ?string $categoryId): ?HousekeepingKind`: compares with `cleaning_task_category_id` and `inspection_task_category_id`.

  Leave the public methods for the story phases.
- [X] T025 Create `app/Services/MaintenanceService.php`, constructor-injected with `HousekeepingService`. It has an empty body; the story phases add its methods.
- [X] T026 Add `public function isReady(Room $room, ?Hotel $hotel = null): bool` to `HousekeepingService` (R6, FR-006). A room is ready when either holds:
  - its status is `inspected`;
  - its status is `clean`, and either `! $hotel->inspection_required` or `housekeeping_status_changed_at < inspection_required_since`.

  Load the hotel when it isn't passed.

### Foundational tests (US9 acceptance)

- [X] T027 [P] Create `tests/Feature/RoomStatusSplitTest.php`. Run the T008 migration logic by seeding raw rows before re-running the migration's `up()` on a fresh table state, or by calling an extracted static mapper. It covers:
  - `maintenance` → `out_of_order` + `dirty` with the migration reason;
  - `blocked` → same;
  - `blocked` with an in-house stay → `occupied` + `dirty`, plus a `room.status_migration_conflict` event;
  - other values unchanged;
  - the check constraint rejects `maintenance` on insert;
  - `POST /api/room` with `status` → 422 naming `/out-of-order`;
  - `PUT /api/room/{id}` with `housekeeping_status=blocked` → 422;
  - a new room starts `available` + `clean`;
  - `building` is saved and returned.
- [X] T028 [P] Create `tests/Feature/OperationalDefaultsTest.php`:
  - Creating a hotel through `POST /api/hotel` yields both teams, all three categories and all five settings.
  - A hotel whose admin set `housekeeping_team_id` keeps it, and only the missing defaults are created.
  - Running `ensure()` twice creates nothing new.
  - An existing soft-deleted "Housekeeping" team is restored and reused, not duplicated.
  - A renamed default team still receives a check-out cleaning task.
  - `PUT /api/hotel/{id}` rejects a maintenance category under the housekeeping team (422) and another hotel's team (403).
  - Turning `inspection_required` on sets `inspection_required_since`.
- [X] T029 Update the existing tests to the new values: `tests/Feature/RoomControllerTest.php`, `HousekeepingOvernightTest.php`, `CheckInTest.php`, `CheckOutTest.php`, `MultiRoomCheckInOutTest.php`, `ReservationRoomOccupancyTest.php`, `ReservationStatusDeprecationTest.php`, `StayListTest.php`, `AvailabilityServiceTest.php`, `AvailabilityControllerTest.php` and `AvailabilityToolsTest.php`.
  - `maintenance` → `out_of_order` (set through the factory state or `forceFill`).
  - `blocked` → `out_of_order`.
  - Tests that set statuses through `PUT /room` now use `forceFill`.

  Then run `php artisan test`: the suite must be green.

**Checkpoint**: US9 is delivered. The statuses are split, every hotel has defaults, and
the suite is green.

---

## Phase 3: User Story 1 — A room becomes ready again as housekeeping cleans it (Priority: P1) 🎯 MVP

**Goal**: Cleaning tasks drive the room dirty → cleaning → clean. Check-out reuses the
service. There is never more than one open cleaning task per room.

**Independent Test**: Check a guest out, start and then complete the cleaning task, and
confirm the room goes dirty → cleaning → clean with one audit entry each.

### Tests for User Story 1

- [X] T030 [P] [US1] Create `tests/Feature/HousekeepingLifecycleTest.php`. It covers:
  - start → `cleaning`; complete → `clean`;
  - cancel or delete an in-progress task → `dirty`;
  - completing twice → one status change and one `room.housekeeping_changed` event with `cause = task` and `task_id`;
  - an occupied room completes → `clean`, and the status stays `occupied`;
  - a second cleaning task for the same room through `POST /api/task` → 422 naming the existing task;
  - reopening a completed task → `cleaning` / `dirty`;
  - a stale task whose room already has a newer open cleaning task does NOT move the room (FR-008);
  - a task in a non-cleaning Housekeeping category (create "Turndown") never moves the room;
  - moving a task's category into the cleaning category sets `housekeeping_kind` and only affects later changes;
  - check-out with an open stay-over task reuses it and sets `cleaning_reason = check_out`, and the check-out response lists it;
  - `POST /api/task` with a category and no team → the team is the category's team (R9).

### Implementation for User Story 1

- [X] T031 [US1] In `app/Services/HousekeepingService.php`, implement `ensureCleaningTask(Room $room, CleaningReason $reason, ?Stay $stay = null, ?CarbonInterface $due = null): Task` (FR-007, FR-014). The caller has already locked the room.
  - Look for an open `cleaning` task for the room. If one exists:
    - if `$reason === CHECK_OUT` and it is a `stay_over` task, `forceFill(['cleaning_reason' => CHECK_OUT])`;
    - return it.
  - Otherwise create one with:
    - `HousekeepingDefaults` team and cleaning category;
    - the room, plus the stay, reservation and guest when given;
    - title per reason, e.g. "Clean room {n} after check-out", "Stay-over clean room {n}", "Re-clean room {n}", "Clean room {n} after repair";
    - `created_by = SYSTEM`, `status = PENDING`, `priority = NORMAL`, `due_date = $due`;
    - `housekeeping_kind = cleaning` and `cleaning_reason` via `forceFill`.

  Call the notification hook from T061 once US6 exists; leave a `// US6` marker until then.
- [X] T032 [US1] In `HousekeepingService`, implement `roomVacated(Room $room, Stay $stay): Task`: unless the room is out of order, `setStatus(DIRTY, CHECK_OUT)`, then return `ensureCleaningTask($room, CHECK_OUT, $stay)`. Also implement `roomNeedsCleaning(Room $room, CleaningReason $reason, HousekeepingCause $cause): Task`, which does `setStatus(DIRTY, $cause)` and then `ensureCleaningTask`.
- [X] T033 [US1] In `HousekeepingService`, implement `taskCreated(Task $task): void` and `taskChanged(Task $task, array $before): void`. `$before` holds the original `status`, `task_category_id` and `room_id`. Both run in `DB::transaction` and lock the room, then the task. Rules (R4, R5):
  - Set `housekeeping_kind` from `kindFor()` on create, and whenever `task_category_id` changed. A staff-created cleaning task gets `cleaning_reason = manual`.
  - If an open task of the same kind already exists for the room, throw a `ValidationException` naming its id (FR-007).
  - Then apply the R5 transition table, guarded by the FR-008 "current task" rule. Do nothing when the status didn't change.

  Also implement `taskRemoved(Task $task): void`, which behaves like cancel.
- [X] T034 [US1] Update `app/Http/Controllers/TaskController.php`:
  - **Team from category (R9)**: in `store` and `update`, when `task_category_id` is set and no team is given (or the task has none), fill `assigned_to_team_id` from the category's team BEFORE `taskCategoryBelongsToTeam`.
  - **Hooks**: wrap create/update in `DB::transaction` and call `HousekeepingService::taskCreated` / `taskChanged` (pass `$task->getOriginal()` captured before `update`). `destroy` calls `taskRemoved` before `delete()`.
  - **Errors**: `ValidationException` from the service → 422.
- [X] T035 [P] [US1] In `app/Ai/Tools/CreateTaskTool.php`, `app/Ai/Tools/CreateGuestServiceRequestTool.php` and `app/Ai/Tools/EscalateToHumanTool.php`, apply the R9 team-from-category rule and call `HousekeepingService::taskCreated($task)` after `Task::create`.
  - In `CreateGuestServiceRequestTool`, if the guest's room already has an open cleaning task and the request is in the cleaning category, return that task instead of creating one. Its text tells the guest a clean is already scheduled (R17).
- [X] T036 [US1] Replace `StayLifecycleService::cleaningTask()` and the inline dirty-marking in `app/Services/StayLifecycleService.php` check-out with `$this->housekeeping->roomVacated($room, $stay)` (inject `HousekeepingService`). The `cleaning_tasks` response shape is unchanged. Replace the dirty-marking in `ReservationCreator`'s release path in `app/Support/Reservations/ReservationCreator.php` with `HousekeepingService::roomNeedsCleaning(…, CleaningReason::CHECK_OUT, HousekeepingCause::CHECK_OUT)`, resolving the service from the container.
- [X] T037 [US1] Add `housekeeping_kind`, `cleaning_reason`, `inspection_result`, `inspection_note` and `source_task_id` to `app/Http/Resources/TaskResource.php`

**Checkpoint**: Cleaning tasks drive room status, and check-out uses the service.

---

## Phase 4: User Story 4 — A housekeeper reports a room issue (Priority: P1)

**Goal**: A report on a housekeeping task creates a maintenance task for the Maintenance
team. It can also take the room out of order.

**Independent Test**: Report an issue with `room_unsellable=true` and confirm a linked
maintenance task, an out-of-order room, and availability one lower.

**Depends on**: `MaintenanceService::takeOutOfOrder` (T043, US5). Implement T043 first, or
deliver US5 before US4 if working alone.

### Tests for User Story 4

- [X] T038 [P] [US4] Create `tests/Feature/RoomIssueReportTest.php`. It covers:
  - a report on an open cleaning task → 201, with a maintenance task in the hotel's maintenance category and team, the same room, `source_task_id`, and `created_by_user_id` = the reporter;
  - `room_unsellable` with `rooms.set_out_of_order` → the room is `out_of_order` with the description as the reason and `out_of_order_task_id` set;
  - an employee with `tasks.update` only → the task is created, `out_of_order.applied=false`, and the reason mentions permission;
  - an occupied room → the task is created, not applied, and the reason mentions the in-house guest;
  - the same report twice → 200 with `created:false`, and one task exists (FR-025);
  - a source task completed yesterday → 422; a task that is not a housekeeping task → 422; a task with no room → 422;
  - the maintenance hotel default is missing → the task is still created, unassigned.

### Implementation for User Story 4

- [X] T039 [US4] In `app/Services/MaintenanceService.php`, implement `reportIssue(Task $source, User $reporter, string $description, Priority $priority, bool $roomUnsellable): array{task: Task, created: bool, out_of_order: array{requested: bool, applied: bool, reason: ?string}}` (R11, FR-023–025). It runs in `DB::transaction`:
  1. Lock the room, then the source task.
  2. **Duplicates**: look for an open task with this `source_task_id` whose `lower(trim(description))` matches. If found, return it with `created=false`.
  3. **Create**: the task gets:
     - the maintenance category and team from `HousekeepingDefaults`;
     - the room, stay and reservation from the source task;
     - `source_task_id` (forceFill);
     - `created_by_user_id = $reporter->id`;
     - `created_by`: `CreatedBy::AI` when `EventLogger::currentActorKind()` is `ai_agent`, otherwise the staff value used by `TaskController`;
     - title `"Room {n}: " . Str::limit($description, 60)`, and the description.
  4. **Out of order**: if `$roomUnsellable` and `Gate::forUser($reporter)->allows('setOutOfOrder', $room)` and there is no in-house stay, call `takeOutOfOrder($room, $reporter, $description, null, $task)`. Otherwise record why it wasn't applied.
- [X] T040 [P] [US4] Create `app/Http/Requests/ReportIssueRequest.php`: `description` required string max:2000, `priority` nullable `Rule::enum(Priority::class)`, `room_unsellable` boolean (default false)
- [X] T041 [US4] Create `app/Http/Controllers/TaskIssueController.php` with `store(ReportIssueRequest, Task $task)`.
  - Call `$this->authorize('update', $task)`.
  - Validate the source task: it has a `housekeeping_kind`, or its category's team is the hotel's housekeeping team; it has `room_id`; and its status is open, or completed with `updated_at` on the hotel's today. Otherwise return 422.
  - Delegate to `MaintenanceService::reportIssue`.
  - Return `apiResponse(…, created ? 201 : 200, ['maintenance_task' => TaskResource, 'created' => …, 'out_of_order' => …])`.

  Register `Route::post('/task/{task}/issues', …)` in the tenant group of `routes/api.php`.

**Checkpoint**: Housekeeping-found faults reach maintenance automatically.

---

## Phase 5: User Story 5 — Staff take a room out of order and return it to service (Priority: P1)

**Goal**: Out of order with a reason and an information-only end date. It is refused for
occupied rooms and returns the affected lines. Return to service makes the room
available + dirty with a cleaning task.

**Independent Test**: Take a room out of order (availability drops), complete its
maintenance task (still out of order), then return it (available, dirty, cleaning task).

### Tests for User Story 5

- [X] T042 [P] [US5] Create `tests/Feature/OutOfOrderTest.php`. It covers:
  - `POST /api/room/{id}/out-of-order` → `out_of_order` with the reason, since, until, by and task stored, plus a `room.taken_out_of_order` event;
  - `GET /api/availability` for the type is one lower at once;
  - an occupied room → 422 naming the stay;
  - a room assigned to a future line → 200 with `affected_lines` containing that line;
  - doing it twice → `changed:false` and one event;
  - `expected_end_date` in the past → 422;
  - `task_id` from another hotel → 403;
  - `PATCH` updates the reason and until (`room.out_of_order_updated`); `PATCH` on a room that isn't out of order → 422;
  - completing the linked maintenance task through `PUT /api/task` → the room stays out of order and the response has `room_ready_to_return: true`;
  - `POST /return-to-service` → `available` + `dirty`, one `return_to_service` cleaning task, the out-of-order fields cleared, and a `room.returned_to_service` event;
  - returning a room that isn't out of order → 422;
  - check-in into an out-of-order room → still rejected;
  - check-out never flips an `out_of_order` room to `available` (`syncRoomStatus` guard);
  - `expected_end_date` passing does not change availability.

### Implementation for User Story 5

- [X] T043 [US5] In `app/Services/MaintenanceService.php`, implement `takeOutOfOrder(Room $room, User $actor, string $reason, ?string $expectedEndDate, ?Task $task): array{room: Room, changed: bool, affected_lines: Collection}` (R10, FR-016–018, FR-021). It runs in `DB::transaction` and locks the room:
  - **Already out of order**: return `changed=false`.
  - **In-house stay** (`Stay::withoutGlobalScope('hotel')->where('room_id')->where('status', IN_HOUSE)`): throw a `ValidationException` with "Room {n} has an in-house guest (stay {id}). Move the guest first."
  - **Otherwise**: `forceFill` `status = OUT_OF_ORDER`, `out_of_order_reason`, `out_of_order_since = now()`, `out_of_order_until`, `out_of_order_by_user_id` and `out_of_order_task_id`, then `save()`.
  - **`affected_lines`**: `ReservationRoom` rows on this room whose status is not cancelled, joined to reservations not cancelled or checked out, with `departure_date > hotel today`.

  Also implement `updateOutOfOrder(Room, ?string $reason, ?string $until)`, which throws 422 when the room is not out of order.
- [X] T044 [US5] In `MaintenanceService`, implement `returnToService(Room $room, ?string $note): array{room: Room, cleaning_task: Task}`. It runs in `DB::transaction` and locks the room.
  - If the room is not out of order, throw 422.
  - Set `auditExtras['reason'] = $note`, `forceFill` `status = AVAILABLE` with all five `out_of_order_*` set to null, and `save()`.
  - Then `HousekeepingService::roomNeedsCleaning($room, CleaningReason::RETURN_TO_SERVICE, HousekeepingCause::RETURN_TO_SERVICE)`.
- [X] T045 [P] [US5] Create two requests:
  - `app/Http/Requests/OutOfOrderRequest.php`:
    - POST: `reason` required string max:500, `expected_end_date` nullable `date_format:Y-m-d` and not before the hotel's today (closure rule), `task_id` nullable uuid exists:tasks.
    - PATCH: `reason` sometimes string max:500, `expected_end_date` sometimes nullable date, and at least one of them present.
  - `app/Http/Requests/ReturnToServiceRequest.php`: `note` nullable string max:500.
- [X] T046 [US5] Create `app/Http/Controllers/RoomOutOfOrderController.php` with `store`, `update` and `returnToService`. Each calls `$this->authorize('setOutOfOrder', $room)` and checks `invalidRelation($room->hotel, ['tasks' => task_id])` (403). It delegates to `MaintenanceService` and responds as the contract says. Register `POST`/`PATCH /room/{room}/out-of-order` and `POST /room/{room}/return-to-service` in `routes/api.php`.
- [X] T047 [US5] In `TaskController::update` (`app/Http/Controllers/TaskController.php`), after a task becomes `completed`: if a room with `out_of_order_task_id = $task->id` is still out of order, add `room_ready_to_return: true` to the response body (FR-019). Use `TaskResource::make($task)->additional([...])` or wrap it in an array.

**Checkpoint**: Out of order is fully managed and reflected in availability.

---

## Phase 6: User Story 2 — A supervisor inspects cleaned rooms (Priority: P2)

**Goal**: With inspection on, a completed clean creates an inspection task. A pass gives
`inspected`; a fail gives `dirty` plus a re-clean task.

**Independent Test**: Turn inspection on, complete a clean, pass the inspection (the room
is inspected). In a second run, fail it (dirty, and a re-clean task with the note).

### Tests for User Story 2

- [X] T048 [P] [US2] Create `tests/Feature/HousekeepingInspectionTest.php`. It covers:
  - inspection on: completing a clean → `clean`, `ready=false`, one inspection task in the inspection category for the Housekeeping team;
  - `POST /api/task/{id}/inspection` `pass` → `inspected`, `ready=true`;
  - `fail` without a note → 422;
  - `fail` with a note → `dirty`, a `re_clean` task whose description contains the note, `inspection_result=fail` and the note stored;
  - `PUT /api/task` with `status=completed` on an inspection task → 422;
  - cancelling an inspection task → the room stays `clean`;
  - inspection off → no inspection task, and `ready=true` at `clean`;
  - turning inspection off while a task is open → the task can still be passed;
  - a room cleaned before inspection was turned on still counts as ready;
  - passing twice → no second change;
  - check-in into a clean-but-not-inspected room returns a "not ready" warning.

### Implementation for User Story 2

- [X] T049 [US2] In `HousekeepingService::taskChanged` (`app/Services/HousekeepingService.php`), when a cleaning task becomes `completed` and `$hotel->inspection_required`, call `ensureInspectionTask(Room $room, Task $cleaning)`.
  - Create it only if no inspection task is open (FR-007).
  - It uses the inspection category and the housekeeping team; title "Inspect room {n}"; `housekeeping_kind = inspection`.
- [X] T050 [US2] In `HousekeepingService`, implement `recordInspection(Task $task, InspectionResult $result, ?string $note): array{task: Task, room: Room, cleaning_task: ?Task}` (FR-005). It runs in a transaction and locks the room, then the task.
  - **Wrong task**: if the task is not an open inspection task, throw 422. If it is already completed with the same result, return it unchanged.
  - **Always**: `forceFill` `status = completed`, `inspection_result` and `inspection_note`.
  - **pass**: `setStatus(INSPECTED, INSPECTION, $task)`.
  - **fail**: `setStatus(DIRTY, INSPECTION, $task, $note)`, then `ensureCleaningTask($room, RE_CLEAN)` with the description `"Failed inspection: {$note}"`.

  In `taskChanged`, reject `status → completed` on an inspection task with 422 "Use the inspection action".
- [X] T051 [P] [US2] Create `app/Http/Requests/InspectionRequest.php`: `result` required `Rule::enum(InspectionResult::class)`, `note` `required_if:result,fail` nullable string max:2000
- [X] T052 [US2] Create `app/Http/Controllers/TaskInspectionController.php` `store(InspectionRequest, Task $task)`: `$this->authorize('update', $task)`, delegate to `recordInspection`, return `{task, room, cleaning_task}`. Register `POST /task/{task}/inspection` in `routes/api.php`.
- [X] T053 [US2] In `app/Services/StayLifecycleService.php` check-in, change the warning condition from `!== CLEAN` to `! $this->housekeeping->isReady($room, $hotel)`, with the message "Room {n} is not ready ({status})."

**Checkpoint**: Inspection works per hotel.

---

## Phase 7: User Story 3 — Rooms with guests staying on get a daily cleaning task (Priority: P2)

**Goal**: An hourly, per-time-zone, idempotent start-of-day job dirties stay-over rooms
and creates one `stay_over` cleaning task each.

**Independent Test**: With a guest in-house for 3 nights, run the job: the room is dirty
with one stay-over task. Run it again: nothing changes.

### Tests for User Story 3

- [X] T054 [P] [US3] Create `tests/Feature/HousekeepingStartOfDayTest.php`, replacing `tests/Feature/HousekeepingOvernightTest.php`. Port its still-valid cases, then delete the old file. It covers:
  - a stay-over room → `dirty`, one `stay_over` task due by the end of the hotel day, a `room.housekeeping_changed` event with `cause = start_of_day` and `actor_kind = system`;
  - a room departing today → no task;
  - an expected or departed stay → nothing;
  - an out-of-order room → unchanged;
  - an existing open cleaning task → no second task;
  - running twice the same day → one `housekeeping_day_runs` row and no new tasks;
  - two hotels in `Asia/Dubai` and `America/New_York` at a fixed `Carbon::setTestNow` → each uses its own local date;
  - another hotel's rooms are untouched by the run for this hotel;
  - an inactive hotel is skipped.

### Implementation for User Story 3

- [X] T055 [US3] In `HousekeepingService`, implement `startDay(Hotel $hotel, string $day): HousekeepingDayRun|null` (R7, FR-012–014). It runs in one `DB::transaction`:
  1. `insertOrIgnore` into `housekeeping_day_runs` (`hotel_id`, `day`). If 0 rows were inserted, return null.
  2. Load the stay-over rooms with `withoutGlobalScope`: in-house stays of this hotel with `departure_date > $day`, rooms not `out_of_order`, ordered by room id.
  3. For each room: lock it; `setStatus(DIRTY, START_OF_DAY)` unless it is `dirty` or `cleaning`; then `ensureCleaningTask($room, STAY_OVER, $stay, due: end of $day in the hotel's timezone)`. Count what changed.
  4. Update the run row's counts.

  Check the actual stay `departure_date` column name in `app/Models/Stay.php`.
- [X] T056 [US3] Create `app/Jobs/StartHousekeepingDayJob.php` (`ShouldQueue`). For each active hotel (`Hotel::withoutGlobalScope…->where('is_active', true)`, chunked):
  - compute the local date;
  - `TenantContext::runForHotel($hotel, fn () => $service->startDay($hotel, $day))`;
  - catch and `report()` a failure per hotel, so other hotels still run.

  Delete `app/Jobs/MakeRoomDirtyOvernightJob.php`. In `routes/console.php`, replace its schedule entry with `Schedule::job(new StartHousekeepingDayJob)->hourly();` and a comment explaining the per-time-zone run (R7).

**Checkpoint**: Daily stay-over cleaning is automatic.

---

## Phase 8: User Story 6 — Housekeeping and maintenance staff are told about their tasks (Priority: P2)

**Goal**: The assignee, or the members of the Housekeeping or Maintenance team, get one
notice per assignment. Admins are alerted when a task can't be routed.

**Independent Test**: Check a guest out: each housekeeper gets one email. Assign the task
to one of them: no new email for them. Reassign it to someone else: one email to the new
person.

### Tests for User Story 6

- [X] T057 [P] [US6] Create `tests/Feature/TaskNotificationTest.php` using `Notification::fake()`. It covers:
  - a check-out cleaning task assigned to the Housekeeping team → each member is notified once;
  - a task assigned to a user → only that user;
  - reassigning to another user → the new user once, the old user not again;
  - narrowing team → member → no new notice for that member;
  - re-saving with no assignee change → nothing;
  - a task assigned away and back → notified again;
  - a cancelled or completed task → `shouldSend` returns false;
  - a task assigned to a non-housekeeping team ("Front Office") with no user → no fan-out (today's behavior);
  - a Housekeeping team with no members → admins get one `HousekeepingRoutingNotification`; a second automatic task the same day → no second alert;
  - an issue report → the Maintenance team members are notified;
  - an AI-created task → admins still get `AiTaskCreatedNotification`.

### Implementation for User Story 6

- [X] T058 [P] [US6] Create `app/Notifications/HousekeepingRoutingNotification.php` (mail, `ShouldQueue`, `afterCommit`). It carries the hotel and the cause (`no_team` | `no_members`) and tells admins to set the defaults in hotel settings. Add `shouldSend(object $notifiable, string $channel): bool` to `app/Notifications/TaskAssignedNotification.php`; it returns false when the fresh task's status is `completed` or `cancelled` (FR-028).
- [X] T059 [US6] Rework `app/Services/CreationNotificationService.php` (R12):
  - **`recipients(Task $task): Collection<User>`**: the assigned user; or, if there is none and the team is the hotel's `housekeeping_team_id` or `maintenance_team_id` and is active, `User::where('team_id', …)->where('hotel_id', $task->hotel_id)->get()`.
  - **`notifyRecipients(Task $task, Collection $users)`**: for each user, `TaskNotificationReceipt::insertOrIgnore([...])`. Notify only when 1 row was inserted.
  - **`taskCreated`**: uses them; the `$createdByAi` admin notice is unchanged.
  - **`taskReassigned(Task $task, array $before)`**:
    - delete receipts for users who are no longer recipients;
    - `notifyRecipients` for the new recipients;
    - a user who was a team member and is now the direct assignee keeps their receipt, so they get no new notice.
  - **`routingProblem(Hotel $hotel, string $cause)`**: `Cache::add("hk-routing:{$hotel->id}:{$day}:{$cause}", true, end of hotel day)`; when that returns true, `Notification::send($this->admins(…), new HousekeepingRoutingNotification(…))`.
- [X] T060 [US6] In `app/Http/Controllers/TaskController.php`, call `taskReassigned($task, $before)` after update when `assigned_to_user_id` or `assigned_to_team_id` changed.
- [X] T061 [US6] Wire the notifications into the automatic tasks:
  - `HousekeepingService::ensureCleaningTask` and `ensureInspectionTask`, and `MaintenanceService::reportIssue`, call `CreationNotificationService::taskCreated($task, createdByAi: EventLogger::currentActorKind() === ActorKind::AI_AGENT)` for NEWLY created tasks only. Remove the `// US6` markers.
  - When the resolved team is null, or has no members, call `routingProblem()`.

  Files: `app/Services/HousekeepingService.php`, `app/Services/MaintenanceService.php`

**Checkpoint**: Staff hear about their work once.

---

## Phase 9: User Story 7 — The housekeeping board and maintenance list (Priority: P2)

**Goal**: The board groups rooms by housekeeping status with tasks and departures. The
maintenance list shows open repairs.

**Independent Test**: With rooms in each status and open tasks, the board's counts and
grouping match. Another hotel's data never appears.

### Tests for User Story 7

- [X] T062 [P] [US7] Create `tests/Feature/HousekeepingBoardTest.php`. Board cases:
  - counts per status, including `out_of_order`;
  - each room appears once, with `ready`, `departure_date` for an occupied room, and `open_task`;
  - filters by `housekeeping_status`, `status`, `floor`, `building` and `team_id`;
  - a super admin without `hotel_id` → 422/403 as `resolveHotel()` does;
  - an employee without `rooms.view` → 403;
  - 500 seeded rooms (bulk insert) respond in under 2 s with ≤ 5 queries (`DB::enableQueryLog`) (SC-008).

  Maintenance list cases:
  - lists only tasks in the maintenance team or its categories;
  - `filter[room_out_of_order]=true` works;
  - the default order is open, then urgent, then oldest;
  - rows include `reporter`, `source_task_id`, `age_hours`, and `out_of_order.overdue` for a past `expected_end_date`.

### Implementation for User Story 7

- [X] T063 [P] [US7] Create `app/Http/Requests/HousekeepingBoardRequest.php`: `housekeeping_status` nullable enum, `status` nullable enum, `floor` nullable string, `building` nullable string, `team_id` nullable uuid, `hotel_id` nullable uuid
- [X] T064 [US7] Create `app/Http/Controllers/HousekeepingBoardController.php` `__invoke` (R14):
  - Call `$this->authorize('viewAny', Room::class)` and `resolveHotel()`.
  - Query 1: rooms with `roomType`, filtered, ordered by `building`, `floor`, `room_number`.
  - Query 2: in-house stays for those rooms, keyed by `room_id` (`departure_date`).
  - Query 3: open tasks with a `housekeeping_kind`, keyed by `room_id`; the `team_id` filter applies here.
  - Counts per status come from the room collection, plus `out_of_order`.
  - Return `{date, inspection_required, counts, rooms[]}` through `RoomResource`. Avoid N+1 in `ready`: pass the hotel in.

  Register `GET /housekeeping/board` in `routes/api.php`.
- [X] T065 [US7] Create `app/Http/Controllers/MaintenanceTaskController.php` `index(GenericIndexRequest)`:
  - Call `$this->authorize('viewAny', Task::class)` and `resolveHotel()`.
  - Base query: tasks where `assigned_to_team_id = maintenance_team_id` OR the category's `team_id = maintenance_team_id`, with `room` and `createdByUser`.
  - `filter[room_out_of_order]` → `whereHas('room', status …)`.
  - Default sort: `CASE status` open first, then priority rank (urgent first), then `created_at` asc, when no `sort` is given. Then `GenericQuery::apply`.
  - Extra fields go in the resource or `additional()`: `reporter`, `age_hours`, `room.out_of_order` (with `overdue`).

  Register `GET /maintenance/tasks` in `routes/api.php`. Check that `Task` has a `createdByUser` relation; add one if it doesn't.

**Checkpoint**: Staff can run the day from the board and the list.

---

## Phase 10: User Story 8 — Staff correct a room's housekeeping status by hand (Priority: P3)

**Goal**: A permissioned, audited manual correction that leaves tasks alone.

**Independent Test**: Set a dirty room to clean with a reason. The audit records the
reason, and the open task is listed but unchanged.

### Tests for User Story 8

- [X] T066 [P] [US8] Create `tests/Feature/HousekeepingManualStatusTest.php`:
  - `PUT /api/room/{id}/housekeeping-status` with a reason → changed, plus a `room.housekeeping_changed` event with `cause = manual` and the reason;
  - an open cleaning task is returned in `open_housekeeping_tasks` and unchanged;
  - no reason → 422; an invalid value (`blocked`) → 422;
  - the same status → 200 with no event;
  - an employee without the permission → 403; with the permission → 200.

### Implementation for User Story 8

- [X] T067 [P] [US8] Create `app/Http/Requests/UpdateHousekeepingStatusRequest.php`: `housekeeping_status` required `Rule::enum(HousekeepingStatusesEnum::class)`, `reason` required string max:500
- [X] T068 [US8] Add `setManually(Room $room, HousekeepingStatusesEnum $to, string $reason): array{room: Room, open_tasks: Collection}` to `app/Services/HousekeepingService.php`. It locks the room, calls `setStatus(…, MANUAL, null, $reason)`, and returns the open housekeeping tasks without touching them. Create `app/Http/Controllers/RoomHousekeepingController.php` `update`, which calls `$this->authorize('updateHousekeepingStatus', $room)` and delegates. Register `PUT /room/{room}/housekeeping-status` in `routes/api.php`.

**Checkpoint**: All stories are delivered.

---

## Phase 11: Polish & Cross-Cutting Concerns

- [X] T069 [P] Create `tests/Feature/HousekeepingConcurrencyTest.php` (`DatabaseTruncation`, a second DB connection, following `tests/Feature/CheckInOutConcurrencyTest.php`) (R20, FR-035, SC-003). It covers:
  - two concurrent starts of one cleaning task → one change and one event;
  - check-out against take-out-of-order on the same room → serialized, with no occupied out-of-order room;
  - two concurrent `startDay` calls for one hotel and day → one run row and one task per room;
  - two identical concurrent issue reports → one maintenance task;
  - two concurrent `ensureCleaningTask` calls → one open task (the unique index holds).
- [X] T070 [P] Extend `tests/Feature/TenantIsolationTest.php` (FR-034, SC-009). Hotel B's user gets 404 or 403 on hotel A's:
  - `PUT /room/{id}/housekeeping-status`;
  - `POST`/`PATCH /room/{id}/out-of-order`;
  - `POST /room/{id}/return-to-service`;
  - `POST /task/{id}/inspection` and `POST /task/{id}/issues`.

  In addition:
  - Hotel A's board and maintenance list never include hotel B's rooms or tasks.
  - `startDay` for A never touches B.
  - Hotel settings reject B's team and category ids.
- [X] T071 [P] Add rows to the dataset in `tests/Feature/PermissionAuthorizationTest.php`. Each one is allowed with the permission and gets 403 without it:
  - `rooms.update_housekeeping_status` → `PUT /api/room/{id}/housekeeping-status`;
  - `rooms.set_out_of_order` → `POST /api/room/{id}/out-of-order`, `PATCH /api/room/{id}/out-of-order`, `POST /api/room/{id}/return-to-service`;
  - `rooms.view` → `GET /api/housekeeping/board`;
  - `tasks.view` → `GET /api/maintenance/tasks`;
  - `tasks.update` → `POST /api/task/{id}/inspection`, `POST /api/task/{id}/issues`.

  Assert that neither new permission is in `Permission::employeeDefaults()`.
- [X] T072 [P] Update `docs/room-api-documentation.md`:
  - the new status values;
  - `building`;
  - the 422 on status fields;
  - the `RoomResource` additions;
  - `PUT /room/{id}/housekeeping-status`, out-of-order (POST/PATCH), return-to-service;
  - `GET /housekeeping/board`.

  Follow the contract.
- [X] T073 [P] Update `docs/task-management-api-documentation.md`:
  - the new task fields;
  - team from category;
  - one open cleaning/inspection task per room;
  - the inspection action, issue reports, the maintenance list;
  - notification rules;
  - `room_ready_to_return`.
- [X] T074 [P] Update `docs/hotel-api-documentation.md` (the five default settings, `inspection_required`, provisioning on create) and `docs/stays-api-documentation.md` (the "not ready" warning, stay-over task reuse on check-out)
- [X] T075 [P] Update the permission reference in `docs/staff-roles-api-documentation.md`: two new rows, plus the reused `rooms.view`, `tasks.view` and `tasks.update` endpoints
- [X] T076 [P] Create `docs/latest-changes-2026-10-04.md`.

  Breaking changes:
  - room status and housekeeping status are no longer writable through `POST`/`PUT /room`;
  - the status values changed (`maintenance` → `out_of_order`, `blocked` removed, `cleaning` and `inspected` added);
  - completing an inspection task through `PUT /task` is rejected.

  Behavior changes:
  - team from category;
  - default teams on every hotel;
  - stay-over tasks replace overnight dirtying;
  - reassignment notices and team fan-out.

  Add a frontend checklist for `ecosystem-frontend` (D13).
- [X] T077 Run `./vendor/bin/pint`, then `php artisan test` (the full suite, serially). Record the pass count under this task
  - 2026-10-04: Pint clean; `php artisan test` → 1035 passed (baseline 942)..
- [ ] T078 Walk through [quickstart.md](quickstart.md) steps 1–8 against a local DB, and tick the spec success criteria SC-001 to SC-010 in a note under this task
  - Not done by hand: the dev DB has not been migrated (the 2026-10-04 migrations rewrite room statuses, so run them on a snapshot first). Every quickstart step and SC-001–SC-010 is covered by the automated tests listed in quickstart.md, all green in T077.

**Implementation notes (deviations from the plan, 2026-10-04)**

- `tasks.completed_at` was added (migration 4, stamped by `Task::saving`), so "completed today" for issue reports (FR-022) does not depend on `updated_at` (analysis finding U1).
- `rooms.status` / `housekeeping_status` stay in `Room::$fillable` (many writers and tests use `create()`); the API refuses them through `StoreRoomRequest` / `UpdateRoomRequest` (`ProhibitsRoomStatusFields`) with a message naming the new actions.
- `RoomResource.ready` is computed only where the hotel is handed over (room endpoints, board); it is `null` for rooms nested in stays, tasks and reservations, to keep the 500-room lists at a fixed query count.
- Check-out dirties a room even when it is out of order (housekeeping is independent of room status); the start-of-day job still skips out-of-order rooms.
- Changing the housekeeping or maintenance team clears a stranded inspection/maintenance category instead of refusing; the cleaning category keeps its stricter SPEC-025 rule.
- Existing tests that created teams named "Housekeeping"/"Maintenance" were renamed (those names are now the hotel defaults); `HousekeepingOvernightTest` was replaced by `HousekeepingStartOfDayTest`.

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1** → **Phase 2 (US9)**. Phase 2 blocks every story.
- **US1** (Phase 3) is needed by every later story, because they all create or move
  cleaning tasks through `HousekeepingService`.
- **US5** (Phase 5) is needed by **US4** (Phase 4): issue reports call `takeOutOfOrder`
  (T043). When working alone, do Phase 5 before Phase 4.
- **US2** (inspection), **US3** (start of day) and **US8** (manual) each need only US1.
- **US6** (notifications) needs US1 and touches US4's service; do it after US4.
- **US7** (board and list) needs US1, and US5 for the out-of-order fields.
- **Polish** comes after all the stories.

### Recommended order when working alone

T001 → Phase 2 → US1 → US5 → US4 → US2 → US3 → US6 → US7 → US8 → Polish

### Within each story

Write the tests first, and they should fail. Then requests, then service methods, then
controllers and routes, then integration.

## Parallel Opportunities

- **Phase 2**:
  - T002, T003, T004, T005 and T006 (different files).
  - T012, T013 and T014 after the migrations.
  - T019, T022 and T023.
  - T027 and T028 (tests).
- **US1**: T030 (test) alongside T037. T035 (AI tools) alongside T034 (controller) once
  T033 is done.
- **US5**: T042, T045. **US4**: T038, T040. **US2**: T048, T051. **US6**: T057, T058.
  **US7**: T062, T063. **US8**: T066, T067.
- **Polish**: T069–T076 are all [P].

### Parallel example: User Story 5

```bash
Task: "Create tests/Feature/OutOfOrderTest.php (T042)"
Task: "Create OutOfOrderRequest and ReturnToServiceRequest (T045)"
# then sequentially: T043 → T044 → T046 → T047
```

## Implementation Strategy

### MVP first

1. Phase 1, then Phase 2 (US9: status split and defaults). The suite is green.
2. US1: cleaning tasks drive room readiness. **Stop and validate**: check-out → dirty →
   cleaning → clean.
3. US5 and US4: out of order, and issues reach maintenance. This completes every P1 story.

### Incremental delivery

4. US2 (inspection) → US3 (stay-over) → US6 (notifications) → US7 (board and list) → US8
   (manual correction).
5. Polish: concurrency, isolation, permissions, docs, quickstart.

Each phase ends with `php artisan test` green.

## Notes

- **[P]**: different files, with no dependency on an unfinished task.
- **Branch**: do NOT create a branch (user instruction). Commit only when asked. No
  co-author trailer (CLAUDE.md).
- **Migrations**: never edit a shipped migration. All five are new (`2026_10_04_*`).
