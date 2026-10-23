# Latest changes — 2026-10-23

## Reservation import v2 and reconciliation (SPEC-100/101, Phase 13)

The reservation import now migrates real legacy PMS exports. The run happens in the
background, and every row ends with an outcome and a reason. This closes open item F11
(queued imports). Full reference: §6 of `docs/reservations-api-documentation.md`.

### Breaking

- **`POST /api/reservation/import` returns `202`** with an import record (`status:
  "queued"`), instead of `200 { imported, skipped[] }`. Poll
  `GET /api/reservation/imports/{id}` until `status` is `completed` or `failed`. Then read
  `GET /api/reservation/imports/{id}/rows`, or download
  `GET /api/reservation/imports/{id}/report`.
- **Rows the old import accepted or mishandled are now rejected**, each with a readable
  reason:
  - a departure on or before the arrival;
  - an unknown status label (it used to fail with a raw PHP enum error);
  - checked-out before the departure date, or no-show before the arrival date;
  - checked-in with a future arrival;
  - a `reservation_value` whose single separator could mean thousands or decimals
    (`1,200`, `1.200`). Write `1200`, `1200.00` or `1.200,00`.
- **A checked-in row whose departure has passed** is imported as checked out, with a
  `status_corrected` warning.
- **A reference-less row that repeats an existing reservation** (same guest, dates and room
  types) is now a `duplicate`, so importing the same file twice no longer doubles it.
- **Several unnumbered rows of the same room type in one reservation now make one room**;
  the extra rows add guests. Use `room_count` for more rooms of a type.
- **Guest phones are stored normalized** (digits with the country code, using the hotel's
  `country_code` for national numbers).

### New

- `GET /api/reservation/imports`, `GET /api/reservation/imports/{id}`,
  `GET /api/reservation/imports/{id}/rows` and `GET /api/reservation/imports/{id}/report`,
  all under the existing `reservations.import` permission.
- Upload fields `date_order` (`day_first` / `month_first`), `dry_run` and `hotel_id`.
  Super admins can now import by naming the hotel.
- Rows sharing a reference become one reservation with several rooms and guests.
- Header synonyms, legacy date formats and legacy status labels are accepted.
- Bookings are attached to known activities.
- Guests are matched by phone, email or old-system id (`guest_external_id`). Conflicting
  details are reported as `unresolved`, never merged.
- A hotel runs one import at a time, in upload order.
- One `hotel.reservations_imported` audit event per run, now including the import id.

### Unchanged

The file types, the 5 MB limit, the existing column names, auto-created rooms and room
types, and recording legacy data without capacity or availability checks.
