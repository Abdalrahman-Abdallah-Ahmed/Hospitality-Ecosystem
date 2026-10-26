<!-- markdownlint-disable MD013 -->
# Production readiness checklist

What must be true before the first paying hotel goes live, how each item is
proven, and the evidence. Spec 017 (FR-033). Mark an item `proven` only with
its evidence filled in.

Owner: the platform owner signs off every row.

| Item | How it is proven | Evidence | Status |
| --- | --- | --- | --- |
| No hotel sees another's data (SC-001) | `EntryPointInventoryTest`, `CrossTenantRouteTest`, `JobTenantIsolationTest`, `TenantIsolationTest` | Green in CI on 2026-10-25 (272 isolation and AI tests) | proven |
| AI never acts beyond the user (SC-002) | `AiToolInventoryTest`, `AdminToolPermissionTest`, `AiToolFieldProjectionTest`, `ConciergeGuestRestrictionsTest`, `AiActivityCoverageTest` | Green in CI on 2026-10-25 | proven |
| Lists are bounded (SC-006) | `BoundedListTest`, `AiToolInventoryTest` | Green in CI on 2026-10-25 | proven |
| Lookups under 1 s at 100 hotels (SC-005) | `hardening:seed-volume` then `hardening:benchmark --runs=200` on a `_bench` database | 2026-10-25, 100 hotels, 1.58 M reservations, 475 k bookings, 20 k passages, dev machine (Docker Postgres). p95: availability 30 nights 53 ms, overlap check 21 ms, bookings page 11 ms, knowledge search 235 ms. No index needed. Re-run on a production-sized host before go-live. | proven (dev hardware) |
| Knowledge search recall at many hotels (FR-017) | `KnowledgeSearchToolTest` (50 hotels closer to the query) | Green on 2026-10-25 | proven |
| Lanes: slow work never blocks a guest (SC-003, SC-004) | `docs/operations/lane-load-check.md` on staging | _record idle and loaded p95, task mail time_ | open |
| Every lane has a worker; jobs choose lanes (FR-020, FR-022) | `QueueLaneTest`; `systemctl status 'queue-worker@*'` on production | Test green 2026-10-25; _server check_ | open |
| Dashboard cache (FR-023, SC-011) | `DashboardCacheTest` | Green on 2026-10-25 | proven |
| Deploy ends with a health check (FR-025, SC-007) | quickstart §9 on staging: a broken `.env` turns the workflow red | _record date_ | open |
| Health checks (FR-026) | `HealthCheckTest`; `curl` with and without the token on production | Test green 2026-10-25; _production check_ | open |
| Error tracking, scrubbed (FR-027, FR-027a, SC-008) | `SentryScrubberTest`; `php artisan sentry:test` on staging; review 100 events | Test green 2026-10-25; _Sentry review_ | open |
| Alerts reach the owner within 5 min (FR-028, SC-009) | `PlatformAlertTest`, `QueueWaitCheckTest`; rehearsal in `docs/operations/alerts-and-monitoring.md` | Tests green 2026-10-25; _rehearsal times_ | open |
| Fresh install, no demo data (FR-030) | `FreshInstallTest`, `DatabaseSeederTest` | Green on 2026-10-25 | proven |
| Workers and scheduler survive a reboot (FR-031) | Reboot staging, then `systemctl status 'queue-worker@*' scheduler` and `swapon --show` | _record date_ | open |
| Production configuration complete (FR-032) | `php artisan ops:readiness` on production exits 0 | _record date_ | open |
| Frontend states and build (FR-034) | Checklist in `docs/frontend-changes-2026-10-25.md`; `next build` clean | _frontend sign-off_ | open |
| Breaking changes documented (FR-035) | `docs/latest-changes-2026-10-25.md`, `docs/frontend-changes-2026-10-25.md` | Written 2026-10-25 | proven |

## Known post-MVP items

Accepted gaps for the MVP. Going live means accepting each risk.

| Item | Spec | Accepted risk until done |
| --- | --- | --- |
| Database backups and a tested restore | FR-029 | Losing the server or its disk loses all hotel data. If the database is on AWS RDS, check whether its automatic snapshots are on. |
| Redis + Horizon for queue, cache and locks | FR-021 | Queue polling, cache and locks add load to PostgreSQL; fine at pilot size. |
| Atomic release directories with automatic rollback | FR-024 | During a deploy, requests can briefly meet half-updated code; a broken deploy is fixed forward or reverted by hand. |
