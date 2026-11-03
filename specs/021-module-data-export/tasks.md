---
description: "Task list for Module Data Export (spec 021)"
---

# Tasks: Module Data Export

**Input**: Design documents from `/specs/021-module-data-export/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/export-endpoints.md](contracts/export-endpoints.md), [quickstart.md](quickstart.md)

**Tests**: Included. CLAUDE.md requires a tenant-isolation test for every tenant feature and a
permission test (allowed with / 403 without) for every new permission; the spec's success
criteria (SC-002 … SC-008) are verified by feature tests.

**Organization**: Grouped by user story. US4 (permissions, P2) is mostly foundational because
no export can be authorized without it; its phase holds the role-gating tests and docs.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1 reservations spreadsheets · US2 activities/bookings/guests spreadsheets · US3 SQL · US4 role gating

## Conventions that apply to every task

- Tests: Pest, `uses(RefreshDatabase::class)` per file, `beforeEach` sets `putenv('API_KEY=test-api-key')` + `config(['app.api_key' => 'test-api-key'])`, requests send `X-API-KEY`. Only `UserFactory` exists; build other models with `Model::create([...])`. File-local helper functions for fixtures (see `tests/Feature/TaskControllerTest.php`).
- Run with `php artisan test --parallel` against the Postgres container (`docker compose -f .postgres/compose.yaml up -d`).
- Errors always via `apiResponse(string $message, int $code, mixed $body)`; only the successful file is a raw response.
- Run `./vendor/bin/pint` before finishing.

---

## Phase 1: Setup

**Purpose**: configuration and the format enum

- [X] T001 [P] Create `config/exports.php` returning `['max_rows' => (int) env('EXPORT_MAX_ROWS', 10000)]` with a one-line comment that above this an export is refused, never truncated (FR-016); add `EXPORT_MAX_ROWS=10000` to `.env.example`
- [X] T002 [P] Create backed enum `App\Enums\ExportFormat` in `app/Enums/ExportFormat.php` with cases `XLSX = 'xlsx'`, `CSV = 'csv'`, `SQL = 'sql'` and methods `extension(): string` (same as value) and `contentType(): string` returning exactly: xlsx → `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, csv → `text/csv; charset=UTF-8`, sql → `application/sql; charset=UTF-8`

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: shared list query, permissions, policies, request validation, rate limit, and the spreadsheet/exporter core every story uses

**⚠️ CRITICAL**: no user story work can begin until this phase is complete

### Shared list query (research R1)

- [X] T003 Split `app/Support/RequestRules/GenericQuery.php`: add `public static function query(Builder $query, GenericIndexRequest $request): Builder` containing today's filter + search + sort logic (returns the builder, no pagination), and make `apply()` call `self::query($query, $request)->paginate($request->integer('per_page', 15))`. `apply()` behaviour must be byte-for-byte unchanged
- [X] T004 In `app/Http/Controllers/ReservationController.php` move everything `index()` does before `GenericQuery::apply()` (the `RELATIONS` eager load, the `LINE_FILTERS` `whereHas('reservationRooms', ...)` loop, the `$request->merge(['filter' => $filters])`) into `private function listQuery(ReservationIndexRequest $request): Builder` that ends with `return GenericQuery::query($query, $request);`; `index()` becomes `listQuery($request)->paginate($request->integer('per_page', 15))` wrapped in the same `apiResponse(...)`
- [X] T005 [P] Same refactor in `app/Http/Controllers/BookingController.php`: `private function listQuery(BookingIndexRequest $request): Builder` holds the `with(['guest','activity','recommendation'])`, `withExists('openCancellationRequest as cancellation_requested')`, the five `when(...)` filters and the default `scheduled_date/scheduled_time/scheduled_for` sort, then `GenericQuery::query(...)`; `index()` paginates it
- [X] T006 [P] Same refactor in `app/Http/Controllers/ActivityController.php` (`Activity::with('category')`) and `app/Http/Controllers/GuestController.php` (`Guest::with(['hotel','reservations','conversations','stays'])`), each with `private function listQuery(GenericIndexRequest $request): Builder`
- [X] T007 Run the existing list tests to prove the refactor changed nothing: `php artisan test --parallel --filter="ReservationController|BookingController|ActivityController|GuestController|TenantIsolationTest|CrossTenantRouteTest"`

