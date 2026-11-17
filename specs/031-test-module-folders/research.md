# Research: Module Test Folders and Cached-Config Guard (Stage 2)

All findings were checked against the tree at `23b39bb` (branch point) on 2026-11-16.

## R1 — The move list

**Decision**: Move all 207 files in `tests/Feature/*.php` with one scripted `git mv` per file, using the design's table (item 8) with the Stage 1 update. No file needs a new assignment.

**Findings**:

- Comparing the listing with the table, the only difference is that the table's `PermissionAuthorizationTest` is now `PermissionAuthorizationResourcesTest` and `PermissionAuthorizationActionsTest`. Both go to `Invariants/`.
- Resulting counts: Invariants 22, Core 32, Rooms 10, Reservations 34, Housekeeping 7, Tasks 5, Activities 10, Concierge 21, Recommendations 19, Knowledge 9, AiPlatform 22, Finance 5, ImportExport 10, Superadmin 12 + 1 = 13. That is 207 moved files, and 219 files in `tests/Feature/**` with the 12 that are already in `Superadmin/`.
- The list is re-checked just before the move (spec FR-005). If a file was added since, it is assigned and recorded in §7 first.

**Alternatives considered**: Moving by hand, or in several commits per folder. Rejected: one scripted commit is reviewable as pure renames, and the suite is green before and after it.

## R2 — Cross-file dependencies (isolation risk)

**Decision**: No helper move is expected. The isolation check (spec FR-013) is still run, because the static check cannot see every runtime dependency.

**Findings** (static):

- **Functions:** the 458 top-level functions declared in test files (outside `tests/Pest.php`) are each called only from their own file. None is called from another file, in any folder. Stage 1 and earlier work had already moved shared helpers to `tests/Pest.php`.
- **Named datasets:** the 8 `dataset('...')` declarations are each used only by `->with('...')` in the same file.
- **Duplicate definitions:** no function name is declared twice, so there is no "Cannot redeclare" risk.
- **Pest bootstrap:** `pest()->extend(TestCase::class)->in('Feature')` applies recursively, so files in subfolders keep `Tests\TestCase`, as `Superadmin/` already shows.

**Remaining risk**: an ordering dependency, where a test passes only after another file has left state behind (static caches, config, the `TenantContext`). `RefreshDatabase` and the `TestCase` reset make this unlikely. If it happens, it is recorded as "another cause" (FR-014).

## R3 — Paths resolved from `__DIR__`

**Decision**: Only `CrossTenantRouteTest` changes. The fix goes in the move commit.

**Findings**:

- `tests/Feature/CrossTenantRouteTest.php:52` uses `require __DIR__.'/../../bootstrap/app.php'`. One level deeper, that resolves to `tests/bootstrap/app.php`, so it becomes `dirname(__DIR__, 3).'/bootstrap/app.php'`. The docblock above it (lines 40–44) stays.
- `tests/Pest.php:846` (`__DIR__.'/Fixtures/...'`) and `tests/Fixtures/knowledge/build.php` do not move.
- Other tests use `base_path('tests/Inventory/...')`, which does not depend on the test's location.
- `EntryPointInventoryTest` collects `it()` descriptions with `Finder::create()->files()->in(base_path('tests/Feature'))`. Finder searches subfolders by default, so the inventory still sees every test after the move.

## R4 — Guard placement and behaviour

**Decision**: Put the check in `tests/Pest.php` right after the last `use` import (line 95), before `pest()->extend(...)` (line 108). It calls `fwrite(STDERR, ...)` and `exit(1)` when `dirname(__DIR__).'/bootstrap/cache/config.php'` exists.

**Rationale**:

