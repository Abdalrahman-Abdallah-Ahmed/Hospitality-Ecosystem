# Feature Specification: Faster Full Parallel Run (Stage 1)

**Feature Branch**: `030-parallel-run-speedup`

**Created**: 2026-11-16

**Status**: Draft

**Input**: User description: "Stage 1 of docs/designs/test-suite-speed-and-module-runs.md (revision 4): make the full parallel test run faster. `composer test` becomes the full run at 8 workers with no Composer timeout and a cleared config; split CrossTenantRouteTest (218 s) and PermissionAuthorizationTest (79 s), the two files over 25% of the 8-worker wall time (75.6 s), recording the split list before any edit; update the documents that name them; check the two timing-budget tests over 3 runs and raise the parallel 4.0 only if needed; optionally measure with OPcache for the CLI; exit check of 3 warm runs recorded in docs/test-suite-performance.md §6, with G1 revised under Q1 if missed. Scope: tests/, composer.json and docs only. The three flaky tests are already fixed (§5)."

## Problem and Need

Stage 0 ([test-suite-performance.md](../../docs/test-suite-performance.md) §3–§4) measured the full parallel run at **302.4 s** median at 8 workers. 8 is the lowest worker count within 5% of the fastest. The run cannot end before its slowest file does, and one file, `CrossTenantRouteTest`, takes **218 s** of summed time on its own. `PermissionAuthorizationTest` (79 s) is the only other file above 25% of the wall time. Every other file is 69.6 s or less.

Today `composer test` runs at the default 12 workers and is subject to Composer's 300 s script timeout, which the run already reaches.

Stage 1 makes the everyday full run use the chosen worker count without the timeout. It also splits the two long files so that no single file holds up the end of the run. Then it records whether G1 (under 210 s) is met. If not, a revised G1 is recorded under Q1. The three flaky tests that Stage 0 found are already fixed (§5) and are out of scope.

**Actors**:

- **Developer** (the repository owner): runs `composer test` before every commit and reads the stage log.
- **AI coding session**: runs the full run on the developer's behalf and follows `CLAUDE.md` to the permission dataset when adding a permission.
- **Reviewer**: checks that the change set stays within the recorded split list and scope, and that the exit check is met or Q1 is recorded.

## Clarifications

- **`PermissionAuthorizationTest` is split by consecutive `it()` blocks, not by dataset rows** (decided while specifying, 2026-11-16). The file has about 35 separate tests. Many have small inline datasets. The largest is the 35-row "read a resource" dataset. The design's dataset exception is optional ("the rows *may* be split"), and it suits one test over a large dataset, which is `CrossTenantRouteTest`. For this file the design's standard split rule applies. The "read a resource" dataset (the one `CLAUDE.md` calls "the dataset") stays whole in one part, and the documents name that part. If the per-test times show that the standard rule cannot keep each part under 75.6 s, the dataset exception (a shared builder with a fixed filter and a coverage test) is applied to that dataset instead. The stage log records which way was taken.
- **The test count rises by the coverage tests only.** G3 compares against 3,597. The slice-coverage test(s) required by the dataset exception are new test cases, so the expected count is 3,597 plus exactly those. The stage log records the count and this reason, as G3 allows.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - One command for the full run (Priority: P1)

The developer types `composer test` and gets the full suite at 8 workers. The config cache is cleared first, and the run is not stopped by Composer's timeout, however long it takes. Extra arguments such as `--filter` reach the test runner, not the config-clear step.

**Why this priority**: It is the run used before every commit (CUJ 4). Without it the developer has to remember the worker count, and a run longer than 300 s is killed partway through.

**Independent Test**: Run `composer test` and confirm it reports 8 processes, clears the config first, and finishes past 300 s if needed. Run `composer test -- --filter=SomeTest` and confirm the filter applies to the tests.

**Acceptance Scenarios**:

1. **Given** the config is cached, **When** `composer test` runs, **Then** the cache is cleared before any test and the tests use the testing database.
2. **Given** a run longer than 300 s, **When** it runs through `composer test`, **Then** Composer does not end it.
3. **Given** `composer test -- --filter=X`, **When** it runs, **Then** only tests matching X run, at 8 workers.

---

### User Story 2 - No single file holds up the end of the run (Priority: P1)

The two files above 75.6 s are split into parts. Each part has a similar summed time, and no part exceeds 75.6 s. Together the parts run the same checks as before: every tenant route and every permission row is checked exactly once.

