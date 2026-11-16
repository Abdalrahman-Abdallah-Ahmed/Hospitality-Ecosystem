# Feature Specification: Module Test Folders and Cached-Config Guard (Stage 2)

**Feature Branch**: `031-test-module-folders` (based on `030-parallel-run-speedup`, not merged yet)

**Created**: 2026-11-16

**Status**: Draft

**Input**: User description: "Stage 2 of docs/designs/test-suite-speed-and-module-runs.md (revision 4): move the feature tests into module folders, add the cached-config guard, and prove each folder runs on its own. Base the branch on 030-parallel-run-speedup. Stage 0/1 results are in docs/test-suite-performance.md; record Stage 2 in a new §7. Scope: tests/, docs, CLAUDE.md path references only."

## Problem and Need

All 207 flat feature test files sit directly in `tests/Feature/`. After an edit, the only ways to run "the tests for this area" are the full run (about 300 s, [test-suite-performance.md](../../docs/test-suite-performance.md) §6.4) or guessing `--filter` names. The design's after-edit loop (CUJ 1) needs a folder per module, so that `php artisan test tests/Feature/<Module>` runs exactly that module's tests.

A second risk is unrelated to speed. If `bootstrap/cache/config.php` exists, Laravel reads the cached (dev) database settings and ignores `phpunit.xml`, so `RefreshDatabase` can wipe the dev database (CUJ 3). `composer test` clears the cache first since Stage 1, but a plain `php artisan test` or a path run does not.

Stage 2 moves the files into 14 folders, adds a guard that refuses to run while the config is cached, proves each folder runs on its own, fixes the paths that point to moved files, and records whether G2 is met.

**Actors**:

- **Developer** (the repository owner): runs one module folder after an edit and `composer test` before a commit.
- **AI coding session**: runs module folders on the developer's behalf and follows `CLAUDE.md` paths to the invariant tests.
- **Reviewer**: checks that the move commit holds only moves plus the one path fix, and that the stage log shows each exit-check item.

## Clarifications

- **Every current flat file is already assigned** (checked while specifying, 2026-11-16). `tests/Feature/*.php` holds 207 files. They match the design's move table (206 files), except that `PermissionAuthorizationTest` is now `PermissionAuthorizationResourcesTest` and `PermissionAuthorizationActionsTest` (Stage 1). Both go to `Invariants/`. `CrossTenantRouteTest` is one file again. `GuestIdentityServiceTest` is in the table under `Concierge/`. The listing is repeated just before the move (FR-005), in case a file was added since.
- **The two-path check is kept, serial only.** Design item 11 asks for it. Parallel mode accepts only one path, so the check confirms that a serial `php artisan test <path> <path>` runs both folders, and records the result.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Run one module's tests after an edit (Priority: P1)

The developer edits housekeeping code and runs `php artisan test tests/Feature/Housekeeping`. Only the housekeeping tests run, and they pass without any other folder loaded.

**Why this priority**: This is the after-edit loop the whole design is built for (CUJ 1, G2). Without folders it does not exist.

**Independent Test**: Run each of the 14 folders alone by path, serially, and confirm each passes and reports a non-zero test count.

**Acceptance Scenarios**:

1. **Given** the move is done, **When** `tests/Feature/` is listed, **Then** it holds only the 14 folders and no `*.php` file directly.
2. **Given** a folder, **When** it runs alone by path, **Then** it passes, with no "Call to undefined function" and no "Cannot redeclare" error.
3. **Given** a folder fails alone but passes in the full run because of a helper defined in another test file, **When** it is fixed, **Then** that helper is moved unchanged into `tests/Pest.php` in a separate commit, and is not copied.
4. **Given** the move, **When** `EntryPointInventoryTest` runs, **Then** it still finds every `it('...')` description, because it searches `tests/Feature` recursively.

---

### User Story 2 - A cached config cannot reach the dev database (Priority: P1)

