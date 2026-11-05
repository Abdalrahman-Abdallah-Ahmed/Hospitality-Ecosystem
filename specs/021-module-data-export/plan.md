# Implementation Plan: Module Data Export

**Branch**: `021-module-data-export` | **Date**: 2026-11-03 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/021-module-data-export/spec.md`

## Summary

Staff download the Reservations, Activities, Bookings or Guests list as Excel, CSV or SQL,
filtered exactly as the list is, in one request. Each module's controller gains an `export()`
action that reuses the same query builder as its `index()` (extracted into a private
`listQuery()`), counts against a 10,000-row cap, then streams the file: spreadsheets through
the already-installed `maatwebsite/excel` (one export class per module with a fixed, readable
column set and formula neutralisation), SQL through a small streaming writer that emits one
PDO-quoted `INSERT` per record with ids kept. Four new `<module>.export` permissions gate it,
a named `exports` rate limiter allows 10 per user per minute, and every export (and every
over-cap refusal) is written to the audit log via `EventLogger`. No schema changes.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13

**Primary Dependencies**: `maatwebsite/excel` ^3.1 (already installed; PhpSpreadsheet underneath), Sanctum, Pest 4. No new packages.

**Storage**: PostgreSQL (existing `reservations`, `activities`, `bookings`, `guests`; audit rows in `event_logs`). No migrations.

**Testing**: Pest feature tests against the real Postgres test database (`--parallel`)

**Target Platform**: Linux server (production host 2 GB RAM), JSON API consumed by the Next.js frontend

**Project Type**: web-service (JSON API)

**Performance Goals**: a 10,000-row export completes in < 30 s in every format (SC-001)

**Constraints**: direct download (no queue); flat memory for CSV/SQL; XLSX ≤ ~150 MB peak at the cap; 10 exports/user/minute; refuse above 10,000 rows

**Scale/Scope**: 4 modules × 3 formats, 4 endpoints, 4 permissions, ~10 new classes

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle / rule | Status | How |
| --- | --- | --- |
| I. PMS is the system of record | ✅ | Read-only over PMS tables; nothing AI-generated |
| II. Preserve and evolve | ✅ | Reuses controllers, index requests, `GenericQuery`, `maatwebsite/excel`, `EventLogger`, `resolveHotel()`. `index()` behaviour unchanged (refactor only moves its builder into `listQuery()`) |
| III. Tenant isolation (NON-NEGOTIABLE) | ✅ | `BelongsToHotel` scope + explicit `where hotel_id = resolved hotel`; super admin must name a hotel; isolation tests per module; `CrossTenantRouteTest` drives the routes |
| IV. Permission-based authorization (NON-NEGOTIABLE) | ✅ | Four new `Permission` cases, policy `export()` abilities via `allows()`; not in `employeeDefaults()`; added to `PermissionAuthorizationTest` dataset and staff-roles docs |
| V. AI acts through tools | ✅ N/A | No AI path; no AI tool added |
| VI. Auditability | ✅ | `hotel.<module>_exported` and `hotel.<module>_export_refused` entries; no record content in them |
| VII. Data integrity | ✅ | Read-only; SQL wrapped in a transaction so a failed load leaves nothing half-done |
| VIII. Tested at the domain boundary | ✅ | Feature tests per endpoint × format, refusals, cap, throttle, audit, isolation |
| IX. Spec-driven | ✅ | Spec clarified (4 answers) before this plan |
| API Design: file endpoints | ✅ | Binary file on success; every error through `apiResponse()` (constitution 2.2.1) |
| Security & privacy | ✅ | Separate export permission, rate limit, cap, formula-injection neutralisation, audit |
| Jobs / queue lanes | ✅ N/A | No jobs (direct download, by decision) |
| Migrations | ✅ N/A | None |
| Documentation | ✅ | `reservations-`, `activity-`, `guest-api-documentation.md`, `booking-entity-documentation.md` + `staff-roles-api-documentation.md` |

No violations. **Post-design re-check (after Phase 1)**: unchanged — the design adds no
tables, jobs, AI tools or cross-tenant reads; all ✅.

## Project Structure

### Documentation (this feature)

```text
specs/021-module-data-export/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── export-endpoints.md
├── checklists/
│   └── requirements.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── ExportFormat.php                     # new: xlsx, csv, sql
│   └── Permission.php                       # + 4 *_EXPORT cases
├── Http/
│   ├── Controllers/
│   │   ├── ReservationController.php        # + export(), index builder → listQuery()
│   │   ├── ActivityController.php           # + export(), listQuery()
│   │   ├── BookingController.php            # + export(), listQuery()
│   │   └── GuestController.php              # + export(), listQuery()
│   └── Requests/
│       ├── Concerns/ValidatesExportFormat.php   # new: format + hotel_id rules
│       ├── Generic/GenericExportRequest.php     # new (activities, guests)
│       ├── ReservationExportRequest.php         # new
│       └── BookingExportRequest.php             # new
├── Policies/
│   └── {Reservation,Activity,Booking,Guest}Policy.php   # + export()
├── Providers/AppServiceProvider.php         # + RateLimiter 'exports'
├── Services/Exports/DataExporter.php        # new: cap check, audit, dispatch by format, file name
└── Support/
    ├── RequestRules/GenericQuery.php        # split: query() + apply()
    └── Export/
        ├── ModuleExport.php                 # new: abstract spreadsheet export (chunked, safe text, CSV BOM)
        ├── SpreadsheetSafe.php              # new: formula neutralisation
        ├── SqlDump.php                      # new: streamed INSERT writer
        ├── ReservationsExport.php           # new
        ├── ActivitiesExport.php             # new
        ├── BookingsExport.php               # new
        └── GuestsExport.php                 # new
