# Research: Super Admin Platform Dashboard

The Technical Context has no open unknowns. These decisions settle the design questions the spec leaves to planning. V numbers refer to [plan-verification.md](plan-verification.md).

Code review later changed four of these, and [plan-verification.md](plan-verification.md#changed-after-code-review) overrules them where they differ: counts caching (R2), where the user rule lives and the query count (R3), the shape of `summary()` (R6), and the per-account hotel lookup (R5).

## R1: The window

**Decision**: `SuperadminDashboardRequest` follows `SuspendRequest` in having `authorize()` return `apiAuth()`, with these rules for both `from` and `to`:

- `sometimes`, `date_format:Y-m-d`, `before_or_equal:today`.

An `after()` hook compares the resolved dates and adds an error on `to` when `to` is before `from`, or later than `from->copy()->addMonthsNoOverflow(12)`. It returns early if a field rule has already failed.

`from()` and `to()` resolve as follows, using the app's UTC clock:

| Sent | `from` | `to` |
| --- | --- | --- |
| Neither | today − 29 days | today |
| `from` | as sent | today |
| `to` | `to` − 29 days | as sent |
| Both | as sent | as sent |

`from()` returns start of day and `to()` returns end of day. Both are `Illuminate\Support\Carbon`, which is what `UsageReport` takes.

**Rationale**: Comparing resolved dates also catches an old `from` sent alone, which `after_or_equal:from` can't. `date_format` is strict, so `2026-1-1` is refused, and only one spelling can reach the cache key. The guard runs before the form request, so a non-super-admin with a bad window gets `403`, not `422`.

**Alternatives**: `after_or_equal:from` in the rules, which skips the comparison when `from` is absent. `addMonths`, which turns 29 February into 1 March (V10).

## R2: The cache

**Decision**: One private controller method does the caching for all three sections:

- The key is `superadmin:dashboard:v1:{section}:{from}:{to}`, with both dates as `Y-m-d`.
- `Cache::remember($key, 900, …)` uses the default store.
- The closure returns the section's arrays plus `from`, `to` and `cached_at`, which is `now()->toIso8601ZuluString()`.

The value holds only arrays and scalars. `describe()` already returns them, and the other sections are built from counts and strings. The test compares a cached response with the first one, byte for byte.

**Rationale**: `cached_at` inside the closure reports when the figures were computed (V13). The caller's identity and headers aren't in the key, because the figures are the same for every super admin and no header changes them (V11). A burst of misses may compute a section twice, which is harmless, so no lock is used.

**Alternatives**: `Cache::flexible`, which would serve older figures than the spec allows. Clearing on writes, which the spec rules out (FR-030).

## R3: Counting suspended users

**Decision**: Count in SQL inside `PlatformSummary`, as two queries over non-super-admin users: all of them, and the suspended ones. `active` is the difference. A user is suspended when:

- **no active hotel is reachable**, and
- **either** some hotel is reachable (so all of them are suspended) **or** their account is suspended.

"Reachable" mirrors `User::accessibleHotelIds()` as a correlated subquery on live hotels:

- **An owner** (`group_role` and `hotel_group_id` both set): `hotels.hotel_group_id = users.hotel_group_id`, and nothing else. A cross-account membership doesn't count for an owner, exactly as in that method.
- **Anyone else**: `hotels.id = users.hotel_id`, or a `hotel_user` row. A direct link with no group role adds nothing.

"Active" within that uses `Hotel::notSuspended()`, so the rule stays in one place. "Their account" mirrors `User::account(withTrashed: true)`: `users.hotel_group_id` when set, or else the group of `users.hotel_id`, reading soft-deleted hotels and accounts.

**Rationale**: `HotelAccess::for` per user costs up to three queries a user. The SQL is one statement, like `HotelGroup::scopeWithUserCount`. It does repeat a rule, so the test builds a user for each row of Story 2 and asserts the count against a real `POST /api/login` for each (V3). If `HotelAccess` changes, that test fails.

**Alternatives**: Calling `HotelAccess` in a loop, which is N+1 and which the spec rules out (FR-016). Loading users, memberships and hotels into PHP and walking them, which is easier to read but holds every user in memory for the same answer. A scope on `User`, which is ruled out because `User` doesn't change, and this is the only caller.

## R4: The other counts and signups

**Decision**:

- **Accounts**: suspended is `HotelGroup::suspended()`, and active is `whereNull('hotel_groups.suspended_at')`, as `AccountController` does.
- **Hotels**: `Hotel::suspended()` and `Hotel::notSuspended()`, which split hotels with no overlap and include a soft-deleted account's suspension. `Hotel::scopeActive` reads `is_active` and isn't used (V9).
- **Signups**: two grouped queries on `DATE(created_at)` inside the window. Accounts use `withTrashed()`, and users exclude super admins. A `CarbonPeriod` walks the window so that every day gets a row, in date order.

**Rationale**: Both timestamps are stored without a time zone, in the app's UTC, so `DATE()` gives the UTC day whatever the database session's time zone is. A year of rows is at most 367, which is small.

**Alternatives**: `generate_series` in SQL, which ties the zero-filling to Postgres for no gain.

## R5: The usage section

**Decision**: `PlatformSummary::usage()` follows `UsageController::index`: one call each to `totals`, `seats($to)` and `aiTotals`, then `HotelGroup::query()->with('hotels:id,hotel_group_id,name')->orderBy('name')`, and `describe()` per account. Each row becomes:

- `hotel_group_id`
- `name`, from `describe()`'s `account`
- `usage`: `seats`, `features` and `recommendations`
- `ai`

The controller then adds the cost to each row inside the cache closure, using `hotel_group_id` to find it, and adds `unattributed`.

**Rationale**: `describe()` is the one place usage is worded, so the dashboard can't disagree with `/usage` (FR-020). Without `seats`, every seat would read as not measured, which is false (V5). `PlatformSummary` stays free of cost data, so `CostVisibilityTest` needs no exception. That test matches substrings, so even the key `ai_cost_usd` must not appear in that class.

**Alternatives**: Adding `name` to `describe()`, which would change `/usage`. New formatting in `PlatformSummary`, which would let the two reports drift apart.

## R6: The cost report

**Decision**: `App\Services\AiCost\AiCostReport::summary(Carbon $from, Carbon $to)` runs one grouped query on `ai_usage_logs`, by `hotel_group_id` with nulls included, selecting the row count, `SUM(cost_usd)`, and a count of rows with `cost_is_estimated`. It returns:

```text
accounts:     { <id>: { ai_cost_usd, cost_basis, estimated_rows_pct } }
unattributed: { calls, ai_cost_usd }
```

An account with no rows gets `0.0` and `AiCostReport::basis(0, 0)`, filled in by the controller's merge. The window filter is `occurred_at`, as `/ai-cost` uses. `ai_cost_usd` is rounded to six decimals, and `estimated_rows_pct` to one.

The labels move to a public static `AiCostReport::basis(int $total, int $estimated): array`, and `AiCostController::basis()` calls it. The controller's output doesn't change, and its existing tests cover that.

**Rationale**: Two copies of the labels could drift, and the spec requires the same definitions (FR-022). One query is enough, unlike the controller, which loads every row because it also needs its breakdowns. The rule that a basis travels with every cost figure comes from that controller (V6).

**Alternatives**: `perAccount()` as designed, which drops the rows with no account. A second method for `unattributed`, which would run the query twice. Copying the labels, which leaves two places to change.

## R7: The activity section

**Decision**: `Hotel::query()->orderBy('name')->orderBy('id')->get(['id', 'hotel_group_id', 'name'])`, plus one grouped count per model:

```text
Reservation / Booking: whereBetween(created_at), groupBy(hotel_id), count → by hotel id
```

A hotel with no rows reads `0`. Soft-deleted hotels are left out by the default scope, and soft-deleted rows by the same mechanism. Status isn't filtered, so cancelled records count (V12).

**Rationale**: Two grouped queries, whatever the number of hotels, as §4 asks. `created_at` is what the spec means by "created", and imports go through `ReservationCreator`, so they count on the import day.

**Alternatives**: `withCount` with a window on `Hotel`, which is one query but needs the relationships' global scopes handled per call. Adding an index on `created_at`, left until someone measures a problem (Alternative K).

## R8: Lifting the tenant scope

**Decision**: Each `PlatformSummary` method and `AiCostReport::summary` wraps its work in `TenantContext::withoutScope()`, even though only `Reservation` and `Booking` are tenant-scoped (V11). A test calls `PlatformSummary::activity()` inside `TenantContext::runForHotel()` and still sees every hotel.

**Rationale**: The request asks for an explicit lift rather than relying on the super admin's null scope. Keeping it in the services means the class is right whoever calls it, and a cached closure can't pick up a narrowed scope. `withoutScope()` restores the previous state, even on an exception.

**Alternatives**: Wrapping in the controller, which leaves the services dependent on their caller.

## R9: Tests

**Decision**: `tests/Feature/Superadmin/DashboardTest.php` covers all stories. `AccessGuardTest`'s `administration routes` dataset gets the three routes, which gives the admin, employee and no-token cases. The owner case goes in `DashboardTest`. Fixtures use `makeHotel()`, `adminOfHotel()` and `apiHeaders()` from `tests/Pest.php`.

Comparisons with `/usage` and `/ai-cost` send explicit `from` and `to`, because their default windows differ (V10). A query-count test adds users and hotels, and asserts that `counts` and `activity` run the same number of queries.

**Rationale**: `EntryPointInventoryTest` and `CrossTenantRouteTest` already discover these routes, so `tests/Inventory/` needs no entry.

**Alternatives**: A separate test file per section, which splits one controller's tests against the repo's one-file-per-controller rule.