The developer ran `php artisan config:cache` earlier and forgot. Any test command now stops at once with a message that names the problem and the fix. No database is touched and the cache file is left in place.

**Why this priority**: It closes the data-loss path of CUJ 3 for every test command, not only `composer test`.

**Independent Test**: Create the cache with `php artisan config:cache`, run `php artisan test` and a path run, and confirm both stop with the guard message and a non-zero exit code. Then confirm `composer test` clears the cache and runs, and that after `php artisan config:clear` everything runs.

**Acceptance Scenarios**:

1. **Given** `bootstrap/cache/config.php` exists, **When** `php artisan test` or `php artisan test tests/Feature/<Folder>` runs, **Then** it exits with code 1 and prints, on standard error, that the config is cached, that tests would use the cached (dev) database settings, and to run `php artisan config:clear`.
2. **Given** the same state, **When** the guard stops the run, **Then** no test database or dev database is connected to, and the cache file still exists.
3. **Given** the same state, **When** `composer test` runs, **Then** it clears the cache first and the full run proceeds normally.
4. **Given** `php artisan config:clear` was run, **When** any test command runs, **Then** the guard does not trigger.

---

### User Story 3 - Paths in living documents point to real files (Priority: P2)

A developer or AI session following `CLAUDE.md` or an API document to a test file finds the file at the named path.

**Why this priority**: `CLAUDE.md` sends anyone adding a permission or a tenant feature to specific test files. A dead path sends them nowhere.

**Independent Test**: Search the living documents for `tests/Feature/<File>` paths and confirm each exists.

**Acceptance Scenarios**:

1. **Given** the move, **When** `CLAUDE.md` is read, **Then** the paths to `TenantIsolationTest`, `PermissionAuthorizationResourcesTest` and every other `tests/Feature/<File>` mention name the file's new folder, and no rule or command text has changed.
2. **Given** the move, **When** `docs/usage-metering-api-documentation.md`, `docs/ai-cost-attribution-api-documentation.md` and `deploy/systemd/README.md` are read, **Then** each test path names the new folder, and `QueueLaneTest` still passes.
3. **Given** the historical records (`docs/PGRIP_*`, `docs/designs/*`, `specs/**`, the review reports in the repository root), **When** the change set is reviewed, **Then** none of them changed.

---

### User Story 4 - G2 measured and recorded (Priority: P1)

The stage log's new §7 records each folder's isolation result, the guard check, the two-path check, each module's warm serial median over 3 runs, the Invariants folder time for reference, and the full-run count. It states whether G2 (slowest module under 180 s) is met, and if not, the Q2 outcome.

**Why this priority**: The design closes Stage 2 on this check, and Stage 3 writes the `CLAUDE.md` loop from these figures.

**Independent Test**: Open §7 and find each figure, and either "G2 met" or the Q2 result with a revised G2 and its reason.

**Acceptance Scenarios**:

1. **Given** 3 warm serial runs per module folder, **When** §7 is written, **Then** it lists each module's 3 times and median, and names the slowest module.
2. **Given** the slowest median is 180 s or more, **When** Q2 is applied, **Then** that module is timed with `--parallel --processes=4`, and §7 records the result and, if still missed, a revised G2 with the reason.
3. **Given** the full run, **When** §7 is written, **Then** it reports 3,598 tests, and the 14 folder counts plus `tests/Unit` equal that total.

---

### Edge Cases

