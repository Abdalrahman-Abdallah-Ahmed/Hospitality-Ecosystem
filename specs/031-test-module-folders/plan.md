# Implementation Plan: Module Test Folders and Cached-Config Guard (Stage 2)

**Branch**: `031-test-module-folders` (from `030-parallel-run-speedup` at `23b39bb`) | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/031-test-module-folders/spec.md`

## Summary

The 207 flat files in `tests/Feature/` move into 14 folders with one scripted `git mv` commit. That commit also carries the one-line `CrossTenantRouteTest` path fix.

**Guard.** `tests/Pest.php` gains a guard that exits with code 1 before anything boots when `bootstrap/cache/config.php` exists.

**Isolation.** Each folder is run alone by path. A static check found no helper, class or named dataset shared across files ([research.md](research.md) R2), so no helper move is expected.

**Path references.** Seven lines in `CLAUDE.md`, two API docs and the systemd README get their new paths.

**Stage log.** §7 records:

- the guard check;
- the isolation results;
- the serial medians per module for G2, with Q2 at 4 processes if Reservations misses 180 s (estimated about 190 s serial, R6);
- the 3,598-test full run.

## Technical Context

**Language/Version**: PHP 8.4.16 CLI (Laragon, Windows); project targets PHP 8.3+.

**Primary Dependencies**: Pest 4 (path runs, `--parallel` via ParaTest), Laravel Pint, git.

**Storage**: Postgres 17 + pgvector in `hospitality-postgres`, testing DB `Hospitality_Ecosystem_testing` (and its `_test_<n>` copies for Q2/full runs). No schema change.

**Testing**: The suite itself; no new test. Validation follows [quickstart.md](quickstart.md).

**Target Platform**: The developer's Windows 11 machine, 12 logical CPUs, Docker Desktop.

**Project Type**: Laravel JSON API; this feature touches `tests/`, `docs/`, path text in `CLAUDE.md` and `deploy/systemd/README.md`.

**Performance Goals**: G2 — slowest module (expected Reservations) warm serial median under 180 s; Q2 fallback at 4 processes. G3 — 3,598 tests.

**Constraints**: Spec FR-001–FR-004 (scope, testing DB only, stop on a cached config except during the guard check, one commit per logical change after Pint, no co-author trailer). Move commit holds renames only plus FR-009.

**Scale/Scope**:

- 207 renames, 1 line in `CrossTenantRouteTest`, and about 12 lines in `tests/Pest.php`;
- 7 reference lines;
- about 52 serial module runs (13 modules × 4), 1 Invariants run and 1 two-path run;
- 2 or 3 full runs, plus 4 Q2 runs if needed.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Status | Notes |
| --- | --- | --- |
| II. Preserve and Evolve | **Pass** | Files are moved, not rewritten. Their history follows the rename, and the only content change is one path. |
| III. Tenant Isolation (NON-NEGOTIABLE) | **Pass** | Every isolation test is kept, in `Invariants/`. `EntryPointInventoryTest` still searches subfolders (R3), and the count stays at 3,598. |
| IV. Permission-Based Authorization | **Pass** | `CLAUDE.md` still sends a new permission to the dataset, at its new path. |
| VIII. Tested at the Domain Boundary | **Pass** | No test is added or removed. The folders make module-level runs possible. |
| IX. Spec-Driven | **Pass** | Spec → plan → tasks. The move list is fixed before the move. |
| Secrets / data safety (CLAUDE.md, memories) | **Pass** | The guard closes the cached-config path to the dev DB for every test command. The guard check runs only commands that exit before connecting, or that clear the cache first (R8). The dev DB counts are checked before and after. The shared container is untouched. |

There are no violations, and Complexity Tracking is empty.

**Post-design re-check**: unchanged. The guard message holds no settings values (contract).

## Project Structure

### Documentation (this feature)

```text
specs/031-test-module-folders/
├── plan.md              # This file
├── research.md          # Phase 0: R1–R8
├── data-model.md        # Phase 1: folders, runs, guard check, DB snapshot
├── quickstart.md        # Phase 1: pre-flight, validation, exit
├── contracts/
│   ├── cached-config-guard.md
│   └── stage-log-section-7.md
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks)
```

### Source Code (repository root)

```text
tests/Feature/<14 folders>/*.php       # 207 renames (R1)
tests/Feature/Invariants/CrossTenantRouteTest.php   # line 52: dirname(__DIR__, 3)
tests/Pest.php                          # guard after the use imports (before line 108)
CLAUDE.md                               # lines 99, 188, 191 — path text only
docs/usage-metering-api-documentation.md        # line 223
docs/ai-cost-attribution-api-documentation.md   # lines 134, 334
deploy/systemd/README.md                # line 20
docs/test-suite-performance.md          # §7
```

**Structure Decision**: These are the folders in the design's Technical Plan table. `Superadmin/` keeps its 12 files and gains one. `tests/Unit/` is unchanged.

### Commit order

Each commit is formatted with Pint, and the suite is green after each one.

1. `docs(031): add Stage 2 spec, plan and tasks`
2. `refactor(tests): move feature tests into module folders` (renames plus the `CrossTenantRouteTest` line)
3. `test: refuse to run with a cached config`
4. `refactor(tests): move <helper> to Pest.php`, only if the isolation check needs it
5. `docs: point test paths at the module folders`
6. `docs: record Stage 2 results` (§7)

**Order of the checks**:

- The guard check runs after commit 3.
- The isolation and G2 runs run after commit 3 (and after 4, if there is one), so that the guard protects every run.
- The final `composer test` (G3) runs after commit 5.

## Complexity Tracking

None.
