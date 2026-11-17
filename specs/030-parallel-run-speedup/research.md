# Research: Faster Full Parallel Run (Stage 1)

All figures below come from the Stage 0 8-worker JUnit files (`storage/test-reports/w8-r2..r4.xml`, the 3 warm runs). Each is the per-test-case median, summed. A scratchpad script computed them; it is not committed.

## R1 — How `CrossTenantRouteTest` is sliced

**Decision**: Slice by the route's **first URI segment after `api/`**, using an explicit map from part name to list of segments. There are four parts. Every `/api/superadmin/...` route counts as the single segment `superadmin`.

| Part (file) | Segments | Cases | Summed (s) |
| --- | --- | --- | --- |
| `CrossTenantRouteReservationsTest` | `reservation`, `stays`, `availability` | 68 | 50.6 |
| `CrossTenantRouteOperationsTest` | `room`, `room-types`, `housekeeping`, `maintenance`, `task`, `task-category`, `team` | 69 | 47.8 |
| `CrossTenantRouteGuestServicesTest` | `guest`, `booking`, `activity`, `activity-category`, `recommendation`, `proactive-messages`, `voucher-templates`, `transaction` | 80 | 61.6 |
| `CrossTenantRoutePlatformTest` | `ai-activity`, `ai-insights`, `analytics`, `available-channels`, `check-paired`, `conversations`, `dashboard`, `hotel`, `hotel-policy`, `knowledge-base-articles`, `knowledge-documents`, `permissions`, `staff-roles`, `usage`, `user`, `users`, `whatsapp-device`, `superadmin` | 98 | 57.2 |
| **Total** | 45 segments | 315 | 217.2 |

The largest part (61.6 s) is 19% under the 75.6 s limit.

**Rationale**:

- Each row takes about 1.5 s whatever its rule (`superadmin` rows take about 0.3 s), so time follows the route count closely. Grouping segments by domain gives even parts without splitting any one segment.
- A segment is stable: a new route under an existing prefix joins its part automatically. Only a new prefix needs a map entry, and the coverage test (R2) names it.
- All six `it()` blocks run in every part with the same slice. The split is by rows, not by rules: the rules' times are uneven (71 / 50 / 18 / 61 / 15 / 2.5 s), and slicing by rule would leave the `index` part close to the limit.

**Alternatives considered**:

- *Catch-all part* ("everything not claimed by the others"): no new-prefix maintenance, but a new prefix lands there silently and the part grows unseen. Rejected; the spec's User Story 2 scenario 3 wants a named failure.
- *Hash or modulo of the route key*: perfectly even, but a reader cannot tell which part checks a route, and a module run in Stage 2 cannot pick parts by domain. Rejected.
- *3 parts*: 72 s each, within 5% of the limit. Rejected for lack of margin under contention.

## R2 — Coverage test

**Decision**: One plain test (not a dataset) in `CrossTenantRouteOperationsTest`, the part with the lowest summed time: `it('puts every tenant route in exactly one cross-tenant part', ...)`. For each driven rule (`index`, `bound`, `super_admin`, `foreign_key`), it checks:

- the union of the four slices equals the unfiltered list;
- no route is in two slices;
- no segment is listed under two parts in the map.

A failure names the route and its segment.

**Rationale**: It runs on the already-booted route list in milliseconds and adds exactly **1** test case. G3's expected count is therefore **3,598**.

**Alternatives considered**: a coverage test in every part (adds 4 cases, says the same thing four times). Rejected.

## R3 — What moves to `tests/Pest.php` for the cross-tenant parts

**Decision**: Move these, unchanged apart from the two points below:

- `crossTenantRoutesFor()`, which gains an optional `?string $slice = null` argument (`null` = full list);
- `crossTenantRoute()`;
- `crossTenantAdmin()`.

Add two new functions:

- `crossTenantSlices(): array<string, list<string>>`, the map in R1;
- `crossTenantSegment(string $uri): string`.

