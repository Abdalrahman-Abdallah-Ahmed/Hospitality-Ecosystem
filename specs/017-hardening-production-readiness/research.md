# Research: Hardening and Production Readiness

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-10-24

What the code looks like today (2026-10-24, `main` at `214eb27`):

- 195 routes, 25 of them in `routes/admin.php`.
- 64 AI tools under `app/Ai/Tools`. Admin AI tools are registered in
  `AdminToolset` and wrapped in `GuardedTool`, which holds the permission per tool. Write
  tools of every other agent are wrapped in `AuditedTool`.
- 21 queued jobs, all on the single `default` queue of the `database` connection. Cache
  and locks also use the database.
- `TenantIsolationTest` already has a dataset of every tenant-owned model, plus about 25
  per-feature isolation tests. `PermissionAuthorizationTest` has an endpoint dataset.
- Deploys run `git pull` in the live directory (`.github/workflows/deploy.yml`) after
  `tests.yml` passes.
- `/up` is Laravel's built-in health route. There is no error tracking.

Each decision below is numbered **R1–R15** and referenced from the plan and tasks.

---

## R1 — The entry-point inventory is built from the code, not written by hand

**Decision**: The inventory is a PHP file, `tests/Inventory/entry_points.php`. It holds
only **exceptions and explicit coverage**; the list of entry points itself is discovered
when the tests run:

- **Routes**: every route in `Route::getRoutes()` whose middleware includes `tenant`, or
  that lives in `routes/admin.php`, plus the WhatsApp webhook.
- **Jobs**: every class in `app/Jobs` that implements `ShouldQueue`.
- **Scheduled tasks**: every event in `Schedule::events()`.
- **AI tools**: every class in `app/Ai/Tools` that implements `Laravel\Ai\Contracts\Tool`,
  except the wrappers (`GuardedTool`, `AuditedTool`).

A meta-test (`EntryPointInventoryTest`) fails when a discovered entry point is neither
covered by the generic route checks (R2) nor listed in the inventory with a `covered_by`
test name. It names the uncovered entry point (FR-003, SC-001). A `covered_by` that
names a test which does not exist also fails.

**Rationale**: A hand-written list goes stale the first time someone adds a route.
Discovering entry points from the framework means the inventory cannot miss one, and the
file only grows when something needs a special case.

**Alternatives considered**:
- A Markdown inventory. It is readable, but nothing checks it.
- PHP attributes on each controller method. Spreading the rule across about 50 files makes
  it hard to review as a unit, which is the same reason `routes/admin.php` is one file.

## R2 — Generic route-driven isolation checks, with per-feature tests kept

**Decision**: `CrossTenantRouteTest` walks every tenant route and applies one rule per
route shape:

| Route shape | Check |
| --- | --- |
| `GET` with no model parameter (index or report) | Seed Hotel A and Hotel B. Call as a Hotel A admin. No Hotel B id appears anywhere in the JSON body, and totals equal Hotel A's count |
| `GET/PUT/PATCH/DELETE` with a bound model parameter | Create the record in Hotel B. Call as a Hotel A admin. Response is `404` and the Hotel B row is unchanged (`updated_at`, `deleted_at`) |
| `POST/PUT/PATCH` whose request rules reference another model by `*_id` | Send Hotel B's id. Response is `404` or `422` and nothing is written |

Records come from the model-factory dataset that `TenantIsolationTest` already uses,
moved to `tests/Pest.php` so both files share it. Routes the generic rules cannot drive
(multi-step flows, file uploads, the webhook) are listed in the inventory with the
existing per-feature test that covers them. The existing per-feature tests stay.

**Rationale**: It covers every route the same way and catches nested leaks (the
"no Hotel B id anywhere in the body" check), and it does not throw away the more specific
tests that already exist (Principle II).

**Alternatives considered**: One hand-written test per endpoint (about 170). That is a
lot of duplication, and still misses new routes.

## R3 — Jobs and scheduled tasks: tenant context is checked, with a reviewed cross-hotel list

**Decision**: The job audit uses the same discovery. Each job is either:

