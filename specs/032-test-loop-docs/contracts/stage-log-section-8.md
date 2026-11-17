# Contract: `docs/test-suite-performance.md` §8 (Stage 3)

§8 is added after §7.6, under `## 8. Stage 3 — CLAUDE.md (<date>)`, with the branch and its base (`031-test-module-folders`, `42decef`). §1–§7 do not change.

## 8.1 CLAUDE.md

A short list of what changed, with the commit hash:

- Commands block: `php artisan test` replaced by the module run (4 processes), the serial run over several folders, `composer test` and `--filter`. The 4 processes come from §7.4: Reservations takes 92 s at 4 processes and 225 s serially.
- Testing section: the folder line was rewritten, and the folder table and the six loop rules were added.
- Git section: the pre-commit line was replaced by the five rules.
- Unchanged: the shared-fixtures lines and the three Stage 2 paths.

## 8.2 Goals

| Goal | Target | Result | Verdict |
| --- | --- | --- | --- |
| G1 — Full run | 3 min 30 s; revised to 300 s (§6.4) | about 304–310 s (§7.5) | revised |
| G2 — After-edit run | slowest module under 180 s | `Reservations` 92 s at 4 processes (§7.4) | met (Q2) |
| G3 — Same tests | same count as the baseline | 3,598 (3,597 + 1 test added in Stage 1, §6.1) | met |
| G4 — Written loop | `CLAUDE.md` gives the loop | this stage (§8.1, §8.3) | met / not met |

Under the table, one line notes that G1's current runs sit a few seconds above the revised 300 s, for the reason given in §6.4 (the floor of summed work at 8 workers).

## 8.3 Exit check

The fields from data-model "Exit-check record", as a short list. If there was a second attempt, both attempts are listed, with the wording change between them. The section ends with the G4 verdict.

## 8.4 Checks

- `composer test` on the branch: the count (3,598) and the result.
- `./vendor/bin/pint --test`: clean.
- Scope: `git diff 42decef --stat` shows only `CLAUDE.md`, `docs/test-suite-performance.md`, the design doc, `.specify/feature.json` and `specs/032-test-loop-docs/`.
