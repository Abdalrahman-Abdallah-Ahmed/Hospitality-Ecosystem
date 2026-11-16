---

description: "Task list for Stage 1: faster full parallel run"
---

# Tasks: Faster Full Parallel Run (Stage 1)

**Input**: Design documents from `specs/030-parallel-run-speedup/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: One new test: the cross-tenant coverage test (spec FR-010, research R2). Otherwise tests are moved, not written; the existing suite is the check.

**Organization**: Tasks are grouped by user story.

- Full runs need the machine to themselves and are never parallel. `[P]` marks only edits to different files with no open dependency.
- `<scratchpad>` is the working session's scratchpad directory. Measurement tooling goes in `<scratchpad>/stage1/` and is never committed.

**Decided**: The timing budgets follow the rule as written (research R7; confirmed by the developer, 2026-11-16).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US6)

---

## Phase 1: Setup

**Purpose**: Branch, safety checks, and the spec commit.

- [X] T001 Create branch `030-parallel-run-speedup` from the current `029-test-suite-measurement` head (research R11). Do not branch from `main`: it lacks the §5 flaky-test fixes
- [X] T002 Pre-flight stop check: confirm `bootstrap/cache/config.php` does **not** exist. If it does, stop and tell the developer; do not delete it to continue (FR-003)
- [X] T003 Pre-flight: confirm `docker ps` shows `hospitality-postgres` Up. Never run `docker compose up` and never change the container's settings (FR-002)
- [X] T004 Dev DB check, before (read-only, on `Hospitality_Ecosystem`, via `docker exec hospitality-postgres psql -U root -d Hospitality_Ecosystem -tAc ...`): count the public tables, `migrations` rows and `users` rows. Save to `<scratchpad>/stage1/dev-fingerprint-before.txt`
- [X] T005 Run `./vendor/bin/pint --test` (nothing to fix in specs), then commit `specs/030-parallel-run-speedup/` (spec, plan, research, data-model, quickstart, contracts, checklists, tasks) as `docs(030): add Stage 1 spec, plan and tasks`. No co-author trailer

**Checkpoint**: The branch is ready and the environment is safe.

---

## Phase 2: Foundational (blocks US2–US3)

**Purpose**: The split list must be in the stage log, committed, before any test file is edited (spec FR-007; contract [stage-log-section-6.md](contracts/stage-log-section-6.md) §6.1).

- [ ] T006 Append `## 6. Stage 1 — speed (2026-11-16)` and `### 6.1 Split list` to `docs/test-suite-performance.md`, after §5. Content per the contract §6.1:
  - Worker count 8; Stage 0 wall time 302.4 s; threshold 75.6 s.
  - Table: original → part file → slice → Stage 0 summed time. Use research R1 for the 4 cross-tenant parts (their segment lists, 68/69/80/98 cases, 50.6/47.8/61.6/57.2 s) and research R4 for the 2 permission parts (lines 85–324 → `PermissionAuthorizationResourcesTest` 38.8 s; lines 326–656 → `PermissionAuthorizationActionsTest` 40.1 s).
  - Moving to `tests/Pest.php`: `crossTenantRoutesFor` (gains `?string $slice = null`), `crossTenantRoute`, `crossTenantAdmin`, `permissionApiHeaders`, `hotelForPermissions`, `employeeWithPermissions`. New: `crossTenantSlices`, `crossTenantSegment`. Staying in their part: `availabilityProbeUrl` (Resources), `guestForPermissions` (Actions).
  - The coverage test `it('puts every tenant route in exactly one cross-tenant part')` in `CrossTenantRouteOperationsTest`; expected count 3,598 (3,597 + 1).
  - The other files the stage may touch: `composer.json`; `CLAUDE.md` 99, 168, 191; the `tests/Feature/EntryPointInventoryTest.php:90` message; the `tests/Pest.php:1615` comment; and, only if 6.3 requires it, `tests/Feature/RecommendationQueueTest.php:110` and `tests/Feature/StayListTest.php:147`.
  - Note that Pint removes unused imports from each part (research R5).
- [ ] T007 Commit `docs/test-suite-performance.md` as `docs: record the Stage 1 split list`