Changes to the moved builder:

- the bootstrap path becomes `__DIR__.'/../bootstrap/app.php'`, because the file now sits one level higher;
- the slice filter is applied **after** the cached `$byRule` lookup. The application is still booted once per process, and the error handlers and facades are still handed back (FR-011).

`tests/Pest.php` gains the imports it does not have: `Illuminate\Contracts\Console\Kernel`, `Illuminate\Foundation\Bootstrap\HandleExceptions`, `Illuminate\Support\Facades\Facade` and `Tests\Support\EntryPointDiscovery`. It already has `Route`, `Hotel` and `User`.

**Rationale**: All four parts call these functions. Copying them would end the run with "Cannot redeclare". The existing `crossTenantRuleFor`, `crossTenantBoundModels` and `crossTenantForeignKeys` are already in `tests/Pest.php` (lines 1940–2020).

**Alternatives considered**: a separate filter argument per `with()` (a closure per part). Rejected: the map in one place is what the coverage test reads.

## R4 — How `PermissionAuthorizationTest` is split

**Decision**: Two parts of consecutive `it()` blocks, in source order, split between line 324 (end of the no-show dataset) and line 326 (`it('never gives employees without a role the voucher permissions'`).

| Part (file) | Blocks | Summed (s) |
| --- | --- | --- |
| `PermissionAuthorizationResourcesTest` | lines 85–324: role defaults, the **"read a resource" dataset** (35 rows, 17.9 s), export, housekeeping, stays, room assignment, no-shows | 38.8 |
| `PermissionAuthorizationActionsTest` | lines 326–656: vouchers, role replacement, guests, other hotel, admin-only areas, unreadable role, knowledge documents, booking actions, recommendations, imports | 40.1 |
| **Total** | | 78.9 |

**Rationale**:

- The two parts are as even as a single boundary in source order allows.
- The "read a resource" dataset, which `CLAUDE.md` means by "the dataset", stays whole in the Resources part.
- No test in this file sums across a loop.
- The spec's fallback (slicing the dataset) is not needed.

**Helpers**:

- Used by both parts, so they move to `tests/Pest.php` unchanged: `permissionApiHeaders()`, `hotelForPermissions()`, `employeeWithPermissions()`.
- Used by one part only, so they stay in it: `availabilityProbeUrl()` (Resources; also called inside its dataset at file load), `guestForPermissions()` (Actions).

**Note**: The Stage 0 JUnit files group `RoomTypePermissionAuthorizationTest` (2.9 s) separately. It is a different file and is not touched.

## R5 — Imports in each part

**Decision**: Each part starts from the original's full `use` list, `uses(RefreshDatabase::class)` and `beforeEach`; neither file has an `afterEach`. Pint (Laravel preset, `no_unused_imports`) then removes the imports a part does not use.

**Rationale**: The design's rule exists so that nothing a test needs goes missing. Pint is the project's only formatter and runs before every commit. Keeping unused imports would fail Pint's check. The stage log notes this.

## R6 — References to the split files

**Decision**: These are the live references; each one is updated.

| Where | Now | After |
| --- | --- | --- |
| `CLAUDE.md:99` | dataset in `tests/Feature/PermissionAuthorizationTest.php` | `tests/Feature/PermissionAuthorizationResourcesTest.php` |
| `CLAUDE.md:168` | generic checks (`CrossTenantRouteTest`, …) | `CrossTenantRoute*Test` |
| `CLAUDE.md:191` | dataset in `tests/Feature/PermissionAuthorizationTest.php` | `tests/Feature/PermissionAuthorizationResourcesTest.php` |
| `tests/Feature/EntryPointInventoryTest.php:90` | message "…is driven by CrossTenantRouteTest…" | "…is driven by the CrossTenantRoute*Test parts…" (message text only) |
| `tests/Pest.php:1615` | comment naming `CrossTenantRouteTest` | `CrossTenantRoute*Test` |

**No change** to:

- `docs/usage-metering-api-documentation.md:223` (names `CostVisibilityTest`);
- `docs/ai-cost-attribution-api-documentation.md:134, 334` (name `AiCapabilityCoverageTest`, `AiCostAttributionTest`). The design listed them "only if affected", and they are not.

Historical records stay as written: `concepts_brief.md`, `goldfish_review_report.md`, the PGRIP WP doc, `docs/production-readiness-checklist.md` (dated evidence), specs and the stage log.

`CLAUDE.md` line 188 does not name a split file; the design's "188" was line 168.

## R7 — Timing budgets: what JUnit `time` measures

**Finding**: Stage 0 8-worker JUnit times:

- "decides 100 at once in under two seconds": 3.90–4.07 s;
- "arrivals of a 500-room hotel": 3.42–3.52 s;
- at 12 workers: 5.2–5.5 s.

Every run passed its 4.0 s budget. JUnit `time` covers the whole test, including the setup that creates 100 recommendations or 500 rooms. The budget times only the request. JUnit time is therefore an upper bound on what the budget checks.

**Decision**: Apply the rule the user and the design give: raise to the slowest × 1.5, rounded up to the next 0.5 s, when an observed JUnit time is at or above 3.0 s. Stage 0's figures already meet that condition, so the expected result is a raise:

- `RecommendationQueueTest` to about 6.5 s (4.07 × 1.5 = 6.1);
- `StayListTest` to about 5.5 s (3.52 × 1.5 = 5.3).

The stage log records that the trigger is the JUnit time, which includes setup, so the raise is larger than the timed request needs.

**Alternatives considered**: reading the timed request's own elapsed time. That needs the test to print or log it, which is a test change not in the brief. It goes into the stage log as a possible follow-up, not done here.

## R8 — `composer test` and argument passing

**Decision**:

```json
"test": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan config:clear --ansi @no_additional_args",
    "@php artisan test --parallel --processes=8"
]
```

- Arguments after `--` (for example `composer test -- --filter=X --log-junit=...`) go to the last command only, because `@no_additional_args` stops them reaching `config:clear`.
- The exit-check runs use `composer test -- --log-junit=storage/test-reports/s1-r<k>.xml`, so they measure the script itself.

**Rationale**: This is the design's item 3 word for word. `disableProcessTimeout` must come first so it applies to the commands after it.

## R9 — Revised G1, if missed

**Decision**: If the warm median is above 210 s, the revised G1 is **the measured median rounded up to the next 10 s**. The reason given is the floor: summed time ÷ 8 workers, plus the measured per-run start-up (wall time − summed ÷ 8).

**Rationale**: After the split no single file sets the floor. The total work over 8 workers does: Stage 0 measured ~2,234 s summed, so about 280 s. A goal below that floor cannot be met by test layout alone. The design's Non-Goals (no DB setup changes, no container tuning) rule out the levers that could lower it.

## R10 — OPcache measurement

**Finding**: OPcache is **not loaded** in the CLI. `php.ini` line 833 has `;zend_extension=opcache` commented out, and line 1519 has `;opcache.enable_cli=0`. `php_opcache.dll` is present.

**Decision**: For the optional run, uncomment `zend_extension=opcache` and set `opcache.enable_cli=1` in `C:\laragon\bin\php\php-8.4.16-Win32-vs17-x64\php.ini`. Confirm with `php -r "var_dump(ini_get('opcache.enable_cli'));"`. Run one cold run and 3 warm runs, then restore the two lines.

The stage log gives both medians and whether the setting was kept. That choice is the developer's; it is a machine setting.

**Expectation**: The gain is likely small. Each worker is a long-lived process that compiles each file once anyway. OPcache without `file_cache` does not share compiled code across processes on the CLI.

## R11 — Branch

**Decision**: Work on a new branch `030-parallel-run-speedup` created from the current `029-test-suite-measurement` head. Stage 1 needs the §5 flaky-test fixes, which are only on that branch.