**Why this priority**: `CrossTenantRouteTest` alone sets a floor of 218 s summed time on the 8-worker run. Until it is split, no other change can bring the run near G1.

**Independent Test**: From the JUnit files of the exit-check runs, sum each part's test times and confirm every part is at or under 75.6 s. Run the coverage test and confirm the slices equal the full route list with no overlap.

**Acceptance Scenarios**:

1. **Given** the split list, the new file names and the helpers moving to `tests/Pest.php` are in the stage log, **When** the split is made, **Then** only those test files and `tests/Pest.php` change under `tests/`.
2. **Given** the cross-tenant route list, **When** each part's filter is applied, **Then** every route of every rule falls in exactly one part, and a test fails if one is missing or in two parts.
3. **Given** a new tenant route is added later, **When** it matches none of the filters, **Then** the coverage test fails and names it.
4. **Given** the split, **When** the full run executes, **Then** no "Cannot redeclare" error occurs and no test is reported risky.
5. **Given** the split, **When** `EntryPointInventoryTest` runs, **Then** it passes unchanged, because every `it('...')` text is kept word for word.
6. **Given** a cross-tenant part is run on its own (by path or filter), **When** its datasets are collected, **Then** it finds its routes as before (the dataset builder still boots its own application).

---

### User Story 3 - Documents point to files that exist (Priority: P2)

Every live reference to a split file (FR-017 lists them) names the part that now holds the test it refers to. Where a reference means the permission dataset, it names the part that holds that dataset.

**Why this priority**: A developer or AI session adding a permission follows `CLAUDE.md` to the dataset, and a dead path sends them nowhere.

**Independent Test**: Search the repository for the two original file names. Each match is either a correct reference to a part or a historical record (the stage log, past specs).

**Acceptance Scenarios**:

1. **Given** the split, **When** the lines listed in FR-017 are read, **Then** each names an existing file, and the permission-dataset references name the part with the "read a resource" dataset.

---

### User Story 4 - Timing budgets checked against the new load (Priority: P2)

After the splits, the JUnit `time` of the timed tests in `RecommendationQueueTest` (line 110) and `StayListTest` (line 147) is read from 3 full runs. The parallel 4.0 s budget is raised only if an observed time is within 25% of it (at or above 3.0 s). It is raised to the slowest × 1.5, rounded up to the next 0.5 s. The serial 2.0 s is unchanged. The stage log records the observed times and the decision.

**Why this priority**: A different worker count changes the load on these tests. A budget that is too tight makes the suite flaky again.

**Independent Test**: Read the stage log's table of 3 × 2 observed times and check the decision against the 3.0 s rule.

**Acceptance Scenarios**:

1. **Given** all six observed times are under 3.0 s, **When** the result is recorded, **Then** both files stay unchanged and the stage log says so.
2. **Given** an observed time of 3.4 s, **When** the budget is raised, **Then** the new parallel value is 5.5 s (3.4 × 1.5 = 5.1, rounded up to 5.5), only in the file that needed it.

---

### User Story 5 - Exit check and G1 decision recorded (Priority: P1)

The stage log's new §6 records 3 warm `composer test` runs at 8 workers (after one discarded cold run), with JUnit output. It gives the median wall time, the summed time, the slowest file and the test count, compared with Stage 0. It states whether G1 (210 s) is met. If G1 is missed, it gives a revised G1 and the reason (Q1).

**Why this priority**: The design closes Stage 1 on this check. Stage 2 and the `CLAUDE.md` text in Stage 3 depend on the recorded figures.

**Independent Test**: Open §6 and find each figure, the G3 count with its reason, and either "G1 met" or a revised G1 with a reason drawn from the data.

**Acceptance Scenarios**:

1. **Given** the 3 warm runs, **When** §6 is written, **Then** it lists each run's wall time, summed time and test count, their median, and the slowest file with its summed time.
2. **Given** the median is above 210 s, **When** §6 is written, **Then** it gives a revised G1 and explains it from the measured figures, such as the summed work divided by 8 workers.
3. **Given** a run had a failure, **When** results are gathered, **Then** that run is replaced and the failing test is named.

---

### User Story 6 - OPcache comparison (Priority: P3, optional)

The developer enables `opcache.enable_cli=1` in the local PHP configuration (outside the repository) and repeats the exit check. The stage log records both results side by side and notes that this is a machine setting, not a repository change.

**Why this priority**: It may be a cheap gain, but nothing in the repository depends on it, and it can be skipped.

