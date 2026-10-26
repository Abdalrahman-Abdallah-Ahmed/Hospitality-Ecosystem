# Tasks: Hardening and Production Readiness

**Input**: Design documents from `specs/017-hardening-production-readiness/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/health-api.md](contracts/health-api.md), [contracts/operations-interfaces.md](contracts/operations-interfaces.md), [quickstart.md](quickstart.md)

**Tests**: Required by the constitution (Principle VIII) and CLAUDE.md. Most of this feature
*is* tests (the audits). Write each story's tests first and see them fail, or see them find
real gaps, before fixing. Run with `php artisan test --parallel`. Every new
`tests/Feature/*Test.php` starts with `uses(RefreshDatabase::class);` and a `beforeEach`
that sets the API key (`putenv('API_KEY=test-api-key')` and
`config(['app.api_key' => 'test-api-key'])`), as CLAUDE.md "Testing" requires.

**MVP scope decisions (spec Clarifications 2026-10-25)**: the queue, cache and locks stay
on Postgres (no Redis, no Horizon); backups are not part of this feature; the deploy stays
in place with one final health-check step (no release directories, no automatic rollback).
Do not add tasks for any of these.

**Before starting**: create branch `017-hardening-production-readiness` from `main`. The
feature directory is already set in `.specify/feature.json`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: the user story the task belongs to (US1–US6)

---

## Phase 1: Setup

**Purpose**: Configuration keys and folders. No behaviour change.

- [X] T001 Add the new environment keys, empty, to `.env.example` with one comment line
  each: `HEALTH_CHECK_TOKEN`, `OPS_ALERT_EMAIL`, `SENTRY_LARAVEL_DSN`,
  `SENTRY_TRACES_SAMPLE_RATE=0.1`, `SENTRY_ENVIRONMENT`, `SENTRY_RELEASE`, `SEED_DEMO=false`.
  Leave `QUEUE_CONNECTION=database` and `CACHE_STORE=database` unchanged. Never copy values
  from `.env` (CLAUDE.md, Secrets).
- [X] T002 [P] Create `config/health.php` returning `['token' => env('HEALTH_CHECK_TOKEN')]`
  and `config/ops.php` returning
  `['alert_email' => env('OPS_ALERT_EMAIL'), 'job_failure_threshold' => 3, 'job_failure_window_minutes' => 15, 'lane_alert_cooldown_minutes' => 15]`
  (research R14, data-model "Alert").
- [X] T003 [P] Create `tests/Inventory/` with `entry_points.php` and `ai_tools.php`, each
  returning an array with the keys listed in data-model.md ("Entry-point inventory":
  `routes`, `jobs`, `scheduled`, `global_data`, `route_exceptions.bounded_list`; "AI tool
  inventory": `unused`, `pii_fields`), all empty for now. Add a header comment saying the
  entry points are discovered by the tests and these files hold only exceptions and
  explicit coverage (research R1).

---

## Phase 2: Foundational (blocks every story)

**Purpose**: Shared test discovery, the shared tenant-model dataset, and the platform alert
used by US3 and US5.

- [X] T004 Move `tenantOwnedModelFactories()` and `makeHotel()` (if defined locally) out of
  `tests/Feature/TenantIsolationTest.php` into `tests/Pest.php`, unchanged, so
  `CrossTenantRouteTest` can reuse them (research R2). `TenantIsolationTest` must still pass.
- [X] T005 Create `tests/Support/EntryPointDiscovery.php` (autoloaded through
  `composer.json` `autoload-dev` → `Tests\\`) with static methods (research R1):
  - `tenantRoutes(): Collection`: every route in `Route::getRoutes()` whose
    `gatherMiddleware()` contains `tenant` or `super_admin`, plus `POST api/whatsapp` and
    `GET api/whatsapp`. Key each as `"METHOD uri"`.
  - `queuedJobs(): array`: every class under `app/Jobs` (recursive) implementing
    `ShouldQueue`.
  - `scheduledEvents(): array`: the job class or command of each `Schedule::events()` entry
    (resolve the schedule with `app(Schedule::class)` after the console kernel boots).
  - `aiTools(): array`: every class under `app/Ai/Tools` implementing
    `Laravel\Ai\Contracts\Tool`, excluding `GuardedTool` and `AuditedTool`.
- [X] T006 Create `app/Notifications/PlatformAlertNotification.php` (data-model "Alert",
  contracts/operations-interfaces.md "Alert mail"). It does **not** implement
  `ShouldQueue` (sent immediately, so a stuck lane cannot hold back its own alert). Its
  constructor takes `string $subject` and `array $lines`, and `toMail()` renders them.
  Never put a job payload, guest phone, email or message text in it.
- [X] T007 Create `app/Support/Observability/PlatformAlerts.php` with
  `send(string $dedupeKey, int $cooldownMinutes, string $subject, array $lines): bool`. It
  uses `Cache::add($dedupeKey, true, now()->addMinutes($cooldownMinutes))`, and only when
  that succeeds sends `PlatformAlertNotification` through
  `Notification::route('mail', config('ops.alert_email'))`. It returns whether it sent. When
  `ops.alert_email` is empty it logs a warning and sends nothing.

**Checkpoint**: the suite is still green.

---

## Phase 3: User Story 1 — Prove no hotel can see another hotel's data (P1) 🎯 MVP

**Goal**: Every entry point (routes, jobs, scheduled tasks, webhook, AI tools) is
discovered and covered by a cross-hotel check; leaks found are fixed (FR-001 – FR-008).

**Independent test**: quickstart §1: the inventory and cross-tenant tests pass, and adding
an uncovered route or job makes `EntryPointInventoryTest` fail with its name.

### Tests

- [X] T008 [US1] Write `tests/Feature/EntryPointInventoryTest.php` (research R1). It uses
  `EntryPointDiscovery` and `tests/Inventory/entry_points.php`:
  - every tenant route is covered by `CrossTenantRouteTest`'s generic rules (export the
    predicate from that file as a function in `tests/Pest.php`, for example
    `crossTenantRuleFor(Route $route): ?string`) or listed under `routes` with a
    `covered_by` test description;
  - every queued job is listed under `jobs` with `scope` `hotel` (+ `covered_by`) or
    `cross_hotel` (+ a non-empty `reason`);
  - every scheduled event is listed under `scheduled`;
  - every `covered_by` names a test that exists: grep `tests/Feature` for
    `it('<description>'`;
  - no inventory key names a route or class that no longer exists.

  Failure messages must name the uncovered entry point. Expect it to fail until T010–T012.
- [X] T009 [US1] Write `tests/Feature/CrossTenantRouteTest.php` with the three generic
  rules of research R2, as datasets built from `EntryPointDiscovery::tenantRoutes()`:
  - **index rule**: a `GET` with no route parameter. Seed every model in
    `tenantOwnedModelFactories()` for Hotel A and Hotel B. Call as a Hotel A admin
    (`adminWithHotel()`, `apiHeaders()`). Assert no Hotel B row id appears anywhere in the
    JSON body (search the raw body string for each Hotel B id).
  - **bound-model rule**: a route with a parameter bound to a tenant model. Create the
    record in Hotel B and call as a Hotel A admin. Assert `404` and that the Hotel B row's
    `updated_at` and `deleted_at` are unchanged.
  - **foreign-key rule**: a `POST/PUT/PATCH` whose FormRequest `rules()` contain a
    `*_id` key for a tenant model. Send Hotel B's id. Assert `404` or `422` and that no row
    was created or changed.

  Routes the rules cannot drive (uploads, multi-step flows, the webhook, `routes/admin.php`
  cross-account reports) are skipped here and listed in the inventory instead (T010).

### Inventory and fixes

- [X] T010 [US1] Fill `tests/Inventory/entry_points.php` `routes` with every route
  `CrossTenantRouteTest` skips, each with `covered_by` set to an existing test in
  `tests/Feature/TenantIsolationTest.php` or another feature test. Where no test exists,
  add one to `TenantIsolationTest.php` (same style as its existing `it('keeps one hotel out
  of …')` tests) and point at it. `routes/admin.php` routes are covered by an existing
  super-admin test, or a new one asserting a non-super-admin gets `403`.
- [X] T011 [US1] Audit each queued job and fill `jobs` in `tests/Inventory/entry_points.php`
  (research R3):
  - `PruneReservationImportPreviewsJob`, `PurgeDeletedKnowledgeDocumentsJob`: `cross_hotel`,
    reason "platform-wide removal of expired data".
  - `RebuildUsageCountersJob`, `FlagAiCostOverrunsJob`: `cross_hotel`, reason "account-level
    (HotelGroup) metering".
  - `GenerateActivityRecommendationsJob`, `RetryGuestMemoryExtractionsJob`,
    `SyncKnowledgeChunksJob`, `SendWhatsAppMessageJob`: read each `handle()`. If it touches
    tenant models for one hotel, wrap the work in `TenantContext::runForHotel($hotelId, …)`
    and mark it `hotel`. If it fans out across hotels, list it as `cross_hotel` with the
    reason, and make it dispatch or run per hotel inside `runForHotel`.
  - Every other job: `hotel`. Each `TenantContext::withoutScope()` call inside a `hotel` job
    (`IndexKnowledgeDocumentJob`, `PrepareReservationImportJob`, `WriteReservationImportJob`)
    gets a one-line comment above it explaining why.
- [X] T012 [US1] Add `tests/Feature/JobTenantIsolationTest.php`. For every job marked
  `hotel`, dispatch it synchronously for Hotel A with Hotel B data present. Record queries
  with `DB::listen` and assert that no Hotel B row was written: compare `updated_at` on
  Hotel B fixtures before and after. Use the existing fakes for AI and WhatsApp (see
  `gsFakeWhatsApp()` and `Ai::fake` usage in the suite). Point each job's `covered_by` at
  its dataset case.
- [X] T013 [US1] Fill `scheduled` (one entry per event in `routes/console.php`, matching the
  `jobs` classification) and `global_data` (global knowledge articles and documents with
  `hotel_id` null, and `AiModelPrice`) in `tests/Inventory/entry_points.php`. Add one test
  in `TenantIsolationTest.php` asserting a hotel admin can read but not update or delete a
  global knowledge article (FR-007).
- [X] T014 [US1] Run the US1 tests. Fix every leak they find in the controller, policy,
  FormRequest (`invalidRelation()`) or job concerned. Add a regression case to the test
  that caught it (FR-008), and list each fix with its file in `docs/latest-changes-<date>.md`
  only if it changes an API response.

**Checkpoint**: `php artisan test --filter='EntryPointInventoryTest|CrossTenantRouteTest|JobTenantIsolationTest|TenantIsolationTest' --parallel`
is green.

---

## Phase 4: User Story 2 — Prove AI cannot act beyond the user's permissions (P1)

**Goal**: Every AI tool is inventoried, every write is guarded or audited, D17 is enforced,
guest data sent to the provider is minimal, and AI list reads are bounded (FR-009 – FR-015,
FR-019).

**Independent test**: quickstart §2: `AiToolInventoryTest`, `AdminToolPermissionTest` and
`AiToolFieldProjectionTest` pass, and registering an unguarded write tool makes the
inventory test fail with its name.

### Tests

- [X] T015 [US2] Write `tests/Feature/AiToolInventoryTest.php` (research R4):
  1. Every class in `EntryPointDiscovery::aiTools()` is reachable from at least one agent:
     the union of `AdminToolset::for($admin)` inner tools (via `GuardedTool::inner()` or
     equivalent accessor, adding one if missing) and each other agent's `tools()`
     (`GuestConciergeAgent`, `InsightsAgent`, `RecommendationAgent`), or listed under
     `unused` in `tests/Inventory/ai_tools.php` with a reason.
  2. Every tool implementing `App\Ai\Tools\Contracts\WritesData` is wrapped in `GuardedTool`
     with a non-empty permission list or `adminOnly`, or in `AuditedTool`.
  3. No `AdminToolset` write entry lists `User::class`, `StaffRole::class`, `Hotel::class` or
     a hotel-settings model in its `writes` (D17, FR-011).
  4. Every guarded write tool has an allowed and a denied case in
     `AdminToolPermissionTest`'s dataset.

  Failures name the tool.
- [X] T016 [P] [US2] Extend `tests/Feature/AdminToolPermissionTest.php` so its dataset has, for
  every `self::write(...)` entry in `AdminToolset::entries()`:
  - an employee with the mapped permissions: the call succeeds, and the AI activity entry
    names the AI agent as actor and the employee as acted-for (FR-012);
  - an employee without them: refused, the model is unchanged, the AI activity entry has
    status `refused`, and the reply does not contain the record's name or other fields
    (FR-015).
- [X] T017 [P] [US2] Add D17 cases to `tests/Feature/AdminToolPermissionTest.php`: an admin
  asking the advisor (through `AdminAdvisorAgent` with `Ai::fake` tool calls) to change a
  user, a staff role, permissions or hotel settings gets no matching tool and nothing
  changes (FR-011, SC-002).
- [X] T018 [P] [US2] Write `tests/Feature/AiToolFieldProjectionTest.php` (research R5). Seed a
  guest with canary values (`email` `canary-…@example.test`, `phone` `+0000000000`, an
  `identity_hash`, internal notes). Run every read tool that returns guest data, as each
  agent that uses it. Assert none of the canary values appear in the output, except fields
  listed for that tool under `pii_fields` in `tests/Inventory/ai_tools.php` (for example
  `GetGuestTool` → `phone` for staff with `guests.view`) (FR-014).
- [X] T019 [P] [US2] Extend `tests/Feature/ConciergeGuestRestrictionsTest.php` with a case per
  guest-facing tool (`GetOwnReservationTool`, `GetOwnBookingsTool`, `GetOwnRequestsTool`,
  `RequestBookingCancellationTool`, `RequestRoomChangeTool`, `CreateGuestServiceRequestTool`,
  `SetContactPreferenceTool`): Guest A passing Guest B's ids (and Hotel B's) is refused and
  nothing changes (FR-006, US2 scenario 4).

### Fixes

- [X] T020 [US2] Bound the AI list reads that do not use `ListResult` (research R6):
  `GetActivitiesTool`, `GetRecommendationsTool`, `GetStaysTool`, `GetTaskCategoriesTool`,
  `GetOwnRequestsTool` in `app/Ai/Tools/`. Change each list read to
  `ListResult::fromQuery($query, ListResult::MAX, fn ($row) => [...])`, keeping the fields
  it returns today. Tools that return one record or one row per room type
  (`GetAvailabilityTool`, `GetGuestAvailabilityTool`, `GetHotelSettingsTool`,
  `GetOwnReservationTool`, `GetReservationTool`) stay as they are; record them under a new
  `naturally_bounded` key in `tests/Inventory/ai_tools.php`, each with its reason.
- [X] T021 [US2] Add a case to `AiToolInventoryTest.php` (FR-019): every read tool not in
  `naturally_bounded` returns a payload with `total`, `returned` and `partial`, and at most
  `ListResult::MAX` items when 60 rows exist.
- [X] T022 [US2] Fix every gap T015–T021 finds:
  - a missing permission goes into the `AdminToolset` entry;
  - an unwrapped write gets `AuditedTool`;
  - an over-sharing serializer gets a field projection, as `GetOwnReservationTool` does.

  If a gap needs a new `Permission` case, follow CLAUDE.md "Access control" steps 1–5 in
  full, including `docs/staff-roles-api-documentation.md` and
  `tests/Feature/PermissionAuthorizationTest.php`.

**Checkpoint**: `php artisan test --filter='AiToolInventoryTest|AdminToolPermissionTest|AdminToolIsolationTest|AiToolFieldProjectionTest|ConciergeGuestRestrictionsTest' --parallel`
is green.

---

## Phase 5: User Story 3 — Urgent work is never stuck behind slow work (P2)

**Goal**: Four lanes on the existing database queue, each with its own workers; a wait
check alerts when a lane waits too long; the dashboard is cached for 60 s per hotel
(FR-020 – FR-023; research R8–R10).

**Independent test**: quickstart §5–6: `QueueLaneTest`, `QueueWaitCheckTest` and
`DashboardCacheTest` pass, and a stopped `realtime` worker triggers one lane-wait alert.

### Tests

- [X] T023 [P] [US3] Write `tests/Feature/QueueLaneTest.php` (data-model "Work lane"):
  - every class in `EntryPointDiscovery::queuedJobs()` resolves to a `QueueLane` value: build
    each job with the container or a minimal constructor and read `->queue`, with `null`
    meaning `default`;
  - every queued notification (`app/Notifications` implementing `ShouldQueue`) resolves to
    `default`;
  - every `QueueLane` value has at least one worker instance listed in
    `deploy/systemd/README.md` (T029);
  - every job's `$timeout` (default 60) is below `config('queue.connections.database.retry_after')`
    (360).
- [X] T024 [P] [US3] Write `tests/Feature/QueueWaitCheckTest.php`. Insert rows into `jobs`
  with old `available_at` values, then run `artisan('queue:check-waits')`:
  - a `realtime` job waiting 61 s sends one alert mail (`Notification::fake()`, assert
    `PlatformAlertNotification` sent to `config('ops.alert_email')`);
  - running again within 15 minutes sends none;
  - an `imports` job waiting 61 s sends none;
  - a reserved job (`reserved_at` set) is ignored;
  - two lanes over their limits send two alerts.
- [X] T025 [P] [US3] Write `tests/Feature/DashboardCacheTest.php` (research R10, SC-011):
  - two `GET /api/dashboard` calls within 60 s run the summary queries once (count queries
    with `DB::listen`; set `CACHE_STORE` to `array` in the test);
  - Hotel B's admin right after Hotel A's sees Hotel B's figures;
  - an employee without `dashboard.view` gets `403` even when a cached entry exists;
  - after `travel(61)->seconds()` the figures are recomputed.

### Implementation

- [X] T026 [US3] Create `app/Enums/QueueLane.php`: a string-backed enum with cases
  `Realtime = 'realtime'`, `Default = 'default'`, `AiBatch = 'ai-batch'`,
  `Imports = 'imports'`, and `waitLimitSeconds(): int` returning 60 / 300 / 1800 / 3600
  (spec Clarifications, FR-028).
- [X] T027 [US3] Route each job to its lane by calling `$this->onQueue(QueueLane::X->value)` in
  its constructor (research R9 table):
  - **Realtime**: `ProcessInboundWhatsAppMessageJob`, `SendWhatsAppMessageJob`,
    `SendProactiveMessageJob`, `SendGuestRequestNoticeJob`.
  - **AiBatch** (LLM and embedding work only): `CreateAiInsightsJob`,
    `ExtractGuestMemoriesJob`, `RetryGuestMemoryExtractionsJob`,
    `GenerateActivityRecommendationsJob`, `IndexKnowledgeDocumentJob`,
    `SyncKnowledgeChunksJob`.
  - **Default**, set explicitly so the choice is visible: the short scheduled jobs that are
    not AI work: `StartHousekeepingDayJob`, `ExpireRecommendationsJob`,
    `EvaluateProactiveTriggersJob`, `MatchRecommendationOutcomesJob`,
    `PruneReservationImportPreviewsJob`, `PurgeDeletedKnowledgeDocumentsJob`,
    `Metering/RebuildUsageCountersJob`, `AiCost/FlagAiCostOverrunsJob`. They must never
    wait behind a long LLM job (research R9).
  - **Imports**: `PrepareReservationImportJob`, `WriteReservationImportJob`.

  Queued notifications stay on `default` (no change). Where a job is dispatched with an
  explicit `->onQueue()` elsewhere, remove that call so the constructor decides.
- [X] T028 [US3] Create `app/Console/Commands/CheckQueueWaitsCommand.php`
  (`queue:check-waits`, contracts/operations-interfaces.md):
  - query `select queue, min(available_at) as oldest, count(*) as waiting from jobs where reserved_at is null group by queue`
    on the queue connection;
  - for each row whose queue is a `QueueLane` value and whose `now()->timestamp - oldest`
    is above `waitLimitSeconds()`, call
    `PlatformAlerts::send("alert:lane-wait:{lane}", config('ops.lane_alert_cooldown_minutes'), "[Ops] Lane {lane} waiting {n}s", [...])`
    with the lane, the oldest wait, the limit and the waiting count;
  - always exit `0`.

  Schedule it in `routes/console.php` with
  `Schedule::command('queue:check-waits')->everyMinute()->withoutOverlapping();`, plus a
  comment that it runs on the scheduler, not a lane, so it is never stuck behind the work
  it checks.
- [X] T029 [P] [US3] Create `deploy/systemd/queue-worker@.service`. It is a template; the
  instance name is `<lane>-<n>`, and the lane is derived with
  `ExecStart=/bin/bash -c 'exec /usr/bin/php /var/www/api-hospitality/artisan queue:work database --queue="${0%%-*}" --memory=128 --max-time=3600 --sleep=3' %i`.
  In a unit file `%%` is systemd's escape for a literal `%`, so bash receives `${0%-*}`.
  That strips only the trailing `-<n>`, which matters because `ai-batch` itself contains
  a `-`. Use `User=ec2-user`, `Restart=always`, `RestartSec=5`,
  `WantedBy=multi-user.target`. Create `deploy/systemd/scheduler.service` running
  `php artisan schedule:work` with the same restart settings. Create
  `deploy/systemd/README.md` listing the five instances to enable: `realtime-1`,
  `realtime-2`, `default-1`, `ai-batch-1`, `imports-1` (research R9).
- [X] T030 [US3] Cache the dashboard in `app/Http/Controllers/DashboardController.php`
  (research R10): after the `dashboard.view` and hotel checks, wrap the building of
  `$data` in
  `Cache::remember("dashboard:{$hotel->id}:{$date}", 60, fn () => …)`, where `$date` is
  `$request->date('date')?->toDateString() ?? now($hotel->timezone)->toDateString()`.
  Resolve the resources to arrays inside the closure (`->resolve()`), so the cached value
  holds no models. `DashboardSummary` stays uncached.
- [X] T031 [US3] Write `docs/operations/deploy.md` (plan structure). It covers:
  - today's deploy steps from `.github/workflows/deploy.yml`;
  - the one-time lane cutover from research R9: add a 2 GB swap file (`fallocate`,
    `mkswap`, `swapon`, `/etc/fstab`); copy and enable the systemd units from
    `deploy/systemd/`; start the five workers **before** deploying the lane routing;
    retire the old worker once `queue-worker@default-1` is running;
  - `ps -o rss,cmd -C php` to check memory per worker before raising counts;
  - how to check a lane: `select queue, count(*) from jobs group by queue`;
  - the expected nightly `ai-batch` backlog (research R9): after the first night on staging,
    record the oldest `ai-batch` wait. If it passes 30 minutes, spread `CreateAiInsightsJob`
    dispatches over the night (one hotel every few minutes) before raising the limit or
    adding a worker;
  - a "Later: Redis" section with the steps from research R8.
- [ ] T032 [US3] Write `docs/operations/lane-load-check.md` and run it on staging (SC-003,
  SC-004, quickstart §5). The procedure:
  1. With all lanes idle, send 20 signed test messages to `POST /api/whatsapp` (fake AI
     provider, `Ai::fake`-style config on staging) and record the 95th percentile time from
     receipt to `SendWhatsAppMessageJob` finishing (from `whatsapp_inbound_messages` and the
     outbound timestamps).
  2. Dispatch 500 `CreateAiInsightsJob` jobs from tinker:
     `foreach (range(1, 500) as $i) CreateAiInsightsJob::dispatch(Hotel::query()->inRandomOrder()->value('id'));`
     (adjust to the job's real constructor). Repeat step 1. **Pass**: the loaded 95th
     percentile is no more than 10% above the idle one.
  3. Start a 10,000-row reservation import, assign a task during it, and record the time
     until `TaskAssignedNotification` is sent. **Pass**: under 1 minute.

  Record both results in `docs/production-readiness-checklist.md`.

**Checkpoint**: `php artisan test --filter='QueueLaneTest|QueueWaitCheckTest|DashboardCacheTest' --parallel`
is green.

---

## Phase 6: User Story 4 — Busy screens stay fast as data grows (P2)

**Goal**: No list is unbounded, and the four busiest lookups meet p95 < 1 s at the SC-005
volume (FR-016 – FR-018).

**Independent test**: quickstart §3–4: `BoundedListTest` passes, and
`hardening:benchmark` exits `0` against the `_bench` database.

- [X] T033 [P] [US4] Write `tests/Feature/BoundedListTest.php` (research R6). For every
  `GET` route in `EntryPointDiscovery::tenantRoutes()` with no route parameter, except
  those under `route_exceptions.bounded_list` in `tests/Inventory/entry_points.php`:
  - seed more rows than the route's maximum, or 101 when unknown;
  - call with no `per_page`: at most the default page size plus pagination `meta`/`links`;
  - call with `per_page=10000`: either `422`, or at most the route's maximum.

  The maximums are 100 for generic routes, 50 for guest memories and 200 for conversations
  (documented exception). Fill `route_exceptions.bounded_list` for report routes that
  return a fixed shape (`/dashboard`, `/analytics`, `/usage`, availability), each with its
  reason.
- [X] T034 [US4] Fix any route `BoundedListTest` finds returning unbounded rows: add `per_page`
  rules (`nullable|integer|min:1|max:100`) and `->paginate()`. Each such fix is a breaking
  change; record it in `docs/<resource>-api-documentation.md` and
  `docs/latest-changes-<date>.md` (FR-035).
- [X] T035 [P] [US4] Create `app/Console/Commands/SeedBenchmarkVolumeCommand.php`
  (`hardening:seed-volume {--hotels=100} {--years=3}`, contracts). It refuses to run, with
  an error, unless `config('database.connections.pgsql.database')` ends in `_bench`. Per
  hotel it creates room types, rooms, about 15,000 reservations over `--years` with
  reservation room lines, stays, activity bookings and 200 knowledge documents with chunks
  carrying random stored embeddings, in chunks of 1,000 with `insert()`.
- [X] T036 [P] [US4] Create `app/Console/Commands/RunBenchmarkCommand.php`
  (`hardening:benchmark {--runs=200} {--explain}`, contracts). It times four operations per
  run against random hotels:
  - `AvailabilityService` for a 30-night range;
  - the overlap check used by `ReservationCommands::assignRooms` (call the query it uses
    directly, no writes);
  - the booking index query through `GenericQuery::apply()` page 1;
  - `KnowledgeSearchTool` with a stored embedding (no provider call).

  It prints p50/p95 per operation, exits `1` if any p95 is 1,000 ms or more, and with
  `--explain` prints `EXPLAIN (ANALYZE, BUFFERS)` of the slowest run.
- [X] T037 [US4] Run the benchmark (quickstart §4). For each operation over target, add an
  index in a new migration `database/migrations/2026_10_25_00000N_add_<name>_index.php`
  with `public $withinTransaction = false;` and
  `DB::statement('create index concurrently if not exists …')`, plus `drop index
  concurrently` in `down()`. Re-run until every p95 is under 1 s, and record the final
  table in `docs/production-readiness-checklist.md` (T051).
- [X] T038 [P] [US4] Extend `tests/Feature/KnowledgeRetrievalIsolationTest.php` (FR-017):
  seed 50 hotels with 20 knowledge chunks each, close in embedding to the query, plus one
  matching chunk for the target hotel. Assert `KnowledgeSearchTool` for the target hotel
  returns its own chunk.

**Checkpoint**: `php artisan test --filter='BoundedListTest|KnowledgeRetrievalIsolationTest' --parallel`
is green, and the benchmark passes.

---

## Phase 7: User Story 5 — Deploys are checked and failures reach a person (P2)

**Goal**: Two health checks, a final health check in the deploy, Sentry with scrubbing,
and alerts for repeated job failures and spend ceilings (FR-025 – FR-028; FR-024 and
automatic rollback are deferred).

**Independent test**: quickstart §7–9: `HealthCheckTest`, `SentryScrubberTest` and
`PlatformAlertTest` pass, and a broken staging `.env` makes the deploy workflow red.

### Tests

- [X] T039 [P] [US5] Write `tests/Feature/HealthCheckTest.php`, following the "Tests"
  section of [contracts/health-api.md](contracts/health-api.md) exactly:
  - `401` with no header, a wrong token, or an empty `health.token`;
  - `200` with `{status: healthy, checks: {database: ok, queue: ok, cache: ok}}`;
  - `503` with the failing check marked `fail` (mock `Cache::put` to throw; then point
    `queue.connections.database.connection` at a missing connection);
  - the body contains no host name, connection name or exception text;
  - `/up` returns `200` without a token.
- [X] T040 [P] [US5] Write `tests/Feature/SentryScrubberTest.php` (research R13, SC-008).
  Build `Sentry\Event` objects with planted values:
  - a request body with `phone`;
  - an `authorization` header;
  - extra context `{guest: {email, message_text}}`;
  - an exception message containing an email and a `+20…` phone;
  - a breadcrumb with a token.

  Pass each through `SentryScrubber::beforeSend()`, and a transaction event through
  `beforeSendTransaction()`. Assert none of the planted values survive, while `hotel_id`,
  `operation` and the exception class do.
- [X] T041 [P] [US5] Write `tests/Feature/PlatformAlertTest.php` (research R14), with
  `Notification::fake()`:
  - 3 failures of one job class within 15 minutes (fire `JobFailed` events) send one alert,
    and a 4th sends none;
  - failures of another class still alert;
  - 2 failures send none;
  - reaching the spend ceiling (drive `AiSpendCeiling` past the account ceiling the way
    its existing tests do) sends one alert per hotel group per day;
  - no alert body contains the job payload.

### Implementation

- [X] T042 [US5] Create `app/Http/Controllers/HealthController.php` with `__invoke`
  (contracts/health-api.md):
  - when `config('health.token')` is empty, or `hash_equals` with the `X-Health-Token`
    header fails, return `apiResponse('Unauthorized.', 401)`;
  - otherwise run three checks, each in try/catch with no detail kept:
    - `database`: `DB::select('select 1')`;
    - `queue`: `DB::connection(config('queue.connections.database.connection'))->table(config('queue.connections.database.table'))->limit(1)->get()`;
    - `cache`: put, get and forget `health:`.Str::uuid();
  - return `apiResponse('Healthy.', 200, ['status' => 'healthy', 'checks' => [...]])` or
    `apiResponse('Unhealthy.', 503, …)` with the failing checks set to `fail`;
  - report a caught exception with `report()` (it reaches Sentry), never in the body.

  Register `Route::get('/health', HealthController::class)->middleware('throttle:30,1');` in
  `routes/api.php` **outside** the `api.key` group, next to the WhatsApp webhook routes.
- [X] T043 [US5] Install Sentry: `composer require sentry/sentry-laravel`, then
  `php artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"` to create
  `config/sentry.php`. In it:
  - read everything from env (T001);
  - set `'send_default_pii' => false` and
    `'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1)`;
  - turn `breadcrumbs.queue_info` and `breadcrumbs.sql_bindings` off;
  - set `'before_send' => [SentryScrubber::class, 'beforeSend']` and
    `'before_send_transaction' => [SentryScrubber::class, 'beforeSendTransaction']`.

  In `bootstrap/app.php` `withExceptions`, add `Integration::handles($exceptions);` and
  `$exceptions->dontReportDuplicates();`. Do not put the org slug, DSN or any setup code in
  the repository.
- [X] T044 [US5] Create `app/Support/Observability/SentryScrubber.php` (research R13,
  data-model "Error report and trace") with static `beforeSend(Event $e, ?EventHint $h): ?Event`
  and `beforeSendTransaction(Event $e, ?EventHint $h): ?Event`. Both:
  - drop the request body and cookies;
  - keep only the `accept`, `content-type` and `user-agent` headers;
  - recursively remove any extra, context or tag key matching
    `/phone|email|body|message|text|content|token|password|secret|api_key|authorization|identity/i`,
    except the tags `hotel_id`, `hotel_group_id`, `operation` and `queue`;
  - mask emails and phone patterns (`/\+?\d[\d\s-]{7,}\d/`) in exception messages and
    breadcrumb messages;
  - reduce the user to the id only.
- [X] T045 [US5] Create `app/Support/Observability/TagsSentryScope.php` with
  `apply(?string $operation): void`. It sets the Sentry tags `hotel_id` (from
  `TenantContext::currentHotelId()`), `hotel_group_id` (from the hotel when cheap to load)
  and `operation`. Call it:
  - in `ResolveTenant` after the context is set, with the route name;
  - from a `JobProcessing` listener registered in `AppServiceProvider`, setting
    `operation` (the job class) and `queue`. The hotel tag for jobs is set when the job
    calls `runForHotel`, by reading `TenantContext::currentHotelId()` lazily in a Sentry
    `configureScope` callback.

  Do not change `TenantContext` itself; tenancy stays free of observability code.

  Make it a no-op when Sentry is not bound or has no DSN.
- [X] T046 [US5] Create `app/Listeners/AlertOnRepeatedJobFailures.php`, registered for
  `Illuminate\Queue\Events\JobFailed`. It increments the cache counter
  `alert:job-failures:{class}` with a 15-minute expiry (`Cache::add` then
  `Cache::increment`). At `config('ops.job_failure_threshold')` (3) it calls
  `PlatformAlerts::send("alert:job-failed:{class}", 15, "[Ops] Job failing repeatedly: {class}", [count, window, queue, time])`.
  Never include the payload.
- [X] T047 [US5] In `app/Services/AiCost/AiSpendCeiling.php`, next to the existing
  `Log::critical` (line ~95), call
  `PlatformAlerts::send("alert:spend-ceiling:{$groupId}:{$date}", 1440, "[Ops] AI spend ceiling reached: {group name}", [group id, ceiling, spend today, time])`
  (research R14).
- [X] T048 [US5] Add the final health-check step to `.github/workflows/deploy.yml` (research
  R12, contracts "deploy.yml final step"). After `php artisan queue:restart`, in the same
  SSH script, loop 5 times:
  `curl -fsS -o /dev/null -H "X-Health-Token: $HEALTH_CHECK_TOKEN" "$API_HEALTH_URL" && exit 0; sleep 5`,
  then `exit 1`. Pass both through `appleboy/ssh-action`'s `envs` from two new repository
  secrets: `HEALTH_CHECK_TOKEN` (same value as the server `.env`) and `API_HEALTH_URL` (the
  full `https://…/api/health` URL). The owner creates both secrets before this ships. Do
  not add release directories or rollback.
- [X] T049 [P] [US5] Write `docs/health-api-documentation.md` from
  [contracts/health-api.md](contracts/health-api.md), and
  `docs/operations/alerts-and-monitoring.md` covering:
  - the Sentry setup steps, pointing to the team's password manager for the DSN and org
    details;
  - the Sentry Uptime monitor on the **full** check `/api/health` with the
    `X-Health-Token` header (not `/up`, which answers `200` even with the database down),
    and the alert rule for new issues;
  - the four alert triggers and their dedupe windows (data-model "Alert");
  - how to read a lane backlog.
- [ ] T050 [US5] Configure Sentry and rehearse every alert (FR-028, SC-009, research R14).
  Done with the owner's Sentry access; no secrets go into the repository.
  1. Create a Sentry Uptime monitor on `API_HEALTH_URL` (`/api/health`): every 1 minute,
     header `X-Health-Token`, alert after 2 failures in a row, mail to the owner.
  2. Create an issue alert rule: new issue in production → mail to the owner.
  3. Rehearse on staging and time each alert from trigger to inbox:
     - lane wait: stop `queue-worker@realtime-*`, send a message;
     - repeated failure: make one job class fail 3 times;
     - health: break the staging database setting;
     - spend ceiling: set a tiny ceiling and make one AI call.

     **Pass**: each mail arrives within 5 minutes. Restore staging afterwards.

  Record the times in `docs/production-readiness-checklist.md`.

**Checkpoint**: `php artisan test --filter='HealthCheckTest|SentryScrubberTest|PlatformAlertTest' --parallel`
is green.

---

## Phase 8: User Story 6 — A written, proven production readiness checklist (P3)

**Goal**: A clean fresh install, a configuration readiness check, supervised workers, the
frontend handoff, and a checklist proving each item (FR-030 – FR-034).

**Independent test**: quickstart §10: `FreshInstallTest` and `OpsReadinessCommandTest`
pass, and every checklist row is proven or listed as a known post-MVP item.

- [X] T051 [P] [US6] Write `tests/Feature/FreshInstallTest.php` (research R15). With
  `config(['seeding.demo' => false])` and the app environment set to `production`, run
  `$this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])`. Assert:
  - exactly one super admin exists;
  - the default teams, task categories and activity categories exist;
  - model prices exist;
  - no hotel, guest or reservation exists.

  A second case with `seeding.demo = true` asserts the demo hotel exists.
- [X] T052 [US6] Split the seeders (data-model "Seeder split"):
  - create `database/seeders/PlatformSeeder.php` with the super admin, read from
    `config('seeding.super_admin_email')` / `config('seeding.super_admin_password')`
    (new `config/seeding.php` with `env()` defaults, plus
    `'demo' => (bool) env('SEED_DEMO', false)`), and the default teams and categories,
    moved out of `database/seeders/HospitalitySeeder.php`;
  - create `database/seeders/DemoSeeder.php` calling the remaining demo part of
    `HospitalitySeeder` and `GuestReservationSeeder`;
  - make `DatabaseSeeder` call `AiModelPriceSeeder`, `PlatformSeeder` and
    `KnowledgeBaseSeeder` (global knowledge only), then `DemoSeeder` when
    `app()->environment('local', 'testing') || config('seeding.demo')`.

  `php artisan migrate --seed` on a dev machine must give the same data as before.
- [X] T053 [P] [US6] Write `tests/Feature/OpsReadinessCommandTest.php`, with one case per
  check in contracts/operations-interfaces.md "`ops:readiness`":
  - break one setting (for example `config(['app.debug' => true])` or an empty
    `health.token`);
  - assert exit `1` and the line `✗ <check name>`;
  - assert no secret value is printed.

  An all-good configuration exits `0`. `--deploy` skips `infra.lanes`.
- [X] T054 [US6] Create `app/Console/Commands/OpsReadinessCommand.php` (`ops:readiness
  {--deploy}`), implementing exactly the checks in the contracts table:
  - `app.env`, `app.secrets`;
  - `ai.provider`: keys for providers in `config('ai')`;
  - `ai.models`: each configured model has an `AiModelPrice` row;
  - `ai.ceilings`: every `HotelGroup` has a ceiling;
  - `infra.queue`, `infra.cache`, `infra.lanes`: reuse the `queue:check-waits` query
    without sending alerts;
  - `ops.health_token`: at least 32 characters;
  - `ops.sentry`;
  - `ops.alerts`: the mailer is not `log`/`array`.

  Print `✓ name` or `✗ name: problem` with variable names only, and exit `1` on any failure.
- [X] T055 [US6] Write `docs/production-readiness-checklist.md` (FR-033, data-model
  "Readiness checklist item"): a table with the columns Item · How proven · Evidence ·
  Owner · Status, with one row each for:
  - FR-025 (deploy health check, quickstart §9);
  - FR-026 (`HealthCheckTest`);
  - FR-027/027a (`SentryScrubberTest` + the staging `sentry:test`);
  - FR-028 / SC-009 (`PlatformAlertTest`, `QueueWaitCheckTest`, and the alert rehearsal
    from T050);
  - SC-003 / SC-004 (lane load check from T032);
  - FR-030 (`FreshInstallTest`);
  - FR-031 (reboot check, quickstart §10);
  - FR-032 (`ops:readiness` on production);
  - FR-034 (frontend build);
  - SC-005 (benchmark table from T037);
  - SC-001/SC-002 (audit tests).

  End with a "Known post-MVP items" section: backups (FR-029), Redis (FR-021), and atomic
  deploys with rollback (FR-024), each with one line on the accepted risk.
- [X] T056 [P] [US6] Write `specs/017-hardening-production-readiness/frontend-changes.md`
  (FR-034, D13), in the style of `specs/016-import-column-mapping/frontend-changes.md`:
  - for every screen in `ecosystem-frontend` (dashboard, hotels, rooms, room types, guests,
    reservations, imports, activities, bookings, tasks, teams, staff roles, users,
    knowledge base, conversations, recommendations, insights, AI activity, usage), require
    an error state, an empty state and a loading state;
  - `next build` must finish with no errors;
  - note that list responses are unchanged (no breaking page-size change), unless T034
    changed one, in which case name it.

**Checkpoint**: `php artisan test --filter='FreshInstallTest|OpsReadinessCommandTest' --parallel`
is green.

---

## Phase 9: Polish & Cross-Cutting

- [X] T057 [P] Update `docs/system-design-assessment-2026-09-19.md` §7:
  - F5: health check done; atomic releases post-MVP;
  - F9: split queues done; Redis post-MVP;
  - F11: dashboard caching and error tracking done.

  Mark Phase 14 delivered in `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md`
  with the spec path, as Phase 13 was.
- [X] T058 [P] Write `docs/latest-changes-<date>.md` only if T014, T022 or T034 changed an API
  response. Otherwise note in the PR description that there are no breaking changes.
- [X] T059 Run `./vendor/bin/pint` and `php artisan test --parallel`. Both must be clean.
- [ ] T060 Walk through `quickstart.md` §1–10 and record evidence (dates, benchmark table,
  rehearsal results) in `docs/production-readiness-checklist.md`. Steps that need the
  production host (§9, §10 reboot, `ops:readiness`) are done with the owner, following
  `docs/operations/deploy.md`.

---

## Dependencies & Execution Order

- **Setup (T001–T003)** → **Foundational (T004–T007)** → stories.
- **US1 (T008–T014)** and **US2 (T015–T022)** both depend only on Foundational and can run
  in parallel. They are the MVP of this feature.
- **US3 (T023–T032)** needs T006–T007 (alerts). T027 must not ship before the workers in
  T029 are running on the server (the T031 runbook order). T032 runs on staging after the
  lanes are live.
- **US4 (T033–T038)** needs T005 (route discovery). T037 needs T035–T036.
- **US5 (T039–T050)** needs T006–T007. T048 needs T042 deployed first, with both secrets
  set. T050 needs T042, T046, T047 and the lanes (US3) live on staging.
- **US6 (T051–T056)** needs US3–US5 for the checklist evidence. T052 and T054 can start
  any time after Setup.
- **Polish (T057–T060)** comes last.

```text
Setup ─► Foundational ─┬─► US1 ─┐
                       ├─► US2 ─┤
                       ├─► US3 ─┼─► US6 ─► Polish
                       ├─► US4 ─┤
                       └─► US5 ─┘
```

## Parallel Examples

- **US1**: T008 and T009 are written first (different files). T011 and T013 touch the same
  inventory file, so run them in sequence.
- **US2**: T016, T017, T018 and T019 in parallel (different test files), then T020 → T021 →
  T022.
- **US3**: T023, T024, T025 and T029 in parallel. Then T026 → T027, then T028 and T030,
  then T031 and finally T032 on staging.
- **US4**: T033, T035, T036 and T038 in parallel. Then T034 and T037.
- **US5**: T039, T040, T041 and T049 in parallel. Then T042, T043 → T044 → T045, T046,
  T047, then T048, then T050 on staging.
- **US6**: T051, T053 and T056 in parallel. Then T052, T054, then T055.

## Implementation Strategy

1. **MVP first**: Setup + Foundational + US1 + US2. These are tests and fixes only, need no
   server changes, and can merge on their own. They prove isolation and AI authorization,
   the two non-negotiable principles.
2. **Then US5's code** (health check, Sentry, alerts), because it has no server
   dependency. Then **US4** (bounded lists, benchmark).
3. **Then US3**: start the workers and the swap file on the server, then deploy the lane
   routing.
4. **Then T048** (deploy health check) once `/api/health` and both secrets exist, then the
   staging rehearsals T032 and T050.
5. **Finally US6** and Polish: checklist evidence and go-live sign-off with the owner.
