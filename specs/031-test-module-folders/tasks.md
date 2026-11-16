---

description: "Task list for Stage 2: module test folders and the cached-config guard"
---

# Tasks: Module Test Folders and Cached-Config Guard (Stage 2)

**Input**: Design documents from `specs/031-test-module-folders/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: No new tests. Files are moved, not written; the existing suite is the check.

**Organization**: Tasks are grouped by user story, in the commit order of [plan.md](plan.md):

1. the move;
2. the guard;
3. the isolation check, with a helper move if one is needed;
4. the paths;
5. G2;
6. the stage log.

**Running and tooling**:

- Test runs need the machine to themselves and are never parallel. `[P]` marks only edits to different files with no open dependency.
- `<scratchpad>` is the working session's scratchpad directory. Scripts and run logs go in `<scratchpad>/stage2/` and are never committed. The JUnit and text outputs may also go in `storage/test-reports/`, which git ignores.

**Stop rules**, which apply to every task:

- Stop if `bootstrap/cache/config.php` exists, except inside T011–T016.
- Stop if `hospitality-postgres` is not Up.
- Stop if a file has an unclear module.
- Never run `docker compose up`, and never change the container's settings.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US4)

---

## Phase 1: Setup

**Purpose**: Branch, safety checks, and the spec commit.

- [ ] T001 Confirm the current branch is `031-test-module-folders` and that its base is `030-parallel-run-speedup` at `23b39bb` (`git merge-base HEAD 030-parallel-run-speedup` = `23b39bb`). Leave the untracked `storage/framework/lsp-*.php` alone
- [ ] T002 Pre-flight stop check: confirm `bootstrap/cache/config.php` does **not** exist. If it does, stop and tell the developer. Do not delete it (FR-003)
- [ ] T003 Pre-flight: confirm `docker ps --filter name=hospitality-postgres` shows the container Up (FR-002)
- [ ] T004 Take the dev DB counts before any test run. This is read-only, on `Hospitality_Ecosystem`, through `docker exec hospitality-postgres psql -U root -d Hospitality_Ecosystem -tAc ...`. Count the public tables, the `migrations` rows and the `users` rows. Save them to `<scratchpad>/stage2/dev-fingerprint-before.txt`. Stage 1 ended at 60, 119 and 17; a difference is recorded, not fixed
- [ ] T005 Commit `specs/031-test-module-folders/` (spec, plan, research, data-model, quickstart, contracts, checklists, tasks) and `.specify/feature.json` as `docs(031): add Stage 2 spec, plan and tasks`. No co-author trailer

**Checkpoint**: The branch is ready and the environment is safe.

---

## Phase 2: Foundational — the move list

**Purpose**: Fix the move list before any file moves (FR-005).

- [ ] T006 Build `<scratchpad>/stage2/moves.txt`, one line per file in the form `<File>.php <Folder>`:
  - Take the rows from the table in `docs/designs/test-suite-speed-and-module-runs.md`, Detailed Implementation Plan item 8 (lines 640–656).
  - Replace `PermissionAuthorizationTest` with two lines, `PermissionAuthorizationResourcesTest.php Invariants` and `PermissionAuthorizationActionsTest.php Invariants`.
  - The `Superadmin/ (+1)` row gives `SuspensionAdministrationTest.php Superadmin`.
- [ ] T007 Compare `moves.txt` with `ls tests/Feature/*.php` (207 files expected).
  - Every file must be in the list exactly once, and every entry must exist.
  - Per-folder counts must match research R1: Invariants 22, Core 32, Rooms 10, Reservations 34, Housekeeping 7, Tasks 5, Activities 10, Concierge 21, Recommendations 19, Knowledge 9, AiPlatform 22, Finance 5, ImportExport 10, Superadmin +1.
  - **If a file is not in the table:** assign it, and note the file, folder and reason for §7.1. If its module is unclear, **stop and ask the developer**.
- [ ] T008 Grep for paths resolved from `__DIR__` (`grep -rn "__DIR__" tests/Feature`). The only match must be `tests/Feature/CrossTenantRouteTest.php:52`. Save the output for §7.1. Any other match is fixed in the move commit and noted

**Checkpoint**: The move list is complete and the only path fix is known.

---

## Phase 3: User Story 1 — Run one module's tests after an edit (P1) 🎯 MVP

**Goal**: 14 folders. Each runs alone by path and passes.

**Independent Test**: `ls tests/Feature/*.php` is empty, and each `php artisan test tests/Feature/<Folder>` passes with a non-zero count.

### The move commit

- [ ] T009 [US1] Create the 13 new folders, then run `git mv tests/Feature/<File>.php tests/Feature/<Folder>/<File>.php` for every line of `moves.txt`, from a script in `<scratchpad>/stage2/move.sh`. Then:
  - confirm `ls tests/Feature/*.php` finds nothing (FR-008);
  - confirm the per-folder counts match T007;
  - confirm `git status` shows only renames.
- [ ] T010 [US1] In `tests/Feature/Invariants/CrossTenantRouteTest.php`, inside `crossTenantRoutesFor()`:
  - change `$app = require __DIR__.'/../../bootstrap/app.php';` to `$app = require dirname(__DIR__, 3).'/bootstrap/app.php';`;
  - do not touch the docblock above the function, which explains why the routes come from a separately booted application;
  - change nothing else (FR-009).

  Then:

  1. Run `php artisan test --filter=CrossTenantRouteTest` and `php artisan test --filter=EntryPointInventoryTest`. Both must pass.
  2. Run `./vendor/bin/pint --test`.
  3. Commit as `refactor(tests): move feature tests into module folders`.
  4. Check `git show --stat HEAD`. It must show only renames, with one 1-line change in `CrossTenantRouteTest`.

  *(The isolation check, T017–T019, follows after the guard commit so that the guard protects those runs.)*

**Checkpoint**: The tree is in folders and the moved invariant tests pass.

---

## Phase 4: User Story 2 — A cached config cannot reach the dev database (P1)

**Goal**: Every test command refuses to run while `bootstrap/cache/config.php` exists ([contract](contracts/cached-config-guard.md)).

**Independent Test**: With the cache present, `php artisan test` and a path run both exit 1 with the message, and `composer test` clears the cache and runs.

- [ ] T011 [US2] In `tests/Pest.php`, insert the guard after the last `use` import (`use Tests\TestCase;`, line 95) and before the `Test Case` comment block that precedes `pest()->extend(TestCase::class)` (line 108). Give it a short comment block in the file's `/* |---| */` style. The guard:
  - checks `if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php'))`;
  - then runs `fwrite(STDERR, <message>); exit(1);`.

  The message says three things, with no settings values:

  1. the configuration is cached in `bootstrap/cache/config.php`;
  2. tests would use the cached (dev) database settings instead of `phpunit.xml`;
  3. "Run `php artisan config:clear` and try again."

  The guard must not delete, change or read the file (FR-010–FR-012).
