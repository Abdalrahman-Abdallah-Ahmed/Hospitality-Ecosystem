# Implementation Plan: Super Admins Lose Direct Hotel Access

**Branch**: `028-superadmin-hotel-lockout` | **Date**: 2026-11-09 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/028-superadmin-hotel-lockout/spec.md`. Pre-work and plan-vs-code mismatches: [plan-verification.md](plan-verification.md).

## Summary

A `no_super_admin` middleware on the tenant group in `routes/api.php` returns 403 to a super admin's own token before route-model binding; `POST /logout` and `GET /user` opt out. The 27 policy `before()` bypasses and every super admin branch that serves only hotel routes are removed, including `resolveHotel()`'s `hotel_id` parameter and the super-admin-only `hotel_id` inputs it fed. Three superadmin endpoints replace what the frontend still did through hotel routes: per-hotel staff role and team lists, and a user delete that refuses owners with 409. A generic test walks every hotel route as a super admin. 29 test files are rewritten, and the docs say a super admin reaches a hotel only through impersonation.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, Sanctum, Pest 4

**Storage**: PostgreSQL + pgvector. No migration.

**Testing**: Pest feature tests on the real Postgres container, `php artisan test --parallel`

**Target Platform**: Linux server, JSON API

**Project Type**: Web service (backend only; the frontend follows from a changes doc)

**Performance Goals**: The block is one in-memory role check per request, before any query. No measurable change.

**Constraints**: `accessibleHotelIds()`, `permissions()`, `EnsureSuperAdmin` and the tenant scope do not change. Super admin controllers keep working without edits beyond the three new actions.

**Scale/Scope**: Over 100 hotel route/method pairs; 54 `app/` files and 55 test files reference a super admin; about 30 API docs.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Status | Notes |
| --- | --- | --- |
| III. Tenant Isolation | **Pass** | Removes the only identity that crossed hotels on hotel routes. The null vs `[]` rule is untouched (`accessibleHotelIds()` unchanged). The new lists are nested under one hotel and use an explicit `where('hotel_id')`; isolation tests are planned (FR-018) |
| IV. Permission-Based Authorization | **Pass (v3.0.0)** | Constitution 3.0.0 (2026-11-09) requires this feature: a super admin's own credentials must not reach hotel endpoints, no code may grant them a bypass on hotel data, and they reach a hotel only through audited impersonation. The hotel admin's full access is unchanged. Super admin platform access stays on `routes/superadmin.php`. No new `Permission` cases: users, staff roles and hotel settings stay admin-only, and the new endpoints sit behind the `super_admin` guard, as CLAUDE.md requires for that group |
| V. AI through tools | **Pass** | `GuardedTool`, `GetStaffTool`, `GetReportTool` lose their super admin branches; the advisor is unreachable for a super admin's own token |
| VI. Auditability | **Pass** | The new delete records `platform.user.deleted` through `recordPlatform()`. Impersonation, already audited, becomes the only way into a hotel |
| VII. Data Integrity | **Pass** | The delete runs the owner check, event, token revocation and row delete in one transaction |
| VIII. Tested at the Domain Boundary | **Pass** | Generic walk, middleware inventory, isolation tests for the new lists, delete tests, 29 rewritten files |
| IX. Spec-Driven | **Pass** | Spec, clarifications and plan precede code |

No unjustified violations. Complexity Tracking is empty.

**Post-design re-check**: unchanged. The design adds no schema, no permission, and no tenant-facing endpoint.

## Project Structure

### Documentation (this feature)

```text
specs/028-superadmin-hotel-lockout/
├── spec.md
├── plan-verification.md   # pre-work: Open Question, inventory, frontend calls, mismatches
├── plan.md                # this file
├── research.md            # R1–R9 decisions
├── data-model.md          # no schema change; rules and the new event
├── quickstart.md
├── contracts/api.md       # changed and new endpoints, frontend call map
└── checklists/requirements.md
```

### Source Code

```text
app/Http/Middleware/DenySuperAdmin.php                      # new (no_super_admin)
bootstrap/app.php                                           # alias + priority
routes/api.php                                              # group + 2 exemptions
routes/superadmin.php                                       # 3 routes
app/Http/Controllers/Superadmin/HotelStaffRoleController.php   # new
app/Http/Controllers/Superadmin/HotelTeamController.php        # new
app/Http/Controllers/Superadmin/UserController.php          # + destroy
app/Services/Superadmin/UserAdministration.php              # + delete, OWNER_DELETE_REFUSAL
app/Helpers/Helper.php                                      # resolveHotel(User): ?Hotel
app/Policies/*.php                                          # 27 before() removed
app/… (inventory below)                                     # branch removals
tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php        # new
tests/Feature/Superadmin/HotelAccessOptionsTest.php         # new
tests/Feature/Superadmin/UserAdministrationTest.php         # + delete cases
tests/Pest.php                                              # impersonationTokenFor()
tests/Feature/… (inventory below)                           # rewrites
docs/… (list below), CLAUDE.md, docs/latest-changes-<date>.md
```

**Structure Decision**: The existing Laravel layout. New super admin code goes under `Superadmin\` namespaces and `routes/superadmin.php`, so the `super_admin` guard covers it by construction.

## Implementation Design

### 1. The block (FR-001–FR-005; research R1, R2)

- `DenySuperAdmin::handle()`: `if ($request->user()?->isSuperAdmin()) return apiResponse(self::REFUSAL, 403);`, with `REFUSAL = 'Super admins use /api/superadmin.'`.
- `bootstrap/app.php`: alias `'no_super_admin' => DenySuperAdmin::class`; `prependToPriorityList(SubstituteBindings::class, DenySuperAdmin::class)`.
- `routes/api.php`: add `no_super_admin` to the tenant group's middleware array; `->withoutMiddleware(['not_suspended', 'hotel_choice', 'no_super_admin'])` on `/logout`; `->withoutMiddleware('no_super_admin')` on `GET /user`. Update the group comment.

### 2. Bypass removal (FR-006–FR-011) — inventory, one decision per file

Re-run of the search at `2e18350` (plan-verification Task 2, with the decisions confirmed here).

| File | Decision |
| --- | --- |
| 27 policies: `ActivityCategory`, `Activity`, `AiInsights`, `Booking`, `Conversation`, `EventLog`, `GuestMemory`, `Guest`, `Hotel`, `HotelPolicy`, `KnowledgeBaseArticle`, `KnowledgeDocument`, `ProactiveMessage`, `Recommendation`, `ReservationImport`, `ReservationImportPreview`, `Reservation`, `Room`, `RoomType`, `StaffRole`, `Stay`, `TaskCategory`, `Task`, `Team`, `Transaction`, `User`, `VoucherTemplate` | **Remove** the `before()` method (each holds only the bypass) |
| `UserPolicy::managesUser` (`! $model->isSuperAdmin()`) | **Keep** |
| `Helpers/Helper.php` `resolveHotel()` | **Remove** the branch and the `$requestedHotelId` parameter (R3) |
| Callers of `resolveHotel()`: `ActivityCategoryController`, `ActivityController`, `AiActivityController`, `AvailabilityController`, `Concerns/ExportsLists`, `ConversationController`, `GuestController`, `HotelPolicyController`, `HousekeepingBoardController`, `KnowledgeBaseArticleController`, `KnowledgeDocumentController`, `MaintenanceTaskController`, `ReservationController`, `ReservationImportPreviewController`, `RoomController`, `RoomTypeController`, `StaffRoleController`, `StayController`, `TaskCategoryController`, `TaskController`, `TeamController`, `VoucherTemplateController`, `Requests/StaffRoleRequest`, `Requests/Vouchers/StoreVoucherTemplateRequest` | **Modify**: drop the second argument; delete any "super admin must name the hotel" path (for example `ConversationController`'s `abort(422, 'Choose a hotel…')` becomes the normal no-hotel answer) |
| Super-admin-only `hotel_id` rules: `AiActivityIndexRequest`, `AvailabilityRequest`, `Concerns/ValidatesExportFormat`, `ConversationIndexRequest`, `HousekeepingBoardRequest`, `ImportReservationsRequest`, `StaffRoleRequest`, `StayDayRequest`, `StoreReservationImportPreviewRequest`, `UnassignedArrivalsRequest`, `Vouchers/StoreVoucherTemplateRequest` | **Remove** the rule and its comment. `Knowledge/StoreKnowledgeDocumentRequest` and `Knowledge/ReplaceKnowledgeDocumentFileRequest`: remove only if `Superadmin\KnowledgeDocumentController` does not read `hotel_id`. `Knowledge/StartKnowledgeRebuildRequest`: **keep** (superadmin) |
| `Controllers/UserController.php` (tenant) | **Remove** the caller-is-super-admin branches (index line 41, store line 75, update lines 131, 136, the super-admin-target block at 140, `refusesAdminGrant` line 240). **Keep** `refusesSuperAdminGrant` (lines 66, 122) |
| `Controllers/AccountUsageController.php` | **Remove** `! $user->isSuperAdmin()` |
| `Controllers/AiActivityController.php` | **Remove** the branch and `attachRunCosts()` |
| `Controllers/KnowledgeBaseArticleController.php`, `KnowledgeDocumentController.php` (tenant) | **Remove** the branches; update the comment at line 109 |
| `Ai/Tools/Admin/GuardedTool.php`, `Ai/Tools/GetReportTool.php`, `Ai/Tools/GetStaffTool.php` | **Remove** `|| $user->isSuperAdmin()` |
| `Resources/AiActivityResource.php` | **Remove** the super admin `mergeWhen` (run cost) |
| `Requests/HotelListRequest.php` | **Remove** `|| $user->isSuperAdmin()` |
| `Resources/EventLogResource.php` | **Remove** the caller clause; **keep** the actor clause |
| `Resources/KnowledgeDocumentResource.php` | **Keep**: the superadmin controller renders it |
| `Auth/AuthenticatedSessionController.php` | **Keep**: login skips the suspension check for a super admin |
| `Models/User.php` (`permissions`, `accessibleHotelIds`, `isSuperAdmin`) | **Keep** |
| `Middleware/EnsureSuperAdmin.php`, `Enums/UserRole.php`, `Models/HotelGroup.php`, `Services/Reports/PlatformSummary.php` | **Keep** |
| `Superadmin/UserController.php`, `Superadmin/ImpersonationController.php`, `Services/Superadmin/UserAdministration.php`, `Services/Superadmin/Impersonation.php`, `Requests/Superadmin/StoreUserRequest.php`, `Requests/Superadmin/UpdateUserAccessRequest.php` | **Keep** |
| `Middleware/UseChosenHotel.php`, `Middleware/EnsureNotSuspended.php` null-scope branches; `DashboardController:51`; `HotelController:126` | **Keep** (R8) |

**Nothing moves** between stacks.

### 3. New super admin endpoints (FR-012, FR-014; research R4, R5)

- `routes/superadmin.php`:
  - `Route::get('/hotels/{hotel}/staff-roles', [HotelStaffRoleController::class, 'index']);`
  - `Route::get('/hotels/{hotel}/teams', [HotelTeamController::class, 'index']);`
  - `Route::delete('/users/{user}', [UserController::class, 'destroy']);`
- `HotelStaffRoleController::index(GenericIndexRequest $request, Hotel $hotel)`: `GenericQuery::apply(StaffRole::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->withCount('users'), $request)` → `StaffRoleResource::collection`. Check `users_count` under `withoutScope` (User is not hotel-scoped, so it counts correctly).
- `HotelTeamController::index`: the same over `Team::withoutGlobalScope('hotel')->with('hotel')` → `TeamResource::collection`.
- `UserController::destroy(User $user)`: `$this->users->delete($this->administrable($user))` → `apiResponse('User deleted successfully.', 200)`. `DomainRuleException` renders as today.
- `UserAdministration::delete(User $user)`: R5.

### 4. Tests (FR-016–FR-018) — one decision per file

New:

- `tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php`: R7.
- `tests/Feature/Superadmin/HotelAccessOptionsTest.php`: both lists return only the named hotel's records (two hotels, one per account); paging, search, sort; 404 for unknown and soft-deleted hotel; 403 for an admin and for an impersonation token; soft-deleted staff roles not listed.
- `tests/Feature/Superadmin/UserAdministrationTest.php` (add): delete success (row gone, tokens gone, seat count updated, `platform.user.deleted` with snapshot and `hotel_id = null`); super admin target 404; owner 409 and unchanged, then success after `group_role: null`; admin caller 403; an impersonation session for the deleted user stops working (401).
- `tests/Pest.php`: `impersonationTokenFor()` (R6); `ImpersonationTest` switches to it.

Existing, from the 55 files that mention a super admin:

| File | Decision |
| --- | --- |
| `AccessLevelSeederTest`, `AccountUsageAiBlockTest`, `AiCostAttributionTest`, `AiCostCapabilityTest`, `DeletedHotelAccessTest`, `FreshInstallTest`, `KnowledgeDocumentVisionTest`, `OperationalDefaultsTest`, `SuspensionAdministrationTest`, `SuspensionJobTest`, `SuspensionModelTest`, `SuspensionRouteInventoryTest`, `UsageMeteringTest`, `CrossTenantRouteTest`, `tests/Support/EntryPointDiscovery.php` | **Keep**: superadmin stack, seeding, model or scope checks only |
| `Superadmin/AccessGuardTest`, `AccountAdministrationTest`, `DashboardTest`, `GuestMemoryExtractionSuperadminTest`, `HotelAdministrationTest`, `KnowledgeRebuildTest`, `PlatformAuditTest`, `RouteMoveTest`, `UserAdministrationTest`, `GlobalKnowledgeTest` | **Keep** (its hotel-route calls use a hotel admin) |
| `TenantIsolationTest` | **Keep** "leaves a super admin unrestricted" (scope unchanged); check the other two mentions |
| `tests/Pest.php` | **Keep** `knSuperAdmin`, `crossTenantRuleFor`; **add** `impersonationTokenFor` |
| `Superadmin/ImpersonationTest` | **Modify**: "shows a super admin the full row" (line 770) becomes a 403 assertion on `/api/history` for the super admin's own token; use `impersonationTokenFor` |
| `AccountOwnershipTest` | **Modify** line 142: clear the owner role, then `DELETE /api/superadmin/users/{owner}` |
| `CostVisibilityTest` | **Modify**: drop the `AiActivityController` exemption (line 315) |
| `CreateUserTest` (41 mentions) | **Modify**: super admin index/create/update cases on `/api/users` become 403 assertions; behaviours still needed (refusing the `super_admin` role, owner role changes) move to an admin or to `Superadmin/UserAdministrationTest`, where most already exist |
| `KnowledgeBaseArticleControllerTest` | **Modify**: line 79 → 403; line 104 rewritten with a hotel admin as "keeps global articles out of reach on the hotel routes"; **update** `tests/Inventory/entry_points.php` `global_data` to the new name |
| `TransactionsFeatureFlagTest` | **Modify**: drop `'super admin'` from the dataset (line 91); the generic walk covers it |
| `AiActivityApiTest`, `AiAdvisorControllerTest`, `AvailabilityControllerTest`, `ConversationViewerTest`, `DashboardCacheTest`, `DashboardControllerTest`, `ExportControllerTest`, `GuestControllerTest`, `HotelControllerTest`, `HotelPolicyControllerTest`, `HousekeepingBoardTest`, `KnowledgeDocumentControllerTest`, `MultiHotelAccessTest`, `ReservationControllerTest`, `ReservationImportPreviewTest`, `ReservationImportTest`, `RoomControllerTest`, `StayListTest`, `SuspensionTest`, `VoucherTemplateControllerTest` | **Become 403 assertions**: each "super admin sees/changes any hotel" or "super admin names the hotel" case asserts 403 for the super admin's own token. Where a case also proves something for hotel users (`RoomControllerTest` room-type picker, `DashboardCacheTest` cache key, `SuspensionTest` login), that part keeps an admin |
| `FilterableTest`, `GenericCrudTest`, `GenericIndexFilterTypesTest` | **Move to admin**: they test filtering and listing, not the super admin |

### 5. Documentation (FR-019–FR-021)

- `CLAUDE.md`, Tenancy: a super admin's own token never reaches a tenant route (`no_super_admin`); `hotelIds() === null` still means unrestricted, and for a super admin it now matters only on the superadmin stack, `GET /user` and logout. Access control: "`admin` and `super_admin` hold every permission" becomes: admins hold every permission; a super admin's list is reported by login and `GET /user` but used nowhere, because a super admin never reaches a hotel route. Remove "(super admin must name one)" from the `resolveHotel()` helper line. Add `no_super_admin` to the request-flow line.
- `docs/dashboard-api-documentation.md` (lines 10, 34, 186, 208) and `docs/staff-roles-api-documentation.md` (lines 11, 25, 187, 272, 293, 300).
- Sweep the other tenant API docs that mention a super admin: `user-management`, `hotel`, `task-management`, `knowledge-base-article`, `activity`, `hotel-policy`, `reservations`, `history`, `guest-memories`, `whatsapp-device`, `conversations`, `ai-insights`, `usage-metering` (tenant part), `knowledge-document`, `guest`, `auth`, `ai-advisor-chat`, `ai-activity`, `voucher-templates`, `transaction`, `room`, `reservation-recommendations`, `availability`, `stays`, `room-types`, `proactive-messages`, `conversion-analytics`.
- `docs/superadmin-accounts-api-documentation.md`: the three new endpoints. `docs/superadmin-impersonation-api-documentation.md`: impersonation is now the only way into a hotel.
- `docs/latest-changes-<date>.md`: the breaking change, the removed `hotel_id` input, the lost super-admin-only detail (AI run cost, full history actor), and the frontend call map from [contracts/api.md](contracts/api.md).
- `docs/designs/super-admin-platform.md`: mark the Open Question settled (FR-021).

### 6. Order of work

1. Pre-work is done (plan-verification.md).
2. `impersonationTokenFor()` and the new generic test, which fails at first (red).
3. Middleware, alias, priority, routes → generic test green; many old tests now fail.
4. New superadmin endpoints and their tests.
5. Bypass removal per the inventory.
6. Test rewrites per the inventory, then the full suite.
7. Docs, Pint, full suite.

## Complexity Tracking

None.