**Checkpoint**: The touch list is fixed. Any later edit outside it is a scope violation.

---

## Phase 3: User Story 1 — One command for the full run (P1) 🎯 MVP

**Goal**: `composer test` runs the full suite at 8 workers, with no timeout, after clearing the config (FR-006).

**Independent Test**: `composer test -- --filter=StayListTest` shows the config clear first, then 8 processes, and runs only StayListTest.

- [ ] T008 [US1] In `composer.json`, replace the `test` script (currently lines 66–69: `config:clear` + `@php artisan test`) with exactly these three entries, in order (research R8):
  1. `"Composer\\Config::disableProcessTimeout"`
  2. `"@php artisan config:clear --ansi @no_additional_args"`
  3. `"@php artisan test --parallel --processes=8"`

  Add no other script.
- [ ] T009 [US1] Run `composer validate --no-check-publish`, then `composer test -- --filter=StayListTest`. Confirm the config clear runs first, the header shows 8 processes, only `StayListTest` runs, and it passes
- [ ] T010 [US1] Commit `composer.json` as `chore: run composer test in parallel at 8 workers without a timeout`

**Checkpoint**: US1 is done and works on its own. The runs in later phases use it.

---

## Phase 4: User Story 2 — No single file holds up the end of the run (P1)

**Goal**: Six parts replace the two long files. Each part ≤ 75.6 s, every row checked once (FR-009–FR-016).

**Independent Test**:

- `php artisan test --filter=CrossTenantRoute` gives 316 cases, all passing.
- The coverage test fails when a segment is removed from the map.
- `php artisan test --filter=PermissionAuthorization` gives the same case count as before, plus RoomType's 6.
- `EntryPointInventoryTest` passes.

### 4a. `CrossTenantRouteTest` → four parts (one commit)

- [ ] T011 [US2] Before editing, record the original case counts: `php artisan test --filter=CrossTenantRouteTest` (expect 315) and `php artisan test tests/Feature/PermissionAuthorizationTest.php`. Note both in `<scratchpad>/stage1/counts-before.txt`
- [ ] T012 [US2] In `tests/Pest.php`, near the existing `crossTenantRuleFor` (line ~1940), move **unchanged** from `tests/Feature/CrossTenantRouteTest.php` lines 40–78:
  - the docblock and `crossTenantRoutesFor`, `crossTenantRoute`, `crossTenantAdmin`.

  Apply only these changes, per [contracts/cross-tenant-builder.md](contracts/cross-tenant-builder.md):
  - (a) the signature becomes `crossTenantRoutesFor(string $rule, ?string $slice = null): array`;
  - (b) the bootstrap path becomes `__DIR__.'/../bootstrap/app.php'`;
  - (c) after the cached `$byRule` lookup: when `$slice` is not null, throw `InvalidArgumentException` if it is not a key of `crossTenantSlices()`, else keep only the rows whose `crossTenantSegment($uri)` is in that part's list.

  Add `crossTenantSlices(): array` (keys in order `Reservations`, `Operations`, `GuestServices`, `Platform`, with the segment lists from research R1, verbatim). Add `crossTenantSegment(string $uri): string` (strip a leading `api/`, return the text before the next `/`).

  Add the imports `Illuminate\Contracts\Console\Kernel`, `Illuminate\Foundation\Bootstrap\HandleExceptions`, `Illuminate\Support\Facades\Facade` and `Tests\Support\EntryPointDiscovery` (check first that they are not already imported). Update the comment at line 1615 to name `CrossTenantRoute*Test`.
- [ ] T013 [US2] `git mv tests/Feature/CrossTenantRouteTest.php tests/Feature/CrossTenantRouteReservationsTest.php`. Delete the three moved functions from it (now in `tests/Pest.php`). Change each of the six `->with(fn () => crossTenantRoutesFor('<rule>'))` to pass `'Reservations'` as the second argument; the owner test keeps its `array_filter(..., GET)` around the sliced call. Keep the header comment, every `use` import, `uses(RefreshDatabase::class)`, `beforeEach`, the spec-025 comment block, and every `it('...')` text and body word for word
- [ ] T014 [P] [US2] Create `tests/Feature/CrossTenantRouteOperationsTest.php` as a copy of the T013 result, with `'Operations'` in all six `with()` calls. Add, at the end, the coverage test `it('puts every tenant route in exactly one cross-tenant part', ...)`. For each rule in `['index', 'bound', 'super_admin', 'foreign_key']`, it checks that:
  - no segment appears under two keys of `crossTenantSlices()`;
  - the union of the four sliced key sets equals the keys of `crossTenantRoutesFor($rule)`;
  - the sliced sets are pairwise disjoint.

  The failure message names the unmatched routes and their segments.
