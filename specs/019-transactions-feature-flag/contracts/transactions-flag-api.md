# Contract: Transactions Feature Flag

All responses use the standard `{ message, code, body }` envelope.

## Transaction endpoints when the flag is off

Applies to:

- `GET /api/transaction`
- `GET /api/transaction/{transaction}`
- `POST /api/transaction/import`
- `POST /api/transaction/{transaction}/reverse`

Every actor (super admin, admin, employee), every hotel, any id:

```json
HTTP 404
{ "message": "The transactions feature is not enabled.", "code": 404, "body": null }
```

- Checked after `api.key`, `auth:sanctum` and `tenant`, before route-model binding and
  the policy. Unauthenticated requests still get `401`.
- Nothing is read, written or metered.

When the flag is on: unchanged; see `docs/transaction-api-documentation.md`.
A missing permission is still `403`, another hotel's id still `404` (model not found).

## `GET /api/hotel/{hotel}` and `GET /api/hotel` — new field

```json
{ "transactions_enabled": false }
```

Read-only. Sending `transactions_enabled` on a hotel create or update has no effect.

## `GET /api/analytics/conversion`

- Off: `settlement_rate`, `settled_value` and `settled_value_by_currency` are **absent** from `body` (the same shape the Admin AI gets).
- On: unchanged.

## `GET /api/booking/{booking}`

- Off: `transactions` is **absent** from `body`.
- On: unchanged.

## Breaking change

Clients that called the transaction endpoints or read `settlement_rate` /
booking `transactions` get the shapes above until the flag is turned on. Recorded in
`docs/latest-changes-<date>.md`.
