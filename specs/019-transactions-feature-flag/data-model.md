# Data Model: Transactions Feature Flag

No migration. No table or column is added, changed or removed.

## Transactions setting (new, configuration)

| Key | Type | Default | Source |
| --- | --- | --- | --- |
| `features.transactions` | bool | `false` | `FEATURE_TRANSACTIONS` env var, read in `config/features.php` |

- Platform-wide; not stored per hotel; not editable through any endpoint.
- Changing it is an environment change plus deploy (and `php artisan config:cache` where
  config is cached).

## Transaction (existing, unchanged)

`transactions` table and `App\Models\Transaction` stay as they are: append-only, hotel
scoped through `BelongsToHotel`, reversal by negating row. Only whether the endpoints and
read paths reach it depends on the setting. There is no ledger data yet, so nothing to
migrate.

## Derived response fields

| Response | Field | When off | When on |
| --- | --- | --- | --- |
| Hotel | `transactions_enabled` (new) | `false` | `true` |
| Conversion analytics | `settlement_rate` | absent | as today |
| Booking detail | `transactions` | absent | as today |
