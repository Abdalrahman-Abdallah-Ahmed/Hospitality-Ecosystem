# Contract: Reservation Import API (v2)

All endpoints use the standard stack (`api.key` → `auth:sanctum` → `throttle:api` →
`tenant`) and return `apiResponse()` envelopes `{ message, code, body }`, except the
report download, which is a file. Every endpoint requires `reservations.import` plus the
same-hotel check. An employee without the permission gets `403`, and another hotel's
import gives `404` through scoped route-model binding.

## Endpoints

| Method | Path | Does | Change |
| --- | --- | --- | --- |
| `POST` | `/api/reservation/import` | Upload a file and queue an import or dry run | **Breaking**: `200` sync → `202` queued |
| `GET` | `/api/reservation/imports` | The hotel's imports, newest first, paginated | New |
| `GET` | `/api/reservation/imports/{import}` | One import: status, progress, totals, report summary | New |
| `GET` | `/api/reservation/imports/{import}/rows` | Row results, paginated; filter by `outcome` | New |
| `GET` | `/api/reservation/imports/{import}/report` | Download the non-imported rows and warnings as `.xlsx` | New |

## `POST /api/reservation/import`

`multipart/form-data`:

| Field | Rules |
| --- | --- |
| `file` | required; `xlsx` / `xls` / `csv` / `txt`; max 5 MB (unchanged) |
| `date_order` | optional; `day_first` (default) or `month_first` (FR-008a) |
| `dry_run` | optional boolean; default `false` (FR-030) |
| `hotel_id` | required for a super admin, ignored for anyone else (`resolveHotel()`) |

**`202 Accepted`**: `body` is the [Import resource](#import-resource) with
`status: "queued"`.

| Error | When |
| --- | --- |
| `422` `file` | missing, wrong type or too large |
| `422` `date_order` | not one of the two values |
| `422` `hotel_id` | a super admin without a valid hotel |
| `403` | missing `reservations.import` |

Missing required columns are **not** checked at upload. The import fails in the
background with `failure_reason` naming them, so a large file never blocks the request
(SC-003).

## Import resource

```json
{
  "id": "9f1c…",
  "hotel_id": "3b2a…",
  "file_name": "reservations-sept-2025.xlsx",
  "uploaded_by": { "id": "…", "name": "Sara Ali" },
  "dry_run": false,
  "date_order": "day_first",
  "status": "running",
  "stage": "import",
  "progress": { "processed_rows": 1200, "total_rows": 5000 },
  "totals": { "imported": 1100, "skipped": 0, "duplicate": 60, "invalid": 30, "unresolved": 10 },
  "created_rooms": ["214", "215"],
  "created_room_types": ["Dbl Deluxe"],
  "unrecognized_columns": ["fax", "agent_code"],
  "warnings_count": 42,
  "failure_reason": null,
  "created_at": "2026-10-23T09:00:00Z",
  "started_at": "2026-10-23T09:00:03Z",
  "finished_at": null
}
```

- `uploaded_by` is null when the user was deleted.
- On a dry run, `created_rooms` and `created_room_types` mean "would be created".
- `totals` add up to `total_rows` once `status` is `completed` (FR-025).

## `GET /api/reservation/imports`

`GenericIndexRequest` query: filter on `status` and `dry_run`; sort on `created_at`
(default `-created_at`); `per_page`. Returns a paginated list of Import resources.

## `GET /api/reservation/imports/{import}/rows`

| Query | Rules |
| --- | --- |
| `outcome` | optional; one of `imported`, `skipped`, `duplicate`, `invalid`, `unresolved` |
| `has_warnings` | optional boolean |
| `per_page`, `page` | as in `GenericIndexRequest` |

Ordered by `row_number`. Each item:

```json
{
  "row_number": 418,
  "external_reference": "BK-77812",
  "outcome": "unresolved",
  "stage": "map",
  "fields": ["guest_phone", "guest_email"],
  "reason": "The phone matches Ahmed Saleh and the email matches Mona Saleh; choose the right guest and re-import.",
  "warnings": [],
  "reservation_id": null
}
```

Warning items are `{ "code": "no_phone", "message": "…" }`. Codes: `no_phone`,
`no_room_assigned`, `room_clash`, `data_differs`, `restored`, `phone_country_unknown`, `phone_unrecognized`,
`booking_unresolved`, `status_corrected`.

A row of a reservation that already existed has `outcome: "duplicate"` and
`reservation_id` set to the existing reservation.

## `GET /api/reservation/imports/{import}/report`

Returns `200` with an `.xlsx` attachment named `import-<id>-report.xlsx`. It has one row
per non-imported row and one per warning, with the columns `row_number`,
`external_reference`, `outcome`, `fields`, `reason`, `warning`.

If the import isn't finished yet, it returns `409` (an `apiResponse` envelope): "The
import has not finished yet."

## Supported file columns (documented in full in `docs/reservations-api-documentation.md`)

Header matching ignores case, spaces and punctuation, and goes through a synonym table.
The current column names are kept: `reservation_id` is a synonym of
`reservation_reference`, and the `guests` JSON column still works.

| Group | Columns |
| --- | --- |
| Reservation | `reservation_reference`, `arrival_date`*, `departure_date`*, `status`, `source`, `adults`, `children`, `children_ages`, `special_requests`, `reservation_value`, `currency` |
| Room | `room_number`, `room_type`, `floor`, `room_count` |
| Guest (one per row) | `guest_first_name`, `guest_last_name`, `guest_phone`†, `guest_email`†, `guest_external_id`†, `is_primary`, or `guests` (JSON list) |
| Activity booking | `activity_name`, `activity_date`, `activity_time`, `activity_pax` |

\* required column. † at least one of these columns must exist in the file, and each guest
needs at least one value among them (FR-019a).

## Breaking change summary (for `docs/latest-changes-<date>.md`)

- `POST /api/reservation/import` returns `202` with an Import resource, instead of `200`
  with `{ imported, skipped[] }`. Clients poll `GET /api/reservation/imports/{id}` until
  `status` is `completed` or `failed`, then read `/rows` or download `/report`.
- Rows the old import silently accepted are now rejected: a departure on or before the
  arrival, an unknown status label (it used to fail with a raw enum error), and
  checked-out or no-show statuses with dates not yet reached, and checked-in with an
  arrival in the future. A checked-in row whose departure has passed is now imported as
  checked-out with a `status_corrected` warning.
- Several unnumbered rows of the same room type in one reservation now make one room
  (the rest add guests) unless `room_count` says otherwise.
- A super admin can now import by passing `hotel_id`.
