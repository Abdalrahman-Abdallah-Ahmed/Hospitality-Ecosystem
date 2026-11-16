---

description: "Task list for Stage 0: measure the test suite"
---

# Tasks: Measure the Test Suite (Stage 0)

**Input**: Design documents from `specs/029-test-suite-measurement/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: No new tests. The feature is a measurement; each run executes the whole existing suite, and validation is the exit check in [quickstart.md](quickstart.md) §4.

**Organization**: Tasks are grouped by user story. The runs themselves can never be parallel: they need the machine to themselves, and only one runs at a time. `[P]` marks only writing tasks on different files.

**Scratchpad**: `<scratchpad>` means the scratchpad directory of the session that does the work; tooling goes in `<scratchpad>/stage0/` and is never committed (FR-005).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US4)

---

## Phase 1: Setup

**Purpose**: Branch, ignore rule, and the safety checks that must pass before any run.

- [X] T001 Create branch `029-test-suite-measurement` from `docs/test-suite-speed-design` (the design commit `c8ba629` is not on `main` yet)
- [X] T002 Add the line `/storage/test-reports/` to `.gitignore` (next to `/.phpunit.cache`, line 13), and create the empty folder `storage/test-reports/` (FR-004)
- [X] T003 Pre-flight stop check: confirm `bootstrap/cache/config.php` does **not** exist. If it does, stop and tell the developer; do not clear it to continue (FR-001, research R5)
- [X] T004 Pre-flight: confirm `docker ps` shows `hospitality-postgres` running; never run `docker compose up` (it replaces the shared container), and never change its settings (FR-002)
- [X] T005 Pre-flight: confirm `phpunit.xml` forces `DB_DATABASE=Hospitality_Ecosystem_testing` as both `<env ... force="true">` and `<server>` (research R6). Commands run with no env-var prefix
- [X] T006 Take the dev DB fingerprint (before), read-only on `Hospitality_Ecosystem` via `docker exec hospitality-postgres psql -U root -d Hospitality_Ecosystem -tAc ...`: public table count, `select count(*) from migrations`, `select count(*) from users`. Save to `<scratchpad>/stage0/dev-fingerprint-before.txt`. Do not touch the leftover `Hospitality_Ecosystem_test_1`…`_12` DBs (research R6)

**Checkpoint**: Safe to run tests against the testing DB only.

---

## Phase 2: Foundational (blocks every story)

**Purpose**: Run log, folder map and summary script, proved on a smoke run.

- [X] T007 [P] Create `<scratchpad>/stage0/runs.csv` with header `label,workers,run,kind,started_at,ended_at,wall_s,pest_duration_s,exit_code,tests,note`. Rules from data-model.md: `label` is "`w<N>-r<k>`, e.g. `w12-r1`"; `kind` is "`cold` | `warm` | `excluded`"; "0 required for `warm`"; a run is cold if it is first in its block or the gap from the previous `ended_at` is "> 5 min"
- [X] T008 [P] Create `<scratchpad>/stage0/folders.json` mapping each test file base name to its folder, from the move table in `docs/designs/test-suite-speed-and-module-runs.md` (Detailed Implementation Plan, item 8): 21 → `Invariants`; 185 flat files → their module folder (`SuspensionAdministrationTest` → `Superadmin`); the 12 files in `tests/Feature/Superadmin/` → `Superadmin`; `tests/Unit/ExampleTest.php` → `Unit`. Verify every test file under `tests/` (206 flat + 12 `Superadmin/` + 1 `Unit/` = 219) is mapped exactly once, and list any that are not
- [X] T009 Write `<scratchpad>/stage0/run.sh`: for a given `N` and `k`, run `php artisan config:clear` then time `php artisan test --parallel --processes=<N> --log-junit=storage/test-reports/w<N>-r<k>.xml` (wall from just before start to exit, 1 decimal), capture exit code and Pest's reported duration, and append a row to `runs.csv` with `kind` set by the gap rule (research R2, R9). Abort if `bootstrap/cache/config.php` reappears
- [X] T010 Write `<scratchpad>/stage0/summarize.py` (Python 3 stdlib, run with `python -I`) per [contracts/summary-script.md](contracts/summary-script.md): group `<testcase>` by test file path, one dataset case = one test, sum `time`; per-file median of 3 warm runs; folder sums; sweep medians; chosen count; split list; G2 estimate; the failure rules (fewer than 3 warm runs, missing/unparsable file, disagreeing counts, folder counts not adding up → non-zero exit). Output Markdown tables in stage-log order
- [X] T011 Smoke test (not a measurement): `php artisan test --parallel --processes=2 --log-junit=storage/test-reports/smoke.xml tests/Unit/ExampleTest.php`; confirm the JUnit layout gives a file path per testcase and `summarize.py` reads it (research R3). Delete `smoke.xml` and remove its row from `runs.csv` afterwards

**Checkpoint**: Tooling proven; measurement can start.

---

## Phase 3: User Story 1 — Recorded baseline of the full run (Priority: P1) 🎯 MVP

**Goal**: 3 warm runs at the current default (12 workers) with their median, summed time and test count, next to the 2026-11-14 reference.

**Independent Test**: The stage log's §4.1 has 3 warm 12-worker runs, their medians, and the count compared with 3,597.

- [X] T012 [US1] Run the 12-worker block with `run.sh 12 1` … `run.sh 12 4` back to back; run 1 is `cold`. If a run fails, has a stray test count, or follows a > 5 min gap or a Postgres restart, mark it per research R9/R10 and run another (`k=5`, …) until there are exactly 3 `warm` runs. Record failing test names in `note`
- [X] T013 [US1] Run `summarize.py` for the baseline section only; confirm the 3 warm runs agree on test count; note the difference from 3,597 and find its reason if any (G3)
- [X] T014 [US1] Create `docs/test-suite-performance.md` with stage-log §1 Purpose, §2 Method (machine: Windows 11, 12 logical CPUs, PHP 8.4.16 ZTS from Laragon, Postgres 17 + pgvector in the shared `hospitality-postgres` Docker container; the design's warm-run definition quoted; the exact commands; wall and summed time definitions; the 12-not-16 note), §3 Pre-Stage-0 reference (2026-11-14: 403 s wall, 3,483 s summed, 3,597 tests, 1 run, "default (12; recorded as 16)"), and §4.1 Baseline, per [contracts/stage-log.md](contracts/stage-log.md)

**Checkpoint**: The baseline exists and stands on its own.

---

## Phase 4: User Story 2 — Worker count chosen from data (Priority: P1)

**Goal**: The sweep table at 4, 6, 8, 10, 12, 16 workers and the named chosen count.

**Independent Test**: §4.2 has 6 rows × 3 warm runs with medians, and the named count is the lowest within 5% of the fastest.

- [X] T015 [US2] Run the 4-worker block (`run.sh 4 1`…`4`, replacing runs per T012's rules)
- [X] T016 [US2] Run the 6-worker block the same way
- [X] T017 [US2] Run the 8-worker block the same way
- [X] T018 [US2] Run the 10-worker block the same way
- [X] T019 [US2] Run the 16-worker block the same way (its cold run creates `_test_13`…`_test_16`)
- [X] T020 [US2] Run `summarize.py` for the sweep (the 12 row reuses T012's runs); check the chosen count by hand: lowest `workers` with median ≤ 1.05 × fastest median
- [X] T021 [US2] Add §4.2 Worker sweep to `docs/test-suite-performance.md`: the table, the excluded runs with reasons, and "Chosen count: N"

**Checkpoint**: Stage 1 has its worker count.

---

## Phase 5: User Story 3 — Where the time goes (Priority: P2)

**Goal**: Per-file, per-folder and invariant figures, the split list and the G2 estimate, from the saved JUnit files only (no extra runs).

**Independent Test**: Folder counts sum to the total; per-file sums match folder sums; the split list and G2 match their definitions in data-model.md.

**Depends on**: US1 (per-file and folder tables use the 12-worker runs) and US2 (split list uses the chosen count).

- [X] T022 [US3] Run `summarize.py` in full; it must exit 0. Check: Σ folder counts = total tests; `UNMAPPED` row present (even if empty)
- [X] T023 [US3] Add §4.3 Files (all files, slowest first: File, Folder, Tests, Summed), §4.4 Planned folders (every module folder, `Invariants`, `Unit`, `UNMAPPED`, totals row) and §4.5 Invariant tests (the 21 files' summed time) to `docs/test-suite-performance.md`
- [X] T024 [US3] Add the split list to §5: files whose median summed time at the chosen count > 0.25 × that count's median wall time, with the threshold in seconds, or "none"
- [X] T025 [US3] Add the G2 estimate to §5: slowest module folder + invariants = total, against 180 s, labelled an upper bound because the times come from parallel runs

**Checkpoint**: Stage 1 split decision and Stage 2 G2 outlook are recorded.

---

## Phase 6: User Story 4 — Environment checks recorded (Priority: P3)

**Goal**: Xdebug, OPcache and `max_connections` answers with observed values.

**Independent Test**: §4.6 shows three rows, each with an observed value and an answer.

**Independent of** the runs; can be done any time after Phase 1.

- [X] T026 [P] [US4] Re-read the three values at measurement time: `php -m` (Xdebug, Zend OPcache), `php -r "var_export(ini_get('opcache.enable_cli'));"`, `SHOW max_connections` and the current connection count on `hospitality-postgres` (read-only). Expected per research R7/R8: Xdebug not loaded; OPcache not loaded and `enable_cli` off; 100 connections, enough for 16 workers
- [X] T027 [US4] Add §4.6 Checks to `docs/test-suite-performance.md`, noting OPcache for the CLI as a Stage 1 candidate (a machine setting, not changed here)

---

## Phase 7: Polish & exit check

- [X] T028 Add the rest of §5 Stage 0 exit check to `docs/test-suite-performance.md`: chosen count with its median and the fastest; G1 outlook (chosen count's median against 210 s, for Q1); "Every figure in §3–§4 is present: yes"
- [X] T029 Take the dev DB fingerprint (after) the same way as T006 into `<scratchpad>/stage0/dev-fingerprint-after.txt`; it must equal the "before" file (SC-007). If not, stop and report
- [X] T030 Walk the [quickstart.md](quickstart.md) §4 exit check; confirm no placeholders remain in `docs/test-suite-performance.md` (SC-001)
- [X] T031 `git status`: only `docs/test-suite-performance.md` (new) and `.gitignore` (modified) are changed, and `storage/test-reports/` is not listed (SC-006). Ignore the unrelated untracked `storage/framework/lsp-*.php`
- [X] T032 Report results to the developer and propose the commit (`docs: add Stage 0 test suite measurements`) with only those two files; commit only when asked, with no co-author trailer (CLAUDE.md)

---

## Dependencies & Execution Order

- **Setup (T001–T006)** → **Foundational (T007–T011)** → stories.
- **US1 (T012–T014)** first: the 12-worker block is the baseline and the sweep's 12 row.
- **US2 (T015–T021)** after US1: blocks run one after another, never together.
- **US3 (T022–T025)** after US1 and US2: uses only the saved JUnit files.
- **US4 (T026–T027)**: after Setup, independent of the runs; best done just before or after them so the values are current.
- **Polish (T028–T032)** last.

## Parallel Opportunities

- T007 and T008 (different scratchpad files).
- T026 can be done alongside writing T014/T021 text, but **never during a run**, since it loads PHP and Postgres.
- No two test runs ever overlap.

## Implementation Strategy

- **MVP = US1**: a 3-run baseline in the stage log already replaces the single 2026-11-14 run. Stop there if the sweep must wait.
- Then US2 (≈ 20 more runs, the long part: plan 2–3 hours of machine time), US3 (script only, minutes), US4 (minutes).
- Total: 24 measured runs + 1 smoke run, about 2.5–4 hours.

## Notes

- Never edit tests, `app/`, `routes/`, `config/`, `database/`, `composer.json` or CI files (FR-003).
- Never copy credentials from `phpunit.xml` or `.env` into the stage log or scripts' output.
- The design doc's "16 workers" correction is a separate change, not part of this PR.
