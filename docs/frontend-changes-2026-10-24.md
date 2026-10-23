# Frontend Changes: 2026-10-24 — Reservation Import Column Mapping (016)

For `ecosystem-frontend`. This adds a mapping step to the reservation import, so admins
can import exports whose column names the system doesn't know. **Nothing existing
breaks**: the current upload, status, rows and report screens keep working unchanged. Ship
this whenever it's ready.

Backend spec: `specs/016-import-column-mapping/`. API reference: §6 "Column mapping
(preview)" of `docs/reservations-api-documentation.md`. Contract:
`specs/016-import-column-mapping/contracts/import-preview-api.md`.

## 1. New flow: upload → map → import

Make this the default way to import. Keep the direct upload as a fallback, for example
behind "Import without mapping".

1. **Upload**: `POST /api/reservation/import-previews` (`multipart/form-data`: `file`,
   plus `hotel_id` for super admins). The file rules are unchanged: `.xlsx`, `.xls`,
   `.csv`, max 5 MB. **`201`** with the preview; open the mapping screen with it.
2. **Mapping screen** (§2), built from the preview.
3. **Confirm**: `POST /api/reservation/import-previews/{id}/confirm` with the header row,
   the mapping and the settings (§3). **`202`** with the same import record as today, so
   go straight to the existing import status / report page and poll as now.

## 2. Mapping screen

**Header row picker.** Show `rows` (the first 10 rows of the sheet, as text) as a small
table, with `header_row` highlighted and a "This row has the column names" action on each
row. Choosing a row calls `GET /api/reservation/import-previews/{id}?header_row=N` and
redraws the columns. Start with `suggested_header_row`, which is already applied in the
upload response.
- Hint text: "Choose the row with the column names. Rows above it are ignored."
- `422` on `header_row` (the row is past the end of the file): show the message.

**Columns table**: one line per entry in `columns`.

| Column | Shows |
| --- | --- |
| Column | `letter` and `header` (a blank header arrives as "Column F") |
| Examples | `samples`, up to 5 values, e.g. comma-separated, truncated |
| Maps to | A dropdown of `fields` (by `label`), plus "Ignore this column" (`null`). Pre-select `suggested_field` |

Dropdown rules:
- **One column per field.** A field already chosen elsewhere is disabled, or moving it
  clears the other column. The server refuses duplicates anyway.
- **Mark required fields:** `required: true` (arrival date, departure date) and the
  `guest_identifier` group (phone, email, old-system guest id), where at least one must be
  mapped. Show a checklist above the table:
  - ✓/✗ Arrival date;
  - ✓/✗ Departure date;
  - ✓/✗ a guest phone, email or id.

  Disable "Import" until all three are ✓.
- **Full name:** "Guest full name" can't be combined with "Guest first name" / "Guest last
  name". Grey out one group while the other is in use.
- **Arabic:** translate `label` by `key`. The keys are listed in the API reference.

**Settings** (as today, plus one):
- **Date format** (`date_order`): unchanged.
- **Amounts** (`decimal_mark`), new: three choices.
  - "Automatic" (`auto`, the default): `1,200` alone is rejected as unclear.
  - "1,234.56" (`dot`): the dot is the decimal.
  - "1.234,56" (`comma`): the comma is the decimal.

  Showing the sample values of the column mapped to Reservation value next to this choice
  helps the admin pick.
- **Check without importing** (`dry_run`): unchanged.

**Buttons:**
- "Check without importing" confirms with `dry_run: true`.
- "Import" confirms with `dry_run: false`.

After a dry run, offer "Import for real". This confirms **the same preview** again with
`dry_run: false`, with no re-upload, which is the big difference from today.

## 3. Confirm request and errors

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

- Send every column's choice. A position you leave out is ignored, the same as `null`.
- **`422` with `errors.columns`**: an array of messages, all problems at once. Show them
  above the table. Nothing was created.
- **`410`**: the upload expired (24 h). Show "This upload has expired. Upload the file
  again." and go back to the upload step.

## 4. Open uploads

`GET /api/reservation/import-previews` lists the hotel's unfinished uploads, newest first,
paginated. The list entries have no `rows`, `columns` or `fields`.

Show them on the import page as "Uploads waiting to be mapped": file name, uploader,
uploaded at, "expires in X h" from `expires_at`, and how many imports were started from
it (`imports`).
- **Continue**: `GET /api/reservation/import-previews/{id}`, then the mapping screen.
- **Discard**: `DELETE /api/reservation/import-previews/{id}` → `200`; remove the entry.

## 5. Import record: new fields

Every import record (status page, history) now also has:

```json
{ "preview_id": "01a1…", "header_row": 4,
  "column_map": { "0": "reservation_reference", "5": null }, "decimal_mark": "dot" }
```

On the status / report page, optionally show "Header row 4 · Amounts 1,234.56". For
direct uploads, `preview_id` and `column_map` are `null`. `unrecognized_columns` now also
lists the columns the admin chose to ignore.

## 6. Remembered mapping

There is nothing to build for this. After the first confirm, the next upload's
`suggested_field`s and `suggested_header_row` come from that hotel's last mapping. Still,
make it visible, e.g. "Mapped like your last import", when every column arrives with a
suggestion.

## 7. Permissions and states

- **Permission:** everything uses `reservations.import`, the same as the import menu
  today. Another hotel's upload is a `404`.
- **Loading:** the upload response can take a few seconds for a large file; show progress
  on the upload. Header-row changes are fast.
- **Empty:** a sheet with headers but no data shows `data_rows: 0`. Allow confirming; it
  completes with nothing imported.
- **Errors:** an unreadable file is a `422` on `file` from the upload. Show its message.
