---

description: "Task list for the transactions feature flag (SPEC-005, D11)"
---

# Tasks: Transactions Feature Flag

**Input**: Design documents from `specs/019-transactions-feature-flag/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/transactions-flag-api.md](contracts/transactions-flag-api.md)

**Tests**: Required (constitution VIII). Pest feature tests on real Postgres; run with
`SENTRY_LARAVEL_DSN= php artisan test --parallel`.

**Organization**: grouped by user story. US1 and US2 are both P1 and share the gate; US3 is
the frontend signal.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)

---

## Phase 1: Setup

**Purpose**: the setting itself (R1).

- [X] T001 Create `config/features.php` returning `['transactions' => (bool) env('FEATURE_TRANSACTIONS', false)]`, with a short doc comment: platform-wide, off by default, changed only by env + deploy (D11, spec 019). Data-model constraint: "`features.transactions` | bool | `false`".
- [X] T002 [P] Add `FEATURE_TRANSACTIONS=false` with a one-line comment to `.env.example`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the reusable gate (R2). Blocks US1 and US2.

- [X] T003 Create `app/Http/Middleware/EnsureFeatureEnabled.php`: `handle(Request $request, Closure $next, string $feature)`; when `! config("features.{$feature}")` return `apiResponse(ucfirst($feature).' are not enabled.', 404)`, else `$next($request)`. Doc comment in the style of `EnsureSuperAdmin`: why 404 (hidden, D11) and why a middleware (runs before binding and policy, so no permission outranks it). Message for transactions must be exactly "The transactions feature is not enabled."
- [X] T004 Register the alias `'feature' => EnsureFeatureEnabled::class` in `bootstrap/app.php`, and add it to the priority list before `SubstituteBindings` next to the existing `prependToPriorityList(SubstituteBindings::class, ResolveTenant::class)` so a bound `{transaction}` id is never looked up when the flag is off (it must still run after `ResolveTenant`).
- [X] T005 In `routes/api.php`, wrap the four routes `POST /transaction/import`, `POST /transaction/{transaction}/reverse`, `GET /transaction`, `GET /transaction/{transaction}` (currently lines 131–134) in `Route::middleware('feature:transactions')->group(...)`, keeping paths, controllers and order unchanged.

**Checkpoint**: with the flag unset, every `/api/transaction*` request returns the 404.

---

## Phase 3: User Story 1 - The ledger is off (Priority: P1) 🎯 MVP

**Goal**: nobody can reach the ledger and nothing shows ledger figures while the flag is off.

**Independent Test**: with the flag unset, call the four endpoints as super admin, admin
and an employee with every `transactions.*` permission; all get the 404 and the ledger
count is unchanged.

### Tests for User Story 1

- [X] T006 [US1] Create `tests/Feature/TransactionsFeatureFlagTest.php` (`uses(RefreshDatabase::class)`, API key in `beforeEach` per CLAUDE.md, file-local fixtures; reuse `wp5Transaction()` from `tests/Pest.php` to seed one ledger row). Do **not** set the flag in `beforeEach` — the default must be exercised. Cases:
  - flag off by default: `config('features.transactions')` is `false`;
  - for super admin, admin, and an employee whose `StaffRole` grants `TRANSACTIONS_VIEW`, `TRANSACTIONS_IMPORT`, `TRANSACTIONS_REVERSE`: `GET /api/transaction`, `GET /api/transaction/{id}`, `POST /api/transaction/import` (a small CSV `UploadedFile`), `POST /api/transaction/{id}/reverse` each return `404` with `message` "The transactions feature is not enabled."; transaction count unchanged; no reversal row;
  - unknown id with flag off → same 404 message (not model-not-found);
  - unauthenticated request with flag off → `401`;
  - refused import records no `MeterFeature::TRANSACTION_ROWS_IMPORTED` usage;
  - `GET /api/analytics/conversion` with flag off → `body` has no `settlement_rate` key;
  - `GET /api/booking/{id}` with flag off → `body` has no `transactions` key;
  - MVP journey with flag off (create reservation → assign room → check in → create activity booking → realise booking → check out, using the existing endpoints/services and fixtures) leaves `Transaction::withoutGlobalScopes()->count()` at 0 (FR-008, SC-003).

### Implementation for User Story 1

- [X] T007 [P] [US1] In `app/Http/Controllers/AnalyticsController.php::conversion`, call `$report->for($from, $to, includeLedger: (bool) config('features.transactions'))`; update the method comment to say settlement is left out while the ledger is off (D11).
- [X] T008 [P] [US1] In `app/Http/Controllers/BookingController.php::show`, load `transactions` only when `config('features.transactions')` is true (e.g. build the relation list and append `'transactions'` conditionally); `BookingResource` already uses `whenLoaded`, so no resource change.

**Checkpoint**: T006 passes; US1 is complete.

---

## Phase 4: User Story 2 - Turned on by configuration, behaviour unchanged (Priority: P1)

**Goal**: with the flag on, everything works exactly as today.

**Independent Test**: set the flag on and the existing ledger suites pass unchanged.

### Tests for User Story 2

- [X] T009 [P] [US2] Add `config(['features.transactions' => true]);` to `beforeEach` in `tests/Feature/TransactionControllerTest.php`.
- [X] T010 [P] [US2] Add `config(['features.transactions' => true]);` to `beforeEach` in `tests/Feature/TransactionImportTest.php`.
- [X] T011 [P] [US2] Add `config(['features.transactions' => true]);` to `beforeEach` in `tests/Feature/ConversionAnalyticsTest.php` (its `settlement_rate` assertions need the ledger).
- [X] T012 [P] [US2] Add `config(['features.transactions' => true]);` to `beforeEach` in `tests/Feature/PermissionAuthorizationTest.php`, so the `transactions` dataset row (line ~159) and the employee-defaults `/api/transaction` → `403` check (line ~108) keep testing permissions.
- [X] T013 [US2] Add "flag on" cases to `tests/Feature/TransactionsFeatureFlagTest.php` (each sets `config(['features.transactions' => true])`): admin `GET /api/transaction` → `200`; employee without `TRANSACTIONS_VIEW` → `403`; Hotel B admin `GET /api/transaction/{hotelA id}` → `404` and Hotel A rows not listed for Hotel B; analytics has `settlement_rate`; booking detail has `transactions`.

### Implementation for User Story 2

No production code beyond Phase 2 and US1; the gate passes through when on.

**Checkpoint**: full suite green.

---

## Phase 5: User Story 3 - The frontend knows whether to show Transactions (Priority: P2)

**Goal**: the hotel response tells the frontend whether to show the Transactions menu.

**Independent Test**: `GET /api/hotel/{id}` shows `transactions_enabled` matching the config.

### Tests for User Story 3

- [X] T014 [US3] Add to `tests/Feature/TransactionsFeatureFlagTest.php`: `GET /api/hotel/{id}` → `body.transactions_enabled` is `false` by default and `true` after `config(['features.transactions' => true])`; `PATCH /api/hotel/{id}` with `transactions_enabled: true` as admin succeeds or ignores the field, and a following `GET` (flag unset) still shows `false`.

### Implementation for User Story 3

- [X] T015 [US3] In `app/Http/Resources/HotelResource.php`, add `'transactions_enabled' => (bool) config('features.transactions'),` after `guest_memories_enabled`, with a comment: platform-wide, read-only, the frontend hides the Transactions menu from it (spec 019 FR-009). Do not add it to `Hotel::$fillable` or any request rules.

**Checkpoint**: all three stories done.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T016 [P] Update `docs/transaction-api-documentation.md`: a "Feature flag" section at the top (off by default, `FEATURE_TRANSACTIONS`, the 404 body from the contract, applies to every role).
- [X] T017 [P] Update `docs/conversion-analytics-api-documentation.md`: `settlement_rate` is absent while transactions are off.
- [X] T018 [P] Update `docs/hotel-api-documentation.md`: document read-only `transactions_enabled`.
- [X] T019 [P] Update `docs/booking-entity-documentation.md`: booking detail omits `transactions` while the flag is off.
- [X] T020 [P] Create `docs/latest-changes-2026-10-30.md`: breaking change summary (four endpoints 404 by default, `settlement_rate` and booking `transactions` absent, new `transactions_enabled`; how to turn on), in the style of `docs/latest-changes-2026-10-25.md`.
- [X] T021 [P] Update SPEC-005 in `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md` §Phase 1 item 5 and D11: platform config only, no per-hotel override (clarified 2026-10-30), delivered as spec 019.
- [X] T022 [P] Create `specs/019-transactions-feature-flag/frontend-changes.md` in the style of `specs/018-reservation-voucher/frontend-changes.md`: hide the Transactions menu and routes and any settlement figure when `hotel.transactions_enabled` is `false`; treat a 404 "The transactions feature is not enabled." as feature-off.
- [X] T023 Run `./vendor/bin/pint` and `SENTRY_LARAVEL_DSN= php artisan test --parallel`; fix any failure (expect only ledger tests that hit HTTP; add the flag to their `beforeEach` rather than weakening the gate). Done: also turned the flag on for the HTTP import/list tests in `BookingTest` (2), `EventLogTest` (1), `UsageMeteringTest` (1) and `BoundedListTest` (`beforeEach`).
- [ ] T024 Walk through [quickstart.md](quickstart.md) manual steps locally.

---

## Dependencies & Execution Order

- **Setup (T001–T002)** → **Foundational (T003–T005)** → stories.
- **US1 (T006–T008)** and **US2 (T009–T013)** both need Phase 2. T009–T012 must land with
  T005, or those suites go red.
- **US3 (T014–T015)** needs only T001.
- **Polish** after the stories; T023 last but one, T024 last.

### Parallel Opportunities

- T002 with T001's review.
- T007, T008 together (different controllers).
- T009–T012 together (different test files).
- T016–T022 together (different docs).

## Parallel Example: User Story 2

```text
Task: "Add flag on in beforeEach of tests/Feature/TransactionControllerTest.php"
Task: "Add flag on in beforeEach of tests/Feature/TransactionImportTest.php"
Task: "Add flag on in beforeEach of tests/Feature/ConversionAnalyticsTest.php"
Task: "Add flag on in beforeEach of tests/Feature/PermissionAuthorizationTest.php"
```

## Implementation Strategy

1. Phases 1–2, then US1 together with T009–T012 so the suite stays green → MVP: the ledger
   is off.
2. T013 confirms on-behaviour; US3 adds the frontend signal.
3. Docs and frontend handoff, Pint, full suite.

## Notes

- `BookingTest` and `EventLogTest` mostly use `TransactionService` directly; only their HTTP import
  cases set the flag (see T023; research R3).
- No migration, permission, job, route or AI tool is added, so `EntryPointInventoryTest`,
  `CrossTenantRouteTest` and `AiToolInventoryTest` need no change.
