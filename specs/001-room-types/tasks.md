# Tasks: Room Types

**Input**: Design documents from `/specs/001-room-types/`

**Prerequisites**: plan.md, spec.md, data-model.md, contracts/, quickstart.md

**Context**: Laravel 13 backend (PHP 8.3), PostgreSQL, Pest 4 testing. No external dependencies. Reuses existing BelongsToHotel, Resource, Policy, and TenantContext patterns.

**Format**: `[ID] [P?] [Story?] Description with file path`

**Task Count**: 51 total tasks (after remediation additions T008a, T046a, clarification T041)
- Phase 1 (Setup): 5 tasks
- Phase 2 (Foundational): 6 tasks (includes T008a for audit logging)
- Phase 3 (US1): 12 tasks
- Phase 4 (US2): 4 tasks
- Phase 5 (US3): 11 tasks
- Phase 6 (Polish): 9 tasks (includes T046a for performance verification)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Database migrations, permission enum, and basic project structure

- [x] T001 Add `room_types.*` permission cases (create, view, update, delete) to `app/Enums/Permission.php` per CLAUDE.md procedure
- [x] T002 Create migration: `database/migrations/YYYY_MM_DD_NNNNNN_create_room_types_table.php` with columns: id (UUID), hotel_id (FK), name, description, max_occupancy, adult_capacity, child_capacity, bed_configuration (JSON), amenities (JSON), base_price, is_active, timestamps, deleted_at. Add constraints: (hotel_id, name) unique, max_occupancy ≥ 1, adult_capacity ≥ 1, child_capacity ≥ 0, adult_capacity + child_capacity ≤ max_occupancy, base_price ≥ 0. Add indexes on (hotel_id), (hotel_id, deleted_at), (is_active), (hotel_id, is_active)
- [x] T003 Create migration: `database/migrations/YYYY_MM_DD_NNNNNN_add_currency_to_hotels_table.php` to add currency column (string(3), default 'USD') per data-model.md
- [x] T004 Create migration: `database/migrations/YYYY_MM_DD_NNNNNN_backfill_room_types_from_enum.php` to backfill RoomType records for each (hotel, RoomTypes enum value) pair from existing Room records, with auto-generated descriptions like "Auto-created from [EnumValue]" and default base_price = 0
- [x] T005 Create migration: `database/migrations/YYYY_MM_DD_NNNNNN_migrate_room_type_column_to_foreign_key.php` to: (1) add nullable room_type_id to rooms, (2) backfill from room_type enum to room_type_id FK, (3) set room_type_id NOT NULL, (4) drop room_type enum column

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core model, authorization policy, validation, serialization—MUST complete before endpoint work

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [x] T006 [P] Create `app/Models/RoomType.php` with: `HasUuids`, `BelongsToHotel`, `SoftDeletes`, `$fillable = ['name', 'description', 'max_occupancy', 'adult_capacity', 'child_capacity', 'bed_configuration', 'amenities', 'base_price', 'is_active']`, explicit `$casts` for JSON fields, `$keyType = 'string'`, `$incrementing = false`. Add relationship `public function rooms()` returning `hasMany(Room::class, 'room_type_id')`
- [x] T007 [P] Create `app/Http/Resources/RoomTypeResource.php` to serialize room types in standard format: id, hotel_id, name, description, max_occupancy, adult_capacity, child_capacity, bed_configuration, amenities, base_price, is_active, created_at, updated_at. Exclude deleted_at from response.
- [x] T008 Create `app/Policies/RoomTypePolicy.php` with abilities: view, create, update, delete. Each ability uses `ChecksPermissions::allows()` to check (room_types.{action}, $user, $roomType). Add hotel scope check using `resolveHotel()` for context (admin gets own hotel; super admin must name one).
- [x] T008a Create audit logging integration in RoomTypeController: import EventLogger, dispatch mutations (create, update, soft delete) via `EventLogger` per existing audit system pattern. Document: "All create/update/delete operations log to audit trail with actor, timestamp, action type, and resource ID" (FR-010)
- [x] T009 [P] Create `app/Http/Requests/RoomType/CreateRequest.php` with validation rules per FR-006: name (string, required, max:255, unique:room_types,name,NULL,id,hotel_id,{hotel_id}), description (optional string), max_occupancy (required integer ≥1), adult_capacity (required integer ≥1), child_capacity (required integer ≥0), bed_configuration (optional JSON), amenities (optional JSON), base_price (required decimal ≥0). Add rule: adult_capacity + child_capacity ≤ max_occupancy.
- [x] T010 [P] Create `app/Http/Requests/RoomType/UpdateRequest.php` with same validation rules as CreateRequest but all fields optional (PATCH-like updates allowed).

