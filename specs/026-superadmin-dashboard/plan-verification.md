# Stage 4 Plan Verification

Checks the Stage 4 plan in `docs/designs/super-admin-platform.md` (lines 1314–1369, plus Goal 6, CUJ 6, §4, Alternatives K and L, R10 and the NFRs) against the code on `main` at `c908cc8`, including what stages 2 and 3 and spec 025 built. The spec follows the "Default used" column, so `/speckit-plan` should follow it too. Overrule any row through `/speckit-clarify`.

## Mismatches and gaps

| # | Plan says | Code shows | Default used | Spec |
| --- | --- | --- | --- | --- |
| V1 | 4.1 and the request: a user is suspended when "their account is suspended, or they have at least one hotel and all of them are". | Login and `EnsureNotSuspended` both use `HotelAccess::for` (`app/Support/Suspension/HotelAccess.php:37-61`), which decides per hotel and reads the account **only when the user reaches no hotel**. Its docblock says why: a user is never refused over the wrong account. The two rules differ for a user linked to a suspended account who also reaches an active hotel in another account. The plan says suspended, but login lets them in. Stage 2 refuses such links, so this is rare. | Clarified 2026-11-08: match `HotelAccess`, so the count always equals what login does. Users have no suspension of their own, and adding one is out of scope. | FR-013, Story 2 #7 |
| V2 | 4.1: "they have at least one hotel". | Since spec 025 a user reaches hotels three ways (`User::accessibleHotelIds`, `app/Models/User.php:246-265`). An owner reaches **every live hotel of the account**, with no home or membership row needed. Anyone else reaches only their live home hotel and live memberships, and a direct `hotel_group_id` with no `group_role` adds nothing (spec 025 edge case). Counting home plus pivot gets owners wrong. Counting `users.hotel_group_id` as reach gets role-less linked users wrong. Soft-deleted hotels must be left out. `User::account()` is not the answer either, because it prefers the direct link. | "Hotels" means what `accessibleHotelIds()` returns. Story 2 has a scenario for each route. | FR-014, Story 2 #1, #3, #5 |
| V3 | 4.1 gives the rule but not how to count. | `HotelAccess::for` plus `accessibleHotelIds()` costs up to three queries per user. Per user, a super admin's page load would run a query for each of the platform's users. | Count in a few set-based queries, with no per-user query. Since this reimplements the rule, a test compares the result with a real `apiLogin` outcome for each kind of user, so the two can't drift apart unnoticed. | FR-016, FR-032, SC-002 |
| V4 | 4.5's test list: counts, signups, activity, default window, range rejected, cache hit, 403 for an admin. | It leaves out every case above, the usage section entirely (the cost merge, the match with the existing reports), `X-Hotel-Id`, and a `to`-only window. | Tests cover all acceptance scenarios. | FR-032 |
| V5 | §4 and 4.1: `usage` wraps `totals()` and `aiTotals()`. 4.2: `{hotel_group_id, name, usage, ai, ai_cost_usd}`. | The only public formatter is `UsageReport::describe()` (`app/Services/Metering/UsageReport.php:91-107`), whose `everyFeature`/`everySeat` are private. It also takes `seats` and returns `account`, not `name`. Passing no seats reports every seat as `measured: false`, which is false. `AiUsageSummary::describe` reads `$account->hotels` (`:87`), so one query per account unless eager-loaded. `describe()`'s output is already plain arrays. | Do what `UsageController` does: call `totals`, `seats` and `aiTotals` once, eager-load `hotels:id,hotel_group_id,name`, and call `describe()`. Map it to `name`, `usage: {seats, features, recommendations}` and `ai`. Leaves `UsageReport` unchanged. | FR-019, FR-020 |
| V6 | 4.1 and 4.2: `AiCostReport::perAccount` returns USD cost keyed by `hotel_group_id`, shown as a bare `ai_cost_usd`. | `AiCostController` holds this as a stated reporting rule: "Every figure carries its basis" (`:24-26`, `basis()` at `:233-247`). An unpriced model's cost reads `0`, and "a zero that means not priced must never be read as free". `ai_usage_logs.hotel_group_id` is nullable, and `/ai-cost` reports those rows as `unattributed` (`:71`), so a report keyed by account drops real spend silently. | Clarified 2026-11-08: add `cost_basis` and `estimated_rows_pct` per account, and a top-level `unattributed: {calls, ai_cost_usd}`, beyond the plan's shape. EUR, margin and the breakdowns stay on `/ai-cost`. | FR-019, FR-022, FR-023 |
| V7 | Doesn't say what happens to soft-deleted accounts. | `/usage` and `/ai-cost` list only `HotelGroup::query()`, so a deleted account's cost is in neither its own row nor `unattributed`. Deleting needs no live hotels (`AccountAdministration.php:50`), and the logs stay. | Match the existing reports, because the figures must agree. Listed as a known limit. Fixing it belongs in `/ai-cost` too. | FR-019, Known Limits |
| V8 | §4: "Signups are accounts created and users created." | Registration yields one account (through the `Hotel::creating` hook) and one user, so it counts as the request asks (`RegisterUserController.php:47-74`). But nothing records origin, so super admin accounts (`AccountAdministration.php:25`) and all staff count too. Accounts soft-delete but users hard-delete (`User` has no `SoftDeletes`), so history can shrink for users. A hotel move leaves an empty account (`HotelGroup.php:104-106`). The plan doesn't mention days with no signups. | Clarified 2026-11-08: count all, as the plan says, with no origin column. Include soft-deleted accounts, so a past day's figure doesn't fall when an account is deleted. Fill empty days with zeros. Empty accounts count, as they do in `/superadmin/accounts`. | FR-017, FR-018, Known Limits |
| V9 | "Active vs suspended" for all three; CUJ 6 gives no more detail. | `is_active` exists on hotels and accounts, and `Hotel::scopeActive` reads it, but spec 024 keeps it separate from suspension. Users have no disabled state: `revokeAccess` only deletes tokens (`UserAdministration.php:201-207`), and a user with no hotels and no account isn't refused. `HotelGroup::scopeWithUserCount` (`:73-96`) counts per account, including attachment through an owner's account, so the platform total can't be its sum; metering seats differ again. | "Active" means not suspended. `is_active` is ignored, and `Hotel::scopeActive` isn't used. Unattached and revoked users count as active. Documented as a known limit. | FR-010, FR-015, Known Limits |
| V10 | 4.3: 30 days ending today, `to` ≥ `from`, `to` ≤ `from.addMonths(12)`. | "30 days ending today" could mean 30 or 31 dates. One date on its own isn't covered. Carbon's `addMonths(12)` turns 29 Feb into 1 Mar. Future dates aren't covered: `seats($to)` reads a future period with no counters, so all seats would read as not measured, and any future date adds another cache entry. `/usage` and `/ai-cost` default to a different window (the start of the month), and `/ai-cost` to the end of the month, so a dashboard figure only matches them with explicit dates. | 30 dates counting today (`today - 29`). `from` alone runs to today, and `to` alone starts 29 days earlier. Use `addMonthsNoOverflow`. Compare the resolved dates, so an old `from` sent alone is also refused. Clarified 2026-11-08: reject dates after today with `422`, and the frontend caps its date picker at today. | FR-007, FR-008 |
| V11 | The request and 4.1: run under `withoutScope()`. CLAUDE.md: the super admin stack is "the same stack plus `super_admin`". | Of the models read, only `Reservation` and `Booking` use `BelongsToHotel`. `Hotel`, `HotelGroup`, `User`, `MeterEvent`, `UsageCounter` and `AiUsageLog` are not tenant-scoped, so `withoutScope` only matters for activity, though it's harmless elsewhere. The stack in `bootstrap/app.php:38` has **no `not_suspended` or `hotel_choice`**. `ResolveTenant:26-35` gives a super admin a null scope, and `X-Hotel-Id` moves only `currentHotelId`. So no header can narrow the figures or poison the shared cache. **CLAUDE.md's line is out of date.** It isn't changed here. | Run each section's work inside `withoutScope()` as asked, `AiCostReport` included. Test with `X-Hotel-Id`, and call `PlatformSummary` directly under a narrowed `TenantContext`. | FR-005, Story 7 #3 |
| V12 | 4.2: `hotels: [{…reservations, bookings}]`, "created per hotel". | The plan doesn't say whether hotels with zero, suspended hotels or soft-deleted rows count. Only one place creates reservations (`ReservationCreator.php:157`), and imports go through it, so `created_at` is the import time. `reservations` has no index on `created_at`; `bookings` has `(guest_id, created_at)` only. Alternative K says speed hasn't been measured. | List every live hotel, suspended ones included, with zeros. Count live rows of any status by `created_at`. Add no index. Listed as known limits. | FR-025, FR-026, Known Limits |
| V13 | 4.2: `cached_at` stored inside the closure. | Correct, and the frozen-time test in 4.5 proves it. Its format isn't stated. | ISO 8601 in UTC, from `now()` inside the closure. | FR-029 |