- **hotel**: it calls `TenantContext::runForHotel()`. The isolation check dispatches it for
  Hotel A and asserts no Hotel B row was read or written. Rows are watched with a query
  listener on the tables the job touches.
- **cross_hotel**: it is listed in the inventory with a `reason` (FR-004).

Current classification from the code (to be confirmed per job during implementation):

| Job | Today | Proposed |
| --- | --- | --- |
| `PruneReservationImportPreviewsJob`, `PurgeDeletedKnowledgeDocumentsJob` | `withoutScope` | cross_hotel: platform-wide housekeeping of expired data |
| `RebuildUsageCountersJob`, `FlagAiCostOverrunsJob` | no context | cross_hotel: account-level (HotelGroup) metering |
| `GenerateActivityRecommendationsJob`, `RetryGuestMemoryExtractionsJob`, `SyncKnowledgeChunksJob`, `SendWhatsAppMessageJob` | no `runForHotel` | **audit**: either add `runForHotel` or list as cross_hotel with a reason |
| `IndexKnowledgeDocumentJob`, `PrepareReservationImportJob`, `WriteReservationImportJob` | `runForHotel` + `withoutScope` | hotel; each `withoutScope` call must be justified inline |
| all others | `runForHotel` | hotel |

**Rationale**: The constitution allows the escape hatch only when it is used deliberately
(Principle III). Writing the reason next to each use makes the review possible.

## R4 — AI authorization: the existing registries are the AI tool inventory

**Decision**: `AdminToolset` already records each Admin AI tool's kind (`read`/`write`),
required permissions, `adminOnly` flag and written models. That registry **is** the Admin
AI part of the inventory (FR-009). Other agents' tools are listed through each agent's
`tools()`. `AiToolInventoryTest` then checks that:

1. Every Tool class is reachable from at least one agent, or is listed as unused.
2. Every tool implementing `WritesData` is wrapped in `GuardedTool` (Admin AI) with at
   least one permission or `adminOnly`, or in `AuditedTool` (other agents) (FR-013).
3. No Admin AI write tool lists `User`, `StaffRole`, `Hotel` or `HotelSetting` among the
   models it writes (FR-011, D17).
4. The permission dataset in `AdminToolPermissionTest` has an allowed/denied case for every
   guarded write tool (FR-010, SC-002).

Refused calls already leave a `refused` AI activity entry (`AiActionRecorder`). The spec
keeps this behavior (FR-012).

**Rationale**: It reuses the guard that Phase 9 built (Principle II). The audit adds the
completeness checks, not a second registry.

## R5 — Field projection is tested by searching the output for canary values

**Decision**: `AiToolFieldProjectionTest` seeds a guest with recognizable values
(email `canary@…`, phone `+000…`, `identity_hash`, internal notes). It runs every read tool
that can return guest data, as the agent that uses it, and asserts that none of those
values appear in the tool output, unless the tool is listed with the field it needs
(for example `GetGuestTool` for an admin with `guests.view` may return the phone).

**Rationale**: This tests what actually reaches the provider (FR-014). Each tool's
serializer can change later without breaking the test, as long as nothing extra leaks.

## R6 — Bounded lists

**Decision**:
- **API**: every index endpoint already validates `per_page`, with maximums of 100
  (generic), 50 (guest memories) and 200 (conversations). The maximums stay as they are,
  so there is no breaking change; the 200 for conversation messages is documented as the
  one exception. `BoundedListTest` calls every `GET` route without a model parameter, once
  with no `per_page` and once with `per_page=10000`. It expects either a `422` for the
  oversized value, or at most the maximum number of rows plus pagination `meta`/`links`
  (FR-018, SC-006). Report routes that return a fixed shape rather than a list are listed
  as exceptions.
- **AI**: `ListResult::MAX = 50` already bounds the paged Admin AI reads. The tools that do
  not use `ListResult` (`GetActivitiesTool`, `GetRecommendationsTool`, `GetStaysTool`,
  `GetTaskCategoriesTool`, `GetOwnRequestsTool`, plus the availability and single-record
  tools) are audited. Each list read moves to `ListResult`, or is documented as naturally
  bounded (a single record, or one row per room type) (FR-019).

