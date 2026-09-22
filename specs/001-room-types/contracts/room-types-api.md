# API Contract: Room Types

**Feature**: Room Types (Phase 1)

**Date**: 2026-09-23

**API Style**: JSON REST API

**Base Path**: `/api`

---

## Endpoints

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
    "bed_configuration": {
      "beds": [
        {"type": "king", "count": 1},
        {"type": "twin", "count": 1}
      ]
    },
    "amenities": ["WiFi", "AC", "Minibar", "Bathrobe"],
    "base_price": "150.00",
    "is_active": true,
    "created_at": "2026-09-23T10:30:00Z",
    "updated_at": "2026-09-23T10:30:00Z"
  }
}
```

**Error Responses**:

- `400 Bad Request`: Invalid JSON, missing required fields, or validation failure
  ```json
  { "message": "Validation failed", "code": 400, "body": { "errors": { "name": ["Name is required"], "adult_capacity": ["Adult capacity must be at least 1"] } } }
  ```
- `403 Forbidden`: User lacks `room_types.create` permission
- `422 Unprocessable Entity`: Business rule violation (e.g., name not unique per hotel)

---

### 2. List Room Types

**Endpoint**: `GET /api/room-types`

**Authentication**: Required

**Authorization**: Requires `room_types.view` permission

**Query Parameters**:

- `page` (int, default 1): Pagination page number
- `per_page` (int, default 50, max 250): Records per page
- `sort` (string): Sort field; prefix with `-` for descending (e.g., `sort=-created_at`, `sort=name`)
- `search` (string): Full-text search on name and description
- `filter[is_active]` (bool): Filter by active status (optional)

**Example Request**: `GET /api/room-types?page=1&per_page=25&sort=name&search=deluxe`

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
    },
    {
      "id": "660e8400-e29b-41d4-a716-446655440001",
      "hotel_id": "110e8400-e29b-41d4-a716-446655440000",
      "name": "Standard Room",
      "description": "Comfortable room for individual travelers",
      "max_occupancy": 2,
      "adult_capacity": 1,
      "child_capacity": 1,
      "base_price": "80.00",
      "is_active": true,
      "created_at": "2026-09-23T09:15:00Z",
      "updated_at": "2026-09-23T09:15:00Z"
    }
  ]
}
```

**Notes**:
- Soft-deleted room types are ALWAYS excluded from the list (no filter option to include them)
- Only room types belonging to the user's hotel are returned (tenant isolation)
- Pagination metadata may be included in a wrapper (decided by implementation)

---

### 3. Retrieve Single Room Type

**Endpoint**: `GET /api/room-types/{id}`

**Authentication**: Required

**Authorization**: Requires `room_types.view` permission

**Path Parameters**:

- `id` (UUID): Room type ID

**Response** (200 OK):

Same structure as Create response body.

**Error Responses**:

- `404 Not Found`: Room type does not exist or belongs to a different hotel (tenant isolation)
- `403 Forbidden`: User lacks `room_types.view` permission

---

### 4. Update Room Type

**Endpoint**: `PUT /api/room-types/{id}`

**Authentication**: Required

**Authorization**: Requires `room_types.update` permission

**Path Parameters**:

- `id` (UUID): Room type ID

**Request Body** (all fields optional for PATCH-like partial updates, or only changed fields):

```json
{
  "name": "Deluxe Suite (Renovated)",
  "description": "Updated description",
  "base_price": 175.00,
  "is_active": false
}
```

**Response** (200 OK):

Updated room type resource (same structure as Create response).

**Error Responses**:

- `400 Bad Request`: Invalid JSON or validation failure
- `403 Forbidden`: User lacks `room_types.update` permission or room belongs to different hotel
- `404 Not Found`: Room type does not exist
- `422 Unprocessable Entity`: Business rule violation (e.g., cannot update an active field if it would violate constraints)

**Concurrency**: Last-write-wins. No version field or 409 Conflict response. If two admins edit simultaneously, the last update persists.

---

### 5. Delete Room Type

**Endpoint**: `DELETE /api/room-types/{id}`

**Authentication**: Required

**Authorization**: Requires `room_types.delete` permission

**Path Parameters**:

- `id` (UUID): Room type ID

**Response** (200 OK):

```json
{
  "message": "Room type deleted successfully",
  "code": 200,
  "body": {}
}
```

**Error Responses**:

- `403 Forbidden`: User lacks `room_types.delete` permission or room belongs to different hotel
- `404 Not Found`: Room type does not exist or already deleted
- `422 Unprocessable Entity`: Deletion blocked because active rooms still reference this type
  ```json
  {
    "message": "Cannot delete room type: active rooms still reference this type. Deactivate the room type instead, or delete/reassign the rooms first.",
    "code": 422,
    "body": { "error": "deletion_blocked_by_rooms" }
  }
  ```

**Soft Deletion**: Marks `deleted_at` timestamp; does not remove the record. Soft-deleted room types never appear in API list responses.

---

## Common Response Format

All endpoints follow the standardized response envelope:

```json
{
  "message": "Human-readable status message",
  "code": 200,
  "body": { /* Resource data or error details */ }
}
```

---

## Authentication & Authorization

- **Authentication Header**: `X-API-KEY: <key>` or `Authorization: Bearer <token>`
- **Tenant Scope**: All requests automatically scoped to the authenticated user's hotel via `TenantContext` middleware
- **Permissions**: Enforced by `RoomTypePolicy` via `ChecksPermissions::allows()`
- **Cross-tenant protection**: Attempting to access another hotel's room types returns 403 or 404

---

## Pagination

List endpoints support pagination via `GenericIndexRequest` and `GenericQuery`:

- Default page size: 50
- Max page size: 250
- Page query param: `page=N` (1-indexed)
- Per-page param: `per_page=M`

---

## Sorting

Sort field specified via `sort` query param:

- Ascending: `sort=field_name` (e.g., `sort=created_at`)
- Descending: `sort=-field_name` (e.g., `sort=-created_at`)

---

## Search

Full-text search on name and description via `search` query param:

- `search=deluxe` → matches "Deluxe Suite", "Deluxe Room", etc.
- Case-insensitive
- Applied to all room types in the user's hotel

---
