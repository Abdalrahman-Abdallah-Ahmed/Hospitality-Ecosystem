# Faster Test Loop and Module-Scoped Test Runs

*   **Author:** Abdalrahman-Abdallah-Ahmed
*   **Status:** Proposed (revision 4: Stage 0 results applied)
*   **Date:** 2026-11-16
*   **Reviews:**
    *   [round 1](reviews/test-suite-speed-and-module-runs/consolidated_review_report.md)
        reviewed revision 1;
    *   [round 2](reviews/test-suite-speed-and-module-runs/round-2/consolidated_review_report.md)
        reviewed revision 2.

    Revision 3 applied the round-2 fixes. Revision 4 applies the Stage 0
    measurements in [the stage log](../test-suite-performance.md):
    *   the after-edit run drops the invariant tests;
    *   large datasets may be split across files;
    *   three flaky tests are fixed before Stage 1.

## Overview

The backend has 218 feature test files, which expand to 3,597 test cases once
datasets are counted. Developers and AI coding sessions run them at two
moments:

*   after each edit, to check the change;
*   before each commit, to check nothing else broke.

This design makes three changes:

1.  **A faster full run.** The parallel worker count is set from a
    measurement, and slow files are split only if the measurement shows they
    hold up the end of the run.
2.  **Test folders by module.** Tests move into one folder per module. After
    an edit, a developer runs only the changed module's folder, by path. The
    invariant tests run in the full run before each commit.
3.  **One safety guard and written rules.**
    *   A check at the top of `tests/Pest.php` refuses to run when the Laravel
        config is cached, because that would point the tests at the dev
        database.
    *   `CLAUDE.md`, the instruction file that developers and AI coding
        sessions follow, says which command to run when.

CI keeps running the full suite unchanged, and it stays the required check
before a merge.

## The Problem

**Full run before commit.**

*   **Baseline (Stage 0, median of 3 warm runs):** **298.6 s** at the
    default 12 workers.
    *   The default worker count is one per logical CPU, which is 12 on this
        machine (Windows 11, 6 cores / 12 threads, Postgres in Docker).
    *   An earlier single run of 403 s was an outlier.
*   **Worker count barely matters above 8.** Workers 8–16 all finish in
    293–303 s. More workers make each test slower, because the CPU is the
    limit.
*   **One file sets the floor.**
    *   `CrossTenantRouteTest` takes 218 s at 8 workers, which is 72% of the
        run.
    *   Pest's parallel runner gives each worker whole files, so no worker
        count can make the run faster than that file.
    *   `PermissionAuthorizationTest` is next, at 79 s.
*   **Flaky tests.** Stage 0 found three tests that sometimes fail for
    reasons unrelated to the change being tested.

**After an edit.** There is no command that runs one module's tests:

*   206 of the 218 files sit flat in `tests/Feature/`. Only
    `tests/Feature/Superadmin/` (12 files) has its own folder.
*   `--dirty` selects only changed test files.
*   The only narrow option in `CLAUDE.md` is `--filter` on a single test
    name.

## Definitions

*   **Module folder:** one of the 13 folders in the Technical Plan table
    (every row except `Invariants/`).
*   **Invariant tests:** tests that check one rule across every route, AI
    tool, job or model, instead of one feature. Examples:
    *   tenant isolation;
    *   permissions;
    *   the entry-point and AI-tool inventories;
    *   queue lanes;
    *   cost visibility;
    *   list bounds and agent limits.

    There are 21. They live in `tests/Feature/Invariants/`, listed in the
    move table (Detailed Implementation Plan, item 8).
*   **Module run:** `php artisan test tests/Feature/<Module>`. It runs one
    module folder, by path, in one process. When an edit touches several
    modules, all of their folders are passed in one command.
*   **Warm run:** a run that meets all three conditions:
    *   it starts within 5 minutes of a completed run of the same command;
    *   Postgres has not been restarted since;
    *   it uses the same worker count.

    Each worker count creates its own worker databases, so the first run at
    a new count is cold and is discarded. Reported times are the median of
    3 warm runs.

## Goals

*   **G1 — Full run:** under 3 min 30 s, warm, at the worker count chosen in
    Stage 1.
*   **G2 — After-edit run:** the module run for the slowest module
    (`Reservations`) finishes in under 3 minutes, warm. The invariant tests
    are not part of it. Stage 0 measured them at 325–540 s alone, so they
    run only in the full run.
*   **G3 — Same tests:** the full run executes the same 3,597 test cases as the
    baseline. Splitting or moving a file must not change the count. Any
    difference is recorded in the stage log with its reason.
*   **G4 — Written loop:** `CLAUDE.md` gives the module run, the full run, the
    module table, and the rules for failures and for Postgres being down.

## Non-Goals

*   **CI speed.** `tests.yml` is unchanged.
*   **Catching every cross-module break after an edit.** The full run before
    commit and CI catch what a module run misses.