## R7 — Performance is measured by a separate benchmark, not inside the normal test run

**Decision**:
- `php artisan hardening:seed-volume` builds the SC-005 data set (100 hotels × about 15,000
  reservations, rooms, stays, bookings, 200 knowledge documents each) in a separate
  database. It refuses to run against the configured application database (see the memory
  note about `--env=testing`).
- `php artisan hardening:benchmark` runs each SC-005 operation 200 times through its real
  service:
  - `AvailabilityService` for a 30-night range;
  - the assignment overlap check in `ReservationCommands::assignRooms`;
  - the booking index query through `GenericQuery`;
  - `KnowledgeSearchTool` with a stored embedding, so there is no provider call.

  It reports p50/p95 and fails if any p95 is at or above 1 s. It also prints `EXPLAIN
  (ANALYZE, BUFFERS)` for the slowest run.
- Indexes are added only where the plans show sequential scans on the large tables. Each
  index goes in a new migration (`CREATE INDEX CONCURRENTLY`, outside a transaction), so it
  is safe on the live tables.
- The recall check (FR-017) already exists for F7. It is extended to the SC-005 shape
  (many hotels) and stays in the normal suite.

**Rationale**: A 1.5-million-row seed does not belong in every CI run. The benchmark runs
before go-live and after any change to these queries, and its result is recorded in the
readiness checklist.

## R8 — Lanes stay on the database queue for the MVP; Redis is a later upgrade

**Decision** (spec Clarifications 2026-10-25):
- `QUEUE_CONNECTION=database` and `CACHE_STORE=database` stay as they are. Locks and rate
  limiters keep following the cache store.
- The database queue already supports named queues: every row in `jobs` has a `queue`
  column, and a worker reads only the queues it is given. On Postgres, Laravel reserves
  jobs with `FOR UPDATE SKIP LOCKED`, so several workers can share the table safely.
- Workers are plain `php artisan queue:work database --queue=<lane>` processes, one systemd
  service per worker (R14). There is no Horizon (it needs Redis).
- A lane's wait alert is our own scheduled check (R9). Laravel's built-in `queue:monitor`
  only counts jobs; it does not measure how long they have waited.
- **The upgrade path stays open (FR-021).** Jobs choose their lane through `QueueLane`, not
  through the connection. Moving to Redis later means changing `QUEUE_CONNECTION` and
  `CACHE_STORE`, replacing the systemd workers with Horizon, and replacing the wait check
  with Horizon's `waits`. No job code changes.
- The test suite keeps `QUEUE_CONNECTION=sync` and the `array` cache. CI does not change.

**Rationale**:
- The MVP needs the lanes, not a new store. Lanes alone fix the problem guests would feel.
- The production host has 2 GB of memory, about 1 GB free and no swap. Redis plus
  Horizon's extra processes would take memory the workers need.
- No new software to install, update or learn before go-live.

**Alternatives considered**:
- Redis + Horizon now: built-in supervision and wait alerts, and less load on Postgres. It
  is deferred for the memory and scope reasons above, and remains the planned post-MVP
  step (assessment F9).
- A bigger server (4 GB) to make room for Redis now: adds a monthly cost the MVP does not
  need.
- One worker reading all queues in priority order (`--queue=realtime,default,…`). Rejected:
  it still runs one job at a time, so a long job still blocks a guest reply.

## R9 — Four lanes, a wait check, and a cutover that loses no work

**Decision**: Lane names and limits (spec Clarifications, FR-028). Worker counts are sized
for the 2 GB host and can be raised later without code changes:

| Queue | Carries | Wait alert | Workers (start) |
| --- | --- | --- | --- |
| `realtime` | `ProcessInboundWhatsAppMessageJob`, `SendWhatsAppMessageJob`, `SendProactiveMessageJob`, `SendGuestRequestNoticeJob` | 60 s | 2 |
| `default` | queued notifications and mail; the short scheduled jobs that are not AI work (`StartHousekeepingDayJob`, `ExpireRecommendationsJob`, `EvaluateProactiveTriggersJob`, `PruneReservationImportPreviewsJob`, `PurgeDeletedKnowledgeDocumentsJob`, `RebuildUsageCountersJob`, `FlagAiCostOverrunsJob`, `MatchRecommendationOutcomesJob`); anything not routed elsewhere | 300 s | 1 |
| `ai-batch` | LLM and embedding work only: `CreateAiInsightsJob`, `ExtractGuestMemoriesJob`, `RetryGuestMemoryExtractionsJob`, `GenerateActivityRecommendationsJob`, `IndexKnowledgeDocumentJob`, `SyncKnowledgeChunksJob` | 1800 s | 1 |
| `imports` | `PrepareReservationImportJob`, `WriteReservationImportJob` | 3600 s | 1 |

- Each job sets its queue in its constructor (`$this->onQueue(QueueLane::Realtime->value)`)
  through an `App\Enums\QueueLane` enum, so there are no string literals. Queued
  notifications use `viaQueues()`.
- Short scheduled jobs go to `default`, not `ai-batch`, so the start of each hotel's
  housekeeping day and proactive messages never wait behind long LLM jobs.
- **Expected nightly backlog**: with one `ai-batch` worker, the nightly insights for every
  hotel plus knowledge indexing (up to 300 s a job) can pass the 30-minute limit. That is
  batch work, so it is acceptable, but the alert must not fire every night. After the
  first night on staging, check the oldest wait on `ai-batch`. If it passes 30 minutes,
  spread `CreateAiInsightsJob` dispatches over the night (one hotel per few minutes)
  before raising the limit or adding a worker. The runbook records the measured backlog.
- Each worker runs with `--memory=128` (it restarts itself above 128 MB) and
  `--max-time=3600` (it restarts hourly, which also picks up new code).
- **Wait check**: `php artisan queue:check-waits`, scheduled every minute **as a command
  on the scheduler, not as a queued job**, so it is never stuck behind the work it checks.
  It runs `select queue, min(available_at) from jobs where reserved_at is null group by
  queue`, compares each lane's oldest waiting job with `QueueLane::waitLimitSeconds()`, and
  sends one `PlatformAlert` per lane per 15 minutes when the limit is passed (FR-028).
- **One timeout rule**: the database connection has a single `retry_after` (360 s) shared
  by every lane. A job still running at `retry_after` is handed to a second worker, so
  every job's `$timeout` must stay below 360 s. Today's longest is 300 s.
- `QueueLaneTest` asserts that:
  - every `ShouldQueue` job and queued notification resolves to a `QueueLane` value;
  - every lane has a worker unit in `deploy/systemd/`, so nothing can be sent to a queue no
    worker listens on (FR-022);
  - every job's `$timeout` is below the connection's `retry_after`.
- **Cutover** (one deploy, with a server step first, in the deploy runbook):
  1. Install and start the four lane worker services on the server. They sit idle on
     their empty lanes. The existing worker keeps serving `default`.
  2. Deploy the lane routing. New jobs go to their lanes, and jobs already waiting on
     `default` are still processed by the `default` worker, because that lane keeps its
     name. Nothing is stranded.
  3. Retire the old worker once the new `default` worker is confirmed running.

**Rationale**: The assessment warns that moving jobs to named queues before workers
listen on them stops them being processed (§7, F9). Starting the workers first prevents
that. Keeping the connection unchanged means there is no second store to drain.

## R10 — Dashboard cache: 60 seconds, keyed by hotel and date

**Decision**: `DashboardController` caches the fully built response array (resources
already resolved to arrays) in `Cache::remember("dashboard:{hotel_id}:{date}", 60, …)`.
- The permission check runs before the cache read, so access is never cached.
- The key holds the hotel id, so a hotel switch is a different key and shows at once
  (FR-023).
- `DashboardSummary` stays uncached, because the Admin AI reads it and needs live figures.
- No event-based invalidation. 60 seconds is the agreed staleness.

## R11 — Health checks: public `/up` stays shallow, full check behind a token

**Decision** (spec Clarifications):
- `GET /up` (Laravel built-in) stays as it is. It answers `200` when the app boots and
  shows no details.
