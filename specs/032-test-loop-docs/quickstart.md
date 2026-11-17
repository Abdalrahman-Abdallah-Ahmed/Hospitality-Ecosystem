# Quickstart: validating Stage 3

## Pre-flight

- You are on branch `032-test-loop-docs`, and `git merge-base HEAD 031-test-module-folders` is `42decef`.
- `bootstrap/cache/config.php` does not exist. If it does, stop and tell the developer.
- `docker ps --filter name=hospitality-postgres` shows the container as Up. This is needed only for the full run and the exit check.

## Content checks (after the CLAUDE.md commit)

Run these from the repository root (Git Bash).

| Check | Command | Expected |
| --- | --- | --- |
| Commands block | `grep -n "php artisan test" CLAUDE.md` | Only the 3 Commands-block lines (the module run, the run over several modules, `--filter`); nothing in the Git section |
| Pre-commit consistency | `grep -n "composer test" CLAUDE.md` | The Commands block, rules b and c, and the Git section |
| Folder line | `grep -n "Pest, feature tests in" CLAUDE.md` | The new wording |
| Table | `grep -c "^  | \`" CLAUDE.md` | 14 folder rows |
| Fixtures untouched | `git diff 42decef -- CLAUDE.md \| grep "Shared fixtures\|or in \`tests/Pest.php\`"` | No output |
| Stage 2 paths untouched | `git diff 42decef -- CLAUDE.md \| grep "Invariants/"` | Only the table row; no `-` line with a path |
| Everything else unchanged | `git diff 42decef --stat -- CLAUDE.md` and reading the diff | Only the three sites in the contract |

Then run `./vendor/bin/pint --test`. It should be clean.

## Full run

Run `composer test` once. Expect 3,598 passed. A different count stops the work until the reason is found.

## Exit check (manual)

1. Commit the `CLAUDE.md` change first, so the session reads it.
2. Start a fresh Claude Code session in the repository and give it this task, word for word:
   > add a test to tests/Feature/Tasks/TaskControllerTest.php that a task title over 255 characters is rejected
3. Let it work until it proposes a commit, or asks to. Note:
   - the test command it ran after the edit;
   - whether it ran `composer test` before proposing a commit;
   - the file and folder it wrote the test in.
4. Discard its change: run `git checkout -- tests/`, delete any new untracked file it created, then run `git status`. Nothing may remain under `tests/` or `app/`.
5. Record the result in §8.3 ([contracts/stage-log-section-8.md](contracts/stage-log-section-8.md)).

## Exit

- §8 is complete.
- The design doc's Status line reads "Implemented (Stages 0–3)" and links to the stage log.
- `git diff 42decef --stat` stays within scope.
- Every commit is without a co-author trailer.
