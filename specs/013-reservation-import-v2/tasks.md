# Tasks: Reservation Import v2 and Reconciliation

**Input**: Design documents from `specs/013-reservation-import-v2/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/reservation-import-api.md](contracts/reservation-import-api.md), [quickstart.md](quickstart.md)

**Tests**: Required by the constitution (Principle VIII) and CLAUDE.md (permission and
tenant-isolation tests for every tenant-scoped surface). Write each story's tests first and
see them fail before implementing.

**Before starting**: `015-reservation-lifecycle` must be merged into `main`, and this
branch rebased onto it (`ReservationStatus::NO_SHOW`, `ReservationLifecycle`,
`syncStays()` no-show arm).

**Organization**: a shared foundation (data, normalizers, mapper, writer), then one phase
per user story, so each story can be implemented and tested on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: the user story the task belongs to (US1–US5)

---

## Phase 1: Setup

**Purpose**: The dependency, the mapping config and the shared test fixtures. No behaviour
change.

- [X] T001 Run `composer require giggsey/libphonenumber-for-php-lite` and commit the
  `composer.json` / `composer.lock` changes (research R5). Do not add the full
  `libphonenumber-for-php` package.
- [X] T002 [P] Create `config/reservation_import.php` (research R4, R5):
  - `header_synonyms`: normalized header → canonical column. Include at least:
    - `reservation_id`, `res_no`, `booking_ref`, `confirmation` → `reservation_reference`;
    - `mobile`, `phone` → `guest_phone`;
    - `email` → `guest_email`;
    - `check_in` / `arrival` → `arrival_date`;
    - `check_out` / `departure` → `departure_date`;
    - `room`, `room_no` → `room_number`;
    - `primary` → `is_primary`.
  - `canonical_columns`: every column in the contract's "Supported file columns" table.
  - `required_columns`: `arrival_date`, `departure_date`; plus
    `guest_identifier_columns`: `guest_phone`, `guest_email`, `guest_external_id` (at least
    one must exist).
  - `status_labels`: the R5 table (confirmed / pending / cancelled / checked_in /
    checked_out / no_show labels).
  - `write_chunk_size` = 100; `max_reservations_per_file` is not needed.
- [X] T003 [P] Add shared import fixtures to `tests/Pest.php`:
  - `imCsv(array $header, array $rows): UploadedFile`: writes a temp CSV with any header
    row (generalizes `importCsv()` in `tests/Feature/ReservationImportTest.php`);
  - `imHotel(string $timezone = 'UTC', ?string $countryCode = 'EG'): array` returning
    `[$admin, $hotel]`, built like `adminWithHotel()` with `country_code` set;
  - `imUpload($test, User $user, UploadedFile $file, array $fields = []): TestResponse`:
    multipart POST to `/api/reservation/import` with `apiHeaders()` + `actingAs`;
  - `imRun(ReservationImport $import): ReservationImport`: runs
    `PrepareReservationImportJob` and every chained `WriteReservationImportJob`
    synchronously, then returns `$import->fresh()`.

---

## Phase 2: Foundational

**Purpose**: Tables, enums, models, normalizers, the mapper and the writer, which every
story builds on.

**⚠️ CRITICAL**: Finish this phase before any user story.

### Enums and schema

- [X] T004 [P] Create `app/Enums/ImportStatus.php` (`queued`, `running`, `completed`,
  `failed`), `app/Enums/ImportStage.php` (`parse`, `normalize`, `validate`, `map`,
  `import`, `report`), `app/Enums/ImportRowOutcome.php` (`imported`, `skipped`,
  `duplicate`, `invalid`, `unresolved`) and `app/Enums/ImportDateOrder.php`
  (`day_first`, `month_first`), as string-backed enums like `app/Enums/StayStatus.php`.
- [X] T005 [P] Create `database/migrations/2026_10_23_000001_create_reservation_imports_table.php`
  with the [data-model](data-model.md) columns:
  - `uploaded_by` "uuid FK → users, null on delete";
  - `file_name` "string(255)", `file_path` "string, nullable";
  - `dry_run` boolean;
  - `date_order` "string(16)", `status` "string(16)", `stage` "string(16), nullable";
  - `total_rows`, `processed_rows` and the five totals: "unsigned int, default 0";
  - `created_rooms`, `created_room_types`, `unrecognized_columns`: "jsonb, default `[]`";
  - `failure_reason` "text, nullable"; `started_at` / `finished_at` "timestampTz,
    nullable".

  Add `CHECK` constraints on the `status`, `stage` and `date_order` values and
  `CHECK (imported + skipped + duplicate + invalid + unresolved <= total_rows)`, plus an
  index on `(hotel_id, status, created_at)`. Follow
  `2026_..._create_knowledge_index_rebuilds_table.php` for the `DB::statement` style.
- [X] T006 [P] Create `database/migrations/2026_10_23_000002_create_reservation_import_rows_table.php`:
  - `reservation_import_id` "uuid FK → reservation_imports, cascade";
  - `row_number` unsigned int; `external_reference` "string, nullable";
  - `outcome` "string(16), nullable"; `stage` "string(16)";
  - `fields` / `warnings` "jsonb, default `[]`"; `reason` "text, nullable";
  - `reservation_id` "uuid FK → reservations, null on delete, nullable";
  - `draft_id` "uuid, nullable".

  Unique `(reservation_import_id, row_number)`; index `(reservation_import_id, outcome)`;
  `CHECK` on the `outcome` values.
- [X] T007 [P] Create `database/migrations/2026_10_23_000003_create_reservation_import_drafts_table.php`
  with `reservation_import_id` (cascade), `position` unsigned int, `payload` jsonb, and
  unique `(reservation_import_id, position)`.
- [X] T008 [P] Create `database/migrations/2026_10_23_000004_add_external_source_to_guests_table.php`:
  - add `external_source` "string(16), nullable";
  - create the partial unique index
    `CREATE UNIQUE INDEX guests_import_external_id_unique ON guests (hotel_id, external_id) WHERE external_source = 'import' AND deleted_at IS NULL`;
  - `down()` drops both.
- [X] T009 Create `app/Models/ReservationImport.php`:
  - `HasUuids`, `BelongsToHotel`, `Filterable`, no `SoftDeletes`;
  - `$fillable` for every writable column;
  - casts: the enums, `dry_run` boolean, the jsonb lists as `array`, timestamps as
    `datetime`;
  - relations `uploader()` (`belongsTo(User::class, 'uploaded_by')`), `rows()`,
    `drafts()`;
  - `isFinished(): bool` (completed or failed).
- [X] T010 [P] Create `app/Models/ReservationImportRow.php` (`HasUuids`, `BelongsToHotel`;
  casts `outcome` → `ImportRowOutcome`, `stage` → `ImportStage`, `fields` / `warnings`
  → array) and `app/Models/ReservationImportDraft.php` (`HasUuids`, `BelongsToHotel`;
  `payload` → array).
- [X] T011 [P] Add `external_source` to `$fillable` in `app/Models/Guest.php`.
- [X] T012 [P] Add `EventLogger::actingAs(User $user, callable $callback): mixed` to
  `app/Support/Audit/EventLogger.php`. It sets the existing `$onBehalfOf` slot for the
  callback with actor kind `user` (not `ai_agent`), restores the previous value in a
  `finally`, and has a docblock naming the queued import as its caller (research R9).

### Normalizers (unit-level, shared)

- [X] T013 [P] Create `app/Support/Import/DateParser.php` with
  `parse(mixed $raw, ImportDateOrder $order): ?CarbonImmutable` (null = unparseable), per
  research R5:
  - numeric Excel serials via `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject`;
  - `Y-m-d`;
  - numeric `d/m/Y` or `m/d/Y` by `$order`, with separators `/ - .` and 2-digit years as
    20yy;
  - `d M Y`, `d-M-Y`, `M d, Y` with English month names or abbreviations;
  - a time part is ignored.

  Reject impossible dates (e.g. 31/02/2025) with no rollover: use `createFromFormat` with
  `!` and compare the formatted round-trip.
- [X] T014 [P] Create `app/Support/Import/PhoneNormalizer.php` with
  `normalize(?string $raw, ?string $countryCode): array{digits: ?string, warning: ?string}`:
  - parse with `libphonenumber\PhoneNumberUtil` (lite), using `$countryCode` as the default
    region;
  - a valid number becomes E.164 without the `+`;
  - otherwise fall back to `App\Support\PhoneNumber::digits()`, with warning
    `phone_country_unknown` when the input was national and could not be resolved;
  - a leading `00` is treated as an international prefix.

  `App\Support\PhoneNumber` stays unchanged.
- [X] T015 Create `app/Support/Import/Reservations/ImportFileReader.php`:
  - `read(string $path, string $extension): array{headers: list<string>, rows: list<array{row_number:int, values: array<string,mixed>}>, unrecognized: list<string>}`;
  - read the first sheet with `Excel::toArray()` and no heading formatting;
  - normalize headers (trim, lower-case, spaces/dashes/dots → `_`, collapse repeats), then
    map through `config('reservation_import.header_synonyms')`; anything not in
    `canonical_columns` goes into `unrecognized`;
  - row numbers count the header as row 1;
  - drop blank rows after the last row with data before counting (FR-025); a blank row
    between data rows stays and becomes `skipped`;
  - throw `App\Support\Import\Reservations\ImportFailed` (a new small exception with a
    staff-readable message) when the required columns, or every guest identifier column,
    are missing, naming them.
- [X] T016 Create `app/Support/Import/Reservations/RowNormalizer.php` with
  `normalize(array $values, ImportDateOrder $order, Hotel $hotel): NormalizedRow` (a
  readonly value object in the same folder). It covers:
  - trimmed strings;
  - dates through `DateParser`;
  - phones through `PhoneNormalizer` with `$hotel->country_code`;
  - emails trimmed and lower-cased;
  - the status through `config('reservation_import.status_labels')`, matched case- and
    space-insensitively; empty means `pending`;
  - `adults`, `children`, `room_count`, `activity_pax` as whole numbers;
  - `children_ages` from a comma list;
  - `is_primary` truthy values `1/true/yes/y`;
  - Arabic-Indic (`٠–٩`) and Eastern Arabic-Indic (`۰–۹`) digits converted to ASCII in
    every field before parsing (dates, phones, numbers), because legacy exports from
    Arabic-locale systems use them (constitution: English and Arabic);
  - the `guests` JSON column parsed into guest arrays.

  It collects `errors` (field → message) instead of throwing, and `warnings` (code →
  message).
- [X] T017 Create `app/Support/Import/Reservations/RowValidator.php` with
  `validate(NormalizedRow $row, CarbonImmutable $hotelToday): NormalizedRow` (adds errors).
  Rules, each a readable message naming the field (data-model "Validation rules"):
  - an unparseable date, unknown status or non-numeric party (FR-008);
  - departure not after arrival; an age outside 0–17 (FR-009);
  - an invalid email;
  - a guest with no phone, email or external id (FR-019a), and warning `no_phone` for a
    guest with no phone.

  Status-vs-date rules are applied per reservation in the mapper (T018).
- [X] T018 Create `app/Support/Import/Reservations/ReservationMapper.php` with
  `map(list<NormalizedRow> $rows, Hotel $hotel, CarbonImmutable $hotelToday): MappedFile`
  (value object: `drafts` in file order, plus per-row outcomes and warnings). Implement:
  - grouping by case-insensitive reference; reference-less rows are groups of one;
  - reservation-level field agreement, where an empty cell isn't a conflict (FR-011);
  - units per FR-012 as clarified 2026-10-23:
    - each distinct number is one unit;
    - the first unnumbered row of a type adds one unit, and later ones add guests only;
    - `room_count` overrides that, and disagreement on it is invalid;
    - rows with no room columns add guests only;
    - no units at all means one unit of the default type;
    - more than `ReservationRoomSync::MAX_UNITS` units is invalid;
  - guests: one per row plus the `guests` JSON, de-duplicated by identity within the
    group; the primary is the flagged one, otherwise the first; two flagged is invalid
    (FR-013);
  - party: a missing party defaults adults to the named-guest count (min 1) and children
    to 0; guests > adults + children is invalid (FR-013a); ages vs children (FR-009);
  - statuses vs dates (FR-017b):
    - `checked_out` needs departure ≤ today, `no_show` needs arrival ≤ today, and
      `checked_in` needs arrival ≤ today, otherwise invalid;
    - `checked_in` with departure < today becomes `checked_out` with warning
      `status_corrected`;
  - bookings: `activity_name` / `activity_date` / `activity_time` / `activity_pax` kept
    on the draft for the resolver.

  A group with any row error makes all its rows `invalid`, at stage `validate`, with the
  first error's reason and all error fields.
- [X] T019 Create `app/Support/Import/Reservations/ReservationImportResolver.php` (read-only)
  with `resolve(MappedFile $file, Hotel $hotel): ResolvedFile`. All queries filter on
  `hotel_id` explicitly. In this phase, implement:
  - room-type and room lookup, collecting `created_rooms` / `created_room_types` names
    (FR-016);
  - activity matching by trimmed, case-insensitive name among the hotel's activities,
    including inactive ones (FR-015); none or more than one match records warning
    `booking_unresolved`;
  - booking status from the reservation (FR-015, clarified): `cancelled` for a cancelled
    or no-show reservation; `realised` when the activity date < hotel today and the
    reservation is `checked_in` / `checked_out`; else `confirmed`;
  - guests through the existing `GuestIdentityService::findForReservation()`; US4
    replaces this. Catch its `ValidationException` (phone and email point at different
    guests, or the email matches several) and mark that group `unresolved` with the
    message as reason, so one conflict never fails the whole import.

  Leave clear extension points (private methods) for duplicates (US3) and guest conflicts
  (US4).
- [X] T020 Create `app/Support/Import/Reservations/ReservationImportWriter.php` with
  `write(ReservationImportDraft $draft, Hotel $hotel): Reservation`, in one
  `DB::transaction` (FR-024, research R8):
  1. Create missing room types and rooms (`RoomType::resolveFor()`; room lookup as in the
     old `App\Imports\ReservationsImport::writeRow()`).
  2. Create or reuse guests: fill only empty fields, never overwrite (FR-020); set
     `external_id` and `external_source = 'import'` when given.
  3. Call `AvailabilityService::lockTypes()` for the draft's types, then for each assigned
     line run `RoomAssignmentRules::reason()` against a transient `ReservationRoom`. On a
     clash, unassign the line and add warning `room_clash`.
  4. Call `ReservationCreator::create($attributes, $rooms, fromImport: true)`, passing
     `guests` as `[{guest_id, is_primary}]`. A draft without a reference gets a generated
     `reservation_id` of `'RES-'.strtoupper(Str::random(8))`, as the old import did
     (FR-022). Retry once if `ReservationCreator::isReservationIdInUse()` says it's taken.
  5. Create bookings through `BookingService::create()` with `origin = import`,
     `fromImport: true`, and the resolved status.

  Return the reservation. Warnings from step 3 are returned through a small result object.
- [X] T021 Add a `fromImport` option to `BookingService::create()` in
  `app/Services/BookingService.php`: `$data['from_import'] = true` (unset before fill).
  When set:
  - skip `requireDate()`, `availability()->lock()/assertBookable()` and
    `repeatedAiBooking()`;
  - skip `creditRecommendation()` and `meter()`;
  - set `status` and, for `realised`, `realised_at` (the scheduled time) directly.

  Add a docblock line saying why: legacy data is recorded as it is and isn't demand.
- [X] T022 Create `app/Support/Import/Reservations/ImportOutcomeRecorder.php`:
  - `recordRows(ReservationImport, list<RowResult>)` bulk-inserts
    `reservation_import_rows` in chunks of 500;
  - `markGroup(ReservationImport, string $draftId, ImportRowOutcome, ?string $reservationId, array $warnings = [], ?string $reason = null)`
    updates a draft's rows;
  - `refreshTotals(ReservationImport)` recounts outcomes with one grouped query and
    updates the five totals and `processed_rows`.
- [X] T023 Write normalizer unit datasets in `tests/Feature/ReservationImportNormalizationTest.php`
  (`uses(RefreshDatabase::class)` only where a hotel is needed):
  - **DateParser**: "12/09/2025" is 12 Sep under `day_first` and 9 Dec under
    `month_first`; "2025-09-12"; "12-Sep-2025"; "Sep 12, 2025"; Excel serial `45912`;
    "31/02/2025" and "next week" give null.
  - **PhoneNormalizer** with `EG`: "+20 100 123 4567", "0020 100 123 4567" and
    "01001234567" all give `201001234567`. With `IT`: "06 1234 5678" keeps its trunk 0
    (`390612345678`). With a null region and "01001234567": digits kept, warning
    `phone_country_unknown`.
  - **ImportFileReader**: "Guest Phone", "Res No." and "Check-In" headers map to
    canonical names; "Fax" is reported as unrecognized; a file without `departure_date`
    throws `ImportFailed` naming it.
  - **Arabic-Indic digits**: "١٢/٠٩/٢٠٢٥" parses like "12/09/2025", and "٠١٠٠١٢٣٤٥٦٧"
    normalizes like "01001234567".
  - **Status labels**: "Checked Out", "CO", "No Show", "In House", "cxl" map correctly;
    "Lost" is invalid.

**Checkpoint**: the foundation maps a file into drafts and writes one draft through the
domain write path. The normalizer tests pass.

---

## Phase 3: User Story 1 - Upload a legacy export and get a reconciliation report (Priority: P1) 🎯 MVP

**Goal**: The upload returns an import id at once. The import runs in the background,
reports progress, and ends with totals and a row-by-row report.

**Independent Test**: Upload a file mixing good rows, missing-data rows and bad dates. It
returns `202` + `queued`, moves to `completed`, and its totals equal the data rows, with a
reason for each non-imported row.

### Tests for User Story 1

- [X] T024 [P] [US1] Rewrite `tests/Feature/ReservationImportTest.php` for the queued
  contract (keep its auth tests; drop the `ReservationsImport` import and `importCsv()` in
  favour of `imCsv()`):
  - `202` with `status: queued` and the Import resource shape, with `Queue::fake()`
    asserting `PrepareReservationImportJob` was pushed;
  - after `imRun()`, `completed` with totals summing to `total_rows`, and each
    non-imported row has `row_number`, `external_reference`, `fields` and a `reason`
    that contains no PHP class name (SC-004);
  - a file missing `arrival_date` gives `failed` with `failure_reason` naming it and no
    reservation created;
  - an empty or header-only file gives `completed` with 0 rows;
  - a blank data row gives `skipped`;
  - the history list is newest first with file name, uploader, times, status and totals;
  - `GET /rows?outcome=invalid` filters;
  - the report download is `.xlsx` with the non-imported rows and warnings; `409` while
    running;
  - a super admin gets `422` without `hotel_id` and `202` with it;
  - `422` for `date_order=sideways`; `422` on `file` for a file over 5 MB;
  - a CSV ending in 50 blank rows: they aren't counted in `total_rows`;
  - the uploader is deleted between upload and `imRun()`: the import completes,
    `uploaded_by` is null in the resource, and the audit event has no actor;
  - progress (`processed_rows`) increases between write chunks: set
    `reservation_import.write_chunk_size` to 2 in the test;
  - one `reservations_imported` event with the uploader as actor and the totals; no
    per-reservation create events (FR-032).
- [X] T025 [P] [US1] Add the five import endpoints to the dataset in
  `tests/Feature/PermissionAuthorizationTest.php`: an employee with
  `reservations.import` is allowed, and gets `403` without it. Add the upload, history,
  show, rows and report endpoints.
- [X] T026 [P] [US1] Add import cases to `tests/Feature/TenantIsolationTest.php`:
  - another hotel's admin gets `404` on show, rows and report;
  - the history list never includes another hotel's imports;
  - the jobs run with `TenantContext` set to the import's hotel only.

### Implementation for User Story 1

- [X] T027 [US1] Create `app/Jobs/PrepareReservationImportJob.php` (research R3):
  - `ShouldQueue`, `tries = 0`, `maxExceptions = 3`, `timeout = 300`,
    `failOnTimeout = true`;
  - middleware `WithoutOverlapping('reservation-import:'.$hotelId)->releaseAfter(15)->expireAfter(400)`;
  - `handle()`:
    - release(15) if an older import of the hotel is `queued` or `running`;
    - set `running`, `started_at` and the stage as each stage starts;
    - inside `TenantContext::runForHotel()`, run reader → normalizer → validator →
      mapper → resolver;
    - record rows (`ImportOutcomeRecorder::recordRows`) and save drafts;
    - delete the uploaded file and null `file_path`;
    - then dispatch `WriteReservationImportJob`, or complete when there are no drafts;
  - `ImportFailed` marks the import `failed` with its message.
- [X] T028 [US1] Create `app/Jobs/WriteReservationImportJob.php`:
  - same queue settings and middleware as T027;
  - run everything inside `TenantContext::runForHotel($import->hotel_id)` (constitution:
    jobs establish tenant context explicitly); `failed()` uses it too when it touches
    tenant rows;
  - take the next `config('reservation_import.write_chunk_size')` drafts by `position`;
  - for each draft, call `ReservationImportWriter::write()` inside
    `EventLogger::withoutRecording()`, then mark its rows `imported` with the
    reservation id and warnings, and delete the draft;
  - a `Throwable` from one draft marks its rows `invalid` at stage `import`, with
    "Could not be saved: " plus a readable summary (log the exception with the import and
    row ids), and continues;
  - `refreshTotals()` after the chunk;
  - dispatch itself again while drafts remain, else call
    `ReservationImportCompleter::complete()` (T029).
- [X] T029 [US1] Create `app/Support/Import/Reservations/ReservationImportCompleter.php`:
  - `complete(ReservationImport)` sets stage `report`, recounts totals, sets `completed`
    and `finished_at`, deletes leftover drafts, and inside
    `EventLogger::actingAs($uploader)` records (when `uploaded_by` is null because the
    user was deleted, it records without an actor instead of failing) `reservations_imported` on the import with
    `{file_name, dry_run, totals}`;
  - `fail(ReservationImport, string $reason)` sets `failed` with the reason and progress,
    and deletes the drafts and file;
  - both jobs' `failed()` call `fail()` with "The import stopped unexpectedly at
    {stage}; re-upload the file to finish it.", logging the raw exception.
- [X] T030 [US1] Update `app/Http/Requests/ImportReservationsRequest.php`:
  - add `date_order` `['nullable', Rule::enum(ImportDateOrder::class)]`,
    `dry_run` `['nullable', 'boolean']`, and `hotel_id` `['nullable', 'uuid']`;
  - keep the `file` rule unchanged.
- [X] T031 [US1] Rewrite `ReservationController::import()` in
  `app/Http/Controllers/ReservationController.php`:
  - authorize `import`, then `resolveHotel($request->user(), $request->input('hotel_id'))`,
    returning `422` on `hotel_id` for a super admin without a valid hotel;
  - store the file on the local private disk at
    `reservation-imports/{hotel_id}/{uuid}.{ext}`;
  - create a `ReservationImport` (queued, `uploaded_by`, `file_name`, `date_order`
    default `day_first`, `dry_run`);
  - dispatch `PrepareReservationImportJob` after commit;
  - return `apiResponse('Reservation import queued.', 202, new ReservationImportResource($import))`.
- [X] T032 [P] [US1] Create `app/Http/Resources/ReservationImportResource.php` (the shape
  in the contract's "Import resource": `progress`, `totals`, `uploaded_by {id, name}` or
  null, `warnings_count` = rows with non-empty warnings) and
  `app/Http/Resources/ReservationImportRowResource.php`.
- [X] T033 [P] [US1] Create `app/Policies/ReservationImportPolicy.php` using
  `ChecksPermissions`: `viewAny(User)` and `view(User, ReservationImport)`, both
  `$this->allows($user, Permission::RESERVATIONS_IMPORT, ...)`. Register it if policies
  are not auto-discovered (check `AppServiceProvider` / `AuthServiceProvider`).
- [X] T034 [P] [US1] Create `app/Http/Requests/ReservationImportRowsRequest.php`:
  `outcome` `Rule::enum(ImportRowOutcome::class)`, `has_warnings` boolean, and
  `per_page`, `page` as in `Generic/GenericIndexRequest`.
- [X] T035 [P] [US1] Create `app/Support/Import/Reservations/ImportReportExport.php`
  (`FromQuery`, `WithHeadings`, `WithMapping`) with columns `row_number`,
  `external_reference`, `outcome`, `fields`, `reason`, `warning`: one line per
  non-imported row, and one per warning.
- [X] T036 [US1] Create `app/Http/Controllers/ReservationImportController.php`:
  - `index` (`GenericIndexRequest` + `GenericQuery::apply()`, default `-created_at`);
  - `show`;
  - `rows` (ordered by `row_number`, filtered, paginated);
  - `report`: `409` `apiResponse` while unfinished, otherwise
    `Excel::download(new ImportReportExport($import), "import-{$import->id}-report.xlsx")`.

  Each action authorizes through the policy.
- [X] T037 [US1] Register the routes in `routes/api.php` next to
  `Route::post('/reservation/import', ...)`:
  - `GET /reservation/imports`;
  - `GET /reservation/imports/{reservationImport}`;
  - `GET /reservation/imports/{reservationImport}/rows`;
  - `GET /reservation/imports/{reservationImport}/report`.

  Declare them before any `/reservation/{reservation}` wildcard so `imports` isn't bound
  as a reservation id.
- [X] T038 [US1] Delete `app/Imports/ReservationsImport.php` and remove its imports from
  `app/Http/Controllers/ReservationController.php`. Grep for any remaining reference.

**Checkpoint**: an upload runs end to end in the background with a full reconciliation
report. US1 tests pass.

---

## Phase 4: User Story 2 - Legacy rows are normalized and mapped to the new reservation model (Priority: P1)

**Goal**: Multi-row reservations become one reservation with a line per room unit, all
guests (one primary), party and ages. Statuses respect the lifecycle, rooms never clash,
and bookings are attached.

**Independent Test**: One reference over 3 rows (two rooms of one type, one of another,
two named guests, mixed date and phone formats) gives one reservation with 3 lines, 2
guests with the right primary, and normalized values.

### Tests for User Story 2

- [X] T039 [P] [US2] Create `tests/Feature/ReservationImportMappingTest.php` covering US2
  scenarios 1–10 and the matching edge cases:
  - 3 rows → 1 reservation, 3 lines, rooms 214/215 assigned;
  - two guests with the flagged one primary; with no flag, the first is primary; two
    flags is invalid;
  - rows disagreeing on arrival → the whole reservation invalid, naming `arrival_date`;
  - unnumbered rows: 3 "Deluxe" rows with different guests → 1 line, 3 guests; with
    `room_count` 2 → 2 lines; conflicting `room_count` → invalid;
  - a room or type missing at the hotel is created and listed in `created_rooms` /
    `created_room_types`;
  - a reservation over its room type's capacity, and one on nights already sold out, are
    both imported (FR-010);
  - party: 1 adult + 3 guests → invalid; no party columns + 3 guests → 3 adults;
    2 children with ages "5" → invalid; 2 children with no ages → imported with `[]`;
  - statuses (with `Carbon::setTestNow` and a non-UTC hotel timezone):
    - "No Show" with a past arrival → `no_show`, stays `no_show`, no room held, no task;
    - "No Show" with a future arrival → invalid;
    - "Checked Out" with a future departure → invalid;
    - "In House" with a departure last week → `checked_out` with warning
      `status_corrected`, no room occupied;
    - "In House" departing tomorrow → checked-in and room `occupied`;
    - "In House" without a room number → line unassigned, warning `no_room_assigned`;
  - two reservations in the file on room 301, overlapping nights: the second is imported
    unassigned with a `room_clash` warning naming the first. The same holds against an
    existing reservation at the hotel. Afterwards no room is on two live lines on any
    night (SC-008);
  - bookings: a known activity name → booking with `origin = import` and the clarified
    status (realised for a past date on a checked-out reservation, confirmed for a future
    one, cancelled for a cancelled reservation); an unknown name → reservation imported,
    warning `booking_unresolved`;
  - the import writes no `transactions`, no `tasks`, no notifications and no memory
    extraction jobs (`Queue::fake()` for `ExtractGuestMemoriesJob`, `Notification::fake()`).

### Implementation for User Story 2

- [X] T040 [US2] Implement in-file conflict handling in
  `app/Support/Import/Reservations/ReservationMapper.php` (research R6):
  - an in-file room clash: in file order, keep a map `room_number → [(arrival,
    departure, reference)]` for drafts whose status holds inventory and isn't
    `checked_out` / `no_show`; an overlapping later line becomes unassigned with warning
    `room_clash` naming the earlier reference;
  - an in-house line without a room → warning `no_room_assigned`.
- [X] T041 [US2] Implement existing-data room clashes in
  `app/Support/Import/Reservations/ReservationImportResolver.php`:
  - one query over live, inventory-holding `reservation_rooms` joined to `reservations`,
    with `room_id` in the file's rooms and dates overlapping the file's range, excluding
    lines whose stay is `no_show` / `departed` (the `RoomAssignmentRules::reason()`
    predicate);
  - a clashing draft line becomes unassigned, with warning `room_clash` naming the
    existing reservation code.
- [X] T042 [US2] Verify, and fix if needed, that `ReservationCreator::create(...,
  fromImport: true)` with `checked_out` / `no_show` creates no cleaning task, doesn't
  dirty rooms and queues no memory extraction (research R8). Any fix goes in
  `app/Support/Reservations/ReservationCreator.php` or `app/Services/StayService.php`,
  scoped to the import path, with a comment.
- [X] T043 [US2] Make the mapping tests pass, adjusting the mapper, resolver and writer
  from Phase 2. Confirm `ReservationRoomSync::MAX_UNITS` is enforced with a readable
  reason.

**Checkpoint**: legacy multi-row files map correctly. US2 tests pass.

---

## Phase 5: User Story 3 - Re-importing the same file is safe (Priority: P1)

**Goal**: Re-imports never create a second reservation, guest or booking. Uploads for a
hotel run one at a time, in order. A crash followed by a re-run equals one clean run.

**Independent Test**: Import a file, import it again. The second report is all
`duplicate`, and the reservation, guest and booking counts are unchanged.

### Tests for User Story 3

- [X] T044 [P] [US3] Create `tests/Feature/ReservationImportIdempotencyTest.php`:
  - an identical re-import: all `duplicate` with `reservation_id` set to the existing
    reservation, and counts of reservations, guests and bookings unchanged (SC-002);
  - a corrected re-run: previously invalid rows now imported, the others `duplicate`;
  - reference-less rows equal to an existing reservation (same primary guest, dates and
    room types) → `duplicate`; two equal reference-less rows in one file → the second is
    `duplicate`;
  - the same reference at another hotel → imported at this hotel;
  - a changed departure → `duplicate` with warning `data_differs`, stored reservation
    unchanged;
  - a soft-deleted reservation with the reference → restored, warning `restored`,
    outcome `imported`;
  - two uploads for one hotel: the second's prepare job releases while the first is
    `running`, then runs and reports `duplicate`. Assert through `Queue::fake()` plus
    manual handling of the release;
  - a crash: make the writer throw a non-row error on draft 3 of a chunk run (bind a
    spy), mark the import `failed` through `failed()`, then re-upload. The final data
    equals one clean run (SC-007).

### Implementation for User Story 3

- [X] T045 [US3] Implement reference duplicates in `ReservationImportResolver`:
  - one `whereIn` over `reservations.reservation_id` with `hotel_id` and live rows;
  - a match → group outcome `duplicate` with `reservation_id`, plus `data_differs` when
    arrival, departure, status, live line count or primary guest identity differ;
  - a trashed match → the draft carries a `restore` flag, and the writer adds warning
    `restored` (`ReservationCreator::create()` already restores).
- [X] T046 [US3] Implement content duplicates (FR-022):
  - in the mapper, in-file reference-less groups with an equal (primary identity,
    arrival, departure, sorted room types) key → the later one is `duplicate`;
  - in the resolver, for reference-less groups whose primary guest matched an existing
    guest, a live reservation with that guest, the same dates and the same room-type
    multiset → `duplicate`.
- [X] T047 [US3] Verify the per-hotel ordering in `PrepareReservationImportJob`: the
  older-import check uses `created_at, id` ordering and ignores the job's own import.
  Write jobs never start a newer import's prepare. Add a docblock describing the handoff.

**Checkpoint**: re-imports and concurrent uploads are safe. US3 tests pass.

---

## Phase 6: User Story 4 - Conservative guest matching (Priority: P2)

**Goal**: A guest is reused only on a confident match by phone, email or old-system id.
Conflicting signals make the reservation unresolved.

**Independent Test**: Seed a guest. Rows matching by formatted phone, by email and by
external id reuse it. A row whose phone matches A and email matches B is unresolved.

### Tests for User Story 4

- [X] T048 [P] [US4] Create `tests/Feature/ReservationImportGuestMatchingTest.php`:
  - "0020 100 123 4567" reuses the guest with "+201001234567";
  - "sara@example.com" with no phone reuses "Sara@Example.com";
  - `guest_external_id` "G-77" reuses the guest imported earlier with that id;
  - phone → A and email → B gives `unresolved` naming both, with nothing from that
    reservation written;
  - in the file, row 1 (phone P, email E1) and row 5 (phone P2, email E1, phone P2
    belonging to another in-file person) gives `unresolved`;
  - a matched guest with a different name: the name is unchanged and an empty email is
    filled in;
  - the same phone at another hotel is never matched (also in `TenantIsolationTest.php`);
  - a guest with only an email is imported with warning `no_phone`;
  - a guest with no identifier → `invalid`.

### Implementation for User Story 4

- [X] T049 [US4] Implement the in-file identity index in `ReservationMapper` (research R6):
  - keys `phone:`, `email:`, `ext:` → in-file person id, filled in file order;
  - a mention whose keys point to two different persons → the group is `unresolved`, at
    stage `map`, with a reason naming the conflicting values;
  - otherwise it joins that person and registers its new keys.
- [X] T050 [US4] Replace the `GuestIdentityService::findForReservation()` call in
  `ReservationImportResolver` with import matching (research R7), all `hotel_id`-filtered
  and batched per file:
  - by phone digits (`PhoneNumber::DIGITS_SQL` = ?);
  - by `identity_hash` = `email:`.hash;
  - by `external_source = 'import'` and `external_id`.

  More than one distinct guest across the three → `unresolved`, with a reason naming the
  candidates by first and last name only (never phone or email; research R2). Exactly one → reuse; trashed → restore
  in the writer. None → create.

**Checkpoint**: no wrong merges (SC-005). US4 tests pass.

---

## Phase 7: User Story 5 - Validate a file before committing it (Priority: P3)

**Goal**: A dry run produces the full report and writes nothing.

**Independent Test**: A dry run creates no reservation, guest, room, room type or
booking. Its row outcomes equal those of a later real import of the same file.

### Tests for User Story 5

- [X] T051 [P] [US5] Create `tests/Feature/ReservationImportDryRunTest.php`:
  - a dry run with new rooms, types, guests, clashes, duplicates and invalid rows:
    `completed`, the report is filled in, and `created_rooms` lists "would be created"
    names. The counts of reservations, guests, rooms, room types, bookings and stays are
    unchanged;
  - then a real run of the same file: each `row_number` has the same outcome and the
    same warning codes in both;
  - a dry run waits behind a running real import of the same hotel (FR-023).

### Implementation for User Story 5

- [X] T052 [US5] In `PrepareReservationImportJob`, when `dry_run` is true, mark every
  surviving draft's rows `imported` (as "would be imported") with no `reservation_id`,
  delete the drafts, and call `ReservationImportCompleter::complete()` without
  dispatching a write job. Writer-only warnings (`room_clash` found at write time) can't
  appear in a dry run. Document that limit in the import docs.
- [X] T053 [US5] Make sure the resolver never writes: no `firstOrCreate`, `RoomType::resolveFor()`
  or `Guest::create()` in prepare. Add an assertion to the dry-run test that the
  `guests`, `rooms` and `room_types` row counts are unchanged.

**Checkpoint**: dry runs are safe and faithful. US5 tests pass.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T054 [P] Rewrite §6 of `docs/reservations-api-documentation.md` for the queued
  contract, following [the contract](contracts/reservation-import-api.md):
  - stages and endpoints;
  - supported columns with synonyms;
  - date formats and `date_order`;
  - the status map and the status-vs-date rules (including `status_corrected`);
  - the room-unit counting rule;
  - matching rules, outcomes and warning codes;
  - the booking status rule;
  - the dry-run limit (T052);
  - the 5 MB limit;
  - a terminology note: the file's `reservation_reference` (old name `reservation_id`)
    is stored as the reservation's code (`reservation_id` in the API) and shown as
    `external_reference` in import reports.

  Remove the "super admins cannot import" note and the old `200` response.
- [X] T055 [P] In `docs/staff-roles-api-documentation.md`, list the four new GET
  endpoints under `reservations.import`.
- [X] T056 [P] Create `docs/latest-changes-2026-10-23.md` with the breaking change summary
  from the contract.
- [X] T057 [P] Create `specs/013-reservation-import-v2/frontend-changes.md` and append to
  `docs/frontend-changes-2026-10-23.md` (create it if missing):
  - upload with `date_order` and `dry_run`;
  - polling `GET /reservation/imports/{id}` until finished;
  - the history list;
  - the report view with the outcome filter and warnings;
  - the `.xlsx` download;
  - the super-admin hotel picker.
- [X] T058 Run the quickstart scenarios that the automated tests don't cover (manual
  queue worker run with `composer dev`; a 5,000-row generated CSV finishing within 10
  minutes, SC-003) and note the timings in `specs/013-reservation-import-v2/quickstart.md`.
- [ ] T059 Ask the project owner for the September 2025 reference export. Dry-run it on a
  staging hotel and extend `config/reservation_import.php` synonyms and status labels
  until no data column is unrecognized and no reason is a raw error. Then run it for real
  twice (SC-001, SC-002). If the export uses Arabic headers, status labels or month
  names, add them to the synonym and label tables and to `DateParser` (constitution:
  English and Arabic). Do not commit the file.
- [X] T060 Run `./vendor/bin/pint` and `php artisan test --parallel`. Fix all failures,
  including other suites that relied on `App\Imports\ReservationsImport` or the `200`
  import contract.
- [X] T061 Update the Phase 13 status in
  `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md` (F11 closed;
  SPEC-100/101 delivered).

---

## Dependencies & Execution Order

- **Setup (T001–T003)**: start immediately (after rebasing on merged `015`).
- **Foundational (T004–T023)**: needs Setup. It blocks every story.
  - T004–T008 and T011–T014 can run in parallel.
  - The models (T009, T010) need the migrations.
  - The pipeline goes T015 → T016 → T017 → T018 → T019 → T020, with T021 and T022 in
    parallel with T018–T020.
  - T023 needs T013–T016.
- **US1 (T024–T038)**: needs Foundational. It is the MVP and the base for every later
  story's end-to-end tests (it adds `imRun()`'s jobs).
- **US2 (T039–T043)**, **US3 (T044–T047)**, **US4 (T048–T050)**: each needs US1. They
  touch the mapper and resolver in separate methods, so they can be parallel with care.
  Sequential order US2 → US3 → US4 avoids merge conflicts in
  `ReservationMapper.php` / `ReservationImportResolver.php`.
- **US5 (T051–T053)**: needs US1. It is most meaningful after US2–US4.
- **Polish (T054–T061)**: after the stories it documents. T059 needs the dataset from
  the owner.

## Parallel Opportunities

- Phase 2: T004, T005, T006, T007, T008, T011, T012, T013 and T014 together.
- US1: the three test files (T024–T026) together, then T032–T035 together while T027–T029
  are written.
- Each story's test file can be written in parallel with the previous story's
  implementation.
- Polish: T054–T057 together.

## Implementation Strategy

1. **MVP = Setup + Foundational + US1**: a queued import with a full reconciliation
   report. This alone closes F11 and ships the breaking contract the frontend needs.
   Single-row files import correctly at this point.
2. **+ US2**: the reference dataset's multi-row reservations, statuses and clashes map
   correctly. This is the minimum before a real hotel migration.
3. **+ US3**: safe re-imports and ordering (Principle VII).
4. **+ US4**: conservative guest matching, a privacy safeguard. Required before
   production imports of real guest data.
5. **+ US5**: the dry run.
6. **Polish**: docs, reference-dataset tuning, timings.

Stop and validate at each checkpoint with `php artisan test --filter=ReservationImport`.

## Format Validation

All 61 tasks use `- [ ] T### [P?] [US?] description` with exact file paths. Story labels
appear only in Phases 3–7.

