# Feature Specification: Room Types

**Feature Branch**: `001-room-types`

**Created**: 2026-09-22

**Status**: Draft

**Input**: Phase 1 — Inventory Foundation; read from docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md

## Clarifications

### Session 2026-09-23

- Q: Concurrent edit conflict resolution? → A: Last-write-wins; no optimistic locking or version fields required.
- Q: Soft-deleted room type visibility in list responses? → A: Always hidden; no `include_deleted` filter provided.
- Q: Localization for room type names/descriptions? → A: English-only for MVP; translation deferred to frontend presentation layer.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Hotel Admin Creates and Manages Room Types (Priority: P1)

A hotel admin needs to define the different types of rooms available in their property (e.g., Single, Double, Deluxe, Suite) with their characteristics, pricing, and availability status. This establishes the room inventory catalog that reservations reference and drives occupancy calculations.

**Why this priority**: Room types are the foundational data model for the entire reservation and availability system. Without room types, no subsequent operations (availability calculation, room assignment, reservation creation) can function.

**Independent Test**: Can be fully tested by creating, viewing, editing, and deactivating room types through the API or admin UI. The system should allow a hotel to define its complete room inventory independently.

**Acceptance Scenarios**:

1. **Given** a hotel exists, **When** an admin creates a room type with name, description, max occupancy, adult capacity, child capacity, bed configuration, amenities, base price, and currency, **Then** the room type is stored and returned with a unique ID, linked to the hotel.
2. **Given** a room type exists, **When** an admin views the room type, **Then** all fields are returned accurately, including JSON fields (bed configuration, amenities).
3. **Given** a room type exists, **When** an admin updates any field, **Then** changes persist and future reservations/availability calculations use the updated data.
4. **Given** a room type exists, **When** an admin sets `is_active` to false, **Then** the room type becomes inactive and cannot be assigned to new reservations (existing ones remain valid).
5. **Given** a hotel has multiple room types, **When** an admin lists room types, **Then** all hotel-scoped types are returned with filtering and pagination support.

---

### User Story 2 - Room Types Drive Availability and Reservation Workflow (Priority: P1)

The system uses room types to determine sellable inventory: only room types linked to active physical rooms contribute to availability calculations. When a staff member or AI creates a reservation, they specify room-type lines (type + quantity), not physical rooms.

**Why this priority**: Room types must be available for the reservation creation flow and availability service. This is a domain prerequisite that must work end-to-end.

**Independent Test**: Can be tested by creating physical rooms with a room type, then checking that availability queries count only active rooms of that type. A new reservation creation flow is tested in a later phase but depends on this working.

**Acceptance Scenarios**:

1. **Given** a room type with multiple physical rooms, **When** availability is calculated for a date range, **Then** the count reflects only active, unoccupied rooms of that type.
2. **Given** a room type assigned to zero physical rooms, **When** availability is calculated, **Then** the count is zero.
3. **Given** a room type linked to both active and inactive rooms, **When** availability is calculated, **Then** only active rooms are counted.

---

### User Story 3 - Authorization: Only Authorized Staff Can Manage Room Types (Priority: P1)

Hotel admins and staff with the `room_types.create`, `room_types.update`, or `room_types.delete` permission can manage room types. Other staff and guests cannot. Each operation respects the staff member's hotel scope and permission level.

**Why this priority**: Authorization is a non-negotiable principle. Without permission checks, the system violates the constitution.

**Independent Test**: Can be tested independently by verifying that a staff member without the permission receives a 403 Forbidden response, while one with the permission succeeds.

**Acceptance Scenarios**:

1. **Given** a staff member has `room_types.create` permission, **When** they create a room type, **Then** the operation succeeds and the room type is stored.
2. **Given** a staff member lacks `room_types.create` permission, **When** they attempt to create a room type, **Then** the API returns 403 Forbidden.
3. **Given** a room type belongs to Hotel A, **When** a staff member from Hotel B attempts to update it, **Then** the API returns 403 Forbidden (tenant isolation).
4. **Given** a staff member has `room_types.view` permission, **When** they list room types, **Then** only their hotel's room types are returned.

---

### Edge Cases

- What happens when a room type is deactivated but active rooms still reference it? → No impact on existing reservations or room assignments; new availability calculations exclude it.
- What happens when a hotel creates its first room type? → The data is stored and immediately available for use; no side effects.
- Can a room type be created with zero adult capacity and non-zero child capacity? → No. The system requires `adult_capacity ≥ 1` for all room types. Children-only rooms are not supported in MVP.
- What happens if a staff member tries to delete a room type that has active rooms? → Deletion is rejected with `422 Unprocessable Entity`. The API response includes: "Cannot delete room type: active rooms still reference this type. Deactivate the room type instead, or delete/reassign the rooms first."

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST allow a hotel admin to create a room type with the following fields: name (string, required, unique per hotel), description (text, optional), max_occupancy (integer, required, ≥ 1), adult_capacity (integer, required, ≥ 1), child_capacity (integer, required, ≥ 0), bed_configuration (JSON, optional), amenities (JSON, optional), base_price (decimal, required, ≥ 0), and is_active (boolean, default true). Currency is derived from the hotel's currency setting, not the room type.

- **FR-002**: System MUST link every room type to a hotel through the `hotel_id` field. A room type belongs to exactly one hotel. MUST enforce `BelongsToHotel` scope.

- **FR-003**: System MUST provide a CRUD API:
  - `POST /room-types` (create)
  - `GET /room-types` (list, with filtering, search, sorting, pagination via `GenericIndexRequest` + `GenericQuery`)
  - `GET /room-types/{id}` (retrieve)
  - `PUT /room-types/{id}` (update)
  - `DELETE /room-types/{id}` (soft delete via `SoftDeletes`)