- New `GET /api/health`, outside the `api.key`/`auth:sanctum` stack, with `throttle:30,1`:
  - It requires `X-Health-Token`, compared with `hash_equals` against
    `config('health.token')` (`HEALTH_CHECK_TOKEN`). With a missing or wrong token, or with
    no token configured, it answers `401` with no detail.
  - It checks the database (`select 1`), the queue (a read of the `jobs` table on the
    queue connection) and the cache (put/get/forget of a random key), each with a short
    timeout. With the database queue and cache these all reach Postgres, but through the
    connection each one is configured with, so the check stays correct after the Redis
    upgrade.
  - It answers `200 {status: "healthy", checks: {database: "ok", queue: "ok", cache: "ok"}}`
    or `503` with the failing check marked `"fail"`. There are no messages, versions or
    hostnames (FR-026). See [contracts/health-api.md](contracts/health-api.md).
- The deploy and the uptime monitor hold the token. It is stored in GitHub secrets and the
  server `.env`, never in the repository.

## R12 — Deploy stays in place, with a health check at the end

**Decision** (spec Clarifications 2026-10-25):
- `.github/workflows/deploy.yml` keeps today's steps: `git pull`, `composer install
  --no-dev`, `migrate --force`, `optimize`, `queue:restart`. `queue:restart` makes each lane
  worker finish its current job and restart on the new code under systemd.
- One step is added at the end: call the full health check (R11) up to 5 times, 5 s apart,
  with the token passed from a GitHub secret (`HEALTH_CHECK_TOKEN`). If it never answers
  `200`, the step fails, the workflow is marked failed, and GitHub emails the owner
  (FR-025, SC-007). Nothing is rolled back.
- No release directories, symlink switch, automatic rollback, readiness gate in the deploy,
  or migration compatibility check. `ops:readiness` is run by hand before go-live (R14).

**Rationale**: The MVP needs to know when a deploy breaks, not to undo it automatically.
The health check is one step in the existing workflow and needs no server changes.

**Post-MVP** (assessment F5, still open): release directories with an atomic symlink
switch, code-only rollback on a failed health check, and a CI check that stops migrations
the previous code cannot work with.

## R13 — Sentry for errors and 10% traces, with scrubbing before sending

**Decision**:
- Add `sentry/sentry-laravel`. Configure it only through env: `SENTRY_LARAVEL_DSN`,
  `SENTRY_ENVIRONMENT`, `SENTRY_TRACES_SAMPLE_RATE=0.1`, `SENTRY_RELEASE` (the git sha
  set by the deploy). The org slug and setup codes are kept out of the repository; they
  go in the operator runbook only as "see the team's password manager".
- `send_default_pii => false`. `App\Support\Observability\SentryScrubber` is registered as
  both `before_send` and `before_send_transaction`. It:
  - removes request bodies, headers other than a safe list (`accept`, `content-type`,
    `user-agent`) and cookies;
  - drops any context or extra key matching `phone|email|body|message|text|content|token|password|secret|api_key|authorization|identity`,
    at any depth;
  - masks email and phone patterns in exception messages and breadcrumbs;
  - keeps job payloads out (`breadcrumbs.queue_info` off, and job context reduced to the
    job class and queue).
- Tags: `hotel_id` and `hotel_group_id` from `TenantContext` (set by `ResolveTenant` and by
  `runForHotel`), and `operation` (route name or job class). No user email or name; only
  the user id.
- No duplicate reports: `$exceptions->dontReportDuplicates()` in `bootstrap/app.php`.
- `SentryScrubberTest` feeds events with planted values and asserts that none survive
  (SC-008). The test runs without a DSN; the scrubber is a plain class.

## R14 — Readiness command, alerts and supervision

**Decision**:
- **`php artisan ops:readiness`** checks the configuration and reports each problem by
  name. It exits `1` if there are any (FR-032). Checks:
  - `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set;
  - `API_KEY`, `WHATSAPP_VERIFY_TOKEN` and `WHATSAPP_APP_SECRET` set;
  - the AI provider key for every configured provider is set, and every configured model
    has a row in `ai_model_prices`, so cost can be attributed;
  - every hotel group has a spend ceiling;
  - the queue and cache connections are reachable, and no lane's oldest waiting job is
    past its limit;
  - `HEALTH_CHECK_TOKEN`, `SENTRY_LARAVEL_DSN`, `OPS_ALERT_EMAIL` and mail settings are
    present.

  `--deploy` skips the lane wait check, for use right after workers restart. The command
  never prints secret values, only their names.
