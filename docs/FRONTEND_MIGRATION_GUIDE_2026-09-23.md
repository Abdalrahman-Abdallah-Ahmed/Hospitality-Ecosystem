# Frontend Migration Guide: Room Types API Changes
**Date**: 2026-09-23 | **Target**: Frontend Team

---

## Overview

The backend has migrated from the **RoomTypes enum** to a **RoomType model**. This enables:
- Per-hotel room type management via API
- Dynamic room type creation/updates
- Better alignment with availability and reservation workflows

**Impact**: Frontend must replace all hardcoded room type enums with dynamic API calls.

---

## What Changed

### Before (Old Way - Enum)

```typescript
// Frontend maintained static list of room types
const ROOM_TYPES = [
  { value: 'single', label: 'Single' },
  { value: 'double', label: 'Double' },
  { value: 'twin', label: 'Twin' },
  { value: 'triple', label: 'Triple' },
  { value: 'suite', label: 'Suite' },
  { value: 'deluxe', label: 'Deluxe' },
  { value: 'family', label: 'Family' },
];

// Creating/updating rooms with enum value
const createRoom = {
  room_number: '203',
  room_type: 'suite',  // ← Hardcoded enum value
  floor: 2,
};
```

### After (New Way - API Model)

```typescript
// Frontend fetches room types from API
interface RoomType {
  id: string;           // UUID
  hotel_id: string;
  name: string;         // "Suite", "Double", etc.
  description?: string;
  max_occupancy: number;
  adult_capacity: number;
  child_capacity: number;
  base_price: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

// Creating/updating rooms with room_type_id (FK)
const createRoom = {
  room_number: '203',
  room_type_id: 'uuid-of-suite-type',  // ← FK to RoomType
  floor: 2,
};
```

---

## New API Endpoints

### List Room Types (with filtering, search, pagination)

```http
GET /api/room-types?page=1&per_page=50&sort=-created_at&search=deluxe&filter[is_active]=true
```

**Response**:
```json
{
  "message": "Room types retrieved successfully",
  "code": 200,
  "body": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "hotel_id": "110e8400-e29b-41d4-a716-446655440000",
      "name": "Deluxe Suite",
      "description": "Spacious suite with city view",
      "max_occupancy": 4,
      "adult_capacity": 2,
      "child_capacity": 2,
      "bed_configuration": { "beds": [{"type": "king", "count": 1}] },
      "amenities": ["WiFi", "AC", "Minibar"],
      "base_price": "150.00",
      "is_active": true,
      "created_at": "2026-09-23T10:30:00Z",
      "updated_at": "2026-09-23T10:30:00Z"
    }
  ]
}
```

### Create Room Type

```http
POST /api/room-types
Content-Type: application/json
Authorization: Bearer {token}

{
  "name": "Deluxe Suite",
  "description": "Spacious suite with city view",
  "max_occupancy": 4,
  "adult_capacity": 2,
  "child_capacity": 2,
  "bed_configuration": {
    "beds": [{"type": "king", "count": 1}]
  },
  "amenities": ["WiFi", "AC", "Minibar"],
  "base_price": 150.00
}
```

**Response**: (201 Created)
```json
{
  "message": "Room type created successfully",
  "code": 201,
  "body": { /* RoomType object */ }
}
```

### Update Room Type

```http
PUT /api/room-types/{id}
Content-Type: application/json
Authorization: Bearer {token}

{
  "base_price": 175.00,
  "is_active": false
}
```

### Delete Room Type (Soft Delete)

```http
DELETE /api/room-types/{id}
Authorization: Bearer {token}
```

**Response**: (200 OK)
```json
{
  "message": "Room type deleted successfully",
  "code": 200,
  "body": {}
}
```

---

## Frontend Implementation Checklist

### 1. **Room Type Management Page/Component**
- [ ] Create new "Room Types" management UI (admin-only)
- [ ] List room types with pagination, search, sorting
- [ ] Create/Edit/Delete room type forms
- [ ] Validation for capacity constraints:
  - `adult_capacity ≥ 1`
  - `child_capacity ≥ 0`
  - `adult_capacity + child_capacity ≤ max_occupancy`
  - `base_price ≥ 0`
- [ ] Deactivation toggle (`is_active` flag)
- [ ] Show error when trying to delete room type with active rooms

### 2. **Room Creation/Edit**
- [ ] Fetch room types on component load: `GET /api/room-types`
- [ ] **Replace enum dropdown** with dynamic room type selector
- [ ] Use `room_type_id` (UUID) instead of `room_type` (string enum)
- [ ] Update create/update room payloads:
  ```typescript
  // OLD
  { room_number: '203', room_type: 'suite', floor: 2 }
  
  // NEW
  { room_number: '203', room_type_id: 'uuid-...', floor: 2 }
  ```

### 3. **Reservation Management**
- [ ] Update reservation creation to reference room types by ID
- [ ] Show room type details in reservation display
- [ ] Availability filters may reference room types

### 4. **API Integration Updates**

#### Old Code Pattern (Remove)
```typescript
// ❌ Do NOT use enum anymore
const roomType = 'suite';
const ROOM_TYPES = ['single', 'double', 'twin', ...];
```