*   **Automatic test selection.** There is no selector, no module map and no
    trigger list. See Future Improvements.
*   **Splitting `tests/Pest.php` by module.** Many of its helper functions are
    used by several modules. For example, the `av*` helpers that build hotels,
    room types and rooms are called from 33 files.
*   **Faster database setup.** There is no schema dump, template database or
    second container.
*   **Changing `app/`.** Production code and its layout stay as they are.
*   **Tuning the shared Postgres container.** Its volume is shared with another
    project.

## Open Questions

*   **Q1 — Is G1 reachable on this machine?** If the Stage 0 sweep shows it is
    not, G1 is revised from the sweep data before Stage 1 is closed. The new
    value and the reason go in the stage log.
*   **Q2 — Is G2 reachable?** If the slowest module run misses G2, first add
    `--parallel --processes=4` to the module run for all modules. If it still
    misses, revise G2 from the data and record why.

## Critical User Journeys (CUJs)

### CUJ 1: Edit inside one module

*   **Given:** Postgres is running and the developer changed
    `app/Services/HousekeepingService.php`.
*   **When:** they run `php artisan test tests/Feature/Housekeeping`.
*   **Then:** only that folder runs, within G2.
*   **Variant:** if the changed file serves more than one module, for example
    `app/Services/BookingService.php`, they do one of two things:
    *   search `tests/Feature` for the class name and pass every folder that
        mentions it;
    *   or run the full run.

### CUJ 2: Edit to shared test setup

*   **Given:** the developer changed any of these:
    *   `tests/Pest.php` or `tests/TestCase.php`;
    *   `phpunit.xml`;
    *   `composer.json` or `composer.lock`;
    *   `config/`, `bootstrap/` or `database/`.
*   **When:** they reach the after-edit check.
*   **Then:** they run the full run (`composer test`) instead of a module
    run.

### CUJ 3: Cached config

*   **Given:** `bootstrap/cache/config.php` exists, for example after someone
    ran `php artisan config:cache`.
*   **When:** any test command starts.
*   **Then:**
    *   The run stops before Laravel boots, with a message telling the user
        to run `php artisan config:clear`.
    *   No test touches a database.

### CUJ 4: Before a commit

*   **Given:** the change is ready and Postgres is running.
*   **When:** the developer or an AI session runs `./vendor/bin/pint`, then
    `composer test`.
*   **Then:** the full run, including the invariant tests, finishes within
    G1 and reports the baseline test count (G3).

### CUJ 5: AI session

*   **Given:** a fresh AI session implements a task in one module.
*   **When:** it finishes each edit, and later prepares a commit.
*   **Then:**
    *   It follows the `CLAUDE.md` loop:
        *   a module run after each edit;
        *   a full run for shared setup;
        *   a full run before commit.
    *   It treats a run that reports zero tests as a failure.
    *   It fixes failures inside the module.
    *   It reports failures outside the module instead of editing unrelated
        code.
    *   It never edits an invariant test only to make it pass.
    *   If Postgres is not reachable, it stops and tells the user.

## Technical Plan (Architecture)

There are four stages. Each one is its own PR, merged in order. Each stage
ends with an **Exit check**, and the results go in the stage log,
`docs/test-suite-performance.md`. Because later stages build on earlier ones,
a stage can be reverted only after the stages that follow it.

```text
Stage 0 Measure ─► Stage 1 Speed ─► Stage 2 Module folders ─► Stage 3 CLAUDE.md
```

### Stage 0 — Measure (no behaviour change)

*   **Baseline:** three warm full parallel runs at the default worker count
    (12 on this machine),
    with JUnit output. The JUnit files give per-file and per-test times.
*   **Worker sweep:** the same measurement with `--processes` set to 4, 6, 8,
    10, 12 and 16.
*   **Derived figures** (computed from the JUnit files, with no extra runs):
    *   each file's summed time;
    *   each planned module folder's test count and summed time, using the
        move table;
    *   the invariant tests' summed time.
*   **Checks:**
    *   Is Xdebug loaded?
    *   Is `opcache.enable_cli` on?
    *   Is Postgres `max_connections` (100 today) enough for the counts in
        the sweep?

The JUnit files are summarised by a script kept outside the repository. For
each file it computes the summed `time` of its test cases, as a median across
the 3 runs.

**Exit check:** every figure above is recorded in the stage log.

### Stage 1 — Speed

*   **First, fix the three flaky tests Stage 0 found.** A run that fails
    for unrelated reasons makes every timing below unreliable.
    *   `AiActivityOutreachTest`: two rows tie on time, so their order
        varies.
    *   A worker database lost its tables partway through a run.
    *   `ProactiveTriggersTest`: a unique-key violation.

    Each fix goes in its own commit, with the cause recorded in the stage
    log.