## Changed after code review

These came from `/code-review` on the first implementation, and overrule the rows above where they differ.

| # | Finding | Decision | Spec |
| --- | --- | --- | --- |
| V14 | `unattributed` had no basis, so 500 unpriced calls read as free. | It carries `cost_basis` and `estimated_rows_pct`. | FR-023 |
| V15 | A soft-deleted account's cost silently dropped out of the totals (V7). | Report it as one `deleted_accounts` total. Unlike `/ai-cost`, which doesn't report it. | FR-023a |
| V16 | With the window in the key, two date ranges could show different current counts, and each new range recounted users. | **Differs from the requested key rule.** `accounts`, `hotels` and `users` are cached under `superadmin:dashboard:v1:counts:current`. The requested key still holds the per-window part, `signups`. `cached_at` is the older of the two entries. | FR-027, FR-029 |

Other fixes changed no contract:

- The window is resolved once per request.
- A too-old `from` sent alone is reported under `from`.
- `AiUsageSummary::totals` selects each hotel's name in its rows, removing the per-account lookup. This also applies to `/usage`, `/api/usage` and the Admin AI's report.
- User counts come from one SQL statement, through the new `User::scopeRefusedBySuspension`, kept beside `accessibleHotelIds()`.
- `AiCostReport::money()` is shared with `AiCostController`.
- `CLAUDE.md` records that these report endpoints use plain arrays, and that the dashboard controller adds the cost.

