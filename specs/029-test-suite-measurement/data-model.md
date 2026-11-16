# Data Model: Measure the Test Suite (Stage 0)

No database or application model changes. These are the records the measurement produces and the summary script reads.

## Run (one row of `runs.csv`, scratchpad)

| Field | Type | Rule |
| --- | --- | --- |
| `label` | string | `w<N>-r<k>`, e.g. `w12-r1`; the JUnit file is `storage/test-reports/<label>.xml` |
| `workers` | int | one of 4, 6, 8, 10, 12, 16 |
| `run` | int | 1-based order within its block, counting replacements |
| `kind` | enum | `cold` \| `warm` \| `excluded` |
| `started_at`, `ended_at` | timestamp | local time; the gap from the previous run's `ended_at` decides `cold` (> 5 min) |
| `wall_s` | decimal | wrapper-measured, 1 decimal (research R2) |
| `pest_duration_s` | decimal | as Pest reports it; informational |
| `exit_code` | int | 0 required for `warm` |
| `tests` | int | `<testcase>` count from the JUnit file |
| `note` | string | reason for `excluded`, or restart/gap note |

**State**: a run starts `cold` (first of block, or after a gap/restart) or `warm`. A warm run that fails or has a stray test count becomes `excluded`. A block is complete with exactly 3 `warm` rows.

## JUnit report (`storage/test-reports/<label>.xml`, ignored)

Read only. Each `<testcase>` gives: test file path, name, `time` (s). One dataset case = one testcase.

## Folder map (`folders.json`, scratchpad)

`{ "<TestFileBaseName>": "<Folder>" }`, built from the design's move table (Detailed Implementation Plan, item 8):

- 21 flat files → `Invariants`; the other 185 flat files → one of the 13 module folders (`SuspensionAdministrationTest` → `Superadmin`).
- The 12 files already in `tests/Feature/Superadmin/` → `Superadmin`.
- `tests/Unit/ExampleTest.php` → `Unit` (not a module; reported on its own row).
- Any test file not in the map → `UNMAPPED` (FR-013).

## Derived figures (computed by the script, written to the stage log)

| Figure | Definition |
| --- | --- |
| Run summed time | Σ testcase `time` in one run |
| File summed time | Σ testcase `time` of one file in one run; reported as the median across 3 warm runs |
| Folder count / time | Σ test counts / Σ file medians for files mapped to the folder |
| Invariants time | the `Invariants` folder's time |
| Sweep median | median of 3 warm `wall_s` per worker count |
| Chosen count | lowest `workers` with sweep median ≤ 1.05 × min(sweep medians) |
| Split list | files whose median summed time at the chosen count > 0.25 × chosen count's sweep median |
| G2 estimate | max(module folder time) + Invariants time; compared with 180 s; marked upper bound |

**Validation**: Σ folder counts = total tests per run; each warm run's test count equals the others in its block; the 12-worker test count is compared with 3,597.