**Checkpoint**: Foundation complete. User story implementation can now begin.

---

## Phase 3: User Story 1 - Hotel Admin Creates and Manages Room Types (Priority: P1) 🎯 MVP

**Goal**: Implement CRUD API endpoints for room types: create, list (with filtering/search/pagination), retrieve, update, soft delete.

**Independent Test**: Hotel admin can create 2 room types, list them (both visible), update one (price changes), deactivate one (still visible but marked inactive), attempt to delete (succeeds). All operations return correct HTTP status and response format.

### Tests for User Story 1

- [x] T011 [P] [US1] Create feature test `tests/Feature/RoomTypeControllerTest.php` with test methods: `test_create_room_type_success`, `test_create_room_type_validation_failure`, `test_list_room_types_paginated`, `test_get_single_room_type`, `test_update_room_type_fields`, `test_soft_delete_room_type`, `test_soft_deleted_not_in_list`
- [x] T012 [P] [US1] Add test: `test_create_with_invalid_capacity_sum()` — attempts to create with adult_capacity + child_capacity > max_occupancy, expects 422
- [x] T013 [P] [US1] Add test: `test_create_with_zero_adult_capacity()` — attempts to create with adult_capacity=0, expects validation error (FR-006)
- [x] T014 [P] [US1] Add test: `test_delete_blocked_by_active_rooms()` — attempts to delete room type with active rooms, expects 422 with message about active rooms

### Implementation for User Story 1

- [x] T015 [US1] Create `app/Http/Controllers/RoomTypeController.php` with methods: store (POST), index (GET), show (GET), update (PUT), destroy (DELETE). Use `authorize()` for policy checks. Use `apiResponse()` for all responses. Implement last-write-wins concurrency (no version field).
- [x] T016 [US1] Implement `store()` method: Accept CreateRequest, authorize via policy, create RoomType via model, return RoomTypeResource with 201 status code
- [x] T017 [US1] Implement `index()` method: Accept GenericIndexRequest, apply GenericQuery scopes (filtering, search, sort, pagination), return array of RoomTypeResource. Soft-deleted records ALWAYS excluded (no include_deleted filter).
- [x] T018 [US1] Implement `show()` method: Fetch room type by id, authorize, return RoomTypeResource or 404 if not found/wrong hotel
- [x] T019 [US1] Implement `update()` method: Accept UpdateRequest, authorize, update fields, return RoomTypeResource. Last-write-wins (no conflict detection).
- [x] T020 [US1] Implement `destroy()` method: Authorize, check if any active rooms reference this room type (count Room WHERE room_type_id = id AND deleted_at IS NULL), return 422 if found with message: "Cannot delete room type: active rooms still reference this type. Deactivate the room type instead, or delete/reassign the rooms first." Otherwise soft delete (Illuminate\Database\Eloquent\SoftDeletes::delete()) and return 200.
- [x] T021 [US1] Register routes in `routes/api.php`: POST /room-types, GET /room-types, GET /room-types/{id}, PUT /room-types/{id}, DELETE /room-types/{id}. Use middleware: auth:sanctum, throttle:api, tenant. Apply RoomTypePolicy::class as authorization gate.
- [x] T022 [US1] Add request validation method in CreateRequest to validate unique name per hotel using custom rule or inline closure per Laravel best practices

