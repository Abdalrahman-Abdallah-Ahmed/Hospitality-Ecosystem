# Frontend Changes: 2026-10-23 — Reservation Import v2 (SPEC-100/101)

For `ecosystem-frontend`. The import screen must change: the upload no longer returns
results. Ship §1 with the backend release.

Backend spec: `specs/013-reservation-import-v2/`. API reference: §6 of
`docs/reservations-api-documentation.md`. Contract:
`specs/013-reservation-import-v2/contracts/reservation-import-api.md`.

## 1. Required: the upload is queued

`POST /api/reservation/import` now answers `202` with an import record, not
`{ imported, skipped }`. After uploading:

1. Keep `body.id`.
2. Poll `GET /api/reservation/imports/{id}` every few seconds while `status` is `queued`
   or `running`.
   - Show `progress.processed_rows / progress.total_rows`.
   - Show "waiting for the previous import" while `queued`.
3. When `status` is `completed`, show `totals` (imported, skipped, duplicate, invalid,
   unresolved) and open the report (§3).
4. When `status` is `failed`, show `failure_reason` as written: it tells staff what to do.

## 2. Upload form

- **Date format** (`date_order`): "Day first (12/09 = 12 Sep)" (default) or "Month first
  (12/09 = 9 Dec)".
- **Check without importing** (`dry_run`): runs everything and shows the report, but
  writes nothing. Offer "Import for real" afterwards, which uploads the same file again
  without `dry_run`.
- **Hotel picker** for super admins only (`hotel_id`, required for them).
- The file rules are unchanged: `.xlsx`, `.xls`, `.csv`, max 5 MB.

## 3. Report view

- **Summary**: the totals, plus `created_rooms`, `created_room_types` (labelled "would be
  created" on a dry run) and `unrecognized_columns` ("these columns were ignored").
- **Rows table**: `GET /api/reservation/imports/{id}/rows`.
  - Filter tabs by `outcome` (invalid, unresolved, duplicate, skipped, imported), plus a
    "with warnings" filter (`has_warnings=1`).
  - Columns: row number, reference, outcome, the fields involved, the reason, and the
    warnings.
  - For `duplicate` and `imported` rows, link to the reservation through `reservation_id`.
- **Warnings**: show `message`; the `code` can drive an icon. Codes: `no_phone`,
  `no_room_assigned`, `room_clash`, `status_corrected`, `data_differs`, `restored`,
  `phone_country_unknown`, `phone_unrecognized`, `booking_unresolved`.
- **Download**: `GET /api/reservation/imports/{id}/report` returns an `.xlsx` file. It
  returns `409` while running, so enable the button only when the import has finished.

## 4. Import history

`GET /api/reservation/imports` lists the hotel's imports, newest first. It takes the usual
`per_page`, `filter[status]` and `filter[dry_run]` parameters. Show the file name,
uploader, dates, status and totals, and open the report from each entry.

## 5. Permissions

Everything above uses `reservations.import`. An employee without it gets `403`, so hide
the import menu for them.

## 6. Response shapes

Upload (`202`) and `GET /api/reservation/imports/{id}` return the same import record:

```json
{
  "id": "01a1…", "hotel_id": "3b2a…", "file_name": "reservations.xlsx",
  "uploaded_by": { "id": "…", "name": "Sara Ali" },
  "dry_run": false, "date_order": "day_first",
  "status": "running", "stage": "import",
  "progress": { "processed_rows": 1200, "total_rows": 5000 },
  "totals": { "imported": 1100, "skipped": 0, "duplicate": 60, "invalid": 30, "unresolved": 10 },
  "created_rooms": ["214"], "created_room_types": ["Dbl Deluxe"],
  "unrecognized_columns": ["Fax"], "warnings_count": 42,
  "failure_reason": null,
  "created_at": "…", "started_at": "…", "finished_at": null
}
```

- Numbers are always integers. The upload response has `0`s, never `null`.
- `total_rows` is `0` until the file has been read, so guard the progress bar against
  dividing by zero.
- `uploaded_by` can be `null` if the uploader's account was deleted.

A row from `/rows`:

```json
{ "row_number": 418, "external_reference": "BK-77812", "outcome": "invalid", "stage": "validate",
  "fields": ["arrival_date"], "reason": "\"2026-13-45\" in arrival_date is not a date this import can read…",
  "warnings": [{ "code": "no_phone", "message": "…" }], "reservation_id": null }
```

## 7. Behaviour staff will notice

Show these as written: the backend's `reason` and `message` are meant for staff.

- **A failed import** still accounts for every row. Rows it never reached are `invalid`
  with "Not imported: the import stopped before this row was written. Upload the file
  again to import it." Suggest re-uploading: rows already imported come back as
  `duplicate`.
- **Re-uploading the same file** is safe and creates nothing twice. Expect mostly
  `duplicate`.
- **Amounts** like `1,200` or `1.200` are rejected as ambiguous. Staff should write `1200`,
  `1200.00` or `1.200,00`.
- **Uploads queue per hotel.** A second upload shows `queued` until the first finishes.