**Independent Test**: §6 shows a second set of 3 warm runs labelled OPcache on, or says the measurement was skipped.

**Acceptance Scenarios**:

1. **Given** the OPcache runs are made, **When** §6 is read, **Then** both medians are shown and the local setting change is described, with no repository file changed for it.

---

### Edge Cases

- **Cached config**: if `bootstrap/cache/config.php` exists before any measured run, stop and tell the developer. Do not delete it silently. (`composer test` clears it, but measurements made another way must not reach the dev database.)
- **Wrong database**: before the first run, confirm the tests resolve to `Hospitality_Ecosystem_testing`, even when the terminal holds `.env` values. If not, stop.
- **Postgres down or restarted**: stop. The next run counts as cold.
- **A part still over 75.6 s**: re-balance the filters (or, for `PermissionAuthorizationTest`, the block boundaries) and measure again. Do not split a third file that is not on the recorded list.
- **A route with no recognisable prefix**: it still has to land in exactly one slice. A catch-all slice ("everything not claimed by another filter") is allowed only if the coverage test still proves there is no overlap.
- **A test that sums across a loop** (like `CostVisibilityTest`'s `$checked > 100`): it stays whole in one part with its dataset. Neither split file is expected to have one; if one is found, it is kept whole.
- **Helper name collision**: a helper moved to `tests/Pest.php` that already has a same-named function there stops the run. Rename nothing; resolve it by moving, not copying.
- **Test count differs from 3,597 + coverage tests**: the run is not counted until the reason is known and recorded.
- **Gap over 5 minutes between runs**: the next run is treated as cold and discarded.

## Requirements *(mandatory)*

### Functional Requirements

**Scope and safety**

- **FR-001**: Changes MUST stay within `tests/`, `composer.json`, `CLAUDE.md` and `docs/`. Nothing under `app/`, `routes/`, `config/`, `database/`, `phpunit.xml` or CI workflows MAY change.
- **FR-002**: All runs MUST use the testing database on the existing `hospitality-postgres` container. The dev database and the container's settings MUST NOT change.
- **FR-003**: Measurement MUST stop if `bootstrap/cache/config.php` exists.
- **FR-004**: Each logical change MUST be its own commit, formatted with Pint first, with no co-author trailer.
- **FR-005**: The three flaky-test fixes in §5 MUST NOT be redone or altered.

**Full-run script**

- **FR-006**: The `test` script in `composer.json` MUST be replaced by, in order: disabling Composer's process timeout; clearing the config, with no additional arguments passed to it; running the full suite in parallel at 8 processes. No other script MAY be added.

**Split list (before any test edit)**

- **FR-007**: Before any test file is edited, the stage log MUST record: the split list (`CrossTenantRouteTest`, `PermissionAuthorizationTest`), each part's file name in the form `<Base><Part>Test.php`, how the rows or blocks are divided between the parts, and every helper or named dataset moving to `tests/Pest.php`.
- **FR-008**: The only test files the stage MAY touch are those on that list, `tests/Pest.php`, the failure message in `EntryPointInventoryTest` (FR-017, text only), and (only if User Story 4 needs it) `RecommendationQueueTest` and `StayListTest`.

**Splitting `CrossTenantRouteTest` (dataset exception)**

- **FR-009**: The dataset builder MUST move to `tests/Pest.php` unchanged in behaviour, taking a fixed filter in addition to its rule. Each part MUST pass its own filter for every one of its tests.
- **FR-010**: Every route of every rule MUST land in exactly one part. A test MUST check that, for each rule, the union of the slices equals the full list and no route is in two slices.
- **FR-011**: The builder MUST still boot its own application while datasets are collected, and still hand back the global error handlers and facades afterwards, in every part.
- **FR-012**: Each part MUST keep the original's `use` imports, `uses(...)`, `beforeEach` and `afterEach`, and its `it('...')` texts word for word.

**Splitting `PermissionAuthorizationTest`**

- **FR-013**: The file MUST be split into consecutive `it()` blocks in source order, with similar summed times per part. The "read a resource" dataset MUST stay whole in one part. The Clarifications describe the fallback.
- **FR-014**: Helpers used by more than one part MUST move to `tests/Pest.php` unchanged and MUST NOT be copied.

**Both splits**

- **FR-015**: No part MAY exceed 75.6 s summed time (median over the exit-check runs).
- **FR-016**: No test may be lost or duplicated. The full-run count MUST equal 3,597 plus the new coverage test cases.

**Documents**

- **FR-017**: Every live reference to a split file MUST name the part (or parts) that now hold the referenced test. Permission-dataset references MUST name the part that holds that dataset. The planning check ([research.md](research.md) R6) found these references: `CLAUDE.md` lines 99, 168 and 191; the message in `tests/Feature/EntryPointInventoryTest.php` line 90; and the comment in `tests/Pest.php` line 1615. The lines the design names in `docs/usage-metering-api-documentation.md` (223) and `docs/ai-cost-attribution-api-documentation.md` (134, 334) refer to files that are not split, so they stay unchanged. Historical records (past specs, reviews, the WP plans, the readiness checklist's dated evidence) also stay unchanged.

**Timing budgets**

- **FR-018**: After the splits, the JUnit time of the two timed tests MUST be read from 3 full runs. The parallel budget MUST be raised only if an observed time is at or above 3.0 s, to the slowest × 1.5 rounded up to the next 0.5 s. The serial budget MUST NOT change. The stage log MUST record the times and the decision either way.

**Exit check (stage log §6)**

- **FR-019**: §6 MUST record 3 warm `composer test` runs at 8 workers (one cold run discarded), with JUnit output in `storage/test-reports/`. It MUST give each run's figures, the median wall time, the summed time, the slowest file, and the test count, beside the Stage 0 figures.
- **FR-020**: §6 MUST state whether G1 (210 s) is met. If not, it MUST give a revised G1 and the reason, from the measured data (Q1).
- **FR-021**: If the optional OPcache measurement is made, §6 MUST give both medians and describe the local setting. No repository file MAY change for it.

### Key Entities

- **Split list**: the files to split, their part names, the row or block division, and the helpers moving out. Written before any edit; it bounds the change set.
- **Part**: one new test file holding a slice of an original file's rows or blocks; has a summed time.
- **Slice filter**: the fixed criterion a cross-tenant part passes to the shared builder; slices must cover the full route list exactly once.
- **Run**: one full parallel execution; has a cold/warm flag, wall time, summed time, test count and pass/fail.
- **Stage log**: `docs/test-suite-performance.md`. Stage 1 adds the split list and §6.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The median warm full-run wall time at 8 workers is recorded and is lower than Stage 0's 302.4 s.
- **SC-002**: No test file's summed time exceeds 75.6 s in the exit-check runs.
- **SC-003**: Every tenant route and every permission row is checked exactly once: the coverage test passes, and the test count equals 3,597 plus the new coverage tests.
- **SC-004**: `composer test` finishes a run longer than 300 s without being stopped, and clears the config first.
- **SC-005**: §6 either shows G1 met or gives a revised G1 with a reason a reader can check against the recorded figures.
- **SC-006**: A search for the two original file names finds no stale reference outside historical records.
- **SC-007**: The change set touches only the files allowed by FR-001 and FR-008, and each commit is one logical change.
- **SC-008**: The dev database is unchanged after all runs.

## Assumptions

- **Worker count 8** is taken from Stage 0 and not re-swept.
- **Part count is not fixed in advance.** `CrossTenantRouteTest` (218 s) needs at least 3 parts to stay under 75.6 s, and probably 4 for margin. `PermissionAuthorizationTest` (79 s) needs 2. The exact division comes from per-test JUnit times in the Stage 0 8-worker runs and is recorded in the split list.
- **The "read a resource" dataset is "the permission dataset"** that `CLAUDE.md` and the docs refer to.
- **The coverage test lives in one cross-tenant part** and reads the full list from the same builder with no filter. It is a plain test, not a dataset, so it adds a small fixed number of test cases.
- **Summed time** is the sum of JUnit `time` of a file's test cases, as in Stage 0. Parallel contention inflates it, so a part's time can vary between runs; FR-015 uses the median.
- **G1 is likely missed.** Stage 0's summed work at 8 workers (~2,234 s) gives a floor of about 280 s, against the 210 s target. A revised G1 is the expected outcome, not a failure of the stage.
- **OPcache** is changed only in the developer's local `php.ini`. It is not committed and not assumed by any test.
- **Branch**: the spec directory is `030-parallel-run-speedup`. The developer chooses whether the work continues on `029-test-suite-measurement` or a new branch.
- **Dependencies**: the `hospitality-postgres` container is up; `phpunit.xml` already forces the testing database and an empty Sentry DSN.
