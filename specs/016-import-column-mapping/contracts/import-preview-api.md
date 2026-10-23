# Contract: Reservation Import Preview and Column Mapping

All endpoints use the standard stack (`api.key` → `auth:sanctum` → `throttle:api` →
`tenant`), return `apiResponse()` envelopes `{ message, code, body }`, and require
`reservations.import` plus the same-hotel check. An employee without the permission gets
`403`. Another hotel's preview is a `404`.

**Nothing here is breaking.** The SPEC-100 endpoints keep their contracts. The direct
upload and the Import resource only gain optional fields.

## Endpoints

| Method | Path | Does |
| --- | --- | --- |
| `POST` | `/api/reservation/import-previews` | Upload a file for mapping |
| `GET` | `/api/reservation/import-previews` | The hotel's unexpired previews, newest first |
| `GET` | `/api/reservation/import-previews/{preview}` | One preview, built for a header row |
| `POST` | `/api/reservation/import-previews/{preview}/confirm` | Map and queue an import |
| `DELETE` | `/api/reservation/import-previews/{preview}` | Discard the preview and its file |

## `POST /api/reservation/import-previews`

`multipart/form-data`:

| Field | Rules |
| --- | --- |
| `file` | required; `xlsx` / `xls` / `csv` / `txt`; max 5 MB (same as the direct upload) |
| `hotel_id` | required for a super admin, ignored for anyone else (`resolveHotel()`) |

**`201 Created`**: `body` is the [Preview resource](#preview-resource), built for the
suggested header row.

| Error | When |
| --- | --- |
| `422` `file` | missing, wrong type, too large, or unreadable ("The file could not be read…") |
| `422` `hotel_id` | a super admin without a valid hotel |

## `GET /api/reservation/import-previews`

`GenericIndexRequest` query: `per_page` and `page`; always sorted `-created_at`. Expired
previews are never listed. Each item is the Preview resource **without** `rows`, `columns`
and `fields`, to keep the list light.

## `GET /api/reservation/import-previews/{preview}`

| Query | Rules |
| --- | --- |
| `header_row` | optional; integer 1–10 and ≤ the file's last row. Default: `suggested_header_row` |

**`200`**: the Preview resource built for that header row. **`410`** if expired:
"This upload has expired. Upload the file again." **`422` `header_row`** if out of range:
"The file has only N rows."

## Preview resource

```json
{
  "id": "01a1…",
  "hotel_id": "3b2a…",
  "file_name": "reservations-sept-2025.xlsx",
  "uploaded_by": { "id": "…", "name": "Sara Ali" },
  "created_at": "2026-10-23T09:00:00Z",
  "expires_at": "2026-10-24T09:00:00Z",
  "header_row": 4,
  "suggested_header_row": 1,
  "data_rows": 438,
  "rows": [
    ["Grand Harbor Hotel"],
    ["Reservations report"],
    ["01/09/2025 - 30/09/2025"],
    ["Conf. No.", "Guest Name", "Mob.", "Arr. Date", "Dep. Date", "Nationality"],
    ["GHH-784108", "Ahmed Saleh", "+20 100 123 4567", "12/09/2025", "15/09/2025", "EG"]
  ],
  "columns": [
    { "position": 0, "letter": "A", "header": "Conf. No.", "samples": ["GHH-784108", "GHH-784119"], "suggested_field": "reservation_reference" },
    { "position": 1, "letter": "B", "header": "Guest Name", "samples": ["Ahmed Saleh"], "suggested_field": "guest_full_name" },
    { "position": 2, "letter": "C", "header": "Mob.", "samples": ["+20 100 123 4567"], "suggested_field": null },
    { "position": 5, "letter": "F", "header": "Nationality", "samples": ["EG"], "suggested_field": null }
  ],
  "fields": [
    { "key": "arrival_date", "label": "Arrival date", "required": true, "guest_identifier": false },
    { "key": "guest_phone", "label": "Guest phone", "required": false, "guest_identifier": true }
  ],
  "imports": [
    { "id": "01a1…", "dry_run": true, "status": "completed", "created_at": "2026-10-23T09:05:00Z" }
  ]
}
```

- `rows`: the first 10 rows as displayed (dates formatted), for choosing the header row.
- `columns`: built from `header_row`. A blank header shows as `"Column F"`. `samples`
  holds up to 5 non-empty values from the rows below the header.
- `suggested_field` comes from the hotel's saved mapping, then from the known header names,
  then null (FR-006 to FR-008). An explicit "unmapped" in the saved mapping is `null`.
- `suggested_header_row` is the header row the hotel used last, or 1.
- `imports` lists the imports already started from this preview, newest first.

## `POST /api/reservation/import-previews/{preview}/confirm`

JSON body:

```json
{
  "header_row": 4,
  "columns": { "0": "reservation_reference", "1": "guest_full_name", "2": "guest_phone",
               "3": "arrival_date", "4": "departure_date", "5": null },
  "date_order": "day_first",
  "decimal_mark": "dot",
  "dry_run": true
}
```

| Field | Rules |
| --- | --- |
| `header_row` | required; integer 1–10, ≤ the file's last row |
| `columns` | required object: sheet position → field key or `null`. Positions left out are unmapped |
| `date_order` | optional; `day_first` (default) or `month_first` |
| `decimal_mark` | optional; `auto` (default), `dot` or `comma` |
| `dry_run` | optional boolean; default `false` |

**`202 Accepted`**: `body` is the SPEC-100 Import resource with `status: "queued"`, plus
the new fields below. The hotel's saved mapping is replaced with this one.

| Error | When |
| --- | --- |
| `410` | the preview has expired |
| `422` `header_row` | out of range |
| `422` `columns` | one message per problem: unknown position, unknown field, field mapped twice, arrival or departure date not mapped, no guest identifier mapped, full name mapped with first or last name |
| `422` `date_order` / `decimal_mark` | not one of the allowed values |

Nothing is created and the saved mapping is untouched when any error is returned.

The same preview can be confirmed again (for example, a dry run and then a real run). Each
confirm creates its own import.

## `DELETE /api/reservation/import-previews/{preview}`

**`200`**, empty body: "Upload discarded." The file is deleted at once. Imports already
started from the preview are unaffected. Their `preview_id` becomes null.

## Changes to existing endpoints (additive)

**`POST /api/reservation/import`** accepts an optional `decimal_mark` (`auto` / `dot` /
`comma`, default `auto`). Headers are still matched by name only and row 1 is the header.
It also now recognizes a full-name column (`Guest Name`, `Name`, `Full Name`).

**Import resource**: new fields, on every import endpoint:

```json
{
  "preview_id": "01a1…",
  "header_row": 4,
  "column_map": { "0": "reservation_reference", "5": null },
  "decimal_mark": "dot"
}
```

`preview_id` and `column_map` are null for direct uploads. `unrecognized_columns` lists the
headers the admin left unmapped.
