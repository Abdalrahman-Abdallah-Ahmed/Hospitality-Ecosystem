# Quickstart: Measure the Test Suite (Stage 0)

How to run Stage 0 and check it is done. Details: [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/).

## 1. Pre-flight (stop on any failure)

1. **Config not cached**: `bootstrap/cache/config.php` must not exist. If it does, stop and tell the developer.
2. **Postgres up**: `docker ps` shows `hospitality-postgres` running. Do not run `compose up` (it would replace the shared container; see project memory).
3. **Target is the testing DB**: `phpunit.xml` forces `DB_DATABASE=Hospitality_Ecosystem_testing` (env `force="true"` and `<server>`).
4. **Dev DB fingerprint (before)**: read-only, on `Hospitality_Ecosystem`: table count, `migrations` row count, `users` row count. Save in the scratchpad.
5. **Checks** (research R7, R8): `php -m` for Xdebug and OPcache, `ini_get('opcache.enable_cli')`, `SHOW max_connections`.
6. **Machine quiet**: no other test run, build or heavy app running.
7. **Script smoke test**: `php artisan test --parallel --processes=2 --log-junit=storage/test-reports/smoke.xml tests/Unit/ExampleTest.php`, then run the script against it and confirm it finds the file path and test count. Delete `smoke.xml`.

## 2. Runs

For each count in the order **12, 4, 6, 8, 10, 16**, run 4 times back to back (`k` = 1…4):

```text
php artisan config:clear
php artisan test --parallel --processes=<N> --log-junit=storage/test-reports/w<N>-r<k>.xml
```

Record each run in `runs.csv`: start, end, wall time, exit code. Run 1 is `cold`. Re-run (next `k`) if a run fails, the gap since the last run exceeds 5 minutes, or Postgres restarted, until the block has 3 `warm` runs. No env-var prefix on the commands.

Expected: 24 runs, about 2.5–4 hours.

## 3. Summarise and write the log

1. Run the script (contract: [summary-script.md](contracts/summary-script.md)); it must exit 0.
2. Create `docs/test-suite-performance.md` following [stage-log.md](contracts/stage-log.md).
3. Add `/storage/test-reports/` to `.gitignore`.
4. **Dev DB fingerprint (after)**: must equal the "before" values.

## 4. Exit check

- [ ] The stage log has every section of the stage-log contract, with no placeholders.
- [ ] The sweep has 6 rows × 3 warm runs and a median each.
- [ ] Chosen count = lowest count with median ≤ 1.05 × fastest (check by hand from the table).
- [ ] Folder counts sum to the total; the 12-worker count is compared with 3,597.
- [ ] Split list (or "none") and G2 estimate stated with their thresholds.
- [ ] `git status` shows only `docs/test-suite-performance.md` (new) and `.gitignore` (modified); `storage/test-reports/` is not listed.
- [ ] Dev DB fingerprint unchanged.
- [ ] Pint is not needed (no PHP changed); the suite itself was the 24 runs.