*   **Worker count:** the lowest count whose median is within 5% of the
    fastest median in the sweep. Stage 0 chose **8**.
*   **Full-run script:** the existing `composer test` script becomes the full
    run. It:
    *   turns off Composer's 300 s script timeout;
    *   clears the config cache;
    *   runs `php artisan test --parallel --processes=<N>`.

    There is no second script.
*   **Slow files are split only if the data shows an end-of-run wait.**
    *   A file is split when its summed time is more than 25% of the Stage 1
        wall time.
    *   A file that long can finish last on its own, whatever order the
        files run in.
    *   If no file passes that test, nothing is split.
*   **Split rules:**
    *   Tests are split as consecutive `it()` blocks, in source order, so
        each part has a similar summed time.
    *   A helper function or named dataset used by more than one part moves
        to `tests/Pest.php` under its existing name. It is never copied,
        because every file loads into the same PHP process, and a second
        definition stops the run with "Cannot redeclare".
    *   Each part keeps the original's `use` imports, `uses(...)`,
        `beforeEach` and `afterEach`.
    *   `it('...')` texts stay word for word, because
        `EntryPointInventoryTest` finds tests by them.
    *   A test that checks a total across a loop stays in one part, with its
        dataset. An example is the `$checked > 100` check in
        `CostVisibilityTest`.
    *   **Exception for large datasets of independent rows.** When one test
        runs over a big dataset, and each row is checked on its own, the rows
        may be split across files. This applies to `CrossTenantRouteTest`
        (315 routes) and `PermissionAuthorizationTest`.
        *   The function that builds the dataset moves to `tests/Pest.php`.
            It takes a fixed filter, for example a route-name prefix, and
            each file passes its own filter.
        *   Every row must land in exactly one file. A test in one of the
            files checks this: the slices together must equal the full
            list, with no row in two slices.
        *   Each file's slice gets a similar summed time.
    *   The list of files to split, and their new names, is written into the
        stage log before any edit. That list is the set of files the PR may
        touch.
*   **Timing-budget tests:** `RecommendationQueueTest` and `StayListTest`
    allow 4.0 s in parallel. After any splits, they are checked against
    their JUnit times in 3 full runs. A budget is raised only if those runs
    need it, and the new value is recorded.

**Exit check:** the G1 median. If G1 is missed, Q1 applies.

### Stage 2 — Module folders and the guard

*   **Move commit:**
    *   One commit moves the flat files into module folders with `git mv`,
        following the move table. This covers the original 206 files plus
        any parts from Stage 1.
    *   The same commit fixes the one path that breaks, in
        `CrossTenantRouteTest`, so the commit stays green.
*   **Guard:** a few lines at the top of `tests/Pest.php`.
    *   If `bootstrap/cache/config.php` exists, the guard stops the run with
        a clear message.
    *   It runs before Laravel boots and before `RefreshDatabase` touches
        any database.
    *   It refuses to run rather than deleting the cache file.
*   **Isolation check:** each of the 14 folders is run alone by path, for
    example `php artisan test tests/Feature/Housekeeping`.
    *   A path run loads only that folder's files and `tests/Pest.php`. A
        `--group` run would load every file before filtering, so it could not
        catch a missing helper.
    *   A folder that fails alone usually depends on a helper defined in
        another test file. That helper moves to `tests/Pest.php`, in a
        separate commit after the move commit.
    *   Any other cause is recorded in the stage log.
*   **Two-path check:** confirm that `php artisan test <path> <path>` runs
    both folders. If it does not, the module run becomes two commands.

**Exit check:** each folder passes alone. Each module run is timed for G2,
and Q2 applies if G2 is missed.

Folders:

| Folder | Covers |
| --- | --- |
| `Core/` | Hotels, users, accounts, tenancy, auth, staff roles, seeders, health checks, queue checks, alerts, event log |
| `Rooms/` | Rooms, room types, availability |
| `Reservations/` | Reservations, stays, check-in and check-out, room assignment, vouchers |
| `Housekeeping/` | Housekeeping and maintenance |
| `Tasks/` | Tasks, teams, task categories |
| `Activities/` | Activities, bookings, cancellations |
| `Concierge/` | WhatsApp, guests, guest memory, guest requests, escalation |
| `Recommendations/` | Recommendations, pitching, proactive messages, outcomes |
| `Knowledge/` | Knowledge base, documents, retrieval |
| `AiPlatform/` | AI cost, usage metering, admin advisor and its tools, insights |
| `Finance/` | Transactions |
| `ImportExport/` | Reservation import, data export |
| `Superadmin/` | `/api/superadmin` (existing folder) |
| `Invariants/` | The 21 invariant tests (not a module) |

### Stage 3 — CLAUDE.md

`CLAUDE.md` is rewritten so that it does six things:

*   lists the module run and the full run as the test commands;
*   includes the folder table above;
*   gives the rule for files that serve several modules (CUJ 1 variant) and
    for shared setup (CUJ 2);
*   says a run reporting zero tests is a failure;
*   says new test files go in their module's folder;
*   gives the failure and Postgres-down rules from CUJ 5.

**Exit check:** a fresh AI session, given a one-module task, follows the loop.
This is checked by hand and recorded in the stage log.

## Risks & Mitigations

| Risk | Impact | Mitigation | Signal |
| --- | --- | --- | --- |
| A module run passes but another module breaks | The break is found later than the edit | The full run before every commit, and CI on every PR | The full run or CI fails after a green module run |
| A cached config sends tests to the dev database | Dev data lost | The guard at the top of `tests/Pest.php` (Stage 2) | The guard's message |
| A test file depends on a helper defined in another test file | A folder fails when run alone | The Stage 2 isolation check by path; shared helpers live in `tests/Pest.php` | A folder fails alone but passes in the full run |
| A split copies a helper into two files | "Cannot redeclare" ends the run | The Stage 1 split rules | A fatal error on the first run after a split |
| Timing-budget tests fail more often after the worker count changes | Intermittent test failures | Budgets checked against JUnit times in Stage 1 | `RecommendationQueueTest` or `StayListTest` fails, then passes on rerun |
| A new test file is added directly under `tests/Feature/` | Module runs skip it (the full run still covers it) | The `CLAUDE.md` rule; the Stage 2 verification | A `*.php` file directly under `tests/Feature/` |
| The chosen worker count exceeds what Postgres allows | Connection errors | Stage 0 checks `max_connections` | "too many clients" errors |
| The move commit breaks something | Failing test run | It contains only moves plus the one path fix, and can be reverted on its own | The full run fails after the move |

## Alternatives Considered

Each entry ends with a decision. **Rejected** means it will not be done.
**Deferred** means it is listed under Future Improvements. **Not available**
means the tools cannot do it.

### Pest groups registered per folder

*   **Pros:** short commands, such as `--group=housekeeping`.
*   **Cons:**
    *   A `--group` run loads every test file before filtering, so it cannot
        test a folder in isolation.
    *   The 14 registration lines can fall out of step with the folders
        without any warning.
*   **Decision:** rejected in favour of path runs.

### Running the invariant tests after every edit

*   **Pros:** catches cross-cutting breaks before the commit.
*   **Cons:** Stage 0 measured them at 325–540 s alone, which is more than
    the 3-minute after-edit target.
*   **Decision:** rejected by measurement. They run in the full run before
    each commit.

### A list of paths that trigger the invariant tests

*   **Pros:** saves running the invariant tests after edits that do not
    affect them.
*   **Cons:**
    *   It is a hand-kept list that nothing checks.
    *   The revision-2 list already missed controllers, requests, reports and
        `QueueLane`.
    *   Most edits touch models, routes or policies anyway.
*   **Decision:** rejected. The invariant tests always run with the module.

### Automatic test selector and module map

*   **Pros:** picks the folders from git changes.
*   **Cons:**
    *   About 668 app files would need an owner.
    *   Shared helpers let a narrow selection skip affected tests without any
        warning.
    *   Core is used by every module.
    *   It needs its own tests.

    Details are in the round-1 review.
*   **Decision:** deferred.

### A Composer script for module runs

*   **Pros:** could clear the config cache before each module run.
*   **Cons:** path runs, `--filter` and IDE runs would bypass it.
*   **Decision:** rejected. The guard in `tests/Pest.php` covers every
    command.

### Longest-first file ordering

*   **Pros:** would start slow files early and shorten the end of the run.
*   **Cons:**
    *   PHPUnit's `--order-by=duration` runs the shortest tests first.
    *   `duration` and `reverse` cannot be combined.
*   **Decision:** not available.

### Per-test distribution across workers

*   **Pros:** would remove the end-of-run wait without splitting any files.
*   **Cons:** Pest's parallel runner hands out whole files.
*   **Decision:** not available.

### Datasets or shared seeds to speed up slow files

*   **Pros:** no new files.
*   **Cons:**
    *   Dataset rows stay on the same worker as their file.
    *   `beforeEach` runs again for every test, so moving setup into it saves
        no time.
    *   Data seeded outside the per-test transaction leaks into later files.
*   **Decision:** rejected.

### Schema dump, template database or separate test container

*   **Pros:** removes the 25–30 s migration replay per worker.
*   **Cons:**
    *   The saving is at most about 7%.
    *   A committed dump would also be loaded by a fresh production install
        through `deploy.yml`.
    *   It needs Postgres 17 client tools on every machine.
*   **Decision:** deferred.

### Pest `--dirty`

*   **Pros:** built in.
*   **Cons:** it selects only changed test files, and ignores `app/` changes
    and committed changes.
