# Contract: Module Export Endpoints

Base: `/api` · Middleware: `api.key → auth:sanctum → throttle:api → tenant → throttle:exports`
Headers: `X-API-KEY`, `Authorization: Bearer <token>`

| Method | Path | Permission | Accepts the query string of |
| --- | --- | --- | --- |
| GET | `/reservation/export` | `reservations.export` | `GET /reservation` |
| GET | `/activity/export` | `activities.export` | `GET /activity` |
| GET | `/booking/export` | `bookings.export` | `GET /booking` |
| GET | `/guest/export` | `guests.export` | `GET /guest` |

## Query parameters

| Name | Type | Default | Notes |
| --- | --- | --- | --- |
| `format` | `xlsx` \| `csv` \| `sql` | `csv` | |
| `hotel_id` | uuid | — | Super admin: required. Others: ignored |
| `search` | string ≤ 255 | — | Same as list |
| `sort` | column, `-column` for desc | list default | Same columns as list |
| `filter[<column>]` | scalar or list | — | Same columns as list (plus `room_type_id`, `room_id` on reservations) |
| booking extras | see `GET /booking` | — | `activity_id`, `reservation_id`, `scheduled_from`, `scheduled_to`, `cancellation_requested` |
| `page`, `per_page` | — | — | Ignored |

Example: `GET /api/reservation/export?format=xlsx&filter[status]=confirmed&sort=arrival_date`

## Success — 200, file body (not the JSON envelope)

| Format | `Content-Type` | Body |
| --- | --- | --- |
| `xlsx` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` | One sheet named after the module; header row + one row per record |
| `csv` | `text/csv; charset=UTF-8` | UTF-8 with BOM; header row + one row per record; streamed. Date-times in the hotel's time zone |
| `sql` | `application/sql; charset=UTF-8` | Comment header, `BEGIN;`, one `INSERT` per record, `COMMIT;`, then `-- Rows written: N` |

`Content-Disposition: attachment; filename="<module>-<hotel-slug>-<YYYYMMDD-HHMM>.<ext>"`

Zero matching records → 200 with header row only (spreadsheets) or no INSERTs (SQL).

## Errors — `apiResponse()` envelope `{ message, code, body }`

| Code | When | `body` |
| --- | --- | --- |
| 401 | Missing/invalid token | — |
| 403 | No `<module>.export` permission; or super admin without a valid `hotel_id` | — |
| 422 | Unknown `format`, filter column or sort column; invalid booking extras | the API's standard validation error shape (`message`, `errors`), as on the list endpoints |
| 422 | Matching records > `max_rows` (default 10,000) | `{ "matching": 12345, "max_rows": 10000 }` |
| 429 | More than 10 exports by this user in the last minute (all modules combined) | framework throttle response, `Retry-After` header |

Order of checks: the permission (403) runs first, in the request's own authorization, then request validation (422), then the row cap (422). A user without the permission therefore never learns the filter or sort columns.

Message for the cap refusal: `"This export matches 12345 records; the limit is 10000. Narrow the filters and try again."`

## Side effects

- Success: one audit entry `hotel.<module>_exported` (see [data-model.md](../data-model.md)).
- Cap refusal: one audit entry `hotel.<module>_export_refused`.
- 401/403/422-validation/429: nothing recorded, no file.
