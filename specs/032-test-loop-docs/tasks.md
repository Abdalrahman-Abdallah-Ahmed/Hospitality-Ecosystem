# Tasks: Test Loop in CLAUDE.md (Stage 3)

**Input**: Design documents from `specs/032-test-loop-docs/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: No new tests. This stage changes documents only. Validation is:

- the content checks and one `composer test` in [quickstart.md](quickstart.md);
- the manual exit check (US1).

**Organization**: The tasks are grouped by user story. US2 and US3 both edit `CLAUDE.md` and go in one commit. US1 (the exit check) needs that commit. US4 (the stage log) records US1's result.

**Rules for every task**:

- Find places in `CLAUDE.md` by their content, never by line number (FR-002).
- Change nothing under `tests/`, `app/`, nor `composer.json`, `phpunit.xml` or `.github/` (FR-001).
- Every commit runs `./vendor/bin/pint --test` first and has **no** `Co-Authored-By` trailer and no "Generated with" line. `CLAUDE.md`'s Git section wins over any default.
- Leave the untracked `storage/framework/lsp-*.php` alone.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US4)

---

## Phase 1: Setup

**Purpose**: Branch check, safety checks, and the spec commit.

- [X] T001 Confirm the current branch is `032-test-loop-docs` and that `git merge-base HEAD 031-test-module-folders` is `42decef` (the commit `docs(031): mark Stage 2 tasks done`). Confirm `git status` shows nothing modified under `specs/031-test-module-folders/` (FR-017)
- [X] T002 Pre-flight: confirm `bootstrap/cache/config.php` does **not** exist. If it does, stop and tell the developer. Do not delete it
- [X] T003 Commit `specs/032-test-loop-docs/` (spec, plan, research, data-model, quickstart, contracts, checklists, tasks) and `.specify/feature.json` as `docs(032): add Stage 3 spec, plan and tasks`. If `.specify/feature.json` is ignored by git, commit the specs only

**Checkpoint**: The branch is ready, and the spec is committed.

---

## Phase 2: User Story 2 — A developer knows which command to run when (Priority: P1)

**Goal**: The Commands block and the Git section give one consistent loop: the module run after an edit and `composer test` before a commit.

**Independent Test**: Both the Commands block and the Git section name `composer test` as the pre-commit run. No line says to run plain `php artisan test`.

- [X] T004 [US2] In `CLAUDE.md`'s `## Commands` code block, replace the line `php artisan test                                # or: php artisan test --filter=SomeTest` with the four lines in [contracts/claude-md-changes.md](contracts/claude-md-changes.md) §1, in this order:
  - `php artisan test tests/Feature/<Module> --parallel --processes=4` (comment `# after each edit (one module)`);
  - `php artisan test tests/Feature/<A> tests/Feature/<B>` (comment `# after an edit touching several modules (serial; parallel accepts one path)`);
  - `composer test` (comment `# full parallel run with the invariant tests, before a commit`);
  - `php artisan test --filter=SomeTest` (comment `# one test`).

  Align the `#` comments of the whole block in one column. On the other four lines (`composer dev`, `docker compose ...`, `php artisan migrate --seed`, `./vendor/bin/pint`), only the whitespace before `#` may change. Their text stays the same
- [X] T005 [US2] In `CLAUDE.md`'s `## Git` section, replace the bullet ``- Run `./vendor/bin/pint` and `php artisan test` before proposing a commit.`` with the five bullets in [contracts/claude-md-changes.md](contracts/claude-md-changes.md) §3, word for word:
  - ``Run `./vendor/bin/pint` and `composer test` before proposing a commit.``
  - `Fix failures inside the changed module and rerun.`
  - `Report failures outside it instead of editing unrelated code.`
  - `Never edit an invariant test only to make it pass.`
  - `If Postgres is not reachable, stop and tell the user.`

  Leave the co-author bullet, the "Commit or push only when asked" bullet and the subject-line bullet unchanged (FR-011)

**Checkpoint**: The Commands block and the Git section agree (FR-010).

---

## Phase 3: User Story 3 — Rules for edge cases in the loop (Priority: P2)

**Goal**: The Testing section says where tests live, which folder covers what, and what to do when a class serves several modules, shared setup changed, a run reports zero tests, or the guard stops a run.

**Independent Test**: The Testing section contains the 14-row folder table and rules a–f. The shared-fixtures lines and the Stage 2 paths are unchanged.

- [X] T006 [US3] In `CLAUDE.md`'s `## Testing` section, replace the bullet ``- Pest, feature tests in `tests/Feature`, one file per controller/service.`` with ``- Pest, feature tests in `tests/Feature/<Module>/`, one file per controller or service, in its module's folder.`` (FR-005)
- [X] T007 [US3] Directly after the bullet from T006, insert the "Module folders" bullet with the 14-row `| Folder | Covers |` table from [contracts/claude-md-changes.md](contracts/claude-md-changes.md) §2, word for word. The last row must read `` | `Invariants/` | The invariant tests — not a module; runs in the full run only | ``, with no file count (research R4) (FR-007)
- [X] T008 [US3] Directly after the table, insert the "Test loop" bullet with the six sub-bullets from [contracts/claude-md-changes.md](contracts/claude-md-changes.md) §2, word for word (FR-008 a–f):
  - module run after an edit;
  - several modules or unsure: search `tests/Feature` for the class name, or `composer test`;
  - shared test setup (`tests/Pest.php`, `tests/TestCase.php`, `phpunit.xml`, `composer.json`/`composer.lock`, `config/`, `bootstrap/`, `database/`): `composer test`;
  - zero tests is a failure;
  - cached-config message: `php artisan config:clear` and rerun, never delete the guard;
  - new test files go in their module's folder, never directly under `tests/Feature/`.