### Permissions and policies (research R7, constitution IV)

- [X] T008 Add four cases to `app/Enums/Permission.php`, each placed with its resource group: `ACTIVITIES_EXPORT = 'activities.export'` after `ACTIVITIES_DELETE`; `BOOKINGS_EXPORT = 'bookings.export'` after `BOOKINGS_OVERRIDE_CAPACITY`; `GUESTS_EXPORT = 'guests.export'` after `GUESTS_DELETE`; `RESERVATIONS_EXPORT = 'reservations.export'` after `RESERVATIONS_MOVE_ROOM`. Do **not** add any of them to `employeeDefaults()` (FR-019); add a short comment above `employeeDefaults()`'s list noting exports are deliberately excluded because they take personal data out of the system
- [X] T009 [P] Add `public function export(User $user): bool { return $this->allows($user, Permission::RESERVATIONS_EXPORT); }` with a docblock to `app/Policies/ReservationPolicy.php`
- [X] T010 [P] Add the same `export()` ability with `Permission::ACTIVITIES_EXPORT` to `app/Policies/ActivityPolicy.php`
- [X] T011 [P] Add the same `export()` ability with `Permission::BOOKINGS_EXPORT` to `app/Policies/BookingPolicy.php`
- [X] T012 [P] Add the same `export()` ability with `Permission::GUESTS_EXPORT` to `app/Policies/GuestPolicy.php` (check each policy has the super-admin `before()`; if one does not, rely on `allows()` exactly as its other abilities do)

### Request validation (research R2)

- [X] T013 Create trait `app/Http/Requests/Concerns/ValidatesExportFormat.php` with `protected function exportRules(): array` returning `['format' => ['nullable', Rule::enum(ExportFormat::class)], 'hotel_id' => ['nullable', 'uuid']]` and `public function format(): ExportFormat` returning `ExportFormat::from($this->validated('format') ?? 'csv')`
- [X] T014 [P] Create `app/Http/Requests/Generic/GenericExportRequest.php` extending `GenericIndexRequest`, using `ValidatesExportFormat`, `rules()` = `[...parent::rules(), ...$this->exportRules()]`; docblock: same filters/search/sort as the list, `page`/`per_page` accepted and ignored
- [X] T015 [P] Create `app/Http/Requests/ReservationExportRequest.php` extending `ReservationIndexRequest` (keeps `room_type_id`/`room_id` virtual filters) with the same trait and `rules()` merge
- [X] T016 [P] Create `app/Http/Requests/BookingExportRequest.php` extending `BookingIndexRequest` (keeps `activity_id`, `reservation_id`, `scheduled_from`, `scheduled_to`, `cancellation_requested`) with the same trait and `rules()` merge
- [X] T017 Add a PHPDoc line to each `listQuery()` from T004–T006 stating it serves both `index()` and `export()`; the export requests extend the index requests, so the existing parameter types accept them unchanged

### Rate limit (research R8)

- [X] T018 In `app/Providers/AppServiceProvider.php` `throttleAuthentication()`, after the `api` limiter, add `RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));` with a comment: one budget per user shared by all four export endpoints (clarification Q3), on top of `throttle:api`

### Spreadsheet core (research R3)

