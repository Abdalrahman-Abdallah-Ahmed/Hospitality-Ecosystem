# Feature Specification: Test Loop in CLAUDE.md (Stage 3)

**Feature Branch**: `032-test-loop-docs` (based on `031-test-module-folders`, not merged yet)

**Created**: 2026-11-16

**Status**: Draft

**Input**: User description: "Stage 3 of docs/designs/test-suite-speed-and-module-runs.md (revision 4): write the test loop into CLAUDE.md so developers and AI sessions use the module runs from Stage 2. Base the branch on 031-test-module-folders (not merged yet). Stage results are in docs/test-suite-performance.md; record Stage 3 in a new §8. Scope: CLAUDE.md, docs/test-suite-performance.md, and the design doc's status line only."

## Problem and Need

Stage 2 split the feature tests into 14 folders and measured the module run: the slowest module, `Reservations`, takes 92 s at 4 processes against 225 s serially ([test-suite-performance.md](../../docs/test-suite-performance.md) §7.4). The full run takes about 304–310 s.

`CLAUDE.md` still tells everyone to run the whole suite (`php artisan test`) after an edit and before a commit. Nobody, human or AI, is told the module run exists, which folder covers which feature, or what to do when a run fails outside the changed module. Until this is written down, the Stage 2 folders save no time in practice, and G4 ("Written loop") is not met.

Stage 3 writes the loop into `CLAUDE.md`, records the G1–G4 summary and a manual exit check in the stage log, and marks the design as implemented.

**Actors**:

- **Developer** (the repository owner): runs the module run after an edit and `composer test` before a commit.
- **AI coding session**: reads `CLAUDE.md` at start and must follow the same loop without being told.
- **Reviewer**: checks that only the agreed lines of `CLAUDE.md` changed and that §8 records each result.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An AI session follows the loop unprompted (Priority: P1)

A fresh AI session is given a one-module task, for example "add a test to `tests/Feature/Tasks/TaskControllerTest.php` that a task title over 255 characters is rejected". Without further instruction it puts the test in the `Tasks` folder, runs the `Tasks` module run after the edit, and runs `composer test` before proposing a commit.

**Why this priority**: This is the design's Stage 3 exit check and the reason for G4. AI sessions read `CLAUDE.md` and nothing else about the test loop.

**Independent Test**: Start a fresh session in the repository, give it the task, watch which commands it runs and where it writes the test, record the result in §8, then discard its change.

**Acceptance Scenarios**:

1. **Given** the updated `CLAUDE.md`, **When** a fresh session finishes the edit, **Then** it runs `php artisan test tests/Feature/Tasks --parallel --processes=4`.
2. **Given** the same session, **When** it is about to propose a commit, **Then** it runs `./vendor/bin/pint` and `composer test`.
3. **Given** the same session, **When** it adds the test, **Then** the test is in `tests/Feature/Tasks/`, not directly under `tests/Feature/`.
4. **Given** the check is done, **When** §8 is written, **Then** it records each of these three observations as yes or no, and the session's change is discarded.

---

### User Story 2 - A developer knows which command to run when (Priority: P1)

The developer opens `CLAUDE.md` and finds, in the Commands block, one command for a one-module edit, one for an edit across modules, the full run before a commit, and the single-test filter. The Git section says the same thing about the full run before a commit.

**Why this priority**: The Commands block is the first place anyone looks; if it and the Git section disagree, the loop is ambiguous.

**Independent Test**: Read the Commands block and the Git section and confirm the pre-commit command is `composer test` in both, and the after-edit command is the module run.

**Acceptance Scenarios**:

1. **Given** the Commands block, **When** it is read, **Then** the old `php artisan test   # or: php artisan test --filter=SomeTest` line is replaced by exactly four lines: the parallel module run (4 processes), the serial multi-folder run with the note that parallel accepts one path, `composer test` before a commit, and `--filter` for one test.
2. **Given** the Git section, **When** it is read, **Then** its pre-commit line says `./vendor/bin/pint` and `composer test`, and no line anywhere in `CLAUDE.md` still says to run plain `php artisan test` before a commit.

---

### User Story 3 - Rules for edge cases in the loop (Priority: P2)

