# Transactions API Documentation

This document describes the transaction (commercial ledger) APIs — the "till"
that records what a guest actually bought, so conversion, revenue, and
recommendation outcomes can be measured (Phase 1, WP-3).

It covers four endpoints: a **bulk import** (`import`), two **read** endpoints
(`index`, `show`), and **reverse** (the only correction path). There is
deliberately **no create, update, or delete** — the ledger is append-only.

## Base URL

All endpoints are defined in `routes/api.php`, served under `/api`:

- `POST /api/transaction/import`
- `POST /api/transaction/{id}/reverse`
- `GET  /api/transaction`
- `GET  /api/transaction/{id}`

## Required Headers

Every request needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
```

(`Content-Type: application/json` for `reverse`; omit it for `import` — let the
HTTP client set the `multipart/form-data` boundary.)

## Who Can Call These Endpoints

Gated by `App\Policies\TransactionPolicy` on top of the bearer-token check:

| Action | Rule |
| --- | --- |
| `index` (list) | `role` must be `admin` (super admin bypasses). |
| `show` | `admin`, **and** the transaction's `hotel_id` must equal the user's own hotel. |
| `import` | Same `create` check — `role` must be `admin`. **A super admin cannot target an arbitrary hotel here** (same as reservation import): the import always uses `$request->user()->hotel`, so a super admin gets `403 You do not belong to any hotel.` |
| `reverse` | `admin`, **and** the transaction's `hotel_id` must equal the user's own hotel. |

Every query is also automatically scoped to the caller's hotel(s) by the
`BelongsToHotel` global scope — the list only ever contains the user's own rows.

## Response Format

Standard wrapper: `{ "message": ..., "code": ..., "body": ... }`. Validation
errors (`422`) use Laravel's default `{ "message", "errors" }` shape instead.

## The Transaction Object

```json
{
  "id": "019f9b37-c26b-703f-bd9b-2ebe9eb03a55",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1",
  "stay_id": "019f9b37-c268-738c-bc46-53281c1763cf",
  "room_id": "019f9b37-c269-...",
  "activity_id": null,
  "item_name": "Sunset dive trip",
  "revenue_center": "diving",
  "department": null,
  "quantity": 2,
  "unit_price": "60.00",
  "line_total": "120.00",
  "discount_amount": "0.00",
  "currency": "USD",
  "transacted_at": "2026-09-04T19:30:00.000000Z",
  "business_date": "2026-09-04T00:00:00.000000Z",
  "seller_reference": null,
  "sold_by_user_id": null,
  "source_system": "import",
  "external_reference": "POS-88213",
  "evidence_level": "L1",
  "reverses_transaction_id": null,
  "raw_payload": { "...": "the original import row, untouched" },
  "created_at": "...",
  "updated_at": "..."
}
```

Field notes for the UI:

- All money fields (`unit_price`, `line_total`, `discount_amount`) are **strings**
  (DB `decimal`), not JSON numbers — parse before arithmetic.
- **`line_total` is the only field to sum for revenue.** `unit_price` is
  per-unit and may be `null`.
- **Never sum across currencies** — group by `currency`. Phase 1 does no FX.
- `unit_price` is `null` when the source gave only a single undifferentiated
  amount (stored as `line_total`, row downgraded to `L4` — see below).
- `stay_id` / `guest_id` / `room_id` are `null` when the purchase could not be
  tied to a guest who was in the room at the time of sale — see
  [Attribution](#attribution).
- `business_date` is date-only, serialized as midnight UTC — show the date part.
- `evidence_level` is one of `L1` (observed fact), `L2` (strong inference),
  `L3` (hypothesis), `L4` (unverified / unattributed / ambiguous amount).
- `source_system` is one of `eleanor`, `opera`, `manual`, `import`. Every
  reversal is `manual`.

### Attribution

Transactions arrive tagged with a **room number**, not a guest. A room hosts
many guests over its life, so the ledger credits a purchase only to the guest
whose **stay window actually contains the moment of sale** (`checked_in_at <=
transacted_at <= checked_out_at`, or still in-house). If no stay matches — the
room was empty, the guest had checked out, the room number is unknown — the
transaction is still imported, but `guest_id`/`stay_id`/`room_id` stay `null`
and `evidence_level` is forced to `L4`. This is intentional: an unattributed
transaction is a known, measurable gap; a wrongly attributed one silently
corrupts every report built on top of it.

## 1. List Transactions — `GET /api/transaction`

Generic filter/search/sort/paginate, same engine as `GET /api/reservation`:

| Param | Example | Behavior |
| --- | --- | --- |
| `filter[<column>]` | `filter[evidence_level]=L4` | Exact match on any real `transactions` column (arrays match any). |
| `search` | `search=POS-882` | `LIKE %term%` across string columns (`item_name`, `external_reference`, `revenue_center`, `department`, `currency`, `source_system`, ...). |
| `sort` | `sort=-transacted_at` | Real column; `-` prefix = descending. |
| `page` / `per_page` | `per_page=25` | 1-indexed; `per_page` 1–100, default 15. |

`body` is a Laravel paginator; read rows from `body.data`. `guest`, `stay`,
`room`, and `activity` are eager-loaded.

## 2. Get a Transaction — `GET /api/transaction/{id}`

`200` with a [transaction object](#the-transaction-object) (plus `reverses` and
`reversals` relations loaded). `404` if the id doesn't exist; `403` if it
belongs to another hotel.

## 3. Import Transactions — `POST /api/transaction/import`

`multipart/form-data`, one field:

| Field | Rules |
| --- | --- |
| `file` | **required**, `xlsx` / `xls` / `csv` / `txt`, max 5120 KB. |

```bash
curl -X POST http://your-domain.com/api/transaction/import \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -F "file=@transactions.csv"
```

### Expected Columns

First row is a header row; order doesn't matter; unknown columns are ignored;
names are matched case-insensitively with spaces folded to underscores.

| Column | Required? | Notes |
| --- | --- | --- |
| `item_name` | **Required.** Row rejected without it. | Human-readable description. |
| `transacted_at` | **Required.** Row rejected without it. | Any parseable datetime — the moment of sale. |
| `unit_price` | one of these three | Price of ONE unit. Preferred with `line_total`. |
| `line_total` | one of these three | Total for the line, after discount. **The field reports sum.** |
| `amount` | one of these three | Ambiguous fallback. If it's the *only* amount given, it's stored as `line_total`, `unit_price` stays `null`, and the row is marked `L4`. |
| `quantity` | optional | Integer, defaults to `1`. |
| `discount_amount` | optional | Defaults to `0`. |
| `currency` | optional | Defaults to the hotel's own `currency`. |
| `room_number` | optional | Drives [attribution](#attribution). No match → row imported but unattributed + `L4`. |
| `business_date` | optional | The hotel's accounting day. Defaults to the calendar date of `transacted_at`. |
| `external_reference` | optional | Idempotency key. A row whose `(hotel, source_system, external_reference)` already exists is counted as a duplicate, not re-imported. |
| `revenue_center` | optional | e.g. `spa`, `diving`, `restaurant`. |
| `department` | optional | Free text. |
| `seller_reference` | optional | Free text. |
| `activity_name` | optional | Matched to an `activities` row by exact name within the hotel; no match is not an error. |

### Behavior Notes

- Every row is accounted for: **imported**, **duplicate**, or **rejected** with
  a reason. Nothing is silently dropped.
- Re-running the same file is safe — rows with an `external_reference` already
  present are skipped (`duplicates` count), backed by a DB unique index.
- Rows without an `external_reference` are **not** deduplicated (there's no key
  to match on) — importing such a file twice will create two rows.
- The import always uses the uploader's own hotel; a `hotel_id` in the file is
  ignored.

### Success Response

`200 OK` — counts only, not the created rows:

```json
{
  "message": "Transactions imported successfully.",
  "code": 200,
  "body": {
    "read": 120,
    "imported": 116,
    "duplicates": 2,
    "rejected": [
      { "row": 7, "reason": "Missing required field(s): item_name, transacted_at, or an amount (unit_price / line_total / amount)." }
    ],
    "attributed": 101,
    "unattributed": 15
  }
}
```

- `row` in `rejected` is 1-indexed and counts the header row (first data row is
  `row: 2`).
- **`attributed` / `unattributed` is the data-quality metric** — surface it. A
  high `unattributed` count means stays are missing check-in/out times or the
  room numbers don't line up.
- To see the created rows, re-fetch `GET /api/transaction`.

## 4. Reverse a Transaction — `POST /api/transaction/{id}/reverse`

The ledger has no edit or delete. A mistake is corrected by appending a
**reversal**: a new row with negated `quantity`/`line_total`/`discount_amount`,
`source_system: manual`, `reverses_transaction_id` pointing at the original, and
`raw_payload.reason` holding the supplied reason. The original row is never
touched; the two net to zero.

### Request Body

```json
{ "reason": "Guest disputed the spa charge — confirmed with reception." }
```

| Field | Rules |
| --- | --- |
| `reason` | **required**, string, max 1000. |

### Success Response

`201 Created`, `body` is the new reversal [transaction object](#the-transaction-object).

### Errors

| Status | When |
| --- | --- |
| `422` (wrapper) | The transaction is itself a reversal, or has already been reversed. `message` explains which. |
| `422` (Laravel shape) | Missing/too-long `reason`. |
| `404` | Unknown id. |
| `403` (wrapper) | Not an admin, or the transaction belongs to another hotel. |

## Validation Errors

Field-shape failures return Laravel's default `{ "message", "errors" }` shape,
not the custom wrapper. Check for `errors` to tell the two apart.
