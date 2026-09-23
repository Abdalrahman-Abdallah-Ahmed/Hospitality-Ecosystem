# Research: Reservation Rooms

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-09-23

The spec has no open clarifications. This file records the design decisions the plan
needed and why, from a read of the current code.

## Current state (what the code does today)

| Area | Today | File |
| --- | --- | --- |
| Reservation → room | `reservations.room_id` nullable FK, `nullOnDelete` | `database/migrations/2026_07_18_000004_create_reservations_table.php` |
| Creation path | Static `ReservationCreator::create()` shared by controller, `CreateReservationTool`, `ReservationsImport`; restores a soft-deleted reservation with the same code | `app/Support/Reservations/ReservationCreator.php` |
| Validation | `GenericStoreRequest`/`GenericUpdateRequest` derive rules from `$fillable`; cross-hotel FKs checked with `invalidRelation()` | `app/Http/Controllers/ReservationController.php` |
| Stay | One stay per reservation (unique `stays.reservation_id`); `StayService::syncFromReservation()` copies `room_id` onto the stay | `app/Services/StayService.php` |
| Occupancy | `syncRoomOccupancy()` re-derives room status from IN_HOUSE **stays** in the room, for the reservation's room and its previous room | `ReservationCreator::syncRoomStatus()` |
| Overnight dirty | Rooms with an IN_HOUSE stay → `dirty` | `app/Jobs/MakeRoomDirtyOvernightJob.php` |
| Historic occupancy | Counts stays covering a date | `Stay::occupiedRoomsOn()` |
| Readers of `reservation->room` | Dashboard arrivals/departures, `GetReservationsTool`, `GetOwnReservationTool`, `CreateGuestServiceRequestTool`, `WhatsAppReservationCreatedNotification`, `ReservationResource` | see plan §Touched files |
| Audit | `RecordsEvents` trait on models, `EventLogger::record()` for semantic events, `EventLogger::asAiAgent()` for AI actor | `app/Support/Audit/EventLogger.php` |
| AI create | `CreateReservationTool` is used by `AdminAdvisorAgent`; it is **not** wrapped in `asAiAgent()`, so its writes are logged as the user | `app/Ai/Tools/CreateReservationTool.php` |
| Room-type deletion | Blocked only when rooms reference the type | `RoomTypeController::destroy()` |

## Decisions

### R1. One row per room unit in a new `reservation_rooms` table

- **Decision**: New tenant-owned model `ReservationRoom` (`BelongsToHotel`, UUID,
  `SoftDeletes`, `RecordsEvents`) with `reservation_id`, `room_type_id`, nullable
  `room_id`, `status`. Quantity in requests is expanded into rows.
- **Rationale**: Decision D2 in the master plan; later specs (assignment, stay per room,
  check-in per room) all address one unit at a time.
- **Alternatives**: a `quantity` column per type line — rejected by D2, and it would need
  splitting again when rooms are assigned.

### R2. Line status enum: `reserved`, `cancelled`

- **Decision**: New `App\Enums\ReservationRoomStatus` with two cases. Later specs add cases
  (e.g. `in_house`, `departed`, `no_show`).
- **Rationale**: FR-003. Removing a line is a cancel, not a delete, so history and audit
  survive (FR-010, FR-012).
- **Alternatives**: hard delete on removal — loses history; reuse `ReservationStatus` —
  couples line and reservation lifecycles that SPEC-012/024 will separate.

### R3. Extend `ReservationCreator` instead of a new service

- **Decision**: Keep `ReservationCreator` as the single domain entry point, add
  `create(attributes, lines, capacityOverride)` and a new `update(...)`, both in one
  `DB::transaction()`. Signature: `create(array $attributes, array $rooms, bool
  $capacityOverride = false, bool $fromImport = false)`; only the import passes
  `$fromImport` (skips the capacity check, accepts deactivated room types). Line expansion, diffing and validation go in a new collaborator
  `App\Support\Reservations\ReservationRoomSync`.
