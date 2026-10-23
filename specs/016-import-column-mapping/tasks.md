# Tasks: Reservation Import Column Mapping

**Input**: Design documents from `specs/016-import-column-mapping/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/import-preview-api.md](contracts/import-preview-api.md), [quickstart.md](quickstart.md)

**Tests**: Required by the constitution (Principle VIII) and CLAUDE.md (permission and
tenant-isolation tests for every tenant-scoped surface). Write each story's tests first and
see them fail before implementing. Run them with `php artisan test --parallel`.

**Before starting**: commit (or carry into this branch) the uncommitted T059 changes from
2026-10-23: the synonyms and status labels in `config/reservation_import.php`, their tests
in `tests/Feature/ReservationImportNormalizationTest.php`, and the docs and quickstart
notes. Create branch `016-import-column-mapping` from `main`.

**Organization**: a shared foundation (tables, models, policy, layout-aware reader), then
one phase per user story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: the user story the task belongs to (US1–US5)

---

## Phase 1: Setup

**Purpose**: Config and shared test fixtures. No behaviour change.

- [X] T001 Extend `config/reservation_import.php` (research R5, R11):
  - add `'guest_full_name'` to `canonical_columns`;
  - add the header synonyms `'guest_name'`, `'name'` and `'full_name'` → `'guest_full_name'`;
  - add `field_labels`: an English label for every canonical column, for example
    `arrival_date => 'Arrival date'`, `guest_full_name => 'Guest full name'`,
    `guests => 'Guests (JSON list)'`;
  - add `'preview_ttl_hours' => 24` and `'preview_head_rows' => 30`;
  - add `'preview_header_rows' => 10` (how many rows can be the header).
- [X] T002 [P] Add preview fixtures to `tests/Pest.php`, next to the existing `im*` helpers:
  - `imXlsx(array $rows): UploadedFile`: writes a temp `.xlsx` from a list of rows, any of
    which may be title lines (use PhpSpreadsheet `fromArray`);
  - `imPreview($test, User $user, UploadedFile $file, array $fields = []): TestResponse`:
    a multipart POST to `/api/reservation/import-previews` with `apiHeaders()` and
    `actingAs`;
  - `imConfirm($test, User $user, string $previewId, array $body): TestResponse`: a JSON
    POST to `/api/reservation/import-previews/{id}/confirm`.

---

## Phase 2: Foundational (blocks every story)

**Purpose**: Tables, models, enum, policy and the layout-aware reader. Nothing is reachable
over HTTP yet.

- [X] T003 [P] Migration
  `database/migrations/2026_10_23_000006_create_reservation_import_previews_table.php`
  (data-model):
  - `id` uuid primary key;
  - `hotel_id` uuid FK → hotels, cascade on delete;
  - `uploaded_by` uuid FK → users, null on delete;
  - `file_name` string(255) and `file_path` string;
  - `head_rows` jsonb, default `'[]'::jsonb`;
  - `last_row` unsigned int, default 0;
  - `expires_at` timestampTz;
  - timestampsTz;
  - index on `(hotel_id, expires_at)` and on `expires_at`.
- [X] T004 [P] Migration
  `database/migrations/2026_10_23_000007_create_reservation_import_mappings_table.php`:
  - `id` uuid primary key;
  - `hotel_id` uuid FK → hotels, cascade on delete, **unique**;
  - `header_row` unsigned smallint, default 1;
  - `columns` jsonb, default `'{}'::jsonb`;
  - `updated_by` uuid FK → users, null on delete;
  - timestampsTz.
- [X] T005 Migration
  `database/migrations/2026_10_23_000008_add_mapping_columns_to_reservation_imports_table.php`,
  after T003, on `reservation_imports`:
  - `reservation_import_preview_id` uuid FK → `reservation_import_previews`, nullable,
    **null on delete**, indexed;
  - `header_row` unsigned smallint, default 1;
  - `column_map` jsonb, nullable;
  - `decimal_mark` string(8), default `'auto'`.
- [X] T006 [P] Create `app/Enums/ImportDecimalMark.php`: a backed string enum with
  `AUTO = 'auto'`, `DOT = 'dot'` and `COMMA = 'comma'`, styled like
  `app/Enums/ImportDateOrder.php`.
- [X] T007 [P] Create `app/Models/ReservationImportPreview.php`:
  - traits `BelongsToHotel` and `HasUuids`, **no** `SoftDeletes`; the docblock says why
    (research R7: guest data must really go);
  - `$fillable` and `$casts` (`head_rows` array, `last_row` integer, `expires_at` datetime);
  - relations `uploader()` → User via `uploaded_by`, and `imports()` → hasMany
    ReservationImport via `reservation_import_preview_id`;
  - `isExpired(): bool`;
  - a scope `unexpired()` (`expires_at > now()`).
- [X] T008 [P] Create `app/Models/ReservationImportMapping.php`: traits `BelongsToHotel`
  and `HasUuids`; `$fillable` = `header_row`, `columns`, `updated_by`; `$casts` =
  `columns` array and `header_row` integer.
- [X] T009 Update `app/Models/ReservationImport.php`:
  - add `reservation_import_preview_id`, `header_row`, `column_map` and `decimal_mark` to
    `$fillable`;
  - casts: `column_map` → array, `header_row` → integer,
    `decimal_mark` → `ImportDecimalMark::class`;
  - add `preview()`, a belongsTo ReservationImportPreview.
- [X] T010 [P] Create `app/Policies/ReservationImportPreviewPolicy.php`:
  - `before()` lets a super admin through, as in `ReservationImportPolicy`;
  - `viewAny` and `create` check `allows($user, Permission::RESERVATIONS_IMPORT)`;
  - `view` and `delete` check `allows($user, Permission::RESERVATIONS_IMPORT, $preview)`;
  - register the policy if the project doesn't auto-discover policies (check
    `AppServiceProvider`).
- [X] T011 Make `ImportFileReader::read()` in
  `app/Support/Import/Reservations/ImportFileReader.php` layout-aware (research R3):
  - **Signature**: `read(string $path, ?string $disk = null, int $headerRow = 1, ?array $columnMap = null)`.
  - **Header row**: skip every row above `$headerRow` and take the header from that row.
  - **Row numbers**: `row_number = index + $headerRow + 1`, the file's own numbering.
  - **With a `$columnMap`** (position → field|null): read only the mapped positions as
    their fields. `unrecognized` lists the trimmed header text of every unmapped position
    whose header isn't blank.
  - **Without a map**: the synonym logic stays exactly as it is.
  - **Header normalization**: make `normalizeHeader()` `public static`, since the
    suggester and the saved mapping use it.
  - **Required columns**: `assertRequiredColumns()` still runs in both modes.
- [X] T012 Pass the import's layout through in `app/Jobs/PrepareReservationImportJob.php`:
  call `$reader->read($import->file_path, config('reservation_import.disk'), $import->header_row ?? 1, $import->column_map)`.
- [X] T013 [P] Tests for T011 in
  `tests/Feature/ReservationImportNormalizationTest.php`:
  - a CSV with two title rows, read with `headerRow: 3`, gives `row_number` 4 for the first
    data row;
  - a `columnMap` of `{0: arrival_date, 1: departure_date, 2: guest_phone, 3: null}` reads
    headers `Arr`, `Dep`, `Mob`, `Fax`, and lists `Fax` as unrecognized;
  - a map that ignores a known header (`Email` → null) leaves it out of the values;
  - with no map, behaviour is unchanged (the existing tests still pass).

**Checkpoint**: `php artisan migrate`, then the normalization tests pass.

---

## Phase 3: User Story 1 — Map an unfamiliar export and import it (P1) 🎯 MVP

**Goal**: upload → preview (with a header-row choice) → confirm a mapping → a queued
import that runs as today. Previews can be listed, reopened and discarded, and they expire.

**Independent test**: quickstart scenarios 1–4 and 8–10, 14 and 15. Upload a file with
unknown arrival, departure and phone headers, map them, confirm, and compare the outcomes
with the same file using known headers.

### Tests for User Story 1 (write first, see them fail)

- [X] T014 [P] [US1] Create `tests/Feature/ReservationImportPreviewTest.php`, with
  `uses(RefreshDatabase::class)` and the API key in `beforeEach`:
  - **Upload:** returns `201` with `columns` (position, letter, header, up to 5 non-empty
    `samples`), `fields`, `rows` (the first 10) and `data_rows`. The file is on the
    private disk under `reservation-import-previews/{hotel_id}/`.
  - **Date cells:** they appear in `samples` as formatted dates, not serial numbers.
  - **Header row:** for a file with title rows 1–3,
    `GET /api/reservation/import-previews/{id}?header_row=4` rebuilds `columns` from row 4,
    with `data_rows = last_row - 4`. `header_row=11` and `header_row` past the last row
    are `422` "The file has only N rows."
  - **Blank and duplicate headers:** a blank header shows as `"Column F"`. Two `Phone`
    columns are separate positions.
  - **Unreadable or oversized files:** a corrupt file and a file over 5 MB are `422`
    `file`, and nothing is stored.
  - **List and discard:** the list shows only unexpired previews, newest first, without
    `rows`, `columns` or `fields`. `DELETE` returns `200`, deletes the file, and the
    preview then 404s.
  - **Expiry:** with `expires_at` in the past, `GET` and confirm are `410`. After
    `PruneReservationImportPreviewsJob` runs, the record and file are gone (404).
  - **Super admin:** a super admin without `hotel_id` gets `422`; with it, `201` for that
    hotel.
- [X] T015 [P] [US1] Create `tests/Feature/ReservationImportMappingConfirmTest.php`:
  - **Unknown headers:** a file with headers `Arr. Date`, `Dep. Date`, `Mob.` and
    `Nationality`, confirmed with a mapping that leaves `Nationality` null, returns `202`
    with `status: queued`, `header_row`, `column_map`, `preview_id` and
    `decimal_mark: auto`. After `imRun()`, the outcomes equal those of the same rows
    uploaded through `/api/reservation/import` with canonical headers. The import's
    `unrecognized_columns` is `['Nationality']`.
  - **Header on row 4:** the import's row results start at `row_number` 5.
  - **Refusals** (each a `422` with a message under `columns`, no import created, saved
    mapping untouched): arrival date missing; departure date missing; no guest
    identifier; `guest_phone` on two positions; position 99; field `fax`; full name
    together with first name. A combined case returns several messages in one response.
  - **Re-confirm:** confirming the same preview twice (dry run, then real) creates two
    imports, both listed in the preview's `imports`.
  - **Discard after import:** discarding the preview leaves the imports readable with
    `preview_id: null`.
- [X] T016 [P] [US1] Add the five preview endpoints to the `reservations.import` dataset
  in `tests/Feature/PermissionAuthorizationTest.php`: `preview upload`, `preview list`,
  `preview show`, `preview confirm` and `preview discard`. For each, an employee without
  the permission gets `403` and one with it succeeds. Follow the import block at about
  line 470: create the preview as the granted user first.
- [X] T017 [P] [US1] Add a test to `tests/Feature/TenantIsolationTest.php`, next to the
  SPEC-100/101 import test: another hotel's admin gets `404` on show, confirm and discard
  of this hotel's preview. The list shows only the caller's own hotel's previews.

### Implementation for User Story 1

- [X] T018 [P] [US1] Add `ImportFileReader::head(string $path, ?string $disk = null): array`
  in `app/Support/Import/Reservations/ImportFileReader.php`:
  - read the first sheet once with an anonymous import implementing `WithFormatData`;
  - return `['rows' => <first preview_head_rows rows as trimmed strings, each trimmed to its last non-empty cell>, 'last_row' => <1-based index of the last non-blank row, 0 if none>]`;
  - on a read failure, throw `ImportFailed` with the same message `read()` uses.
- [X] T019 [P] [US1] Create
  `app/Support/Import/Reservations/ImportPreviewBuilder.php`, with
  `build(ReservationImportPreview $preview, int $headerRow, ImportColumnSuggester $suggester): array`.
  It returns:
  - `rows`: the first `preview_header_rows` entries of `head_rows`;
  - `columns`: one per position in the header row or any wider row below it, each
    `{position, letter, header, samples, suggested_field}`. `letter` comes from
    `Coordinate::stringFromColumnIndex(position + 1)`. A blank header becomes
    `"Column {letter}"`. `samples` are up to 5 non-empty values from the head rows below
    the header row;
  - `data_rows`: `max(0, last_row - headerRow)`;
  - `fields`: from config `canonical_columns` and `field_labels`, each
    `{key, label, required, guest_identifier}`, where `required` comes from
    `required_columns` and `guest_identifier` from `guest_identifier_columns`.
- [X] T020 [P] [US1] Create
  `app/Support/Import/Reservations/ImportColumnSuggester.php`, with
  `suggest(array $headers, ?ReservationImportMapping $saved): array` (position → field|null).
  Order: the saved mapping by normalized header (an explicit null wins), then
  `header_synonyms`, then a header that is already a canonical column name, then null.
  A field already given to an earlier position isn't suggested again (FR-007).
  Also `suggestedHeaderRow(?ReservationImportMapping $saved, int $lastRow): int`, which
  returns the saved row if it is ≤ min(`preview_header_rows`, `$lastRow`), otherwise 1.
  US1 calls these with `$saved = null`; US3 supplies the saved mapping.
- [X] T021 [P] [US1] Create
  `app/Http/Requests/StoreReservationImportPreviewRequest.php` with the same `file` and
  `hotel_id` rules as `ImportReservationsRequest` (`mimes:xlsx,xls,csv,txt`, `max:5120`,
  `hotel_id` nullable uuid).
- [X] T022 [P] [US1] Create
  `app/Http/Requests/ShowReservationImportPreviewRequest.php`. `header_row` is a nullable
  integer, `min:1`, `max:config('reservation_import.preview_header_rows')`. In `after()`,
  add `header_row` "The file has only N rows." when it is greater than the bound preview's
  `last_row`.
- [X] T023 [US1] Create
  `app/Http/Requests/ConfirmReservationImportPreviewRequest.php` (research R9).
  **Rules:**
  - `header_row`: required integer 1–`preview_header_rows`, and ≤ the preview's `last_row`;
  - `columns`: required array;
  - `columns.*`: nullable string;
  - `date_order`: nullable, `Rule::enum(ImportDateOrder::class)`;
  - `decimal_mark`: nullable, `Rule::enum(ImportDecimalMark::class)`;
  - `dry_run`: nullable boolean.

  **In `after()`**, using the preview's columns at that header row (from
  `ImportPreviewBuilder`), add one `columns` error per problem:
  - a key that isn't an existing position;
  - a value that isn't a key of the mappable fields;
  - a field used more than once (name the field);
  - `arrival_date` not mapped;
  - `departure_date` not mapped;
  - none of the `guest_identifier_columns` mapped;
  - `guest_full_name` mapped together with `guest_first_name` or `guest_last_name`.

  Normalize the keys to ints before checking.
- [X] T024 [P] [US1] Create `app/Http/Resources/ReservationImportPreviewResource.php`
  matching the contract's Preview resource. `rows`, `columns`, `fields`, `header_row`
  and `data_rows` are included only when the controller set them (use `$this->when` with
  a built-layout attribute), so the list stays light. Also: `uploaded_by {id, name}`,
  `suggested_header_row`, `created_at`, `expires_at`, and `imports` (id, dry_run, status,
  created_at, newest first).
- [X] T025 [US1] Add the four new fields to
  `app/Http/Resources/ReservationImportResource.php`:
  - `preview_id` (from `reservation_import_preview_id`);
  - `header_row`;
  - `column_map`;
  - `decimal_mark` (`?->value`).
- [X] T026 [US1] Create `app/Http/Controllers/ReservationImportPreviewController.php`.
  Keep it thin: authorize with `ReservationImportPreviewPolicy`, and return everything
  through `apiResponse()`.
  - **`store`**: `resolveHotel()` (`422` `hotel_id` if null, as in
    `ReservationController::import()`). Read `head()` from the uploaded temp file **before**
    storing it, turning `ImportFailed` into a `422` `file` error. Then store it with
    `storeAs("reservation-import-previews/{hotel_id}", "{id}.{ext}", config('reservation_import.disk'))`,
    using a pre-generated `Str::uuid7()` that is set explicitly on the model (`forceFill`,
    since `id` isn't fillable). Create the preview with
    `expires_at = now()->addHours(config('reservation_import.preview_ttl_hours'))`.
    Return `201` with the resource built at the suggested header row.
  - **`index`**: unexpired previews, `-created_at`, paginated via `GenericIndexRequest`,
    with `uploader` and `imports` eager-loaded.
  - **`show`**: `410` "This upload has expired. Upload the file again." if expired;
    otherwise build at `header_row` or the suggested row.
  - **`confirm`**: `410` if expired. Inside `DB::transaction`, create the
    `ReservationImport` with a pre-generated uuid7 set explicitly:
    `reservation_import_preview_id`, `header_row`, `column_map` (the validated `columns`
    with int keys), `decimal_mark` (default `auto`), `date_order` (default `day_first`),
    `dry_run`, `file_name` from the preview, `status: queued`. Copy the preview file to
    `reservation-imports/{hotel_id}/{import_id}.{ext}` and set `file_path`. Dispatch
    `PrepareReservationImportJob` `->afterCommit()`. Return `202` with
    `ReservationImportResource`, as `ReservationController::import()` does.
  - **`destroy`**: delete the file from the disk, then the record; `200` "Upload discarded."
- [X] T027 [US1] Register the routes in `routes/api.php` next to the existing
  `/reservation/imports` routes, **before** the `/reservation` resource:
  - `POST /reservation/import-previews` → store;
  - `GET /reservation/import-previews` → index;
  - `GET /reservation/import-previews/{reservationImportPreview}` → show;
  - `POST /reservation/import-previews/{reservationImportPreview}/confirm` → confirm;
  - `DELETE /reservation/import-previews/{reservationImportPreview}` → destroy.
- [X] T028 [P] [US1] Create `app/Jobs/PruneReservationImportPreviewsJob.php`. Under
  `TenantContext::withoutScope()`, delete every preview with `expires_at <= now()`, file
  first, then record, in chunks of 100. Schedule it hourly in `routes/console.php` next to
  `PurgeDeletedKnowledgeDocumentsJob`.

**Checkpoint**: T014–T017 pass. Quickstart scenarios 1–4, 8–10, 14 and 15 behave as listed.

---

## Phase 4: User Story 2 — The mapping is suggested, not typed (P1)

**Goal**: known headers arrive mapped, unknown ones don't, and no field is suggested twice.

**Independent test**: quickstart scenario 5. A file with only known headers, confirmed
without edits, gives the same result as a direct upload.

- [X] T029 [P] [US2] Add tests to `tests/Feature/ReservationImportPreviewTest.php`:
  - `Check-In`, `Email` and `Res No.` are suggested `arrival_date`, `guest_email` and
    `reservation_reference`;
  - `Arr Dt X` gets null;
  - with both `Mobile` and `Phone`, only the first is suggested `guest_phone`;
  - a header that is already a canonical name (`room_type`) is suggested as itself.
- [X] T030 [P] [US2] Add a test to `tests/Feature/ReservationImportMappingConfirmTest.php`:
  build a CSV with only known headers. Confirm its preview with `columns` equal to the
  suggestions, and upload the same file directly. After `imRun()` on both, the per-row
  outcomes and the reservation counts match (SC-002). Use separate hotels, or the second
  run is all duplicates.
- [X] T031 [US2] Make T029–T030 pass in `ImportColumnSuggester` (T020) and
  `ImportPreviewBuilder` (T019). The ordering and first-wins dedupe are already specified
  there, so this task is the fixes the tests reveal.

**Checkpoint**: US1 and US2 together are the MVP. An admin can import any export, and
known exports need no edits.

---

## Phase 5: User Story 3 — The hotel's mapping is remembered (P2)

**Goal**: a confirm saves the hotel's mapping (headers, explicit nulls and header row),
and the next preview suggests it.

**Independent test**: quickstart scenarios 6 and 7.

- [X] T032 [P] [US3] Add tests to `tests/Feature/ReservationImportMappingConfirmTest.php`:
  - after confirming `Mob.` → `guest_phone` and `Email` → null at `header_row` 2, a new
    preview with the same headers in another order suggests `guest_phone` for `Mob.`,
    null for `Email`, and `suggested_header_row` 2;
  - a header absent from the saved mapping falls back to the synonym table;
  - a refused confirm (`422`) leaves the saved mapping unchanged (SC-006);
  - a dry-run confirm also saves the mapping.
- [X] T033 [P] [US3] Add to `tests/Feature/TenantIsolationTest.php`: hotels A and B confirm
  different mappings for the same headers, and each one's next preview suggests only its
  own.
- [X] T034 [US3] In `ReservationImportPreviewController::confirm()` (T026), upsert
  `ReservationImportMapping` for the hotel inside the same transaction:
  - `header_row`;
  - `columns` = `{ ImportFileReader::normalizeHeader(header) => field|null }` for each
    position with a non-blank header, the first position winning on duplicate normalized
    headers;
  - `updated_by` = the user.
- [X] T035 [US3] Pass the hotel's `ReservationImportMapping` (or null) to
  `ImportColumnSuggester` in `store` and `show`, and use `suggestedHeaderRow()` for the
  default header row and the resource's `suggested_header_row`.

**Checkpoint**: the quickstart scenario 6 round trip works.

---

## Phase 6: User Story 4 — A single "guest name" column (P2)

**Goal**: `guest_full_name` is split into first and last name, for both mapped and direct
uploads.

**Independent test**: quickstart scenario 11. Also scenario 13 for the direct upload.

- [X] T036 [P] [US4] Add tests to `tests/Feature/ReservationImportNormalizationTest.php`
  for the data-model "Full name split" table (`Mohamed Abdel Rahman` → `Mohamed` /
  `Abdel Rahman`; `Smith, John` → `John` / `Smith`; `  Sara  ` → `Sara` / null; empty →
  no name). Also:
  - when `guest_first_name` is also present on the row, the separate fields win;
  - a row with only `guest_full_name` and no contact still gets the "has no phone, email or
    guest id" error;
  - a direct-upload CSV with a `Guest Name` header maps to `guest_full_name` through the
    synonym table.
- [X] T037 [US4] In `app/Support/Import/Reservations/RowNormalizer.php`:
  - add `'guest_full_name'` to the `$columns` list that decides whether the row names a
    guest (`guests()`);
  - when both `guest_first_name` and `guest_last_name` are empty, split
    `guest_full_name` (FR-016): a comma gives last name before it and first name after
    it; otherwise the first word is the first name and the rest the last name; collapse
    whitespace; empty parts become null;
  - the split runs on the raw text before `guest()`.

**Checkpoint**: T036 passes. Quickstart 11 gives the expected guest names.

---

## Phase 7: User Story 5 — Amounts written with thousands separators (P3)

**Goal**: `decimal_mark` (`auto`, `dot`, `comma`) on the confirm and the direct upload,
applied in `RowNormalizer::money()`.

**Independent test**: quickstart scenario 12, then the synthetic export re-run (SC-004).

- [X] T038 [P] [US5] Add tests to `tests/Feature/ReservationImportNormalizationTest.php`
  for the data-model `ImportDecimalMark` table: `25,200`, `1,260.50`, `EGP 3,600`,
  `99,50`, `25.200`, `1.260,50` under each of `auto`, `dot` and `comma`. Numeric cells are
  unaffected in every mode. Each invalid case names `reservation_value` and quotes the raw
  value.
- [X] T039 [P] [US5] Add tests:
  - `tests/Feature/ReservationImportMappingConfirmTest.php`: the confirm accepts
    `decimal_mark: dot` and stores it, and rejects `decimal_mark: euro` with `422`;
  - `tests/Feature/ReservationImportTest.php`: the direct upload accepts
    `decimal_mark=dot` and the import shows it; without it, the import shows `auto`.
- [X] T040 [US5] Change `RowNormalizer::normalize()` in
  `app/Support/Import/Reservations/RowNormalizer.php` to take an
  `ImportDecimalMark $decimalMark = ImportDecimalMark::AUTO` after `$order`, and pass it to
  `money()`. `AUTO` keeps `decimalAmount()` as it is. `DOT` accepts
  `^-?\d{1,3}(,\d{3})+(\.\d+)?$` or `^-?\d+(\.\d+)?$` and strips ",". `COMMA` mirrors it
  (strips ".", turns "," into "."). Anything else is the error
  `reservation_value "{raw}" doesn't match the chosen decimal mark ({mode}).`
