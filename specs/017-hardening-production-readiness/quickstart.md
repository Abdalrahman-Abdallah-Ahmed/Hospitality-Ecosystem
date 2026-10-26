# Quickstart: Validating Hardening and Production Readiness

How to prove each user story works. Contracts: [health-api.md](contracts/health-api.md),
[operations-interfaces.md](contracts/operations-interfaces.md). Entities:
[data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d   # Postgres + pgvector
composer install
php artisan test --parallel                      # baseline: suite green before starting
```

The queue and cache stay on Postgres (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`),
so nothing else is needed. The test suite uses the `sync` queue and `array` cache.

## 1. Tenant isolation audit (US1)

```bash
php artisan test --filter='EntryPointInventoryTest|CrossTenantRouteTest|TenantIsolationTest' --parallel
```

**Expect**: all green.
- **Negative check**: add a throwaway `Route::get('/probe', …)` inside the tenant group
  without an inventory entry. `EntryPointInventoryTest` fails and names `GET api/probe`.
- Do the same with a throwaway `ShouldQueue` job that has no `runForHotel` and no inventory
  entry. The test fails and names the class.
- Remove both.

## 2. AI authorization audit (US2)

```bash
php artisan test --filter='AiToolInventoryTest|AdminToolPermissionTest|AdminToolIsolationTest|AiToolFieldProjectionTest|ConciergeGuestRestrictionsTest' --parallel
```

**Expect**: all green.
- **Negative check**: register a write tool in `AdminToolset` with no permission.
  `AiToolInventoryTest` fails and names it.
- Add `User::class` to any write's `writes`. The D17 check fails.

## 3. Bounded lists (US4)

```bash
php artisan test --filter=BoundedListTest --parallel
```

**Expect**: every list route returns at most its maximum or a `422` for `per_page=10000`,
with pagination `meta`/`links`.

## 4. Performance benchmark (US4, SC-005)

```bash
createdb -h 127.0.0.1 -U postgres hospitality_bench   # once
DB_DATABASE=hospitality_bench php artisan migrate --force
DB_DATABASE=hospitality_bench php artisan hardening:seed-volume --hotels=100 --years=3
DB_DATABASE=hospitality_bench php artisan hardening:benchmark --runs=200 --explain
```

**Expect**: every p95 is under 1 s, and the exit code is `0`. Record the table in the
readiness checklist. `hardening:seed-volume` refuses to run if `DB_DATABASE` does not end
in `_bench`.

## 5. Lanes (US3)

```bash
php artisan test --filter='QueueLaneTest|QueueWaitCheckTest'
# one terminal per lane:
php artisan queue:work database --queue=realtime
php artisan queue:work database --queue=default
php artisan queue:work database --queue=ai-batch
php artisan queue:work database --queue=imports
```

- Follow the load procedure in `docs/operations/lane-load-check.md` (task T032): it
  dispatches 500 `CreateAiInsightsJob` jobs, then sends a signed test webhook to
  `POST /api/whatsapp` with a fake AI provider, and times the reply on an idle and a loaded
  system.
- **Expect**: `select queue, count(*) from jobs group by queue` shows `ai-batch` with a
  backlog. The WhatsApp job
  runs on `realtime` within seconds (SC-003). A task assignment notification goes out on
  `default` (SC-004).
- Stop the `realtime` worker, send another message, wait 60 s and run
  `php artisan queue:check-waits`. **Expect**: one lane-wait alert mail in the log mailer.
  Running it again within 15 minutes sends no second mail. Restart the worker: the waiting
  job is processed.

## 6. Dashboard cache (US3, SC-011)

```bash
php artisan test --filter=DashboardCacheTest
```

**Expect**:
- two calls within 60 s run the summary queries once (query-count assertion);
- a call for Hotel B right after Hotel A shows Hotel B's figures;
- an employee without `dashboard.view` gets `403` even when a cached entry exists.

## 7. Health checks (US5)

```bash
php artisan test --filter=HealthCheckTest
curl -i localhost:8000/up
curl -i localhost:8000/api/health                                      # 401
curl -i -H "X-Health-Token: $HEALTH_CHECK_TOKEN" localhost:8000/api/health   # 200
```

Point the queue at a wrong database in a local `.env` and call again with the token.
**Expect**: `503` with `"queue": "fail"` and no error text.

## 8. Error tracking and alerts (US5)

```bash
php artisan test --filter='SentryScrubberTest|PlatformAlertTest' --parallel
```

**Expect**:
- planted phone, email, message text and token values never survive the scrubber, for
  errors or traces;
- 3 failures of one job class in 15 minutes send one alert, and a fourth does not send a
  second;
- a failure of another class still alerts.

On staging with a real DSN, run `php artisan sentry:test`. Then trigger a failing
`SendWhatsAppMessageJob`. **Expect**: one Sentry issue tagged with `hotel_id` and
`queue=realtime`, and no phone or message text in it.

## 9. Post-deploy health check (US5, SC-007)

On staging:

1. Push a commit. **Expect**: the workflow's final health check step passes and the
   workflow is green.
2. Break the staging `.env` (for example a wrong `DB_HOST`) and push again. **Expect**: the
   health check step fails within 2 minutes, the workflow is red, and GitHub emails the
   owner. Restore the `.env`.

## 10. Production readiness (US6)

```bash
php artisan test --filter=FreshInstallTest
php artisan ops:readiness            # on the production host before go-live
```

- **Expect**: `FreshInstallTest` leaves a super admin and default categories, with no demo
  hotel. `ops:readiness` exits `0`.
- Reboot the staging host. **Expect**: `systemctl status 'queue-worker@*' scheduler` shows all
  five workers and the scheduler active, and `swapon --show` lists the swap file.
- Go through `docs/production-readiness-checklist.md`. Every row is `proven`, and backups,
  Redis and atomic deploys appear under "Known post-MVP items".

## Done

```bash
./vendor/bin/pint
php artisan test --parallel
```

Both clean, and the checklist is updated.
