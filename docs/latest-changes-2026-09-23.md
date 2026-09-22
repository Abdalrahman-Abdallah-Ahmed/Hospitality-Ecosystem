# Latest Changes: 2026-09-23

## Room Types (Phase 1 — Inventory Foundation)

### Summary

Added comprehensive room type inventory management system as the foundation for availability calculations and reservation workflows. Room types categorize guest accommodations (e.g., Standard, Deluxe, Suite) and are managed via the new `/api/room-types` endpoints.

### What's New

#### Database Schema

- **New table**: `room_types` with fields: id, hotel_id, name, description, max_occupancy, adult_capacity, child_capacity, bed_configuration, amenities, base_price, is_active, timestamps, soft delete marker
- **Enhanced table**: `hotels` now includes `currency` field (ISO 4217 code, default USD) for per-hotel pricing configuration
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
1. Create `room_types` table with auto-generated records from enum values
2. Add `currency` column to hotels (default USD)
3. Add nullable `room_type_id` to rooms
4. Backfill `room_type_id` from enum values
5. Drop `room_type` enum column

**No data loss**: All existing room-type assignments preserved via FK relationships.

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
- Requests: `App\Http\Requests\RoomType\CreateRequest` and `UpdateRequest` (validation)
- Resource: `App\Http\Resources\RoomTypeResource` (serialization)
- Seeder: `database/seeders/RoomTypeSeeder.php` (sample data)

---

**Ready for**: Phase 2 (Reservations), Phase 3 (Availability), Phase 4 (Room Assignment)