- [X] T041 [US5] Wire the setting through:
  - `PrepareReservationImportJob` passes `$import->decimal_mark ?? ImportDecimalMark::AUTO`
    to `normalize()`;
  - `ImportReservationsRequest` adds `'decimal_mark' => ['nullable', Rule::enum(ImportDecimalMark::class)]`;
  - `ReservationController::import()` stores `decimal_mark` (default `auto`).

**Checkpoint**: T038–T039 pass. The synthetic export, re-run as a dry run with `dot`, has
zero `reservation_value` rejections (SC-004).

---

## Phase 8: Polish and cross-cutting

- [X] T042 [P] Document the feature in `docs/reservations-api-documentation.md`:
  - a "Column mapping (preview)" section with the five endpoints, the Preview resource,
    the confirm body, every `422` / `410` case, the 24-hour expiry and the saved mapping;
  - in the import section, `decimal_mark`, the new Import resource fields and the
    `Guest Name` / `guest_full_name` column;
  - the header synonyms added for full name.
- [X] T043 [P] Add the five `/api/reservation/import-previews` endpoints to the
  `reservations.import` row of the permission reference in
  `docs/staff-roles-api-documentation.md`.
- [X] T044 [P] Write `specs/016-import-column-mapping/frontend-changes.md`, the frontend
  handoff, styled like `specs/013-reservation-import-v2/frontend-changes.md`.
  - **Flow**: upload (a file input plus the super-admin hotel picker), then the mapping
    screen.
  - **Header row**: a picker showing the first 10 rows, with re-fetch on change.
  - **Columns table**: header, samples, and a field dropdown (fields from `fields`, each
    usable once, an "Ignore" option, required and identifier fields marked).
  - **Settings**: date order, decimal mark and a dry-run toggle.
  - **Confirm**: errors from `422 columns` shown per message; on `202`, go to the existing
    import status page.
  - **Open uploads**: list, reopen and discard.
  - **States**: `410` (re-upload prompt), empty, loading and error states.
  - **Arabic**: translations for the field labels, by key.
  - Note that the direct-upload flow can stay as the fallback.