- [ ] T015 [P] [US2] Create `tests/Feature/CrossTenantRouteGuestServicesTest.php` as a copy of the T013 result, with `'GuestServices'` in all six `with()` calls
- [ ] T016 [P] [US2] Create `tests/Feature/CrossTenantRoutePlatformTest.php` as a copy of the T013 result, with `'Platform'` in all six `with()` calls
- [ ] T017 [US2] In `tests/Feature/EntryPointInventoryTest.php` line 90, change only the message text to `"{$key} is driven by the CrossTenantRoute*Test parts; remove it from the inventory"`. In `CLAUDE.md` line 168, change `CrossTenantRouteTest` to `CrossTenantRoute*Test`
- [ ] T018 [US2] Run `php artisan test --filter=CrossTenantRoute` (expect 316 cases: 315 + 1, all passing, no risky tests, no "Cannot redeclare"). Then run `php artisan test --filter=EntryPointInventoryTest` and `php artisan test --filter=TenantIsolationTest`. Also run one part alone by path, `php artisan test tests/Feature/CrossTenantRoutePlatformTest.php`, to prove the builder boots on its own (FR-011)
- [ ] T019 [US2] Negative check of the coverage test (local only, never committed): temporarily delete `'team'` from the Operations list in `crossTenantSlices()`. Run `php artisan test --filter="puts every tenant route"`; it must fail and name the `api/team` routes. Restore, then confirm `git diff tests/Pest.php` shows no leftover change from this check
- [ ] T020 [US2] Run `./vendor/bin/pint`, then `php artisan test --parallel --processes=8` (full suite green; 3,598 tests). Commit `tests/Pest.php`, the four `CrossTenantRoute*Test.php` files, `tests/Feature/EntryPointInventoryTest.php` and `CLAUDE.md` as `refactor(tests): split CrossTenantRouteTest into four route-segment parts`

### 4b. `PermissionAuthorizationTest` → two parts (one commit)

- [ ] T021 [US2] In `tests/Pest.php`, near the other shared helpers, move **unchanged** from `tests/Feature/PermissionAuthorizationTest.php`: `permissionApiHeaders` (line 26), `hotelForPermissions` (31), `employeeWithPermissions` (50), with any docblocks. Add any imports they need that `tests/Pest.php` lacks
- [ ] T022 [US2] `git mv tests/Feature/PermissionAuthorizationTest.php tests/Feature/PermissionAuthorizationResourcesTest.php`. Delete:
  - the three moved helpers;
  - `guestForPermissions`;
  - every block from line 326 (`it('never gives employees without a role the voucher permissions'`) to the end of the file.

  Keep `availabilityProbeUrl`, the header (all `use` imports, `uses(RefreshDatabase::class)`, `beforeEach`), and lines 85–324 word for word.
- [ ] T023 [US2] Create `tests/Feature/PermissionAuthorizationActionsTest.php`: the original header (all `use` imports, `uses(RefreshDatabase::class)`, `beforeEach` with its comment), then `guestForPermissions` (original lines 64–76), then original lines 326–656 word for word. Take the text from `git show HEAD:tests/Feature/PermissionAuthorizationTest.php`
- [ ] T024 [US2] In `CLAUDE.md` lines 99 and 191, change `tests/Feature/PermissionAuthorizationTest.php` to `tests/Feature/PermissionAuthorizationResourcesTest.php`
- [ ] T025 [US2] Run `php artisan test --filter=PermissionAuthorization`: the two parts' counts must sum to the T011 count, plus `RoomTypePermissionAuthorizationTest`'s 6, all passing. Run `./vendor/bin/pint`, then `php artisan test --parallel --processes=8` (3,598). Commit `tests/Pest.php`, the two `PermissionAuthorization*Test.php` files and `CLAUDE.md` as `refactor(tests): split PermissionAuthorizationTest into Resources and Actions`