- [X] T019 [P] Create `app/Support/Export/SpreadsheetSafe.php` with `public static function text(?string $value): ?string` that returns the value prefixed with `'` when it starts with `=`, `+`, `-`, `@`, `"\t"` or `"\r"` (FR-011), otherwise unchanged; `null` stays `null`
- [X] T020 Create abstract `app/Support/Export/ModuleExport.php` implementing `FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithCustomCsvSettings, WithStrictNullComparison, WithTitle`. Constructor takes `Builder $query`. `query()` returns `(clone $this->query)->with($this->relations())->orderBy($this->query->getModel()->qualifyColumn('id'))` (cloned so repeated calls never stack the sort) (tie-breaker after the list sort, R3). Abstract: `columns(): array` (header ⇒ closure taking the model), `relations(): array`, `title(): string`, `dateColumns(): array`, `dateTimeColumns(): array`. `headings()` = `array_keys(columns())`. `map($row)` runs each closure and passes every `string` result through `SpreadsheetSafe::text()`; `DateTimeInterface` results become `PhpOffice\PhpSpreadsheet\Shared\Date::dateTimeToExcel()` numbers. `columnFormats()` maps date columns to `NumberFormat::FORMAT_DATE_YYYYMMDD` and date-time columns to `'yyyy-mm-dd hh:mm'`. `getCsvSettings()` = `['use_bom' => true, 'delimiter' => ',', 'enclosure' => '"']`. Add protected helpers `label(?BackedEnum $enum): ?string` (enum's `label()` if it exists, else `Str::headline($enum->value)`), `yesNo(?bool)`, `fullName(?Model $person)` (`trim(first_name.' '.last_name)`, `null` when missing), `joined(iterable $values)` (unique non-empty values joined with `', '`)
- [X] T021 In `ModuleExport`, make CSV dates readable: when the export is being written as CSV, date/date-time values are emitted as `Y-m-d` / `Y-m-d H:i` strings instead of Excel serials. Pass the `ExportFormat` into the constructor and branch in `map()`

### Exporter service (research R5, R6, R10, R11)

- [X] T022 Create `app/Services/Exports/DataExporter.php` with `public function download(string $module, Builder $query, ExportFormat $format, Hotel $hotel, Request $request, callable $spreadsheet): Response|JsonResponse` where `$module` is one of `reservations|activities|bookings|guests` and `$spreadsheet` is `fn (ExportFormat $f) => ModuleExport`. Steps:
  1. `$matching = (clone $query)->toBase()->getCountForPagination();` (count without eager loads/order).
  2. If `$matching > config('exports.max_rows')`: `EventLogger::record($hotel, "{$module}_export_refused", changes: ['format' => $format->value, ...$this->filters($request), 'matching' => $matching, 'max_rows' => $cap]);` and return `apiResponse("This export matches {$matching} records; the limit is {$cap}. Narrow the filters and try again.", 422, ['matching' => $matching, 'max_rows' => $cap])`. Exactly `max_rows` must succeed.
  3. `EventLogger::record($hotel, "{$module}_exported", changes: ['format' => ..., ...$this->filters($request), 'row_count' => $matching]);`
  4. File name `"{$module}-".Str::slug($hotel->name).'-'.now()->format('Ymd-Hi').'.'.$format->extension()` (FR-014).
  5. xlsx → `Excel::download($spreadsheet($format), $name, \Maatwebsite\Excel\Excel::XLSX)`; csv → same with `Excel::CSV` and header `Content-Type: text/csv; charset=UTF-8`; sql → until US3 lands, `apiResponse('SQL export is not available yet.', 422)`.
  `filters()` returns only `search`, `sort`, `filter` and the booking extras (`activity_id`, `reservation_id`, `scheduled_from`, `scheduled_to`, `cancellation_requested`) that are present — never record content
- [X] T023 Add a private helper used by every `export()` action, as a trait `app/Http/Controllers/Concerns/ExportsLists.php` with `protected function exportList(string $module, string $modelClass, Builder $query, Request $request /* has format() */, callable $spreadsheet)`: `$this->authorize('export', $modelClass)`; `$hotel = resolveHotel($request->user(), $request->validated('hotel_id'))`, if null `return apiResponse('You must belong to, or specify, a valid hotel.', 403)`; `$query->where($query->getModel()->qualifyColumn('hotel_id'), $hotel->id)`; delegate to `DataExporter::download(...)`. Authorization runs before any count or file work. Note: the form request validates before the action runs, so a request with invalid parameters gets 422 even from a user without the permission; that is expected and documented in the contract

**Checkpoint**: foundation ready — permissions exist, list behaviour unchanged, exporter core in place

---

## Phase 3: User Story 1 — Export filtered reservations to a spreadsheet (P1) 🎯 MVP

**Goal**: `GET /api/reservation/export` returns the filtered reservation list as CSV or Excel, capped, rate-limited and audited.

**Independent Test**: seed 40 reservations for one hotel, 12 matching a filter; export CSV and XLSX; files contain exactly those 12 in list order with the data-model columns; audit row present.

### Tests for User Story 1 (write first, expect failure)

- [X] T024 [US1] Create `tests/Feature/ExportControllerTest.php` with file-local helpers `exportHotel()`, `exportAdmin($hotel)`, `exportHeaders()`, `reservationFor($hotel, array $overrides)`, `csvRows(TestResponse $r): array` (strips the UTF-8 BOM, parses with `str_getcsv` per line respecting quoted newlines) and `xlsxRows(TestResponse $r): array` (writes `streamedContent()`/`getFile()` to a temp file and reads it with `PhpOffice\PhpSpreadsheet\IOFactory::load`). Add reservation tests:
  - filtered CSV has a header row equal to the data-model Reservations headers in order and exactly the matching rows, in the same order as `GET /api/reservation` with the same `filter`/`sort` across 3 pages (Story 1 scenarios 1, 3; SC-002)
  - XLSX: same rows; Arrival/Departure cells are numeric date cells, Reservation Value is numeric (scenario 2; FR-009)
  - a reservation with 2 active room lines of different types and 2 party guests produces **one** row whose Room Types / Rooms / Party Guests cells are comma-joined; one with no room assigned leaves Rooms empty (SC-007)
  - zero matches → header only, 200
  - `Content-Disposition` filename matches `/^reservations-[a-z0-9-]+-\d{8}-\d{4}\.csv$/`
  - default format with no `format` param is CSV
  - unknown `format`, unknown `filter[nope]`, unknown `sort` → 422 via the `{message, code, body}` envelope
  - a special request `=HYPERLINK("x")` comes out prefixed with `'` in CSV and as a string cell in XLSX (FR-011)
  - accented Latin (`José Müller`), Arabic (`أحمد`) and Cyrillic (`Иван`) guest names round-trip unchanged in CSV (bytes start with `\xEF\xBB\xBF`) and XLSX (SC-004)
  - a special request with a comma, double quote and newline stays one CSV field
- [X] T025 [US1] In the same file add the safeguard tests: `config(['exports.max_rows' => 5])` with 5 matching → 200; with 6 → 422 with `body.matching = 6`, `body.max_rows = 5`, no file, and an `event_logs` row `hotel.reservations_export_refused`; 10 successful exports then the 11th within the minute → 429 (the test cache store is `array`, so limiter hits reset with each test); a successful export writes exactly one `event_logs` row with `event_type = 'hotel.reservations_exported'`, `actor_id` = the user, `changes.format`, `changes.filter` and `changes.row_count` (FR-022, SC-006)
- [X] T026 [US1] In the same file add isolation tests: Hotel A admin exporting with no filter, and with `filter[guest_id]` = a Hotel B guest, never sees a Hotel B reservation number (SC-005); a super admin without `hotel_id` → 403; a super admin with Hotel B's `hotel_id` gets only Hotel B's rows; a Hotel A admin sending `hotel_id` = Hotel B still gets Hotel A's rows; soft-deleted reservations are absent (FR-004)

### Implementation for User Story 1

- [X] T027 [US1] Create `app/Support/Export/ReservationsExport.php` extending `ModuleExport`: `title()` `'Reservations'`; `relations()` `['guest', 'reservationGuests.guest', 'reservationRooms' => fn ($q) => $q->active(), 'reservationRooms.roomType', 'reservationRooms.room']`; `columns()` in exactly this order: `Reservation Number` (`reservation_id`), `Guest Name` (`fullName(guest)`), `Guest Email`, `Guest Phone` (`guest.phone_number`), `Arrival Date`, `Departure Date` (dates), `Nights` (`arrival_date->diffInDays(departure_date)` as int), `Adults`, `Children`, `Room Types` (`joined(reservationRooms->pluck('roomType.name'))`), `Rooms` (`joined(reservationRooms->pluck('room.room_number'))`), `Party Guests` (`joined(reservationGuests->map(fullName(guest)))`), `Status` (label), `Source` (label of `ReservationChannels` when the value is a case, else raw string), `Reservation Value` (float), `Currency`, `Special Requests`, `Created At` (date-time)
- [X] T028 [US1] Add `public function export(ReservationExportRequest $request)` to `app/Http/Controllers/ReservationController.php` using the `ExportsLists` trait: `$query = $this->listQuery($request)->setEagerLoads([]);` then `return $this->exportList('reservations', Reservation::class, $query, $request, fn (ExportFormat $f) => new ReservationsExport($query, $f));` (the export class decides its own eager loads)
- [X] T029 [US1] In `routes/api.php` add `Route::get('/reservation/export', [ReservationController::class, 'export'])->middleware('throttle:exports');` **above** the `/reservation/{reservation}/…` routes and `Route::resource('/reservation', …)` so `export` is not bound as a reservation id
- [X] T030 [US1] Run `php artisan test --parallel --filter=ExportControllerTest` until the US1 tests pass, then `--filter="CrossTenantRouteTest|EntryPointInventoryTest"`; if `EntryPointInventoryTest` names `GET api/reservation/export` as uncovered, add it to `tests/Inventory/entry_points.php` with `'covered_by'` = the exact `it(...)` description of the T026 Hotel A isolation test

**Checkpoint**: reservations export works end to end (MVP)

---

## Phase 4: User Story 4 — Control who may export through staff roles (P2)

**Goal**: export is granted only through `<module>.export`, never by `view` alone or by default.

**Independent Test**: employee with `reservations.view` only → 403; add `reservations.export` → 200; no-role employee → 403; `GET /api/permissions` lists the four cases.

- [X] T031 [US4] In `tests/Feature/PermissionAuthorizationTest.php` add the dataset row `'reservation export' => ['/api/reservation/export', Permission::RESERVATIONS_EXPORT]` to `'lets an employee read a resource only when their role grants it'` (the activity, booking and guest rows are added in T044, once their routes exist)
- [X] T032 [US4] In the same file add `it('does not let view alone export')`: an employee granted `RESERVATIONS_VIEW` gets 403 on `/api/reservation/export`, and a no-role employee (defaults only) gets 403 on it (FR-019, FR-021); also assert none of the four `*_EXPORT` cases is in `Permission::employeeDefaults()`. T044 extends both checks to the other three endpoints
- [X] T033 [US4] In the same file assert `GET /api/permissions` includes `reservations.export`, `activities.export`, `bookings.export`, `guests.export` (FR-020)
- [X] T034 [P] [US4] Update the permission reference in `docs/staff-roles-api-documentation.md`: add the four permissions with their endpoint (`GET /api/reservation/export`, `/api/activity/export`, `/api/booking/export`, `/api/guest/export`) and note they are not in the employee defaults

**Checkpoint**: export is role-gated and documented

---

## Phase 5: User Story 2 — Export guests, bookings and activities (P2)

**Goal**: the same export for the three other modules, with their data-model column sets.

**Independent Test**: for each module, seed records, export with and without a filter as CSV and XLSX; rows, order and columns match.

### Tests for User Story 2

- [X] T035 [US2] In `tests/Feature/ExportControllerTest.php` add guest tests: `filter[is_vip]=1` returns only VIP guests with the data-model Guests headers in order; `VIP` column reads `Yes`/`No`; Hotel B guests never appear; XLSX and CSV agree (Story 2 scenario 1)
- [X] T036 [US2] Add booking tests: `filter[status]=realised` rows show Reference, the guest's full name, Activity name and Reservation Number instead of ids; the booking-only `scheduled_from`/`scheduled_to` and `cancellation_requested` filters narrow the export exactly as `GET /api/booking`; default order equals the list's default (`scheduled_date`, `scheduled_time`, `scheduled_for`); a booking whose activity was soft-deleted/removed has an empty Activity cell and does not fail (Story 2 scenario 2; edge case); Hotel B bookings never appear
- [X] T037 [US2] Add activity tests: rows show Category name, Price (numeric in XLSX), Currency, Available From/Until (dates), Duration (Days), Daily Capacity; `Active` reads `Yes`/`No`; Hotel B activities never appear (Story 2 scenario 3)
- [X] T038 [US2] Add one audit assertion per module (`hotel.guests_exported`, `hotel.bookings_exported`, `hotel.activities_exported`) and one assertion that the shared `exports` limiter counts across modules (e.g. 4 reservation + 3 guest + 3 booking exports, then the activity export is 429)

### Implementation for User Story 2

- [X] T039 [P] [US2] Create `app/Support/Export/GuestsExport.php`: `title()` `'Guests'`; `relations()` `[]`; columns in order `First Name`, `Last Name`, `Email`, `Phone` (`phone_number`), `Preferred Language`, `Nationality`, `Loyalty Status`, `VIP` (`yesNo(is_vip)`), `Channel` (label), `External ID`, `Created At` (date-time)
- [X] T040 [P] [US2] Create `app/Support/Export/BookingsExport.php`: `title()` `'Bookings'`; `relations()` `['guest', 'activity', 'reservation', 'createdBy']` (a deleted activity resolves to `null`, so its cell is empty — spec edge case "Missing related record"); columns in order `Reference`, `Item Name`, `Activity` (`activity?->name`), `Guest Name`, `Reservation Number` (`reservation?->reservation_id`), `Status` (label), `Scheduled For` (date-time), `Guests (Pax)` (int), `Charge Model` (label), `Expected Value` (float or null), `Currency`, `Origin` (label), `Channel` (label), `Created By` (`createdBy?->name`), `Confirmed At`, `Realised At`, `Cancelled At` (date-times), `Cancellation Reason`, `Notes`
- [X] T041 [P] [US2] Create `app/Support/Export/ActivitiesExport.php`: `title()` `'Activities'`; `relations()` `['category']`; columns in order `Name`, `Category` (`category?->name`), `Description`, `Price` (float), `Currency`, `Active` (`yesNo(is_active)`), `Available From`, `Available Until` (dates), `Audience` (label), `Duration (Days)` (int|null), `Daily Capacity` (int|null), `Created At` (date-time)
- [X] T042 [US2] Add `export()` actions using `ExportsLists`: `GuestController::export(GenericExportRequest $request)` → module `'guests'`, `GuestsExport`; `ActivityController::export(GenericExportRequest $request)` → `'activities'`, `ActivitiesExport`; `BookingController::export(BookingExportRequest $request)` → `'bookings'`, `BookingsExport` — in `app/Http/Controllers/GuestController.php`, `app/Http/Controllers/ActivityController.php`, `app/Http/Controllers/BookingController.php`. For guests and activities, the export's `listQuery()` must not eager-load the list's heavy relations (`reservations`, `conversations`, `stays`) — `ModuleExport::relations()` decides what is loaded; call `->setEagerLoads([])` on the builder before handing it over
- [X] T043 [US2] In `routes/api.php` add, each with `->middleware('throttle:exports')`: `Route::get('/activity/export', [ActivityController::class, 'export'])` above `/activity/{activity}/availability` and `Route::resource('/activity', …)`; `Route::get('/booking/export', [BookingController::class, 'export'])` above `Route::get('/booking/{booking}', …)`; `Route::get('/guest/export', [GuestController::class, 'export'])` above the `/guest/{guest}/…` routes and `Route::resource('/guest', …)`
- [X] T044 [US2] In `tests/Feature/PermissionAuthorizationTest.php` add the dataset rows `'activity export' => ['/api/activity/export', Permission::ACTIVITIES_EXPORT]`, `'booking export' => ['/api/booking/export', Permission::BOOKINGS_EXPORT]`, `'guest export' => ['/api/guest/export', Permission::GUESTS_EXPORT]`, and extend T032's test so an employee with `ACTIVITIES_VIEW`, `BOOKINGS_VIEW`, `GUESTS_VIEW` and a no-role employee both get 403 on those three endpoints. Then run `php artisan test --parallel --filter="ExportControllerTest|PermissionAuthorizationTest|CrossTenantRouteTest|EntryPointInventoryTest"`; add any export route `EntryPointInventoryTest` names to `tests/Inventory/entry_points.php` with `'covered_by'` = the matching isolation test description

**Checkpoint**: all four modules export to Excel and CSV

---

## Phase 6: User Story 3 — Export records as SQL (P3)

**Goal**: `format=sql` returns one PDO-quoted `INSERT` per record, ids kept, loadable into an empty copy of the table.

**Independent Test**: export a filtered set as SQL, load it into an empty table copy, compare values.

### Tests for User Story 3

- [X] T045 [P] [US3] Create `tests/Feature/SqlDumpTest.php`: for a guest named `O'Brien` with `preferences` JSON, `is_vip = true`, `nationality = null`, the dump contains `INSERT INTO "guests" (...)` with `'O''Brien'`, `TRUE`, `NULL` and the JSON as a quoted literal; numbers (`reservation_value`, `pax`) are unquoted; output is wrapped in `BEGIN;` … `COMMIT;` with a leading `--` comment header naming module, hotel, time, row count; `bookings` dump omits the `context` column but includes `id`, `hotel_id`, `guest_id`, `activity_id`, `reservation_id` (FR-012, FR-013, clarification Q4)
- [X] T046 [US3] In `tests/Feature/SqlDumpTest.php` add a round-trip test (SC-003, SC-008): create guests and bookings, export both via `GET /api/guest/export?format=sql` and `GET /api/booking/export?format=sql`, then inside the test DB create temporary tables `CREATE TEMP TABLE guests_rt (LIKE guests INCLUDING DEFAULTS)` / `bookings_rt (LIKE bookings INCLUDING DEFAULTS)`, rewrite the table names in the dumps, execute them with `DB::unprepared()`, and assert counts, every column value equals the source row (except excluded columns), and every `bookings_rt.guest_id` exists in `guests_rt.id`
- [X] T047 [US3] In `tests/Feature/ExportControllerTest.php` add: `format=sql` on reservations returns `Content-Type: application/sql; charset=UTF-8`, filename ending `.sql`, exactly N `INSERT` lines for N filtered reservations in list order; zero matches → no `INSERT`; the row cap and audit (`changes.format = 'sql'`) apply to SQL too; Hotel B rows never appear

### Implementation for User Story 3

- [X] T048 [US3] Create `app/Support/Export/SqlDump.php`: `public function __construct(private Builder $query, private string $module, private Hotel $hotel, private int $rowCount, private array $filters, private array $excluded)`; `public function stream(string $filename): StreamedResponse` → `response()->streamDownload(fn () => $this->write(), $filename, ['Content-Type' => ExportFormat::SQL->contentType()])`. `write()`: echo a `--` header (module, hotel name and id, `now()->toIso8601String()`, row count, JSON of filters), then `BEGIN;`, then for each model from `$this->query->setEagerLoads([])->orderBy(qualified id)->cursor()` echo `INSERT INTO "<table>" ("col", ...) VALUES (...);`, then `COMMIT;`. Columns = `Schema::getColumnListing($table)` minus `$excluded`, in table order. Values from `$model->getRawOriginal($col)`: `null` → `NULL`; PHP `bool` → `TRUE`/`FALSE`; `int`/`float` → as is; Postgres boolean strings read raw (`true`/`false`/`t`/`f`) on boolean columns → `TRUE`/`FALSE` (look up column types once via `Schema::getColumns($table)`); numeric/decimal columns → unquoted; everything else → `DB::getPdo()->quote((string) $value)`
- [X] T049 [US3] In `app/Services/Exports/DataExporter.php` replace the temporary SQL 422 with `(new SqlDump($query, $module, $hotel, $matching, $this->filters($request), self::SQL_EXCLUDED[$module] ?? []))->stream($name)`, where `private const SQL_EXCLUDED = ['bookings' => ['context']];` with a comment: internal AI/recommendation processing context, meaningless outside the system (FR-013)
- [X] T050 [US3] Run `php artisan test --parallel --filter="SqlDumpTest|ExportControllerTest"` until green

**Checkpoint**: all user stories complete

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T051 [P] Document the export in `docs/reservations-api-documentation.md`: endpoint, parameters (`format` default `csv`, `hotel_id` for super admin, same filters as the list incl. `room_type_id`/`room_id`), the column list from data-model.md, SQL behaviour, errors (403, 422 validation, 422 cap with `matching`/`max_rows`, 429), audit event (written when the download starts, so it records an export that was started), permission `reservations.export`
- [X] T052 [P] Same documentation for activities in `docs/activity-api-documentation.md` (`activities.export`)
- [X] T053 [P] Same documentation for bookings in `docs/booking-entity-documentation.md` (`bookings.export`, booking-only filters, `context` excluded from SQL)
- [X] T054 [P] Same documentation for guests in `docs/guest-api-documentation.md` (`guests.export`; note contact data is exported in full to holders of the permission)
- [X] T055 [P] Write `docs/latest-changes-2026-11-03.md` (or append if it exists) summarising the four new endpoints and permissions; note no breaking change to the list endpoints
- [X] T056 Performance check (SC-001): seed 10,000 reservations for one hotel in a throwaway script or tinker session against the dev DB, time `format=xlsx|csv|sql`; each must finish under 30 s. If XLSX does not, enable cell caching in `config/excel.php` (`cache.driver` → `illuminate` or `batch`) and re-measure; record the timings in `specs/021-module-data-export/quickstart.md` step 6
- [X] T057 Run `./vendor/bin/pint` and the full suite `php artisan test --parallel`; fix any failure
- [ ] T058 Walk through `specs/021-module-data-export/quickstart.md` steps 2–5 manually (CSV in Excel with Arabic names, XLSX types, SQL load into a scratch DB, refusals) and tick them off

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (T001–T002)**: none
- **Foundational (T003–T023)**: after Setup; blocks every story
- **US1 (T024–T030)**: after Foundational — MVP
- **US4 (T031–T034)**: after US1 (covers the reservation export route; T044 in US2 adds the other three endpoints to the permission tests, so the suite stays green between phases)
- **US2 (T035–T044)**: after Foundational; independent of US1 except sharing `ExportControllerTest.php` (sequence the edits to that file)
- **US3 (T045–T050)**: after Foundational; its end-to-end tests use the guest and booking routes from US2
- **Polish (T051–T058)**: after the stories it documents

### Within phases

- T003 before T004–T006; T004–T006 before T007
- T008 before T009–T012
- T013 before T014–T016
- T019 before T020 → T021; T022 needs T020; T023 needs T022
- Tests in each story are written before the story's implementation and should fail first

### Parallel opportunities

- T001 ∥ T002
- T005 ∥ T006 (after T003; T004 touches a different file and can run alongside too)
- T009 ∥ T010 ∥ T011 ∥ T012
- T014 ∥ T015 ∥ T016
- T018 ∥ T019 (different files)
- US2: T039 ∥ T040 ∥ T041
- US3: T045 can be written while T048 is in progress
- Polish: T051 ∥ T052 ∥ T053 ∥ T054 ∥ T055

## Parallel Example: User Story 2

```text
Task: "Create app/Support/Export/GuestsExport.php"      (T039)
Task: "Create app/Support/Export/BookingsExport.php"    (T040)
Task: "Create app/Support/Export/ActivitiesExport.php"  (T041)
# then, sequentially: T042 controllers → T043 routes → T044 test run
```

## Implementation Strategy

### MVP first

1. Phase 1 + Phase 2 (list refactor proven unchanged by T007).
2. Phase 3 (US1): reservations to CSV/XLSX with cap, throttle, audit, isolation.
3. **Stop and validate** with quickstart step 2; demo.

### Incremental delivery

1. + US4 → export is role-gated and documented in the permission reference.
2. + US2 → the other three modules.
3. + US3 → SQL for integrations/migrations.
4. Polish → docs, performance check, full suite, Pint.
