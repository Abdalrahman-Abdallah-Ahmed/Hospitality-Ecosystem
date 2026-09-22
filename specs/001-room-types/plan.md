# Implementation Plan: Room Types

**Branch**: `001-room-types` | **Date**: 2026-09-23 | **Spec**: [specs/001-room-types/spec.md](spec.md)

**Input**: Feature specification from `specs/001-room-types/spec.md`

**Note**: This plan maps Room Types to the existing Hospitality Ecosystem architecture (Laravel 13, PostgreSQL, Pest 4).

## Summary

Implement room type inventory management: a per-hotel `room_types` table with CRUD API, validation, policies, and soft deletion. Room types are the foundational domain for reservations and availability calculations. The implementation reuses existing architecture patterns (models with `BelongsToHotel`, Resource serialization, permission-based policies) and adds no new external dependencies.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, Sanctum (auth), Pest 4 (testing), pgvector (reserved for later phases, not used in Room Types)

**Storage**: PostgreSQL with `pgvector` extension (for embeddings in later phases; Room Types uses standard SQL)

**Testing**: Pest 4 with real PostgreSQL (specified in phpunit.xml; SQLite not used)

**Target Platform**: Laravel JSON API (web service, no frontend coupling)

**Project Type**: Multi-tenant hospitality PMS backend

**Performance Goals**: <500ms per request (SC-001); no specific throughput target for MVP

**Constraints**: 
- Tenant isolation (mandatory per constitution)
- Permission-based authorization (mandatory per constitution)
- Soft deletion support (SoftDeletes trait)
- No external API dependencies

**Scale/Scope**: Multi-property (per-hotel scoped); typical hotel has 5–50 room types

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

✅ **Core Principles (All Pass)**:

- **I. PMS Is the System of Record**: Room types are structured PMS data, not AI-generated. ✅
- **II. Preserve and Evolve**: Reusing existing model patterns (BelongsToHotel, SoftDeletes, Resource, Policy). ✅
- **III. Tenant Isolation (NON-NEGOTIABLE)**: Will use BelongsToHotel scope, hotel_id foreign key, and TenantContext enforcement. ✅
- **IV. Permission-Based Authorization (NON-NEGOTIABLE)**: Will add room_types.create, .view, .update, .delete cases to Permission enum. ✅
- **V. AI Acts Through Tools**: N/A for CRUD endpoints; AdminAI tools in later phases. ✅
- **VI. Auditability**: FR-010 logs mutations via EventLogger (audit system integration). ✅
- **VII. Data Integrity**: Using database transactions, validation, constraints (SoftDeletes). ✅
- **VIII. Tested at Domain Boundary**: Pest tests against real PostgreSQL (per phpunit.xml). ✅
- **IX. Spec-Driven Delivery**: Following Specify → Plan → Tasks workflow. ✅

✅ **Non-Negotiable Architectural Rules (Relevant Subset)**:

- **Rule 1**: PMS structured data is operational source of truth. ✅ (Room types are PMS data)
- **Rule 3**: Tenant isolation mandatory. ✅ (BelongsToHotel required)
- **Rule 4**: Reservation does not require physical room assignment at creation. ✅ (N/A; room types are inventory)
- **Rule 7**: Room Type and physical Room remain separate. ✅ (Implemented via separate models and FK)
- **Rule 25**: MVP remains incrementally deployable. ✅ (Room types alone do not block deployability)

## Project Structure

### Documentation (this feature)

```text
specs/[###-feature]/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Models/RoomType.php               # Model with BelongsToHotel, SoftDeletes
├── Http/Controllers/RoomTypeController.php
├── Http/Requests/RoomType/
│   ├── CreateRequest.php
│   └── UpdateRequest.php
├── Http/Resources/RoomTypeResource.php
├── Policies/RoomTypePolicy.php       # Authorization via ChecksPermissions
├── Enums/Permission.php              # Add room_types.* cases
└── Services/RoomTypeService.php      # Business logic (optional, CRUD may be in controller)

database/
├── migrations/YYYY_MM_DD_NNNNNN_create_room_types_table.php
└── migrations/YYYY_MM_DD_NNNNNN_add_currency_to_hotels_table.php

routes/
└── api.php                           # Register Room Type endpoints (existing file)

tests/Feature/
└── RoomTypeControllerTest.php        # Pest suite with authorization, isolation, validation

docs/
└── room-types-api-documentation.md   # API reference (update or create)
```