- **FR-004**: System MUST enforce authorization through policies: `create`, `view`, `update`, `delete` → `room_types.create`, `.view`, `.update`, `.delete` permissions (final names in this spec).

- **FR-005**: System MUST include a data migration that, during Phase 1:
  - Adds a `currency` column to the `hotels` table (default: USD); requires hotel admins to set after migration.
  - Creates a `room_types` table with hotel_id, name, description, max_occupancy, adult_capacity, child_capacity, bed_configuration, amenities, base_price, is_active, and Laravel timestamps (created_at, updated_at, deleted_at for SoftDeletes).
  - For each existing hotel and each room type value present in the `RoomTypes` enum currently used in the `rooms` table, backfills one `RoomType` record per hotel/type pair, using default descriptions and pricing (documented in the Assumptions section).
  - Updates `rooms.room_type_id` to NOT NULL after backfill, replacing the old `room_type` enum column with the new foreign key.

- **FR-006**: System MUST validate:
  - `max_occupancy` is an integer ≥ 1.
  - `adult_capacity` is an integer ≥ 1 (at least one adult required for all room types).
  - `child_capacity` is an integer ≥ 0.
  - `adult_capacity + child_capacity ≤ max_occupancy` (sum of capacities cannot exceed max occupancy).
  - `base_price ≥ 0`.
  - `name` is unique per hotel.

- **FR-007**: System MUST return room types as a Resource (JSON) with all fields, including UUID primary key and timestamps.

- **FR-008**: System MUST support soft deletion: `DELETE /room-types/{id}` marks the record deleted (sets `deleted_at`), and soft-deleted room types are ALWAYS excluded from all list endpoints (no `include_deleted` filter provided; audit history retrieval is out of scope for MVP).

- **FR-009**: System MUST exclude soft-deleted room types from availability calculations and new reservation workflows.

- **FR-010**: System MUST log all room type mutations (create, update, delete) in the audit log with actor, timestamp, and change details (implemented as part of the broader audit system).

### Key Entities

- **RoomType**: Represents a category of room (e.g., Single, Double, Deluxe). Attributes: id (UUID), hotel_id (UUID, foreign key), name, description, max_occupancy, adult_capacity, child_capacity, bed_configuration (JSON), amenities (JSON), base_price, currency, is_active, created_at, updated_at, deleted_at.
  - Relationships: `belongsTo(Hotel)`, `hasMany(Room)`.

- **Room** (existing, updated): Previously used a `room_type` enum column. Updated to use `room_type_id` (foreign key to RoomType, NOT NULL after migration).

- **Hotel** (existing): Has many room types through the `hotel_id` scope.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Hotel admins can create, update, list, and delete room types through the API; all CRUD operations complete in under 500ms per request.
- **SC-002**: Availability calculations correctly count only active, unoccupied rooms of the specified type; calculations reflect room type changes within 1 minute.
- **SC-003**: All API responses adhere to the standard `{ message, code, body }` format via `apiResponse()` and Resource serialization.
- **SC-004**: Authorization is enforced: staff without the required permission receive 403 Forbidden; tenants cannot access or modify another hotel's room types.
- **SC-005**: Tenant isolation is verified: no staff member from Hotel A can view, create, update, or delete room types from Hotel B.
- **SC-006**: The migration backfills all existing room types accurately, and the `rooms.room_type_id` column is populated and NOT NULL after migration.
- **SC-007**: Pest test coverage includes CRUD operations, authorization checks, tenant isolation, validation, and soft deletion.

---

## Assumptions

- **Backfill pricing**: During migration, backfilled room types receive a default `base_price` of 0. Hotels must edit pricing after migration. The hotel's currency is set to USD by default and must be updated if needed. This is documented in release notes.
- **Backfill descriptions**: Backfilled room types receive auto-generated descriptions like "Auto-created from [EnumValue]" to distinguish them from manually created types.
- **JSON fields**: `bed_configuration` and `amenities` are stored as JSON and expected to be valid JSON; the spec does not enforce a schema at the database level (schema validation is optional, decided by the plan).
- **Soft deletion**: Soft-deleted room types are treated as inactive for business logic (not counted in availability, not selectable for new reservations) and are ALWAYS hidden from list API responses (no audit history retrieval endpoint in MVP). Hard deletion can proceed once all active rooms are removed or reassigned.
- **Currency**: All room types within a hotel MUST use the same currency. A `currency` field is added to `hotels` table during Phase 1, and `room_types.currency` is replaced by deriving it from the hotel. This simplifies pricing calculations, reporting, and guest communication.
- **API pagination**: List endpoints use the existing `GenericIndexRequest` and `GenericQuery` patterns; default page size is 50 records, configurable up to 250.
- **Deletion constraints**: A room type cannot be deleted if active rooms still reference it. Instead, staff should deactivate the room type (`is_active` = false) for operational cleanup while preserving audit history. Hard deletion requires that no active rooms reference the type; the API returns 422 Unprocessable Entity if deletion is attempted with active rooms present.
- **Concurrent edits**: Last-write-wins; no optimistic locking or version fields required. If two admins edit the same room type simultaneously, the last update persists. No conflict error or retry mechanism needed.
- **Localization**: Room type names and descriptions are English-only in MVP. Multi-language support (Arabic per constitution) is deferred to the frontend presentation layer, which can translate display labels independently without modifying the database schema or API contract.

---

