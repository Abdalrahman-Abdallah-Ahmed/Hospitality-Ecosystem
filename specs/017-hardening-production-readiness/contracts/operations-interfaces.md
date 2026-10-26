# Contract: Operational Interfaces

The non-HTTP interfaces this feature adds or changes. Commands, configuration, deploy
scripts and alert mail. The HTTP health checks are in [health-api.md](health-api.md).

## Artisan commands

### `php artisan ops:readiness [--deploy]`

Checks production configuration (R14, FR-032).

- **Output**: one line per check, `✓ <name>` or `✗ <name>: <problem>`. Values of secrets
  are never printed, only the variable name (for example
  `✗ ai.provider: ANTHROPIC_API_KEY is not set`).
- **Exit code**: `0` when every check passes, `1` otherwise.
- **`--deploy`**: skips the lane wait check, for use right after workers restart.

| Check name | Fails when |
| --- | --- |
| `app.env` | `APP_ENV` ≠ `production`, `APP_DEBUG` true, or `APP_KEY` empty |
| `app.secrets` | `API_KEY`, `WHATSAPP_VERIFY_TOKEN` or `WHATSAPP_APP_SECRET` empty |
| `ai.provider` | a configured provider has no key |
| `ai.models` | a configured model has no `ai_model_prices` row |
| `ai.ceilings` | a hotel group has no spend ceiling |
| `infra.queue` | the queue connection is not reachable |
| `infra.cache` | the cache store is not reachable |
| `infra.lanes` | a lane's oldest waiting job is past its limit (skipped with `--deploy`) |
| `ops.health_token` | `HEALTH_CHECK_TOKEN` empty or shorter than 32 characters |
| `ops.sentry` | `SENTRY_LARAVEL_DSN` empty |
| `ops.alerts` | `OPS_ALERT_EMAIL` empty, or the mailer is `log`/`array` |

### `php artisan queue:check-waits`

Scheduled every minute on the scheduler, as a command (never as a queued job). For each
`QueueLane`, it reads the oldest waiting job in `jobs` and sends one lane-wait alert per lane
per 15 minutes when that wait is past the lane's limit. Exit code `0` even when it alerts.

### `php artisan hardening:seed-volume [--hotels=100] [--years=3]`

Builds the SC-005 data set (R7). It refuses to run unless `DB_DATABASE` ends in `_bench`,
so it can never touch the dev or testing database.

### `php artisan hardening:benchmark [--runs=200]`

Prints p50 and p95 per operation (availability over 30 nights, the assignment overlap
check, one booking index page, knowledge search). Exits `1` if any p95 is 1 s or more.
With `--explain` it prints `EXPLAIN (ANALYZE, BUFFERS)` for the slowest run of each.

## Configuration (env → config)

Read only through `config()`, never `env()` outside config files.

| Env | Config key | Default | Notes |
| --- | --- | --- | --- |
| `QUEUE_CONNECTION` | `queue.default` | `database` (unchanged for the MVP) | `redis` after the post-MVP upgrade (R8) |
| `CACHE_STORE` | `cache.default` | `database` (unchanged for the MVP) | same |
| `HEALTH_CHECK_TOKEN` | `health.token` | empty (full check refuses everyone) | |
| `SENTRY_LARAVEL_DSN` | `sentry.dsn` | empty (Sentry off) | tests run with it empty |
| `SENTRY_TRACES_SAMPLE_RATE` | `sentry.traces_sample_rate` | `0.1` | FR-027a |
| `SENTRY_ENVIRONMENT` / `SENTRY_RELEASE` | `sentry.environment` / `sentry.release` | `APP_ENV` / set by deploy | |
| `OPS_ALERT_EMAIL` | `ops.alert_email` | empty | |
| `SEED_DEMO` | `seeding.demo` | `false` | FR-030 |

## Queue lanes

Queue names are the `App\Enums\QueueLane` values: `realtime`, `default`, `ai-batch`,
`imports` (see [data-model.md](../data-model.md#work-lane)). A job selects its lane with
`$this->onQueue(QueueLane::X->value)`. A queued notification selects it with `viaQueues()`.

## Deploy

### `deploy.yml` final step

After `queue:restart`, the workflow calls `GET /api/health` with `X-Health-Token` from the
`HEALTH_CHECK_TOKEN` GitHub secret, up to 5 times, 5 s apart (R12).

- **`200`**: the step passes.
- **Anything else after 5 tries**: the step fails, the workflow is red, and GitHub emails
  the owner. Nothing is rolled back.

### Systemd units

Units in `deploy/systemd/`:
- `queue-worker@.service`: a template, one instance per worker named `<lane>-<n>`, running
  `php artisan queue:work database --queue=<lane> --memory=128 --max-time=3600`. Instances
  at the start: `realtime-1`, `realtime-2`, `default-1`, `ai-batch-1`, `imports-1`;
- `scheduler.service`: `php artisan schedule:work`.

All use `Restart=always` and are enabled at boot.

## Alert mail

Sent to `OPS_ALERT_EMAIL` as `PlatformAlertNotification`, **sent immediately, not queued**,
so a stuck lane cannot hold back its own alert.

| Subject | Body holds | Never holds |
| --- | --- | --- |
| `[Ops] Job failing repeatedly: {JobClass}` | failure count, window, queue, last failure time, Sentry issue link if any | payload, guest data |
| `[Ops] AI spend ceiling reached: {hotel group name}` | group id, ceiling, spend today, time | per-guest data |
| `[Ops] Lane {lane} waiting {n}s` | lane, oldest wait, limit, number of jobs waiting | payload, guest data |

## Sentry event contract

What may leave the server (R13):
- exception class, scrubbed message, frames;
- tags `hotel_id`, `hotel_group_id`, `operation`, `queue`;
- user id;
- release and environment.

Everything listed under "Removed" in [data-model.md](../data-model.md#error-report-and-trace-spec-error-report)
is dropped by `SentryScrubber` before sending, for errors and for traces.
