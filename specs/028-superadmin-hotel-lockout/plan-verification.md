# Stage 6 Plan Verification

**Feature**: [spec.md](spec.md) · **Checked**: 2026-11-09 against `main` at `2e18350` (backend) and `fcd1f08` (frontend, `D:\ecosystem-frontend`)

The design's Stage 6 plan (`docs/designs/super-admin-platform.md`, lines 1480–1574) was written before stages 2–5 and spec 025 were built. This file records the three pre-work tasks the request asked for, and every point where the plan and the code disagree. Requirements tagged **[V1]**–**[V12]** in the spec point here.

## Task 1: Open Question — do the moved super admin controllers rely on the null tenant scope?

**Result: none rely on it implicitly. The Open Question is settled.** Each controller either reads models that carry no `hotel` scope, or opts out explicitly. Because `accessibleHotelIds()` keeps returning `null` for super admins (FR-009), none of them changes behaviour in this stage either way.

| Controller (`app/Http/Controllers/Superadmin/`) | What it reads | Scope reliance |
| --- | --- | --- |
| `AccountController` | `HotelGroup`, user counts | None: models are not hotel-scoped |
| `HotelController` | `Hotel`; writes through `HotelAdministration` | None: `Hotel` is not hotel-scoped; the service uses `TenantContext::withoutScope()` |
| `UserController` | `User` | Explicit `withoutScope()` in `index` and `resource()` |
| `ImpersonationController` | `User` through route binding; `Impersonation` service | None |
| `DashboardController` | `PlatformSummary` | Explicit `withoutScope()` in every section |
| `UsageController` | `MeterEvent`, `UsageCounter`, `HotelGroup` | None: not hotel-scoped |
| `AiCostController` | `AiUsageLog`, `HotelGroup` | None: not hotel-scoped |
| `GuestMemoryExtractionController` | `GuestMemoryExtraction` | Explicit `withoutGlobalScope('hotel')` |
| `KnowledgeDocumentController` | `KnowledgeDocumentService::findForScope(null, …)` | Explicit `withoutGlobalScope('hotel')`; `hotel_id` set explicitly on create |
| `KnowledgeBaseArticleController` | `KnowledgeBaseArticle` | Explicit `withoutGlobalScope('hotel')`; clears the current hotel around create |
| `KnowledgeRebuildController` | `KnowledgeIndexRebuild`; `KnowledgeRebuildService` | None. **[V1]** The design says this controller "is known to" rely on the null scope. It no longer does: `KnowledgeRebuildService::start()` wraps its work in `withoutScope()`, and `KnowledgeIndexRebuild` is not hotel-scoped |

No superadmin controller, request or service calls a policy (`authorize`, `Gate`, `->can()`), so removing the policy `before()` bypasses cannot affect them.

## Task 2: `isSuperAdmin` / `SUPER_ADMIN` inventory

### `app/` — one decision per file

Search: `isSuperAdmin|SUPER_ADMIN|'super_admin'` over `app/`. 54 files. The 27 policies are grouped in the first row.