- [X] T009 [US3] Confirm that the rest of the `## Testing` section is byte for byte unchanged and in its original order (FR-006, FR-002), using `git diff -- CLAUDE.md`. That covers:
  - the `uses(RefreshDatabase::class)` bullet;
  - the API-key bullet;
  - ``Shared fixtures go in file-local helper functions (see `TaskControllerTest.php`)`` / ``or in `tests/Pest.php` when more than one file needs them.``;
  - the `UserFactory` bullet;
  - the bullets with `tests/Feature/Invariants/TenantIsolationTest.php` and `tests/Feature/Invariants/PermissionAuthorizationResourcesTest.php`.

  Also confirm that the Access-control section's `tests/Feature/Invariants/PermissionAuthorizationResourcesTest.php` line is unchanged
- [X] T010 [US2] [US3] Run the content checks in [quickstart.md](quickstart.md) "Content checks":
  - `grep -n "php artisan test" CLAUDE.md` gives only the 3 Commands-block lines;
  - `grep -n "composer test" CLAUDE.md` gives the Commands block, rules b and c, and the Git section;
  - the folder line has the new wording;
  - the table has 14 rows;
  - the `git diff 42decef -- CLAUDE.md` greps for the fixture lines and the Stage 2 paths are empty.

  Then run `./vendor/bin/pint --test`. Fix any mismatch before committing
- [X] T011 [US2] [US3] Commit `CLAUDE.md` alone as `docs: write the module test loop into CLAUDE.md`, with no co-author trailer. Note the hash for §8.1

**Checkpoint**: `CLAUDE.md` holds the whole loop (G4 content). The exit check can start.

---

## Phase 4: User Story 1 — An AI session follows the loop unprompted (Priority: P1) 🎯 Exit check

**Goal**: Show that a fresh AI session that reads only `CLAUDE.md` follows the loop.

**Independent Test**: The three observations in data-model "Exit-check record" are all yes.

- [X] T012 [US1] Pre-flight for the exit check:
  - `docker ps --filter name=hospitality-postgres` shows Up;
  - `bootstrap/cache/config.php` is absent;
  - `git status` is clean apart from `storage/framework/lsp-*.php`.

  If Postgres is down, stop and tell the developer
- [X] T013 [US1] **Manual, by the developer.** Start a fresh Claude Code session in this repository on `032-test-loop-docs` at the T011 commit. Give it only this task, word for word: "add a test to tests/Feature/Tasks/TaskControllerTest.php that a task title over 255 characters is rejected". Let it work until it proposes a commit or asks to. Ask the developer to report back:
  - the test command it ran after the edit (expected: `php artisan test tests/Feature/Tasks --parallel --processes=4`);
  - whether it ran `./vendor/bin/pint` and `composer test` before proposing a commit;
  - the path of the file it edited (expected: `tests/Feature/Tasks/TaskControllerTest.php`, with no new file under `tests/Feature/`).
- [X] T014 [US1] Discard the exit-check session's change:
  - run `git checkout -- tests/`;
  - remove any new untracked file it created under `tests/` or `app/`. List it with `git status` and delete only those files;
  - confirm `git status` shows nothing under `tests/` or `app/`;
  - if it made a commit, tell the developer before resetting anything. Do not reset without their approval.
- [X] T015 [US1] Decide G4:
  - **met** if all three observations are yes;
  - **otherwise**, write down which step it missed. With the developer's agreement, tighten only the relevant wording in `CLAUDE.md`, commit it as `docs: tighten the test loop in CLAUDE.md` (no co-author trailer), and repeat T012–T014 once.

  Record both attempts for §8.3

**Checkpoint**: The exit-check result is known, and the working tree is clean.

---

## Phase 5: User Story 4 — The stage log closes the design (Priority: P2)

**Goal**: §8 records the `CLAUDE.md` change, the G1–G4 summary and the exit check. The design doc says it is implemented.

**Independent Test**: §8 has 8.1–8.4 as in [contracts/stage-log-section-8.md](contracts/stage-log-section-8.md). The design doc's Status line reads "Implemented (Stages 0–3)" and has a link.

