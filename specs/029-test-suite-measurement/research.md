# Research: Measure the Test Suite (Stage 0)

Facts below were checked on 2026-11-15 on the developer's machine.

## R1 — The current default is 12 workers, not 16

- **Decision**: The baseline is `php artisan test --parallel` with no `--processes`, which runs **12** workers on this machine. The sweep runs it as `--processes=12`, and the same 4 runs serve as the baseline and the sweep's 12 row. The 16 row stays in the sweep.
- **Evidence**:
  - ParaTest's `Options::fromConsoleInput()` sets `processes` to `getNumberOfCPUCores()` when none is given (`vendor/brianium/paratest/src/Options.php:167-169`).
  - The machine reports 12 logical CPUs (`nproc`, `NUMBER_OF_PROCESSORS`).
  - The testing DB's worker copies stop at `Hospitality_Ecosystem_testing_test_12`; a 16-worker run would have left `_test_13`…`_test_16`.
- **Effect on the design**: "current 16 workers" (Stage 0) and "the default 16 workers" (The Problem) should read 12. The 46% idle estimate becomes 12 × 403 = 4,836 worker-seconds against 3,483 s of test time, so about 28% idle. The stage log records the correction; the design doc is fixed in its own change, not in Stage 0.
- **Alternatives considered**: Keep 16 as the baseline as the request says. Rejected: the baseline's purpose is "what runs today", and that is 12.

## R2 — Wall time

- **Decision**: Wall time is measured by the run wrapper as the time from just before `php artisan test` starts to its exit (seconds, 1 decimal), recorded in `runs.csv`. Pest's own reported duration is recorded next to it, not used for medians.
- **Rationale**: The wrapper time is what a developer waits for, and includes worker start-up and the per-worker migrations, which are part of the problem the design names.
- **Alternatives**: Pest's duration only. Rejected: it may leave out start-up.

## R3 — Reading the JUnit files

- **Decision**: Pest merges each worker's JUnit into the single `--log-junit` file (`WrapperRunner::$junitFiles`). The script groups `<testcase>` elements by the test file path (the `file` attribute of the testcase or its enclosing file-level `<testsuite>`), counts each dataset case as one test, and sums `time`.
- **Rationale**: The 3,597 count includes dataset cases; counting `<testcase>` elements matches it.
- **Check**: A smoke run on one small file (`tests/Unit/ExampleTest.php`) before the sweep confirms the attribute layout. It is not a measurement and its output is deleted.

## R4 — Medians and which runs feed which table

- **Decision**:
  - Sweep table: median of the 3 warm wall times per worker count.
  - Per-file, per-folder and invariant tables: each file's summed time is computed per run; the reported value is the median across the 3 warm **12-worker** runs. Folder and invariant sums are sums of those per-file medians.
  - Split list: per-file medians from the 3 warm runs at the **chosen** count, compared with 25% of that count's median wall time.
  - G2 estimate: the largest folder sum plus the invariant sum (12-worker tables), labelled an upper bound.
- **Alternatives**: Per-file tables for every count. Rejected: six copies of a 218-row table add noise to the log; the JUnit files stay on disk if needed later.

## R5 — Config cache

- **Decision**: Before the first run, if `bootstrap/cache/config.php` exists, stop and tell the developer; do not clear it to continue. Afterwards run `php artisan config:clear` before each run, as the method states.
- **Observed**: `bootstrap/cache/` holds only `packages.php`, `services.php` and two `.tmp` files. No `config.php`.

## R6 — Database target and dev DB safety

- **Decision**:
  - `phpunit.xml` forces `DB_DATABASE=Hospitality_Ecosystem_testing` with `force="true"` plus a `<server>` entry, which wins over terminal-leaked `.env` values.
  - Before and after the whole measurement, take a read-only fingerprint of the dev DB (`Hospitality_Ecosystem`): number of tables, row count of `migrations` and `users`. The two must match (SC-007).
  - Run commands with no env prefix, as the project memory requires.
- **Observation, no action**: the server also holds `Hospitality_Ecosystem_test_1`…`_test_12`, worker copies named after the **dev** DB, left by an earlier run that targeted it (the incident the env-leak memory records). Stage 0 does not drop or touch them; the developer may clean them up separately.

## R7 — Postgres connections

- **Decision**: `max_connections` is 100 (read with `SHOW max_connections` on `hospitality-postgres`). At the check, 6 connections were in use by others. Each worker holds one connection to its own DB, and the parent briefly connects to create worker DBs, so 16 workers need about 17–20. Every swept count fits; no count is skipped.
- **Constraint**: the container's settings (`max_connections`, `max_locks_per_transaction=256`) are read, never changed.

## R8 — PHP extensions

- **Decision**: Record as found:
  - **Xdebug**: not loaded (`php -m`).
  - **OPcache**: the extension is not loaded, and `opcache.enable_cli` is off (`ini_get` returns false). Loaded ini: `C:\laragon\bin\php\php-8.4.16-Win32-vs17-x64\php.ini`.
- **Rationale**: Turning OPcache on for the CLI is a machine setting outside the repository, and Stage 0 changes nothing. The stage log lists it as a candidate for Stage 1 to measure.

## R9 — Run order and warm runs

- **Decision**: Run each count as one block of 4 back-to-back runs: 12 first (the baseline), then 4, 6, 8, 10, 16. Run 1 of each block is cold and discarded. If the gap before a run exceeds 5 minutes, or Postgres restarts, that run becomes a new cold run and the block continues until it has 3 warm runs.
- **Rationale**: Matches the design's warm-run definition. Going to 16 last means `_test_13`…`_test_16` are created only once, in its cold run.
- **Alternatives**: Interleaving counts to spread out drift. Rejected: every run would then be cold.

## R10 — Failed runs

- **Decision**: A run that exits non-zero, or whose test count differs from the block's others without a known reason, is excluded and replaced by another run in the same block. The stage log lists each excluded run with its reason and the failing tests.
