# Consolidated Review: Faster Test Loop and Module-Scoped Test Runs

* **Target:** `docs/designs/test-suite-speed-and-module-runs.md` (983 lines)
  * FeatureRange: lines 1–614
  * DetailedRange: lines 615–983
* **Date:** 2026-11-15
* **Mode:** uninterrupted, all five stages.
* **Reviewer context:** `CLAUDE.md`, `phpunit.xml`, `tests/Pest.php`, `.github/workflows/tests.yml`.
* **Stage reports:** removed after consolidation. The traceability matrix below keeps the stage and finding numbers (S5–S9).
* **Overall verdict:** not ready to implement.
  * The measure-first approach and the speed half (Stages 0–1) hold up, after one correction.
  * The after-edit selector and the schema dump fail on correctness, safety or cost.

## The central decision

Three independent stages (5, 8 and 9) reach the same conclusion. As designed, the selector subsystem has these parts:
* the selector itself;
* `tests/modules.php`;
* `ModuleMapTest`;
* the commit replay;
* the helper split.

It cannot be built without open design decisions. It has a silent false-pass bug (helpers are shared across modules), and its own go/no-go gate is circular and biased.

The Stage 8 reviewer recommends a smaller design:

1. **Stage 0 (measure):** 3 warm runs, a worker sweep, and the time for the `invariants` group.
2. **Stage 1 (speed):** set `--processes` from the sweep, recheck the two timing tests, and split the files that take more than about 20 s. Exit check: G1, or revise G1.
3. **Stage 2 (folders):** a commit containing only `git mv` moves, Pest groups per folder plus `invariants`, the `CrossTenantRouteTest` path fix, and one isolated run of each group. `tests/Pest.php` stays as it is.
4. **Stage 3 (`CLAUDE.md` rules):**
   * after an edit, run `php artisan test --group=<module>`, adding `invariants` when shared files change;
   * before a commit, run Pint and `composer test:full`;
   * the failure and Postgres-down rules;
   * new tests go in their module's folder;
   * update the test paths named in the docs.
5. **Future Improvements:** the selector and map (with the lessons below), the helper split, the schema dump, a test container, WSL2, and CI sharding.

This is Alternative L plus folders, decided now instead of after a replay.

## Action items

🔴 = Blocker · 🟡 = Major · 🟢 = Nit. "Moot if simplified" means the item disappears under the smaller design above.

### 🔴 Blockers

| # | Action | Moot if simplified? |
|---|---|---|
| A1 | Decide whether to keep the selector, or adopt the smaller design | — |
| A2 | Replace the dataset fix with splitting files. Pest queues whole files, so a dataset doesn't spread across workers | No |
| A3 | Add a Risks & Mitigations section (5–6 rows in the smaller design) | No (shrinks) |
| A4 | Fix the schema-drop hook. The `RefreshDatabase` trait's own method takes priority over the `TestCase` override, so the hook never runs | Yes (Stage 2 removed) |
| A5 | Assign helpers by their real callers, or make all of `tests/Helpers/` `run_all`. `av*` is used in 33 files, `hk*` in 16, `kn*` in 20, `aat*` in 20 | Yes (no split) |
| A6 | Make the replay runnable and unbiased: translate old paths, use `--first-parent`, and set out the build order | Yes |
| A7 | Fill in the module map completely: owners, precedence, the full glob list, Superadmin, and how `dependents` is worked out | Yes |

### 🟡 Major

