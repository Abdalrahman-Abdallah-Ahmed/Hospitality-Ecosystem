---

description: "Task list for the super admin platform dashboard"
---

# Tasks: Super Admin Platform Dashboard

**Input**: Design documents from `specs/026-superadmin-dashboard/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [plan-verification.md](plan-verification.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/dashboard.md](contracts/dashboard.md), [quickstart.md](quickstart.md)

**Tests**: Included. `CLAUDE.md` and the constitution require them, and FR-032 and [quickstart.md](quickstart.md) name what they must prove.

**Organization**: Tasks are grouped by user story, with the P1 stories first. Phase 2 creates the window validation and the cache helper, and each section's story adds its route and service method. All tests go in one file, so tasks in that file aren't marked parallel.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: The user story in [spec.md](spec.md) this task belongs to

## Rules for every task

- `TenantContext`, `HotelAccess` and `UsageReport` don't change. (`User` and `AiUsageSummary` changed in Phase 11, after code review.) No migration, no permission case, no cache clearing.
- `app/Services/Reports/PlatformSummary.php` must not contain `AiUsageLog`, `AiModelPrice`, `cost_usd`, `price_per_million`, `contract_value` or `gross_margin` in code. The check is a substring match, so the key `ai_cost_usd` also fails it. `CostVisibilityTest` must pass with no edits.
- Never add revenue, price or currency to a response.
- Controllers call no tenant policy, and return through `apiResponse()` with plain arrays. Cached values are arrays and scalars only.
- Feature tests: `uses(RefreshDatabase::class)`, set the API key in `beforeEach` (`putenv('API_KEY=test-api-key')` and `config(['app.api_key' => 'test-api-key'])`), and use `makeHotel()`, `adminOfHotel()` and `apiHeaders()` from `tests/Pest.php`. Set a record's `created_at` with `$this->travelTo()` or `forceFill`. Call `forgetGuards()` between token requests in one test. Run with `php artisan test --parallel`.
- Write each story's tests first, and see them fail before implementing it. T020, T021, T027 and T028 are exempt, because Phase 2 or the route guard already implements what they test.
- Run `./vendor/bin/pint` before proposing a commit. Commit only when asked.

---

## Phase 1: Setup

**Purpose**: Branch and baseline

- [X] T001 Create and switch to branch `026-superadmin-dashboard` from `main`, bringing the untracked `specs/026-superadmin-dashboard/` with it
- [X] T002 Check `docker ps -a` first. If `hospitality-postgres` is running, use it. Run `docker compose -f .postgres/compose.yaml up -d` only if it isn't, and not while another project's `pilot-postgres` is in use, because they share a volume. Then run `php artisan test --parallel` for a baseline, and note any existing failures

---

## Phase 2: Foundational

**Purpose**: What every section uses. **No story can start until this phase is done.**

- [X] T003 Create `app/Http/Requests/Superadmin/SuperadminDashboardRequest.php` per research R1. `authorize()` returns `apiAuth()`, as in `SuspendRequest`. `from` and `to` each have `sometimes`, `date_format:Y-m-d`, `before_or_equal:today`. An `after()` hook returns early if a field rule failed, and otherwise adds an error on `to` when the resolved `to` is before the resolved `from`, or later than `from->copy()->addMonthsNoOverflow(12)`. `from()` and `to()` return `Illuminate\Support\Carbon` at start and end of day: neither sent gives today − 29 days to today, `from` alone runs to today, and `to` alone starts 29 days earlier
- [X] T004 Create `app/Http/Controllers/Superadmin/DashboardController.php` with a private `section(string $section, SuperadminDashboardRequest $request, callable $compute): array`. It calls `Cache::remember("superadmin:dashboard:v1:{$section}:{$from}:{$to}", 900, ...)` with both dates as `Y-m-d`, and the closure returns `$compute()`'s array plus `from`, `to` (`Y-m-d`) and `cached_at` (`now()->toIso8601ZuluString()`). Add a class docblock saying the route guard is the authorization and these figures can be 15 minutes old
- [X] T005 Create `app/Services/Reports/PlatformSummary.php` as an empty class with a docblock naming the `CostVisibilityTest` rule, and `tests/Feature/Superadmin/DashboardTest.php` with `uses(RefreshDatabase::class)`, the API key `beforeEach`, and a file-local helper for a super admin request with `apiHeaders()`

**Checkpoint**: Each story can add its route and service method.

---

## Phase 3: User Story 1 - See how many customers there are and who is stopped (Priority: P1)

**Goal**: A super admin reads accounts and hotels as active vs suspended, and signups per day.

**Independent Test**: Create accounts and hotels in a known mix of suspended states, call counts, and compare every figure.

### Tests for User Story 1

- [X] T006 [US1] In `tests/Feature/Superadmin/DashboardTest.php`, add tests for Story 1 scenarios 1 to 6: the response shape; a super admin in no figure; an active account with one suspended hotel (account active, hotels 1 and 1); a suspended account with two hotels (all suspended); `POST /api/register` adds one account and one user to that day's signups; and a row for every day of the window, with zeros
- [X] T007 [US1] In the same file, add edge case tests: a soft-deleted account leaves `accounts` but stays in its creation day's signups; a soft-deleted hotel isn't counted; `is_active` off still counts as active; an account with no hotels counts; an empty platform gives zeros; changing the window doesn't change `accounts` or `hotels`

### Implementation for User Story 1

- [X] T008 [US1] In `app/Services/Reports/PlatformSummary.php`, add `counts(Carbon $from, Carbon $to): array`, wrapped in `TenantContext::withoutScope()`, returning `accounts`, `hotels`, `users` and `signups` per research R4. Accounts: `HotelGroup::suspended()` and `whereNull('hotel_groups.suspended_at')`. Hotels: `Hotel::suspended()` and `Hotel::notSuspended()`, never `scopeActive`. For now, `users` is `{active: total non-super-admins, suspended: 0}`, which T012 replaces
- [X] T009 [US1] In the same file, add the signups: grouped `DATE(created_at)` counts inside the window, using `HotelGroup::withTrashed()` for accounts and non-super-admins for users, then a `CarbonPeriod` over the window building one `{date, accounts, users}` row per day in date order
- [X] T010 [US1] In `app/Http/Controllers/Superadmin/DashboardController.php`, add `counts(SuperadminDashboardRequest $request, PlatformSummary $summary)`, returning `apiResponse('Dashboard counts fetched successfully.', 200, ...)` through `section()`. In `routes/superadmin.php`, add `Route::get('/dashboard/counts', ...)` with a one-line comment naming this spec

**Checkpoint**: Accounts, hotels and signups work.

---

## Phase 4: User Story 2 - Count suspended users the way access actually works (Priority: P1)

**Goal**: A user counts as suspended exactly when suspension would refuse them at login.

**Independent Test**: Build one user per row, and compare each count with a real login attempt.

### Tests for User Story 2

- [X] T011 [US2] In `tests/Feature/Superadmin/DashboardTest.php`, add a dataset with one row per kind of user from Story 2 and [quickstart.md](quickstart.md): owner with home hotel suspended, another account hotel active and no membership row (active); owner with every account hotel suspended (suspended); direct account link with no group role, home hotel suspended and other account hotels active (suspended); home hotel suspended and member of an active hotel (active); only hotel soft-deleted and account suspended (suspended); no hotels and no account (active); linked to a suspended account and reaching an active hotel in another account (active); owner of a suspended account with no hotels (suspended). Each row asserts the `users` count, and a real `POST /api/login` with the known password: `403` must match suspended, and `200` active. Add a test that revoking tokens through `POST /api/superadmin/users/{user}/revoke-tokens` doesn't change the count

### Implementation for User Story 2

- [X] T012 [US2] In `app/Services/Reports/PlatformSummary.php`, replace the `users` figure per research R3 and the table in [data-model.md](data-model.md): two queries over non-super-admins, total and suspended, with `active` as the difference. A user is suspended when no active hotel is reachable, and either some hotel is reachable or their account is suspended. "Reachable" is a correlated subquery on live hotels: an owner (`group_role` and `hotel_group_id` both set) reaches `hotels.hotel_group_id = users.hotel_group_id` and nothing else; anyone else reaches `hotels.id = users.hotel_id` or a `hotel_user` row. "Active" uses `Hotel::notSuspended()`. "Account" is `users.hotel_group_id` when set, else the group of `users.hotel_id`, reading soft-deleted hotels and accounts. No per-user queries (FR-016), and no change to `User`
- [X] T013 [US2] In `tests/Feature/Superadmin/DashboardTest.php`, add a test that `counts` runs the same number of queries after adding more users and hotels, with an empty cache (`DB::enableQueryLog()`)

**Checkpoint**: The user figure matches login for every kind of user, and the counts section is complete.

---

## Phase 5: User Story 3 - Compare usage and AI cost across accounts (Priority: P1)

**Goal**: A super admin sees each account's usage and its AI cost in USD, with the cost's basis.

**Independent Test**: Record usage and cost for two accounts, and compare with `/usage` and `/ai-cost` for the same window.

### Tests for User Story 3

- [X] T014 [US3] In `tests/Feature/Superadmin/DashboardTest.php`, add tests for Story 3 scenarios 1 to 6: the row shape, with `usage` holding `seats`, `features` and `recommendations`; `usage`, `ai` and `ai_cost_usd` equal to `GET /api/superadmin/usage` and `GET /api/superadmin/ai-cost` with the same explicit `from` and `to`; an account with no activity reads `0` and `no data`; a call with `cost_is_estimated` gives `estimated` or `mixed`, with the right `estimated_rows_pct`; a call with a null `hotel_group_id` appears only in `unattributed`; and a soft-deleted account isn't listed. Create logs with `AiUsageLog::create`, following `aiBlockCall` in `tests/Feature/AccountUsageAiBlockTest.php`
- [X] T015 [US3] In the same file, add a test that the query count for `usage` doesn't grow with the number of accounts, using data with no hotels moved between accounts, because each such hotel adds one existing lookup. Add tests that: a seat a recount has written reads as measured, not `null`; a seat with no counter for the month of `to` reads `null` with `measured: false`; a moved hotel's earlier usage stays with the account that incurred it; and a soft-deleted hotel's AI usage stays in its account's `ai`

### Implementation for User Story 3

- [X] T016 [P] [US3] Create `app/Services/AiCost/AiCostReport.php` per research R6. `summary(Carbon $from, Carbon $to): array`, wrapped in `TenantContext::withoutScope()`, runs one query on `AiUsageLog` filtered on `occurred_at` and grouped by `hotel_group_id`, with nulls, selecting the row count, `SUM(cost_usd)` and a count of `cost_is_estimated` rows. It returns `accounts` (keyed by id: `ai_cost_usd` rounded to six decimals, `cost_basis`, `estimated_rows_pct`) and `unattributed` (`calls`, `ai_cost_usd`). Add a public static `basis(int $total, int $estimated): array` returning `cost_basis` (`no data`, `measured`, `estimated`, `mixed`) and `estimated_rows_pct` (one decimal), with the logic from `AiCostController::basis()`
- [X] T017 [US3] In `app/Http/Controllers/Superadmin/AiCostController.php`, change the private `basis()` to call `AiCostReport::basis($rows->count(), $rows->where('cost_is_estimated', true)->count())`, with no other change
- [X] T018 [US3] In `app/Services/Reports/PlatformSummary.php`, add `usage(Carbon $from, Carbon $to): array`, wrapped in `TenantContext::withoutScope()`, following `UsageController::index`: one call each to `UsageReport::totals`, `seats($to)` and `aiTotals`, then `HotelGroup::query()->with('hotels:id,hotel_group_id,name')->orderBy('name')`, and `describe()` per account. Return a list of `{hotel_group_id, name, usage: {seats, features, recommendations}, ai}`, with `name` from `describe()`'s `account`. Inject `UsageReport` through the constructor
- [X] T019 [US3] In `app/Http/Controllers/Superadmin/DashboardController.php`, add `usage(SuperadminDashboardRequest $request, PlatformSummary $summary, AiCostReport $cost)`, returning `apiResponse('Dashboard usage fetched successfully.', 200, ...)` through `section()`. Inside the closure, add each account's cost by `hotel_group_id`, using `AiCostReport::basis(0, 0)` and `0.0` for an account with no rows, and add `unattributed`. In `routes/superadmin.php`, add `Route::get('/dashboard/usage', ...)`

**Checkpoint**: The usage section matches both existing reports, and `CostVisibilityTest` and `AiCostAttributionTest` pass unedited.

---

## Phase 6: User Story 7 - Only super admins can read it (Priority: P1)

**Goal**: No customer can reach any figure, and no request can narrow the figures.

**Independent Test**: Call all three sections as an admin, an owner, an employee and with no token.

**Note**: The `activity` route arrives in Phase 7, so T020's row for it and T021's activity assertion fail until T026 is done. Run this phase's tests again after it.

### Tests for User Story 7

- [X] T020 [P] [US7] In `tests/Feature/Superadmin/AccessGuardTest.php`, add `GET /dashboard/counts`, `/dashboard/usage` and `/dashboard/activity` to the `administration routes` dataset, which gives the admin, employee and no-token cases
- [X] T021 [US7] In `tests/Feature/Superadmin/DashboardTest.php`, add tests that: an account owner gets `403` on all three; an admin with an invalid window gets `403`, not `422`; a super admin sending `X-Hotel-Id` gets platform-wide figures, and the same figures as without it; and `PlatformSummary::activity()` and `counts()` called inside `TenantContext::runForHotel()` still return every hotel's data. Then confirm `CrossTenantRouteTest` and `EntryPointInventoryTest` pass with the new routes, with no entry in `tests/Inventory/`

**Checkpoint**: Access is proven. There's no implementation task: the file-level guard already gives this.

---

## Phase 7: User Story 4 - See which hotels are in use (Priority: P2)

**Goal**: A super admin sees reservations and bookings created per hotel.

**Independent Test**: Create records in two accounts' hotels, inside and outside the window, and compare the counts.

### Tests for User Story 4

- [X] T022 [US4] In `tests/Feature/Superadmin/DashboardTest.php`, add tests for Story 4 scenarios 1 to 5: the shape across two accounts; a hotel with nothing reads zeros; a suspended hotel is listed; records outside the window don't count; and a scan of each of the three sections' encoded responses finds no `revenue`, `price`, `currency` or `amount`. Add a test that reading all three sections writes no `EventLog` row
- [X] T023 [US4] In the same file, add edge case tests: a cancelled reservation counts; a soft-deleted reservation doesn't; a soft-deleted hotel isn't listed; and a moved hotel shows under its current account. Add a test that the query count doesn't grow with the number of hotels

### Implementation for User Story 4

- [X] T024 [US4] In `app/Services/Reports/PlatformSummary.php`, add `activity(Carbon $from, Carbon $to): array`, wrapped in `TenantContext::withoutScope()`, per research R7: `Hotel::query()->orderBy('name')->orderBy('id')->get(['id', 'hotel_group_id', 'name'])`, plus one grouped count each on `Reservation` and `Booking`, using `whereBetween('created_at', [$from, $to])` grouped by `hotel_id`. Return a list of `{hotel_id, hotel_group_id, name, reservations, bookings}` with integer counts, and zero where there's none
- [X] T025 [US4] In `app/Http/Controllers/Superadmin/DashboardController.php`, add `activity(SuperadminDashboardRequest $request, PlatformSummary $summary)`, returning `apiResponse('Dashboard activity fetched successfully.', 200, ...)` through `section()`
- [X] T026 [US4] In `routes/superadmin.php`, add `Route::get('/dashboard/activity', ...)`, then re-run `AccessGuardTest` and the Phase 6 tests

**Checkpoint**: All three sections work.

---

## Phase 8: User Story 5 - Choose the window (Priority: P2)

**Goal**: The default window and chosen windows behave as the contract says.

**Independent Test**: Call a section with no dates, each date alone, a 12-month range and a longer one.

### Tests for User Story 5

- [X] T027 [US5] In `tests/Feature/Superadmin/DashboardTest.php`, with time frozen, add tests for Story 5 scenarios 1 to 7: no dates gives today − 29 days to today; `from` alone runs to today; `to` alone starts 29 days earlier; exactly 12 months succeeds; one day more gets `422`; `to` before `from`, an empty value (`from=`), a wrong format (`2026-1-1`), a date after today, and an old `from` sent alone all get `422`; a window starting 29 February allows up to 28 February the next year and refuses 1 March; records at `00:00:00` on `from` and `23:59:59` on `to` count, and those a second outside don't. Run these for all three sections through a dataset where the behaviour is shared

**Checkpoint**: T003 already implements this. Fix the request if any case fails.

---

## Phase 9: User Story 6 - Know how old the figures are (Priority: P2)

**Goal**: Figures are reused for 15 minutes, and say when they were computed.

**Independent Test**: Read a section, change the data, and read again with time frozen, then after 15 minutes.

### Tests for User Story 6

- [X] T028 [US6] In `tests/Feature/Superadmin/DashboardTest.php`, with time frozen and `config(['cache.stores.array.serialize' => true])` plus `Cache::forgetDriver('array')`, so values are serialized as in production's database store and `cache.serializable_classes` applies, add tests for Story 6 scenarios 1 to 5, for each section: a second read after a data change returns the same figures and the original `cached_at`; a read just after 900 seconds returns new figures and a new `cached_at`; suspending an account doesn't clear `counts`; two windows and two sections each get their own entry and `cached_at`; `Cache::get` of the exact key `superadmin:dashboard:v1:{section}:{from}:{to}` returns an array with no objects at any depth; and the first and cached responses are identical, byte for byte, including `usage`'s nested fields

**Checkpoint**: T004 already implements this. Fix the helper if any case fails.

---

## Phase 10: Polish & Cross-Cutting

**Purpose**: Documentation and final checks

- [X] T029 [P] Create `docs/superadmin-dashboard-api-documentation.md` from [contracts/dashboard.md](contracts/dashboard.md), in the style of `docs/superadmin-accounts-api-documentation.md`: required headers, the three endpoints, query parameters, response shapes, the 15-minute cache, what "active", "suspended" and "signup" mean, and the known limits from [spec.md](spec.md), including the two booking figures (FR-031)
- [X] T030 Walk through [quickstart.md](quickstart.md) and confirm every scenario has a test
- [X] T031 Run `./vendor/bin/pint` and `php artisan test --parallel`. Compare with the T002 baseline, and confirm `CostVisibilityTest`, `AiCostAttributionTest`, `RouteMoveTest`, `CrossTenantRouteTest` and `EntryPointInventoryTest` pass with no edits to them

---

## Phase 11: Code Review Fixes

**Purpose**: Findings from `/code-review`. Decisions are in [plan-verification.md](plan-verification.md#changed-after-code-review).

- [X] T032 In `app/Services/AiCost/AiCostReport.php`, give `unattributed` a cost basis and add a `deleted_accounts` total (V14, V15). Add `money()`, and have `app/Http/Controllers/Superadmin/AiCostController.php` call it
- [X] T033 In `app/Http/Controllers/Superadmin/DashboardController.php` and `app/Services/Reports/PlatformSummary.php`, cache current counts under one key and signups per window, reporting the older `cached_at` (V16)
- [X] T034 In `app/Http/Requests/Superadmin/SuperadminDashboardRequest.php`, resolve the window once, and report a too-old `from` sent alone under `from`
- [X] T035 In `app/Services/Metering/AiUsageSummary.php`, select each hotel's name in the rows, removing the per-account lookup
- [X] T036 Move the user rule to `User::scopeRefusedBySuspension` in `app/Models/User.php`, and count users in one statement in `app/Services/Reports/PlatformSummary.php`
- [X] T037 Record the plain-array and controller-merge exception in `CLAUDE.md`
- [X] T038 Cover all of the above in `tests/Feature/Superadmin/DashboardTest.php`, and update `docs/superadmin-dashboard-api-documentation.md`, [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md) and [contracts/dashboard.md](contracts/dashboard.md)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: After Setup. Blocks all stories.
- **US1 (Phase 3)**: After Foundational.
- **US2 (Phase 4)**: After US1, because it replaces US1's placeholder user figure.
- **US3 (Phase 5)**: After Foundational. Independent of US1 and US2.
- **US7 (Phase 6)**: Its tests need all three routes, so they finish after US1, US3 and US4.
- **US4 (Phase 7)**: After Foundational. Independent of the others.
- **US5 and US6 (Phases 8 and 9)**: Implemented in Phase 2. Their tests need at least one section, and cover all three once they exist.
- **Polish (Phase 10)**: After all stories.

### Within Each Story

Tests first, and see them fail. Then the service method, the controller action and the route.

### Parallel Opportunities

- T016 (`AiCostReport`) is a new file, and can run alongside work in other files.
- T020 (`AccessGuardTest`) and T029 (docs) touch only their own files.
- US3 and US4 could be built side by side, but both edit `PlatformSummary`, `DashboardController`, `routes/superadmin.php` and `DashboardTest`, so expect to merge. Doing them in order is simpler.

### Parallel Example: Foundations of User Story 3

```text
Task: "T016 Create AiCostReport in app/Services/AiCost/AiCostReport.php"
Task: "T029 Create docs/superadmin-dashboard-api-documentation.md"
```

---

## Implementation Strategy

### MVP First

1. Phases 1 and 2.
2. US1 and US2 give correct platform counts, which is the first thing the operator needs and is usable by itself.
3. **Stop and check**: the user figure against real logins.

### Incremental Delivery

1. US3 adds usage and cost, which is the commercial reason for the dashboard.
2. US4 adds activity, which also completes US7's access tests.
3. US5 and US6 prove the shared window and cache across all sections.
4. Polish adds the docs. Stage 4 ships as one PR.

---

## Notes

- Frontend changes follow from a handoff after the backend is done. The notes are at the end of [contracts/dashboard.md](contracts/dashboard.md).
- `CLAUDE.md`'s "same stack" line is out of date (V11). It's left for the owner to correct, and isn't part of this feature.
- Commit only when asked. No `Co-Authored-By` or generated-by lines, as `CLAUDE.md` requires.
