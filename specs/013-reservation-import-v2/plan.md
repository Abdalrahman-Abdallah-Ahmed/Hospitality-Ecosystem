# Implementation Plan: Reservation Import v2 and Reconciliation

**Branch**: `013-reservation-import-v2` | **Date**: 2026-10-23 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/013-reservation-import-v2/spec.md`. Phase 13
of the master plan (D16): SPEC-100 Reservation Import v2 and SPEC-101 Import
Reconciliation. It closes open item F11 (queued imports).

**Depends on**:
- SPEC-011 guest lists and ages (`014`, merged);
- SPEC-012 lifecycle and `no_show` (`015`, on its branch; **must merge first**);
- `RoomAssignmentRules` and the SPEC-043 `BookingService` (on `main`).

The plan was blocked on 2026-10-12 until SPEC-011 and SPEC-012 existed. Its findings are
folded into [research.md](research.md).

## Summary

- **Plan, then write (R1)**: an import has two phases.
  - *Prepare* runs parse → normalize → validate → map → resolve over the whole file. It
    only reads the database, and it stores one *draft* per surviving reservation.
  - *Write* runs the drafts in file order, one transaction each, in chunks of 100.

  A **dry run is the prepare phase alone**, so its report matches a later real run row
  for row.
- **Queued (R3)**: the upload returns `202` with an import id (breaking, F11).
  `PrepareReservationImportJob` and the self-chaining `WriteReservationImportJob` run on
  the existing `database` queue, under `TenantContext::runForHotel()`. They are serialized
  per hotel with `WithoutOverlapping` plus an "older import first" check, and each job
  times out under the queue's `retry_after`.
- **Normalization (R4, R5)**:
  - a header synonym table and a status label map, both in `config/reservation_import.php`;
  - a date parser that honours the import's `day_first` / `month_first` order;
  - a phone normalizer using `libphonenumber-for-php-lite` with the hotel's country code.
    Only the import uses it; `PhoneNumber::digits()` is unchanged.
- **Mapping (R6)**:
  - rows are grouped by reference into one reservation, with a line per room unit;
  - guests are linked across rows, with one primary;
  - the party defaults to the number of named guests;
  - statuses are checked against the dates (FR-017b);
  - activity bookings are matched by name.

  Conflicts inside the file (guest identity, room clashes, content duplicates) are settled
  over the whole file in file order.
- **Resolve (R7)**: batched lookups against the hotel's data:
  - duplicate references (with a "data differs" note);
  - guests matched separately by phone, email and old-system id, where any disagreement
    makes the reservation unresolved;
  - room clashes, using the `RoomAssignmentRules` predicate;
  - rooms and types to create.

  Old-system guest ids go in `guests.external_id` with a new `external_source = import`.
- **Write (R8)**: through the existing `ReservationCreator::create(..., fromImport: true)`,
  so SPEC-011/012 rules, stays and occupancy are reused. Bookings go through
  `BookingService::create()` with a new `fromImport` option. Each draft re-checks its room
  assignments before writing. No notifications, ledger rows, tasks or memory extraction.
- **Report (R2, R9)**:
  - one `reservation_import_rows` record per data row, holding outcome, fields, reason and
    warnings;
  - totals and lists on `reservation_imports`;
  - JSON endpoints for history, status and rows, plus an `.xlsx` download.

  Drafts and the uploaded file are deleted when the run ends. Row results are kept.
- **Access and audit**:
  - the existing `reservations.import` permission, through a new `ReservationImportPolicy`;
  - super admins name the hotel (`resolveHotel()`);
  - one `reservations_imported` event per run, with the uploader as actor
    (`EventLogger::actingAs()`).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.

**Primary Dependencies**: maatwebsite/excel ^3.1 (unchanged), Eloquent, Sanctum, Pest 4.
**New**: `giggsey/libphonenumber-for-php-lite` (R5).

**Storage**: PostgreSQL. Three new tables (`reservation_imports`,
`reservation_import_rows`, `reservation_import_drafts`), plus `guests.external_source`
with a partial unique index. Uploaded files go on the private local disk under
`reservation-imports/{hotel_id}/{import_id}.{ext}` until prepare ends.

**Testing**: Pest feature tests on real Postgres, including:
- normalization datasets;
- mapping, idempotency, guest-matching and dry-run files;
- the queued contract through `Queue::fake()`, and end-to-end runs on the sync connection;
- tenant-isolation and permission dataset rows.

**Target Platform**: Linux server: JSON API, queue worker (`database` connection),
scheduler.

**Project Type**: Multi-tenant Laravel web service (backend only; frontend in
`ecosystem-frontend`).

**Performance Goals**:
- The upload responds within 2 s whatever the file size up to 5 MB (SC-003): it stores the
  file and queues a job only.
- A 5,000-row file completes within 10 minutes: one prepare job plus about 50 write
  chunks of 100.
- Each job stays under 300 s (timeout) and below `retry_after` = 360 s.

**Constraints**:
- Tenant scope on every lookup, and jobs establish it explicitly.
- One import at a time per hotel, in upload order.
- One transaction per reservation (FR-024).
- No ledger writes, notifications, cleaning tasks or memory extraction.
- No raw exception text in API reasons.
- Guest PII is not kept after the run.

**Scale/Scope**:
- 4 migrations, 4 enums, 3 models.
- New code:
  - about 8 support classes in `app/Support/Import/Reservations` (reader, header
    normalizer, value normalizers, validator, mapper, resolver, writer, report exporter);
  - 2 jobs;
  - 1 policy, 1 controller, 2 requests and 2 resources.
- Touched: `ReservationController::import`, `BookingService::create`, `EventLogger`,
  `Guest`.
- `App\Imports\ReservationsImport` is removed; its logic moves into the pipeline.
- 5 endpoints (1 changed, 4 new).

## Constitution Check

*Gate before Phase 0 and re-checked after Phase 1. Constitution v2.1.0.*

| Principle | How this plan complies | Status |
| --- | --- | --- |
| I. PMS is the system of record | The import writes structured reservations, lines, guests, stays and bookings through the domain write paths; nothing is stored as free text to be read later | ✅ |
| II. Preserve and evolve | Keeps the upload route, file types, size limit, column names (`reservation_id` and `guests` become synonyms), `fromImport` semantics, room and type auto-creation, and `reservations.import`. Reuses `ReservationCreator`, `ReservationGuestSync`, `RoomAssignmentRules`, `BookingService`, `RoomType::resolveFor()` and `TenantContext`. The one breaking change (sync → queued) is the one the spec requires (FR-005) and is documented | ✅ |
| III. Tenant isolation | Every resolver query names `hotel_id`. Jobs run in `runForHotel()`. Models use `BelongsToHotel`. Route-model binding is scoped. Isolation tests cover endpoints, matching, references and rooms | ✅ |
| IV. Permission-based authorization | `ReservationImportPolicy` and `ReservationPolicy::import` check `reservations.import` through `allows()`. No new case. Five endpoints are added to the permission dataset and the staff-roles reference | ✅ |
| V. AI acts through tools | No AI path changes. The import is staff-only | ✅ |
| VI. Auditability and observability | One `reservations_imported` event per run with the actor, hotel, file and totals. Per-row results are kept as the reconciliation record. Failures are logged with the exception, and staff see a readable reason | ✅ |
| VII. Integrity and idempotency | The reference is the idempotency key, with a content check when there is none. One transaction per reservation. Serialized per hotel. A crash followed by a re-run equals one clean run (SC-007). Room assignments are re-checked at write time under the room-type lock that `ReservationCreator::create()` already takes (there is no database exclusion constraint yet; SPEC-021 only plans one) | ✅ |
| VIII. Tested at the domain boundary | Normalizer datasets plus feature tests for each user story, isolation and permissions | ✅ |
| IX. Spec-driven | Spec → 7 clarifications → SPEC-011/012 alignment (2026-10-23) → this plan | ✅ |
| Import and Migration section | Normalizes legacy data, validates, keeps hotel scope, avoids duplicate guests and reservations, is idempotent, reports errors and reconciles | ✅ |

**Post-design re-check**: passes. Two items in research go beyond the spec text, both in
its spirit:
- `checked_in` with a departure already passed is invalid (R5, from FR-008/FR-017a);
- the new `external_source` column on `guests` (R7).

Neither needs a justification entry.

## Project Structure

### Documentation (this feature)

```text
specs/013-reservation-import-v2/
├── plan.md                         # This file
├── research.md                     # R1–R10
├── data-model.md                   # 3 tables, guests column, enums, validation rules
├── quickstart.md                   # validation scenarios
├── contracts/
│   └── reservation-import-api.md   # 5 endpoints, resources, file columns, breaking change
├── checklists/requirements.md
└── tasks.md                        # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── ImportStatus.php                    # NEW
│   ├── ImportStage.php                     # NEW
│   ├── ImportRowOutcome.php                # NEW
│   └── ImportDateOrder.php                 # NEW
├── Models/
│   ├── ReservationImport.php               # NEW (BelongsToHotel, Filterable)
│   ├── ReservationImportRow.php            # NEW
│   ├── ReservationImportDraft.php          # NEW
│   └── Guest.php                           # + external_source fillable
├── Support/Import/
│   ├── PhoneNormalizer.php                 # NEW (libphonenumber lite, hotel region)
│   ├── DateParser.php                      # NEW (formats + date order + Excel serials)
│   └── Reservations/
│       ├── ImportFailed.php                # NEW: whole-import failure with a staff-readable reason
│       ├── ImportFileReader.php            # NEW: parse + header normalization/synonyms
│       ├── NormalizedRow.php               # NEW: value object (values, errors, warnings)
│       ├── MappedFile.php / ResolvedFile.php # NEW: mapper and resolver results
│       ├── RowNormalizer.php               # NEW: values, status map, party
│       ├── RowValidator.php                # NEW: FR-008–FR-009, FR-017b, FR-019a
│       ├── ReservationMapper.php           # NEW: grouping, units, guests, bookings, in-file identity/clash/dup
│       ├── ReservationImportResolver.php   # NEW: read-only checks against hotel data
│       ├── ReservationImportWriter.php     # NEW: one draft → one transaction
│       ├── ImportOutcomeRecorder.php       # NEW: row results + totals
│       ├── ReservationImportCompleter.php  # NEW: complete/fail, audit event, cleanup
│       └── ImportReportExport.php          # NEW: xlsx download (FromQuery)
├── Jobs/
│   ├── PrepareReservationImportJob.php     # NEW
│   └── WriteReservationImportJob.php       # NEW (self-chaining chunks)
├── Http/
│   ├── Controllers/ReservationController.php        # import() → queue + 202
│   ├── Controllers/ReservationImportController.php  # NEW: index, show, rows, report
│   ├── Requests/ImportReservationsRequest.php       # + date_order, dry_run, hotel_id
│   ├── Requests/ReservationImportRowsRequest.php    # NEW: outcome, has_warnings
│   ├── Resources/ReservationImportResource.php      # NEW
│   └── Resources/ReservationImportRowResource.php   # NEW
├── Policies/ReservationImportPolicy.php    # NEW: viewAny, view
├── Services/BookingService.php             # create(): fromImport option
├── Support/Audit/EventLogger.php           # + actingAs(User, callable)
└── Imports/ReservationsImport.php          # REMOVED (logic moves to Support/Import)