*   **Decision:** rejected.

### Coverage-based test selection

*   **Pros:** links code to tests from real data.
*   **Cons:**
    *   It needs a coverage driver on every run, plus stored coverage data.
    *   We found no maintained PHP tool that selects tests from coverage.
*   **Decision:** rejected.

### Tuning durability settings on the shared Postgres container

*   **Pros:** one setting change.
*   **Cons:**
    *   It affects the other project's data.
    *   Rolled-back tests do not write to disk, so only migrations and 6
        concurrency tests would gain.
*   **Decision:** rejected.

### Running PHP next to Postgres (WSL2 or Docker)

*   **Pros:** removes the Windows-to-Docker network hop on every query.
*   **Cons:**
    *   Every contributor would need a new setup.
    *   IDE debugging changes.
    *   The checkout would have to move into the Linux file system for speed.
*   **Decision:** deferred.

## Non-Functional Requirements

*   **Data safety:**
    *   The guard stops any test run while the config is cached.
    *   `composer test` also clears the cache before it runs.
*   **No production impact:**
    *   No file under `app/`, `routes/`, `config/` or `database/` changes.
    *   `deploy.yml` and `tests.yml` do not change.
*   **Rollback:** stages are reverted in reverse order.

## Future Improvements

*   **An automatic selector and module map,** if module runs often pick the
    wrong folders in practice. The round-1 and round-2 reviews list what such
    a tool must handle.
*   **Splitting `tests/Pest.php` by module,** once each helper has been
    assigned by its real callers.
*   **Faster database setup:** a schema dump kept out of git, a template
    database, or a separate test container.
*   **PHP in WSL2 or Docker.**
*   **CI sharding,** if CI time becomes a problem.
*   **Organising `app/` by module.**

## Detailed Implementation Plan

Each stage is one PR. "Move" means `git mv`. The move commit contains no
content changes other than the path fix in item 9.

### Stage 0 — Measure

#### 1. `docs/test-suite-performance.md` (Create)

*   **Type / Scope:** Documentation (stage log).
*   **Proposed Changes:**
    *   **Method:**
        *   the machine;
        *   the definition of a warm run;
        *   the commands:

            ```text
            php artisan config:clear
            php artisan test --parallel --processes=<N> --log-junit=storage/test-reports/<label>.xml
            ```
        *   discard the first run at each worker count.
    *   **Pre-Stage 0 reference:** one run on 2026-11-14 at the default
        worker count (12): 403 s wall, 3,483 s summed test time, 3,597
        tests.
    *   **Stage 0 results:**
        *   the 3-run median and the test count;
        *   the sweep table (worker count against median wall time);
        *   every file with its summed time, sorted;
        *   each planned folder's test count and summed time;
        *   the invariant tests' summed time;
        *   the Xdebug, opcache and `max_connections` checks.
*   **Why:** G1–G3 are judged against these recorded numbers.

#### 2. `.gitignore` (Modify)

*   **Type / Scope:** Repository configuration.
*   **Proposed Changes:**
    *   Add the line `/storage/test-reports/`.
    *   `/.phpunit.cache` is already ignored, at line 13.
*   **Why:** JUnit output must not be committed.

### Stage 1 — Speed

#### 3. `composer.json` (Modify)

*   **Type / Scope:** Composer scripts.
*   **Proposed Changes:** Replace the `test` script (lines 66–69) with three
    entries:
    1.  `Composer\\Config::disableProcessTimeout`;
    2.  `@php artisan config:clear --ansi @no_additional_args`;
    3.  `@php artisan test --parallel --processes=<N>`.

    `@no_additional_args` keeps arguments such as `--filter` going to
    `artisan test`, not to `config:clear`. No other script is added.
*   **Why:**
    *   The 298.6 s baseline is within a few seconds of Composer's 300 s
        default timeout, and a slow or failing run goes past it.
    *   A cached config ignores the database settings in `phpunit.xml`.
    *   Nothing else in the repository calls `composer test`; CI calls
        `php artisan test` directly.

#### 4. `docs/test-suite-performance.md` (Modify, before any split)

*   **Type / Scope:** Stage log.
*   **Proposed Changes:**
    *   Record the chosen worker count and the Stage 1 wall time.
    *   Record every file whose summed time exceeds 25% of that wall time.
        This is the split list.
    *   Record each file's new part names, `<Base><Part>Test.php`. `<Base>`
        is the original name without `Test`, and `<Part>` is a short noun
        for the subject of its `it()` blocks.
    *   Record which helpers and named datasets move to `tests/Pest.php`.
    *   If the list is empty, record that and skip items 5–6.
*   **Why:** The PR may touch only the files on this list.

#### 5. The files on the split list (Split), and `tests/Pest.php` (Modify)

