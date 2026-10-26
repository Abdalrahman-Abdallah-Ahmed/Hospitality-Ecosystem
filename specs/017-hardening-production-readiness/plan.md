# Implementation Plan: Hardening and Production Readiness

**Branch**: `017-hardening-production-readiness` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/017-hardening-production-readiness/spec.md`.
Phase 14 of `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md` (SPEC-102 – 105).
Closes F5, F9 and the rest of F11 from `docs/system-design-assessment-2026-09-19.md`.

**Depends on**: Phases 1–13 and 016 on `main`. The server changes (the lane worker
services and a swap file) need SSH access to the host, and are done
by following the runbooks this feature writes.

## Summary

- **Isolation audit (US1, R1–R3)**: entry points are discovered from the framework:
  - routes with `tenant`, plus `routes/admin.php`;
  - `ShouldQueue` jobs;
  - `Schedule::events()`;
  - AI Tool classes.

  `EntryPointInventoryTest` fails on any entry point that is not covered. The new
  `CrossTenantRouteTest` drives every tenant route generically: index (no Hotel B id in
  the body), bound model (`404`, row unchanged) and foreign-key input (`404`/`422`). It
  reuses the model dataset from `TenantIsolationTest`. Jobs are classified as `hotel` or
  `cross_hotel` (with a reason), and four jobs without `runForHotel` are audited. Leaks
  found are fixed with a regression test.
- **AI authorization audit (US2, R4–R6)**: `AdminToolset` and the agents' `tools()`
  are the inventory. `AiToolInventoryTest` checks four things:
  - every write tool is guarded or audited;
  - no Admin AI write touches users, staff roles or hotels (D17);
  - every guarded write has a permission case;
  - every Tool class is reachable from an agent.

  `AiToolFieldProjectionTest` looks for canary guest values in tool output. The AI list
  reads that do not use `ListResult` are brought under it.
- **Performance (US4, R6–R7)**: `BoundedListTest` covers every `GET` list route. The
  existing maximums stay (no breaking change). A separate `_bench` database with the
  SC-005 volume, `hardening:seed-volume` and `hardening:benchmark` (p95 < 1 s) find the
  indexes to add. They are added concurrently, in new migrations. The F7 recall test is
  extended to many hotels.
- **Queues and cache (US3, R8–R10)**: the queue and cache stay on Postgres for the MVP
  (spec Clarifications 2026-10-25); Redis is a post-MVP upgrade that needs no job code
  changes.
  - Four lanes from `QueueLane` on the existing database queue: `realtime` 60 s,
    `default` 300 s, `ai-batch` 1800 s, `imports` 3600 s.
  - 5 workers to start (2 / 1 / 1 / 1), one systemd service each, sized for the 2 GB host.
  - `queue:check-waits` runs on the scheduler every minute and alerts when a lane's oldest
    job is past its limit.
  - `QueueLaneTest` stops work going to a lane with no worker.
  - The cutover is one deploy, with the workers started first, and loses no work.
  - The dashboard response is cached for 60 s per hotel and date, after the permission
    check.
- **Production readiness (US5–US6, R11–R15)**:
  - `/up` stays shallow; `/api/health` is token-protected and checks the database, the
    queue and the cache.
  - The deploy stays in place as today, plus a final full health check that fails the
    workflow if the new version is unhealthy. Atomic releases and automatic rollback are
    deferred to after the MVP.
  - Sentry gets errors and 10% traces through `SentryScrubber`, tagged with hotel and
    operation.
  - `PlatformAlert` mail, sent immediately rather than queued, covers lane waits, repeated
    job failures (3 in 15 min) and spend ceilings.
  - `ops:readiness` checks the configuration.
  - systemd units supervise the lane workers and the scheduler.
  - The seeders are split into platform and demo seeders.
  - Backups are deferred to after the MVP (spec FR-029).
  - `docs/production-readiness-checklist.md` lists each item and its proof.
  - The frontend handoff covers error, empty and loading states.

## Technical Context

**Language/Version**: PHP 8.3 (runtime), CI on 8.4. Laravel 13. Bash for the deploy
scripts.

**Primary Dependencies**:
- Existing: `laravel/ai`, Sanctum, Pest 4.
- New: `sentry/sentry-laravel` (R13). No Horizon or Redis for the MVP (R8).
- On the server: nothing new.

**Storage**:
- PostgreSQL + pgvector. No new tables; only indexes the benchmark shows are needed
  (`CREATE INDEX CONCURRENTLY`, new migrations).
- The queue, cache and locks stay on Postgres (`database` connection and store).

**Testing**:
- Pest feature tests against real Postgres, run with `php artisan test --parallel`. The
  suite keeps the `sync` queue and the `array` cache, so CI does not change.
- The benchmark runs by hand against a `_bench` database.
- The post-deploy health check is rehearsed on staging (quickstart 9).

**Target Platform**: one Linux EC2 host (nginx + PHP-FPM), deployed by GitHub Actions over
SSH.

**Project Type**: web service (JSON API). The frontend gets a handoff.

**Performance Goals**: SC-005. p95 under 1 s for availability over 30 nights, the
assignment overlap check, a booking index page and knowledge search, at 100 hotels × about
15,000 reservations. Guest replies no more than 10% slower with 500 batch jobs waiting
(SC-003).

**Constraints**:
- No breaking API change; page maximums are unchanged (R6).
- No work is lost in the queue cutover (R9).
- Migrations must work with the previous code (spec Clarifications).
- Nothing personal or secret reaches Sentry (R13).
- Secrets and the Sentry org details stay out of the repository.

**Scale/Scope**:
- 14 new test files, 4 extended.
- About 14 new classes: entry-point discovery, health controller, scrubber, Sentry
  tagging, alert notification and sender, failure listener, `QueueLane`, 4 commands,
  2 seeders.
- About 20 jobs get a lane; queued notifications stay on `default`.
- 1 composer package, 2 systemd units, 6 docs.
- 0 new tables.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How |
| --- | --- | --- |
| I. PMS is the system of record | Pass | No domain data moves. The dashboard cache is a 60 s copy of PMS figures, and the AI keeps reading live figures through `DashboardSummary` (R10) |
| II. Preserve and evolve | Pass | Reuses the `TenantIsolationTest` dataset, `AdminToolset`/`GuardedTool`, `AuditedTool`, `ListResult` and `/up`. Existing per-feature tests are kept (R2, R4). Demo seeding is kept behind `SEED_DEMO`/local (R15) |
| III. Tenant isolation | Pass | This is the feature's purpose. Every entry point is discovered and checked (R1–R3). Each `withoutScope` use needs a written reason. Cache keys and Sentry tags carry the hotel id |
| IV. Permission-based authorization | Pass | The AI tool checks prove permission parity (R4). No new `Permission` cases: the health check is token-based infrastructure, not a hotel resource, and `ops:readiness` is a console command. If an audit finds an unguarded action, its case is added following CLAUDE.md steps 1–5 |
| V. AI acts through tools | Pass | Every write tool is guarded or audited; D17 is enforced by a test. Field projection is tested (R5). AI list reads are bounded (R6) |
| VI. Auditability and observability | Pass | Sentry errors and traces, lane wait and failure alerts, spend-ceiling alerts, health checks (R11–R14). Refused AI calls stay audited |
| VII. Data integrity and idempotency | Pass | The cutover loses no work (R9). Alerts are de-duplicated. Migrations still run in place, as today (R12) |
| VIII. Tested at the domain boundary | Pass | Feature tests per story (quickstart 1–3, 5–8, 10). Benchmark and deploy are rehearsed with recorded evidence (quickstart 4, 9) |
| IX. Spec-driven, incremental | Pass | Spec and clarify are done. The work splits into independent stories: the audits (P1) ship before the infrastructure (P2) |
| Security and privacy | Pass | Token-only full health check with no details (R11). The scrubber runs before sending (R13). Secrets are read through `config()`, and `ops:readiness` prints names only |
| MVP boundary | Pass | No new domain features. Redis, horizontal scaling and partitioning stay post-MVP |

**Post-design re-check**: still passing.
- No violations, so Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```text
specs/017-hardening-production-readiness/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── health-api.md
│   └── operations-interfaces.md
├── checklists/
│   └── requirements.md
├── frontend-changes.md      # error/empty/loading handoff (tasks phase)
└── tasks.md                 # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/
│   └── QueueLane.php                              # new: lanes + wait limits (R9)
├── Console/Commands/
│   ├── OpsReadinessCommand.php                    # new: ops:readiness (R14)
│   ├── CheckQueueWaitsCommand.php                 # new: queue:check-waits, every minute (R9)
│   ├── SeedBenchmarkVolumeCommand.php             # new: hardening:seed-volume (R7)
│   └── RunBenchmarkCommand.php                    # new: hardening:benchmark (R7)
├── Http/Controllers/
│   ├── HealthController.php                       # new: GET /api/health (R11)
│   └── DashboardController.php                    # 60 s per-hotel cache (R10)
├── Jobs/*.php                                     # onQueue(QueueLane::…) per job; runForHotel where the audit requires (R3, R9)
├── Notifications/
│   ├── *.php                                      # viaQueues() for queued notifications (R9)
│   └── PlatformAlertNotification.php              # new (R14)
├── Listeners/
│   └── AlertOnRepeatedJobFailures.php             # new: Queue::failing → alert (R14)
├── Services/AiCost/AiSpendCeiling.php             # also send PlatformAlert (R14)
├── Ai/Tools/Get*.php                              # move list reads to ListResult where unbounded (R6)
└── Support/Observability/
    ├── SentryScrubber.php                         # new: before_send / before_send_transaction (R13)
    ├── TagsSentryScope.php                        # new: hotel/group/operation tags (R13)
    └── PlatformAlerts.php                         # new: deduplicated, immediate alert mail (R14)
bootstrap/app.php                                  # dontReportDuplicates(); Sentry handler
config/
├── sentry.php                                     # new: env-driven, scrubber wired
├── health.php                                     # new: token
├── ops.php                                        # new: alert_email, failure window
└── seeding.php                                    # new: super admin from env, demo flag (R15)
database/
├── migrations/2026_10_2x_*_add_*_index*.php       # only if the benchmark requires (R7)
└── seeders/
    ├── DatabaseSeeder.php                         # base only; demo when local/SEED_DEMO (R15)
    ├── PlatformSeeder.php                         # new: extracted from HospitalitySeeder
    └── DemoSeeder.php                             # new
deploy/
└── systemd/
    ├── queue-worker@.service                      # new: one instance per lane worker (R9)
    ├── scheduler.service                          # new
    └── README.md                                  # new: instances to enable, read by QueueLaneTest
.github/workflows/
└── deploy.yml                                     # + final health check step (R12)
routes/api.php                                     # GET /api/health, outside api.key
tests/
├── Inventory/
│   ├── entry_points.php                           # new (R1)
│   └── ai_tools.php                               # new (R4, R5)
├── Support/
│   └── EntryPointDiscovery.php                    # new: routes, jobs, schedule, AI tools (R1)
├── Pest.php                                       # shared tenant-model dataset (moved)
└── Feature/
    ├── EntryPointInventoryTest.php                # new
    ├── CrossTenantRouteTest.php                   # new
    ├── JobTenantIsolationTest.php                 # new: jobs stay in their hotel (R3)
    ├── AiToolInventoryTest.php                    # new
    ├── AiToolFieldProjectionTest.php              # new
    ├── BoundedListTest.php                        # new
    ├── QueueLaneTest.php                          # new
    ├── QueueWaitCheckTest.php                     # new: lane wait alert, dedupe (R9)
    ├── DashboardCacheTest.php                     # new
    ├── HealthCheckTest.php                        # new
    ├── SentryScrubberTest.php                     # new
    ├── PlatformAlertTest.php                      # new
    ├── FreshInstallTest.php                       # new
    ├── OpsReadinessCommandTest.php                # new
    ├── KnowledgeRetrievalIsolationTest.php        # recall at many hotels (FR-017)
    ├── TenantIsolationTest.php                    # dataset moved to Pest.php
    ├── ConciergeGuestRestrictionsTest.php         # one case per guest tool
    └── AdminToolPermissionTest.php                # every guarded write has a case
docs/
├── production-readiness-checklist.md              # new (FR-033)
├── operations/deploy.md                           # new: deploy steps, lane cutover R9, worker services
├── operations/alerts-and-monitoring.md            # new: Sentry, uptime monitor, alert rules
├── operations/lane-load-check.md                  # new: SC-003/SC-004 load procedure
├── health-api-documentation.md                    # new
├── system-design-assessment-2026-09-19.md         # §7: F5, F9, F11 marked done
└── latest-changes-<date>.md                       # only if a breaking change appears
```

**Structure Decision**: The single Laravel project layout is kept.
- Inventories live under `tests/Inventory`, because they are test inputs, not runtime
  configuration.
- Observability helpers go in `app/Support/Observability`.
- Operational scripts and units go in a new `deploy/` directory, so they are versioned
  and reviewable.
- Server-only steps are in the `docs/operations/` runbooks.

## Delivery Order

1. **US1 and US2 audits.** Tests only, plus fixes for what they find. No infrastructure
   needed, so they can merge first.
2. **US4.** Bounded lists, then the benchmark and its indexes.
3. **US5 code.** Health endpoint, Sentry and scrubber, alerts, `ops:readiness`, seeders.
   These have no server dependency.
4. **US3 lanes.** Start the lane worker services and add the swap file on the server, then
   deploy the lane routing, the wait check and the dashboard cache.
5. **US5 deploy.** The final health check step in `deploy.yml`, rehearsed on staging.
6. **US6.** systemd, the frontend handoff, and checklist
   sign-off.

## Complexity Tracking

No constitution violations to justify.
