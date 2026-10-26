<!-- markdownlint-disable MD013 -->
# Alerts and monitoring

What tells the platform owner something is wrong, and where to look.
Spec 017 (FR-027, FR-027a, FR-028), research R13 and R14.

## Who is told

Every alert goes to `OPS_ALERT_EMAIL`. With it empty, alerts are only logged.
The mailer must be a real one (`MAIL_MAILER` not `log` or `array`), which
`php artisan ops:readiness` checks.

| Trigger | Sent by | At most |
| --- | --- | --- |
| A lane's oldest job waits past its limit (realtime 1 min, default 5 min, ai-batch 30 min, imports 60 min) | `queue:check-waits` on the scheduler, every minute | once per lane per 15 min |
| One job class fails 3 times within 15 min | `App\Listeners\AlertOnRepeatedJobFailures` | once per job class per 15 min |
| An account reaches its daily AI spend ceiling | `App\Services\AiCost\AiSpendCeiling` | once per account, scope and day |
| The full health check fails | Sentry Uptime monitor, and the deploy's last step | Sentry's own rules |
| A new unhandled error | Sentry issue alert rule | Sentry's own rules |

Each alert source has its own dedupe key, so one noisy source never hides
another. Alert mail is sent immediately, never queued, so a stuck lane cannot
hold back its own alert. No alert carries a job payload, guest contact details
or message text.

## Sentry

Errors, plus performance traces for 10% of requests and jobs.

- **Configuration** is environment only: `SENTRY_LARAVEL_DSN`,
  `SENTRY_ENVIRONMENT`, `SENTRY_RELEASE`, `SENTRY_TRACES_SAMPLE_RATE` (default
  `0.1`). The DSN and the organization details are in the team's password
  manager, never in the repository. With no DSN, Sentry is off.
- **Scrubbing.** `App\Support\Observability\SentryScrubber` runs on every error
  and every trace before it leaves the server. It removes request bodies,
  cookies and all but three headers; drops any key naming contact details,
  message text or a secret; masks emails and phone numbers in messages; and
  keeps only the user id. `send_default_pii` is hard-off, and queue breadcrumbs
  (which carry job payloads) are off.
- **Tags** that make an error actionable: `hotel_id`, `operation` (the route,
  or the job class), `queue`.
- **Each error is reported once** (`dontReportDuplicates`).

### One-time Sentry setup

Do this with the owner's Sentry access:

1. Create the Laravel project and put its DSN in the server `.env`.
2. Run `php artisan sentry:test` on staging; one event should arrive.
3. **Uptime monitor** on the full check, not `/up`: `/up` answers `200` even
   with the database down.
   - URL: the `API_HEALTH_URL`;
   - every 1 minute;
   - header `X-Health-Token` set to the token;
   - alert after 2 failures in a row, mail to the owner.
4. **Issue alert rule:** a new issue in production mails the owner.
5. **Rehearse every alert** (spec SC-009) and record each time to inbox in
   `docs/production-readiness-checklist.md`:
   - stop `queue-worker@realtime-*` and send a guest message;
   - make one job class fail three times;
   - break the staging database setting;
   - set a tiny AI ceiling and make one AI call.

## Reading the state

- Lanes: `select queue, count(*) from jobs where reserved_at is null group by queue;`
- Workers: `systemctl status 'queue-worker@*' scheduler`
- Failed jobs: `php artisan queue:failed`
- Configuration: `php artisan ops:readiness`
