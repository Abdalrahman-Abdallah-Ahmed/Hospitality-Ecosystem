# Round 2 Consolidated Review: Faster Test Loop and Module-Scoped Test Runs (revision 2)

* **Target:** `docs/designs/test-suite-speed-and-module-runs.md` (630 lines)
  * FeatureRange: lines 1–402
  * DetailedRange: lines 403–630
* **Date:** 2026-11-15
* **Mode:** uninterrupted, all five stages.
* **Stage reports:** removed after consolidation. The traceability matrix below keeps the stage and finding numbers (S5–S9).
* **Overall verdict:**
  * **Feature spec:** approve with changes.
  * **Implementation plan:** not ready, with 1 blocker. Every finding has a fix that makes the design smaller, not bigger.
* **Round 1 comparison:** the 7 round-1 blockers are gone. No reviewer asked to bring back the selector, the map, the helper split or the schema dump.

## The four changes that matter

The stages converge on these:

1. **Run tests by folder path, not by Pest group.**
   * `--group` loads every test file before filtering, so a group run can't test isolation, and the 14 registry lines can drift.
   * A path run loads only that folder plus `tests/Pest.php`.
   * Commands become `php artisan test tests/Feature/<Module> tests/Feature/Invariants`.
2. **Always run `invariants` with the module.**
   * This deletes the hand-kept trigger list, which was incomplete: it missed Controllers, Requests, Reports and `QueueLane`.
   * G2 becomes "slowest module + invariants". If that misses the budget, add `--parallel` with a small worker count.
3. **Add one data-loss guard that runs before the app boots.**
   * If `bootstrap/cache/config.php` exists, the guard refuses to run.
   * It goes at the top of `tests/Pest.php`, or before `parent::setUp()`. A check after `parent::setUp()` would run after `RefreshDatabase` has already wiped the database.
   * This replaces the written config-clear rule.
4. **Add a rule for splitting files, and split only when the data shows a tail.**
   * Helpers and named datasets used by more than one part move to `tests/Pest.php` and are never copied.
   * Split by consecutive `it()` blocks, balanced by time.
   * Record the list of files to split before editing starts.
   * Longest-first ordering isn't available: PHPUnit's duration sort runs the shortest tests first.

## Action items

🔴 Blocker · 🟡 Major · 🟢 Nit

| # | Pri | Action |
|---|---|---|
| R1 | 🔴 | Split rule: helpers and named datasets used by more than one part move to `tests/Pest.php`; split by consecutive `it()` blocks balanced by time; copy `use` imports and `afterEach`; add `tests/Pest.php` to Stage 1 |
| R2 | 🟡 | Replace the group registry with path runs. Run the isolation check by path. Delete file 9's group lines and update the Definitions, CUJs, Stage 3 and Alternatives |
| R3 | 🟡 | Always run `invariants` with the module. Delete the trigger list and CUJ 2. Restate G2 (with `invariants`) and add a fallback: `--parallel`, then revise G2 |
| R4 | 🟡 | Add a guard that refuses to run when the config is cached, before the app boots. Drop the written rule and update the Risks table |
| R5 | 🟡 | Make splitting depend on the Stage 0 tail. Record "ordering not available" in Alternatives |
| R6 | 🟡 | Add a rule for `app/` files used by several modules (search `tests/Feature` or run the full suite). Copy the folder and Covers table into `CLAUDE.md`. Treat a run of zero tests as a failure |
| R7 | 🟡 | G2: give the exact command; work out per-module counts and times from the Stage 0 JUnit data; add an exit check in Stage 2 |
| R8 | 🟡 | Record the allowlists before editing: Stage 0's report names the files to split and their new names. Files 11 and 12 point to the split names, and the plan names the file that owns the permission dataset |
| R9 | 🟡 | Define `test:full` once, in Stage 1, and consider folding it into the unused `composer test` script. Fix the Stage 3 heading |
| R10 | 🟡 | Clean up the `CLAUDE.md` edits: lines 184–185 kept word for word; line 180's fate stated; "fix" limited to failures inside the module; text to paste separated from editor instructions; no claim that CUJ 5 is met |
| R11 | 🟡 | Remove the revision-1 terms: delete the "lessons" bullets and the stale-function-hook note, and link the review report |
| R12 | 🟡 | Timing budgets: state the intent only, measure after the splits, and read times from JUnit or a log file, not `dump` |
| R13 | 🟡 | Insert the group or path lines after line 110, not 108. This becomes moot if R2 removes the group lines |
| R14 | 🟡 | Module count: 13 modules plus `Invariants/`. Give the source of the baseline (one run on 2026-11-14) |
| R15 | 🟢 | Worker sweep: add a tie-break and include 16. Discard the first run at each worker count |
| R16 | 🟢 | Stage log: record all files over 20 s, plus the test count. Verification: check that no files remain directly under `tests/Feature` and that the counts add up |
| R17 | 🟢 | The file 8 comment already exists. The split example doesn't fit. List the root review reports as exempt from path updates |
| R18 | 🟢 | Wording: "3x" should be "2–4x"; the 40% has no derivation; G3 should say "must not"; fix the cross-reference names; CUJ 4 has no Given; revision history sits in the Overview; plus the style nits in the stage reports |

## Traceability matrix

| Action | Source findings |
|---|---|
| R1 | S9-IR B1; S9-CD B1; S7 M2 (design 2); S8 C4 |
| R2 | S5 #2; S7 M1 (design 1); S8 C1; S9-IR M3; S9-CD M2 |
| R3 | S5 #3; S7 M5 (design 5); S8 C2 |
| R4 | S7 M3 (design 3, structural 2, style M4); S8 A2, C3 |
| R5 | S5 #1, nit 7; S7 nit (design 7); S8 A1, C4 |
| R6 | S5 #4; S6 #1; S8 C5; S9-IR M4 |
| R7 | S7 M4 (design 4, style M2); S8 C6; S9-CD M5 |
| R8 | S9-CD M4; S9-IR M2 |
| R9 | S5 nit 5; S7 M7 (structural 1, design 9); S8 D; S9-CD nit 11 |
| R10 | S9-style M3, M4, N9, N10; S7 M6 (design 6) |
| R11 | S6 #2, #6; S7 M9 (style M1); S8 C7 |
| R12 | S5 nit 6; S8 C8; S9-IR nit 5; S9-CD M6; S9-style M2 |
| R13 | S9-CD M3; S9-style N8 |
| R14 | S6 nit 3; S7 M8; S9-style M1; S9-CD nit 9 |
| R15 | S9-IR nit 6; S6 nit 9 |
| R16 | S9-IR nits 7, 9; S9-style N2 |
| R17 | S9-IR nit 8; S9-CD nits 7, 8, 10; S9-style N12 |
| R18 | S7 nits (style N1–N14, structural 4–10); S9-style nits |

## Re-audit cap

This round was the one re-audit the review allows. After these fixes are applied, the review concludes, and any remaining findings are recorded as accepted trade-offs.
