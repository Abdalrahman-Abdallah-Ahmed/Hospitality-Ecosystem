# Data Model: Stage 2

No database or application data changes. These are the records the stage produces and checks.

## Module folder

| Field | Meaning |
| --- | --- |
| name | One of the 14 folders: `Core`, `Rooms`, `Reservations`, `Housekeeping`, `Tasks`, `Activities`, `Concierge`, `Recommendations`, `Knowledge`, `AiPlatform`, `Finance`, `ImportExport`, `Superadmin`, `Invariants` |
| files | Count after the move (research R1); must match |
| tests | Test count from the isolation run; must be > 0 |
| isolation | pass / fail (+ cause) |
| times | 3 warm serial wall times (modules only); Invariants: 1 reference time |
| median | Median of `times` (modules only) |

**Rules**: The sum of `tests` over the 14 folders plus `tests/Unit` equals the full-run count (3,598). `Invariants` is excluded from G2.

## Move entry

`file → folder`, from the design table + Stage 1 update. Every `tests/Feature/*.php` file has exactly one entry; after the move, none remains at the top level.

## Run

| Field | Meaning |
| --- | --- |
| label | `s2-<Folder>-r<n>` for module runs, `s2-full` for the full run, `s2-q2-r<n>` for Q2 runs |
| mode | serial, or parallel with process count |
| cold/warm | First run of a folder (or after a Postgres restart, or a gap over 5 minutes) is cold and discarded |
| wall | Seconds, from a timed shell clock |
| tests, result | From Pest's summary line |

## Guard check

| Step | Expected |
| --- | --- |
| `config:cache` | `bootstrap/cache/config.php` exists |
| `php artisan test` | exit 1, guard message on stderr, no test output |
| `php artisan test tests/Feature/Tasks` | same |
| `composer test` | cache cleared, full run passes |
| after | cache file absent; any command runs |

## Dev database snapshot

`tables`, `migrations` rows, `users` rows — taken before the first run and after the last; must be equal.
