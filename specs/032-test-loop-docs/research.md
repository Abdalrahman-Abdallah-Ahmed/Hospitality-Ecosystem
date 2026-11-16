# Research: Test Loop in CLAUDE.md (Stage 3)

Nothing in the Technical Context was left as NEEDS CLARIFICATION. These notes record the decisions made while planning.

## R1 — Module run at 4 processes for every module

- **Decision**: The after-edit command is `php artisan test tests/Feature/<Module> --parallel --processes=4`.
- **Rationale**:
  - Stage log §7.4: `Reservations` takes 225 s serially, which misses the 180 s G2 target. At 4 processes it takes 92 s (warm median of 3).
  - The design's Q2 says that, once Q2 is needed, the flag goes on the module run for all modules, so there is one command to remember.
  - Every other module is at or under 142 s serially, so the flag only helps them.
- **Alternatives considered**:
  - A serial run for small modules and a parallel run for large ones: two commands and a table lookup, for a few seconds saved.
  - 8 processes: not measured for a module run, and on a 12-CPU machine it would compete with the editor and the database.

## R2 — Several modules: serial with several paths

- **Decision**: `php artisan test tests/Feature/<A> tests/Feature/<B>`, serial, with the comment "parallel accepts one path".
- **Rationale**: Stage 2's two-path check (§7.3) showed that a serial run with two paths runs both folders. Parallel mode accepts only one path (§7.4).
- **Alternatives considered**:
  - Two parallel commands, one per folder. This works, but it is longer to write as a rule, and rule b already offers `composer test` as the simple fallback.

## R3 — Where the table and rules go in the Testing section

- **Decision**: Order the section like this:
  1. the rewritten folder line;
  2. the folder table;
  3. rules a–f under a short lead-in;
  4. the existing bullets (RefreshDatabase, API key, shared fixtures, UserFactory, tenant and permission tests), unchanged and in their current order.
- **Rationale**:
  - The folder line and the table answer "where do tests live".
  - The rules answer "what do I run", which follows from the table.
  - The existing bullets are about writing tests and stay together.
  - Nothing existing is reordered, so the diff is additions only, plus the one rewritten line.
- **Alternatives considered**:
  - A new `## Test loop` section. This splits testing guidance across two places.
  - Putting the table in the Commands block. That block is code, and a table cannot go there.

## R4 — The Invariants row

- **Decision**: The row reads `Invariants/` | "The invariant tests — not a module; runs in the full run only".
- **Rationale**:
  - The user asked for this wording.
  - The design's table says "The 21 invariant tests". Since Stage 1 there are 22 files, so the number is left out rather than quoted wrong.
- **Alternatives considered**: Writing "22". It would go stale the next time an invariant test is added.

## R5 — G1–G4 verdicts

- **Decision**:

  | Goal | Verdict | Note |
  | --- | --- | --- |
  | G1 | revised | Changed from 210 s to 300 s at the Stage 1 exit (§6.4). §8 says that runs since then measure 304–310 s (§7.5: 310 s and 313 s), just above the revised figure, and that the floor explained in §6.4 still applies. |
  | G2 | met | Met with Q2: 92 s against 180 s. |
  | G3 | met | 3,598 tests. The design's 3,597, plus the one test added in Stage 1 (§6.1, "Expected test count: 3,598"). |
  | G4 | met if the exit check passes | Otherwise, recorded as not met, with what the session missed. |

- **Rationale**: These use the figures the user gave, and every number traces to an existing section of the log.
- **Alternatives considered**: Re-timing the full run for G1. This is out of scope: Stage 3 changes no code.

## R6 — Exit check method

- **Decision**:
  - The developer starts a fresh Claude Code session in the repository on branch `032-test-loop-docs` after commit 2. They give it the example task, word for word, and nothing about how to test.
  - Three things are observed: the module command after the edit, `composer test` before proposing a commit, and the folder the test went into.
  - Afterwards, `git checkout -- tests/` and `git status` confirm the change is gone.
- **Rationale**:
  - The task names the file, so the folder check tests whether the session keeps it there rather than creating a file under `tests/Feature/`.
  - Task titles are validated from the column type through `GenericStoreRequest` and `ModelColumnRules`. This makes the task realistic: the test should pass without changes to `app/`.
- **Alternatives considered**:
  - A task that does not name the file. That tests rule f harder, but it changes the check the user specified.
