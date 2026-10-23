# Data Model: Reservation Import v2 and Reconciliation

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

Three new tenant-owned tables plus one column on `guests`. Every new model uses UUID keys,
`BelongsToHotel` and explicit `$fillable` / `$casts`. Imports are never edited by clients,
so there is no `SoftDeletes` on the run tables; they are removed only with their hotel.

## `reservation_imports` (model `ReservationImport`, `Filterable`)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK → hotels, cascade | `BelongsToHotel` |
| `uploaded_by` | uuid FK → users, null on delete | the actor for audit (FR-032); stays readable if the user goes |
| `file_name` | string(255) | the original client name, for display only |
| `file_path` | string, nullable | private disk path; set to null once prepare ends or the import fails |
| `dry_run` | boolean | FR-030 |
| `date_order` | string(16) | `day_first` / `month_first` (FR-008a). Enum `ImportDateOrder` |
| `status` | string(16) | `queued` / `running` / `completed` / `failed`. Enum `ImportStatus` (FR-003) |
| `stage` | string(16), nullable | `parse` / `normalize` / `validate` / `map` / `import` / `report`. Enum `ImportStage`, the last stage reached |
| `total_rows` | unsigned int, default 0 | data rows in the file (set after parse) |
| `processed_rows` | unsigned int, default 0 | progress (FR-003) |
| `imported` / `skipped` / `duplicate` / `invalid` / `unresolved` | unsigned int, default 0 | totals (FR-025) |
| `created_rooms` | jsonb, default `[]` | room numbers created, or "would be created" on a dry run (FR-016, FR-027) |
| `created_room_types` | jsonb, default `[]` | likewise |
| `unrecognized_columns` | jsonb, default `[]` | FR-027 |
| `failure_reason` | text, nullable | a staff-readable reason when `failed` |
| `started_at` / `finished_at` | timestampTz, nullable | |
| `created_at` / `updated_at` | timestampTz | `created_at` is the upload time and sets the queue order |

**Constraints**:
- `CHECK` on `status`, `stage` and `date_order` values.
- `CHECK (imported + skipped + duplicate + invalid + unresolved <= total_rows)`.
- When `completed`, the five totals equal `total_rows` (FR-025). This is checked in code
  and in tests, not by a constraint, because a failed run stops short.

**Index**: `(hotel_id, status, created_at)` for the per-hotel queue check (R3) and the
history list.

### Status transitions

```text
queued ──prepare starts──▶ running ──last chunk / dry-run prepare ends──▶ completed
   │                          │
   └──── job failed() ────────┴──────────────────────────────────────▶ failed
```

- `completed` and `failed` are final. There is no retry endpoint: re-uploading the file is
  the retry (idempotent, US3).
- `failed` keeps the totals counted up to the failure, so the report shows how far it got.

## `reservation_import_rows` (model `ReservationImportRow`)

One row per data row in the file (FR-025, FR-026).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK | `BelongsToHotel` |
| `reservation_import_id` | uuid FK → reservation_imports, cascade | |
| `row_number` | unsigned int | as the spreadsheet shows it (header is row 1) |
| `external_reference` | string, nullable | the file's reservation code, as normalized |
| `outcome` | string(16), nullable | `imported` / `skipped` / `duplicate` / `invalid` / `unresolved`. Enum `ImportRowOutcome`. Null while waiting for the write phase |
| `stage` | string(16) | the stage it reached or stopped at |
| `fields` | jsonb, default `[]` | the normalized field names involved |
| `reason` | text, nullable | human-readable (SC-004) |
| `warnings` | jsonb, default `[]` | `[{code, message}]`. Codes: `no_phone`, `no_room_assigned`, `room_clash`, `data_differs`, `restored`, `phone_country_unknown`, `phone_unrecognized`, `booking_unresolved`, `status_corrected` |
| `reservation_id` | uuid FK → reservations, null on delete, nullable | the reservation produced or matched |
| `draft_id` | uuid, nullable | the draft this row belongs to while the run is in progress |

