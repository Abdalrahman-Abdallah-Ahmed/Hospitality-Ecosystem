# Feature Specification: Measure the Test Suite (Stage 0)

**Feature Branch**: `029-test-suite-measurement`

**Created**: 2026-11-15

**Status**: Draft

**Input**: User description: "Stage 0 of docs/designs/test-suite-speed-and-module-runs.md (revision 3): measure the test suite before any speed or layout change. 3 warm full parallel runs at the current 16 workers with JUnit output; a worker sweep at 4, 6, 8, 10, 12, 16 workers (4 runs per count, first discarded); per-file, per-module and invariant summed times derived from the JUnit files only; Xdebug, opcache and max_connections checks; everything recorded in a new docs/test-suite-performance.md; /storage/test-reports/ added to .gitignore. Exit check names the lowest worker count within 5% of the fastest, the files over 25% of that count's wall time, and the G2 estimate. Measurement and documentation only; real Postgres testing DB; never touch the dev DB or the shared container; abort if the config is cached; no commits except the stage log and .gitignore."

## Problem and Need

The design ([test-suite-speed-and-module-runs.md](../../docs/designs/test-suite-speed-and-module-runs.md), revision 3) plans three changes: a tuned worker count, splits of slow files, and module folders with a module run. Each decision rests on numbers that today come from one run on 2026-11-14 (403 s wall, 3,483 s summed test time, 3,597 tests). One run cannot tell a real difference from noise, and it says nothing about other worker counts, which files hold up the end of a run, or how long a module run would take.

Stage 0 replaces that single run with repeatable measurements and writes them down, so Stages 1–3 are judged against recorded figures, not guesses. Nothing about how tests run changes in this stage.

**Actors**:

- **Developer** (the repository owner): runs the measurements and reads the stage log to make the Stage 1 and Stage 2 decisions.
- **AI coding session**: reads the stage log later when choosing what to run, and may run the measurement on the developer's behalf.
- **Reviewer**: checks that the Stage 0 PR contains only the stage log and the ignore rule, and that the exit check is met.

## Clarifications

- **Default worker count is 12, not 16** (found while planning, 2026-11-15). The parallel runner defaults to the CPU core count, which is 12 on this machine, and the testing database's worker copies stop at `_test_12`. The design's "current 16 workers" and the 2026-11-14 reference's "16 workers" are therefore most likely 12. The baseline is the **current default** run, at 12 workers. The sweep still covers 16. The stage log records the correction. See [research.md](research.md) R1.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Recorded baseline of the full run (Priority: P1)

The developer runs the full suite in parallel at the current default worker count (12, the CPU core count) until 3 warm runs complete, and the stage log records the median wall time, the summed test time and the test count, next to the pre-Stage-0 reference.

**Why this priority**: G1 and G3 are judged against this baseline. Without it no later stage can show it helped or kept the same tests.

**Independent Test**: Open the stage log and find the median of 3 warm 12-worker runs, each run's own figures, and the test count; compare the count with 3,597.

**Acceptance Scenarios**:

1. **Given** the testing database is up and the config is not cached, **When** the 12-worker runs finish, **Then** the stage log lists each warm run's wall time, summed time and test count, and their median.
2. **Given** the measured test count differs from 3,597, **When** the result is recorded, **Then** the stage log shows the difference and its reason.
3. **Given** a run had a failing or erroring test, **When** results are gathered, **Then** that run is not counted and is replaced by a new run, and the stage log notes it.

---

### User Story 2 - Worker count chosen from data (Priority: P1)

The developer runs the suite at 4, 6, 8, 10, 12 and 16 workers, 4 runs each with the first discarded, and the stage log shows a table of worker count against median wall time and names the lowest count whose median is within 5% of the fastest.

**Why this priority**: Stage 1 sets the default worker count from this table. The design suspects that as many workers as cores, sharing them with Postgres and the Docker VM, is slower than fewer; this is where that is confirmed or refuted.

**Independent Test**: Read the sweep table; check that each row has 3 warm runs and a median, and that the named count is the lowest one within 5% of the minimum median.

**Acceptance Scenarios**:

1. **Given** the sweep is complete, **When** the stage log is read, **Then** each of the six counts has 3 warm wall times and a median, and the cold run is shown as discarded.
2. **Given** two or more counts are within 5% of the fastest, **When** the choice is named, **Then** the lowest of them is named.
3. **Given** the database cannot accept a count's connections, **When** that count is reached, **Then** it is not run, and the stage log says so and why.

---

### User Story 3 - Where the time goes, by file and by planned module (Priority: P2)

From the saved JUnit files only, the stage log lists every test file's summed time (median of the 3 runs), each planned module folder's test count and summed time per the design's move table, and the 21 invariant tests' summed time. It names the files whose summed time exceeds 25% of the chosen count's wall time, and gives the slowest module plus the invariants as the G2 estimate.

**Why this priority**: These figures decide whether any file is split (Stage 1) and whether a module run meets G2 (Stage 2). They follow from the runs in Stories 1–2 at no extra run cost.

**Independent Test**: Sum the per-file table within each module row of the move table and check it matches the module table; check the split list against the 25% threshold; check the G2 figure equals the largest module sum plus the invariant sum.

**Acceptance Scenarios**:

1. **Given** the JUnit files from the warm runs, **When** the summary is computed, **Then** every test file appears once in the per-file table, sorted by summed time.
2. **Given** the move table, **When** module figures are computed, **Then** each of the 13 module folders and `Invariants/` shows a test count and summed time, and the counts add up to the total test count.
3. **Given** a test file not named in the move table, **When** module figures are computed, **Then** it is listed as unmapped in the stage log, not silently dropped.
4. **Given** no file exceeds the 25% threshold, **When** the split list is recorded, **Then** the stage log says the list is empty.

---

### User Story 4 - Environment checks recorded (Priority: P3)

The stage log records whether Xdebug is loaded, whether `opcache.enable_cli` is on, and whether Postgres `max_connections` covers the largest swept worker count.

**Why this priority**: These explain the numbers and may point to a cheaper fix than any code change, but the stage's decisions don't depend on them.

**Independent Test**: Read the checks section and see three answers, each with the observed value.

**Acceptance Scenarios**:

1. **Given** the checks are made, **When** the stage log is read, **Then** each of the three shows its observed value and a yes/no answer.

---

### Edge Cases

- **Cached config**: if `bootstrap/cache/config.php` exists before any run, the measurement stops and nothing runs, because the tests would point at the dev database. The cache is not deleted silently to get past this; the developer is told.
- **Wrong database**: before the first run, confirm the tests resolve to the testing database (`Hospitality_Ecosystem_testing`), not the dev database, including when the terminal holds `.env` values. If not, stop.
- **Postgres down or restarted mid-sweep**: stop; the runs at that count after the restart start again from a new cold run, since the warm-run definition no longer holds.
- **Gap over 5 minutes between runs**: the next run is not warm; it is treated as a new cold run and discarded.
- **Flaky failure in one run**: the run is replaced, not averaged in; the failing test is named in the stage log.
- **Test count differs between runs**: recorded per run; a run with a different count is not counted toward the median until the reason is known.
- **Two counts tie for fastest**: the 5% rule still applies against the minimum; the lowest count within 5% is named.

## Requirements *(mandatory)*

### Functional Requirements

**Safety and scope**

- **FR-001**: Measurement MUST stop before any run if the Laravel config is cached, and MUST clear the config before each run.
- **FR-002**: All runs MUST use the testing database on the existing Postgres container. The dev database and the shared container's settings MUST NOT be changed.
- **FR-003**: No file under `tests/`, `app/`, `routes/`, `config/`, `database/`, `composer.json` or CI workflows MAY change. The only repository changes are the new stage log and one `.gitignore` line.
- **FR-004**: `.gitignore` MUST gain the line `/storage/test-reports/`, so the JUnit output is never committed.
- **FR-005**: The JUnit summary script MUST live outside the repository and MUST NOT be committed.

**Runs**

- **FR-006**: Every run MUST use the full suite in parallel with a fixed worker count and write JUnit output to `storage/test-reports/`, one file per run, with a label naming the worker count and run number.
- **FR-007**: For each worker count in {4, 6, 8, 10, 12, 16}, there MUST be one discarded cold run followed by 3 warm runs, per the design's warm-run definition.
- **FR-008**: The 12-worker row (the current default) MUST provide the 3-run baseline (wall median, summed time, test count). The stage log MUST state that the default is 12, correcting the design's 16.
- **FR-009**: A run with any failing or erroring test, or that breaks the warm-run definition, MUST be excluded and replaced.