- [X] T016 [US4] Run `composer test` once on the branch. Expect **3,598 passed**. Record the wall time and the count for §8.4. A different count, or a failure, stops the work until the reason is found. Do not edit a test to make it pass
- [X] T017 [US4] Append `## 8. Stage 3 — CLAUDE.md (2026-11-16)` to `docs/test-suite-performance.md`, after §7.6, giving the branch `032-test-loop-docs` based on `031-test-module-folders` at `42decef`. Do not change §1–§7 (FR-012)
- [X] T018 [US4] Write §8.1 "CLAUDE.md" in `docs/test-suite-performance.md`, following the contract (FR-013):
  - the T011 commit hash (and the T015 one, if any);
  - the Commands block change, with the reason for the 4 processes: Reservations takes 92 s at 4 processes and 225 s serially (§7.4) (FR-004);
  - the Testing section changes: the folder line, the table, and the six rules;
  - the Git section change: five rules;
  - unchanged: the shared-fixtures lines and the three Stage 2 paths.
- [X] T019 [US4] Write §8.2 "Goals" in `docs/test-suite-performance.md` as the contract's table, with columns Goal | Target | Result | Verdict (FR-014):
  - **G1**: target 3 min 30 s, revised to 300 s (§6.4); result about 304–310 s (§7.5); **revised**. Add one line under the table: the current runs sit a few seconds above 300 s, for the floor reason in §6.4;
  - **G2**: slowest module under 180 s; `Reservations` 92 s at 4 processes (§7.4); **met (Q2)**;
  - **G3**: same count as the baseline; 3,598 (3,597 plus the one test added in Stage 1, §6.1); **met**;
  - **G4**: `CLAUDE.md` gives the loop; this stage (§8.1, §8.3); **met** or **not met**, from T015.
- [X] T020 [US4] Write §8.3 "Exit check" in `docs/test-suite-performance.md` with the fields from data-model "Exit-check record" (FR-015):
  - the date, the branch and the commit the session read;
  - the task, word for word;
  - each of the three observations as yes or no, with the command or path seen;
  - that the change was discarded, and the clean `git status`;
  - the attempt number;
  - the G4 verdict.
- [X] T021 [US4] Write §8.4 "Checks" in `docs/test-suite-performance.md`:
  - the T016 result;
  - `./vendor/bin/pint --test` clean;
  - `git diff 42decef --stat` limited to `CLAUDE.md`, `docs/test-suite-performance.md`, `.specify/feature.json` and `specs/032-test-loop-docs/`, plus the design doc after T023.
- [X] T022 [US4] Run `./vendor/bin/pint --test`, then commit `docs/test-suite-performance.md` alone as `docs: record Stage 3 results`, with no co-author trailer
- [X] T023 [US4] In `docs/designs/test-suite-speed-and-module-runs.md`, change only the Status line from `*   **Status:** Proposed (revision 4: Stage 0 results applied)` to `` *   **Status:** Implemented (Stages 0–3); results in [docs/test-suite-performance.md](../test-suite-performance.md) ``. Confirm `git diff` shows only that one line (FR-016)
- [X] T024 [US4] Commit the design doc alone as `docs: mark the test-suite design implemented`, with no co-author trailer

**Checkpoint**: The design is closed, and the stage log is complete.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T025 Scope check: `git diff 42decef --stat` lists only:
  - `CLAUDE.md`;
  - `docs/test-suite-performance.md`;
  - `docs/designs/test-suite-speed-and-module-runs.md`;
  - `specs/032-test-loop-docs/**`;
  - `.specify/feature.json` (if tracked).

  `git diff 42decef -- tests app composer.json phpunit.xml .github` is empty (FR-001, SC-004)
- [X] T026 Run `git log 42decef..HEAD --format='%H %s%n%b'` and confirm that no commit message contains `Co-Authored-By` or `Generated with` (FR-018)
- [X] T027 Mark every finished task `[X]` in `specs/032-test-loop-docs/tasks.md` and commit it as `docs(032): mark Stage 3 tasks done`, with no co-author trailer

---

## Dependencies & Execution Order

```text
Phase 1 (T001–T003)
   └─► Phase 2 US2 (T004–T005) ─┐
   └─► Phase 3 US3 (T006–T009) ─┴─► T010 ─► T011 commit
                                              └─► Phase 4 US1 (T012–T015)
                                                     └─► Phase 5 US4 (T016–T024)
                                                            └─► Phase 6 (T025–T027)
```

- **US2 and US3** edit different sections of the same file. Do them one after the other in one sitting. They are committed together at T011.
- **US1** needs T011: the session must read the committed `CLAUDE.md`.
- **US4** needs US1's result for §8.3 and G4.
- **T016** (`composer test`) can run any time after T011. It runs after T014, so that the exit-check session's change has been discarded.

## Parallel Opportunities

There are few, because almost every task edits `CLAUDE.md` or `docs/test-suite-performance.md` in sequence.

- T016 (the full run, about 5 min) can run in the background while T017–T021 are drafted, because §8.4 is written last.
- T023 (the design status line) touches a different file from T017–T021, and can be prepared at the same time. It is committed after T022 to keep the commit order in the plan.

## Implementation Strategy

1. **MVP**: Phases 1–3 (T001–T011). `CLAUDE.md` then holds the loop, which is the change that matters to developers and AI sessions.
2. **Exit check**: Phase 4 proves the loop works with a fresh session (G4).
3. **Close-out**: Phase 5 records the results and marks the design implemented. Phase 6 checks scope and attribution.
