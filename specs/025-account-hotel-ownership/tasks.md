---

description: "Task list for account-level hotel ownership"
---

# Tasks: Account-Level Hotel Ownership

**Input**: Design documents from `specs/025-account-hotel-ownership/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [plan-verification.md](plan-verification.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/account-ownership.md](contracts/account-ownership.md), [quickstart.md](quickstart.md)

**Tests**: Included. `CLAUDE.md` and the constitution require them, and the spec's success criteria and [quickstart.md](quickstart.md) name what they must prove.

**Organization**: Tasks are grouped by user story, with the P1 stories first. Phase 2 removes `owner_id` everywhere, which every story needs. Story numbers match [spec.md](spec.md), so they don't run in numeric order.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: The user story in [spec.md](spec.md) this task belongs to

## Rules for every task

- Line numbers are from `b6e3fba`, and may have moved. Find the code by name if so.
- Keep `null` and `[]` distinct for `TenantContext::hotelIds()`: check `!== null`, not truthiness. A `null` context must never read as "every hotel".
- Only change the in-memory `hotel` relation. Never write a user's `hotel_id` from request-scoped code.
- Employees' access doesn't change. The one-hotel narrowing applies to admins only. No tenant module policy changes.
- Controllers stay thin and return through `apiResponse()` and Resources.
- Feature tests: `uses(RefreshDatabase::class)`, set the API key in `beforeEach`, build records with `Model::create([...])`, and call `forgetGuards()` between token requests in one test. Run with `php artisan test --parallel` and no env prefix. Never point tests at the dev database.
- Write each story's tests first, and see them fail before implementing it.
- Run `./vendor/bin/pint` before proposing a commit. Commit only when asked. No attribution lines in commits, per `CLAUDE.md`.

---

## Phase 1: Setup

**Purpose**: Branch and baseline

- [X] T001 Create and switch to branch `025-account-hotel-ownership`. Branch from `main` if `024-superadmin-suspension` has merged, otherwise from `024-superadmin-suspension`, because this work needs spec 024's code. Bring the untracked `specs/025-account-hotel-ownership/` along, and leave any unrelated untracked `storage/framework/lsp-*.php` file out of commits
- [X] T002 Check `docker ps -a` first. If `hospitality-postgres` is running, use it. Run `docker compose -f .postgres/compose.yaml up -d` only if it isn't, and not while another project's `pilot-postgres` is in use, because they share a Compose project name and that command replaces the container. Never drop or recreate the shared volume. Create the `Hospitality_Ecosystem_testing` database if it is missing. Then run `php artisan test --parallel` to record a passing baseline before any change, reporting existing failures rather than fixing them here

---

## Phase 2: Foundational

**Purpose**: Remove `owner_id` from the schema, code and fixtures, and shrink `GroupRole`. **No story can start until this phase is done.**

- [X] T003 Create `database/migrations/2026_11_07_000002_move_hotel_ownership_to_account.php`, using the next free number on the day. It: drops `hotels.owner_id` with `dropConstrainedForeignId`; adds a unique index on `users` over `(hotel_group_id, group_role)` named `users_one_owner_per_account`, with no `WHERE` clause (research R4 explains why a partial index breaks `ModelColumnRules`); and adds `agent_conversations.hotel_id` as a "nullable uuid, foreign key to `hotels`, null on delete". Include no data backfill
- [X] T004 [P] Reduce `app/Enums/GroupRole.php` to `case OWNER = 'owner';`, and replace the docblock, whose claim about the tenant endpoint stops being true in US5
- [X] T005 Cast `group_role` to `GroupRole::class` in `casts()`, and add `isOwner(): bool`, true when both `group_role` and `hotel_group_id` are set, in `app/Models/User.php`. `accessibleHotelIds()` doesn't change
- [X] T006 [P] Remove `owner_id` from `$fillable`, and remove the `owner()` relation, in `app/Models/Hotel.php`
- [X] T007 [P] Remove the `owner_id` and `owner` keys from `app/Http/Resources/HotelResource.php`. `Superadmin\HotelResource` inherits this
- [X] T008 [P] Remove `owner_id` from `PLATFORM_ATTRIBUTES` in `app/Http/Controllers/HotelController.php`, and correct its docblock. Update no other behaviour here
- [X] T009 [P] Remove `'owner_id' => $user->id` from `apiStore` in `app/Http/Controllers/Auth/RegisterUserController.php`. US2 adds the owner fields
- [X] T010 [P] Remove `'owner_id' => User::factory()` from `database/factories/HotelFactory.php`, and its import if now unused
- [X] T011 [P] Remove `'owner_id' => $user->id` from `database/seeders/HospitalitySeeder.php`. The super admin keeps `hotel_id`, and the demo account has no owner (V11)
- [X] T012 [P] Remove `'owner_id' => $owner->id` from `seedHotel` in `app/Console/Commands/SeedBenchmarkVolumeCommand.php`. The benchmark's admin keeps their home hotel, and doesn't become an owner
- [X] T013 [P] In `app/Services/Superadmin/UserAdministration.php`, remove `claimOwnership`, `moveOwnershipWithRole` and their calls in `create` and `changeAccess`. Keep the `hotel_user` role sync at `changeAccess:143`, and any import still used
- [X] T014 [P] Remove the check at `refuseMoveLeavingCrossAccountLinks:111` in `app/Services/Superadmin/HotelAdministration.php`. Keep the three other refusals
- [X] T015 In `tests/Pest.php`, remove `owner_id` from the helpers at about lines 169, 269, 360 and 1610, and add a shared `adminOfHotel(Hotel $hotel): User` that returns the hotel's first home admin through `$hotel->users()`
- [X] T016 Replace lookups through `owner_id` with `adminOfHotel()` in `tests/Feature/CrossTenantRouteTest.php:77`, `tests/Feature/TenantIsolationTest.php:320,570` and `tests/Feature/PermissionAuthorizationTest.php:207,478`, and change `group_admin` to `owner` at `tests/Feature/TenantIsolationTest.php:124`
- [X] T017 Delete the `owner_id` fixture line in every other file under `tests/Feature/` that an admin's `hotel_id` already covers, leaving the files named in T018 to T020 and in US1. Find them with `grep -rl owner_id tests`, and where a fixture only existed to supply it, remove the unused `User::factory()` call too
- [X] T018 [P] In `tests/Feature/GenericIndexFilterTypesTest.php`, change the `filter[owner_id]` cases at lines 29-31, 42-43 and 49 to `hotel_group_id`. Left alone, the malformed cases would still pass, because an unknown column is also `422`
- [X] T019 [P] In `tests/Feature/Superadmin/HotelAdministrationTest.php`: change `:205` to `filter[hotel_group_id]=abc`, remove the assertions at `:63` and `:87` and the `'the owner\'s home hotel is elsewhere'` case at `:318`, and delete the `owner_id` lines at `:83` and `:253`
- [X] T020 [P] In `tests/Feature/Superadmin/UserAdministrationTest.php`: remove the ownership assertion at `:91` and the tests at `:185-199` and `:712-742`, and change `group_admin` to `owner` at `:626-629`
- [X] T021 [P] Replace the assertion at `tests/Feature/FreshInstallTest.php:69` with one that the demo hotel belongs to the super admin's `hotel_id`
- [X] T022 Run `php artisan test --parallel` and no separate migrate command, because `RefreshDatabase` migrates the test database, and `artisan --env=testing` would hit the dev database. Confirm the only failures are hotel `show` and `update` tests, which depend on `HotelPolicy` and are fixed in US1, and `CreateUserTest:561`, which sends `group_admin` and is rewritten in US5

**Checkpoint**: `owner_id` and `group_admin` are gone from `app/` and `database/`, apart from the shipped migration that created the column.

---

## Phase 3: User Story 1 - Admins open the hotels they can reach (Priority: P1) 🎯 MVP

**Goal**: The hotel list and the hotel record agree, for owners and hotel admins (FR-001 to FR-007).

**Independent Test**: An account with hotels A and B, an owner based at A and an admin based at A. Request both hotels as each, against the scenarios in spec US1.

### Tests for User Story 1

- [X] T023 [US1] Create `tests/Feature/MultiHotelAccessTest.php` with a hotel record section covering spec US1 scenarios 1 to 8: an owner on hotel B, a home admin, the same admin on hotel B, a member admin, an employee with every permission including `hotels.view`, a stranger with no `body.reason`, a suspended hotel with `body.scope` of `hotel` and no change written, and a super admin
- [X] T024 [P] [US1] Add to `tests/Feature/TenantIsolationTest.php` a test that an account owner and a hotel admin of another account get `403` on `GET`, `PUT` and `PATCH /api/hotel/{id}` (FR-031)

### Implementation for User Story 1

- [X] T025 [US1] In `app/Policies/HotelPolicy.php`, make `view` and `update` return `$user->isAdmin() && in_array($hotel->getKey(), $user->accessibleHotelIds() ?? [], true)`, as research R1 sets out. Don't read `TenantContext` or use `allows()`. Correct `viewAny`'s docblock
- [X] T026 [P] [US1] Give admins a home hotel in `hotelFor` in `tests/Feature/HotelControllerTest.php`, delete the `owner_id` line at `:109`, and drop the final `owner_id` assertion at `:247`, keeping the rest of that test
- [X] T027 [P] [US1] Give the admins at `tests/Feature/GenericCrudTest.php:63,75` a home hotel, and remove the remaining `owner_id` lines in that file. `rejects a hotel update for a non-owner` keeps a stranger and stays `403`
- [X] T028 [US1] In `tests/Feature/SuspensionTest.php`, remove the `owner_id` lines at `:304` and `:368`, and turn the owners at `:344` and `:360` into real account owners with `hotel_group_id` and `group_role`

**Checkpoint**: Finding 3 is fixed, and `php artisan test --parallel` passes.

---

## Phase 4: User Story 2 - A customer who registers owns their account (Priority: P1)

**Goal**: Registration creates an owner (FR-014).

**Independent Test**: Register, check the response, add a second hotel to the new account, and open it as the new user.

### Tests for User Story 2

- [X] T029 [US2] Create `tests/Feature/AccountOwnershipTest.php` with a registration section covering spec US2: the response's `user.hotel_group_id` is the hotel's account and `user.group_role` is `owner`; a hotel added to that account opens with no further step; and a registration forced to fail midway leaves no user, hotel or account

### Implementation for User Story 2

- [X] T030 [US2] In `apiStore` in `app/Http/Controllers/Auth/RegisterUserController.php`, update the user with `hotel_id`, `hotel_group_id => $hotel->hotel_group_id` and `group_role => GroupRole::OWNER`, inside the existing transaction (research R10)
- [X] T031 [P] [US2] Update any response shape assertions for registration in `tests/Feature/AuthTest.php`, and delete its `owner_id` line at `:59` if T017 left it

**Checkpoint**: Every new customer is their account's owner.

---

## Phase 5: User Story 3 - One owner per account (Priority: P1)

**Goal**: A second owner is impossible, and refused clearly (FR-008 to FR-013).

**Independent Test**: Assign an owner, try a second through both super admin paths, then delete the owner and assign another.

### Tests for User Story 3

- [X] T032 [US3] Add a one-owner section to `tests/Feature/AccountOwnershipTest.php` covering spec US3: a second owner through `/access` and through `POST /api/superadmin/users`, each `422` with `This account already has an owner.`, nothing changed, no event, no user and no email; re-sending `owner` for the current owner is `200` with no event; deleting an owner frees the account; `group_admin` is `422` on both endpoints; two direct inserts violate `users_one_owner_per_account`; and calling `UserAdministration::create` directly with a duplicate email raises a database error, not the second-owner refusal (FR-011)

### Implementation for User Story 3

- [X] T033 [P] [US3] Create `app/Support/Superadmin/SecondOwner.php` with `matches(QueryException $e): bool`, testing SQLSTATE `23505` and the index name, in the style of `RoomHoldConflict::matches`
- [X] T034 [US3] In `app/Services/Superadmin/UserAdministration.php`, wrap the transactions in `create` and `changeAccess` so that a `SecondOwner` match, caught outside `DB::transaction`, throws `DomainRuleException('This account already has an owner.', 422)`. Rethrow everything else, and don't add a check before the write (research R4)

**Checkpoint**: The index holds under any order of requests, and ordinary tests exercise the race's refusal path.

---

## Phase 6: User Story 5 - Only the owner manages other admins (Priority: P1)

**Goal**: Only the account owner creates, promotes, edits or deletes other admins, and the tenant endpoint can't grant account-wide reach (FR-021 to FR-024).

**Independent Test**: As a non-owner admin, try to edit, delete, create and promote admins. Repeat as the owner, then as a super admin.

### Tests for User Story 5

- [X] T035 [US5] Add a section to `tests/Feature/AccountOwnershipTest.php` covering spec US5: a non-owner gets `403` updating or deleting another admin and the owner, for password, role and details, with no change, and can still view them; a non-owner can edit and delete themselves and manage employees; a non-owner gets `403` with `Only the account owner can create or promote an admin.` on creating an admin and on promoting an employee, and creating an employee still works; an owner can create, promote, edit and delete other admins, and a new admin isn't an owner; an owner can edit their own details, and gets `403` changing their own role or deleting themselves; a super admin changing an owner's role clears `group_role` and `hotel_group_id`, so no employee keeps account-wide reach; a super admin can delete an owner
- [X] T036 [US5] Update `tests/Feature/CreateUserTest.php`: `:561` becomes "ignores account attributes from a super admin on the tenant endpoint", and also asserts a malformed `group_role` is `422`; `:367` and `:680` use an owner as caller, and `:367` gains a `403` case for another admin; `:668` uses an owner as caller and keeps its `422`. Add owner helpers in the same file as needed. `:483`'s `403` for a super admin target stays

### Implementation for User Story 5

- [X] T037 [US5] In `assignableAttributes` in `app/Http/Controllers/UserController.php`, drop `hotel_id`, `hotel_group_id` and `group_role` for every caller, and correct `ACCOUNT_ATTRIBUTES`' docblock. The super admin target's `403` keeps reading the request directly
- [X] T038 [US5] In `update` and `delete` in `app/Policies/UserPolicy.php`: if the target is an admin and isn't the caller, the caller must be an owner. `delete` also refuses when the target is an owner. `view` doesn't change, and `before()` keeps passing super admins (research R6)
- [X] T039 [US5] In `store` and `update` in `app/Http/Controllers/UserController.php`: refuse with `403` and `Only the account owner can create or promote an admin.` when a caller who is neither an owner nor a super admin sends `role: admin` on a create or as a change, treating a re-sent existing role as no change. Refuse with `403` when an owner who isn't a super admin sends a changed `role` for their own account, while allowing an owner to change another admin's role. When a super admin changes an owner's role, add `group_role => null` and `hotel_group_id => null` to the update

**Checkpoint**: Nobody but the owner or a super admin can change who is an admin.

---

## Phase 7: User Story 8 - Admins work in every module of their hotels (Priority: P1)

**Goal**: Admins work in one hotel per request, across every module: owners in any hotel of the account, others in their assigned hotels. Employees don't change (FR-033 to FR-043).

**Independent Test**: An account with hotels A, B and C, an owner based at A, and an admin based at A who is a member of B. Choose each hotel, then list, open, change and create rooms and reservations, read the dashboard, and manage staff.

### Tests for User Story 8

- [X] T040 [US8] Add a route-driven section to `tests/Feature/MultiHotelAccessTest.php` reusing `crossTenantRoutesFor('bound')` and `tenantOwnedModelFactories()` from `tests/Feature/CrossTenantRouteTest.php`, moving those helpers to `tests/Pest.php` if they can't be shared otherwise: an owner's `GET` on hotel B's records with `X-Hotel-Id: B` is never `403` or `404`, leaving out routes behind a disabled feature; the same request with no header is `404`; and an admin assigned to A and B gets `403` or `404` on hotel C for every method (SC-009)
- [X] T041 [US8] Add scenario tests to `tests/Feature/MultiHotelAccessTest.php` for spec US8: creating with `X-Hotel-Id` lands in the chosen hotel and without it in the default; lists and the dashboard, including the dashboard's cache, show only that hotel (SC-010); a voucher email request for a chosen hotel's reservation belongs to that hotel; a link from another hotel is refused; `GET /api/hotel` still lists every hotel with another chosen; `PUT /api/hotel/{id}` accepts that hotel's own teams with another chosen; staff, staff roles and teams can be managed in the chosen hotel; `GET /api/users` lists users of the chosen hotel, and `POST /api/users` creates there; an admin's `GET /api/user` keeps their team with another hotel chosen; an employee who is a member of hotel B gets `403` on its records; and a suspended hotel's record is `404`
- [X] T042 [US8] Add header tests to `tests/Feature/MultiHotelAccessTest.php` for FR-035a: an unreachable, unknown, soft-deleted or malformed `X-Hotel-Id` gets the plain `403` for admins and employees, on reads and writes, with nothing created; a reachable suspended hotel keeps the suspended `403`; super admins are unaffected; logout, `GET /api/hotel`, `POST /api/connect` and `GET /api/check-paired` still work with a stale header, and `GET /api/check-paired` still finds a home-hotel device with another hotel chosen; and an owner whose home hotel is soft-deleted gets another hotel rather than "You do not belong to any hotel"
- [X] T043 [US8] Add tests to `tests/Feature/MultiHotelAccessTest.php` for the wrong-hotel cases: editing an article leaves it in its own hotel, through the endpoint and through `UpdateKnowledgeArticleTool` (FR-042); continuing an advisor conversation, or confirming its waiting action, with another hotel chosen returns `404` with `Conversation not found.`, and the action doesn't run (FR-043); a new conversation stores its hotel; `CreateAiInsightsJob` runs for the chosen hotel; and a WhatsApp sender stays on their default hotel (FR-039)
- [X] T044 [US8] Update `tests/Feature/SuspensionTest.php:336` to expect the plain `403` with no `body.reason`, and `tests/Feature/DeletedHotelAccessTest.php:47` to expect `403` for the header on a deleted hotel. Then adjust any admin with several hotels in those files, such as `spUser($a, [$b])`, or in `tests/Feature/DashboardCacheTest.php`, that asserts a list or figures combining hotels
- [X] T045 [P] [US8] Add a check to `tests/Feature/SuspensionRouteInventoryTest.php` that every route carrying `not_suspended` also carries `hotel_choice`, with `POST api/logout`, `POST logout`, `GET api/hotel`, `POST api/connect` and `GET api/check-paired` exempt by name

### Implementation for User Story 8

- [X] T046 [US8] Create `app/Http/Middleware/UseChosenHotel.php` with the steps in research R3: pass on for a `null` scope; refuse a header not in `TenantContext::hotelIds()` with `apiResponse('This action is unauthorized.', 403)`; pass on for an employee; for an admin with a current hotel, when it differs from the stored `hotel_id`, `loadMissing('team')` if they have a `team_id` and `setRelation('hotel', Hotel::find($currentId))`, then always `TenantContext::setHotelIds([$currentId])`. An admin with no hotels keeps `[]`. Don't write the attribute
- [X] T047 [US8] In `bootstrap/app.php`, add the alias `hotel_choice` and `prependToPriorityList(SubstituteBindings::class, UseChosenHotel::class)` after the `EnsureNotSuspended` entry
- [X] T048 [US8] In `routes/api.php`, add `hotel_choice` to the tenant group's stack after `not_suspended`. Opt out with `withoutMiddleware` on `POST /logout`, `POST /connect` and `GET /check-paired`, and `withoutMiddlewareFor('index', 'hotel_choice')` on the `/hotel` resource
- [X] T049 [P] [US8] Correct the header comment in `handle` in `app/Http/Middleware/EnsureNotSuspended.php`, since `hotel_choice` now refuses that header. Behaviour doesn't change
- [X] T050 [US8] In `update` in `app/Http/Controllers/HotelController.php`, run `invalidHousekeepingDefaults` inside `TenantContext::runForHotel($hotel->getKey(), ...)`, because `$hotel->teams()` is hotel-scoped and the edited hotel may not be the current one
- [X] T051 [US8] Remove the `$hotel` parameter and the `hotel_id` stamp from `update` in `app/Services/Knowledge/ArticleCommands.php`, the `$hotel` lookup in `update` in `app/Http/Controllers/KnowledgeBaseArticleController.php`, and the argument at `app/Ai/Tools/UpdateKnowledgeArticleTool.php:58`. Keep the 404 for a global article
- [X] T052 [US8] In `startConversation` in `app/Services/Ai/AdvisorTurn.php`, write `$admin->hotel->id` to `agent_conversations.hotel_id` after `storeConversation`. `whatsAppConversation` stays null
- [X] T053 [US8] In `chat` in `app/Http/Controllers/AiAdvisorController.php`, add `where('hotel_id', $user->hotel->id)` to the ownership check, so a conversation from another hotel gets the existing `404`
- [X] T054 [US8] Fix any advisor test fixtures that create a conversation without a hotel, found by running `php artisan test --parallel --filter=Advisor`

**Checkpoint**: SC-009 and SC-010 pass, and no write can land in the wrong hotel.

---

## Phase 8: User Story 4 - The operator decides who owns an account (Priority: P2)

**Goal**: Ownership is never accidental, and can be ended (FR-015 to FR-020).

**Independent Test**: Create the first admin in a new account, confirm they reach one hotel, assign them as owner, demote them, and confirm the account still works.

### Tests for User Story 4

- [X] T055 [US4] Add a section to `tests/Feature/AccountOwnershipTest.php` covering spec US4: the first admin of a new account and a promoted employee aren't owners and reach only their hotels; demoting an owner and sending `group_role: null` each clear `group_role` and `hotel_group_id`; they reach only their home and member hotels on their next request; an ownerless account's admins and `GET /api/usage` keep working; clear-then-assign replaces an owner; and the access event records both columns

### Implementation for User Story 4

- [X] T056 [US4] In `groupRoleAttributes` in `app/Services/Superadmin/UserAdministration.php`, return `['group_role' => null, 'hotel_group_id' => null]` when the role was sent as null or the user held a role and is losing it. An employee with a link who never held a role and sends nothing stays as they are (research R5)

**Checkpoint**: A super admin can end and reassign ownership through the API alone.

---

## Phase 9: User Story 6 - Moving a hotel with an owner attached (Priority: P2)

**Goal**: A registered customer's hotel can be moved, with explicit steps (FR-025 to FR-027).

**Independent Test**: Register a customer, try to move their hotel, clear their group role, move it, and assign an owner at the destination.

### Tests for User Story 6

- [X] T057 [US6] Add moves to `tests/Feature/Superadmin/HotelAdministrationTest.php` covering spec US6: a self-registered customer's hotel is `422`; after `group_role: null` it moves, the customer's `account()` is the destination and they keep access throughout; they aren't its owner; assigning them where the destination has an owner is `422`; and a hotel with no home users leaves the source owner's reach and joins the destination owner's, with no further step

### Implementation for User Story 6

- [X] T058 [US6] Reword the refusal at `refuseMoveLeavingCrossAccountLinks:103` in `app/Services/Superadmin/HotelAdministration.php` to tell the operator to clear the user's account-wide role, since that is now the way to detach them, and match any test asserting the old text

**Checkpoint**: The move flow in [quickstart.md](quickstart.md) works through the API.

---

## Phase 10: User Story 7 - API clients see the new model (Priority: P2)

**Goal**: `owner_id` is gone from every response, and the change is documented (FR-028 to FR-030).

**Independent Test**: Read a hotel through each path and confirm the field is gone. Read the breaking-changes document.

### Tests for User Story 7

- [X] T059 [US7] Add a test to `tests/Feature/AccountOwnershipTest.php` that no hotel response has `owner_id` or `owner`: `GET /api/hotel`, `/api/hotel/{id}`, `/api/superadmin/hotels`, login, `GET /api/user`, `/api/users` and `POST /api/register`. Also that `filter[owner_id]` and `sort=owner_id` are `422` on hotel lists, and a sent `owner_id` on a hotel write is ignored

### Implementation for User Story 7

- [X] T060 [P] [US7] Update `docs/hotel-api-documentation.md` at lines 9, 49-50, 83, 119, 226, 274, 284 and 329, for who can read and edit a hotel, with no `owner_id`
- [X] T061 [P] [US7] Update `docs/user-management-api-documentation.md` at lines 46-49, 113, 141, 250, 265 and 497, for the owner rules, the listing's reach and the ignored fields
- [X] T062 [P] [US7] Update `docs/superadmin-accounts-api-documentation.md` at lines 153 and 170, for one role, one owner, no automatic owner, and ending ownership, plus the move rules
- [X] T063 [P] [US7] Update `docs/frontend-create-modules-api.md:315`, `docs/register-api-update.md:90` and `docs/usage-metering-api-documentation.md:198`
- [X] T064 [P] [US7] Update `docs/auth-api-documentation.md` for `user.hotel` following `X-Hotel-Id` and the plain `403`, and the permission reference in `docs/staff-roles-api-documentation.md` for reach
- [X] T065 [P] [US7] Update `docs/designs/super-admin-platform.md` at lines 321, 937 and 986, and add to stage 6 that it must add a super admin delete route, because `DELETE /api/users/{id}` is the only one
- [X] T066 [US7] Add the nine breaking changes and "What the frontend needs to do" from [contracts/account-ownership.md](contracts/account-ownership.md#breaking-changes) to `docs/latest-changes-<date>.md`, extending `docs/latest-changes-2026-11-07.md` if implemented that day, with the note to freshly migrate dev databases

**Checkpoint**: A frontend developer has everything needed for the handoff.

---

## Phase 11: Polish & Cross-Cutting Concerns

- [X] T067 Update `CLAUDE.md`: the tenancy section for one hotel per request for admins, `X-Hotel-Id` and `$user->hotel`'s meaning, and the access control section for what the owner alone can do
- [X] T068 Check nothing is left with `grep -rn "owner_id\|group_admin\|GROUP_ADMIN" app database/seeders database/factories routes tests`, which should find nothing, apart from any test proving the field is gone
- [X] T069 Run each scenario in [quickstart.md](quickstart.md) against its tests, and add any test still missing to `tests/Feature/AccountOwnershipTest.php` or `tests/Feature/MultiHotelAccessTest.php`
- [X] T070 Run `php artisan test --parallel`, including `EntryPointInventoryTest` and `CrossTenantRouteTest`, and `./vendor/bin/pint`. Report the results, and any failure, rather than committing

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: After Setup. Blocks every story.
- **US1 (Phase 3)**: After Foundational. Gets the suite passing again.
- **US2 (Phase 4)**: After US1.
- **US3 (Phase 5)**: After Foundational. Its tests need nothing from the others.
- **US5 (Phase 6)**: After US1.
- **US8 (Phase 7)**: After US1. Its tests of managing staff assume US5's rules, so run it after US5.
- **US4 (Phase 8)**: After US3, in the same service method.
- **US6 (Phase 9)**: After US2 and US4, since its flow registers a customer and then clears their role.
- **US7 (Phase 10)**: Its test after US2. Docs after the stories they describe.
- **Polish (Phase 11)**: After all stories.

### Within Each Story

Tests first, then policies and services, then middleware, controllers and routes.

### Parallel Opportunities

- T004 and T006 to T014 touch one file each, once T003 is written.
- T018 to T021 touch one test file each.
- US2 and US3 can run alongside each other once US1 is done, apart from taking turns on `tests/Feature/AccountOwnershipTest.php`.
- T060 to T065 touch separate documents.
- `tests/Feature/AccountOwnershipTest.php`, `tests/Feature/MultiHotelAccessTest.php` and `tests/Feature/SuspensionTest.php` are shared across stories, so tasks on them take turns.

### Parallel Example: Phase 2

```text
# After T003 writes the migration:
T004 app/Enums/GroupRole.php
T006 app/Models/Hotel.php
T007 app/Http/Resources/HotelResource.php
T008 app/Http/Controllers/HotelController.php
T009 app/Http/Controllers/Auth/RegisterUserController.php
T010 database/factories/HotelFactory.php
T011 database/seeders/HospitalitySeeder.php
T012 app/Console/Commands/SeedBenchmarkVolumeCommand.php
T013 app/Services/Superadmin/UserAdministration.php
T014 app/Services/Superadmin/HotelAdministration.php
```

## Implementation Strategy

### MVP First

1. Phases 1 and 2.
2. US1, which fixes finding 3.
3. Stop and validate with `php artisan test --parallel --filter=MultiHotelAccessTest`.

US1 alone isn't safe to ship. Until US2, a newly registered customer reaches only their first hotel, and until US5, any admin at the owner's hotel can still take over the account. All P1 stories belong in the same pull request.

### Incremental Delivery

1. US1 + US2 + US3: the ownership model is in place.
2. US5: only the owner manages admins.
3. US8: admins work across modules. The request's goal is met here.
4. US4 + US6: ending ownership and moving hotels.
5. US7 and Polish, then one pull request.

## Notes

- Out of scope, so no tasks: suspension behaviour, removing the super admin bypasses, impersonation, employees receiving the full hotel record, per-hotel staff roles, and rollback or backfills.
- The frontend's changes follow after implementation, through `/frontend-apply-changes`. Its list is in [plan-verification.md](plan-verification.md#change-inventory).
- Don't run a fresh migration against the shared Postgres volume's other databases. Only this app's own dev database is refreshed.
