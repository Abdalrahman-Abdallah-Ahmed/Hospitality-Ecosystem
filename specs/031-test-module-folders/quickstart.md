# Quickstart: validate Stage 2

Run from the repository root in Git Bash. Use `php artisan test` / `composer test` with no environment prefix; `phpunit.xml` forces the testing DB and an empty Sentry DSN.

## Pre-flight (before every block of runs)

```bash
test -f bootstrap/cache/config.php && echo "ABORT: config cached" && exit 1
docker ps --filter name=hospitality-postgres --format '{{.Names}} {{.Status}}'   # must be Up
```

Take the dev DB's read-only counts (tables, `migrations` rows, `users` rows) as Stages 0–1 did (last: 60, 119, 17). Repeat at the end; they must match.

## 1. Placement (after the move commit)

```bash
ls tests/Feature/*.php 2>/dev/null | wc -l          # 0
for d in tests/Feature/*/; do echo "$(ls "$d"*.php | wc -l) $d"; done   # counts per research R1
grep -rn "__DIR__" tests/Feature                    # only Invariants/CrossTenantRouteTest.php, using dirname(__DIR__, 3)
php artisan test --filter=CrossTenantRouteTest      # passes; dataset still builds
php artisan test --filter=EntryPointInventoryTest   # passes; descriptions found recursively
```

`git show --stat HEAD` for the move commit shows only renames, with `CrossTenantRouteTest` as the one rename that also has a 1-line change.

## 2. Guard (after the guard commit)

```bash
php artisan config:cache
php artisan test;                     echo "exit=$?"   # exit=1, guard message, no tests run
php artisan test tests/Feature/Tasks; echo "exit=$?"   # same
test -f bootstrap/cache/config.php && echo "cache still present (expected)"
composer test                                          # clears cache, full run passes
test -f bootstrap/cache/config.php || echo "cache cleared"
php artisan test tests/Feature/Finance                 # runs normally
```

## 3. Isolation and G2

```bash
for m in Core Rooms Reservations Housekeeping Tasks Activities Concierge Recommendations Knowledge AiPlatform Finance ImportExport Superadmin; do
  for r in 1 2 3 4; do
    s=$(date +%s); php artisan test tests/Feature/$m > storage/test-reports/s2-$m-r$r.txt 2>&1; e=$?
    echo "$m r$r wall=$(( $(date +%s) - s )) exit=$e $(grep -E 'Tests:' storage/test-reports/s2-$m-r$r.txt | tail -1)"
  done
done
s=$(date +%s); php artisan test tests/Feature/Invariants; echo "Invariants wall=$(( $(date +%s) - s ))"
php artisan test tests/Feature/Tasks tests/Feature/Finance       # two-path check: count = Tasks + Finance
```

- `r1` is the isolation run (pass, non-zero count) and the cold run; `r2..r4` are warm. A failing run is replaced and its cause recorded.
- If the slowest median is ≥ 180 s: run that module with `--parallel --processes=4`, 1 cold + 3 warm (`s2-q2-r1..r4`). If still ≥ 180 s, revise G2 in §7 with the reason.

## 4. Paths (after the path-reference commit)

```bash
grep -rnE "tests/Feature/[A-Za-z]+Test\.php" CLAUDE.md docs deploy --include=*.md \
  | grep -vE "docs/(PGRIP_|designs/)"      # every hit names a folder that exists
php artisan test --filter=QueueLaneTest    # passes
```

## 5. Exit

```bash
composer test            # passes, Tests: 3,598
./vendor/bin/pint --test # clean
git diff 23b39bb --stat -- app routes config database phpunit.xml composer.json .github   # empty
```

Done when §7 meets [contracts/stage-log-section-7.md](contracts/stage-log-section-7.md).
