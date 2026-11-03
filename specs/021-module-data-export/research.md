# Research: Module Data Export

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-11-03

Every unknown from the Technical Context is resolved below. Each entry starts from
what already exists in the codebase (constitution principle II).

## R1. Where the export endpoint lives and how it reuses the list query

**Decision**: Add an `export()` action to each module's existing controller
(`ReservationController`, `ActivityController`, `BookingController`, `GuestController`).
Each controller's `index()` query-building is moved into a private `listQuery($request): Builder`
that both `index()` and `export()` call. `GenericQuery` is split into
`GenericQuery::query()` (filter + search + sort, returns the builder) and the existing
`GenericQuery::apply()` (calls `query()` then paginates), so `index()` behaviour is unchanged.

**Rationale**:
- FR-002 requires the export to select *exactly* what the list selects. The list logic is
  not only in `GenericQuery`: `ReservationController::index` applies the virtual
  `room_type_id`/`room_id` line filters, and `BookingController::index` applies
  `activity_id`, `reservation_id`, `scheduled_from/to`, `cancellation_requested` and a
  default sort. One shared builder per module is the only way to keep the two from drifting.
- `GenericIndexRequest` resolves its model from the route's **controller name**
  (`ResolvesModelFromRoute::modelClass()`). An export request extending it only works
  on a route served by the module's own controller; a separate `ExportController` would
  break filter/sort column validation.

**Alternatives considered**:
- A single `ExportController` with `{module}` parameter: breaks model resolution and
  duplicates per-module filter logic.
- Calling `index()` internally with a huge `per_page`: `per_page` is capped at 100 and
  pagination + resources would load everything in memory.

## R2. Export request validation

**Decision**: New requests that extend the module's index request and add one rule through a
shared concern `App\Http\Requests\Concerns\ValidatesExportFormat`:
- `format`: `nullable`, `Rule::enum(ExportFormat::class)`, defaults to `csv`.
- `hotel_id`: `nullable|uuid`, only meaningful for super admins (R6).

| Module | Request |
| --- | --- |
| Reservations | `ReservationExportRequest extends ReservationIndexRequest` |
| Bookings | `BookingExportRequest extends BookingIndexRequest` |
| Activities, Guests | `GenericExportRequest extends GenericIndexRequest` |

`page` and `per_page` are accepted (inherited) and ignored: the export always covers every page.

New enum `App\Enums\ExportFormat`: `xlsx`, `csv`, `sql` (CLAUDE.md: enums, not string literals).

**Rationale**: unknown filter/sort columns are refused exactly as the list refuses them (spec edge case).
Defaulting to `csv` lets the generic cross-tenant `index` rule (R9) read a real text body.

## R3. Spreadsheet generation (Excel and CSV)

**Decision**: `maatwebsite/excel` (already installed, used by `ImportReportExport`). One
export class per module in `app/Support/Export/`, all extending an abstract
`ModuleExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithCustomCsvSettings, WithStrictNullComparison`.
The same class produces both `.xlsx` and `.csv` (`Excel::download($export, $name, Excel::XLSX|Excel::CSV)`).

- **Order**: `FromQuery` reads in chunks (`config/excel.php` `exports.chunk_size`, 1000).
  Chunked reads page with `LIMIT/OFFSET`, so the export appends `orderBy('<table>.id')` after
  the list's own sort as a tie-breaker; without it, rows with equal sort keys can repeat or
  vanish across chunks. Rows with distinct sort keys keep the list's order (SC-002).
- **Eager loading**: each module's `query()` adds the relations its columns need
  (e.g. reservations: `guest`, `reservationGuests.guest`, `reservationRooms.roomType`,
  `reservationRooms.room`), so a 10,000-row export runs a constant number of queries per chunk.
- **Typed cells (FR-009)**: dates are mapped to Excel serial dates
  (`PhpOffice\PhpSpreadsheet\Shared\Date::dateTimeToExcel`) with `WithColumnFormatting`
  date formats; money and counts are mapped as `float`/`int`.