**Checkpoint**: User Story 1 complete. CRUD endpoints fully functional. Room types can be created, listed, updated, and soft deleted.

---

## Phase 4: User Story 2 - Room Types Drive Availability and Reservation Workflow (Priority: P1)

**Goal**: Ensure room type data model and API support availability calculations and reservation workflows (integration point for Phase 3 Availability service).

**Independent Test**: (1) Create room type with multiple active/inactive rooms linked. (2) Verify API returns room type details including is_active flag. (3) Deactivate room type and verify it still appears in GET endpoint but marked is_active=false. (4) Document how availability service will query room_type_id. (5) Verify soft-deleted room types do NOT appear in list (availability calculations skip them).

### Tests for User Story 2

- [x] T023 [P] [US2] Create test `tests/Feature/RoomTypeAvailabilityIntegrationTest.php` with test: `test_active_room_types_included_in_queries()`—creates room type with is_active=true, queries list, verifies it appears
- [x] T024 [P] [US2] Add test: `test_inactive_room_types_excluded_from_queries()`—creates room type with is_active=false (via update), verifies it appears in list but marked inactive, and availability queries (future Phase 3) will exclude it
- [x] T025 [P] [US2] Add test: `test_soft_deleted_room_types_never_in_queries()`—soft deletes room type, verifies it does NOT appear in list, and future availability service will never see it

### Implementation for User Story 2

- [x] T026 [US2] Document in `docs/room-types-api-documentation.md`: (1) API contract per contracts/room-types-api.md, (2) How availability service queries: SELECT COUNT(rooms) WHERE room_types.id = ? AND room_types.is_active = true AND rooms.deleted_at IS NULL AND <occupancy criteria>, (3) Example: "Availability counts active rooms of a type. Deactivated room types are excluded. Soft-deleted room types are never returned and excluded from all queries.", (4) List endpoints exclude soft-deleted records (no filter option to include them)
- [x] T027 [US2] Verify in RoomTypeResource that is_active field is always included in serialization (required for availability logic)
- [x] T028 [US2] Add database index (hotel_id, is_active) to room_types table for availability queries (should already be in T002, verify)
- [x] T029 [US2] Create fixture/seed: `database/seeders/RoomTypeSeeder.php` (or equivalent) that creates sample room types per hotel for manual testing. Used by `php artisan migrate --seed`.

**Checkpoint**: Room type data model fully supports availability queries. Phase 3 Availability service can query room types by is_active status.

---

## Phase 5: User Story 3 - Authorization: Only Authorized Staff Can Manage Room Types (Priority: P1)

**Goal**: Verify permission-based authorization works: staff without room_types.* permissions get 403, with permissions they succeed. Tenant isolation prevents cross-hotel access.

**Independent Test**: Two users (one with permission, one without) attempt each CRUD operation. Verify 403 for unauthorized, success for authorized. Two hotels attempt to access each other's room types; verify 403/404 for cross-hotel access.

### Tests for User Story 3

- [x] T030 [P] [US3] Create test `tests/Feature/PermissionAuthorizationTest.php` (or add to existing if it exists) with dataset row for room_types endpoints per CLAUDE.md procedure
- [x] T031 [P] [US3] Add test: `test_create_requires_room_types_create_permission()`—user without permission gets 403; user with permission creates successfully
- [x] T032 [P] [US3] Add test: `test_view_requires_room_types_view_permission()`—user without permission gets 403; user with permission lists room types
- [x] T033 [P] [US3] Add test: `test_update_requires_room_types_update_permission()`—user without permission gets 403; user with permission updates
- [x] T034 [P] [US3] Add test: `test_delete_requires_room_types_delete_permission()`—user without permission gets 403; user with permission deletes
- [x] T035 [US3] Create test `tests/Feature/TenantIsolationTest.php` (or add row to existing) with test: `test_hotel_a_cannot_access_hotel_b_room_types()`—Staff from Hotel A creates room type, staff from Hotel B attempts GET/PUT/DELETE on it, all return 403 or 404. Lists return only own hotel's types.
- [x] T036 [US3] Add test: `test_tenant_isolation_on_list_endpoint()`—Each hotel lists room types; only their own appear (even if staff has high permissions)