An edit touches a class used by several modules, or changes shared test setup, or a run reports zero tests, or the cached-config guard stops a run, or a test fails in another module, or Postgres is down. In each case `CLAUDE.md` says what to do.

**Why this priority**: These are the cases where a module run alone gives a false green or where an AI session might "fix" unrelated code (CUJ 1 variant, CUJ 2, CUJ 5).

**Independent Test**: For each case, find the matching rule in the Testing or Git section.

**Acceptance Scenarios**:

1. **Given** the Testing section, **When** it is read, **Then** it contains the folder table and rules a–f from the requirements below.
2. **Given** the Git section, **When** it is read, **Then** it contains the rules for failures inside and outside the changed module, invariant tests, and Postgres being unreachable.

---

### User Story 4 - The stage log closes the design (Priority: P2)

The stage log gains §8 with the `CLAUDE.md` change summary, a G1–G4 table, and the exit-check result. The design doc's status says it is implemented and links to the stage log.

**Why this priority**: The design's Verification Plan closes on G4 being recorded; the status line tells readers the design is no longer a proposal.

**Independent Test**: Open §8 and find each item; open the design doc and read the status line.

**Acceptance Scenarios**:

1. **Given** §8, **When** it is read, **Then** it has a G1–G4 table with target, result and "met" or "revised" for each, using the figures in FR-014.
2. **Given** the design doc, **When** its header is read, **Then** the Status line reads "Implemented (Stages 0–3)" and links to `docs/test-suite-performance.md`, and nothing else in the design doc changed.

---

### Edge Cases

- **The exit-check session does not follow the loop** (for example, it runs the full suite after the edit, or skips `composer test`): the result is recorded as observed in §8, with the step it missed. The wording in `CLAUDE.md` may then be tightened in a follow-up commit on this branch, and the check repeated; both attempts are recorded.
- **The exit-check session cannot run tests** (Postgres down, cached config): it should stop and say so; §8 records whether it did.
- **The exit-check session's change is not fully discarded**: before committing §8, `git status` must show no change under `tests/` or `app/`.
- **A Stage 2 path reference is touched by accident**: the paths at the Testing section's `TenantIsolationTest` and `PermissionAuthorizationResourcesTest` lines and the Access-control section's line 99 must stay exactly as Stage 2 left them.
- **Rule wording drifts from the Commands block**: every place that names the pre-commit command must say `composer test`.

## Requirements *(mandatory)*

### Functional Requirements

**Scope**

- **FR-001**: Only `CLAUDE.md`, `docs/test-suite-performance.md`, the Status line of `docs/designs/test-suite-speed-and-module-runs.md`, and this feature's `specs/032-test-loop-docs/` files MAY change. Nothing under `tests/`, `app/`, nor `composer.json`, `phpunit.xml` or CI workflows MAY change.
- **FR-002**: Places in `CLAUDE.md` MUST be located by their content, not by line number. Every line not named in FR-003 to FR-011 MUST stay unchanged, including the three test paths updated in Stage 2.

**Commands block**

- **FR-003**: The line `php artisan test   # or: php artisan test --filter=SomeTest` MUST be replaced by these four lines, with aligned comments:
  - `php artisan test tests/Feature/<Module> --parallel --processes=4` — after each edit (one module);
  - `php artisan test tests/Feature/<A> tests/Feature/<B>` — after an edit touching several modules (serial; parallel accepts one path);
  - `composer test` — full parallel run with the invariant tests, before a commit;
  - `php artisan test --filter=SomeTest` — one test.
- **FR-004**: The choice of 4 processes for the module run MUST trace to §7.4 (Reservations 92 s at 4 processes, 225 s serial); the stage log's §8 states this.

**Testing section**

