# Implementation Plan: Super Admin Platform Dashboard

**Branch**: `026-superadmin-dashboard` | **Date**: 2026-11-08 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/026-superadmin-dashboard/spec.md`

## Summary

Give super admins three read-only, separately loaded dashboard sections: platform counts, usage and AI cost per account, and activity per hotel. A new report class owns the counts, usage and activity queries, and a new class in `app/Services/AiCost/` owns cost. A thin controller validates the window, reads a 15-minute cache per section and adds the cost to the usage section. Nothing new is stored.

The source design is Stage 4 of [docs/designs/super-admin-platform.md](../../docs/designs/super-admin-platform.md). This plan follows it except where [plan-verification.md](plan-verification.md) and the spec's clarifications overrule it. See [Corrections to the design](#corrections-to-the-design).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13

**Primary Dependencies**: Sanctum and the database cache store, both already in use. Pest 4. Nothing new.

**Storage**: PostgreSQL + pgvector, read only. No migration. Cached sections sit in the default cache store. See [data-model.md](data-model.md).

**Testing**: Pest feature tests on real Postgres, run with `php artisan test --parallel`. Tests use the `array` cache store and frozen time.

**Target Platform**: Linux server. No new setting, worker or schedule.

**Project Type**: JSON API web service

**Performance Goals**: The number of queries doesn't grow with users, hotels or accounts. Repeat loads within 15 minutes run no counting queries. Nothing has been measured, and the design expects fewer than 50 hotels (Alternative K).

**Constraints**: No revenue, snapshot tables, live updates or cache clearing. No schema change. `HotelAccess`, `TenantContext` and `UsageReport` don't change. `User` and `AiUsageSummary` changed after code review ([plan-verification.md](plan-verification.md)). `PlatformSummary` can't name cost data, because `CostVisibilityTest` scans it.

**Scale/Scope**: 3 routes, 1 controller, 1 form request, 2 services, 1 new test file, 1 new doc. One small refactor in `AiCostController`, and 3 rows added to an existing test dataset.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Evidence |
| --- | --- | --- |
| I. PMS system of record | Pass | Reads structured data only, and writes nothing |
| II. Preserve and Evolve | Pass | Reuses `UsageReport::describe()`, `Hotel::notSuspended()`/`suspended()` and `HotelGroup::suspended()`. `/usage` and `/ai-cost` stay, with unchanged output |
| III. Tenant Isolation | Pass | `withoutScope()` is used deliberately, for a cross-account report behind the super admin guard. No tenant route is added. `CrossTenantRouteTest` picks the routes up automatically and proves a hotel admin can't reach them |
| IV. Permission-Based Authorization | Pass | The `super_admin` file-level guard is the explicit authorization path, as for `/usage` and `/ai-cost`. CLAUDE.md item 4 keeps usage and cross-account reporting out of the enum, so no permission case is added. Tests refuse admins, owners, employees and missing tokens |
| V. AI through tools | Pass | No AI tool or agent changes |
| VI. Auditability | Pass | Read-only, so there is nothing to audit. Failures go to the normal exception handler and Sentry |
| VII. Data integrity | Pass | No writes |
| VIII. Tested at the boundary | Pass | Feature tests for every story, plus comparisons with real logins and the existing reports |
| IX. Spec-driven delivery | Pass | Stage 4 of 6, after Stage 3 and spec 025 |
| AI cost visibility | Pass | Provider cost is on a super admin route only. `CostVisibilityTest` needs no change, and still covers the new report class |
| Internationalization: English and Arabic | **Justified deviation** | No guest-facing text. API messages stay English only, as in spec 024. See [Complexity Tracking](#complexity-tracking) |
| Security: least privilege | Pass | No customer can read any figure, and the cache key can't be influenced by a request header |
| API Design: `apiResponse()` and Resources | **Justified deviation** | Uses `apiResponse()` with plain arrays and no Resource. See [Complexity Tracking](#complexity-tracking) |
| Documentation | Pass | See [Documentation](#documentation) |
| Non-negotiable rule 19: payments outside MVP | Pass | No revenue or money from customers appears anywhere |

**Gate result**: Pass, with two justified deviations.

**Post-design re-check**: Pass, no change. The design added one small shared method for the cost basis, which removes a possible disagreement between two reports and adds no access path.

## Project Structure

### Documentation (this feature)

```text
specs/026-superadmin-dashboard/
├── spec.md
├── plan.md
├── plan-verification.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── dashboard.md
├── checklists/
│   └── requirements.md
└── tasks.md              # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/Superadmin/
│   │   ├── DashboardController.php            # new
│   │   └── AiCostController.php               # basis() calls the shared method
│   └── Requests/Superadmin/
│       └── SuperadminDashboardRequest.php     # new
└── Services/
    ├── AiCost/AiCostReport.php                # new
    └── Reports/PlatformSummary.php            # new