**Checkpoint**: Both splits are committed and the suite is green. Each part's time is confirmed in US5.

---

## Phase 5: User Story 3 — Documents point to files that exist (P2)

**Goal**: No stale live reference (FR-017, SC-006). The edits were made in T017 and T024; this phase verifies them.

**Independent Test**: The search below returns only historical records.

- [ ] T026 [US3] Run `git grep -n "CrossTenantRouteTest\|PermissionAuthorizationTest" -- . ':!specs' ':!docs/test-suite-performance.md' ':!docs/designs'`. Every match must be one of:
  - `RoomTypePermissionAuthorizationTest`;
  - a historical record left as written (`concepts_brief.md`, `goldfish_review_report.md`, `docs/PGRIP_Ecosystem_WP_Conversational_Pitching_v1_0.md`, `docs/production-readiness-checklist.md`).

  Fix anything else under the T006 touch list. Confirm that `docs/usage-metering-api-documentation.md:223` and `docs/ai-cost-attribution-api-documentation.md:134,334` are unchanged (research R6)

---

## Phase 6: User Story 4 — Timing budgets checked against the new load (P2)

**Goal**: Apply the FR-018 rule using 3 full runs after the splits.

**Independent Test**: The §6.3 table has 3 × 2 times and a decision per file that a reader can recompute.

- [ ] T027 [US4] Pre-flight (T002 again). Then run 4 back-to-back full runs: `composer test -- --log-junit=storage/test-reports/s1-r<k>.xml`, k = 1..4, timing each with `date +%s` and logging label, start, end, wall time and exit code to `<scratchpad>/stage1/runs.csv` (quickstart §3). `s1-r1` is cold and discarded. A failing warm run is replaced (`s1-r5`, …) and the failing test is named
- [ ] T028 [US4] Write `<scratchpad>/stage1/summarize.py` (stdlib only, never committed). From `s1-r2..r4` it reports:
  - per-file summed time (median of the 3);
  - the slowest file;
  - each of the six parts against 75.6 s;
  - run totals (summed time, test count);
  - the JUnit `time` of `RecommendationQueueTest` "it decides 100 at once in under two seconds" and `StayListTest` "it shows the arrivals of a 500-room hotel in under 2 seconds…".

  When reading the `class` attribute, split on `chr(92)`, not `'\\'`.
- [ ] T029 [US4] Apply the rule to each file separately: if its slowest observed time is ≥ 3.0 s, set the parallel value in `ParallelTesting::token() ? 4.0 : 2.0` to `ceil(slowest × 1.5 / 0.5) × 0.5`, in `tests/Feature/RecommendationQueueTest.php:110` and/or `tests/Feature/StayListTest.php:147`. Leave the serial `2.0` unchanged. Expected from Stage 0: about 6.5 s and 5.5 s (research R7). If a file changes, run Pint, run that file, and commit `test: raise the parallel timing budgets to the observed load`
- [ ] T030 [US4] If any part exceeds 75.6 s in T028, re-balance by moving one segment between parts in `crossTenantSlices()` (or move the Resources/Actions boundary by one block). Record the change in §6.1 as an amendment, commit it, and repeat T027–T028. Do not split any file outside the touch list

**Checkpoint**: The budgets are decided. If T029 or T030 changed a file, the exit-check runs (T031) come after that commit.

---

## Phase 7: User Story 5 — Exit check and G1 decision recorded (P1)

**Goal**: §6 records the final state (FR-019, FR-020).

**Independent Test**: §6 meets [contracts/stage-log-section-6.md](contracts/stage-log-section-6.md) with no placeholders.

