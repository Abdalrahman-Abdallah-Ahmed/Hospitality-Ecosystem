# Latest changes — 2026-11-03

## List exports (spec 021)

Reservations, activities, bookings and guests can be downloaded as CSV, Excel or SQL,
filtered and sorted exactly as their lists are. Spec: `specs/021-module-data-export`.

### Breaking

None. The four list endpoints behave as before.

### Added

- **Four endpoints**, each taking its list's `search`, `sort` and `filter[...]` plus
  `format` (`csv` default, `xlsx`, `sql`) and, for super admins only, a required
  `hotel_id`:
  - `GET /api/reservation/export` — see `docs/reservations-api-documentation.md`
  - `GET /api/activity/export` — see `docs/activity-api-documentation.md`
  - `GET /api/booking/export` — see `docs/booking-entity-documentation.md`
  - `GET /api/guest/export` — see `docs/guest-api-documentation.md`
- **Four permissions**: `reservations.export`, `activities.export`, `bookings.export`,
  `guests.export`. None is an employee default and viewing a list does not allow
  exporting it; the role editor lists them through `GET /api/permissions`. See
  `docs/staff-roles-api-documentation.md`.
- **Limits**: an export matching more than 10,000 records is refused with `422`
  (`body.matching`, `body.max_rows`), never cut short; `EXPORT_MAX_ROWS` changes the
  limit per deployment. Each user may run 10 exports a minute across all four (`429`).
- **Audit**: `hotel.<module>_exported` for each export and
  `hotel.<module>_export_refused` for a refusal over the limit.

### Frontend notes

- The success response is a file, not the `{ message, code, body }` envelope: download
  it as a blob and use the `Content-Disposition` file name. Errors stay JSON: the
  usual envelope, or the standard validation shape for a bad `format`/filter/sort.
- Pass the list's current filters and sort unchanged; `page` and `per_page` are ignored.
- Hide the export button unless the user holds `<module>.export`.