Not done, and why:

- **Moving the cost merge out of the controller**: the request asks for it to be merged there.
- **`AiCostController` taking its totals from `AiCostReport`**: it needs row-level breakdowns anyway, so this would be a larger refactor of a working report. The two share `basis()` and `money()`, and a test compares their figures.
- **A lock around recomputes**: nothing has been measured, and two simultaneous recomputes are harmless. A lock would add a failure mode.

## Implementation constraints for `/speckit-plan`

From the request and the design, and kept out of the spec because they name classes:

- New: `App\Services\Reports\PlatformSummary` (`counts`, `usage`, `activity`), `App\Services\AiCost\AiCostReport`, `App\Http\Controllers\Superadmin\DashboardController`, `App\Http\Requests\Superadmin\SuperadminDashboardRequest`, `tests/Feature/Superadmin/DashboardTest.php`, `docs/superadmin-dashboard-api-documentation.md`.
- `PlatformSummary` must contain none of `AiUsageLog`, `AiModelPrice`, `cost_usd`, `price_per_million`, `contract_value` or `gross_margin` in code. The check is a substring match, so `ai_cost_usd` also fails it (`tests/Feature/CostVisibilityTest.php:321`). The controller adds the cost, and both it and `AiCost/` are on that test's allowlist, so the test needs no change.
- `AiCostReport` should aggregate in SQL rather than load rows the way `AiCostController` does. The `basis` labels move to `AiCostReport`, and that controller calls them there with no change in output (research R6). Six-decimal rounding matches it.
- Hotels use the existing `Hotel::notSuspended()` and `Hotel::suspended()`, which split hotels with no overlap and read a soft-deleted account too. Accounts use `HotelGroup::suspended()` and `whereNull('hotel_groups.suspended_at')`, as `AccountController` does.
- Signup days group on the stored UTC `created_at`.
- Responses go through `apiResponse()` with plain arrays and no Resource, as `UsageController` and `AiCostController` do. The value is cached as plain arrays anyway.
- `TenantContext`, `HotelAccess` and `UsageReport` don't change. `User` and `AiUsageSummary` changed after code review (see above).

## Checked and correct

- **Line references**: every section the request cites is at the lines given.
- **Suspension rules**: `Hotel::isSuspended()`, `scopeNotSuspended()` and `scopeSuspended()` exist and read `withTrashed` accounts (`app/Models/Hotel.php:304-360`).
- **Cost placement**: `CostVisibilityTest` allows `app/Services/AiCost/` and `app/Http/Controllers/Superadmin/`, and doesn't allow `app/Services/Reports/` (`:306-319`). `UsageReport` and `AiUsageSummary` are already clean, so `PlatformSummary` can call them.
- **Plain arrays**: the rule is the one `App\Http\Controllers\DashboardController` documents (`:44-49`), and `cache.serializable_classes` is why it matters.
- **Cache store**: the default store is `database`, and tests use `array` (`phpunit.xml:31`), which works with frozen time.
- **UTC**: `config/app.php:72` is hard-coded to `UTC`, and timestamps are stored without a time zone.
- **Routes**: nothing else uses `/dashboard` in `routes/superadmin.php`, and the tenant `GET /api/dashboard` is separate. The class name doesn't clash, because the namespaces differ.
- **Inventory**: `EntryPointDiscovery` picks up routes with `super_admin` (`tests/Support/EntryPointDiscovery.php:36`), and `crossTenantRuleFor` handles them (`tests/Pest.php:1933`). So `CrossTenantRouteTest` covers them with no entry in `tests/Inventory/`.
- **Permissions**: no enum case, because the guard is the authorization, as for `/usage` and `/ai-cost`.
- **Docs name**: `docs/superadmin-dashboard-api-documentation.md` matches `docs/superadmin-accounts-api-documentation.md`.
- **Registration**: `POST /api/register` makes the account and an owner in one transaction, so both appear in that day's signups.
- **Only two other places create accounts or hotels**: `AccountAdministration` and `HotelAdministration`, plus the benchmark seeding command.
- **Alternative L**: the dashboard reads no revenue, `failed_jobs` or WhatsApp health.