#### New Code Pattern (Add)
```typescript
// ✅ Fetch from API instead
const roomTypes = await fetchRoomTypes();  // GET /api/room-types
const selectedRoomType = roomTypes.find(rt => rt.id === roomTypeId);

// Use room_type_id (FK) in create/update payloads
const payload = {
  room_number: '203',
  room_type_id: selectedRoomType.id,  // ← UUID, not string enum
  floor: 2,
};
```

### 5. **UI/UX Changes**

| Component | Old Behavior | New Behavior |
|-----------|--------------|--------------|
| **Room selector** | Hardcoded enum dropdown | Dynamic API dropdown |
| **Room creation form** | Fixed room type options | Real-time room type list |
| **Filters** | Static enum values | Dynamic based on hotel's room types |
| **Room type management** | N/A (manual admin work) | Full CRUD UI (new feature) |

### 6. **Error Handling**

Handle new error scenarios:

```typescript
// Deletion blocked - 422 Unprocessable Entity
{
  "message": "Cannot delete room type: active rooms still reference this type...",
  "code": 422,
  "body": { "error": "deletion_blocked_by_rooms" }
}

// Validation error - 422
{
  "message": "Validation failed",
  "code": 422,
  "body": {
    "errors": {
      "max_occupancy": ["Adult capacity + child capacity cannot exceed maximum occupancy"],
      "adult_capacity": ["Adult capacity must be at least 1"]
    }
  }
}
```

### 7. **Permissions**

New staff role permissions to enforce:
- `room_types.view` — List and view room types
- `room_types.create` — Create new room types
- `room_types.update` — Update room type details
- `room_types.delete` — Delete room types

**Note**: Only granted to staff with appropriate role permissions. Default employees get NO room type management access.

---

## Migration Path (Phases)

### Phase 1: API Integration (IMMEDIATE)
1. ✅ Backend updated with new RoomType model and endpoints
2. Frontend fetches `/api/room-types` to populate selectors
3. Frontend sends `room_type_id` instead of `room_type` enum in create/update

### Phase 2: UI Management (NEXT)
4. Frontend implements "Room Types" admin page
5. Staff can create/edit/delete room types via UI
6. Staff can deactivate room types

### Phase 3: Complete Migration (FINAL)
7. Enum column removed from database
8. All frontend hardcoded room types removed
9. Full API-driven room type system live

---

## Example React Implementation

```typescript
// Room Type Hook
const useRoomTypes = (hotelId: string) => {
  const [roomTypes, setRoomTypes] = useState<RoomType[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchRoomTypes(hotelId).then(setRoomTypes).finally(() => setLoading(false));
  }, [hotelId]);

  return { roomTypes, loading };
};

// Room Create Form
const RoomCreateForm: React.FC<{ hotelId: string }> = ({ hotelId }) => {
  const { roomTypes } = useRoomTypes(hotelId);

  return (
    <form onSubmit={(e) => {
      e.preventDefault();
      const roomTypeId = (e.target as any).room_type_id.value;
      // POST to /api/room with room_type_id
      createRoom({ room_number, room_type_id: roomTypeId, floor });
    }}>
      <input name="room_number" placeholder="203" />
      <select name="room_type_id">
        {roomTypes.map(rt => (
          <option key={rt.id} value={rt.id}>{rt.name}</option>
        ))}
      </select>
      <input name="floor" type="number" />
      <button type="submit">Create Room</button>
    </form>
  );
};

// Room Type Management
const RoomTypeManagement: React.FC<{ hotelId: string }> = ({ hotelId }) => {
  const { roomTypes } = useRoomTypes(hotelId);

  return (
    <div>
      <h2>Room Types</h2>
      <button onClick={() => showCreateModal()}>+ New Room Type</button>
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Max Occupancy</th>
            <th>Base Price</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {roomTypes.map(rt => (
            <tr key={rt.id}>
              <td>{rt.name}</td>
              <td>{rt.max_occupancy}</td>
              <td>{rt.base_price}</td>
              <td>{rt.is_active ? '✓ Active' : '✗ Inactive'}</td>
              <td>
                <button onClick={() => editRoomType(rt)}>Edit</button>
                <button onClick={() => deleteRoomType(rt.id)}>Delete</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};
```

---

## Testing Checklist for Frontend

- [ ] Room type list loads correctly
- [ ] Create room type with all fields
- [ ] Edit room type (partial updates)
- [ ] Deactivate room type (toggle `is_active`)
- [ ] Delete room type (soft delete)
- [ ] Cannot delete room type with active rooms (422 error)
- [ ] Search/filter/sort room types
- [ ] Pagination works (page, per_page)
- [ ] Validation errors display correctly
- [ ] Room creation uses correct `room_type_id` FK
- [ ] Permissions enforced (403 Forbidden for unauthorized)
- [ ] Cross-hotel isolation (cannot access other hotels' types)

---

## Backward Compatibility Notes

**During Migration Period**:
- ✅ Backend still supports old enum values in imports (auto-converted to FK)
- ✅ Tests updated to use new model
- ❌ Frontend **must** transition to new API before enum removed

**After Migration Complete**:
- ❌ No enum endpoint available
- ❌ All UI must use RoomType API
- ✅ Full CRUD management available

---

## Questions?

Contact backend team:
- New RoomType endpoints: See `docs/room-types-api-documentation.md`
- Schema/constraints: See `specs/001-room-types/data-model.md`
- Integration pattern: See `specs/001-room-types/quickstart.md`

---
