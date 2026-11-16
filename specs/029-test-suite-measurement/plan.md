# Implementation Plan: Measure the Test Suite (Stage 0)

**Branch**: `029-test-suite-measurement` | **Date**: 2026-11-15 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/029-test-suite-measurement/spec.md`

## Summary

Run the full test suite 24 times: 4 runs (1 cold, 3 warm) at each of 4, 6, 8, 10, 12 and 16 workers, writing JUnit to `storage/test-reports/`. A summary script kept in the scratchpad reads those files and produces the per-file, per-module and invariant tables plus the three exit-check answers. Results, the method and three environment checks go into a new `docs/test-suite-performance.md`, and `/storage/test-reports/` is added to `.gitignore`. Nothing else in the repository changes.

The baseline is the **current default** run, which is 12 workers, not the design's 16 ([research.md](research.md) R1).

## Technical Context

**Language/Version**: PHP 8.4.16 CLI (Laragon, ZTS, Windows); project targets PHP 8.3+. Summary script: Python 3 (stdlib only), run from the scratchpad.

**Primary Dependencies**: Pest 4 parallel runner (ParaTest), `--log-junit` (Pest merges each worker's JUnit into one file).

**Storage**: Postgres 17 + pgvector in the `hospitality-postgres` container (shares its volume with another system's `hospitality-postgres`). Testing DB `Hospitality_Ecosystem_testing` and its per-worker copies `Hospitality_Ecosystem_testing_test_<n>`. Read-only queries only (`SHOW max_connections`).

**Testing**: The feature *is* a measurement; no new tests. Validation is the quickstart's exit check.

**Target Platform**: The developer's Windows 11 machine, 12 logical CPUs, Docker Desktop.

**Project Type**: Laravel JSON API (web service); this feature touches docs and `.gitignore` only.

**Performance Goals**: None for the feature itself. It records figures against design goals G1 (full run < 210 s) and G2 (slowest module + invariants < 180 s).

**Constraints**: Config not cached; testing DB only (dev DB and container settings untouched); runs back-to-back within 5 minutes; no other heavy load on the machine during runs.

**Scale/Scope**: 218 test files, 3,597 test cases, 24 full runs (~2.5–4 h wall in total, estimated from 403 s at 12 workers and slower at 4).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Status | Notes |
| --- | --- | --- |
| II. Preserve and Evolve | **Pass** | No code, test or config changes. |
| III. Tenant Isolation | **Pass** | Not touched. No test is changed or skipped. |
| VIII. Tested at the Domain Boundary | **Pass** | The full suite runs unchanged 24 times; a failing run is replaced, never ignored, and the failure is named in the stage log. |
| IX. Spec-Driven | **Pass** | Spec → plan → tasks before any run. |
| Secrets (CLAUDE.md) | **Pass** | The stage log records DB names and settings, never credentials. The script reads JUnit only. |
| Data safety (memories: env leak, no `.env.testing`) | **Pass** | Pre-flight confirms the testing DB is the target and the config cache is absent (FR-001, FR-002). |

No violations. Complexity Tracking is empty.

**Post-design re-check**: unchanged. The design adds two repository files and a scratchpad script.

## Project Structure

### Documentation (this feature)

```text
specs/029-test-suite-measurement/
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R8
├── data-model.md        # Phase 1: run, report, folder map, derived figures
├── quickstart.md        # Phase 1: pre-flight, run order, exit check
├── contracts/
│   ├── stage-log.md     # Required sections and tables of docs/test-suite-performance.md
│   └── summary-script.md# Input/output of the scratchpad summary script
└── tasks.md             # Phase 2 (/speckit-tasks)
```

### Source Code (repository root)

```text
.gitignore                         # + /storage/test-reports/
docs/test-suite-performance.md     # new: the stage log
storage/test-reports/              # JUnit output, ignored, not committed
```

Outside the repository (scratchpad, not committed):

```text
<scratchpad>/stage0/
├── summarize.py         # JUnit → tables (see contracts/summary-script.md)
├── folders.json         # move table (design item 8) as file → folder
└── runs.csv             # label, workers, run#, cold/warm, start, end, wall_s, exit code
```

**Structure Decision**: Docs-only change in the repository. All tooling lives in the scratchpad, as the spec requires (FR-005).

## Complexity Tracking

None.