- [X] T045 Update the Phase 13 note in
  `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md`: column mapping delivered as
  `specs/016-import-column-mapping`, extending SPEC-100/101.
- [X] T046 Run the quickstart's preview timing (SC-005) with a generated 5,000-row `.xlsx`
  of about 27 columns against `Hospitality_Ecosystem_staging`
  (`DB_DATABASE=Hospitality_Ecosystem_staging`, never the dev database). Re-run the
  synthetic export with `dot` (SC-004). Record both results in
  `specs/016-import-column-mapping/quickstart.md`.
- [X] T047 Run `./vendor/bin/pint` and `php artisan test --parallel`. Fix every failure,
  including SPEC-100 suites affected by the `ImportFileReader::read()` and
  `RowNormalizer::normalize()` signature changes.

---

## Dependencies and execution order

```text
Phase 1 (T001–T002)
  └─ Phase 2 (T003–T013)   T005 after T003; T009 after T005/T006; T012 after T011
       └─ US1 (T014–T028)  MVP core, blocks the rest
            ├─ US2 (T029–T031)
            ├─ US3 (T032–T035)  needs T026 (confirm) and T020 (suggester)
            ├─ US4 (T036–T037)  independent of US2/US3; needs only T001
            └─ US5 (T038–T041)  independent of US2–US4
                 └─ Polish (T042–T047)
```