config/exports.php                           # new: max_rows (EXPORT_MAX_ROWS, 10000)
routes/api.php                               # + 4 GET …/export routes, before resource routes, throttle:exports

tests/Feature/
├── ExportControllerTest.php                 # new: per module × format, cap, throttle, audit, isolation
├── SqlDumpTest.php                          # new: escaping, NULL/bool/number/json, round-trip load
├── PermissionAuthorizationTest.php          # + 4 dataset rows
└── (CrossTenantRouteTest / EntryPointInventoryTest pick up the routes; inventory entry only if needed)

docs/
├── reservations-api-documentation.md        # + export
├── activity-api-documentation.md            # + export
├── booking-entity-documentation.md          # + export
├── guest-api-documentation.md               # + export
└── staff-roles-api-documentation.md         # + 4 permissions
```

**Structure Decision**: single Laravel application, existing layers. Export actions stay on
the module controllers because `GenericIndexRequest` resolves its model from the controller
name (research R1). Shared logic with four callers lives in `app/Services/Exports`;
spreadsheet/SQL writers are framework-adjacent helpers in `app/Support/Export`, next to the
existing `app/Support/Import`.

## Key design points

1. **One query per module, two consumers** (R1). `listQuery()` returns the unpaginated,
   filtered, sorted builder; `index()` paginates it, `export()` hands it to `DataExporter`.
   `GenericQuery::query()` is the non-paginating half of today's `apply()`.
2. **Flow in `export()`**: `authorize('export', Model::class)` → `resolveHotel()` (403 if none)
   → `listQuery()->where(hotel_id)` → `DataExporter::download($module, $query, $format, $hotel, $request)`.
3. **`DataExporter`**: count → over cap: audit refusal + 422; else build file name, audit success
   (with row count), return `Excel::download(...)` or `SqlDump::stream(...)`.
4. **Ordering**: list sort + `id` tie-breaker so chunked reads neither skip nor repeat (R3).
5. **Rate limit**: named limiter `exports`, 10/min keyed by user, applied only to export routes (R8).

## Complexity Tracking

No constitution violations; nothing to justify.
