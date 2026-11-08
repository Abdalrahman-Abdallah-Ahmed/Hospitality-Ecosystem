# Quickstart: Validating the Dashboard

## Prerequisites

- Postgres running. Don't recreate or drop its volume, because another project shares it.
- No new migration is needed.

## Automated checks

```bash
php artisan test --parallel --filter=DashboardTest
php artisan test --parallel --filter=AccessGuardTest
php artisan test --parallel --filter=CostVisibilityTest
php artisan test --parallel --filter=AiCostAttributionTest
php artisan test --parallel --filter=CrossTenantRouteTest
php artisan test --parallel --filter=EntryPointInventoryTest
php artisan test --parallel --filter=RouteMoveTest
php artisan test --parallel
./vendor/bin/pint
```

`CostVisibilityTest` and `AiCostAttributionTest` must pass with no edits: the first proves the new report class holds no cost data, and the second that `/ai-cost` still answers the same after the small refactor.

## Scenarios the tests must prove

Expected responses are in [contracts/dashboard.md](contracts/dashboard.md).

### Counts (Story 1)

1. Build a mix of suspended and active accounts and hotels, plus a super admin. Every figure matches, and the super admin appears nowhere.
2. Register through `POST /api/register`. That day's signups rise by one account and one user.
3. Soft-delete an account. It leaves `accounts`, but stays in its creation day's signups.

### Suspended users match login (Story 2)

For each kind of user below, read the count and try a real `POST /api/login`. A `403` must match a suspended count, and a `200` an active one.

| User | Expected |
| --- | --- |
| Owner, home hotel suspended, another account hotel active, no membership row | Active |
| Owner, every account hotel suspended | Suspended |
| Direct account link with no group role, home hotel suspended, other account hotels active | Suspended |
| Home hotel suspended, member of an active hotel | Active |
| Only hotel soft-deleted, account suspended | Suspended |
| No hotels and no account | Active |
| Linked to a suspended account, reaches an active hotel in another account | Active |
| Owner of a suspended account with no hotels | Suspended |
| Tokens revoked | No change |

### Usage and cost (Story 3)

1. Give two accounts usage and AI cost. Call `/dashboard/usage`, `/usage` and `/ai-cost` with the same explicit `from` and `to`. `usage`, `ai` and `ai_cost_usd` match.
2. Log a call with an unpriced model. `cost_basis` reads `estimated` or `mixed`.
3. Log a call with no account. It appears in `unattributed`, not under an account.

### Activity (Story 4)

1. Create reservations and bookings in two accounts' hotels, inside and outside the window. Only those inside count, and a hotel with none shows zeros.
2. The response contains no revenue, price or currency field.

### Window (Story 5)

Freeze time, then check each row of the table in [data-model.md](data-model.md#window), the exact 12-month range (accepted), one day more (`422`), a date after today (`422`), `to` before `from` (`422`), `2026-1-1` (`422`), and a start date of 29 February. A record at `00:00:00` on `from` and one at `23:59:59` on `to` both count.

### Cache (Story 6)

Freeze time, read a section, change the data, and read again. The figures and `cached_at` are unchanged, and the two responses are identical. Move forward 15 minutes and read again: new figures, new `cached_at`. A different window or section gets its own entry. Suspending an account doesn't clear anything.

### Access (Story 7)

An admin, an owner and an employee get `403`, including with an invalid window. No token gets `401`. A super admin sending `X-Hotel-Id` gets platform-wide figures. `PlatformSummary::activity()` called under a narrowed `TenantContext` still returns every hotel.

### Queries

Add users and hotels, then read `counts` and `activity` with an empty cache. The query count doesn't change.

## Manual check

1. `composer dev`, and log in as a super admin.
2. Call the three endpoints with `X-API-KEY` and the bearer token, and compare them with [contracts/dashboard.md](contracts/dashboard.md).
3. Suspend an account, and re-read `counts`. The figures can stay old for up to 15 minutes, and `cached_at` shows their age.
