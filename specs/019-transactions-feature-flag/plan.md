# Implementation Plan: Transactions Feature Flag

**Branch**: `019-transactions-feature-flag` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/019-transactions-feature-flag/spec.md`. This is
SPEC-005 from Phase 1 of the master plan, decision D11, clarified on 2026-10-30
(platform config only; no existing ledger data).

## Summary

- **Setting (R1)**: `config/features.php` → `features.transactions`, from
  `FEATURE_TRANSACTIONS`, default `false`. No column, no endpoint changes it.
- **Gate (R2)**: new `EnsureFeatureEnabled` middleware, alias `feature`, applied as
  `feature:transactions` to a group around the four `/transaction` routes. Off → `404`
  "The transactions feature is not enabled." for every actor, before binding and policy.
- **Reads (R4)**: conversion analytics passes `includeLedger` from the flag, so
  `settlement_rate` disappears when off. Booking `show` loads `transactions` only when on.
- **Frontend (R5)**: `HotelResource.transactions_enabled`, read-only.
- **Writes (R3)**: `TransactionService` stays unguarded; its only production caller is
  behind the gate. A journey test proves MVP flows write no ledger rows (FR-008).
- **No migration, no new permission, no new job or route.**

## Technical Context

**Language/Version**: PHP 8.3+, Laravel 13.

**Primary Dependencies**: none new.

**Storage**: PostgreSQL, unchanged. The flag is configuration only.

**Testing**: Pest feature tests on real Postgres: a new `TransactionsFeatureFlagTest`,
and the existing ledger tests flip the flag on in `beforeEach` (R6).

**Target Platform**: Linux server, JSON API. Queue and scheduler untouched.

**Project Type**: Multi-tenant Laravel web service (backend only; frontend slice in
`ecosystem-frontend`).

**Performance Goals**: none; one config read per gated request.

**Constraints**: `env()` only in `config/features.php`; with `config:cache` in production,
turning the flag on needs a config re-cache (the deploy already does this).

**Scale/Scope**: ~8 source files touched, 1 new middleware, 1 new config file, 1 new test
file, 4–5 existing test files adjusted, 4 docs updated.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
| --- | --- | --- |
| I. PMS is system of record | Pass | No data model change. |
| II. Preserve and Evolve | Pass | Ledger code and tables kept; switched off, not removed. |
| III. Tenant isolation | Pass | Gate runs after `tenant`; when on, existing scoping unchanged. No new tenant data. |
| IV. Permission-based authorization | Pass | Flag added in front of permissions, never replacing them (FR-006). Permission cases unchanged and still listed. |
| V. AI through tools | Pass | Admin AI already excludes the ledger; no tool change. |
| VI. Auditability | Pass | No in-app mutation of the flag, so nothing to audit (deploy-time change). |
| VII. Integrity and idempotency | Pass | No new writes. |
| VIII. Tested at the domain boundary | Pass | Gate, reads and the MVP journey are tested; isolation unchanged. |
| IX. Spec-driven | Pass | Spec → plan → tasks before code. |
| MVP boundary ("no MVP feature depends on the ledger") | Pass | This feature enforces it. |

Re-check after Phase 1 design: unchanged, all pass. No violations to justify.

## Project Structure

### Documentation (this feature)

```text
specs/019-transactions-feature-flag/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── transactions-flag-api.md
├── checklists/
│   └── requirements.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
config/
└── features.php                          # new: 'transactions' => env('FEATURE_TRANSACTIONS', false)

app/Http/Middleware/
└── EnsureFeatureEnabled.php              # new: feature:{name} → 404 when config('features.{name}') is false

bootstrap/app.php                         # alias 'feature'; priority before SubstituteBindings
routes/api.php                            # wrap the 4 /transaction routes in ->middleware('feature:transactions')

app/Http/Controllers/
├── AnalyticsController.php               # includeLedger from config
└── BookingController.php                 # load 'transactions' only when enabled

app/Http/Resources/HotelResource.php      # transactions_enabled

.env.example                              # FEATURE_TRANSACTIONS=false

tests/Feature/
├── TransactionsFeatureFlagTest.php       # new (R6)
├── TransactionControllerTest.php         # flag on in beforeEach
├── TransactionImportTest.php             # flag on in beforeEach
├── ConversionAnalyticsTest.php           # flag on in beforeEach
└── PermissionAuthorizationTest.php       # flag on so transaction rows still test permissions

docs/
├── transaction-api-documentation.md      # flag section, 404 when off
├── conversion-analytics-api-documentation.md   # settlement_rate absent when off
├── hotel-api-documentation.md            # transactions_enabled
├── booking-entity-documentation.md       # transactions absent when off
├── latest-changes-2026-10-30.md          # breaking change summary
└── AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md  # SPEC-005 → config only (D11 note)

specs/019-transactions-feature-flag/frontend-changes.md   # hide menu + settlement figures from transactions_enabled
```

**Structure Decision**: existing single Laravel app; changes stay in the layers above.
No staff-roles doc change: no permission is added or changed.

## Complexity Tracking

None.
