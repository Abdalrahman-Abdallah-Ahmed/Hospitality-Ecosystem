# Implementation Plan: Reservation Import Column Mapping

**Branch**: `016-import-column-mapping` | **Date**: 2026-10-23 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/016-import-column-mapping/spec.md`. Extends
Phase 13 (`specs/013-reservation-import-v2`, SPEC-100/101, on `main`). It came out of the
T059 synthetic-export rehearsal on 2026-10-23.

**Depends on**: the SPEC-100 import pipeline on `main` (`ImportFileReader`,
`RowNormalizer`, `PrepareReservationImportJob`, `ReservationImportPolicy`). It also builds
on the uncommitted T059 config additions (header synonyms and status labels). Commit those
first or with this feature.

## Summary

- **Preview (R1, R7)**: a new upload endpoint stores the file privately and reads the
  first sheet once, with formatted values. It keeps the first 30 rows and the last row's
  number on a `reservation_import_previews` record. From those, the screen is built for any
  header row from 1 to 10 without reading the file again: columns, up to 5 samples each,
  suggestions and the data row count. Previews expire after 24 h. An hourly job deletes
  expired ones and their files, and discarding deletes one at once.
- **Suggestions (R4, R8)**: per column, the hotel's saved mapping
  (`reservation_import_mappings`, by normalized header text) comes first, then the synonym
  table, then a canonical-name match. No field is suggested twice.
- **Confirm (R2, R9)**: a JSON request with `header_row`, `columns` (sheet position → field
  or null), `date_order`, `decimal_mark` and `dry_run`. A form request reports every
  mapping problem in one `422`. If valid, one transaction:
  - copies the file to the import path;
  - creates a normal `ReservationImport` that records the header row, column map, decimal
    mark and preview;
  - upserts the hotel's saved mapping.

  It then dispatches the existing `PrepareReservationImportJob`. Status, rows and report
  are unchanged.
- **Reader layout (R3)**: `ImportFileReader::read()` takes the import's header row and
  column map. With a map, only mapped positions are read and the other headers are
  reported as unrecognized. Without one, the synonym table is used as today. Rows above
  the header are skipped, and row numbers stay the file's own.
- **Full name (R5)**: new canonical field `guest_full_name` (with synonyms, so direct
  uploads get it too), split in `RowNormalizer` as described in the data model.
- **Decimal mark (R6)**: an `ImportDecimalMark` enum (`auto`, `dot`, `comma`) stored on the
  import and passed to `RowNormalizer::money()`. `auto` is today's rule. The direct upload
  accepts it too.
- **Access**: a new `ReservationImportPreviewPolicy` on the existing
  `reservations.import` permission. Previews are hotel-scoped, so another hotel's is a
  `404`. No new permission and no new audit event (R10, R12).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.

**Primary Dependencies**: maatwebsite/excel ^3.1 (`WithFormatData` for the preview read),
Eloquent, Sanctum, Pest 4. Nothing new.

**Storage**: PostgreSQL.
- New tables: `reservation_import_previews` and `reservation_import_mappings`.
- New columns on `reservation_imports`: `reservation_import_preview_id`, `header_row`,
  `column_map`, `decimal_mark`.
- Preview files go on the private local disk under
  `reservation-import-previews/{hotel_id}/{id}.{ext}`.

**Testing**: Pest feature tests against the real Postgres test database, run with
`php artisan test --parallel`.

**Target Platform**: Linux server, JSON API. The `database` queue and the scheduler are
already in use.

**Project Type**: web service (backend only; the frontend gets a handoff).

**Performance Goals**: the preview of a 5,000-row file within 10 s (SC-005). Switching the
header row reads no file. Import throughput is unchanged from SPEC-100.

**Constraints**:
- No breaking change to the SPEC-100 endpoints (FR-020).
- One parse per upload for the preview (R1).
- Preview data is deleted at expiry (R7).
- Every mapping error is reported in a single response (R9).

**Scale/Scope**: files up to 5 MB, about 5,000 rows and 30 columns. About 10 new or changed
classes, 3 migrations, 5 routes and 1 scheduled job.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How |
| --- | --- | --- |
| I. PMS is the system of record | Pass | The import still writes only through `ReservationCreator` and `BookingService` (SPEC-100). The mapping only renames columns |
| II. Preserve and evolve | Pass | It extends `ImportFileReader`, `RowNormalizer`, the import model and its resource. The direct upload and the synonym table are kept (R3) |
| III. Tenant isolation | Pass | Previews and mappings use `BelongsToHotel`. The prune job opts in explicitly with `withoutScope()`. Isolation tests are planned (quickstart 7, 14) |
| IV. Permission-based authorization | Pass | `ReservationImportPreviewPolicy` uses `allows($user, Permission::RESERVATIONS_IMPORT, $record)`. The endpoints are added to the permission reference and the authorization dataset |
| V. AI through tools | N/A | No AI surface |
| VI. Auditability | Pass | The import a confirm creates keeps its `reservations_imported` event (R12). The import records the mapping it ran with (FR-013) |
| VII. Data integrity and idempotency | Pass | Confirming is atomic (import plus saved mapping, R9). Re-import idempotency is unchanged. Each confirm gets its own file copy (R2) |
| VIII. Tested at the domain boundary | Pass | Feature tests per endpoint and normalizer tests for full name and decimal mark (quickstart) |
| IX. Spec-driven, incremental | Pass | Spec, clarify and plan are done. Tasks and the frontend handoff follow |
| Internationalization | Pass | Field labels are keyed. Arabic headers work through the user's mapping, and Arabic digits are read as today |
| Privacy | Pass | Samples are shown only to holders of the import permission, and deleted with the preview at 24 h (R7) |

**Post-design re-check**: still passing. No violations, so Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```text
specs/016-import-column-mapping/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── import-preview-api.md
├── checklists/
│   └── requirements.md
├── frontend-changes.md   # handoff for the mapping screen (tasks phase)
└── tasks.md              # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/
│   └── ImportDecimalMark.php                       # new
├── Http/
│   ├── Controllers/
│   │   ├── ReservationImportPreviewController.php  # new: store, index, show, confirm, destroy
│   │   └── ReservationController.php               # import(): accept decimal_mark
│   ├── Requests/
│   │   ├── StoreReservationImportPreviewRequest.php    # new
│   │   ├── ShowReservationImportPreviewRequest.php     # new (header_row)
│   │   ├── ConfirmReservationImportPreviewRequest.php  # new (R9)
│   │   └── ImportReservationsRequest.php               # + decimal_mark
│   └── Resources/
│       ├── ReservationImportPreviewResource.php    # new
│       └── ReservationImportResource.php           # + preview_id, header_row, column_map, decimal_mark
├── Jobs/
│   ├── PrepareReservationImportJob.php             # pass layout + decimal mark through
│   └── PruneReservationImportPreviewsJob.php       # new, hourly
├── Models/
│   ├── ReservationImportPreview.php                # new
│   ├── ReservationImportMapping.php                # new
│   └── ReservationImport.php                       # new fillable/casts, preview relation
├── Policies/
│   └── ReservationImportPreviewPolicy.php          # new
└── Support/Import/Reservations/
    ├── ImportFileReader.php                        # layout-aware read() + head() for previews
    ├── ImportPreviewBuilder.php                    # new: columns/samples/suggestions from head_rows
    ├── ImportColumnSuggester.php                   # new: saved mapping → synonyms → canonical
    └── RowNormalizer.php                           # guest_full_name, decimal mark