## Implementation Notes (2026-10-23)

Where the build differs from the plan, and why:

- **Classes**: `ImportPlan` replaces the planned `MappedFile` / `ResolvedFile` (one object
  the mapper builds and the resolver refines). New small classes: `ImportWarning` (warning
  codes), `Digits` (Arabic-Indic digits), `DraftRejected` (a readable per-reservation
  failure), `ReservationImportCompleter`.
- **Per-hotel ordering** uses the "older import first" check plus an atomic
  `queued → running` claim, with no `WithoutOverlapping` middleware. On the sync queue a
  released nested job is dropped, which would lose the write chain.
- **Retries**: the prepare job doesn't retry, because it only reads; a failure ends the
  import with a readable reason. Write jobs retry 3 times. A draft's reservation, its row
  outcomes and the draft's removal commit together, so a retried chunk resumes cleanly.
- **Audit**: the run's event stays `hotel.reservations_imported` (on the hotel, as before),
  now with the import id. It is recorded through `EventLogger::actingAs()`, or with no actor
  when the uploader was deleted.
- **Memory extraction**: research R8 was wrong. `Stay::booted()` queues extraction on any
  transition to `departed`, so imported checked-out stays would have queued it. Added
  `Stay::withoutMemoryExtraction()`, which the writer wraps around
  `ReservationCreator::create()` (T042).