config/reservation_import.php               # NEW: header synonyms, status labels, chunk size

database/migrations/
├── 2026_10_23_000001_create_reservation_imports_table.php
├── 2026_10_23_000002_create_reservation_import_rows_table.php
├── 2026_10_23_000003_create_reservation_import_drafts_table.php
└── 2026_10_23_000004_add_external_source_to_guests_table.php

routes/api.php                              # 4 GET routes beside /reservation/import

tests/Feature/
├── ReservationImportTest.php               # REWRITTEN: queued contract, status, report (US1)
├── ReservationImportNormalizationTest.php  # NEW: headers, phones, dates, statuses (US2)
├── ReservationImportMappingTest.php        # NEW: grouping, units, guests, party, statuses, bookings, clashes (US2)
├── ReservationImportIdempotencyTest.php    # NEW: re-import, ordering, crash + re-run (US3)
├── ReservationImportGuestMatchingTest.php  # NEW (US4)
├── ReservationImportDryRunTest.php         # NEW (US5)
├── PermissionAuthorizationTest.php         # + 5 import endpoints
└── TenantIsolationTest.php                 # + import reads, matching, references, rooms
```

**Structure Decision**: the existing Laravel monolith layout. The pipeline sits in
`app/Support/Import/Reservations`, next to the other framework-adjacent helpers. It has
one caller (the jobs), so it is not a `Service`. The generic phone and date normalizers
sit one level up for reuse by a later transactions import.

### Documentation to update

- `docs/reservations-api-documentation.md` §6:
  - rewrite for the queued contract;
  - the stages and the supported columns with synonyms;
  - date formats and order, the status map and the status-vs-date rules;
  - matching rules, outcomes and warnings;
  - the four new endpoints;
  - remove the "super admins cannot import" note.
- `docs/staff-roles-api-documentation.md`: list the new import endpoints under
  `reservations.import`.
- `docs/latest-changes-2026-10-23.md`: the breaking change summary from the contract.
- `docs/frontend-changes-2026-10-23.md` and `specs/013-reservation-import-v2/frontend-changes.md`:
  upload with date order and dry run, progress polling, history list, report view and
  download (D13).

## Complexity Tracking

No constitution violations; nothing to justify.