*   **Type / Scope:** Feature and invariant tests.
*   **Proposed Changes:**
    *   Apply the split rules from the Technical Plan, Stage 1.
    *   Each part ends with a similar summed time. The split is done when no
        part exceeds 25% of the wall time on the next run.
    *   Moved helpers and named datasets go to the end of `tests/Pest.php`,
        unchanged.
*   **Why:** This removes the end-of-run wait shown in the data.

#### 6. Documents that name a split file (Modify, only if affected)

*   **Type / Scope:** Documentation.
*   **Proposed Changes:**
    *   Where `CLAUDE.md` (lines 99, 188, 191),
        `docs/usage-metering-api-documentation.md` (line 223) or
        `docs/ai-cost-attribution-api-documentation.md` (lines 134, 334)
        names a file that was split, name the part that now holds the test.
    *   For `PermissionAuthorizationTest`, name the part that holds the
        permission dataset.
*   **Why:** Documents must point to files that exist.

#### 7. `tests/Feature/RecommendationQueueTest.php` (line 110) and `tests/Feature/StayListTest.php` (line 147) (Modify, only if needed)

*   **Type / Scope:** Timing-budget tests.
*   **Proposed Changes:**
    *   After items 5–6, run 3 full runs and read these two tests' JUnit
        `time`.
    *   If any value is within 25% of 4.0 s, raise the parallel value in
        `ParallelTesting::token() ? 4.0 : 2.0`. Use the slowest observed time
        × 1.5, rounded up to the next 0.5 s.
    *   The serial `2.0` does not change.
    *   Record the result in the stage log either way.
*   **Why:** A different worker count changes the load on these tests.

### Stage 2 — Module folders and the guard

#### 8. Move of the flat files in `tests/Feature/*.php` into module folders (Move)

