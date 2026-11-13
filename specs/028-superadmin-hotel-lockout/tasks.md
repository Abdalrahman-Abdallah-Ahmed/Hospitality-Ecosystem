---

description: "Task list for Stage 6: super admins lose direct hotel access"
---

# Tasks: Super Admins Lose Direct Hotel Access

**Input**: Design documents from `specs/028-superadmin-hotel-lockout/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/api.md](contracts/api.md), [plan-verification.md](plan-verification.md)

**Tests**: Required by the spec (FR-016–FR-018) and the constitution (VIII). Pest feature tests on the real Postgres container; run with `php artisan test --parallel` (no env prefix).

**Organization**: One phase per user story from spec.md. The super admin cases in existing tests become 403 assertions in US1, together with the block that makes them fail, so the suite stays green phase by phase. The dead code those cases exercised is removed in US5.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1–US5 from spec.md
- Paths are from the repository root (`app/`, `routes/`, `tests/`, `docs/`)

---

## Phase 1: Setup

**Purpose**: Confirm the pre-work and the baseline.

- [X] T001 Confirm the pre-work in specs/028-superadmin-hotel-lockout/plan-verification.md still matches the code: re-run `grep -rn "isSuperAdmin\|SUPER_ADMIN\|'super_admin'" app` and `grep -rliE "superAdmin|super_admin|SUPER_ADMIN|isSuperAdmin" tests`, and add any file not in the plan.md §2 and §4 inventories to plan.md with a decision (remove / keep / move) before editing code
- [X] T002 Start the Postgres container (`docker compose -f .postgres/compose.yaml up -d`, never drop its volume) and record the baseline: `php artisan test --parallel` is green on the branch before any change

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The shared test fixture every story's tests use.

- [X] T003 Move the file-local `imStart()` from tests/Feature/Superadmin/ImpersonationTest.php into tests/Pest.php as `impersonationTokenFor(User $superAdmin, User $target): string` (calls `POST /api/superadmin/users/{target}/impersonate` with `apiHeaders()` and returns the plain-text token), and change every `imStart()` caller in tests/Feature/Superadmin/ImpersonationTest.php to use it; run `php artisan test --parallel --filter=ImpersonationTest` green

**Checkpoint**: Fixture available. User stories can start.

---

## Phase 3: User Story 1 - A super admin's own token is refused on every hotel endpoint (Priority: P1) 🎯 MVP

**Goal**: Every authenticated hotel route returns 403 `Super admins use /api/superadmin.` to a super admin's own token, before route-model binding and validation (FR-001, FR-002, FR-005).

**Independent Test**: `php artisan test --parallel --filter=SuperAdminDeniedOnTenantRoutesTest` is green and checks over 100 route/method pairs, each with unknown UUIDs for every parameter.

### Tests for User Story 1

- [X] T004 [US1] Create tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php (research R7) with: (a) an inventory test that walks `Route::getRoutes()`, keeps routes whose URI starts with `api/` and whose `gatherMiddleware()` contains `auth:sanctum` and not `super_admin` (this skips the web `POST logout` in routes/web.php, a session logout that redirects), skips `HEAD`, and fails by name for any route that does not run `no_super_admin` (or lists it in `excludedMiddleware()`), except `POST api/logout` and `GET api/user`, which must exclude it, and asserts more than 100 pairs were checked; (b) a priority test that `App\Http\Middleware\DenySuperAdmin` sits before `Illuminate\Routing\Middleware\SubstituteBindings` in `app(Kernel::class)->getMiddlewarePriority()`; (c) a walk with `config(['features.transactions' => true])` that calls every such route and method as `User::factory()->role(UserRole::SUPER_ADMIN)->create()` through `actingAs(..., 'sanctum')` with `X-API-KEY`, fills every route parameter with `Str::uuid()`, sends no body or file (the block runs before validation, so none is needed), runs with `$this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)` (throttling is not what this test checks) so the `api` limit of 240/min cannot turn a pass into a 429 as routes grow (193 pairs today), and collects any pair that does not return 403 with message `DenySuperAdmin::REFUSAL`; expect the collected list to be `[]`. Confirm it fails (red) before T005

### Implementation for User Story 1

- [X] T005 [US1] Create app/Http/Middleware/DenySuperAdmin.php: `public const REFUSAL = 'Super admins use /api/superadmin.';` and `handle()` returning `apiResponse(self::REFUSAL, 403)` when `$request->user()?->isSuperAdmin()`, otherwise `$next($request)`; class docblock says it runs before route-model binding so an unknown id also gets 403, and that an impersonation token belongs to the hotel user so it passes (mirror app/Http/Middleware/EnsureNotImpersonating.php)
- [X] T006 [US1] Register it in bootstrap/app.php: alias `'no_super_admin' => DenySuperAdmin::class` next to `not_impersonating`, and `$middleware->prependToPriorityList(SubstituteBindings::class, DenySuperAdmin::class);` with a one-line comment, after the `EnsureNotImpersonating` entry
- [X] T007 [US1] In routes/api.php add `'no_super_admin'` to the tenant group's middleware array (`['auth:sanctum', 'throttle:api', 'tenant', 'not_suspended', 'hotel_choice', 'no_super_admin']`), change `/logout` to `->withoutMiddleware(['not_suspended', 'hotel_choice', 'no_super_admin'])`, add `->withoutMiddleware('no_super_admin')` to `GET /user`, and extend the group comment: a super admin's own token is refused here; they use /api/superadmin, or impersonate (spec 028). Run T004 green

### Existing super admin cases become 403 assertions (FR-017)

Each task: replace every "a super admin sees / changes / names the hotel on a hotel route" case with an assertion that the super admin's own token gets `403` with `DenySuperAdmin::REFUSAL`; where the case also proved something for hotel users, keep that part with an admin (`adminOfHotel()` / file helper). Rename tests so names describe the new behaviour.

- [X] T008 [P] [US1] tests/Feature/CreateUserTest.php (41 mentions): super admin index/show/create/update/delete on `/api/users` → 403; keep the hotel-admin cases; any rule still needed (refusing the `super_admin` role, an owner's role change) is asserted with an admin here or already exists in tests/Feature/Superadmin/UserAdministrationTest.php — check before deleting a case; update the file's header comment (lines 3–7)
- [X] T009 [P] [US1] tests/Feature/GuestControllerTest.php (lines 117–155), tests/Feature/ReservationControllerTest.php (lines 377–401), tests/Feature/HotelPolicyControllerTest.php (lines 189–205): super admin view/update/delete in any hotel → 403
- [X] T010 [P] [US1] tests/Feature/RoomControllerTest.php (lines 261–290): super admin view/update → 403; the room-type picker test (line 274) keeps the admin half ("own hotel for an admin") and asserts 403 for the super admin
- [X] T011 [P] [US1] tests/Feature/HotelControllerTest.php (lines 117, 151 dataset, 174): super admin list/view → 403; drop `'a super admin'` from the role dataset at line 151 if the dataset expects success
- [X] T012 [P] [US1] "Names the hotel" cases → 403: tests/Feature/AvailabilityControllerTest.php (112), tests/Feature/ConversationViewerTest.php (122), tests/Feature/StayListTest.php (87), tests/Feature/HousekeepingBoardTest.php (61, keep the `rooms.view` half for an employee), tests/Feature/ExportControllerTest.php (353)
- [X] T013 [P] [US1] "Names the hotel" cases → 403: tests/Feature/ReservationImportTest.php (105), tests/Feature/ReservationImportPreviewTest.php (186), tests/Feature/VoucherTemplateControllerTest.php (411, 422), tests/Feature/KnowledgeDocumentControllerTest.php (137, 167)
- [X] T014 [P] [US1] tests/Feature/KnowledgeBaseArticleControllerTest.php: line 79 → 403; rewrite the test at line 104 with a hotel admin as `it('keeps global articles out of reach on the hotel routes', …)` (GET/PUT/DELETE of a `hotel_id = null` article → 404), and update its name in tests/Inventory/entry_points.php `global_data` → `App\Models\KnowledgeBaseArticle` `covered_by`
- [X] T015 [P] [US1] tests/Feature/DashboardControllerTest.php (38) and tests/Feature/DashboardCacheTest.php (94): super admin → 403; keep the cache-key assertion with two admins of different hotel sets if it was the point
- [X] T016 [P] [US1] tests/Feature/AiActivityApiTest.php (96–128) → 403 for the super admin, and rename (no hotel caller sees run cost); tests/Feature/AiAdvisorControllerTest.php (51) → 403 with the new message
- [X] T017 [P] [US1] tests/Feature/MultiHotelAccessTest.php (109, 332): "leaves a super admin unaffected / free to send any header" → 403, with and without `X-Hotel-Id`; tests/Feature/SuspensionTest.php (96–105): login still OK, `GET /api/superadmin/hotels/{id}` OK, `GET`/`PUT /api/hotel/{id}` → 403; rename "never checks a super admin at login or on hotel routes"
- [X] T018 [P] [US1] tests/Feature/TransactionsFeatureFlagTest.php: remove `'super admin'` from the dataset (lines 76, 91); the generic walk covers it
- [X] T019 [P] [US1] Move to an admin (they test listing/filtering, not the super admin): tests/Feature/FilterableTest.php (103), tests/Feature/GenericCrudTest.php (35, "lists all hotels for a super admin" → admin lists their reachable hotels, plus one 403 line for a super admin), tests/Feature/GenericIndexFilterTypesTest.php (22)
- [X] T020 [P] [US1] tests/Feature/Superadmin/ImpersonationTest.php: "shows a super admin the full row" (line 770) → the super admin's own token gets 403 on `/api/history/guest/{id}`; keep the hotel-staff and impersonation history cases unchanged
- [X] T021 [US1] Run `php artisan test --parallel`; the only expected failure is the owner-delete case in tests/Feature/AccountOwnershipTest.php (line 142), fixed in T029. Fix anything else before continuing

**Checkpoint**: The block is live and proven; every old super-admin-on-hotel-route case asserts 403.

---

## Phase 4: User Story 2 - A super admin still signs in, sees who they are, and signs out (Priority: P1)

**Goal**: Login, `GET /api/user` and `POST /api/logout` behave exactly as before for a super admin (FR-003, FR-010).

**Independent Test**: The three assertions below pass.

- [X] T022 [US2] In tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php add: a super admin logs in through `POST /api/login` (200, token returned, `body.user.role` = `super_admin`, `permissions` lists every `Permission` case); `GET /api/user` with that token → 200 with role and full permissions and no `impersonation` key; `POST /api/logout` → 200 and the token is gone from `personal_access_tokens`

**Checkpoint**: The super admin frontend can still route a super admin to the super admin area.

---

## Phase 5: User Story 3 - Impersonation is the way into a hotel (Priority: P1)

**Goal**: An impersonation token passes the block and gets the hotel user's view, with no super admin bypass (FR-004).

**Independent Test**: The two assertions below pass.

- [X] T023 [US3] In tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php add: with `impersonationTokenFor($superAdmin, $hotelAdmin)`, `GET /api/guest` → 200 listing only the admin's hotel's guests; with a token for an employee whose staff role lacks `rooms.view`, `GET /api/room` → 403 `This action is unauthorized.` (the policy decides, not the block)

**Checkpoint**: P1 complete: the block, the exemptions and the replacement path are all proven. This is the MVP.

---

## Phase 6: User Story 4 - The super admin area keeps working, including the user access screen (Priority: P2)

**Goal**: Per-hotel staff role and team lists, and a super admin user delete that refuses owners (FR-012–FR-014, FR-018; research R4, R5; contracts/api.md §2–4).

**Independent Test**: `php artisan test --parallel --filter="HotelAccessOptionsTest|UserAdministrationTest|AccountOwnershipTest"` is green, and every other test under tests/Feature/Superadmin passes unchanged.

### Tests for User Story 4

- [X] T024 [P] [US4] Create tests/Feature/Superadmin/HotelAccessOptionsTest.php (uses `RefreshDatabase`, API key in `beforeEach`): for both `GET /api/superadmin/hotels/{hotel}/staff-roles` and `/teams`: two hotels in different accounts each with records → each list returns only its hotel's ids; `search`, `sort=-name`, `per_page` work; unknown hotel id and a soft-deleted hotel → 404; a hotel admin → 403; an impersonation token → 403; staff roles include `users_count` and exclude soft-deleted roles. Red before T026
- [X] T025 [P] [US4] Add delete cases to tests/Feature/Superadmin/UserAdministrationTest.php: `DELETE /api/superadmin/users/{user}` for an employee → 200 `User deleted successfully.`, row gone, the user's tokens gone, the account's seat count drops, one `platform.user.deleted` event with `hotel_id` null, actor = the super admin, `changes` holding the `name`, `email`, `role`, `hotel_id`, `hotel_group_id` snapshot and `context.tokens_revoked`; a super admin target → 404; an owner → 409 `Transfer ownership first: clear this user's owner role, then delete them.` and the row is unchanged, then `PUT …/access` with `{"group_role": null}` and delete → 200; a hotel admin caller → 403; an impersonation token held for the deleted user → 401 on `GET /api/user` afterwards. Red before T027