### Implementation for User Story 3

- [x] T037 [US3] Ensure RoomTypePolicy uses `ChecksPermissions::allows()` for all CRUD actions (should already be done in T008). Verify each action checks: (1) Permission enum case (room_types.create/view/update/delete), (2) Hotel scope via resolveHotel() + $roomType->hotel_id match
- [x] T038 [US3] Verify TenantContext middleware applied to routes in routes/api.php, filters queries to authenticated user's hotel. RoomType model has BelongsToHotel scope applied globally (T006).
- [x] T039 [US3] Test TenantContext resolution in controller: Admin gets their hotel, super admin must provide hotel_id in request. Document this in API docs.
- [x] T040 [US3] Run Pest tests for authorization and isolation; all must pass green before marking complete. Report coverage: permission checks on all endpoints, isolation tests for cross-hotel access.

**Checkpoint**: All three user stories complete and tested. Room Types is fully functional, authorized, and tenant-isolated.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Documentation, validation, and final quality checks

- [x] T041 [P] Update `docs/staff-roles-api-documentation.md` to add room_types.* permissions to the permission reference table (per CLAUDE.md procedure). File should exist in docs/; if not present, create it with permission reference section including room_types.create, .view, .update, .delete with descriptions per Phase 1 permissions
- [x] T042 [P] Update `docs/latest-changes-2026-09.md` if this is a breaking change or significant feature. Document: New room_types table, new permissions, migration from room_type enum to room_type_id FK, new API endpoints.
- [x] T043 [P] Run `./vendor/bin/pint` to format code per Laravel preset. Commit formatter changes.
- [x] T044 [P] Run `php artisan test --filter=RoomType` to verify all RoomType tests pass. Ensure coverage includes authorization, isolation, validation, soft deletion, edge cases.
- [x] T045 Run quickstart.md validation: Execute all 8 test scenarios manually or via script. Verify: create, list, update, deactivate, authorization errors, tenant isolation, soft deletion, validation errors. Document results.
- [x] T046 Code review: Verify no implementation details leaked into API responses, all responses use `apiResponse()` and Resource serialization, tenant scope enforced on all queries, policies protect all endpoints, SoftDeletes trait used correctly.
- [x] T046a Performance verification: Measure CRUD operation response times (create, list, get, update, delete). Verify all complete in <500ms per SC-001. Test with typical payloads (5 room types per hotel, standard hotel context). Document results in test output or add to quickstart.md if >500ms detected.
- [x] T047 [P] Database review: Verify indexes created (hotel_id, hotel_id+deleted_at, is_active, hotel_id+is_active), constraints enforced (unique names per hotel, capacity validation), foreign keys correctly set up, migrations run cleanly on fresh install
- [x] T048 Commit with message per CLAUDE.md: "feat: Add room types inventory management (Phase 1)" including Co-Authored-By line

**Checkpoint**: Room Types feature complete, tested, documented, and ready for integration with Phase 2–3 features.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies. Start immediately.
- **Foundational (Phase 2)**: Depends on Setup (migrations must exist, permissions must be added to enum)
- **User Stories (Phases 3–5)**: All depend on Foundational completion
- **Polish (Phase 6)**: Depends on all user stories being complete

### User Story Dependencies

- **US1 (CRUD)**: Can start after Foundational. No dependencies on US2 or US3.
- **US2 (Availability)**: Can start after Foundational. No dependencies on US1 or US3; supplements US1 documentation.
- **US3 (Authorization)**: Can start after Foundational. Tests depend on US1 endpoints existing.

### Within Each Phase

