# Frontend Changes (`ecosystem-frontend`): 016 Reservation Import Column Mapping

This is for the frontend team. The full text is in `docs/frontend-changes-2026-10-24.md`,
which is the copy to sync.

Summary:

- **New default import flow:** upload a preview, then map the columns, then confirm.
  Confirming returns the same `202` import record as today.
- **Mapping screen:**
  - a header-row picker over the first 10 rows;
  - a columns table with samples and a field dropdown (one column per field; required and
    guest-identifier checklist; full name excludes first and last name);
  - date order, a new decimal mark setting, and dry run.
- **Dry run, then import for real,** confirms the same preview again: no re-upload.
- **Open uploads:** list, continue or discard; `410` when an upload has expired (24 h).
- **Import record:** new fields `preview_id`, `header_row`, `column_map` and
  `decimal_mark`.
- **Not breaking:** the direct upload and the existing import screens are unchanged.