- **A file not in the move table**: before moving, it is assigned to a folder and recorded in the stage log. If its module is unclear, the work stops and the developer is asked.
- **A folder fails alone for a reason other than a missing helper** (for example, shared state or ordering left by another file): the cause is recorded in the stage log; the fix is not to copy code.
- **A helper defined in two test files already**: moving one copy to `tests/Pest.php` while the other stays would stop the run with "Cannot redeclare". Both definitions must be resolved by moving, and the stage log names them.
- **Another test resolves a path from `__DIR__` relative to `tests/Feature`**: found by grep before the move commit; any match is fixed in that commit. (Checked while specifying: only `CrossTenantRouteTest` line 52 does. `tests/Fixtures/knowledge/build.php` and `tests/Pest.php` do not move.)
- **Cached config at the start of the work or between runs** (outside the guard check): stop and tell the developer. Do not delete it.
- **Postgres down or restarted**: stop. The next timed run counts as cold.
- **A path run that reports zero tests**: treated as a failure, not a pass.
- **Test count differs from 3,598**: the run is not counted until the reason is found and recorded.

## Requirements *(mandatory)*

### Functional Requirements

**Scope and safety**

- **FR-001**: Changes MUST stay within `tests/`, `docs/`, `deploy/systemd/README.md` (path text only) and `CLAUDE.md` (path text only). Nothing under `app/`, `routes/`, `config/`, `database/`, nor `phpunit.xml`, `composer.json` or CI workflows MAY change.
- **FR-002**: All runs MUST use the testing database on the existing `hospitality-postgres` container. The dev database and the container's settings MUST NOT change. The dev database's table count, migrations count and users count MUST be recorded before the first run and checked unchanged after the last.
- **FR-003**: Work MUST stop if `bootstrap/cache/config.php` exists, except during the guard check, which creates it and clears it afterwards.
- **FR-004**: Each logical change MUST be its own commit, formatted with Pint first, with no co-author trailer: (1) move, (2) guard, (3) helper moves (only if needed), (4) path references, (5) stage log.

**Move**

- **FR-005**: Immediately before the move, `tests/Feature/*.php` MUST be re-listed and compared with the move table. Any file the table does not name MUST be assigned and recorded in the stage log first; if its module is unclear, the work MUST stop and ask.
- **FR-006**: The move commit MUST contain only `git mv` operations, following the design's table (Detailed Implementation Plan, item 8) with the Stage 1 updates: `PermissionAuthorizationResourcesTest` and `PermissionAuthorizationActionsTest` go to `Invariants/` (22 files there); `CrossTenantRouteTest` moves as one file; `GuestIdentityServiceTest` goes to `Concierge/`; `SuspensionAdministrationTest` joins the existing `Superadmin/`. `tests/Unit/ExampleTest.php` MUST stay.
- **FR-007**: The expected folder sizes for the moved files are: Invariants 22, Core 32, Rooms 10, Reservations 34, Housekeeping 7, Tasks 5, Activities 10, Concierge 21, Recommendations 19, Knowledge 9, AiPlatform 22, Finance 5, ImportExport 10, Superadmin +1 (207 in total).
- **FR-008**: After the move, no `*.php` file MAY sit directly under `tests/Feature/`.
- **FR-009**: The same commit MUST change `CrossTenantRouteTest`'s `require __DIR__.'/../../bootstrap/app.php'` to `require dirname(__DIR__, 3).'/bootstrap/app.php'`, keeping the docblock that explains why `base_path()` cannot be used. A grep MUST confirm no other test resolves a path from `__DIR__` relative to `tests/Feature`.

**Guard**

- **FR-010**: `tests/Pest.php` MUST gain a check as the first statement after its `use` imports, before the `pest()->extend(TestCase::class)...->in('Feature')` statement. If `dirname(__DIR__).'/bootstrap/cache/config.php'` exists, it MUST write a message to standard error and exit with code 1.
- **FR-011**: The message MUST say that the config is cached, that tests would use the cached (dev) database settings, and to run `php artisan config:clear`.
- **FR-012**: The guard MUST NOT delete or change the cache file, and MUST stop the run before any database connection.

**Isolation**

- **FR-013**: Each of the 14 folders MUST be run alone by path, serially (`php artisan test tests/Feature/<Folder>`), and pass. `--group` MUST NOT be used for this check.
- **FR-014**: A helper that a folder needs from another test file MUST move unchanged into `tests/Pest.php`, in a commit after the move commit. It MUST NOT be copied. Any other cause of an isolation failure MUST be recorded in the stage log.
- **FR-015**: A serial run with two folder paths MUST be tried once, and the stage log MUST record whether both folders ran.