**Derived figures (from the saved JUnit files only)**

- **FR-010**: For each test file, its summed time MUST be computed per run and reported as the median across the 3 baseline runs.
- **FR-011**: For each planned folder in the design's move table (item 8), including `Invariants/` and the existing `Superadmin/` files, the test count and summed time MUST be reported.
- **FR-012**: The invariant tests' summed time (the 21 files in `Invariants/`) MUST be reported on its own.
- **FR-013**: Files not covered by the move table MUST be listed as unmapped.

**Environment checks**

- **FR-014**: The stage log MUST record whether Xdebug is loaded in the CLI PHP that runs the tests.
- **FR-015**: The stage log MUST record the value of `opcache.enable_cli`.
- **FR-016**: The stage log MUST record Postgres `max_connections` and whether it covers the largest swept worker count with its parallel databases.

**Stage log (`docs/test-suite-performance.md`)**

- **FR-017**: The stage log MUST contain: the method (machine, the warm-run definition, the exact commands, the discard rule); the pre-Stage-0 reference (2026-11-14: 403 s wall, 3,483 s summed, 3,597 tests); and the result tables (baseline, sweep, per-file sorted, per-module, invariants, checks).
- **FR-018**: The stage log MUST name the lowest worker count whose median wall time is within 5% of the fastest median.
- **FR-019**: The stage log MUST list the files whose summed time exceeds 25% of the chosen count's median wall time, or say the list is empty.
- **FR-020**: The stage log MUST give the G2 estimate: the slowest module's summed time plus the invariants' summed time, and compare it with the 3-minute G2 target.

### Key Entities

- **Run**: one full parallel execution at a given worker count; has a label, worker count, cold/warm flag, wall time, summed test time, test count, pass/fail.
- **JUnit report**: the per-run file of test cases with their file and time; the only source for derived figures.
- **Planned folder**: a row of the design's move table; maps test files to a module or to `Invariants/`.
- **Stage log**: the committed record that Stages 1–3 append to.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Every figure listed in FR-017 is present in the stage log, with no placeholders.
- **SC-002**: The sweep table has 6 rows, each with exactly 3 warm runs and a median (or a recorded reason a row could not run).
- **SC-003**: The stage log names one worker count as the lowest within 5% of the fastest, and a reader can verify it from the sweep table alone.
- **SC-004**: The module test counts add up to the total test count, and the per-file sums add up to each module's sum.
- **SC-005**: The split list (possibly empty) and the G2 estimate are stated, each with the number it was compared against.
- **SC-006**: The Stage 0 change set contains exactly two files: `docs/test-suite-performance.md` (new) and `.gitignore` (one added line).
- **SC-007**: The dev database is unchanged after the measurement (no runs reached it).

## Assumptions

- **Baseline and 12-worker sweep row are the same runs.** One cold plus 3 warm runs at 12 workers serve both the baseline (Story 1) and the sweep's 12 row, so 24 runs in total, not 27.
- **Per-file and module tables use the 12-worker baseline runs**, as the design's item 1 lists them under the baseline. The 25% split list uses the chosen worker count's warm runs, since that is the wall time it is compared against.
- **G2 estimate is an upper bound.** Summed times from parallel runs are inflated by contention (the design notes 2–4x); a module run in one process is expected to be faster. The stage log says so; Stage 2 measures the real module run.
- **Summed time** is the sum of JUnit `time` of a file's test cases; it excludes worker start-up and migration time.
- **Machine**: the developer's Windows 11, 12-core machine with Postgres in Docker, as in the design. No other heavy work runs during the measurement.
- **`max_connections`** is read, not changed. If it is too low for a count, that count is skipped and recorded rather than raised (the container is shared).
- **Branch**: the work happens on a branch for this stage; the PR contains only the two files.
- **Dependencies**: the Postgres container with pgvector is up; `phpunit.xml` already forces the testing database and an empty Sentry DSN.