*   **Type / Scope:** One commit containing only moves, plus the item 9 fix.
*   **Proposed Changes:**
    *   This table is the one-time move plan, covering the 206 files that
        exist before Stage 1. After the move, the folders are the source of
        truth.
    *   Parts created in Stage 1 go to their original file's folder.
    *   `tests/Feature/Superadmin/` stays and gains one file.
    *   `tests/Unit/ExampleTest.php` stays where it is.

    | Folder (count) | Files |
    | --- | --- |
    | `Invariants/` (21) | AdminToolIsolationTest, AdminToolPermissionTest, AdminToolsetArchTest, AgentLimitsTest, AiToolFieldProjectionTest, AiToolInventoryTest, BoundedListTest, ConciergeToolsArchTest, CostVisibilityTest, CrossTenantRouteTest, EntryPointInventoryTest, JobSuspensionInventoryTest, JobTenantIsolationTest, KnowledgeRetrievalIsolationTest, KnowledgeSearchToolTest, PermissionAuthorizationTest, QueueLaneTest, RoomTypePermissionAuthorizationTest, SuperAdminDeniedOnTenantRoutesTest, SuspensionRouteInventoryTest, TenantIsolationTest |
    | `Core/` (32) | AccessLevelSeederTest, AccountOwnershipTest, ApiKeyTest, AuthTest, CorsTest, CreateUserTest, DashboardCacheTest, DashboardControllerTest, DatabaseSeederTest, DeletedHotelAccessTest, EventLogTest, FilterableTest, FreshInstallTest, GenericCrudTest, GenericIndexFilterTypesTest, HealthCheckTest, HistoryTypesTest, HotelControllerTest, HotelGroupTest, HotelPolicyControllerTest, MultiHotelAccessTest, OperationalDefaultsTest, OpsReadinessCommandTest, PasswordResetTest, PlatformAlertTest, QueueWaitCheckTest, SentryScrubberTest, StaffRoleControllerTest, SuspensionJobTest, SuspensionModelTest, SuspensionTest, SuspensionWhatsAppTest |
    | `Rooms/` (10) | AvailabilityControllerTest, AvailabilityServiceTest, AvailabilityToolsTest, OutOfOrderTest, RoomControllerTest, RoomOverlapConstraintTest, RoomOverlapsCommandTest, RoomStatusSplitTest, RoomTypeAvailabilityIntegrationTest, RoomTypeControllerTest |
    | `Reservations/` (34) | CheckInOutConcurrencyTest, CheckInTest, CheckOutTest, CreateReservationToolTest, CreationNotificationTest, MultiRoomCheckInOutTest, NoShowTest, NoShowToolTest, ReservationAvailabilityConcurrencyTest, ReservationAvailabilityGuardTest, ReservationControllerTest, ReservationGuestsMigrationTest, ReservationGuestsTest, ReservationLifecycleTest, ReservationRoomOccupancyTest, ReservationRoomsAiToolsTest, ReservationRoomsMigrationTest, ReservationRoomsTest, ReservationStatusDeprecationTest, ReservationVoucherTest, RoomAssignmentConcurrencyTest, RoomAssignmentTest, RoomChangeRequestTest, RoomMoveTest, RoomUpgradeTest, StayAiToolsTest, StayListTest, StayMigrationTest, StayServiceTest, StaySyncTest, UnassignedArrivalsTest, VoucherEmailTest, VoucherRendererTest, VoucherTemplateControllerTest |
    | `Housekeeping/` (7) | HousekeepingBoardTest, HousekeepingConcurrencyTest, HousekeepingInspectionTest, HousekeepingLifecycleTest, HousekeepingManualStatusTest, HousekeepingStartOfDayTest, RoomIssueReportTest |
    | `Tasks/` (5) | TaskCategoryControllerTest, TaskControllerTest, TaskNotificationTest, TaskStayLinkTest, TeamControllerTest |
    | `Activities/` (10) | ActivityAvailabilityControllerTest, ActivityAvailabilityServiceTest, ActivityControllerTest, BookingCancellationRequestTest, BookingCapacityConcurrencyTest, BookingControllerTest, BookingScheduleBackfillTest, BookingTest, BookingUpdateTest, CancellationOutcomeNoticeTest |
    | `Concierge/` (21) | AvailableChannelsTest, ConciergeBookingToolsTest, ConciergeGuestRestrictionsTest, ConciergeKnowledgeCitationTest, ConciergeMemoryTest, ConciergeReadToolsTest, ConversationViewerTest, GuestConciergeVipTest, GuestContactPreferenceTest, GuestContactTest, GuestControllerTest, GuestEscalationConcurrencyTest, GuestEscalationTest, GuestIdentityServiceTest, GuestMemoryApiTest, GuestMemoryExtractionTest, GuestRequestNoticeTest, GuestRequestRoutingTest, SenderRecognitionServiceTest, WhatsAppDeviceTest, WhatsAppWebhookTest |
    | `Recommendations/` (19) | ConversionAnalyticsTest, DeclineRetryTest, ExpireRecommendationsJobTest, PitchActivityToolTest, PitchEligibilityTest, PitchFromApprovedPoolTest, ProactiveGuardrailsTest, ProactiveMessageLogTest, ProactiveOptOutTest, ProactivePitchTest, ProactiveSettingsTest, ProactiveTriggersTest, RecommendationApprovalTest, RecommendationDeliveryTest, RecommendationEditApprovalTest, RecommendationMatchingTest, RecommendationOutcomeTest, RecommendationQueueTest, RecommendationStatusMigrationTest |
    | `Knowledge/` (9) | KnowledgeBaseArticleControllerTest, KnowledgeChunkSyncTest, KnowledgeDocumentControllerTest, KnowledgeDocumentCorrectionTest, KnowledgeDocumentExtractionTest, KnowledgeDocumentIndexingTest, KnowledgeDocumentLifecycleTest, KnowledgeDocumentReplaceTest, KnowledgeDocumentVisionTest |
    | `AiPlatform/` (22) | AccountUsageAiBlockTest, AdminAiAuditTest, AdminCreateToolsTest, AdminGuestMessagesRemovalTest, AdminReadToolsTest, AdminRecommendationToolsTest, AdminToolParityTest, AdminToolsReviewFixesTest, AdminWriteToolsTest, AdvisorConfirmationTest, AiActivityApiTest, AiActivityCoverageTest, AiActivityJobsTest, AiActivityOutreachTest, AiActivityRecorderTest, AiAdvisorControllerTest, AiCapabilityCoverageTest, AiCostAttributionTest, AiCostCapabilityTest, AiRunTest, InsightCitationsTest, UsageMeteringTest |
    | `Finance/` (5) | TransactionAttributionTest, TransactionControllerTest, TransactionImportTest, TransactionServiceTest, TransactionsFeatureFlagTest |
    | `ImportExport/` (10) | ExportControllerTest, ReservationImportDryRunTest, ReservationImportGuestMatchingTest, ReservationImportIdempotencyTest, ReservationImportMappingConfirmTest, ReservationImportMappingTest, ReservationImportNormalizationTest, ReservationImportPreviewTest, ReservationImportTest, SqlDumpTest |
    | `Superadmin/` (+1) | SuspensionAdministrationTest |

*   **Why:** The folder decides which module run includes a test.

#### 9. `tests/Feature/Invariants/CrossTenantRouteTest.php` (Modify, in the move commit)

*   **Type / Scope:** Invariant test.
*   **Proposed Changes:**
    *   At line 52, replace `require __DIR__.'/../../bootstrap/app.php'`
        with `require dirname(__DIR__, 3).'/bootstrap/app.php'`.
    *   Keep the existing docblock at lines 40–43, which already explains
        why `base_path()` cannot be used there.
*   **Why:** The file moves one folder deeper.

#### 10. `tests/Pest.php` (Modify)