**Index**: `(reservation_import_id, row_number)` unique; `(reservation_import_id, outcome)`.

**Outcome rules**:
- Every row of a reservation shares its outcome. Warnings are per row.
- `skipped` is a blank data row: a row with no values at all, which spreadsheets often
  leave at the end.
- An unresolved booking is a warning on its row (`booking_unresolved`) and is listed in
  the report (FR-027). The row's outcome is still `imported`.

## `reservation_import_drafts` (model `ReservationImportDraft`)

Mapped and resolved reservations waiting to be written. All of an import's drafts are
deleted when it completes or fails.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK | `BelongsToHotel` |
| `reservation_import_id` | uuid FK, cascade | |
| `position` | unsigned int | file order of the reservation's first row; the write order |
| `payload` | jsonb | normalized reservation attributes, units (type name, room number, assigned or not), guests (resolved `guest_id` or new-guest fields, `is_primary`), bookings, restore flag |

**Index**: `(reservation_import_id, position)` unique.

## `guests`: one new column

| Column | Type | Notes |
| --- | --- | --- |
| `external_source` | string(16), nullable | `import` for ids from a legacy export (R7). Fillable |

A partial unique index on `(hotel_id, external_id) WHERE external_source = 'import' AND
deleted_at IS NULL` makes old-system guest ids unique per hotel. The existing
`(hotel_id, channel, external_id)` index is unchanged.

## Enums (new, in `app/Enums`)

| Enum | Cases |
| --- | --- |
| `ImportStatus` | `queued`, `running`, `completed`, `failed` |
| `ImportStage` | `parse`, `normalize`, `validate`, `map`, `import`, `report` |
| `ImportRowOutcome` | `imported`, `skipped`, `duplicate`, `invalid`, `unresolved` |
| `ImportDateOrder` | `day_first`, `month_first` |

## Existing entities written by the import

| Entity | How |
| --- | --- |
| `reservations`, `reservation_rooms`, `reservation_guests`, `stays` | `ReservationCreator::create(..., fromImport: true)` only |
| `guests` | created or reused (empty fields filled, never overwritten); `external_id` / `external_source` set |
| `rooms`, `room_types` | created when named and missing (`RoomType::resolveFor()`, room lookup as today) |
| `bookings` | `BookingService::create()` with `origin = import` and `fromImport` (R8) |
| `events` (audit) | one `reservations_imported` per import (FR-032) |

Never written: `transactions` (FR-033), `tasks`, notifications, guest memories.

## Validation rules (from the spec)

| Rule | Source | Outcome |
| --- | --- | --- |
| A required column is missing | Edge cases | import `failed`, columns named |
| Unparseable date, unknown status label, non-numeric party | FR-008 | `invalid` |
| Departure not after arrival | FR-009 | `invalid` |
| Ages ≠ children count (when ages given), an age outside 0–17 | FR-009 | `invalid` |
| Guests > adults + children | FR-013a | `invalid` |
| A guest with no phone, no email and no external id | FR-019a | `invalid` |
| A guest with no phone | FR-019a | warning `no_phone` |
| Group rows disagree on reservation fields; more than one primary | FR-011, FR-013 | `invalid` |
| More units than `ReservationRoomSync::MAX_UNITS` | R6 | `invalid` |
| `checked_out` before departure, `no_show` before arrival, `checked_in` before arrival | FR-017b, R5 | `invalid` |
| `checked_in` with departure passed | FR-017b | imported as `checked_out`, warning `status_corrected` |
| Group rows disagree on `room_count` for a type | FR-012 | `invalid` |
| Identifiers point at different guests (in file or at the hotel) | FR-019 | `unresolved` |
| Reference exists (live) at the hotel, or a content duplicate | FR-021, FR-022 | `duplicate` (with `data_differs` when it applies) |
| A room clash in the file or with existing data | FR-012a | line unassigned, warning `room_clash` |
| In-house line with no room number | FR-017a | warning `no_room_assigned` |
| Unexpected error while writing a draft | Edge cases | `invalid` with the error summary |
