# Contract: CLAUDE.md changes (Stage 3)

Find each place by its content (data-model "CLAUDE.md change site"). Every line not named here stays byte for byte.

## 1. Commands block

Replace:

```text
php artisan test                                # or: php artisan test --filter=SomeTest
```

with these four lines, and align the comments with each other:

```text
php artisan test tests/Feature/<Module> --parallel --processes=4   # after each edit (one module)
php artisan test tests/Feature/<A> tests/Feature/<B>               # after an edit touching several modules (serial; parallel accepts one path)
composer test                                                      # full parallel run with the invariant tests, before a commit
php artisan test --filter=SomeTest                                 # one test
```

The neighbouring lines (`composer dev`, `docker compose ...`, `php artisan migrate --seed`, `./vendor/bin/pint`) keep their text. Their comment column may be widened to match the new lines; only whitespace changes there.

## 2. Testing section

**Rewrite** the first bullet:

```markdown
- Pest, feature tests in `tests/Feature/<Module>/`, one file per controller or service, in its module's folder.
```

**Insert** these lines directly after that bullet:

```markdown
- Module folders (after an edit, run the folder that covers the feature you changed):

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
  | `Superadmin/` | `/api/superadmin` |
  | `Invariants/` | The invariant tests — not a module; runs in the full run only |

- Test loop:
  - After an edit, run the module run for the folder whose feature you changed.
  - If the changed file serves more than one module, or you are unsure: search
    `tests/Feature` for the class name and run every folder that mentions it, or
    run `composer test`.
  - If you changed shared test setup (`tests/Pest.php`, `tests/TestCase.php`,
    `phpunit.xml`, `composer.json`/`composer.lock`, `config/`, `bootstrap/`,
    `database/`), run `composer test` instead of a module run.
  - A run that reports zero tests is a failure, not a pass (usually a wrong folder name).
  - If a test run stops with the cached-config message, run `php artisan config:clear`
    and rerun. Never delete the guard.
  - New test files go in their module's folder; never directly under `tests/Feature/`.
```

The rest of the section stays unchanged and in its current order, including the `uses(RefreshDatabase::class)`, API key, shared fixtures, `UserFactory`, tenant-isolation and permission bullets, and their paths.

## 3. Git section

Replace:

```markdown
- Run `./vendor/bin/pint` and `php artisan test` before proposing a commit.
```

with:

```markdown
- Run `./vendor/bin/pint` and `composer test` before proposing a commit.
- Fix failures inside the changed module and rerun.
- Report failures outside it instead of editing unrelated code.
- Never edit an invariant test only to make it pass.
- If Postgres is not reachable, stop and tell the user.
```

The co-author, "commit only when asked" and subject-line bullets stay unchanged.

## Consistency

- `composer test` is the pre-commit run in both the Commands block and the Git section.
- No line says to run plain `php artisan test` (with no path or filter) as a step in the loop.