- **Rationale**: Constitution II (reuse) and plan §2.4 ("MODIFY, do not duplicate"). The
  controller's `update()` currently writes the model directly; moving it behind
  `ReservationCreator::update()` gives the API and future AI update tools one path
  (FR-019) and one transaction (FR-013).
- **Alternatives**: a new `ReservationService` in `app/Services` — would leave two creation
  paths during the transition.

### R4. Request shape: `rooms[]` with quantity on create, desired-state on update

- **Decision**:
  - Create: `rooms: [{ room_type_id, quantity?=1, room_id? }]`, `room_id` only when
    quantity is 1.
  - Update: `rooms` is optional. When present it is the **desired set of non-cancelled
    lines**: items with `id` keep that line (and may change its `room_id`); items without
    `id` are new lines (quantity allowed); existing non-cancelled lines not listed are
    cancelled. When absent, lines are untouched.
  - `capacity_override: boolean` on both.
- **Rationale**: Keeps line identity (US3), maps directly onto a form that edits the whole
  list, and needs no extra endpoints in this spec (dedicated line endpoints come with
  SPEC-021 assignment).
- **Alternatives**: separate `POST/DELETE /reservation/{id}/rooms` endpoints — more
  surface now, and SPEC-021 will define per-line endpoints anyway; a pure list of units
  with no quantity — clumsy for "10 × Standard".

### R5. Validation lives in `ReservationRoomSync`, requests stay generic

- **Decision**: `StoreReservationRequest extends GenericStoreRequest` and
  `UpdateReservationRequest extends GenericUpdateRequest` add only the shape rules for
  `rooms.*` and `capacity_override`. Hotel ownership, active type, type match, duplicate
  room, 50-unit cap, capacity and status rules are checked in `ReservationRoomSync` and
  thrown as `ValidationException` keyed by `rooms.{i}.field`.
- **Rationale**: The AI tool and import don't go through FormRequests, so domain rules must
  live in the shared domain operation (FR-019, SC-003). Ownership checks name the hotel
  explicitly so they work in queued/no-tenant contexts.
- **Alternatives**: rules in the FormRequest — the AI and import paths would skip them.

### R6. Status rules are judged on the status **before** the update

- **Decision**: `pending`/`confirmed` → full line edits; `checked_in` → only `room_id` on
  existing lines, same type (room move); `checked_out`/`cancelled` → any `rooms` key
  rejected. A request that changes status to `cancelled` cancels every line after the
  status is saved (FR-012).
- **Rationale**: Deterministic, and lets a confirmed reservation be edited and checked in
  in one request as today.

### R7. Capacity check and override

- **Decision**: Sum `max_occupancy` and `adult_capacity` over non-cancelled lines' room
  types; reject when `adults + children > max` or `adults > adult_max`. Staff bypass with
  `capacity_override: true`, which records a `reservation.capacity_overridden` audit event
  with the numbers. The override is ignored (check still enforced) when the actor kind is
  `ai_agent`; `CreateReservationTool` never exposes it. `ReservationsImport` and the
  backfill skip the check entirely.
- **Rationale**: Clarification Q2 (option B).
- **Alternatives**: separate permission for override — no new permission is in scope
  (FR-021); can be added later if hotels want it restricted.

### R8. DB-level guard for the same room twice in one reservation

- **Decision**: Partial unique index on `reservation_rooms (reservation_id, room_id)`
  `WHERE room_id IS NOT NULL AND status <> 'cancelled' AND deleted_at IS NULL`, plus the
  service check for a clear 422.
- **Rationale**: Constitution VII ("constraints SHOULD enforce invariants"). The
  cross-reservation overlap guarantee is SPEC-021's exclusion constraint, not this spec.

### R9. Occupancy and overnight-dirty read from lines

