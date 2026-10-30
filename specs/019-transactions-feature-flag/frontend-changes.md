# Frontend changes: Transactions Feature Flag

For `ecosystem-frontend`. API details: [transactions API § Feature flag](../../docs/transaction-api-documentation.md#feature-flag--off-by-default)
and [hotel API](../../docs/hotel-api-documentation.md). The ledger is off by default, so
everything below is the normal state.

## 1. Read the flag

- The hotel response now carries `transactions_enabled` (boolean, read-only). Read it from the
  hotel the app already loads; no extra request.

## 2. While `transactions_enabled` is `false`

- Hide the **Transactions** menu item, its routes (list, detail, import, reverse) and any
  links into them.
- Hide **settlement** figures: `settlement_rate`, `settled_value` and `settled_value_by_currency` are absent from
  `GET /api/analytics/conversion`; don't render an empty or "0%" tile for it.
- Booking detail no longer has a `transactions` key; hide the transactions/settlement section
  instead of showing it empty.
- Don't offer a toggle anywhere; it is a platform setting.

## 3. Errors

- A `404` with message "The transactions feature is not enabled." means the feature is off (for example a
  bookmarked transactions URL). Show a "not available" state, not a "not found" record.
- `403` still means the user lacks the permission.

## 4. While `transactions_enabled` is `true`

Nothing changes from today. Keep gating on `transactions.view|import|reverse` as before.

## 5. Role editor

- While the ledger is off, `GET /api/permissions` has no `transactions` group. Build the grid from
  the response as today; nothing to special-case.