- **Alerts** go to `OPS_ALERT_EMAIL` (FR-028):

  | Trigger | Source |
  | --- | --- |
  | Lane wait over its limit | `queue:check-waits` on the scheduler (R9) |
  | Repeated job failures | Our own `JobFailed` listener (not Sentry) counts failures per job class. When the same job class fails **3 times within 15 minutes**, a `PlatformAlert` mail goes out once per class per 15 minutes (this settles the spec's "repeated" item). Sentry also groups the failures |
  | Health check failure | A Sentry Uptime monitor calls the **full** check `/api/health` every minute, sending the `X-Health-Token` header (Sentry uptime monitors accept custom headers), and alerts after 2 failures in a row. `/up` alone is not enough: it answers `200` whenever the app boots, even with the database down. Between deploys this is the only check of the full health; at deploy time the workflow's final step also checks it (R12) |
  | Spend ceiling reached | The existing `Log::critical` in `AiSpendCeiling` also sends a `PlatformAlert` mail, once per hotel group per day |

  Alert mail is sent **immediately, not queued**, so a stuck lane cannot hold back its
  own alert. Each alert is de-duplicated with `Cache::add` on a key of trigger plus subject (job
  class, hotel group or lane), so one noisy source cannot suppress another (spec edge
  case).
- **Supervision**: a template unit `deploy/systemd/queue-worker@.service`, with one
  instance per worker named `<lane>-<n>` (`queue-worker@realtime-1`, `queue-worker@realtime-2`,
  `queue-worker@default-1`, `queue-worker@ai-batch-1`, `queue-worker@imports-1`). Each runs
  `php artisan queue:work database --queue=<lane> --memory=128 --max-time=3600`. Plus
  `deploy/systemd/scheduler.service` (`php artisan schedule:work`). All are
  `Restart=always` and `WantedBy=multi-user.target`.
- **Memory**: the host has 2 GB with no swap. The runbook adds a 2 GB swap file as a safety
  net, and records the real memory per worker (`ps -o rss,cmd -C php`) before raising any
  worker count.
  The unit files are versioned in the repository, and installing them is a runbook step
  (FR-031).

## R15 — Seeders and the readiness checklist (backups deferred)

**Decision**:
- **Seeders** (FR-030): `DatabaseSeeder` runs only the base seeders:
  - `AiModelPriceSeeder`;
  - the super admin and default categories, extracted from `HospitalitySeeder` into
    `PlatformSeeder`, with the super admin's credentials taken from env and never
    hard-coded;
  - global knowledge.

  Demo data (the sample hotel, `GuestReservationSeeder`) moves to `DemoSeeder`, which
  `DatabaseSeeder` calls only in `local`/`testing` or when `SEED_DEMO=true`. The
  documented `php artisan migrate --seed` for developers keeps working.
  `FreshInstallTest` runs `migrate:fresh --seed` with `APP_ENV=production` semantics and
  asserts a usable platform with no demo rows.
- **Backups** (FR-029): deferred to after the MVP (spec Clarifications 2026-10-25). No
  backup script, timer, bucket or restore runbook in this feature. When the work is picked
  up, the starting point is a nightly `pg_dump -Fc` to S3 from a systemd timer (or RDS
  snapshots if the database is managed), plus a tested restore runbook.
- **Checklist** (FR-033): `docs/production-readiness-checklist.md` has one row per
  FR-024 – FR-034 item, each with how it is proven (test name, command or runbook), its
  evidence and its owner. A "Known post-MVP items" section lists backups, Redis and atomic deploys, so the
  go-live sign-off records them as accepted gaps.
- **Frontend** (FR-034): a `frontend-changes.md` handoff, written at the tasks phase, for
  error, empty and loading states per screen and a clean `next build`, delivered in
  `ecosystem-frontend` (D13).