- US4 and US5 change only the normalizer and request plumbing. They could start right after
  Phase 2, but their confirm-endpoint tests (T039) need US1's controller.
- Within US1, T018–T022 and T024 are parallel. T023 needs T019. T026 needs T018–T025.
  T027 comes after T026.

## Parallel examples

- **After Phase 1**: T003, T004, T006, T007, T008 and T010 together. Then T005 and T009.
  Then T011 and T013, then T012.
- **US1 tests**: T014, T015, T016 and T017 together.
- **US1 implementation**: T018, T019, T020, T021, T022, T024 and T028 together. Then T023,
  T025 and T026, then T027.
- **After US1**: US2 (T029–T031), US3 (T032–T035), US4 (T036–T037) and US5 (T038–T041)
  can run in parallel. US3 and US5 both edit the confirm controller and request, so
  coordinate those two.

## Implementation strategy

1. **MVP = Phases 1–4 (US1 and US2).** An admin can import any export by mapping it, and
   known exports need no edits. Ship it with T042–T044 if needed.
2. **Then US3** (remembered mapping) for repeat imports.
3. **Then US4 and US5**, which close the two data gaps found in the T059 rehearsal.
4. **Finish with Polish**: the timing run, the synthetic re-run, Pint and the full suite.

Total: 47 tasks. Setup 2, foundation 11, US1 15, US2 3, US3 4, US4 2, US5 4, polish 6.
