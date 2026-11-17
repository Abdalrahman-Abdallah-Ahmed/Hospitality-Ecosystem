# Implementation Plan: Faster Full Parallel Run (Stage 1)

**Branch**: `030-parallel-run-speedup` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/030-parallel-run-speedup/spec.md`

## Summary

`composer test` becomes the full run: Composer's timeout off, config cleared, `php artisan test --parallel --processes=8`.

The two files over 75.6 s are split:

- `CrossTenantRouteTest` (218 s) becomes **four parts** sliced by URI segment: Reservations 50.6 s, Operations 47.8 s, GuestServices 61.6 s, Platform 57.2 s. The dataset builder moves to `tests/Pest.php` with a slice argument, and one coverage test proves every route lands in exactly one part.
- `PermissionAuthorizationTest` (79 s) becomes **two parts** of consecutive `it()` blocks: Resources 38.8 s, which keeps the permission dataset, and Actions 40.1 s.

After the splits:

- References are updated in `CLAUDE.md`, the `EntryPointInventoryTest` message and a `tests/Pest.php` comment.
- The two timing budgets are checked over 3 runs.
- The exit check (3 warm `composer test` runs) goes into stage-log §6, with G1 revised from the data if missed.

Per-part times come from the Stage 0 8-worker JUnit files ([research.md](research.md) R1, R4).

## Technical Context

**Language/Version**: PHP 8.4.16 CLI (Laragon, Windows); project targets PHP 8.3+.

**Primary Dependencies**: Pest 4 (datasets, parallel runner via ParaTest), Composer scripts, Laravel Pint.

**Storage**: Postgres 17 + pgvector in `hospitality-postgres`, testing DB `Hospitality_Ecosystem_testing` and its `_test_<n>` worker copies. No schema change.

**Testing**: The suite itself. One new coverage test. Validation follows [quickstart.md](quickstart.md).

**Target Platform**: The developer's Windows 11 machine, 12 logical CPUs, Docker Desktop.

**Project Type**: Laravel JSON API; this feature touches `tests/`, `composer.json`, `CLAUDE.md` and `docs/` only.

**Performance Goals**: No part over 75.6 s summed; full-run median below Stage 0's 302.4 s; G1 (210 s) checked, and revised if missed (expected floor about 280 s, R9).

**Constraints**:

- Spec FR-001–FR-005: scope, testing DB only, abort on a cached config, one commit per logical change after Pint, no co-author trailer.
- Moved helpers are never copied.
- `it()` texts are kept word for word.

**Scale/Scope**:

- 2 files become 6;
- 3 + 3 functions move to `tests/Pest.php`, and 2 new ones are added;
- 5 reference lines change;
- about 8–11 full runs (4 for the exit check, up to 4 more if a budget changes, 4 optional OPcache runs).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Status | Notes |
| --- | --- | --- |
| II. Preserve and Evolve | **Pass** | Tests are moved, not rewritten. Helpers move unchanged. The builder gains one optional argument with the same result when it is left out. |
| III. Tenant Isolation (NON-NEGOTIABLE) | **Pass** | Every cross-tenant row is still checked. The coverage test makes a dropped route fail, which is stronger than today's state, where nothing checks that the dataset is complete across files. |
| IV. Permission-Based Authorization | **Pass** | All permission tests are kept. `CLAUDE.md` points to the part that holds the dataset, so new permissions still get a row. |
| VIII. Tested at the Domain Boundary | **Pass** | Test count +1 (coverage), no test removed. Budget changes follow a recorded rule. |
| IX. Spec-Driven | **Pass** | Spec → plan → tasks. The split list is committed to the stage log before any test edit. |
| Secrets / data safety (CLAUDE.md, memories) | **Pass** | Pre-flight checks the config cache and the testing DB. Dev DB counts are taken before and after. The shared container's settings are untouched (OPcache is a local `php.ini` change). |

No violations. Complexity Tracking is empty.

**Post-design re-check**: unchanged. One point was checked against Principle III: the coverage test lists explicit segments, so a new route prefix fails loudly instead of going unchecked (R1).

## Project Structure

### Documentation (this feature)

```text
specs/030-parallel-run-speedup/
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R11
├── data-model.md        # Phase 1: slice map, rows, parts, runs, budget rule
├── quickstart.md        # Phase 1: pre-flight, validation, exit check
├── contracts/
│   ├── cross-tenant-builder.md   # crossTenantRoutesFor / Slices / Segment
│   └── stage-log-section-6.md    # Required content of §6
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks)
```

### Source Code (repository root)

```text
composer.json                                         # test script → 3 entries (R8)
CLAUDE.md                                             # lines 99, 168, 191 (R6)
docs/test-suite-performance.md                        # §6 (6.1 before any test edit)
tests/Pest.php                                        # + moved helpers, slice map, segment fn, 4 imports; comment at 1615
tests/Feature/CrossTenantRouteTest.php                # deleted (git mv to the first part keeps history)
tests/Feature/CrossTenantRouteReservationsTest.php    # new
tests/Feature/CrossTenantRouteOperationsTest.php      # new, + coverage test
tests/Feature/CrossTenantRouteGuestServicesTest.php   # new
tests/Feature/CrossTenantRoutePlatformTest.php        # new
tests/Feature/PermissionAuthorizationTest.php         # deleted (git mv to Resources)
tests/Feature/PermissionAuthorizationResourcesTest.php# new: lines 85–324 + availabilityProbeUrl
tests/Feature/PermissionAuthorizationActionsTest.php  # new: lines 326–656 + guestForPermissions
tests/Feature/EntryPointInventoryTest.php             # line 90 message text only
tests/Feature/RecommendationQueueTest.php             # line 110, only if R7's rule triggers
tests/Feature/StayListTest.php                        # line 147, only if R7's rule triggers
```

**Structure Decision**: Parts stay flat in `tests/Feature/`. Stage 2 moves files into module folders, and the cross-tenant parts are named by domain so that Stage 2 can place them.

### Commit order

Each commit is formatted with Pint and has the suite green where it changes tests.

1. `docs(030): add Stage 1 spec, plan and tasks`
2. `docs: record the Stage 1 split list` (§6.1)
3. `chore: run composer test in parallel at 8 workers without a timeout`
4. `refactor(tests): split CrossTenantRouteTest into four route-segment parts` (+ `tests/Pest.php`, coverage test, `EntryPointInventoryTest` message, `CLAUDE.md:168`)
5. `refactor(tests): split PermissionAuthorizationTest into Resources and Actions` (+ `tests/Pest.php`, `CLAUDE.md:99,191`)
6. `test: raise the parallel timing budgets to the observed load` (only if R7 triggers)
7. `docs: record Stage 1 results` (§6.2–6.5)

## Complexity Tracking

None.
