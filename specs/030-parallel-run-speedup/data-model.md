# Data Model: Faster Full Parallel Run (Stage 1)

No database or application model changes. The "entities" are test-suite artefacts and stage-log records.

## Slice map

`crossTenantSlices()` in `tests/Pest.php`: an ordered map from part name to a list of URI segments.

| Field | Type | Rule |
| --- | --- | --- |
| part name | string | One of `Reservations`, `Operations`, `GuestServices`, `Platform`. Equals the `<Part>` in the file name. |
| segments | list of string | First URI segment after `api/` ([research.md](research.md) R1). A segment appears under exactly one part. |

**Segment of a route**: `crossTenantSegment($uri)`. Strip a leading `api/`, then take the text up to the next `/`. So `api/superadmin/users/{user}` gives `superadmin`.

**Invariant** (checked by the coverage test, R2): for each driven rule, every route's segment maps to exactly one part.

## Cross-tenant route row

Built by `crossTenantRoutesFor(string $rule, ?string $slice = null)`.

| Field | Type | Source |
| --- | --- | --- |
| key | `"<METHOD> <uri>"` | `EntryPointDiscovery::tenantRoutes()` |
| method, uri | string, string | the dataset values the tests receive (unchanged) |
| rule | `index` / `bound` / `super_admin` / `foreign_key` / `none` | `crossTenantRuleFor()` (unchanged) |

- `$slice = null`: every row of the rule (used only by the coverage test).
- `$slice = '<Part>'`: the rows whose segment is in that part's list.

The dataset row names (`"GET api/activity"`) do not change, so JUnit names per row stay comparable with Stage 0.

## Part

One new test file.

| Field | Rule |
| --- | --- |
| file | `tests/Feature/<Base><Part>Test.php` |
| header | the original's `use` imports (Pint drops unused ones), `uses(RefreshDatabase::class)`, `beforeEach` |
| blocks | Cross-tenant: all six original `it()` blocks, each `->with()` passing the part's slice name. Permission: a consecutive run of the original blocks (R4). |
| summed time | Median over the exit-check runs; must be ≤ 75.6 s. |

Parts:

| Base | Parts |
| --- | --- |
| `CrossTenantRoute` | `Reservations`, `Operations` (+ coverage test), `GuestServices`, `Platform` |
| `PermissionAuthorization` | `Resources` (holds the permission dataset), `Actions` |

## Run

The same as Stage 0, with `composer test` as the command.

| Field | Rule |
| --- | --- |
| label | `s1-r<k>` (OPcache runs: `s1o-r<k>`) |
| cold/warm | First run of a block is cold and discarded; warm = within 5 min of the previous completed run, same command, Postgres not restarted. |
| wall time | Measured around `composer test`, start to exit. |
| summed time | Sum of JUnit `time` over all test cases. |
| test count | Expected **3,598** (3,597 + 1 coverage test). |
| pass | No failure or error. A failed warm-position run is replaced. |

## Budget observation

For each of the 3 runs and each of the two timed tests: the JUnit `time`. Decision rule (FR-018):

```text
slowest ≥ 3.0  →  parallel budget = ceil(slowest × 1.5 / 0.5) × 0.5
otherwise       →  unchanged (4.0)
```