*   **Type / Scope:** Pest bootstrap.
*   **Proposed Changes:**
    *   Add the guard as the first statement after the `use` imports, before
        line 108:
        *   if the file `dirname(__DIR__).'/bootstrap/cache/config.php'`
            exists, write a message to standard error and exit with code 1;
        *   the message says that the config is cached, that tests would use
            the cached database settings, and that the user should run
            `php artisan config:clear`.
    *   In a separate commit after the move commit, move any helper the
        isolation check finds into this file, unchanged.
    *   Nothing else changes.
*   **Why:** This prevents the data-loss risk for every test command.

#### 11. `docs/test-suite-performance.md` (Modify)

*   **Type / Scope:** Stage log.
*   **Proposed Changes:** Record:
    *   each folder's result when run alone;
    *   the result of the two-path check;
    *   each module run's warm median, for G2;
    *   the full-run test count, for G3.

### Stage 3 — CLAUDE.md and documentation

#### 12. `CLAUDE.md` (Modify)

*   **Type / Scope:** Project instructions.
*   **Proposed Changes:**
    *   **Line 20.** Replace it with three lines of text:

        ```text
        php artisan test tests/Feature/<Module>   # after each edit (one or more changed modules)
        composer test                             # full parallel run with invariant tests, before a commit
        php artisan test --filter=SomeTest        # one test
        ```
    *   **Line 99.** Change the path to
        `tests/Feature/Invariants/PermissionAuthorizationTest.php`, or to
        the part named in item 6.
    *   **Line 180.** Replace it with: "Pest, feature tests in
        `tests/Feature/<Module>/`, one file per controller or service, in its
        module's folder."
    *   **Lines 184–185.** Leave them word for word.
    *   **Lines 188 and 191.** Change the paths to
        `tests/Feature/Invariants/`, or to the parts named in item 6.
    *   **Testing section.** Add these items:
        *   the folder table from the Technical Plan;
        *   the CUJ 1 variant rule;
        *   the CUJ 2 rule;
        *   "A run that reports zero tests is a failure.";
        *   "If a test run stops with the cached-config message, run
            `php artisan config:clear`."
    *   **Line 210.** Replace it with these rules:
        *   "Run `./vendor/bin/pint` and `composer test` before proposing a
            commit."
        *   "Fix failures inside the changed module and rerun."
        *   "Report failures outside it instead of editing unrelated code."
        *   "Never edit an invariant test only to make it pass."
        *   "If Postgres is not reachable, stop and tell the user."
*   **Why:**
    *   This meets G4, and gives an AI session the rules that CUJ 5 depends
        on.
    *   Lines 20 and 210 change together so they do not contradict each
        other.

#### 13. `docs/usage-metering-api-documentation.md`, `docs/ai-cost-attribution-api-documentation.md`, `deploy/systemd/README.md` (Modify)

*   **Type / Scope:** Living documentation.
*   **Proposed Changes:**
    *   Update each `tests/Feature/<File>` path to its new folder, or to its
        part from item 6.
    *   `QueueLaneTest` reads only the `queue-worker@...` lines of
        `deploy/systemd/README.md`, so changing the path text there does not
        affect it.
    *   These files keep their original paths, because they are historical
        records:
        *   `docs/PGRIP_*`;
        *   `docs/designs/*`;
        *   `specs/**`;
        *   the review reports in the repository root.
*   **Why:** Living docs must point to real files.

#### 14. `docs/test-suite-performance.md` (Modify)

*   **Type / Scope:** Stage log.
*   **Proposed Changes:**
    *   Add the summary of G1–G4, each met or revised, with the numbers.
    *   Add the result of the Stage 3 exit check.

### Verification Plan

*   **Style:** `./vendor/bin/pint --test` passes in every PR.
*   **G1:**
    *   Run `composer test` 3 times, warm.
    *   It passes if the median is under 3 min 30 s.
    *   If it is not, record the result and settle Q1 before Stage 2.
*   **G2:**
    *   Run each module run 3 times, warm, using the exact command from item
        12 (without the invariant tests).
    *   It passes if the slowest median is under 3 minutes.
    *   If it is not, apply Q2.
*   **G3:** after each stage, the full run reports 3,597 tests, or the stage
    log explains the difference.
*   **Isolation:** each of the 14 folders passes when run alone by path.
*   **Placement:**
    *   After Stage 2, no `*.php` file sits directly under `tests/Feature/`.
    *   The 14 folders' test counts add up to the full-run count, minus the
        one test in `tests/Unit`.
*   **Guard:**
    *   With `bootstrap/cache/config.php` present, `php artisan test` and a
        path run both stop with the guard message before any database is
        touched.
    *   `composer test` clears the cache first, so it runs normally.
    *   After `php artisan config:clear`, every command runs normally.
*   **CI:** every PR passes the unchanged `tests.yml`.
*   **G4:** the Stage 3 exit check is recorded.
