# Quickstart: Transactions Feature Flag

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
```

## Run the tests

```bash
SENTRY_LARAVEL_DSN= php artisan test --parallel --filter=TransactionsFeatureFlagTest
SENTRY_LARAVEL_DSN= php artisan test --parallel   # full suite: existing ledger tests pass with the flag on in their files
```

## Manual check (local)

1. Leave `FEATURE_TRANSACTIONS` unset in `.env`, then `php artisan config:clear`.
2. As an admin, `GET /api/transaction` → `404`, message "The transactions feature is not enabled."
3. `GET /api/hotel/{id}` → `body.transactions_enabled` is `false`.
4. `GET /api/analytics/conversion` → no `settlement_rate` in `body`.
5. Set `FEATURE_TRANSACTIONS=true`, `php artisan config:clear`.
6. Repeat 2–4 → transactions list returns `200`, `transactions_enabled` is `true`,
   `settlement_rate` is present.

Expected outcomes map to SC-001 … SC-005 in [spec.md](spec.md); the response shapes are in
[contracts/transactions-flag-api.md](contracts/transactions-flag-api.md).
