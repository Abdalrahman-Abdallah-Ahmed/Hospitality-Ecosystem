# Latest Changes: 2026-09-23

## Room Types (Phase 1 — Inventory Foundation)

### Summary

Added comprehensive room type inventory management system as the foundation for availability calculations and reservation workflows. Room types categorize guest accommodations (e.g., Standard, Deluxe, Suite) and are managed via the new `/api/room-types` endpoints.

### What's New

#### Database Schema

- **New table**: `room_types` with fields: id, hotel_id, name, description, max_occupancy, adult_capacity, child_capacity, bed_configuration, amenities, base_price, is_active, timestamps, soft delete marker
- **Modified table**: `rooms` now uses `room_type_id` (FK) instead of `room_type` enum; links physical rooms to room types

#### API Endpoints

Five new endpoints under `/api/room-types`:
- `POST /api/room-types` — Create room type
- `GET /api/room-types` — List with filtering, search, sort, pagination
- `GET /api/room-types/{id}` — Retrieve single room type
- `PUT /api/room-types/{id}` — Update fields (partial updates allowed)
- `DELETE /api/room-types/{id}` — Soft delete (blocked if active rooms reference it)

#### Permissions

Four new permissions available for staff roles:
- `room_types.view` — List and view room types
- `room_types.create` — Create new room types
- `room_types.update` — Update room type details (including deactivation)
- `room_types.delete` — Delete room types (soft delete only)

Not granted by default to employees without a staff role.

#### Features

- **Soft deletion**: Deleted room types marked with `deleted_at`; never returned in API lists
- **Deactivation**: Set `is_active = false` to hide from availability calculations without deleting
- **Tenant isolation**: Automatic scoping to user's hotel via `TenantContext` middleware
- **Audit logging**: All create/update/delete operations logged via `EventLogger`
- **Last-write-wins**: No optimistic locking; concurrent edits use last-write-wins
- **Capacity validation**: `adult_capacity + child_capacity ≤ max_occupancy` enforced at database and request levels

#### Integration Points

Room Type data model supports:
- **Availability service queries**: Filter by `is_active = true`, `deleted_at IS NULL`
- **Reservation workflows**: Reservation lines reference room types; multiple lines can specify same type
- **Room assignment**: Links physical rooms to type categories for inventory management

### Breaking Changes

#### For Existing Rooms

Rooms previously using the `room_type` enum (single, double, twin, triple, suite, deluxe, family) are automatically backfilled with corresponding `RoomType` records during migration. The enum column is dropped after backfill completes.

**Migration sequence**:
1. Create `room_types` table (with `CHECK` constraints on capacity and price)
2. `hotels.currency` already existed; that migration is a no-op
3. Create one room type per distinct enum value per hotel
4. Add `room_type_id` to rooms and backfill it from the enum value
5. Rooms that had no type go under a per-hotel **"Standard"** type (created if missing), then `room_type_id` becomes NOT NULL and the enum column is dropped

**No data loss**: All existing room-type assignments preserved via FK relationships. Auto-created types have placeholder capacity (2 adults) and price (0); review them.

#### Rooms API (`/api/room`)

