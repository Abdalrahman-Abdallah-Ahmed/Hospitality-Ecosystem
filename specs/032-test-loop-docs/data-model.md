# Data Model: Test Loop in CLAUDE.md (Stage 3)

There is no database or schema change. The "entities" are the document parts this stage changes or records.

## CLAUDE.md change site

| Site | Found by (content) | Change |
| --- | --- | --- |
| Commands block | the line starting `php artisan test` and containing `# or: php artisan test --filter=SomeTest` | Replace with the 4 lines in [contracts/claude-md-changes.md](contracts/claude-md-changes.md) §1 |
| Testing folder line | `Pest, feature tests in` | Rewrite (§2) |
| Testing table and rules | inserted after the folder line | Add (§2) |
| Shared fixtures | `Shared fixtures go in file-local helper functions` | **Unchanged** |
| Stage 2 paths | `tests/Feature/Invariants/TenantIsolationTest.php`, `tests/Feature/Invariants/PermissionAuthorizationResourcesTest.php` (twice) | **Unchanged** |
| Git pre-commit line | ``Run `./vendor/bin/pint` and `php artisan test` before proposing a commit`` | Replace with 5 rules (§3) |

**Rule**: after the change, `grep -n "php artisan test" CLAUDE.md` matches only the three lines in the Commands block (the module run, the run over several modules, `--filter`) and rule e's `php artisan config:clear` does not match. No other line says to run plain `php artisan test`.

## Goal record (§8 table)

| Field | Values |
| --- | --- |
| Goal | G1, G2, G3, G4 |
| Target | the design's target, and the revised target where there is one |
| Result | the measured figure, with its § reference |
| Verdict | `met`, `met (Q2)`, `revised`, or `not met` |

## Exit-check record (§8)

| Field | Values |
| --- | --- |
| Date, branch, commit | the commit of `CLAUDE.md` the session read |
| Task | the text given, word for word |
| Ran module command after edit | yes / no, and the command it ran |
| Ran `composer test` before proposing a commit | yes / no |
| Test placed in `tests/Feature/Tasks/` | yes / no, and the path |
| Change discarded | yes, and `git status` shows nothing under `tests/` or `app/` |
| Attempt | 1, or 2 if the wording was tightened |

**Rule**: G4 is `met` only if all three observations are yes.
