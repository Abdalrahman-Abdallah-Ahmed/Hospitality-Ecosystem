# Contract: `docs/test-suite-performance.md` (the stage log)

The stage log is the record Stages 1–3 append to. Stage 0 creates it with the sections below, in this order. Every table is filled; nothing is left as a placeholder (SC-001).

## 1. Purpose

One paragraph: what the file is, that each stage appends its exit check, and a link to the design.

## 2. Method

- **Machine**: OS, logical CPU count, PHP version and build, Postgres version and where it runs (Docker, shared container — named, settings untouched).
- **Warm run**: the design's definition, quoted, plus the discard rule (first run per count).
- **Commands**, exactly:

  ```text
  php artisan config:clear
  php artisan test --parallel --processes=<N> --log-junit=storage/test-reports/<label>.xml
  ```

- **Wall time**: how it was measured (research R2).
- **Summed time**: Σ JUnit testcase `time`; excludes start-up and migrations.
- **Note**: the default worker count is 12 on this machine (CPU cores), not 16 as the design states; the baseline is the 12-worker row.

## 3. Pre-Stage-0 reference

| Date | Workers | Wall | Summed | Tests | Runs |
| --- | --- | --- | --- | --- | --- |
| 2026-11-14 | default (12; recorded as 16) | 403 s | 3,483 s | 3,597 | 1 |

## 4. Stage 0 results

### 4.1 Baseline (12 workers)

| Run | Wall (s) | Summed (s) | Tests | Kind |
| --- | --- | --- | --- | --- |

Then: median wall, median summed, test count, difference from 3,597 with its reason (G3).

### 4.2 Worker sweep

| Workers | Warm 1 | Warm 2 | Warm 3 | Median (s) | vs fastest |
| --- | --- | --- | --- | --- | --- |

Below the table: excluded runs (label, reason). Then **Chosen count: N** — the lowest count within 5% of the fastest median.

### 4.3 Files (12 workers, median of 3)

All test files, slowest first:

| File | Folder | Tests | Summed (s) |
| --- | --- | --- | --- |

### 4.4 Planned folders (12 workers)

| Folder | Files | Tests | Summed (s) |
| --- | --- | --- | --- |

Includes every module folder, `Invariants`, `Unit`, and `UNMAPPED` (shown even if empty). A totals row matches §4.1.

### 4.5 Invariant tests

The 21 files' summed time on one line, with the per-file values already in §4.3.

### 4.6 Checks

| Check | Observed | Answer |
| --- | --- | --- |
| Xdebug loaded | … | yes / no |
| `opcache.enable_cli` | … | on / off |
| Postgres `max_connections` vs 16 workers | … | enough / not enough |

## 5. Stage 0 exit check

- **Chosen worker count**: N (median X s; fastest M at Y s).
- **Files over 25% of N's wall time** (threshold T s): list, or "none".
- **G2 estimate**: slowest module `<Folder>` A s + invariants B s = C s, against 180 s; upper bound because times come from parallel runs.
- **G1 outlook**: chosen count's median against 210 s (for Q1; decided in Stage 1).
- Every figure in §3–§4 is present: yes.
