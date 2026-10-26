# Data Model: Hardening and Production Readiness

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

This feature adds **no new database tables** and no new tenant-owned models. Its
"entities" are configuration, test inventories, cache entries and operational artifacts.
Any database change is limited to indexes found necessary by the benchmark (R7). Each of
those goes in a new migration and is listed here when added.

---

## Entry-point inventory (spec: Entry-point inventory)

**Where**: `tests/Inventory/entry_points.php` (returns an array). The list of entry points
is discovered when the tests run (R1); this file holds only exceptions and explicit
coverage.

| Key | Shape | Rule |
| --- | --- | --- |
| `routes` | `route name or "METHOD uri"` → `{covered_by: string}` | For routes the generic checks (R2) cannot drive. `covered_by` must be an existing test description |
| `jobs` | `class-string` → `{scope: "hotel", covered_by}` or `{scope: "cross_hotel", reason}` | Every `ShouldQueue` class must appear. `reason` must not be empty |
| `scheduled` | job class or command → `{scope, reason?}` | Every `Schedule::events()` entry must appear |
| `global_data` | model class → `{reason, read_only_to_hotels: true}` | Intended global records (FR-007) |
| `route_exceptions.bounded_list` | route → `{reason}` | `GET` routes that return a fixed shape, not a list (R6) |

**Validation** (`EntryPointInventoryTest`):
- every discovered entry point is covered or listed;
- every `covered_by` names a real test;
- no entry names a class or route that no longer exists, so stale entries fail too.

## AI tool inventory (spec: AI tool inventory)

**Where**:
- Admin AI: `App\Ai\Tools\Admin\AdminToolset` (existing registry: tool, `kind`,
  `permissions`, `adminOnly`, `writes`).
- Other agents: each agent's `tools()`.
- `tests/Inventory/ai_tools.php` holds only:
  - `unused` (a Tool class reachable from no agent, with a reason);
  - `pii_fields` (a tool → the guest fields it may return, for R5).

**Rules** (`AiToolInventoryTest`, R4):
- a `WritesData` tool is wrapped in `GuardedTool` with permissions or `adminOnly`, or in
  `AuditedTool`;
- no Admin AI write lists `User`, `StaffRole`, `Hotel` or `HotelSetting` in `writes`;
- each guarded write has an allowed and a denied case in `AdminToolPermissionTest`.

## Work lane (spec: Work lane)

**Where**: `App\Enums\QueueLane` (backed enum) plus the worker services in
`deploy/systemd/`. The lanes live on the existing `database` queue connection (R8).

| Case | Value | Wait alert (s) | Workers (start) | Carries |
| --- | --- | --- | --- | --- |
| `Realtime` | `realtime` | 60 | 2 | guest WhatsApp in/out, proactive sends, guest request notices |
| `Default` | `default` | 300 | 1 | queued notifications and mail; short scheduled jobs that are not AI work (housekeeping day start, recommendation expiry, proactive trigger evaluation, prune and purge, usage counters, cost overrun flags, recommendation outcome matching) |
| `AiBatch` | `ai-batch` | 1800 | 1 | LLM and embedding work only: insights, memory extraction, recommendation generation, knowledge indexing |
| `Imports` | `imports` | 3600 | 1 | reservation import prepare and write |

- `QueueLane::waitLimitSeconds()` returns the limit read by `queue:check-waits` (R9).
- **Validation** (`QueueLaneTest`):
  - every `ShouldQueue` job and queued notification resolves to a `QueueLane` value;
  - every value has at least one `queue-worker@<lane>-<n>` instance listed in
    `deploy/systemd/`;
  - every job's `$timeout` is below the database connection's `retry_after` (360 s).

## Health status (spec: Health status)

Not stored. Produced per call; the shape is in [contracts/health-api.md](contracts/health-api.md).

| Field | Values |
| --- | --- |
| `status` | `healthy` · `unhealthy` |
| `checks.database` / `checks.queue` / `checks.cache` | `ok` · `fail` |

## Dashboard cache entry

| Field | Value |
| --- | --- |
| Key | `dashboard:{hotel_id}:{Y-m-d}` |
| Value | The final dashboard response body (arrays, no models) |
| TTL | 60 s |
| Store | the default cache store (`database` for the MVP, R8) |

Never read before the `dashboard.view` check. Never shared across hotels, because the
hotel id is in the key.

## Error report and trace (spec: Error report)

Not stored by us; sent to Sentry after `SentryScrubber` (R13).

| Kept | Removed |
| --- | --- |
| exception class, scrubbed message, stack trace, release, environment | request body, cookies, unsafe headers |
| tags: `hotel_id`, `hotel_group_id`, `operation`, `queue` | user email or name, guest phone or email, message text, tokens, keys, `identity_hash` |
| user id only | job payload |

## Alert (spec: Alert)

Sent as mail to `OPS_ALERT_EMAIL`. It is de-duplicated with a cache key, not stored.

| Trigger | Dedupe key | Window |
| --- | --- | --- |
| Lane wait (`queue:check-waits`) | `alert:lane-wait:{lane}` | 15 min |
| Repeated job failure (≥ 3 failures of one class in 15 min) | `alert:job-failed:{class}` | 15 min |
| Spend ceiling reached | `alert:spend-ceiling:{hotel_group_id}:{date}` | 1 day |
| Health check failed | (Sentry Uptime monitor on `/api/health` with the token header; 2 failures in a row) | Sentry default |

Failure counting uses `alert:job-failures:{class}` as a cache counter with a 15-minute
expiry.

## Readiness checklist item (spec: Readiness checklist item)

**Where**: rows in `docs/production-readiness-checklist.md`.

| Column | Meaning |
| --- | --- |
| Item | The FR it proves (FR-024 … FR-034) |
| How proven | Test name, artisan command, or runbook section |
| Evidence | Date and result (for example "benchmark p95 0.42 s, 2026-11-…") |
| Owner | Who signs it off |
| Status | `open` · `proven` |

The checklist ends with a "Known post-MVP items" section (backups, Redis, atomic deploys), so the go-live
sign-off records them as accepted gaps (spec FR-029, FR-021).

## Seeder split (FR-030)

| Seeder | Runs | Creates |
| --- | --- | --- |
| `AiModelPriceSeeder` | always | model prices |
| `PlatformSeeder` (new, extracted from `HospitalitySeeder`) | always | super admin (credentials from env), default teams and categories |
| `KnowledgeBaseSeeder` | always | global knowledge only |
| `DemoSeeder` (new; calls the demo part of `HospitalitySeeder` and `GuestReservationSeeder`) | `local`/`testing`, or `SEED_DEMO=true` | sample hotel, rooms, guests, reservations |
