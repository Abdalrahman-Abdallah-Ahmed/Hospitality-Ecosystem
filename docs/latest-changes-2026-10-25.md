# Latest changes — 2026-10-25

## Hardening and production readiness (SPEC-102 to 105, Phase 14)

An audit of every way into tenant data and every AI tool, separate queue lanes,
a health check, error tracking and platform alerts. Spec:
`specs/017-hardening-production-readiness`.

### Breaking

- **Another hotel's record answers `404`, not `403`.** Any route that addresses
  one record by id (show, update, delete, and actions such as check-in, cancel
  or no-show) now looks the record up within the caller's hotel first. A record
  of another hotel is simply not found, exactly like one that does not exist,
  so the response no longer reveals that it exists. Before, it was loaded,
  sometimes validated (`422`), and then refused by the policy (`403`).
  `403` still means the caller's role lacks the permission.
  Frontend: `docs/frontend-changes-2026-10-25.md`.

### Added

- **`GET /api/health`**: the full health check (database, queue, cache),
  behind an `X-Health-Token`. See `docs/health-api-documentation.md`.
  `GET /up` is unchanged.
- **Queue lanes.** Background work runs in four lanes, `realtime`, `default`,
  `ai-batch` and `imports`, each with its own workers, so a guest's reply never
  waits behind slow work. Server setup: `docs/operations/deploy.md`.
- **Platform alerts** by mail to `OPS_ALERT_EMAIL`: a lane waiting past its
  limit, a job class failing three times in 15 minutes, an account reaching its
  AI spend ceiling. See `docs/operations/alerts-and-monitoring.md`.
- **Error tracking (Sentry)**, scrubbed of personal data before sending, plus
  traces for 10% of requests and jobs. Off until `SENTRY_LARAVEL_DSN` is set.
- **`php artisan ops:readiness`** checks the production configuration.
- The deploy ends with the health check and fails visibly when it does not
  pass.

### Changed

- **Dashboard** figures are reused for 60 seconds per hotel and day.
- **Seeding.** `migrate --seed` creates the demo hotel only in local and
  testing, or with `SEED_DEMO=true`. Outside those, the first super admin's
  credentials must come from `SEED_SUPER_ADMIN_*`; there is no built-in
  password.
- **AI tools** (no API change): the activities list returns at most 50
  activities with a total, and the front-desk stays list says when it is
  partial.
- Two jobs now run inside their hotel's scope, as every hotel job must:
  on-demand activity recommendations and knowledge article indexing.
