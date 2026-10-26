# Deploy and background workers

How the API is deployed and how its background work runs on the single EC2
host. Spec 017 (Phase 14), research R8, R9 and R12.

For the MVP the deploy updates the live directory in place, and the queue,
cache and locks stay in PostgreSQL. Atomic release directories, automatic
rollback and Redis are post-MVP (see "Later" at the end).

## What a deploy does

`.github/workflows/deploy.yml` runs after the test workflow passes on `main`:

1. `git pull origin main` in `/var/www/api-hospitality`.
2. Fix ownership of `storage/` and `bootstrap/cache`.
3. `composer install --no-dev --optimize-autoloader`.
4. `php artisan migrate --force`.
5. `php artisan optimize`.
6. `php artisan queue:restart`: each worker finishes its current job, exits,
   and systemd starts it again on the new code.
7. **Health check.** Calls `GET /api/health` with the `X-Health-Token` header
   up to 5 times, 5 s apart. If it never answers `200`, the workflow fails and
   GitHub emails the owner. Nothing is rolled back: fix forward, or revert the
   commit and push.

Repository secrets the deploy needs: `EC2_HOST`, `EC2_SSH_KEY`,
`HEALTH_CHECK_TOKEN` (same value as `HEALTH_CHECK_TOKEN` in the server `.env`)
and `API_HEALTH_URL` (the full `https://…/api/health` URL).

## Queue lanes

Background work waits in four lanes, each with its own workers, so slow work
never delays a guest reply (`App\Enums\QueueLane`):

| Lane | Carries | Alert after | Workers |
| --- | --- | --- | --- |
| `realtime` | guest WhatsApp in and out, guest notices | 1 min | 2 |
| `default` | staff notifications and mail, short scheduled jobs | 5 min | 1 |
| `ai-batch` | insights, memories, recommendations, knowledge indexing | 30 min | 1 |
| `imports` | reservation imports | 60 min | 1 |

`php artisan queue:check-waits` runs every minute on the scheduler and mails
`OPS_ALERT_EMAIL` when a lane's oldest job is past its limit (once per lane
per 15 minutes).

### Checking a lane

```sql
select queue, count(*) as waiting, min(to_timestamp(available_at)) as oldest
from jobs where reserved_at is null group by queue;
```

```bash
systemctl status 'queue-worker@*' scheduler
journalctl -u queue-worker@realtime-1 --since "1 hour ago"
```

## One-time setup: lanes and workers

Do these in order. Workers must be listening on a lane **before** any code
sends jobs to it, or those jobs wait with nobody to run them.

1. **Add swap** (the host has 2 GB of memory and no swap):

   ```bash
   sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
   sudo mkswap /swapfile && sudo swapon /swapfile
   echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
   swapon --show
   ```

2. **Install and start the units** from `deploy/systemd/` (see its README). The
   five workers start and sit idle on their empty lanes; the existing worker
   keeps serving `default`.
3. **Deploy the lane routing** (the commit that adds `QueueLane`). New jobs go to
   their lanes. Jobs already waiting on `default` are still run by
   `queue-worker@default-1`, because that lane keeps its name.
4. **Retire the old worker** (the previous `queue:work` process or unit) once
   `systemctl status queue-worker@default-1` shows it running.
5. **Check memory** after a day: `ps -o rss,cmd -C php` shows memory per worker
   in KB. Raise a worker count only when there is room.

### Expected nightly `ai-batch` backlog

With one `ai-batch` worker, the nightly insights for every hotel plus knowledge
indexing (up to 300 s a job) can pass the 30-minute limit. After the first
night on staging, record the oldest `ai-batch` wait here. If it passes 30
minutes, spread the `CreateAiInsightsJob` dispatches over the night (one hotel
every few minutes) before raising the limit or adding a worker.

| Date | Hotels | Oldest `ai-batch` wait | Action |
| --- | --- | --- | --- |
| _to record_ | | | |

## Rules for every change

- Every job chooses a lane in its constructor with
  `$this->onQueue(QueueLane::X->value)`. `QueueLaneTest` fails otherwise.
- Every job's `$timeout` stays below the queue's `retry_after` (360 s): a job
  still running then is handed to a second worker.
- A new lane needs a worker instance in `deploy/systemd/README.md` and on the
  server before any job uses it.

## Later (post-MVP)

- **Redis + Horizon.** Set `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`,
  replace the `queue-worker@` units with one Horizon unit whose supervisors and
  `waits` come from `QueueLane`, and drop `queue:check-waits` (Horizon's
  `LongWaitDetected` replaces it). Jobs need no change. Drain the database
  queue with one `queue:work database` worker until `jobs` stays empty.
- **Atomic releases with rollback.** Release directories, a symlink switch,
  automatic rollback on a failed health check, and a CI check that stops
  migrations the previous code cannot work with (assessment F5).
