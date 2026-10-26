# Background workers and the scheduler

These units keep the queue lanes and the scheduler running on the API host
(spec 017, FR-020 and FR-031). Install them once by following
`docs/operations/deploy.md`.

## Worker instances to enable

One instance per worker, named `<lane>-<n>`. Sized for the 2 GB host; raise a
count only after checking memory per worker (`ps -o rss,cmd -C php`).

| Instance | Lane | Carries |
| --- | --- | --- |
| `queue-worker@realtime-1` | `realtime` | guest WhatsApp in and out, guest notices |
| `queue-worker@realtime-2` | `realtime` | second worker, so one long reply never blocks the next guest |
| `queue-worker@default-1` | `default` | staff notifications and mail, short scheduled jobs |
| `queue-worker@ai-batch-1` | `ai-batch` | insights, memories, recommendations, knowledge indexing |
| `queue-worker@imports-1` | `imports` | reservation imports |

`tests/Feature/QueueLaneTest.php` reads this table: every lane in
`App\Enums\QueueLane` must have at least one instance here.

## Install

```bash
sudo cp deploy/systemd/queue-worker@.service deploy/systemd/scheduler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now queue-worker@realtime-1 queue-worker@realtime-2 \
    queue-worker@default-1 queue-worker@ai-batch-1 queue-worker@imports-1 scheduler
systemctl status 'queue-worker@*' scheduler
```

The deploy runs `php artisan queue:restart`: each worker finishes its current
job, exits, and systemd starts it again on the new code.