- **Dates on continuation rows**: the further rows of a reservation may leave the dates
  blank. "Dates present" and "departure after arrival" are therefore checked by the mapper
  after merging, not by `RowValidator` per row.
- **Status reasons** echo the file's own label ("The file says \"No Show\"").
- **Imported bookings** are charged to the folio when the activity has a price, otherwise
  included; channel `import`.
- **Phones** are stored in normalized form, as digits with the country code.
- **Tests run in parallel**: `php artisan test --parallel`.
- **Not done**: T059 needs the September 2025 reference export from the project owner.
- **Code review fixes (2026-10-23)**:
  - references are matched ignoring case, in the resolver and in the writer's recheck;
  - a failed run settles unwritten rows as `invalid` ("Not imported: …"), so they appear
    in the totals and the report;
  - an amount whose single separator is ambiguous (`1,200`, `1.200`) is rejected instead
    of guessed, and `1.200,50` is read correctly;
  - each file person is matched on every detail any row gives, and on the national form
    of their phone, which guests stored before the import may hold;
  - import ids are time-ordered (`uuid7`), so same-second uploads keep their order;
  - the `202` response shows the stored defaults (zeros);
  - a number invalid for the hotel's country gets `phone_unrecognized`;
  - children are counted from their ages when the count is missing;
  - functional indexes were added for the case-insensitive lookups (migration `000005`).

