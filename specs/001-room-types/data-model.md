# Data Model: Room Types

**Feature**: Room Types (Phase 1 — Inventory Foundation)

**Date**: 2026-09-23

---

## Entities

### RoomType

Represents a category or classification of guest room (e.g., Single, Double, Deluxe, Suite).

**Attributes**:

| Attribute | Type | Required | Unique | Constraints | Notes |
|-----------|------|----------|--------|-------------|-------|
| id | UUID | ✓ | ✓ (primary) | N/A | Generated on create |
| hotel_id | UUID | ✓ | ✗ | FK → Hotel.id | Tenant scope; enforced via BelongsToHotel |
| name | string(255) | ✓ | ✓ (per hotel) | Non-empty | e.g., "Standard", "Deluxe Suite" |
| description | text | ✗ | ✗ | No constraints | Optional marketing copy |
| max_occupancy | integer | ✓ | ✗ | ≥ 1 | Total maximum occupants (adults + children) |
| adult_capacity | integer | ✓ | ✗ | ≥ 1 | Minimum 1 adult required; no children-only rooms in MVP |
| child_capacity | integer | ✓ | ✗ | ≥ 0, adult_capacity + child_capacity ≤ max_occupancy | Can be 0 for adult-only rooms |
| bed_configuration | JSON | ✗ | ✗ | Valid JSON | e.g., `{"beds": [{"type": "queen", "count": 1}]}` (schema optional) |
| amenities | JSON | ✗ | ✗ | Valid JSON | e.g., `["WiFi", "AC", "Minibar"]` |
| base_price | decimal(10,2) | ✓ | ✗ | ≥ 0 | Default nightly rate; currency derived from hotel |
| is_active | boolean | ✓ | ✗ | Default true | Controls visibility in availability/reservation flows |
| created_at | timestamp | ✓ | ✗ | N/A | Laravel timestamp |
| updated_at | timestamp | ✓ | ✗ | N/A | Laravel timestamp |
| deleted_at | timestamp | ✗ | ✗ | N/A | Soft delete marker (SoftDeletes trait) |

**Relationships**:

- `belongsTo(Hotel)` — Many room types per hotel
- `hasMany(Room)` — Multiple physical rooms of the same type

---

### Hotel (Extended)

**New Attribute**:

| Attribute | Type | Required | Unique | Constraints | Notes |
|-----------|------|----------|--------|-------------|-------|
| currency | string(3) | ✓ | ✗ | ISO 4217 code, default USD | All room types in hotel inherit this currency |

**Rationale**: Single currency per hotel simplifies pricing, reporting, and multi-property accounting. Added during Phase 1 migration; existing hotels default to USD.

---

### Room (Modified)

**Removed Attribute**:

- `room_type` (enum string) — `RoomTypes` enum

**New Attribute**:

| Attribute | Type | Required | Unique | Constraints | Notes |
|-----------|------|----------|--------|-------------|-------|
| room_type_id | UUID | ✓ | ✗ | FK → RoomType.id | Replaces enum; NOT NULL after migration |

**Migration Strategy**:
1. Add `room_type_id` column (nullable initially)
2. For each room, set `room_type_id` = ID of the RoomType matching its current `room_type` enum value
3. Set `room_type_id` NOT NULL
4. Drop `room_type` enum column

---

## Validation Rules

**RoomType Validation**:

1. **name**: Non-empty, unique within the hotel (no two room types with same name per hotel)
2. **max_occupancy**: Integer ≥ 1 (at least 1 person can occupy)
3. **adult_capacity**: Integer ≥ 1 (at least 1 adult in every room; no children-only in MVP)
4. **child_capacity**: Integer ≥ 0
5. **Capacity constraint**: `adult_capacity + child_capacity ≤ max_occupancy`
6. **base_price**: Decimal ≥ 0 (no negative prices)
7. **JSON fields**: If provided, must be valid JSON (schema validation optional for MVP)

---

## Lifecycle & State Transitions

**RoomType Lifecycle**:

```
[Created (is_active=true)]
        ↓
  [Active]  ←→  [Inactive (is_active=false)]
        ↓
  [Soft-Deleted (deleted_at set)]
```

**Transitions**:

- **Created → Active**: Automatic on creation (is_active defaults to true)
- **Active → Inactive**: Call `PUT /room-types/{id}` with `is_active=false` (deactivation, does not delete)
- **Inactive → Active**: Call `PUT /room-types/{id}` with `is_active=true` (reactivation)
- **Active/Inactive → Soft-Deleted**: Call `DELETE /room-types/{id}` (only if no active rooms reference the type)
- **Soft-Deleted**: Marked with `deleted_at` timestamp; never returned in API list responses; queryable only internally for audit purposes (out of scope for MVP)

**Business Rules**:

- Deletion is rejected (422) if any active rooms still reference the room type. Staff must first:
  - Deactivate the room type (`is_active=false`), OR
  - Delete/reassign the physical rooms
- Deactivation (`is_active=false`) does NOT delete the record; it hides the room type from availability and reservation flows but preserves history
- Soft deletion (`deleted_at` set) can only happen when no active rooms reference the type

---

## Constraints & Indexes

**Database Constraints**:

- PRIMARY KEY: `id` (UUID)
- FOREIGN KEY: `hotel_id` → `hotels.id`
- UNIQUE: `(hotel_id, name)` — Room type names unique per hotel
- NOT NULL: `hotel_id`, `name`, `max_occupancy`, `adult_capacity`, `child_capacity`, `base_price`, `is_active`
- CHECK: `max_occupancy >= 1`
- CHECK: `adult_capacity >= 1`
- CHECK: `child_capacity >= 0`
- CHECK: `adult_capacity + child_capacity <= max_occupancy`
- CHECK: `base_price >= 0`

**Indexes** (for performance):

- Index on `hotel_id` (speeds up per-hotel queries)
- Index on `(hotel_id, deleted_at)` (soft delete filtering)
- Index on `is_active` (for availability queries)
- Composite index on `(hotel_id, is_active)` (list active types per hotel)

---

## Integration Notes

**How Room Types fit into the domain**:

1. **Reservations**: Reservation lines reference room types, not physical rooms. Multiple lines can specify the same type.
2. **Availability**: Availability calculations count active, unoccupied physical rooms of a specified type per date range.
3. **Room Assignment**: Assigns physical rooms to reservation lines; room type must match (no cross-type assignment in MVP).
4. **Tasks**: Room types don't directly affect task creation but enable room-centric task routing (future).

---

## Data Volume & Scale Assumptions

- **Typical hotel**: 5–50 room types
- **Large multi-property chain**: Up to 1,000 room types across all hotels (each hotel ~20–50)
- **Field sizes**: name (255 chars), description (unbounded text)
- **JSON fields**: bed_configuration and amenities expected <1KB each
- **Growth**: New room types added infrequently (during setup or property expansion); no time-series growth

---
