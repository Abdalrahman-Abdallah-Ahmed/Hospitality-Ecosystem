# Latest changes — 2026-10-30

## Transactions switched off (SPEC-005, D11)

Finance is not part of the MVP, so the transactions ledger is now behind a platform
setting that is off by default. Code and tables are kept. Spec:
`specs/019-transactions-feature-flag`.

### Breaking

- **The four transaction endpoints answer `404`** for every role while the ledger is
  off: `GET /api/transaction`, `GET /api/transaction/{id}`,
  `POST /api/transaction/import`, `POST /api/transaction/{id}/reverse`. The message is
  "The transactions feature is not enabled."; a missing permission is still `403`. See
  `docs/transaction-api-documentation.md`.
- **`settlement_rate`, `settled_value` and `settled_value_by_currency` are absent** from
  `GET /api/analytics/conversion` while it is off.
- **`transactions` is absent** from `GET /api/booking/{id}` (and from any stay or guest
  response) while it is off.
- **`GET /api/permissions` leaves out the `transactions` group** while it is off. Roles
  that already hold those permissions keep them.
- The conversion **notes** no longer mention the settlement rate while it is off.

### Added

- **`transactions_enabled`** on the hotel response (read-only), so the frontend can
  hide the Transactions menu. See `docs/hotel-api-documentation.md`.
- **`FEATURE_TRANSACTIONS`** (default `false`). Turning it on is an environment change
  plus deploy; no endpoint, hotel setting or role can change it.

Frontend: `specs/019-transactions-feature-flag/frontend-changes.md`.
