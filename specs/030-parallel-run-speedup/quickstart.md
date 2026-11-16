# Quickstart: validate Stage 1

Run from the repository root in Git Bash. Use `php artisan test` / `composer test` with no environment prefix; `phpunit.xml` forces the testing DB and an empty Sentry DSN.

## Pre-flight (before every measured block)

```bash
test -f bootstrap/cache/config.php && echo "ABORT: config cached" && exit 1
docker ps --filter name=hospitality-postgres --format '{{.Names}} {{.Status}}'   # must be Up
php artisan test --filter=EntryPointInventoryTest   # confirms tests reach Hospitality_Ecosystem_testing
```

Take the dev DB's read-only counts (tables, `migrations` rows, `users` rows) before and after, as Stage 0 did. They must match.

## 1. The `composer test` script

```bash
composer test -- --filter=StayListTest     # 8 processes reported; only StayListTest runs
```

Expected: the config clear runs first, the header shows 8 processes, and only that file runs.

## 2. The splits

```bash
php artisan test --filter=CrossTenantRoute          # all four parts plus the coverage test
php artisan test --filter=PermissionAuthorization   # Resources + Actions (+ the untouched RoomType file)
php artisan test --filter=EntryPointInventoryTest   # passes unchanged
```

Expected:

- 316 cross-tenant cases (315 rows + 1 coverage);
- the permission parts together have the same case count as the original file;
- no "Cannot redeclare";
- no risky tests.

**Coverage test negative check** (local only, never committed): temporarily remove one segment from `crossTenantSlices()`. Run `--filter="puts every tenant route"`. It must fail and name that segment's routes. Revert.

## 3. Budgets and exit check

```bash
for k in 1 2 3 4; do
  s=$(date +%s); composer test -- --log-junit=storage/test-reports/s1-r$k.xml; e=$?
  echo "s1-r$k wall=$(( $(date +%s) - s )) exit=$e"
done
```

- `s1-r1` is cold and discarded. A warm run that fails is replaced (`s1-r5`, …).
- From `s1-r2..r4`: the per-file summed times (each part ≤ 75.6 s), the slowest file, the medians, the test count (3,598), and the two budget tests' JUnit times.
- If a budget is raised, commit it, then run the 3 exit-check runs again so §6 describes the final state.

## 4. OPcache (optional)

1. In `C:\laragon\bin\php\php-8.4.16-Win32-vs17-x64\php.ini`, uncomment `zend_extension=opcache` and set `opcache.enable_cli=1`.
2. Run `php -r "var_dump(ini_get('opcache.enable_cli'));"`. Expected: `string(1) "1"`.
3. Repeat step 3 with labels `s1o-r1..r4`.
4. Restore the two lines unless the developer decides to keep them.

## Done when

- §6 of `docs/test-suite-performance.md` meets [contracts/stage-log-section-6.md](contracts/stage-log-section-6.md).
- `./vendor/bin/pint --test` is clean.
- `git diff 029-test-suite-measurement --stat` (the branch point) shows only the files allowed by spec FR-001/FR-008.