- Pest loads `tests/Pest.php` while it builds the suite, before any test file runs and before any `TestCase::setUp()`. So the guard runs before `RefreshDatabase`, before parallel worker databases are created, and before `CrossTenantRouteTest` boots its own application to build its dataset. That last one would read the cached config too.
- In parallel mode the parent process builds the suite, so it stops before any worker starts. This is to be confirmed during the guard check, by the message appearing once and no `_test_<n>` connection being made.
- `php artisan test` itself (Collision's command) only starts the Pest process. It does not connect to a database.
- `composer test` runs `config:clear` before Pest, so the guard never triggers through it.

**Alternatives considered**:

- **Deleting the cache in the guard**: rejected by the design, because a run must not change the developer's state silently.
- **A check in `TestCase::setUp()`**: rejected because it is too late. Dataset building and the parallel set-up already ran.
- **A check in `phpunit.xml`'s bootstrap**: it would need a new bootstrap file, and spec FR-001 forbids changing `phpunit.xml`.

## R5 — Two folder paths in one run

**Decision**: Record the check as already known, then confirm it once in serial mode.

**Findings**: Stage 0's verification (§5, "Found during verification") found that parallel mode accepts only one path and serial mode accepts several. Stage 2 runs `php artisan test tests/Feature/Tasks tests/Feature/Finance` once. The reported count must equal the sum of the two folders' counts.

## R6 — G2 expectation and the Q2 fallback

**Decision**: Time each module folder serially (1 cold run, then 3 warm runs). Apply Q2 (`--parallel --processes=4`) to the slowest module only if its warm median is 180 s or more.

**Estimate** (from stage log §3.2 and §3.4):

- Summed time shrinks with fewer workers: 2,944 s at 12, 2,234 s at 8, 1,601 s at 4. That is about 160 s less per worker removed, which projects to about 1,100 s serial (ratio ≈ 0.38 of the 12-worker sums).
- Reservations: 505.8 s × 0.38 ≈ **190 s** serial. That is close to the target, so G2 may be missed narrowly. Core ≈ 140 s, Superadmin ≈ 127 s, Invariants ≈ 205 s.
- **With Q2:** Reservations at 4 processes is about 275 s summed ÷ 4 ≈ 70 s, plus the worker start-up and migration, so roughly 90 s. That meets the target.

**Rule**: The figures in §7 decide. If Q2 is applied, its runs follow the same protocol: 1 cold run discarded (new worker databases), then 3 warm runs.

**Time budget**: one serial pass over the 13 modules is about 15 minutes, so 4 passes take about 1 hour. The isolation run of each folder (FR-013) counts as that folder's cold run when it follows directly, so no extra cold pass is needed.

## R7 — Path references in living documents

**Decision**: Change only the path text on these lines:

| File | Line | Old → new |
| --- | --- | --- |
| `CLAUDE.md` | 99 | `tests/Feature/PermissionAuthorizationResourcesTest.php` → `tests/Feature/Invariants/…` |
| `CLAUDE.md` | 188 | `tests/Feature/TenantIsolationTest.php` → `tests/Feature/Invariants/…` |
| `CLAUDE.md` | 191 | `tests/Feature/PermissionAuthorizationResourcesTest.php` → `tests/Feature/Invariants/…` |
| `docs/usage-metering-api-documentation.md` | 223 | `CostVisibilityTest` → `tests/Feature/Invariants/…` |
| `docs/ai-cost-attribution-api-documentation.md` | 134 | `AiCapabilityCoverageTest` → `tests/Feature/AiPlatform/…` |
| `docs/ai-cost-attribution-api-documentation.md` | 334 | `AiCostAttributionTest` → `tests/Feature/AiPlatform/…` |
| `deploy/systemd/README.md` | 20 | `QueueLaneTest` → `tests/Feature/Invariants/…` |

**Other mentions**:

- Bare test names without a path (such as `CrossTenantRouteTest` at `CLAUDE.md:168`) stay as they are.
- The grep for leftover paths runs again after the move, so a reference missed here is still found.
- `QueueLaneTest` reads only the `queue-worker@` lines of the README, and it is rerun after the edit.

**Excluded as historical**: `docs/PGRIP_*`, `docs/designs/*`, `specs/**` and the review reports in the repository root.

## R8 — Safety checks around the guard test

**Decision**:

1. Run the pre-flight checks.
2. Run `php artisan config:cache`.
3. Run the two refused commands.
4. Run `composer test`. It clears the cache itself.
5. Confirm `bootstrap/cache/config.php` is gone.

**Rationale**:

- The two refused commands exit before any connection, so they cannot reach the dev database.
- `composer test` is the only command run while the cache exists that gets past the guard, and it clears the cache first.
- The dev database counts (60 tables, 119 `migrations`, 17 `users` at the end of Stage 1) are taken before step 2 and again at the end of the stage.
- If the cache is still present after step 4, the work stops.