**Path references**

- **FR-016**: Every `tests/Feature/<File>` path in `CLAUDE.md` (currently lines 99, 188 and 191), `docs/usage-metering-api-documentation.md` (223), `docs/ai-cost-attribution-api-documentation.md` (134, 334) and `deploy/systemd/README.md` (20) MUST name the file's new folder. Only the path text changes.
- **FR-017**: `docs/PGRIP_*`, `docs/designs/*`, `specs/**` and the review reports in the repository root MUST NOT change.

**Exit check (stage log §7)**

- **FR-018**: §7 MUST record: any file assigned outside the table; each folder's isolation result and test count; any helper moved and why; the two-path result; the guard check (both refused commands, `composer test` with the cache present, and a run after `config:clear`); and the dev-database counts before and after.
- **FR-019**: Each module folder (all except `Invariants/`) MUST be timed 3 times, warm and serial, by path. §7 MUST give each median and name the slowest. The `Invariants/` folder MUST be timed alone once, for reference.
- **FR-020**: §7 MUST state whether G2 (slowest module under 180 s) is met. If not, the slowest module MUST be timed with `--parallel --processes=4` (Q2), and if still missed, §7 MUST give a revised G2 with the reason.
- **FR-021**: The full `composer test` run MUST pass and report 3,598 tests. The 14 folder counts plus `tests/Unit` MUST add up to the same total.
- **FR-022**: Pint MUST pass on the final tree.

### Key Entities

- **Module folder**: one of the 14 directories under `tests/Feature/`; has a file count, a test count, an isolation result and (except `Invariants/`) a warm serial median.
- **Move table**: the design's one-time file-to-folder plan, plus the Stage 1 updates and any file assigned before the move.
- **Guard**: the check at the top of `tests/Pest.php` that refuses to run while the config is cached.
- **Stage log**: `docs/test-suite-performance.md`; Stage 2 adds §7.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: `tests/Feature/` holds 14 folders and zero loose test files, and the folder counts match FR-007.
- **SC-002**: All 14 folders pass when run alone by path, each with a non-zero test count.
- **SC-003**: With a cached config, both a full and a path test command stop with the guard message before any database is touched; the dev database counts are unchanged at the end of the stage.
- **SC-004**: The slowest module's warm median is under 180 s, or §7 records the Q2 result and a revised G2 with a reason a reader can check against the figures.
- **SC-005**: The full run reports 3,598 tests, passes, and equals the sum of the folder counts plus `tests/Unit`.
- **SC-006**: No living document names a `tests/Feature/<File>` path that does not exist.
- **SC-007**: The move commit contains only renames plus the one-line `CrossTenantRouteTest` change; each other commit is one logical change.

## Assumptions

- **Branch**: `031-test-module-folders`, created from `030-parallel-run-speedup` at `23b39bb`. It is rebased or merged after 030 lands on `main`.
- **Test baseline is 3,598**, from Stage 1 §6.6 (3,597 plus the `GuestIdentityServiceTest` regression test, after the cleanup).
- **`tests/Unit` holds one file** (`ExampleTest.php`); its count is read from the full run.
- **Warm** means a run after one discarded cold run of the same folder, with no Postgres restart and no gap over 5 minutes, as in §1 of the stage log.
- **Reservations is expected to be the slowest module** (§3.4 planned-folder sums); the measured figures decide.
- **Serial module runs** reuse the single testing database, so no new worker databases are created for them.
- **Rules and commands in `CLAUDE.md`** (module run line, folder table, failure rules) are Stage 3 and do not change here.
- **Dependencies**: the `hospitality-postgres` container is up; `phpunit.xml` already forces the testing database and an empty Sentry DSN.