| File | Decision | Reason |
| --- | --- | --- |
| 27 policies with a `before()` bypass (`ActivityCategoryPolicy` … `VoucherTemplatePolicy`, the list in plan 6.3) | **Remove** the `before()` bypass | Matches plan 6.3 exactly (27 files, one `before()` each) |
| `app/Policies/UserPolicy.php` (`managesUser`, `! $model->isSuperAdmin()`) | **Keep** | An admin must still never manage a super admin |
| `app/Helpers/Helper.php` (`resolveHotel`) | **Remove** the super admin branch | Only tenant routes call it |
| `app/Http/Controllers/UserController.php` (tenant) | **Remove** the branches at lines 41, 75, 131, 136, 140 (block) and 240; **keep** the `refusesSuperAdminGrant` checks (lines 66, 122) | Caller-is-super-admin branches are dead after the edge block. The block at line 140 is for a super admin *target*, which only a super admin could reach (an admin is refused by `UserPolicy::managesUser`), so it is dead too. Granting the `super_admin` role stays refused for everyone. See [V7] for `destroy` |
| `app/Http/Controllers/AccountUsageController.php` | **Remove** | Tenant `GET /api/usage`; a super admin uses `/api/superadmin/usage` |
| `app/Http/Controllers/AiActivityController.php` | **Remove** (with `attachRunCosts` for super admins) | Tenant route only |
| `app/Http/Controllers/KnowledgeBaseArticleController.php` (tenant) | **Remove** | Tenant route only |
| `app/Http/Controllers/KnowledgeDocumentController.php` (tenant) | **Remove** | Tenant route only |
| `app/Ai/Tools/Admin/GuardedTool.php` | **Remove** | Reached only through the tenant AI advisor |
| `app/Ai/Tools/GetReportTool.php` | **Remove** | Same |
| `app/Ai/Tools/GetStaffTool.php` | **Remove** | Same |
| `app/Http/Resources/AiActivityResource.php` | **Remove** | Rendered only by the tenant `AiActivityController` |
| `app/Http/Requests/HotelListRequest.php` **[V2]** | **Remove** the super admin half of `showsFullRecords()` | Used only by the tenant `HotelController::index`. Not in plan 6.4 (added by spec 025) |
| `app/Http/Resources/EventLogResource.php` **[V2]** | **Remove** the caller clause (`$request->user()?->isSuperAdmin()`); **keep** the actor clause (`$this->actor->isSuperAdmin()`) | Rendered only by the tenant `HistoryController`. The actor clause hides a super admin's details from hotel staff (spec 027 FR-019c) and must stay. Not in plan 6.4 |
| `app/Http/Resources/KnowledgeDocumentResource.php` | **Keep** | The superadmin knowledge controller renders it (matches plan 6.4) |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` **[V2]** | **Keep** | Login: a super admin skips the suspension refusal. Not a tenant route. Not in plan 6.4 |
| `app/Models/User.php` (`permissions()`, `accessibleHotelIds()`, `isSuperAdmin()`) | **Keep** | FR-009, FR-010 |
| `app/Http/Middleware/EnsureSuperAdmin.php` | **Keep** | FR-011 |
| `app/Enums/UserRole.php` | **Keep** | The role itself |
| `app/Models/HotelGroup.php`, `app/Services/Reports/PlatformSummary.php` | **Keep** | Exclude super admins from platform counts |
| `app/Http/Controllers/Superadmin/UserController.php`, `ImpersonationController.php`, `app/Services/Superadmin/UserAdministration.php`, `Impersonation.php`, `app/Http/Requests/Superadmin/StoreUserRequest.php`, `UpdateUserAccessRequest.php` **[V2]** | **Keep** | Superadmin stack: refuse a super admin target or the `super_admin` role. Not in plan 6.4 |

**Not found by the search, but relevant [V3]**: `UseChosenHotel` and `EnsureNotSuspended` branch on `TenantContext::hotelIds() === null` ("a super admin's null scope"). **Keep** both: `GET /api/user` runs through `not_suspended`, and `POST /api/logout` still runs `tenant`, so a super admin still reaches them with a null scope.

**Nothing to move.** No branch serves both stacks in a way that needs relocating.

### `tests/` — files to classify in the plan

Search: `superAdmin|super_admin|SUPER_ADMIN|isSuperAdmin` over `tests/`. **55 files** (52 in `tests/Feature`, plus `tests/Pest.php` and `tests/Support/EntryPointDiscovery.php`), against 27 in plan 6.5 **[V4]**. The plan must record one decision per file: *keep* (already tests the superadmin stack), *move to admin*, *move to impersonation token*, or *becomes a 403 assertion*.

- **Probably keep** (superadmin stack only; 11 under `tests/Feature/Superadmin/` plus `AccessLevelSeederTest`, `AccountOwnershipTest`, `AiCostAttributionTest`, `AiCostCapabilityTest`, `SuspensionAdministrationTest`, `SuspensionJobTest`, `UsageMeteringTest`, `KnowledgeDocumentVisionTest`): each must still be read, since several also hit tenant routes (`AccountOwnershipTest` has 28 mentions, 17 of `api/superadmin`).
- **In plan 6.5**: the 27 listed.
- **Not in plan 6.5, mentions a super admin on tenant paths**: `AccountUsageAiBlockTest`, `CrossTenantRouteTest`, `DeletedHotelAccessTest`, `GenericIndexFilterTypesTest`, `MultiHotelAccessTest`, `SuspensionModelTest`, `SuspensionRouteInventoryTest`, `SuspensionTest`, `tests/Support/EntryPointDiscovery.php`.
- `tests/Inventory/entry_points.php` cites test names (for example "does not let a super admin read, change or delete a global article through the hotel routes"); renamed tests must be updated there.

## Task 3: tenant endpoints the frontend still calls as a super admin

Checked in `D:\ecosystem-frontend` (pages under `app/`, components and services under `src/`).

| Endpoint | Where (frontend) | Super admin need | Decision |
| --- | --- | --- | --- |
| `GET /api/staff-roles?filter[hotel_id]=` | `src/components/users/UserAccessModal.tsx:126` (Users page → Manage access) | Pick a role to assign through `PUT /api/superadmin/users/{user}/access` | **Needs a super admin read endpoint** (FR-012) |
| `GET /api/team?filter[hotel_id]=` | `src/components/users/UserAccessModal.tsx:155` | Pick a team to assign through the same endpoint | **Needs a super admin read endpoint** (FR-012) |
| `DELETE /api/users/{id}` **[V7]** | `app/users/page.tsx:232` → `userService.remove` (delete button shown to super admins, `manageBlockedReason` returns null for them) | Delete a user. There is no `DELETE /api/superadmin/users/{user}`; an impersonation token is refused (`not_impersonating`) | **Needs a super admin delete endpoint** (FR-014, clarified 2026-11-09): `DELETE /api/superadmin/users/{user}`, as the design's Stage 6 note requires |
| `GET /api/users` | `src/components/teams/TeamModal.tsx:128`, `src/components/tasks/TaskModal.tsx:185` | Only from the Teams and Tasks screens, which are hotel screens | **Not needed**: a super admin reaches them through impersonation, with the user's own token |
| `GET /api/team`, `GET /api/staff-roles` (unfiltered) | `UserModal.tsx:203, 228`, `TaskModal`, `TaskCategoryModal` | `UserModal` renders `AdministeredUserForm` for a super admin and never reaches these calls | **Not needed** |
| `GET /api/permissions` | `src/store/staffRoleStore.ts:82` (Staff Roles screen) | Staff role definitions are edited only by a hotel's admins (design Non-Goal); `UserAccessModal` does not call it | **Not needed** |
| `POST /api/connect`, `GET /api/check-paired`, `GET`/`DELETE /api/whatsapp-device` | `app/settings/page.tsx` → Integrations tab → `WhatsAppStatus`, shown to every role | A device belongs to the user's home hotel; a super admin has none | **Not needed**; these return 403 to a super admin |
| Hotel screens (Dashboard is already hidden; Reservations, Guests, Rooms, Tasks, Reports, Settings → Hotel, etc.) | `src/components/layout/navigation.ts` gates most items on `hasPermission`, and `permissions()` gives a super admin every permission | Reached through impersonation | **Not needed**; the frontend must hide them (changes doc) |

## Other mismatches between the plan and the code

- **[V5]** Plan 6.2 says the middleware "nests all routes that are under `not_suspended`, except `GET /user`". `POST /logout` is in the tenant group but drops `not_suspended`, and `/connect`, `/check-paired` and `/whatsapp-device` drop `hotel_choice`. The block applies to the whole tenant group with `/logout` and `GET /user` exempt, whatever other middleware a route drops.
- **[V6]** The generic test must walk only the routes in `routes/api.php`. `routes/superadmin.php` is also behind `auth:sanctum`, so a walker that reads the whole route table must exclude the `api/superadmin` prefix.
- **[V7]** `DELETE /api/users/{id}` — see Task 3.
- **[V8]** Plan 6.8 names three docs. Other tenant docs also describe super admin behaviour on hotel endpoints, for example `docs/staff-roles-api-documentation.md:187, 272, 293, 300` ("a super admin sees all", "A super admin must send it") and `docs/dashboard-api-documentation.md:34, 186, 208`. Every `docs/*-api-documentation.md` for a tenant resource needs the same sweep.
- **[V9]** `CLAUDE.md` says `hotelIds() === null` means "unrestricted (super admin, or no context at all)". That stays true, but the Tenancy section must add that a super admin's own token never reaches a tenant route, and Access control's "`admin` and `super_admin` hold every permission" must say the super admin's set is reported by `GET /user` but used only by the superadmin stack.
- **[V10]** Plan 6.3 says the superadmin knowledge controllers do not authorize through the policies. Confirmed, and true of every superadmin controller (Task 1).
- **[V11]** The design's Open Question is resolved by Task 1 and can be closed in the design doc.
- **[V12]** The plan's numbering skips 6.7. No content is missing; noted only so nobody looks for it.
- **[V13]** (found while planning) The `isSuperAdmin` search finds `resolveHotel()` but not what depends on it: its callers and the form-request rules that accept a `hotel_id` only so a super admin can name the hotel. Plan 6.4 says only "Remove" for the helper. The plan removes the branch, the parameter and those inputs (research R3).
- **[V14]** (found while implementing) The tenant `UserController::update` had an owner-demotion path that cleared `group_role`/`hotel_group_id` and wrote a `platform.user.access_changed` event. Only a super admin could reach it, because an owner's role change is refused for everyone else. It was removed with the other branches; an owner's role now changes only through `PUT /api/superadmin/users/{user}/access`.
- **[V15]** (found while implementing) Plan 6.3 says each of the 27 `before()` methods holds only the bypass. Two don't: `GuestMemoryPolicy::before()` refuses every `create`/`update`, and `KnowledgeDocumentPolicy::before()` refuses global documents. Those two keep `before()` with only the bypass line removed; the other 25 lose the method.
- **[V16]** (found while implementing) `GenericIndexRequest` resolves its model from the controller's class name, so the read lists are `Superadmin\StaffRoleController` and `Superadmin\TeamController`, not the `HotelStaffRoleController` / `HotelTeamController` named in plan.md §3.
- **[V17]** (found while implementing) The "name the hotel" responses existed only for super admins: `'You must belong to, or specify, a valid hotel.'` (19 controllers) is now `'You must belong to a hotel.'`, and the 422 "Choose a hotel (hotel_id)…" on `/api/conversations` and `/api/ai-activity` is now that same 403 for a hotel-less caller.
- **[V18]** Per the user's instruction at implementation, `routes/web.php` (a session logout and the welcome page), the `welcome` view, `AuthenticatedSessionController::destroy()` and `tests/Feature/ExampleTest.php` were removed. The API has no web routes left.