- **FR-005**: The line "Pest, feature tests in `tests/Feature`, one file per controller/service." MUST become "Pest, feature tests in `tests/Feature/<Module>/`, one file per controller or service, in its module's folder."
- **FR-006**: The shared-fixtures lines (file-local helpers, or `tests/Pest.php` when more than one file needs them) MUST stay word for word.
- **FR-007**: The section MUST gain the Folder | Covers table from the design's Technical Plan, Stage 2 (14 rows), with the `Invariants/` row marked "not a module; runs in the full run only".
- **FR-008**: The section MUST gain these rules:
  - a. After an edit, run the module run for the folder whose feature you changed.
  - b. If the changed file serves more than one module, or you are unsure: search `tests/Feature` for the class name and run every folder that mentions it, or run `composer test`.
  - c. If you changed shared test setup (`tests/Pest.php`, `tests/TestCase.php`, `phpunit.xml`, `composer.json`/`composer.lock`, `config/`, `bootstrap/`, `database/`), run `composer test` instead of a module run.
  - d. A run that reports zero tests is a failure, not a pass (usually a wrong folder name).
  - e. If a test run stops with the cached-config message, run `php artisan config:clear` and rerun. Never delete the guard.
  - f. New test files go in their module's folder; never directly under `tests/Feature/`.

**Git section**

- **FR-009**: The line "Run `./vendor/bin/pint` and `php artisan test` before proposing a commit." MUST be replaced by:
  - Run `./vendor/bin/pint` and `composer test` before proposing a commit.
  - Fix failures inside the changed module and rerun.
  - Report failures outside it instead of editing unrelated code.
  - Never edit an invariant test only to make it pass.
  - If Postgres is not reachable, stop and tell the user.
- **FR-010**: The Commands block and the Git section MUST NOT contradict each other: both name `composer test` as the pre-commit run.
- **FR-011**: The Git section's other lines (no co-author, commit only when asked, subject-line style) MUST stay unchanged.

**Stage log §8**

- **FR-012**: `docs/test-suite-performance.md` MUST gain "## 8. Stage 3 — CLAUDE.md (2026-11-16)" after §7, and no earlier section MAY change.
- **FR-013**: §8 MUST summarise the `CLAUDE.md` diff: which places changed and how, and that the Stage 2 path references were kept.
- **FR-014**: §8 MUST contain a G1–G4 table with columns Goal, Target, Result, Met or revised:
  - G1: target revised to 300 s (from 3 min 30 s); result about 304–310 s; revised.
  - G2: target under 180 s for the slowest module; result `Reservations` 92 s at 4 processes; met (with Q2).
  - G3: target the same test count as the baseline; result 3,598 tests; met, with the difference from the design's 3,597 explained by the earlier stage log entry.
  - G4: target `CLAUDE.md` gives the loop; result this stage; met or not per the exit check.
- **FR-015**: §8 MUST record the exit check: the task given, whether the session ran the module command after the edit, whether it ran `composer test` before proposing a commit, whether the test went into `tests/Feature/Tasks/`, and that the change was discarded.

**Finish**

- **FR-016**: The design doc's Status line MUST read "Implemented (Stages 0–3)" with a link to `docs/test-suite-performance.md`. No other line of the design doc MAY change.
- **FR-017**: `specs/031-test-module-folders/tasks.md` MUST be committed on `031-test-module-folders` before this branch is created. (Done while specifying: commit `42decef`.)
- **FR-018**: `./vendor/bin/pint --test` MUST pass. Each logical change MUST be its own commit with no co-author trailer: (1) spec, plan and tasks; (2) `CLAUDE.md`; (3) stage log §8; (4) design status.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A fresh AI session given a one-module task runs the module run after its edit, runs the full run before proposing a commit, and puts the test in the right folder — all three observed and recorded as yes.
- **SC-002**: After an edit inside the slowest module, the documented after-edit run takes about 92 s instead of the full run's 304–310 s (roughly a third of the time).
- **SC-003**: Zero places in `CLAUDE.md` give a different pre-commit test command.
- **SC-004**: The change set touches exactly the three named documents plus this feature's spec files; no test, application, build or CI file changes.
- **SC-005**: All four goals G1–G4 appear in §8 with a target, a result and a met-or-revised verdict.

## Assumptions

- The figures for G1–G3 come from the existing stage log (§6.4, §7.4, §7.5) and are not re-measured in Stage 3.
- The exit check is done once by hand, by the developer starting a fresh session; the result is reported back and written into §8.
- `composer test` stays at `--parallel --processes=8` (Stage 1); Stage 3 does not change it.
- The 4-process module run is used for all modules, as Q2 prescribes, even those fast enough serially.
- Branch `032-test-loop-docs` stays based on `031-test-module-folders` and merges after it.
