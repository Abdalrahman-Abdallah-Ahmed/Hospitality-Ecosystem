# Research: Super Admins Lose Direct Hotel Access

**Feature**: [spec.md](spec.md) · **Pre-work**: [plan-verification.md](plan-verification.md) · **Date**: 2026-11-09

The spec left no `NEEDS CLARIFICATION`. These are the design decisions the plan rests on. Each was checked against the code at `2e18350`.

## R1. Where the block sits

- **Decision**: A new `App\Http\Middleware\DenySuperAdmin`, alias `no_super_admin`, added to the tenant group in `routes/api.php` (`['auth:sanctum', 'throttle:api', 'tenant', 'not_suspended', 'hotel_choice', 'no_super_admin']`). `POST /logout` and `GET /user` take `->withoutMiddleware('no_super_admin')`. Registered in `bootstrap/app.php` with `prependToPriorityList(SubstituteBindings::class, DenySuperAdmin::class)`, as `EnsureNotImpersonating` already is.
- **Rationale**: One place, the same pattern as `not_suspended` and `not_impersonating`. Running before `SubstituteBindings` gives 403 for unknown ids (FR-002). Because it is a group middleware, form-request validation runs later, inside the controller call. Exemptions by `withoutMiddleware` are visible on the route, so a route test can read them.
- **Response**: `apiResponse('Super admins use /api/superadmin.', 403)`, as a `public const REFUSAL` on the middleware, so tests and docs quote one string.
- **Check**: `$request->user()?->isSuperAdmin()`. An impersonation token's user is the hotel user, so it passes (FR-004). No call to `CurrentImpersonation` is needed.
- **Alternatives**: a policy-only block, rejected because policies run after binding (404s leak) and some routes do not authorize through a policy. A scope change (`[]` for super admins), rejected in design Alternative G.

## R2. Middleware order with feature flags

- **Decision**: No special ordering against `EnsureFeatureEnabled`. With a flag off, `feature:transactions` may answer 404 first. The generic test switches every flag on (`config(['features.transactions' => true])`; it is the only flag), so the block is what it proves.
- **Rationale**: Matches the spec edge case. A flag that is off hides the route from everyone, and that answer reveals nothing about records.

## R3. `resolveHotel()` and the super admin `hotel_id` inputs

- **Finding [V13]**: The super admin branch of `resolveHotel()` is the mechanism behind every "a super admin must name the hotel" behaviour (`?hotel_id=` on availability, stays, conversations, housekeeping, exports, imports, voucher templates, knowledge, AI activity, staff roles…). The isSuperAdmin search finds only the helper, not its callers (about 25 across controllers, `ExportsLists` and two form requests) or the form-request rules that accept `hotel_id` for it. Plan 6.4 does not mention them.
- **Decision**: Remove the branch **and** the `$requestedHotelId` parameter, so `resolveHotel(User $user): ?Hotel` returns `$user->hotel`. Update every call site. Remove the `hotel_id` input from tenant-only form requests where it exists only for super admins, with their "super admins name the hotel" comments. Keep it where the superadmin stack shares the request (`StartKnowledgeRebuildRequest`; check `StoreKnowledgeDocumentRequest` and `ReplaceKnowledgeDocumentFileRequest` against `Superadmin\KnowledgeDocumentController` before editing).
- **Rationale**: A parameter nobody may use is the kind of dead bypass Alternative G warns about. Removing a rule is not breaking for hotel users: `validated()` already ignored the value for them, and an unknown key is still ignored.
- **Alternative**: keep the parameter and ignore it. Rejected: it invites the next caller to pass one.

## R4. Super admin read lists for the access screen

- **Decision**: `GET /api/superadmin/hotels/{hotel}/staff-roles` and `GET /api/superadmin/hotels/{hotel}/teams`, in two thin controllers `Superadmin\HotelStaffRoleController::index` and `Superadmin\HotelTeamController::index`. Each queries `withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)` through `GenericIndexRequest` + `GenericQuery::apply()`, and renders the existing `StaffRoleResource` (with `withCount('users')`, as the tenant index does) and `TeamResource`.
- **Rationale**: Nested under the hotel, so a list can never mix hotels (FR-018), and an unknown or deleted hotel is 404 through route binding (`Hotel` is not hotel-scoped). The existing resources keep the frontend's types. The explicit `where` is used instead of `runForHotel()` so the controller does not rely on the tenant context at all (risk R2).
- **Alternatives**: `filter[hotel_id]` on a flat list, rejected because a missing filter would list every hotel's roles. Adding the lists to `HotelController::show`, rejected because they need paging and search.