- **CSV encoding (FR-010)**: `WithCustomCsvSettings` → `use_bom: true`, delimiter `,`,
  enclosure `"`. The UTF-8 BOM is what makes Excel open Arabic/Cyrillic correctly; quoting
  is handled by PhpSpreadsheet's CSV writer.
- **Formula injection (FR-011)**: every string cell goes through
  `SpreadsheetSafe::text()`, which prefixes a `'` when the value starts with `=`, `+`, `-`,
  `@`, tab or carriage return (OWASP CSV-injection guidance). Applied in `ModuleExport::map()`
  so no module can forget it. Numbers and dates are not strings and are not touched.
- **Labels (FR-008)**: enum columns use the enum's existing label method where one exists,
  otherwise `Str::headline($enum->value)`.

**Alternatives considered**: `openspout` directly (faster, but a second spreadsheet
library for no measured need at 10,000 rows); hand-written CSV (`fputcsv`) for CSV only
(two code paths for the same columns).

## R4. SQL export

**Decision**: `App\Support\Export\SqlDump`, a small writer that streams
`INSERT INTO "<table>" (<cols>) VALUES (...);` lines through `response()->streamDownload()`,
reading the same filtered, list-ordered builder with `cursor()` (one query, rows
fetched one at a time, flat memory). `lazyById()` is not used because it would replace the
list's sort with id order.

- **Columns**: the table's actual columns (`Schema::getColumnListing`) minus a per-module
  exclusion list (FR-013). Kept: `id`, `hotel_id` and every `*_id` foreign key as stored
  (clarification Q4). Excluded today: `bookings.context` (AI/recommendation processing
  context). Guests, reservations and activities have no internal-only columns at present;
  the exclusion list is per module so a later vector or processing column is a one-line change.
- **Values** read raw from the model's attributes (`getRawOriginal()`), not casts:
  - `null` → `NULL` (FR-012).
  - booleans → `TRUE` / `FALSE`.
  - integers and decimals → unquoted numeric literal.
  - everything else (text, dates, timestamps, json/jsonb) → quoted with `DB::getPdo()->quote()`,
    which escapes for the connection's dialect (single quotes doubled under Postgres standard strings).
- **Header**: a comment block naming module, hotel, export time, row count and filters;
  wrapped in `BEGIN; … COMMIT;` so a partial load does not leave half the rows.