config/reservation_import.php                       # guest_full_name, field_labels, preview_ttl_hours
database/migrations/
├── 2026_10_23_000006_create_reservation_import_previews_table.php
├── 2026_10_23_000007_create_reservation_import_mappings_table.php
└── 2026_10_23_000008_add_mapping_columns_to_reservation_imports_table.php
routes/api.php                                      # 5 preview routes, before /reservation resource
routes/console.php                                  # hourly prune
tests/Feature/
├── ReservationImportPreviewTest.php                # new: upload, show, header row, list, discard, expiry
├── ReservationImportMappingConfirmTest.php         # new: confirm, validation, saved mapping, re-confirm
├── ReservationImportNormalizationTest.php          # + full name, decimal modes, layout reader
├── TenantIsolationTest.php                         # + previews, mappings
└── PermissionAuthorizationTest.php                 # + preview endpoints
docs/reservations-api-documentation.md              # preview section, decimal_mark, new Import fields
docs/staff-roles-api-documentation.md               # reservations.import endpoints
```

**Structure Decision**: Laravel single project, following the SPEC-100 layout.
- Import logic stays in `app/Support/Import/Reservations`.
- HTTP code follows the thin-controller convention, and every body goes through a Resource.
- The preview controller is separate from `ReservationImportController`, which only reads
  imports.

## Complexity Tracking

No constitution violations to justify.