- [ ] T012 [US2] Run `./vendor/bin/pint` and `php artisan test tests/Feature/Finance`, which must pass because the cache is absent. Commit `tests/Pest.php` as `test: refuse to run with a cached config`
- [ ] T013 [US2] Guard check, part 1:
  1. Run `php artisan config:cache` and confirm `bootstrap/cache/config.php` exists.
  2. Run `php artisan test; echo exit=$?` and confirm `exit=1`, the guard message, and no test output.
  3. Run `php artisan test tests/Feature/Tasks; echo exit=$?` and confirm the same.
  4. Run `php artisan test --parallel --processes=8; echo exit=$?`. Confirm `exit=1` and that the message appears. Note whether it appears once (parent) or per worker (research R4).
  5. Confirm the cache file still exists.

  Record each observation in `<scratchpad>/stage2/guard.txt`.
- [ ] T014 [US2] Guard check, part 2:
  1. With the cache still present, run `composer test`.
  2. Confirm `config:clear` ran first and the full run passes, with a test count of 3,598. This is also the first G3 reading. Record the wall time.
  3. Confirm `bootstrap/cache/config.php` is now absent. **If it is still present, stop.**
- [ ] T015 [US2] Guard check, part 3: run `php artisan test tests/Feature/Finance` and confirm it runs normally. Append the result to `guard.txt`
- [ ] T016 [US2] Confirm `bootstrap/cache/config.php` does not exist. From here on, the stop rule applies again

**Checkpoint**: The guard is committed and proven. Every later run is protected.

---

## Phase 3 (continued): User Story 1 — isolation