- [ ] T031 [US5] If T029 or T030 changed a file, repeat T027 (as `s1-r5..r8`) so that the exit check measures the committed final state. Otherwise the T027 runs are the exit check
- [ ] T032 [US5] Dev DB check, after: repeat T004 into `<scratchpad>/stage1/dev-fingerprint-after.txt`. It must match the before file
- [ ] T033 [US5] Append §6.2–§6.4 to `docs/test-suite-performance.md` per the contract:
  - 6.2: the script, and confirmation that `--filter` passes through.
  - 6.3: the budget table and decisions, with the note that JUnit time includes setup, and the follow-up idea of logging the request's own elapsed time.
  - 6.4: the run table, wall and summed medians beside Stage 0 (302.4 s; ~2,234 s), the slowest file, the six parts against 75.6 s, G3 (count vs 3,597; +1 coverage test), and G1. If the median is > 210 s, give the revised G1 = median rounded up to the next 10 s, with the floor (summed ÷ 8 + start-up), per research R9.

---

## Phase 8: User Story 6 — OPcache comparison (P3, optional)

**Goal**: Measure OPcache for the CLI, with no repository change (FR-021).

**Independent Test**: §6.5 shows both medians, or "Not measured."

- [ ] T034 [US6] In `C:\laragon\bin\php\php-8.4.16-Win32-vs17-x64\php.ini`, uncomment `zend_extension=opcache` (line 833) and set `opcache.enable_cli=1` (line 1519). Confirm with `php -r "var_dump(ini_get('opcache.enable_cli'));"` (expect `"1"`)
- [ ] T035 [US6] Run 4 runs as in T027, labelled `s1o-r1..r4` (the first discarded), and summarize them with T028's script
- [ ] T036 [US6] Restore both `php.ini` lines (unless the developer says to keep them), and confirm OPcache is not loaded again. Append §6.5 to `docs/test-suite-performance.md`: the lines changed, the run table, both medians, and whether the setting was kept

---

## Phase 9: Polish & Cross-Cutting

- [ ] T037 Run `./vendor/bin/pint --test` (clean). Run `git diff 029-test-suite-measurement --stat` and confirm only files allowed by FR-001/FR-008 changed: nothing in `app/`, `routes/`, `config/`, `database/`, `phpunit.xml` or `.github/`
- [ ] T038 Mark the completed tasks in this file. Commit `docs/test-suite-performance.md` and `specs/030-parallel-run-speedup/tasks.md` as `docs: record Stage 1 results`. No co-author trailer

---

## Dependencies & Execution Order

- **Setup (T001–T005)** → **Foundational (T006–T007)** → the user stories.
- **US1 (T008–T010)** is independent of US2. It goes first because the US4/US5 runs use `composer test`.
- **US2**: 4a (T011–T020) before 4b (T021–T025), because both edit `tests/Pest.php`. Within 4a, T014–T016 are parallel once T012–T013 are done.
- **US3 (T026)** after US2.
- **US4 (T027–T030)** after US1 and US2.
- **US5 (T031–T033)** after US4.
- **US6 (T034–T036)** after US5 (it compares against US5's median). Optional.
- **Polish (T037–T038)** last.

## Parallel Example: User Story 2

```text
After T013 (Reservations part written):
  T014  CrossTenantRouteOperationsTest.php (+ coverage test)
  T015  CrossTenantRouteGuestServicesTest.php
  T016  CrossTenantRoutePlatformTest.php
```

Full runs (T018, T020, T025, T027, T031, T035) are never run in parallel with each other or with other heavy work.

## Implementation Strategy

- **MVP**: Phases 1–3 (US1). `composer test` already avoids the 300 s kill and uses the chosen worker count.
- **The main gain**: US2. `CrossTenantRouteTest` alone sets a floor of 218 s summed time.
- **Close**: US4 → US5 decide the budgets and record G1. US6 is extra information.

## Commits (plan order)

1. T005 `docs(030): add Stage 1 spec, plan and tasks`
2. T007 `docs: record the Stage 1 split list`
3. T010 `chore: run composer test in parallel at 8 workers without a timeout`
4. T020 `refactor(tests): split CrossTenantRouteTest into four route-segment parts`
5. T025 `refactor(tests): split PermissionAuthorizationTest into Resources and Actions`
6. T029 `test: raise the parallel timing budgets to the observed load` (if triggered)
7. T038 `docs: record Stage 1 results`