### Implementation for User Story 4

- [X] T026 [P] [US4] Create app/Http/Controllers/Superadmin/HotelStaffRoleController.php (`index(GenericIndexRequest $request, Hotel $hotel)`: `GenericQuery::apply(StaffRole::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->withCount('users'), $request)` → `apiResponse('Staff roles fetched successfully.', 200, StaffRoleResource::collection(...))`) and app/Http/Controllers/Superadmin/HotelTeamController.php (same over `Team::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->with('hotel')` → `TeamResource`, message `Teams fetched successfully.`); add `Route::get('/hotels/{hotel}/staff-roles', …)` and `Route::get('/hotels/{hotel}/teams', …)` to routes/superadmin.php under the spec 023 hotel routes, with a comment (spec 028: the access screen's pickers). No `TenantContext` use and no policy call. Run T024 green
- [X] T027 [US4] In app/Services/Superadmin/UserAdministration.php add `public const OWNER_DELETE_REFUSAL = 'Transfer ownership first: clear this user\'s owner role, then delete them.';` and `delete(User $user): void`: inside `TenantContext::withoutScope()` and `DB::transaction()`, throw `new DomainRuleException(self::OWNER_DELETE_REFUSAL, 409)` when `$user->isOwner()`; count and delete `$user->tokens()`; `EventLogger::recordPlatform('platform.user.deleted', $user, <snapshot of name, email, role, hotel_id, hotel_group_id as from-values>, ['tokens_revoked' => $count])`; `$user->delete()`. Record the event before the delete
- [X] T028 [US4] In app/Http/Controllers/Superadmin/UserController.php add `destroy(User $user): JsonResponse` → `$this->users->delete($this->administrable($user)); return apiResponse('User deleted successfully.', 200);`, and `Route::delete('/users/{user}', [UserController::class, 'destroy']);` in routes/superadmin.php next to the other user routes. Run T025 green
- [X] T029 [US4] Fix tests/Feature/AccountOwnershipTest.php line 142: replace `DELETE /api/users/{owner}` as a super admin with `PUT /api/superadmin/users/{owner}/access` `{"group_role": null}` then `DELETE /api/superadmin/users/{owner}`; keep the rest of the case (promoting the admin afterwards)
- [X] T030 [US4] Add an end-to-end test to tests/Feature/Superadmin/UserAdministrationTest.php (SC-003): a super admin logs in through `POST /api/login`, reads `GET /api/user`, lists `GET /api/superadmin/hotels/{hotel}/staff-roles` and `/teams`, assigns a role and team through `PUT /api/superadmin/users/{user}/access`, starts `POST /api/superadmin/users/{user}/impersonate` and with that token reads `GET /api/guest` (200), then `POST /api/logout` with the super admin's own token. Record every request the super admin's own token makes and assert each URI is under `api/superadmin` or is `api/login`, `api/user` or `api/logout`
- [X] T031 [US4] Confirm the new routes are covered by the generic checks: run `php artisan test --parallel --filter="CrossTenantRouteTest|EntryPointInventoryTest|SuspensionRouteInventoryTest"`; if `EntryPointInventoryTest` names a new route, add it to tests/Inventory/entry_points.php with the covering test name

**Checkpoint**: Full suite green. A super admin can do everything the frontend did through hotel routes without a hotel route.

---

## Phase 7: User Story 5 - Hotel users are unaffected by the bypass removal (Priority: P2)

**Goal**: Remove every super admin branch that served only hotel routes (FR-006–FR-009), per plan.md §2, with no change for hotel users.

**Independent Test**: `php artisan test --parallel` is green after each task, and `grep -rn "isSuperAdmin\|SUPER_ADMIN" app` lists only files marked **Keep** in plan.md §2.

- [X] T032 [P] [US5] Delete the `before()` super admin bypass method from the 27 policies in app/Policies/: ActivityCategoryPolicy, ActivityPolicy, AiInsightsPolicy, BookingPolicy, ConversationPolicy, EventLogPolicy, GuestMemoryPolicy, GuestPolicy, HotelPolicy, HotelPolicyPolicy, KnowledgeBaseArticlePolicy, KnowledgeDocumentPolicy, ProactiveMessagePolicy, RecommendationPolicy, ReservationImportPolicy, ReservationImportPreviewPolicy, ReservationPolicy, RoomPolicy, RoomTypePolicy, StaffRolePolicy, StayPolicy, TaskCategoryPolicy, TaskPolicy, TeamPolicy, TransactionPolicy, UserPolicy, VoucherTemplatePolicy. Remove now-unused imports. Keep `! $model->isSuperAdmin()` in `UserPolicy::managesUser`
- [X] T033 [US5] Change `resolveHotel()` in app/Helpers/Helper.php to `resolveHotel(User $user): ?Hotel` returning `$user->hotel`, with the docblock reduced to "the hotel the request works in", and update every caller to drop the second argument: app/Http/Controllers/{ActivityCategory,Activity,AiActivity,Availability,Conversation,Guest,HotelPolicy,HousekeepingBoard,KnowledgeBaseArticle,KnowledgeDocument,MaintenanceTask,Reservation,ReservationImportPreview,Room,RoomType,StaffRole,Stay,TaskCategory,Task,Team,VoucherTemplate}Controller.php, app/Http/Controllers/Concerns/ExportsLists.php, app/Http/Requests/StaffRoleRequest.php, app/Http/Requests/Vouchers/StoreVoucherTemplateRequest.php. Remove "a super admin must name the hotel" paths, such as `ConversationController`'s `abort(422, 'Choose a hotel (hotel_id)…')`, so a missing hotel gives the same answer as for any hotel-less caller. Fix the `EnsureNotSuspended` comment that mentions `resolveHotel()` only if its meaning changed
- [X] T034 [US5] Remove the super-admin-only `hotel_id` rule and its comment from app/Http/Requests/AiActivityIndexRequest.php, AvailabilityRequest.php, Concerns/ValidatesExportFormat.php (rule and the docblock's "the hotel a super admin exports"), ConversationIndexRequest.php, HousekeepingBoardRequest.php, ImportReservationsRequest.php, StaffRoleRequest.php (and `targetHotelId()`'s use of `input('hotel_id')`), StayDayRequest.php, StoreReservationImportPreviewRequest.php, UnassignedArrivalsRequest.php, Vouchers/StoreVoucherTemplateRequest.php. For app/Http/Requests/Knowledge/StoreKnowledgeDocumentRequest.php and ReplaceKnowledgeDocumentFileRequest.php, first check whether app/Http/Controllers/Superadmin/KnowledgeDocumentController.php or app/Http/Controllers/Concerns/HandlesKnowledgeDocuments.php reads `hotel_id`; remove only if not. Do not touch Knowledge/StartKnowledgeRebuildRequest.php
- [X] T035 [US5] In app/Http/Controllers/UserController.php remove the caller-is-super-admin branches: `index` (line 41 condition: always apply the own-hotel filter), `store` (line 75: always use `$request->user()->hotel`), `update` (line 131 `&& ! isSuperAdmin()`, the password block at line 136, and the super-admin-target block starting at line 140), and `refusesAdminGrant` (`&& ! $caller->isSuperAdmin()`, line 240). Keep both `refusesSuperAdminGrant` checks (lines 66, 122). Update the `refusesAdminGrant` docblock ("someone other than the account owner")
- [X] T036 [P] [US5] Remove `|| $user->isSuperAdmin()` / `! $user->isSuperAdmin()` from app/Http/Controllers/AccountUsageController.php (line 46), app/Ai/Tools/Admin/GuardedTool.php (163), app/Ai/Tools/GetReportTool.php (96), app/Ai/Tools/GetStaffTool.php (54), app/Http/Requests/HotelListRequest.php (23, and the docblock "anyone but an admin")
- [X] T037 [P] [US5] In app/Http/Controllers/AiActivityController.php remove the super admin branch and `attachRunCosts()`; in app/Http/Resources/AiActivityResource.php remove the super admin `mergeWhen` (run cost) at line 47; in tests/Feature/CostVisibilityTest.php remove the `'app/Http/Controllers/AiActivityController.php'` exemption (line 315). Run `--filter="CostVisibilityTest|AiActivityApiTest"` green
- [X] T038 [P] [US5] In app/Http/Controllers/KnowledgeBaseArticleController.php (lines 27, 51, 58) and app/Http/Controllers/KnowledgeDocumentController.php (lines 24, 31) remove the super admin branches so `knowledgeScope()` always returns the request's hotel; update the comment at KnowledgeBaseArticleController line 109
- [X] T039 [P] [US5] In app/Http/Resources/EventLogResource.php `hidesActorDetails()`, remove `$request->user()?->isSuperAdmin() ||` from the first condition and keep the actor clause; update the docblock. Run `--filter=ImpersonationTest` green (hotel staff still see only the super admin's id and name, FR-008)
- [X] T040 [US5] Verify: `grep -rn "isSuperAdmin\|SUPER_ADMIN" app` lists only files marked **Keep** in plan.md §2; `grep -rn "resolveHotel(" app` shows no second argument; then `php artisan test --parallel` green

**Checkpoint**: No super admin bypass remains on a hotel path; hotel users' behaviour is unchanged.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Documentation (FR-019–FR-021) and the final gates.

- [X] T041 [P] Update CLAUDE.md: Tenancy (a super admin's own token never reaches a tenant route, `no_super_admin`; `hotelIds() === null` still means unrestricted and now matters for a super admin only on the superadmin stack, `GET /user` and logout); Access control ("`admin` and `super_admin` hold every permission" → admins hold every permission; a super admin's list is reported by login and `GET /user` but used nowhere, since a super admin never reaches a hotel route); Request flow line (add `no_super_admin` after `hotel_choice`); Conventions → Other helpers (`resolveHotel()`: drop "super admin must name one")
- [X] T042 [P] Update docs/dashboard-api-documentation.md (lines 10, 34, 186, 208) and docs/staff-roles-api-documentation.md (lines 11, 25, 187, 272, 293, 300, and the permission reference table): super admins get 403 on these endpoints, the `hotel_id` field is gone, and super admins assign roles through `/api/superadmin`
- [X] T043 [P] Sweep the tenant API docs for super admin behaviour on hotel endpoints (`super admin`, `super_admin`, `hotel_id` for super admins) and replace it with one note that a super admin's own token gets 403 and reaches the hotel through impersonation: docs/user-management-, hotel-, task-management-, knowledge-base-article-, activity-, hotel-policy-, reservations-, history-, guest-memories-, whatsapp-device-, conversations-, ai-insights-, usage-metering- (tenant part only), knowledge-document-, guest-, auth-, ai-advisor-chat-, ai-activity- (run cost is gone), voucher-templates-, transaction-, room-, reservation-recommendations-, availability-, stays-, room-types-, proactive-messages-, conversion-analytics-api-documentation.md
- [X] T044 [P] Document `GET /api/superadmin/hotels/{hotel}/staff-roles`, `GET /api/superadmin/hotels/{hotel}/teams` and `DELETE /api/superadmin/users/{user}` in docs/superadmin-accounts-api-documentation.md, from specs/028-superadmin-hotel-lockout/contracts/api.md §2–4 (include the 409 owner response and the two-step ownership change); in docs/superadmin-impersonation-api-documentation.md state that impersonation is now the only way into a hotel's screens
- [X] T045 [P] Create docs/latest-changes-<implementation date>.md: the breaking change (403 on hotel routes for a super admin's own token, unknown ids included), the removed `hotel_id` input, the lost super-admin-only detail (AI activity run cost, full history actor), the three new endpoints, and the frontend call map from specs/028-superadmin-hotel-lockout/contracts/api.md (including: gate hotel screens on role, since `permissions` still lists everything for a super admin)
- [X] T046 [P] In docs/designs/super-admin-platform.md mark the Open Question (lines 160–163) settled with a pointer to specs/028-superadmin-hotel-lockout/plan-verification.md Task 1, and note the Stage 6 deviations [V1]–[V13] under the Stage 6 plan (lines 1480–1574)
- [X] T047 Run `./vendor/bin/pint`, then `php artisan test --parallel`; all green. Run the scans in specs/028-superadmin-hotel-lockout/quickstart.md ("Scans that must stay clean") and fix any hit

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (T001–T002)** → **Foundational (T003)** → user stories.
- **US1 (T004–T021)** depends on T003. T004 before T005–T007; T005 → T006 → T007; T008–T020 after T007, in parallel; T021 last.
- **US2 (T022)** and **US3 (T023)** depend on US1's T007 (and US3 on T003). Independent of each other, but both edit SuperAdminDeniedOnTenantRoutesTest.php, so run them one after the other.
- **US4 (T024–T031)** depends only on Foundational for its own tests; T029 must follow T028 and closes the one failure T021 allows; T030 follows T026 and T028.
- **US5 (T032–T040)** depends on US1 (the old super admin cases must already assert 403, or removing the bypasses breaks them) and on US4 (`AccountOwnershipTest` no longer uses `DELETE /api/users` as a super admin).
- **Polish (T041–T047)** after US4 and US5.

### Within Each User Story

- Tests first and red, then implementation, then green.
- US5: T033 before T034 (callers first, then the inputs they read). T035 is independent of T033 except both touch the tenant user area; run the suite after each.

### Parallel Opportunities

- T008–T020: 13 tasks on different test files.
- T024 ‖ T025; T026 ‖ T027.
- T032, T036, T037, T038, T039 on different files.
- T041–T046: all docs, different files.

---

## Parallel Example: User Story 1

```text
After T007:
  T008 CreateUserTest.php
  T009 Guest/Reservation/HotelPolicy controller tests
  T010 RoomControllerTest.php
  T011 HotelControllerTest.php
  T012 Availability/Conversation/Stay/Housekeeping/Export tests
  T013 Import/Preview/VoucherTemplate/KnowledgeDocument tests
  T014 KnowledgeBaseArticleControllerTest.php + entry_points.php
  ... T015–T020
Then T021 (full suite).
```

## Parallel Example: User Story 4

```text
T024 HotelAccessOptionsTest.php      ‖  T025 UserAdministrationTest.php (delete cases)
T026 two list controllers + routes   ‖  T027 UserAdministration::delete()
T028 destroy + route → T029 AccountOwnershipTest → T030 end-to-end → T031 inventories
```

---

## Implementation Strategy

### MVP First (P1: US1 + US2 + US3)

1. T001–T003.
2. US1: the block and the 403 rewrites. The one allowed failure is the owner-delete case in AccountOwnershipTest.
3. US2 and US3: exemptions and impersonation proven.
4. **Stop and validate**: the security outcome is in place. Do not ship without US4, because the super admin Users page needs the new delete and pickers.

### Incremental Delivery

1. MVP (US1–US3) → the block.
2. US4 → the super admin area is whole again; suite fully green.
3. US5 → dead branches gone, still green.
4. Polish → docs, Pint, final run. Then hand the frontend its changes doc (`/frontend-apply-changes`), per the project's frontend handoff practice.

---

## Notes

- Do not change `User::accessibleHotelIds()`, `User::permissions()`, `EnsureSuperAdmin`, or the tenant scope (FR-009–FR-011).
- No migration, no new `Permission` case, no change to `docs/staff-roles-api-documentation.md`'s permission list beyond removing super admin wording.
- Commit only when asked; no co-author or attribution lines (CLAUDE.md).