- [ ] T017 [US1] For each of the 14 folders, run `php artisan test tests/Feature/<Folder>` serially:
  - **Order:** Core, Rooms, Reservations, Housekeeping, Tasks, Activities, Concierge, Recommendations, Knowledge, AiPlatform, Finance, ImportExport, Superadmin, Invariants.
  - **Never** use `--group`.
  - **Record:** the wall time, the exit code and Pest's `Tests:` line, in `<scratchpad>/stage2/isolation.txt`. Keep the output as `storage/test-reports/s2-<Folder>-r1.txt`.
  - These are each module's cold run (`r1`) for US4.
  - A run that reports zero tests counts as a failure.
- [ ] T018 [US1] For any folder that failed in T017, first decide whether it also fails in the full run (T014). If it does, it is a real failure outside this stage: stop and report.

  If it fails alone only:

  - **Missing helper** ("Call to undefined function"):
    1. Find the file that defines the helper.
    2. Move the function unchanged, with its imports, from that file into `tests/Pest.php`. Never copy it.
    3. Re-run both folders alone and `php artisan test --filter=<DefiningFile>`.
    4. Run Pint.
    5. Commit as `refactor(tests): move <helper> to Pest.php`.
  - **Any other cause** (for example, an ordering or state dependency): record it in `isolation.txt` for §7.3, and ask the developer before changing a test.

  Research R2 expects no failures, so this task is normally empty.
- [ ] T019 [US1] Two-path check: run `php artisan test tests/Feature/Tasks tests/Feature/Finance` serially. Confirm that its `Tests:` count equals Tasks + Finance from T017. Record it for §7.3 (FR-015)

**Checkpoint**: All 14 folders pass alone (SC-002).

---

## Phase 5: User Story 3 — Paths in living documents point to real files (P2)

**Goal**: No living document names a moved file at its old path (FR-016, research R7).

**Independent Test**: Every `tests/Feature/<File>Test.php` path in the living documents exists.

- [ ] T020 [P] [US3] `CLAUDE.md`, path text only:
  - line 99: `tests/Feature/PermissionAuthorizationResourcesTest.php` → `tests/Feature/Invariants/PermissionAuthorizationResourcesTest.php`;
  - line 188: `tests/Feature/TenantIsolationTest.php` → `tests/Feature/Invariants/TenantIsolationTest.php`;
  - line 191: `tests/Feature/PermissionAuthorizationResourcesTest.php` → `tests/Feature/Invariants/PermissionAuthorizationResourcesTest.php`.

  Leave the rules, the commands and the bare names (such as `CrossTenantRouteTest` at line 168) unchanged. Those are Stage 3.
- [ ] T021 [P] [US3] `docs/usage-metering-api-documentation.md` line 223: `tests/Feature/CostVisibilityTest.php` → `tests/Feature/Invariants/CostVisibilityTest.php`
- [ ] T022 [P] [US3] `docs/ai-cost-attribution-api-documentation.md`:
  - line 134: `tests/Feature/AiCapabilityCoverageTest.php` → `tests/Feature/AiPlatform/AiCapabilityCoverageTest.php`;
  - line 334: `tests/Feature/AiCostAttributionTest.php` → `tests/Feature/AiPlatform/AiCostAttributionTest.php`.
- [ ] T023 [P] [US3] `deploy/systemd/README.md` line 20: `tests/Feature/QueueLaneTest.php` → `tests/Feature/Invariants/QueueLaneTest.php`
- [ ] T024 [US3] Sweep for anything still pointing at the old paths:
  1. Run `grep -rnE "tests/Feature/[A-Za-z]+Test\.php" --include=*.md . | grep -vE "^\./(specs/|docs/PGRIP_|docs/designs/|vendor/|node_modules/)"`, then drop the review reports in the repository root (`*_report*.md`, `concepts_brief.md`).
  2. Every remaining hit must name an existing file. Fix any hit that does not, and note it.
  3. Do the same check for `tests/` PHP comments: `grep -rnE "tests/Feature/[A-Za-z]+Test\.php" tests`.
- [ ] T025 [US3] Run `php artisan test --filter=QueueLaneTest`, which must pass. Then confirm `git diff --stat` touches only the four files above and no historical file (FR-017). Commit as `docs: point test paths at the module folders`

**Checkpoint**: Paths are current (SC-006).

---

## Phase 6: User Story 4 — G2 measured and recorded (P1)

**Goal**: §7 shows each module's warm serial median and the G2 decision ([contract](contracts/stage-log-section-7.md)).

**Independent Test**: §7.4 has 13 module rows with 3 warm times and a median, plus the Invariants reference time and the G2 verdict.