- **Decision**: One query helper, `ReservationRoom::inHouseRoomIds()` — room ids on
  non-cancelled lines whose reservation's stay is `IN_HOUSE`. `syncRoomStatus()` and
  `MakeRoomDirtyOvernightJob` use it. `syncRoomOccupancy()` takes the set of room ids the
  operation touched (old and new) instead of reading `wasChanged('room_id')`.
  `Stay::occupiedRoomsOn()` counts non-cancelled lines (with or without a room, matching
  today's count of stays for unassigned bookings) of reservations whose stay qualifies,
  instead of counting stays.
- **Rationale**: FR-015. For single-room reservations the result is identical, so existing
  occupancy tests keep passing; multi-room reservations now count every room.
- **Alternatives**: keep stay-based queries — a 3-room reservation would mark only one
  room occupied.

### R10. Stay compatibility until SPEC-023

- **Decision**: The single stay's `room_id` is the reservation's **primary line room**:
  the first non-cancelled line (by `created_at`, then `id`) that has a room, else null.
  Exposed as `Reservation::primaryRoomId()`; used by `StayService::syncFromReservation()`
  and `CreateGuestServiceRequestTool`.
- **Rationale**: FR-016 keeps one stay per reservation; transactions attribution and the
  VIP dashboard keep reading `stay.room`.

### R11. Backfill as a data migration, idempotent

- **Decision**: Three migrations, following the `2026_09_23_00000x` room-type precedent
  (data moved with `DB::table`, not models, so global scopes and events don't interfere):
  1. create `reservation_rooms`;
  2. backfill: for every reservation (including soft-deleted) with no line, insert one line
     — room from `reservations.room_id` (joined with trashed rooms, only when the room's
     `hotel_id` matches the reservation's), type from that room; reservations without a
     usable room get the hotel's inactive "Unspecified (migrated)" type,
     created on demand. Line status `cancelled` when the reservation is cancelled, else
     `reserved`. Reports counts per hotel with `Log::info` and console output;
  3. drop `reservations.room_id` (down() restores it from the primary line).
- **Rationale**: FR-024–FR-027. "Insert where no line exists" makes a re-run a no-op.
  Occupancy is untouched because room status columns are not written and the new
  derivation gives the same answer for one-room reservations.

### R12. Room-type deletion guard

- **Decision**: `RoomTypeController::destroy()` also rejects when a non-cancelled line on a
  non-cancelled reservation with `departure_date >= today` uses the type; 422 with
  `error: deletion_blocked_by_reservations` and the count.
- **Rationale**: Clarification Q4 (FR-028). Same response shape as the existing
  `deletion_blocked_by_rooms`.

### R13. AI tools

- **Decision**:
  - `CreateReservationTool`: new `rooms` array (`room_type` name, `quantity`, optional
    `room_number` when quantity is 1). Legacy `room_number` alone still works and becomes
    one line of that room's type. Room type names resolve case-insensitively among the
    hotel's **active** types only (no auto-create, unlike the import); an unknown name
    returns the list of available types. Wrapped in `EventLogger::asAiAgent()`.
  - `GetReservationsTool`, `GetOwnReservationTool`: return `rooms` (type name, room
    number or null, status) and a per-type summary instead of one `room_number`.
- **Rationale**: FR-019, FR-020, US5 scenario 1 (AI actor in the audit log).

### R14. Import

- **Decision**: A row with `room_number` → one line of that room's type and room (room
  still auto-created as today). A row with only `room_type` → one line of
  `RoomType::resolveFor()` with no room. Neither → one line of the hotel's default type via
  `resolveFor(null)`. No capacity check.
- **Rationale**: Preserves today's import behavior (D16 import v2 is SPEC-100).

### R15. List filters

- **Decision**: `GenericIndexRequest` returns 422 for any `filter` key that isn't a table
  column. Add `ReservationIndexRequest extends GenericIndexRequest` that also accepts the
  virtual filter keys `room_type_id` and `room_id` (filter only, **not** sort).
  `ReservationController::index()` removes them from `filter`, applies
  `whereHas('reservationRooms', …)` (any non-cancelled line), then calls
  `GenericQuery::apply()`.
- **Rationale**: FR-018. `filter[room_id]` keeps working for existing clients after the
  column is dropped, now matching any line.
- **Alternatives**: top-level `room_type_id` query params — a second filter convention.