- `room_type_id` is **required** on create and must belong to the same hotel (another hotel's type → `403`). The old `room_type` string is gone.
- Room responses carry `room_type_id` plus the embedded `room_type` object; `index` returns the hotel's room types as `body.room_types`.
- Rooms created implicitly are filed automatically: the reservation import and the AI admin "create room" tool use the named type (matched case-insensitively, created if new) or, with no name, the hotel's oldest active type, or a new "Standard" type.
- See `docs/room-api-documentation.md`.

### Documentation

- See `docs/room-types-api-documentation.md` for full API reference
- See `docs/staff-roles-api-documentation.md` for permission definitions (added `room_types.*`)
- See `specs/001-room-types/quickstart.md` for validation scenarios

### Testing

Complete test coverage for:
- CRUD operations (create, list, get, update, soft delete)
- Validation rules (capacity constraints, unique names per hotel)
- Authorization (permission checks per endpoint)
- Tenant isolation (cross-hotel access blocked)
- Soft deletion behavior (deleted records never in list responses)
- Availability integration (active/inactive filtering)

### Implementation Details

- Model: `App\Models\RoomType` with `BelongsToHotel`, `SoftDeletes`, `RecordsEvents` traits
- Controller: `App\Http\Controllers\RoomTypeController` (RESTful CRUD)
- Policy: `App\Policies\RoomTypePolicy` (authorization via `ChecksPermissions`)
- Requests: the shared `GenericStoreRequest` / `GenericUpdateRequest`; room-type rules (capacity, price, unique name) are checked in the controller
- Resource: `App\Http\Resources\RoomTypeResource` (serialization)
- Seeder: `database/seeders/RoomTypeSeeder.php` (sample data)

---

**Ready for**: Phase 2 (Reservations), Phase 3 (Availability), Phase 4 (Room Assignment)

---

## Reservation Rooms (Phase 2 — SPEC-010) — BREAKING

### Summary

A reservation no longer points at one physical room. It now books one or more **room units ("lines")**, each naming the room type booked and, optionally, the physical room. "2 × Deluxe + 1 × Suite" is one reservation with three lines, all sharing the reservation's dates. This is the base for availability, room assignment and per-room stays in the next specs.

### Breaking Changes — Reservations API (`/api/reservation`)

- **Removed**: the top-level `room_id` request field and the `room_id` / `room` response fields. Sending `room_id` now returns `422` "Use rooms[] instead."
- **Create** requires `rooms`: `[{ room_type_id, quantity?, room_id? }]`, 1–50 rooms in total. Room types must be active and belong to the hotel; `room_id` must be a room of that type and only goes on a line with quantity 1.
- **Responses** carry `rooms[]` (every line with `room_type`, `room`, `status` — `reserved` or `cancelled`) and `room_summary` (`[{ room_type_id, room_type_name, quantity }]` of live lines).
- **Update**: `rooms` is optional. When sent it is the **full desired list of live lines** — `{ id }` keeps a line (optionally changing/clearing its `room_id`), an item without `id` adds lines, a live line left out is cancelled. Omit `rooms` to keep the lines as they are. Allowed changes depend on status: `pending`/`confirmed` — anything; `checked_in` — room moves only (same type); `checked_out`/`cancelled` — none. Cancelling the reservation cancels every line.
- **Capacity**: the party must fit the booked rooms (`adults + children` ≤ Σ max occupancy, `adults` ≤ Σ adult capacity). Staff can save anyway with `capacity_override: true`; each override is audited as `reservation.capacity_overridden`. The AI can never override.
- **List filters**: `filter[room_type_id]` and `filter[room_id]` match any live line. They cannot be used for `sort`. `search` no longer covers `room_id`.

### Other Changes

- **Room types**: `DELETE /api/room-types/{id}` is also blocked (`422`, `body.error = deletion_blocked_by_reservations`) while a current or upcoming reservation still books the type. Deactivate it instead.
- **Occupancy**: a checked-in multi-room reservation occupies every one of its rooms; the overnight "mark dirty" job and the dashboard's date-based occupancy count every room too. Single-room behavior is unchanged.
- **Stay**: still one stay per reservation until per-room stays ship; its `room_id` is the first live line's room.
- **Dashboard**: `today_arrivals` / `today_departures` entries carry `rooms[]` / `room_summary` instead of `room`.
- **Import**: each imported row becomes one line (`room_number` → that room and its type; `room_type` alone → an unassigned line of that type, created if missing; neither → the hotel's default type). Imports skip the capacity check.
- **Admin AI**: the create-reservation tool books by room type name and quantity (active types only; unknown names get the list of available types) and asks for the room type if none is given. Its writes are audited as the AI agent. The reservation read tools return `rooms` and `room_summary` instead of one `room_number`.
- **WhatsApp reservation email**: lists rooms per type ("2 × Deluxe — 101, unassigned").

### Data Migration

1. Create `reservation_rooms` (with a partial unique index so one room can't sit on two live lines of the same reservation).
2. Backfill one line per existing reservation, including soft-deleted and cancelled ones, from its room and that room's type. Reservations with no room, or with a room of another hotel, get an inactive per-hotel **"Unspecified (migrated)"** room type (created only where needed; counts per hotel are logged). Re-running adds nothing; room statuses are not touched.
3. Drop `reservations.room_id` (rollback restores it from the first live line).

### Frontend Slice (`ecosystem-frontend`)

Ship together with this backend change — there is no compatibility shim:

- Reservation form: room-type lines × quantity, with an optional room per single line; show the capacity error and offer an explicit "save anyway" (`capacity_override`).
- Reservation view/list: render `rooms[]` / `room_summary` instead of `room`; filters by room type / room use `filter[room_type_id]` / `filter[room_id]`.
- Edit: send the full `rooms` list with `id`s for kept lines; on a checked-in reservation offer only "move room" (same type).
- Dashboard arrivals/departures: read rooms from `rooms[]`.

### Documentation

- `docs/reservations-api-documentation.md` (object, create/update, filters, errors, import)
- `docs/room-types-api-documentation.md` (new deletion rule)
- `specs/002-reservation-rooms/` (spec, plan, contract, quickstart)
