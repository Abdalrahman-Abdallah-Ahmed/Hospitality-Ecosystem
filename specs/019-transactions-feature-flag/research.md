# Research: Transactions Feature Flag

All spec clarifications are resolved (spec § Clarifications). These are the design
decisions taken from the current code.

## R1 — Where the setting lives

- **Decision**: a new `config/features.php` with
  `'transactions' => (bool) env('FEATURE_TRANSACTIONS', false)`, read as
  `config('features.transactions')`.
- **Rationale**: the master plan names the key `features.transactions`. A dedicated file
  gives later platform flags an obvious home. CLAUDE.md requires `env()` only inside
  config files. Off when the env var is missing satisfies FR-001.
- **Alternatives**: a column on `hotels` (rejected by clarification: no per-hotel value);
  a key in `config/app.php` (works, but hides the flag among framework settings); a
  feature-flag package such as Laravel Pennant (rejected: one boolean, no per-scope
  resolution needed, new dependency).

## R2 — How the endpoints are refused

- **Decision**: one route middleware, `EnsureFeatureEnabled`, aliased `feature` in
  `bootstrap/app.php` and used as `feature:transactions` on a route group around the four
  `/transaction` routes. When the flag is off it returns
  `apiResponse('The transactions feature is not enabled.', 404)`.
- **Rationale**: one place covers all four routes and every actor, before the policy runs,
  so permissions can never outrank the flag (FR-003, FR-006). Matches the existing
  `super_admin` middleware style. The parameter keeps it reusable for later flags without
  building a flag system.
- **Ordering**: added to the middleware priority list before `SubstituteBindings` (as
  `ResolveTenant` already is), so `GET /transaction/{id}` with any id answers with the
  "not enabled" 404 rather than the model-not-found 404 — same status, consistent message.
- **Alternatives**: a check in each controller action (four copies, easy to miss on a new
  route); a policy `before()` (would return 403, conflating with permissions — FR-004);
  not registering the routes when off (route cache would freeze the value and tests
  could not flip it).

## R3 — No guard inside `TransactionService`

- **Decision**: the service stays unguarded.
- **Rationale**: its only production caller is the import, which sits behind the
  middleware. Reversal likewise. Test fixtures across the suite (`wp5Transaction()` in
  `tests/Pest.php`, `bookingSettlement()`, `EventLogTest`) build ledger rows through the
  service; a guard would force the flag on in unrelated tests. FR-008 is proven by a
  journey test instead (R6).
- **Alternatives**: throw in `record()`/`reverse()` when off (defence in depth, but high
  test churn for no current caller).

## R4 — Ledger reads outside the transaction endpoints

- **Conversion analytics**: `ConversionReport::for()` already takes `includeLedger`
  (used `false` by the Admin AI). `AnalyticsController::conversion` passes
  `includeLedger: (bool) config('features.transactions')`. Off → `settlement_rate` key is
  absent, exactly as the Admin AI gets it.
- **Booking detail**: `BookingController::show` always loads `transactions`. It loads it
  only when the flag is on; `BookingResource` already uses `whenLoaded`, so the key is
  simply absent when off.
- **Stay and guest resources**: `whenLoaded('transactions')` but no controller loads it.
  No change.
- **Admin AI**: `GetReportTool` already passes `includeLedger: false` and no tool reads
  the ledger. No change.

## R5 — Exposing the flag to the frontend

- **Decision**: `HotelResource` gains `transactions_enabled` (bool), from config.
- **Rationale**: the frontend already fetches the hotel; FR-009/SC-005 need no extra
  request. Same shape as `guest_memories_enabled`. The value is not in `$fillable` and no
  request accepts it, so a client cannot change it (FR-002).
- **Alternatives**: a `/api/features` endpoint (extra request and a new entry point);
  on `UserResource` (the flag is not about the user).

## R6 — Tests

- New `tests/Feature/TransactionsFeatureFlagTest.php`:
  - off by default: all four endpoints → 404 "not enabled" for super admin, admin, and an
    employee with every `transactions.*` permission; ledger count unchanged; import
    records no metering usage;
  - on: list works as today;
  - analytics has no `settlement_rate` when off and has it when on;
  - booking detail has no `transactions` key when off;
  - `transactions_enabled` on the hotel follows the config; a hotel update sending
    `transactions_enabled` changes nothing;
  - journey with the flag off: reservation → check-in → activity booking → check-out
    leaves zero ledger rows (FR-008, SC-003).
- Existing files that exercise the ledger through HTTP turn the flag on in `beforeEach`
  (`config(['features.transactions' => true])`): `TransactionControllerTest`,
  `TransactionImportTest`, `ConversionAnalyticsTest`, and the booking-show cases in
  `BookingTest` that assert `transactions`.
- `PermissionAuthorizationTest`: the `transactions` dataset row and the employee
  `/api/transaction` → 403 check run with the flag on, so they keep testing permissions.
- No new route, job or AI tool, so `EntryPointInventoryTest` and `CrossTenantRouteTest`
  need no change.

## R7 — Changes after code review (2026-10-30)

- One reader, `App\Support\Features::enabled()`, used by the middleware, resources,
  analytics and the permission list. An unknown feature name throws instead of reading
  as off. The refusal message is "The transactions feature is not enabled."
- `BookingResource`, `StayResource` and `GuestResource` drop `transactions` while off,
  however the relation was loaded (supersedes the "no change" in R4).
- Conversion notes leave out the settlement caveat while off.
- `GET /api/permissions` leaves out the `transactions` group while off (FR-012 amended).
- `phpunit.xml` pins `FEATURE_TRANSACTIONS=false`, so a developer's `.env` cannot flip
  the suite; `CrossTenantRouteTest` and the "every permission" list test turn it on.
