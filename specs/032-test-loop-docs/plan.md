# Implementation Plan: Test Loop in CLAUDE.md (Stage 3)

**Branch**: `032-test-loop-docs` (from `031-test-module-folders` at `42decef`) | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/032-test-loop-docs/spec.md`

## Summary

A documentation-only stage. `CLAUDE.md` gets the test loop measured in Stage 2:

- **Commands block:** the plain `php artisan test` line becomes four lines: the module run at 4 processes, the serial run over several folders, `composer test`, and `--filter`.
- **Testing section:** the folder line is rewritten, and the 14-folder table and rules a–f are added. The shared-fixtures lines and the Stage 2 paths are not changed.
- **Git section:** the pre-commit line becomes five rules that name `composer test`, matching the Commands block.

The stage log gains §8, with the diff summary, the G1–G4 table and the manual exit check. The design doc's Status line becomes "Implemented (Stages 0–3)" and links to the stage log. No code or test changes, so no timed runs. One `composer test` before the last commit confirms nothing broke.

## Technical Context

**Language/Version**: Markdown only. PHP 8.4.16 CLI runs Pint and the confirming test run.

**Primary Dependencies**: git, Laravel Pint (`--test` only, no PHP changes), Pest via `composer test`.

**Storage**: None. The one `composer test` uses the testing DB on `hospitality-postgres`, as before.

**Testing**: There is no new test. The checks are:
- the content checks in [quickstart.md](quickstart.md), which grep `CLAUDE.md`;
- one `composer test`, expecting 3,598 passed;
- the manual exit check ([contracts/stage-log-section-8.md](contracts/stage-log-section-8.md)).

**Target Platform**: The developer's Windows 11 machine.

**Project Type**: Laravel JSON API. This feature touches only the docs listed in spec FR-001.

**Performance Goals**: None new. G1–G3 figures are taken from the stage log (§6.4, §7.4, §7.5), not re-measured.

**Constraints**:
- Spec FR-001, FR-002 and FR-018: the scope is fixed, places are found by content, and each logical change is its own commit with no co-author trailer.
- `CLAUDE.md`'s Git section forbids attribution lines. That rule wins over the session's default trailer.

**Scale/Scope**:
- About 40 changed or added lines in `CLAUDE.md`.
- About 60 lines in §8.
- 1 line in the design doc.
- 1 full run, plus the manual exit-check session.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Status | Notes |
| --- | --- | --- |
| II. Preserve and Evolve | **Pass** | Only the named lines in `CLAUDE.md` change. All other rules, including the tenancy, permission and Git rules, stay word for word (FR-002). |
| III. Tenant Isolation (NON-NEGOTIABLE) | **Pass** | `Invariants/` is still in every full run. The new Git rule forbids editing an invariant test only to make it pass. The module run leaves the invariants out, but `composer test` before every commit runs them. |
| IV. Permission-Based Authorization | **Pass** | The permission rules and the dataset path at `tests/Feature/Invariants/PermissionAuthorizationResourcesTest.php` are unchanged. |
| VIII. Tested at the Domain Boundary | **Pass** | No test is added or removed. The written loop makes the module runs actually get used. |
| IX. Spec-Driven | **Pass** | Spec → plan → tasks before any edit to `CLAUDE.md`. |
| Documentation (Workflow) | **Pass** | Only the developer instructions and the stage log change. No API change, so no `docs/<resource>-api-documentation.md` change. |
| Secrets (CLAUDE.md) | **Pass** | No settings values are written. The cached-config rule tells the reader to clear the cache and never to delete the guard. |

There are no violations, and Complexity Tracking is empty.

**Post-design re-check**: unchanged. The contracts copy rules a–f and the Git rules word for word from the spec. Nothing in them weakens an existing rule.

## Project Structure

### Documentation (this feature)

```text
specs/032-test-loop-docs/
├── plan.md              # This file
├── research.md          # Phase 0: R1–R6
├── data-model.md        # Phase 1: CLAUDE.md change sites, G table, exit-check record
├── quickstart.md        # Phase 1: content checks, full run, exit check
├── contracts/
│   ├── claude-md-changes.md
│   └── stage-log-section-8.md
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks)
```

### Files changed (repository root)

```text
CLAUDE.md                                        # Commands block, Testing section, Git section (contract)
docs/test-suite-performance.md                   # new §8
docs/designs/test-suite-speed-and-module-runs.md # Status line only
.specify/feature.json                            # points at specs/032-test-loop-docs
```

**Structure Decision**: The folder table goes in the Testing section, after the folder line and before the existing fixture lines. Rules a–f follow it under a short "Test loop" lead-in, so the existing bullets keep their order (R3).

### Commit order

Each commit is checked with `./vendor/bin/pint --test` and has no co-author trailer.

1. `docs(032): add Stage 3 spec, plan and tasks`
2. `docs: write the module test loop into CLAUDE.md`
3. Exit check (manual, nothing committed; the session's change is discarded). Run `composer test` once on the branch to confirm 3,598 passed.
4. `docs: record Stage 3 results` (§8)
5. `docs: mark the test-suite design implemented`

If the exit check shows a gap in the wording, a fix commit `docs: tighten the test loop in CLAUDE.md` goes between steps 3 and 4. The check is then repeated, and both attempts are recorded (spec Edge Cases).

## Complexity Tracking

None.