| # | Action | Moot if simplified? |
|---|---|---|
| B1 | Remove the seed-sharing option (`beforeEach` shares nothing, and `beforeAll` data leaks into later files) | No |
| B2 | Add `Composer\Config::disableProcessTimeout` to `test:full` (and `test:changed`). The baseline 403 s run exceeds Composer's 300 s default | No |
| B3 | Drop `--cache-result --order-by=defects`. It is removed in parallel mode, and ordering only applies within each worker | No |
| B4 | Use `dirname(__DIR__, 3)` in `CrossTenantRouteTest`. `base_path()` isn't available at that point | No |
| B5 | Define "warm" and G2 exactly. Use exact numbers instead of "about" | No |
| B6 | Use one term, "invariant tests"; list all 21 (or 22) of them; define or drop "Levels 2 and 3" | No |
| B7 | Correct the facts: 206 flat files plus 12 in Superadmin; Concierge 40 not 45; unmeasured claims backed or removed | No |
| B8 | Rewrite every "Rejected by author" as a technical trade-off, and move L's decision rule into one place | Partly |
| B9 | Give the group names exactly (case-sensitive), and register groups one way only | No |
| B10 | State that `it()` descriptions must not change when files are split (`EntryPointInventoryTest` matches on them) | No |
| B11 | Isolation check: run each module group alone after the move, instead of 206 single-file runs twice | No |
| B12 | Make the moves-only commit's broken intermediate state explicit, or include the `CrossTenantRouteTest` fix in it | No |
| B13 | Path classification and check order, ignore rules (`QueueLaneTest` reads `deploy/systemd/README.md`), helper ownership | Yes |
| B14 | The selector must refuse to run, or run `config:clear`, when a config cache exists (risk of hitting the dev database) | Yes |
| B15 | Selector on Windows: run Pest through `PHP_BINARY`, define exit codes and the test-counting method, add automated selector tests, define the glob syntax and share one matcher | Yes |
| B16 | Schema dump: it reaches production through `deploy.yml`; drift; the safety check doesn't exist; role privileges; Postgres 17 client tools | Yes |
| B17 | Write the Alternative L branch out as an executable plan (clean-up list, `CLAUDE.md` text) | Becomes the main plan |

### 🟢 Nit

- **Structure:** H2 levels for the resource sections; resolved decisions out of Open Questions; the solution summary out of The Problem; NFR placement; one format for all alternatives; code-block tags.
- **Wording:** filler words; mixed contractions; "author decision A"; `_test_<token>` versus `<n>`; "meeting G2"; "*Drafted with EGM*".
- **Docs:** `CLAUDE.md` lines 184–185 on where fixtures go; `specs/**` keeps historical paths; `.phpunit.cache` is already ignored; file counts in each table row.
- **Missing alternatives:** PHP in Docker, and per-test distribution (rejected because Pest queues whole files).
- **Measurement details:** the commands, the `junit-summary` output format, and how timing budgets are measured.

## Traceability matrix

| Action | Source findings |
|---|---|
| A1 | S5 #2, #3, #4; S8 #1, #2; S7 M2, M3; S9-IR B3, B4, M13 |
| A2 | S5 #1; S8 #4; S9-IR B2; S9-CD M3 |
| A3 | S7 B1 (structural #1, design M9); S8 addendum |
| A4 | S9-IR B1; S9-CD B1; S7 M6 |
| A5 | S5 #5; S8 #2; S9-CD B2, M1; S9-IR M4 |
| A6 | S5 #2; S6 #4; S7 M2; S9-IR B3; S9-CD M9 |
| A7 | S6 #3; S9-IR B4; S9-CD M8; S9-style M2 |
| B1 | S6 #6; S7 M7; S8 #4; S9-IR M10; S9-CD M4 |
| B2 | S9-IR M6; S9-CD M5 |
| B3 | S7 N5; S8 #7; S9-CD M6 |
| B4 | S9-IR M9; S9-CD N1; S9-style M5 |
| B5 | S6 #10; S7 M10; S9-style M6 |
| B6 | S6 #8, #9; S7 M9 |
| B7 | S6 #12; S7 M11; S9-style M1, N11 |
| B8 | S7 M8 |
| B9 | S6 #7; S7 nits; S9-IR M14 |
| B10 | S9-IR N5 |
| B11 | S7 N7; S8 #5 |
| B12 | S9-IR N2 |
| B13 | S6 #2; S7 M1; S9-IR M1, M2, M3 |
| B14 | S9-IR M5 |
| B15 | S7 nits (selector details); S9-IR M7, M11, M12; S9-CD M9 |
| B16 | S5 #6; S7 M4, M5; S8 #3; S9-IR M8; S9-CD M7; S9-style M8 |
| B17 | S9-IR M13; S9-style M7 |

## What holds up (agreed across stages)

- It measures before optimising, with a stage log and exit checks.
- It targets the measured causes: too many workers for 12 cores, and the slow tail of files.
- CI stays the full required check and keeps replaying the real migrations.
- The move commit contains only moves (`git mv`), so it can be reverted.
- The 206-file move table is accurate.
- Rejecting `--dirty`, coverage-based selection, class-name matching and per-file `->group()` calls holds up.