## R5. Super admin user delete

- **Decision**: `DELETE /api/superadmin/users/{user}` → `Superadmin\UserController::destroy` → `UserAdministration::delete(User $user)`. Inside `TenantContext::withoutScope()` and a transaction: refuse an owner (`DomainRuleException`, 409, `UserAdministration::OWNER_DELETE_REFUSAL` = "Transfer ownership first: clear this user's owner role, then delete them."); record `platform.user.deleted` through `EventLogger::recordPlatform()` with a snapshot (`name`, `email`, `role`, `hotel_id`, `hotel_group_id`) before the row goes; delete the user's tokens (login and impersonation); `$user->delete()`. The controller uses `administrable()`, so a super admin target is 404.
- **Rationale**: Same effect as the tenant `destroy()`. The `deleted` model hook still recounts seats and forgets password links. Deleting tokens explicitly leaves no orphan rows. Recording before the delete keeps the details that the row will no longer hold. `recordPlatform()` keeps `hotel_id = null`, so the event never appears on a tenant event log (design R5).
- **Owner status**: 409 (clarification 2). The account state conflicts with the request, and the client can resolve it. `SecondOwner` uses 422 for a validation-shaped clash; this one is not a validation error.
- **Open sessions**: An impersonation session for the deleted user ends because its token is deleted. No `platform.impersonation.ended` event is written; the `platform.user.deleted` event's context records `tokens_revoked` (count). This matches spec 027, where only the end endpoint and logout write an end event.
- **Alternatives**: soft delete, rejected because `User` has no `SoftDeletes` and the tenant endpoint deletes for good.

## R6. Tests that need an impersonation token

- **Decision**: Move the file-local `imStart()` from `tests/Feature/Superadmin/ImpersonationTest.php` to `tests/Pest.php` as `impersonationTokenFor(User $superAdmin, User $target): string`, and have `ImpersonationTest` call it. The generic test and any rewritten test use it.
- **Rationale**: CLAUDE.md puts a fixture used by more than one file in `tests/Pest.php`.

## R7. The generic walk

- **Decision**: `tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php`, three tests:
  1. Every route with `auth:sanctum` and without `super_admin` runs `no_super_admin` and does not exclude it, except `POST api/logout` and `GET api/user`, which must exclude it. Fails by route name, like `SuspensionRouteInventoryTest`.
  2. With `features.transactions` on, every such route and method (no `HEAD`) is called as a super admin, with every route parameter filled with a fresh UUID, and returns 403 with the refusal message. `GET api/user` returns 200 and `POST api/logout` returns 200.
  3. An impersonation token for a hotel admin gets 200 on `GET /api/guest`.
- Plus one priority assertion: `DenySuperAdmin` comes before `SubstituteBindings`.
- **Rationale**: Filling parameters with unknown UUIDs proves FR-002 on every route at once. Excluding routes with `super_admin` skips `routes/superadmin.php` **[V6]**. File uploads and bodies are not needed, since nothing past the block runs.

## R8. Remaining branch decisions not in the plan's 6.4

| Code | Decision |
| --- | --- |
| `DashboardController::51` (`$hotelIds === null ? 'all' : …`) | **Keep**. A null scope is still a valid `TenantContext` value, and the code is a cache key, not a permission |
| `HotelController::126` (`hotelIds() !== null && suspended`) | **Keep**, same reason |
| `KnowledgeBaseArticleController` doc comment at line 109 | **Update** the wording with the branch removal |
| `UseChosenHotel`, `EnsureNotSuspended` null-scope branches | **Keep** (plan-verification [V3]) |
| `CostVisibilityTest` exemption for `AiActivityController` ("run cost, super admins only") | **Remove** with `attachRunCosts`; the test then proves no tenant controller mentions cost |

## R9. Documentation scope

- **Decision**: Besides the three docs named, sweep every tenant API doc that mentions a super admin (list in [plan.md](plan.md#documentation)) and replace super admin behaviour on hotel endpoints with a single note: "A super admin's own token gets 403; see impersonation." Document the three new endpoints in `docs/superadmin-accounts-api-documentation.md`. Add `docs/latest-changes-2026-11-09.md` (or the implementation date) with the frontend call map from plan-verification Task 3.
- **Rationale**: Every API change updates its doc (CLAUDE.md); a doc that still says "a super admin must send `hotel_id`" would mislead the frontend.