- Setup: Tasks can run sequentially (each migration depends on schema from previous)
- Foundational: Tasks marked [P] can run in parallel (model, resource, policy, requests)
- US1: Tests written first (all marked [P]), then implementation (endpoints T015–T021 in order)
- US2: Tests first [P], then documentation (T026–T029)
- US3: Tests first [P], then policy verification (T037–T040)
- Polish: Most tasks [P] can run in parallel; final commit/review sequential

### Parallel Opportunities

**Setup Phase**: Migrations sequential (T001 → T002 → T003 → T004 → T005)

**Foundational Phase**: Tasks T006, T007, T009, T010 marked [P] can run together:
- T006: Model (in parallel with...)
- T007: Resource (in parallel with...)
- T009: CreateRequest (in parallel with...)
- T010: UpdateRequest (in parallel with...)
- T008: Policy (depends on T006 Model, must run after)

**User Story 1 Phase**: 
- Tests T011–T014 marked [P] can run together (write all tests simultaneously)
- Implementation T015–T022 depends on tests being written (TDD approach), run sequentially within story

**User Story 2 Phase**:
- Tests T023–T025 marked [P] can run together
- Implementation T026–T029 run after tests

**User Story 3 Phase**:
- Tests T030–T036 marked [P] can run together
- Implementation T037–T040 run after tests and implementations above

**Polish Phase**:
- Documentation/formatting tasks marked [P] can run in parallel
- Final code review/commit sequential

---

## Parallel Example: Foundational Phase

```bash
# Developer A: Model + Resource
Task T006: Create RoomType model
Task T007: Create RoomTypeResource

# Developer B: Requests
Task T009: Create CreateRequest
Task T010: Create UpdateRequest

# Developer C: Policy (after T006 completes)
Task T008: Create RoomTypePolicy (depends on T006)

# All developers: Commit and integrate
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. ✅ Phase 1: Setup (migrations, permissions)
2. ✅ Phase 2: Foundational (model, policy, validation)
3. ✅ Phase 3: User Story 1 (CRUD endpoints)
4. ✅ Phase 5: User Story 3 (authorization tests)
5. **STOP and VALIDATE**: Run Pest suite, run quickstart.md scenarios
6. Deploy User Story 1 MVP ✅

### Full Feature Delivery (All User Stories)

After MVP:
7. Phase 4: User Story 2 (availability integration documentation)
8. Phase 6: Polish (docs, code review, final commit)
9. Merge to main and deploy

### Incremental Delivery Strategy

- **After US1**: Core CRUD works. Hotel admins can create room types. Ship MVP.
- **After US2**: Integration docs ready. Phase 3 (Availability) can reference. Ship Phase 1.
- **After US3**: Authorization fully tested. Staff roles feature is ready. Finalize Phase 1.

---

## Notes

- [P] tasks = independent, can run in parallel within phase
- [Story] label maps task to specific user story for traceability
- Each user story independently testable and completable
- Tests written first (TDD approach) for US1, US2, US3
- All file paths absolute and unambiguous (no `./` or relative references in task descriptions)
- Database constraints enforced at schema level; validation at request level
- Soft deletion via SoftDeletes trait; no hard deletions in this feature
- Last-write-wins concurrency (no version field or conflict detection per clarification)
- Authorization via policies; tenant scope via BelongsToHotel + TenantContext
- Currency derived from hotel; room types don't store currency field
- Run `php artisan test` after each phase to validate
- Run `./vendor/bin/pint` before commit
- Commit after each completed phase (or after each user story if preferred)

---

## Quality Checkpoints

- **After T005**: Database schema ready; `php artisan migrate --seed` succeeds
- **After T010**: Foundational complete; no endpoint work yet, but models/policies/validation ready
- **After T022**: US1 complete; all CRUD endpoints work; tests passing
- **After T029**: US2 complete; availability integration documented
- **After T040**: US3 complete; authorization fully tested
- **After T048**: Polish complete; feature ready for merge

---