- [ ] T026 [US4] Pre-flight again: T002 and T003. If Postgres restarted since T017, or more than 5 minutes have passed since a folder's last run, run that folder once more as a new cold run before its warm runs
- [ ] T027 [US4] Warm runs:
  - **Runs:** for each of the 13 module folders (all except `Invariants`), run `php artisan test tests/Feature/<Module>` serially 3 times (`r2`–`r4`), back to back, as in quickstart step 3.
  - **Record:** the wall time (shell clock), the exit code and the `Tests:` line, in `<scratchpad>/stage2/g2.txt`.
  - **Failures:** a failing run is replaced by another run, and the failing test is named for §7.4.
  - **Medians:** compute each module's median and name the slowest module.
- [ ] T028 [US4] `Invariants` reference: if T017's Invariants run was cold, run `php artisan test tests/Feature/Invariants` once more. Record its wall time (one run, for reference only, not part of G2)
- [ ] T029 [US4] Q2, **only if the slowest median is 180 s or more**:
  1. Run that module with `php artisan test tests/Feature/<Module> --parallel --processes=4`: 1 cold run (`s2-q2-r1`, discarded, because it creates new worker databases), then 3 warm runs (`r2`–`r4`). Record the median.
  2. If the median is still 180 s or more, write a revised G2 for §7.4, with its reason taken from the figures (for example, the summed time ÷ 4 plus start-up).

  If Q2 was not needed, note "not needed".

**Checkpoint**: The G2 verdict is known.

---

## Phase 7: Polish — exit check and stage log

- [ ] T030 Final full run (G3): pre-flight (T002, T003). Run `composer test`.
  - It must pass with `Tests: 3,598`.
  - Sum the 14 folder counts from T017 and add `tests/Unit` (1). The sum must equal the full-run count (FR-021).
  - If the count differs, find the reason before going on.
- [ ] T031 `./vendor/bin/pint --test` is clean. Run `git diff 23b39bb --stat -- app routes config database phpunit.xml composer.json .github`, which must be empty (FR-001)
- [ ] T032 Take the dev DB counts after the runs, with the same query as T004, into `<scratchpad>/stage2/dev-fingerprint-after.txt`. They must equal the counts from before (FR-002). If they differ, stop and report
- [ ] T033 Write `## 7. Stage 2 — module folders and the guard (<date>)` into `docs/test-suite-performance.md`, after §6, following [contracts/stage-log-section-7.md](contracts/stage-log-section-7.md):
  - **7.1 Move:** T007, T008 and T009.
  - **7.2 Guard:** T013–T015.
  - **7.3 Isolation:** T017–T019.
  - **7.4 G2:** T027–T029.
  - **7.5 G3 and the full run:** T014, T030 and T031.
  - **7.6 Paths and safety:** T020–T025, T004 and T032.

  When it is done, no placeholder is left and there are no credentials.
- [ ] T034 Commit `docs/test-suite-performance.md` as `docs: record Stage 2 results`. No co-author trailer. Then run `git log --oneline 23b39bb..HEAD` and confirm one commit per logical change, in the plan's order (SC-007)

---

## Dependencies & Execution Order

- **Setup (T001–T005)** comes first. **Foundational (T006–T008)** comes before the move.
- **Move:** US1 move (T009–T010) runs before US2 (T011–T016).
  - The guard is added after the move so that the move commit holds only renames.
  - The guard check (T014) also gives the first full-run count on the moved tree.
- **Isolation:** US1 isolation (T017–T019) runs after US2, so every folder run is protected by the guard. T017 also supplies the cold runs for US4.
- **Paths:** US3 (T020–T025) depends only on T009. It can be done any time after the move, but its commit comes after any T018 helper commit, to keep the plan's commit order.
- **G2:** US4 (T026–T029) runs after T017.
- **Polish:** T030–T034 run last. T030 runs after US3, so that the final run is on the final tree.

## Parallel Opportunities

- T020, T021, T022 and T023 edit different files and can be done together.
- Test runs are **never** parallel with each other, because they share the machine and the testing database.

## Implementation Strategy

- **MVP**: Phases 1–4 and the isolation check (T001–T019). The tree is in folders, the guard is in, and each folder runs alone. That already delivers the after-edit loop (CUJ 1) and the data-loss guard (CUJ 3).
- **Then**: US3 paths, then the US4 timings, then §7.
- **Rough time**:
  - the isolation and cold runs take about 20 minutes;
  - the warm runs take about 45 minutes;
  - 2 full runs take about 10 minutes;
  - Q2, if needed, takes about 8 minutes.