- **Dialect**: PostgreSQL (the system's own engine). Other engines are out of scope (Assumption).

**Rationale**: maatwebsite has no SQL writer; `pg_dump` cannot apply the list's filters
or tenant scope and needs shell access. PDO quoting is the safe escape (SC-003, Story 3 scenario 2).

**Alternatives considered**: `COPY` format (not "INSERT statements" as chosen by the user);
bulk multi-row INSERT (harder to read and diff; per-record INSERT is what the spec states).

## R5. Row cap

**Decision**: `config/exports.php` → `'max_rows' => (int) env('EXPORT_MAX_ROWS', 10000)`.
Before building any file, the exporter runs `(clone $query)->count()` (no eager loads, no
order). If it exceeds `max_rows`: `apiResponse('…', 422, ['matching' => $n, 'max_rows' => $cap])`.
Exactly `max_rows` succeeds.

The refusal **is** audited (`hotel.<module>_export_refused`, with the count and filters):
the spec left this as SHOULD; recording it costs one row and makes bulk-extraction attempts
visible, which matches constitution principle VI.

**Rationale**: counting first is cheap and avoids any partial file (FR-016). 422 matches how
the API reports a request it understood but will not perform with these inputs.

## R6. Hotel resolution and tenant scope

**Decision**: `resolveHotel($request->user(), $request->validated('hotel_id'))`.
- Scoped admin/employee: their own hotel (any `hotel_id` sent is ignored by `resolveHotel`).
- Super admin: must send `hotel_id`; otherwise `403 'You must belong to, or specify, a valid hotel.'`
  (the existing message used by `store()` actions).

The list builder already runs under the `BelongsToHotel` global scope; the export adds
`->where('<table>.hotel_id', $hotel->id)` so a super admin's export is one hotel (FR-003) and
the audit entry and file name have a definite hotel.

## R7. Permissions and authorization

**Decision**: four new `Permission` cases, grouped with their resource:
`RESERVATIONS_EXPORT = 'reservations.export'`, `ACTIVITIES_EXPORT = 'activities.export'`,
`BOOKINGS_EXPORT = 'bookings.export'`, `GUESTS_EXPORT = 'guests.export'`.
Not added to `employeeDefaults()` (FR-019). New policy ability `export(User $user)` on
`ReservationPolicy`, `ActivityPolicy`, `BookingPolicy`, `GuestPolicy`, each
`return $this->allows($user, Permission::X_EXPORT);`. Super admins pass via the policies'
existing `before()`. `export` does **not** imply or require `view` checks beyond the permission
itself: FR-021 only requires that view alone is insufficient.

`GET /api/permissions` reads the enum, so the role editor shows them (FR-020) with no extra change.

## R8. Rate limit

**Decision**: `RateLimiter::for('exports', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->getAuthIdentifier() ?: $r->ip()))`
in `AppServiceProvider::throttleAuthentication()` (next to `api`; the method name stays, it
already holds the authenticated `api` limiter). Applied as `->middleware('throttle:exports')`
on the four export routes only, on top of the group's `throttle:api`. One named limiter keyed
by user means the 10/minute budget is shared across modules (clarification Q3).
429 rendering is the framework's existing throttle response.

## R9. Routes and entry-point inventory

**Decision**:

| Method | URI | Action |
| --- | --- | --- |
| GET | `api/reservation/export` | `ReservationController@export` |
| GET | `api/activity/export` | `ActivityController@export` |
| GET | `api/booking/export` | `BookingController@export` |
| GET | `api/guest/export` | `GuestController@export` |

Each is registered **before** its `Route::resource(...)` / `{booking}` route, otherwise
`export` binds as a model id and 404s. GET because the export reads data and takes the same
query string as the list.

These are tenant routes with no parameters, so `CrossTenantRouteTest`'s `index` rule drives them
automatically: it requests the route as Hotel A and checks no Hotel B id appears in the body,
which works because the default format is CSV (text). The dedicated `ExportControllerTest`
isolation tests remain the precise proof (CLAUDE.md "Any new tenant-scoped feature needs a test
proving another hotel cannot see it"). If discovery does not classify them as `index`, they are
listed in `tests/Inventory/entry_points.php` with `covered_by` naming that test.

## R10. Audit entry

**Decision**: `EventLogger::record($hotel, '<module>_exported', changes: [...])`, following the
`transactions_imported` pattern (subject = hotel, event type `hotel.reservations_exported`).
`changes` holds `format`, `filter`, `search`, `sort`, booking/reservation extra filters,
and `row_count`. The actor comes from `Auth::user()` as for every other event. No record
content goes into the entry (constitution: audit records must not expose sensitive data).

## R11. File name

**Decision**: `{module}-{hotel-slug}-{Ymd-Hi}.{xlsx|csv|sql}` using `Str::slug($hotel->name)`
and the current time, e.g. `reservations-grand-palace-20261103-1430.xlsx` (FR-014).

## R12. Performance at the cap

10,000 reservations with 4 eager-loaded relations in 1,000-row chunks is ~50 queries and a few
seconds of PhpSpreadsheet time; XLSX at this size needs roughly 100–150 MB peak with the default
cell cache, which fits the 2 GB host but is the heaviest path. CSV and SQL stream with flat memory.
SC-001 (< 30 s at 10,000 rows) is verified with a seeded 10,000-row run in quickstart step 6;
if XLSX misses it, switch on cell caching (`cache.driver` in `config/excel.php`) before changing anything else.
