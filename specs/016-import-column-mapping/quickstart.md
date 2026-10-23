# Quickstart: Reservation Import Column Mapping

How to check that `016-import-column-mapping` works end to end. Endpoint details are in
[contracts/import-preview-api.md](contracts/import-preview-api.md); data and rules are in
[data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
php artisan migrate
```

For manual runs, start a worker (`composer dev` runs `queue:listen`). To run against a
throwaway database, use `Hospitality_Ecosystem_staging` (created for T059 on 2026-10-23)
with `DB_DATABASE=Hospitality_Ecosystem_staging`, never the dev database.

## Run

```bash
php artisan test --parallel --filter='ReservationImport'
php artisan test --parallel --filter='TenantIsolation|PermissionAuthorization'
php artisan test --parallel
./vendor/bin/pint
```

## Acceptance scenarios

| # | Scenario | Expected outcome |
| --- | --- | --- |
| 1 | Upload a file whose arrival, departure and phone headers are unknown (`Arr. Date`, `Dep. Date`, `Mob.`). | `201`. Those columns have `suggested_field: null`; known ones are suggested. `data_rows` equals the data rows. |
| 2 | Confirm scenario 1 with the three columns mapped. | `202`, an import that completes with the same outcomes as the same file with known header names (SC-001). |
| 3 | Confirm without arrival date; with `guest_phone` on two columns; with position 99; with field `fax`; with full name plus first name. | `422`, one `columns` message per problem. No import is created and the saved mapping is unchanged (SC-006). |
| 4 | Upload a file with title lines on rows 1–3 and headers on row 4; `GET` it with `header_row=4`. | Columns and samples come from row 4, and `data_rows` = last row − 4. The import's row numbers are the file's own (row 5 is the first). |
| 5 | Upload a file whose headers are all known names, then confirm the suggestion unchanged. | Same outcomes as a direct upload of the same file (SC-002). |
| 6 | After a confirm that maps `Mob.` → `guest_phone` and leaves `Email` unmapped, upload a file with the same headers in another order. | `Mob.` is suggested `guest_phone`, `Email` is suggested null, and `header_row` is suggested as last time (SC-003). |
| 7 | Two hotels confirm different mappings for the same headers. | Each one's previews suggest its own mapping only. |
| 8 | Confirm a preview as a dry run, then confirm it again for real. | Two imports, both listed under the preview's `imports`. The file isn't uploaded twice. |
| 9 | Discard a preview that started an import. | `200`. The file is gone and the preview no longer listed; the import still opens, with `preview_id: null`. |
| 10 | Move a preview's `expires_at` into the past; `GET` and confirm it; run the prune job. | `410` for both, then `404` once it has been pruned; the file is deleted. |
| 11 | Full-name column with `Mohamed Abdel Rahman`, `Smith, John`, `Sara`. | Guests `Mohamed / Abdel Rahman`, `John / Smith`, `Sara / (empty)`. |
| 12 | Amounts `25,200`, `1,260.50`, `EGP 3,600`, `99,50` with `auto`, then `dot`, then `comma`. | `auto`: `25,200` and `EGP 3,600` invalid as today. `dot`: the first three read as 25200, 1260.50 and 3600; `99,50` invalid. `comma`: `99,50` → 99.50; `1,260.50` invalid. |
| 13 | Direct upload with `decimal_mark=dot` and a `Guest Name` column. | Amounts read as in 12. The full name is split, and the import shows `column_map: null`, `header_row: 1`. |
| 14 | An employee without `reservations.import` calls each preview endpoint; another hotel's admin opens this preview. | `403`; `404`. |
| 15 | A super admin uploads a preview without `hotel_id`, then with it. | `422`, then `201` for that hotel. |

## Synthetic export re-run (SC-004)

Regenerate the T059 synthetic file (generator in the 2026-10-23 session's scratchpad, or
any export with text amounts like `EGP 25,200`). Upload it as a preview and confirm it as a
dry run with `decimal_mark: dot`. No row should be invalid because of
`reservation_value`; 74 were on 2026-10-23.

## Preview timing (SC-005)

Upload a generated 5,000-row `.xlsx` (about 27 columns). The `201` must come back within
10 s of the upload finishing. Note the time here.

## Results (T046, 2026-10-24)

Run on `Hospitality_Ecosystem_staging` through the app's HTTP kernel (real token, API key,
controllers and requests), on the development machine (Windows, Postgres in Docker).

| Check | Result |
| --- | --- |
| SC-005: preview of a 5,046-row, 27-column generated `.xlsx` (506 KB) | `201` in **5.2 s** (target ≤ 10 s). All 23 columns the system has a field for were suggested correctly; `Nationality`, `Nights`, `Market Segment` and `Pax` had no suggestion |
| Header-row switch on the same preview | `200` in **31 ms**: no file read |
| SC-004: T059 synthetic export, preview → confirm with the suggestions, `decimal_mark: dot`, dry run | **0** rows invalid for `reservation_value` (74 on 2026-10-23). Of 444 rows: 343 duplicate (the 2026-10-23 real run is still on staging), 77 would be imported, 22 invalid (planted defects and guests with no contact), 2 skipped |
