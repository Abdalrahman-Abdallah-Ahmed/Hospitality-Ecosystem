# Contract: `docs/test-suite-performance.md` §7 (Stage 2)

§7 is added after §6, under the heading `## 7. Stage 2 — module folders and the guard (<date>)`. It has these subsections, in this order.

## 7.1 Move

- The branch and its base (`030-parallel-run-speedup`, `23b39bb`).
- A table with one row per folder: files moved (and the existing count for `Superadmin`).
- Files assigned outside the design table: "none", or each file with its folder and the reason.
- The `CrossTenantRouteTest` path change, and the grep showing that no other test resolves a path from `__DIR__` under `tests/Feature`.

## 7.2 Guard

The steps from data-model "Guard check", each with what was observed: the exit code, whether the message appeared, and whether any database was reached.

## 7.3 Isolation

- A table with one row per folder (14 rows): the test count, and pass/fail.
- Helpers moved to `tests/Pest.php`, with the folder that needed them, or "none".
- Any other cause of a failure.
- The two-path check: the command, the count, and whether it equals the sum of the two folders.

## 7.4 G2

- A table with one row per module (13 rows): warm 1–3 (s), the median (s), and the tests.
- The slowest module.
- `Invariants` alone: one time, given for reference.
- Q2: "not needed", or the parallel runs table and their median.
- **G2**: met, or a revised value with the reason.

## 7.5 G3 and the full run

- The `composer test` result: the tests, the wall time, and pass/fail.
- The sum of the folder counts plus `tests/Unit`, compared with it. The total is 3,598.
- `./vendor/bin/pint --test`: clean.

## 7.6 Paths and safety

- The living documents changed, by line.
- The historical files left unchanged.
- `QueueLaneTest` result.
- Dev database counts before and after.

## Rules

- When Stage 2 closes, no placeholder is left.
- Times come from timed shell clocks, and counts from Pest's summary line.
- No credentials appear, and no database settings beyond the database name.