**Structure Decision**: Follows existing Laravel Hospitality Ecosystem patterns:
- Single monolithic Laravel app (no separate backend/frontend in this repo; frontend is separate `ecosystem-frontend`)
- Models in `app/Models/` with tenancy traits
- HTTP layer: Controllers, Requests (validation), Resources (serialization)
- Policies in `app/Policies/` for authorization
- Services optional (CRUD is simple; may be omitted for this feature)
- Tests in `tests/Feature/` using Pest

## Complexity Tracking

**Status**: No architectural violations or justified exceptions. Constitution Check passes cleanly.

---

## Phase 0: Research

**Status**: No NEEDS CLARIFICATION items in the specification. All technical ambiguities resolved in `/speckit-clarify`.

**Resolved clarifications**:
- Concurrent edit handling: Last-write-wins (no version field)
- Soft-deleted visibility: Always hidden from list API
- Localization: English-only for MVP; deferred to frontend

**Artifacts to create**: None (no research phase needed)

---

## Phase 1: Design

### 1.1 Data Model (`data-model.md`)

**Entities**:

- **RoomType**
  - id: UUID (primary key)
  - hotel_id: UUID (foreign key, required, NOT NULL)
  - name: string (max 255, required, unique per hotel)
  - description: text (optional)
  - max_occupancy: integer (≥1, required)
  - adult_capacity: integer (≥1, required)
  - child_capacity: integer (≥0, required)
  - bed_configuration: JSON (optional)
  - amenities: JSON (optional)
  - base_price: decimal (≥0, required)
  - is_active: boolean (default true)
  - created_at, updated_at, deleted_at (SoftDeletes)

- **Hotel** (existing, extended)
  - Add currency: string (ISO 4217, default USD, required)

- **Room** (existing, modified)
  - Replace room_type: enum → room_type_id: UUID (foreign key, NOT NULL after migration)

**Relationships**:
- RoomType belongsTo Hotel
- RoomType hasMany Room (not in reverse; Room.room_type_id references RoomType.id)

**Constraints**:
- hotel_id + name unique (per-hotel uniqueness)
- adult_capacity + child_capacity ≤ max_occupancy
- base_price ≥ 0
- Soft deletion: deleted_at nullable timestamp (SoftDeletes trait)

**Migrations**:
- M1: Create room_types table (no currency field; derived from hotel)
- M2: Add currency to hotels table (default USD)
- M3: Backfill room_types from RoomTypes enum (per hotel, per enum value)
- M4: Add room_type_id to rooms, backfill from enum/room_type, drop room_type column (multi-step)

### 1.2 Contracts

**API Endpoints** (RESTful JSON API):

```
POST   /room-types                    Create a room type
GET    /room-types                    List room types (with filtering, search, pagination)
GET    /room-types/{id}               Retrieve a single room type
PUT    /room-types/{id}               Update a room type
DELETE /room-types/{id}               Soft delete a room type
```

**Request/Response Format**:
- All responses: `{ message: string, code: int, body: object | array }`
- Soft-deleted records never appear in list responses
- Last-write-wins concurrency (no version field)

### 1.3 Quickstart (`quickstart.md`)

**Validation scenarios**:

1. **Create and list room types** → Hotel admin creates 3 room types; list endpoint returns all 3 active.
2. **Update room type** → Admin updates a room type's price; change persists in subsequent GET.
3. **Deactivate and verify** → Admin sets is_active = false; room type is excluded from future availability calculations (tested via integration).
4. **Authorization** → Staff without permission receives 403; staff with permission succeeds.
5. **Tenant isolation** → Hotel A admin cannot see/modify Hotel B's room types.
6. **Soft deletion** → Deleted room types are never returned in list responses.
7. **Validation** → Invalid payload (e.g., adult_capacity = 0, base_price < 0) is rejected with error details.

### 1.4 Policies & Authorization

**Permissions to add**:
- room_types.view
- room_types.create
- room_types.update
- room_types.delete

**Policy**: RoomTypePolicy implements ChecksPermissions; all abilities check tenant scope + permission.

### 1.5 Dependencies & Integration Points

**Within this feature**:
- Model (RoomType) → Controller → Resource → Policy

**Upstream dependencies** (must exist before Room Types is implemented):
- Hotel model (already exists)
- Room model (exists, will be modified to add room_type_id)
- Permission enum (exists, will be extended)
- BelongsToHotel trait (exists)
- TenantContext (exists)

**Downstream dependencies** (Room Types enables):
- Availability service (Phase 3)
- Reservation creation (Phase 2)
- Room assignment (Phase 3)