routes/superadmin.php                          # 3 GET routes

tests/Feature/Superadmin/
├── DashboardTest.php                          # new
└── AccessGuardTest.php                        # 3 rows in the routes dataset

docs/superadmin-dashboard-api-documentation.md # new
```

**Structure Decision**: Follows the existing `Superadmin` controller and request folders. Cost goes in `app/Services/AiCost/` and everything else in `app/Services/Reports/`, as `CostVisibilityTest` requires.

## Design

Details and reasons are in [research.md](research.md). In short:

1. **`SuperadminDashboardRequest`** validates each date, then compares the resolved dates, and exposes `from()` and `to()` as start and end of day (R1).
2. **`DashboardController`** has `counts`, `usage` and `activity`. Each goes through one private method that builds the key, calls `Cache::remember` for 900 seconds, and adds `from`, `to` and `cached_at` inside the closure (R2). `usage` merges the cost in that closure too (R5).
3. **`PlatformSummary::counts()`** runs the account, hotel, user and signup queries. Suspended users are counted in SQL with the same rule as `HotelAccess` (R3, R4).
4. **`PlatformSummary::usage()`** repeats what `UsageController` does and reshapes `describe()`'s output (R5).
5. **`AiCostReport::summary()`** runs one grouped query, and returns per-account cost and `unattributed` (R6).
6. **`PlatformSummary::activity()`** lists hotels and adds one grouped count each for reservations and bookings (R7).
7. Both services run under `TenantContext::withoutScope()` (R8).

## Corrections to the design

From [plan-verification.md](plan-verification.md) and clarification.

| Design | This plan | Source |
| --- | --- | --- |
| A user is suspended when their account is, or all their hotels are | Same result as `HotelAccess`: the account is checked only when the user reaches no hotel | V1, clarified |
| "At least one hotel" | Hotels as `accessibleHotelIds()` defines them, with separate routes for owners and everyone else | V2 |
| Doesn't say how to count users | SQL with no per-user query, checked against real logins | V3, R3 |
| 4.5's test list | Every acceptance scenario, including the usage section | V4 |
| `usage` wraps `totals()` and `aiTotals()` | Also reads `seats()` and eager-loads hotels, because `describe()` needs both | V5 |
| `perAccount()` returns a bare USD cost | `summary()` returns `ai_cost_usd`, `cost_basis`, `estimated_rows_pct`, and `unattributed` | V6, clarified |
| `addMonths(12)` | `addMonthsNoOverflow(12)`, and dates after today get `422` | V10, clarified |
| Signup rows only for days with data (not stated) | A row for every day, with zeros | V8 |
| Everything in counts is cached per window | `accounts`, `hotels` and `users` are cached once, and `signups` per window | V16, code review |
| `perAccount()` drops rows with no live account | `unattributed` and `deleted_accounts` carry those rows, with their basis | V14, V15, code review |

## Documentation

- **New**: `docs/superadmin-dashboard-api-documentation.md`. It covers the three endpoints, parameters, response shapes, the cache, and what "active", "suspended" and "signup" mean (FR-031).
- `docs/staff-roles-api-documentation.md` doesn't change, because there's no new permission.
- No `latest-changes` file, because nothing breaks.
- `CLAUDE.md`'s "same stack" line is out of date (V11). It isn't part of this feature, and is left for the owner to correct.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| No Resource class for these responses | The cached value must be plain arrays, and a Resource would have to be resolved and flattened before caching (the `plain()` step in `App\Http\Controllers\DashboardController`). There's no model to wrap. `UsageController` and `AiCostController` already return arrays this way | A Resource would add a conversion and three classes, and change nothing in the response |
| API messages in English only | Only the platform operator reads this API, and every other super admin message is in English. Metering labels inside `usage` come from `UsageReport`, also in English | Translating three success messages would leave the rest of the super admin API in English, and the frontend renders its own text |
