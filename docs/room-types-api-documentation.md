# Room Types API Documentation

**Feature**: Room Types (Phase 1 — Inventory Foundation)

**Date**: 2026-09-23

---

## Overview

The Room Types API provides endpoints for hotel staff to manage room type inventory. Room types categorize guest accommodations (e.g., Standard, Deluxe, Suite) and serve as the foundation for availability calculations and reservation workflows.

---

## API Endpoints

### 1. Create Room Type

**Endpoint**: `POST /api/room-types`

**Authentication**: Required (API Key via X-API-KEY header or Bearer token)

**Authorization**: Requires `room_types.create` permission

**Request Body**:

```json
{
  "name": "Deluxe Suite",
  "description": "Spacious suite with city view",
  "max_occupancy": 4,
  "adult_capacity": 2,
  "child_capacity": 2,
  "bed_configuration": {
    "beds": [
      {"type": "king", "count": 1},
      {"type": "twin", "count": 1}
    ]
  },
  "amenities": ["WiFi", "AC", "Minibar", "Bathrobe"],
  "base_price": 150.00
}
```

**Response** (201 Created):

```json
{
  "message": "Room type created successfully",
  "code": 201,
  "body": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "hotel_id": "110e8400-e29b-41d4-a716-446655440000",
    "name": "Deluxe Suite",
    "description": "Spacious suite with city view",
    "max_occupancy": 4,
    "adult_capacity": 2,
    "child_capacity": 2,
    "bed_configuration": { "beds": [...] },
    "amenities": ["WiFi", "AC", "Minibar", "Bathrobe"],
    "base_price": "150.00",
    "is_active": true,
    "created_at": "2026-09-23T10:30:00Z",
    "updated_at": "2026-09-23T10:30:00Z"
  }
}
```

---

### 2. List Room Types

**Endpoint**: `GET /api/room-types`

**Authentication**: Required

**Authorization**: Requires `room_types.view` permission

**Query Parameters**:
- `page` (int, default 1): Pagination page number
- `per_page` (int, default 50, max 250): Records per page
- `sort` (string): Sort field; prefix with `-` for descending (e.g., `sort=-created_at`)
- `search` (string): Full-text search on name and description
- `filter[is_active]` (bool): Filter by active status (optional)

**Example Request**: `GET /api/room-types?page=1&per_page=25&sort=name`

**Response** (200 OK):

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
      "base_price": "150.00",
      "is_active": true,
      "created_at": "2026-09-23T10:30:00Z",
      "updated_at": "2026-09-23T10:30:00Z"
    }
  ]
}
```

**Notes**:
- Soft-deleted room types are ALWAYS excluded from the list (no filter option to include them)
- Only room types belonging to the user's hotel are returned (tenant isolation)

---

### 3. Retrieve Single Room Type

**Endpoint**: `GET /api/room-types/{id}`

**Authorization**: Requires `room_types.view` permission

**Response** (200 OK): Same structure as Create response body.

---

### 4. Update Room Type

**Endpoint**: `PUT /api/room-types/{id}`

**Authorization**: Requires `room_types.update` permission

**Request Body** (all fields optional for partial updates):

```json
{
  "name": "Deluxe Suite (Renovated)",
  "base_price": 175.00,
  "is_active": false
}
```

**Response** (200 OK): Updated room type resource

**Concurrency**: Last-write-wins. No version field or 409 Conflict response.

---

### 5. Delete Room Type

**Endpoint**: `DELETE /api/room-types/{id}`

**Authorization**: Requires `room_types.delete` permission

**Response** (200 OK):

```json
{
  "message": "Room type deleted successfully",
  "code": 200,
  "body": {}
}
```

**Business Rule**: Deletion is blocked if active rooms reference the room type (returns 422).

---

## Availability Integration

### Query Pattern for Availability Service

Room Type data supports availability calculations via the following query pattern:

```sql
SELECT COUNT(rooms.id) FROM room_types
  INNER JOIN rooms ON rooms.room_type_id = room_types.id
  WHERE room_types.id = ?
    AND room_types.is_active = true
    AND rooms.deleted_at IS NULL
    AND rooms.occupancy_status = 'unoccupied'
    AND room_type_matches_search_criteria
```

### Availability Rules

1. **Active room types only**: Deactivated room types (`is_active = false`) are excluded from availability counts even if they appear in list responses. Staff can deactivate types temporarily without deleting.

2. **Soft-deleted never visible**: Soft-deleted room types (marked with `deleted_at`) never appear in API list responses and are excluded from all availability calculations. This preserves audit history while hiding archived data.

3. **Occupancy criteria**: Availability counts only unoccupied rooms of the specified type. Room occupancy status and guest check-in/check-out dates are orthogonal to room type availability.

### Example

"Availability counts active rooms of a type. Deactivated room types are excluded. Soft-deleted room types are never returned and excluded from all queries."

---

## Validation Rules

- **name**: Required, unique per hotel, max 255 characters
- **max_occupancy**: Required, integer ≥ 1
- **adult_capacity**: Required, integer ≥ 1
- **child_capacity**: Required, integer ≥ 0
- **Capacity constraint**: `adult_capacity + child_capacity ≤ max_occupancy`
- **base_price**: Required, decimal ≥ 0
- **JSON fields**: bed_configuration and amenities must be valid JSON if provided

---

## Tenant Isolation & Authorization

- **Tenant scoping**: Automatic via `TenantContext` middleware; all requests scoped to authenticated user's hotel
- **Permission checks**: All endpoints protected by `RoomTypePolicy` via `ChecksPermissions::allows()`
- **Cross-hotel access**: Attempting to access another hotel's room types returns 403 or 404

---

## Error Responses

| Status | Scenario |
|--------|----------|
| 400 | Invalid JSON or validation failure |
| 403 | User lacks required permission or cross-hotel access |
| 404 | Room type not found or soft-deleted |
| 422 | Business rule violation (e.g., deletion blocked by active rooms) |

---

## Soft Deletion Behavior

Soft-deleted room types:
- Are marked with a `deleted_at` timestamp
- Never appear in API list responses
- Return 404 when accessed by id
- Can be permanently deleted (force delete) only via database administration
- Preserve audit history for compliance

---
