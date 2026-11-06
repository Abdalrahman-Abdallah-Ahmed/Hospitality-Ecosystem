---

description: "Task list for super admin account, hotel and user administration"
---

# Tasks: Super Admin Account, Hotel and User Administration

**Input**: Design documents from `specs/023-superadmin-administration/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [plan-verification.md](plan-verification.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/superadmin-administration.md](contracts/superadmin-administration.md), [quickstart.md](quickstart.md)

**Tests**: Included. `CLAUDE.md` and the constitution require them, and the spec's success criteria and [quickstart.md](quickstart.md) name what they must prove.

**Organization**: Tasks are grouped by user story. US1 creates the controllers and services, and later stories add methods to them.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: The user story the task belongs to (US1 to US7)

## Rules for every task

- **Where the spec and the design differ, follow the spec.** The order of authority is `spec.md`, then `research.md` and `data-model.md`, then the design doc.
- **Controllers stay thin.** Authorize through the route guard only, validate with a request class, delegate to a service, and return `apiResponse()`. Never call a tenant policy from a `Superadmin\*` controller.
- **Refusals** in services throw `DomainRuleException`, and multi-step writes run in `DB::transaction`.
- **Audit** goes through `EventLogger::recordPlatform()` only. Each successful write records one event, with no password, hash or token in it.
- **Tests** use Pest with `uses(RefreshDatabase::class)` per file, the `apiHeaders()` and `makeHotel()` helpers in `tests/Pest.php`, and real Postgres. Run with `php artisan test --parallel`. Don't drop or recreate the Postgres volume.
- **Out of scope**: `suspended_*` anything, the dashboard, impersonation and removing the super admin bypasses. No rollback or backfill.
- **Don't commit** unless the user asks.

---

## Phase 1: Setup

**Purpose**: Branch and configuration

- [X] T001 Create and switch to branch `023-superadmin-administration` from the tip of `022-superadmin-route-move`, keeping the uncommitted `specs/023-superadmin-administration/` files and `.specify/feature.json`. Confirm with `git branch --show-current`
- [X] T002 [P] Add `'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000')` to `config/app.php`, and `FRONTEND_URL=http://localhost:3000` to `.env.example`
- [X] T003 [P] Add an `invitations` broker to `passwords` in `config/auth.php`: `provider` `users`, `table` `user_invitation_tokens`, `expire` `4320`, `throttle` `60`, leaving the `users` broker unchanged
- [X] T004 [P] Create `database/migrations/2026_11_06_000001_create_user_invitation_tokens_table.php`: `email` string primary key, `token` string, `created_at` nullable timestamp, the same shape as `password_reset_tokens`

---

## Phase 2: Foundational

**Purpose**: Pieces every story uses. **No story can start until this phase is done.**

- [X] T005 [P] Create `app/Enums/GroupRole.php`, a string-backed enum with cases `OWNER = 'owner'` and `GROUP_ADMIN = 'group_admin'`. Do not cast `users.group_role` to it (research R9)
- [X] T006 [P] Read `app/Exceptions/DomainRuleException.php`, which every service refusal in this feature uses as `new DomainRuleException($message, $status)`. Create no new exception class (constitution Principle II)
- [X] T007 Add `recordPlatform(string $eventType, Model $subject, ?array $changes = null, array $context = [])` to `app/Support/Audit/EventLogger.php`. It writes `EventLog::create` with `hotel_id` null inside `TenantContext::runForHotel(null, ...)`, uses `$eventType` unchanged, resolves the actor as `record()` does, and honours `$recording` (research R11)
- [X] T008 [P] In `app/Providers/AppServiceProvider.php`, register `ResetPassword::createUrlUsing()` to return `config('app.frontend_url').'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset())`
- [X] T009 [P] In `app/Http/Resources/UserResource.php`, add `'hotel_ids' => $this->whenLoaded('hotels', fn () => $this->hotels->pluck('id')->all())`. Tenant code never loads `hotels`, so its responses don't change

**Checkpoint**: `php artisan migrate` works and the existing suite still passes.

---

## Phase 3: User Story 1 - Onboard a new customer without SQL (Priority: P1) 🎯 MVP

**Goal**: Create an account, hotel and first admin through the API. The admin sets a password from the email, logs in and opens their hotel.

**Independent Test**: The CUJ 1 test runs the whole journey with no seeder or database step, ending with `GET /api/hotel/{id}` as the new admin.

### Tests for User Story 1

- [X] T010 [P] [US1] In `tests/Feature/Superadmin/AccountAdministrationTest.php`, test `POST /api/superadmin/accounts`: `201` with only the `AccountResource` fields, with `hotels_count` and `users_count` equal to `0`, not null; `contract_value_monthly` and `contract_currency` in the request are ignored and absent from the response; a slug held by a soft-deleted account returns `422`, not `500`; one `platform.account.created` event; and `GET /api/superadmin/accounts/{account}` returns `hotels_count` and `users_count`, counting each user once whether attached by account, home hotel or membership, and leaving out super admins
- [X] T011 [P] [US1] In `tests/Feature/Superadmin/HotelAdministrationTest.php`, test `POST /api/superadmin/accounts/{account}/hotels`: `201` with the five operational defaults; client-sent default team and category fields are ignored; omitted currency and timezone take the account's; a live slug returns `422`; a soft-deleted hotel in the same account is restored with its data, the same id and new details, and omitted fields keep their old values; seat counts include the restored hotel again; a soft-deleted hotel in another account returns `422` "The slug has already been taken." and stays deleted in its own account; a deleted account in the path returns `404`; `platform.hotel.created` is recorded, with `context.restored = true` on a restore
- [X] T012 [P] [US1] In `tests/Feature/Superadmin/UserAdministrationTest.php`, test `POST /api/superadmin/users`: `201` with `reset_email_sent: true` and a `UserInvitationNotification` sent; no password in the response or event; a `password` field in the request is ignored; missing `hotel_id` returns `422`, including with `hotel_group_id` present; a soft-deleted hotel returns `422`; a hotel outside `hotel_group_id` returns `422`; `group_role` on an employee returns `422`; `group_role` on an admin sets `hotel_group_id` from the hotel's account; an admin at an ownerless hotel becomes owner, and an existing owner is not replaced; one `platform.user.created` event and no `password_reset_sent` event; with `Notification::shouldReceive('send')->andThrow(...)`, the response is `201` with `reset_email_sent: false`, the user exists, the error is reported, and an immediate `POST .../password-reset` isn't throttled
- [X] T013 [P] [US1] In `tests/Feature/PasswordResetTest.php`, test `POST /api/reset-password` with a first-password link: it works at 71 hours and fails at 73 (use `$this->travel()`); a link can't be used twice; invalid token, expired token and unknown email give the same `422` message; a completed reset writes no platform event, deletes the user's tokens and `WhatsAppDevice`, and leaves no row for the user in `password_reset_tokens` or `user_invitation_tokens`; a missing API key returns `401`; password rules are `required`, `confirmed` and `Password::defaults()`
- [X] T014 [US1] In `tests/Feature/Superadmin/UserAdministrationTest.php`, add the CUJ 1 end-to-end test: create an account, then a hotel, then a user with that `hotel_id` and `role=admin`; read the token from the faked notification; post it to `POST /api/reset-password`; log in at `POST /api/login` with the new password; `GET /api/hotel/{id}` with the returned token returns `200`; exactly three `platform.*` events exist, each with the super admin as actor

### Implementation for User Story 1

- [X] T015 [P] [US1] In `app/Models/HotelGroup.php`, add `public array $searchable = ['name', 'slug'];` and `scopeWithUserCount`, which selects `hotel_groups.*` when no columns are selected yet (so the result doesn't depend on call order with `withCount`) and adds `users_count` as one `selectSub` on `users` with no join: `role` isn't `super_admin`, and either `hotel_group_id` matches, `hotel_id` is in a live hotel of the account, or a `hotel_user` row is in one (research R5)
- [X] T016 [P] [US1] In `app/Models/Hotel.php`, add `static::restored(fn (self $hotel) => $hotel->recountSeats());` in `booted()`
- [X] T017 [P] [US1] Create `app/Http/Resources/Superadmin/AccountResource.php` returning `id`, `name`, `slug`, `country_code`, `default_currency`, `default_timezone`, `hotels_count`, `users_count`, `created_at`, `updated_at`. No contract fields, `settings` or `is_active`
- [X] T018 [P] [US1] Create `app/Http/Requests/Superadmin/StoreAccountRequest.php`: `name` required string max 255; `slug` required string max 255 `Rule::unique('hotel_groups', 'slug')`, which includes deleted rows; `country_code` nullable size 2; `default_currency` optional size 3; `default_timezone` optional `timezone`
- [X] T019 [P] [US1] Create `app/Http/Requests/Superadmin/StoreHotelRequest.php`: `name` required string max 255; `slug` required string max 255 `Rule::unique('hotels', 'slug')->withoutTrashed()`; `timezone` optional `timezone`; `currency` optional size 3; `country_code` nullable size 2; `city`, `address`, `email`, `phone`, `whatsapp_number` nullable with rules by column type. Nothing else is accepted
- [X] T020 [P] [US1] Create `app/Http/Requests/Superadmin/StoreUserRequest.php`: `name` required string max 255; `email` required email unique on `users`; `phone_number` nullable unique on `users`; `role` required `Rule::enum(UserRole::class)`, so `super_admin` passes validation and the service refuses it; `hotel_id` required uuid `Rule::exists('hotels', 'id')->withoutTrashed()`; `hotel_group_id` optional uuid `Rule::exists('hotel_groups', 'id')->withoutTrashed()`; `group_role` nullable `Rule::enum(GroupRole::class)`. No `password`
- [X] T021 [P] [US1] Create `app/Http/Requests/ResetPasswordRequest.php`: `token` required string; `email` required email; `password` required `confirmed` `Password::defaults()`
- [X] T022 [P] [US1] Create `app/Notifications/UserInvitationNotification.php` extending `Illuminate\Auth\Notifications\ResetPassword`, not queued. Override `buildMailMessage` with a welcome and set-your-password message that states the life from `config('auth.passwords.invitations.expire')`, in hours
- [X] T023 [US1] Create `app/Services/Superadmin/AccountAdministration.php` with `create(array)`: creates the `HotelGroup`, refreshes it to pick up column defaults, and records `platform.account.created`
- [X] T024 [US1] Create `app/Services/Superadmin/HotelAdministration.php` with `create(HotelGroup $account, array)`, inside `TenantContext::withoutScope()` and a transaction. Look up `Hotel::onlyTrashed()` by slug. In the same account, `restore()` then `update()` with only the sent fields. In another account, throw `new DomainRuleException('The slug has already been taken.', 422)`. Otherwise create with `hotel_group_id` from the path, and `timezone` and `currency` falling back to `default_timezone` and `default_currency`. Refresh, and record `platform.hotel.created`, with `context.restored = true` on a restore
- [X] T025 [US1] Create `app/Services/Superadmin/UserAdministration.php` with `refusesSuperAdminGrant(?string $role): bool`, returning true only for `UserRole::SUPER_ADMIN->value`, and `create(array): array{0: User, 1: bool}`. In a transaction: refuse `super_admin` with `403` and "The super admin role cannot be assigned through the API."; refuse with `422` when `hotel_group_id` isn't the hotel's account, or `group_role` is sent for a non-admin; set `hotel_group_id` from the hotel when `group_role` is sent; create the user with `Str::password(32)`; for an admin, run `Hotel::whereKey($hotelId)->whereNull('owner_id')->update(['owner_id' => $user->id])`; record `platform.user.created` with public fields only. After the transaction, send the invitation
- [X] T026 [US1] In `app/Services/Superadmin/UserAdministration.php`, add the private invitation send: `Password::broker('invitations')->sendResetLink(['email' => ...], fn ($user, $token) => $user->notify(new UserInvitationNotification($token)))`. On any `Throwable`, `report($e)`, delete the token with `Password::broker('invitations')->deleteToken($user)`, and return false
- [X] T027 [US1] In `app/Services/Superadmin/UserAdministration.php`, add `clearAccess(User $user): void`: in `TenantContext::withoutScope()` and a transaction, `$user->tokens()->delete()` and `$user->whatsappDevice()->delete()`. It records nothing
- [X] T028 [US1] Create `app/Http/Controllers/Auth/PasswordResetController.php`. Call `Password::broker('users')->reset(...)`, and unless it returns `PASSWORD_RESET`, call `Password::broker('invitations')->reset(...)`, always trying both so timing doesn't reveal the email (research R1). The callback sets the password, saves, and calls `clearAccess`. On success, delete the user's token from both brokers and return `200`. Otherwise return `422` with one message, "This password reset link is invalid or has expired."
- [X] T029 [P] [US1] Create `app/Http/Controllers/Superadmin/AccountController.php` with `store` and `show`, and a private helper that reloads the account with `withCount('hotels')` and `withUserCount()`. Every response, including `store` and later `update`, goes through that helper and `AccountResource`
- [X] T030 [P] [US1] Create `app/Http/Controllers/Superadmin/HotelController.php` with `store(StoreHotelRequest, HotelGroup $account)` and `show(Hotel $hotel)`, using the shared `HotelResource`
- [X] T031 [P] [US1] Create `app/Http/Controllers/Superadmin/UserController.php` with a private helper that loads `hotel`, `team`, `staffRole` and `hotels` inside `TenantContext::withoutScope()` and returns a `UserResource`, used by every user response (FR-027, FR-041). `store` returns that resource's `resolve()` plus `reset_email_sent`, with `201`
- [X] T032 [US1] In `routes/superadmin.php`, add `POST /accounts`, `GET /accounts/{account}`, `POST /accounts/{account}/hotels`, `GET /hotels/{hotel}`, `POST /users`. In `routes/api.php`, add `Route::post('/reset-password', [PasswordResetController::class, 'store'])->middleware('throttle:login')` next to `/login` in the `api.key` group

**Checkpoint**: The CUJ 1 test passes. A customer can be onboarded without SQL.

---

## Phase 4: User Story 2 - No request can create a super admin (Priority: P1)

**Goal**: No caller can grant `super_admin` or link a super admin to a hotel or account.

**Independent Test**: Attempt the grant as each kind of caller on every endpoint that writes a role. The role never changes.

### Tests for User Story 2

- [X] T033 [US2] In `tests/Feature/CreateUserTest.php`: change the assertion at line 352 to "The super admin role cannot be assigned through the API."; rewrite `lets a super admin assign the super admin role` at line 454 as a `403` refusal, with `isSuperAdmin()` staying false; add a super admin refused on `POST /api/users`; add `PUT /api/users/{user}` with `hotel_group_id` or `group_role` on a super admin target returning `403` with nothing written, while the same fields on a non-super-admin target still work
- [X] T034 [P] [US2] In `tests/Feature/Superadmin/UserAdministrationTest.php`, test `role=super_admin` on an otherwise valid `POST /api/superadmin/users` returns `403` with the fixed message and creates nothing, and `GET /api/superadmin/users/{user}` on a super admin returns `404`

### Implementation for User Story 2

- [X] T035 [US2] In `app/Http/Controllers/UserController.php`, replace `denySuperAdminGrant` so `store` and `update` call `UserAdministration::refusesSuperAdminGrant($request->validated('role'))` and return `403` with the fixed message for every caller, and update its docblock
- [X] T036 [US2] In `app/Http/Controllers/UserController.php@update`, return `403` with "A super admin cannot be linked to a hotel or account." when `$user->isSuperAdmin()` and `hotel_group_id` or `group_role` is filled in the request (FR-029)
- [X] T037 [US2] In `app/Http/Controllers/Superadmin/UserController.php`, add a private `administrable(User $user): User` that aborts with `404` for a super admin, and `show` using it and the response helper from T031. Add `GET /users/{user}` in `routes/superadmin.php`. Every later method with `{user}` must call `administrable()`

**Checkpoint**: Super admin role grants are refused everywhere that exists so far. US4 adds the `/access` case.

---

## Phase 5: User Story 3 - Lock out a compromised user (Priority: P1)

**Goal**: Revoke a user's tokens, pairing codes and device, send a reset email, and clear access when the reset completes.

**Independent Test**: Give a user a token, an unredeemed pairing code and a paired device. Revoke, then confirm all are refused and a WhatsApp message gets the not-paired reply.

### Tests for User Story 3

- [X] T038 [P] [US3] In `tests/Feature/Superadmin/UserAdministrationTest.php`, add the CUJ 5 test: revoking removes API tokens and a `WhatsAppDevice::PAIRING_TOKEN_NAME` token and deletes the device; `POST /api/pair` with the old code is refused; `ProcessInboundWhatsAppMessageJob` for that number returns its not-paired reply; one `platform.user.access_revoked` event, also when the user had nothing to revoke
- [X] T039 [P] [US3] In `tests/Feature/Superadmin/UserAdministrationTest.php`, test `POST .../password-reset`: `200`, one `ResetPassword` notification and one `platform.user.password_reset_sent`; `429` with no email or event when sent again within 60 seconds, and also right after a user create; `503` with no event on a forced mail failure, and a pending first-password link still works afterwards; a pending first-password link stops working after a reset is sent successfully; the reset URL starts with `config('app.frontend_url')`
- [X] T040 [P] [US3] In `tests/Feature/PasswordResetTest.php`, test a normal link works at 59 minutes and fails at 61, and an email change kills a pending link

### Implementation for User Story 3

- [X] T041 [US3] In `app/Services/Superadmin/UserAdministration.php`, add `revokeAccess(User $user)`, which calls `clearAccess` and records `platform.user.access_revoked`
- [X] T042 [US3] In `app/Services/Superadmin/UserAdministration.php`, add `sendPasswordReset(User $user)`: throw `DomainRuleException` with `429` when either broker's `getRepository()->recentlyCreatedToken($user)` is true; call `Password::broker('users')->sendResetLink`. On a mail failure, `report($e)` once, delete the new `users` token, keep any pending invitation, and return false so the controller answers `503`. Throw no exception for this, or it reports twice. Only after a successful send, delete any invitation token and record `platform.user.password_reset_sent`
- [X] T043 [US3] In `app/Http/Controllers/Superadmin/UserController.php`, add `sendPasswordReset` and `revokeTokens`, both through `administrable()`. In `routes/superadmin.php`, add `POST /users/{user}/password-reset` and `POST /users/{user}/revoke-tokens`

**Checkpoint**: All P1 stories are done.

---

## Phase 6: User Story 4 - Administer users across the platform (Priority: P2)

**Goal**: Find any user by account, hotel or role, edit their details, and change what they can reach.

**Independent Test**: Create a user in one account, change each kind of access, and confirm what they can reach after each change, including that another account's hotel is refused.

### Tests for User Story 4

- [X] T044 [P] [US4] In `tests/Feature/Superadmin/UserAdministrationTest.php`, test the list: filtering by `hotel_group_id` and by `hotel_id` finds users attached by account, home hotel and membership, once each; `filter[role]=super_admin` is empty and no super admin ever appears; responses include `hotel_ids`; filtering or sorting on `password` or `remember_token` returns `422`; a malformed uuid filter returns `422`, not `500`
- [X] T045 [P] [US4] In `tests/Feature/Superadmin/UserAdministrationTest.php`, test `PUT /users/{user}`: edits `name`, `email` and `phone_number` only, ignores `password`, `role` and `hotel_id`; a taken email returns `422`; an unchanged request records no event; a super admin target returns `404`
- [X] T046 [P] [US4] In `tests/Feature/Superadmin/UserAdministrationTest.php`, test `PUT /users/{user}/access`: `role=super_admin` returns `403`; `hotel_ids` replaces memberships, the home hotel stays reachable, and another account's hotel or a deleted hotel returns `422` with nothing changed; a membership in a soft-deleted hotel survives a `hotel_ids` change, and works again after that hotel is restored; every response from the three user routes includes `hotel_ids`; `group_role` sets `hotel_group_id` from `User::account()`, is refused for an employee, and is removed on demotion; a staff role from the home hotel is saved, one from another hotel or for a user with no home hotel returns `403`, and it is removed on promotion; an employee promoted at an ownerless home hotel becomes owner; a demoted owner stops owning their hotels, including soft-deleted ones, and the next admin created becomes owner; one `platform.user.access_changed` event; a refused change leaves everything as it was. Confirm reach by calling `accessibleHotelIds()` after each change

### Implementation for User Story 4

- [X] T047 [P] [US4] In `app/Models/User.php`, add `scopeAttachedToAccount(array $ids)` and `scopeAttachedToHotel(array $ids)` with the matches in `data-model.md` ("Attachment scopes")
- [X] T048 [P] [US4] Create `app/Http/Requests/Superadmin/UserIndexRequest.php` subclassing `GenericIndexRequest`: `availableColumns()` returns an allowlist of `id`, `name`, `email`, `role`, `phone_number`, `team_id`, `hotel_id`, `hotel_group_id`, `group_role`, `staff_role_id`, `email_verified_at`, `created_at`, `updated_at`, and `rules()` adds uuid rules for `filter.hotel_id` and `filter.hotel_group_id`
- [X] T049 [P] [US4] Create `app/Http/Requests/Superadmin/UpdateUserRequest.php`: `name` sometimes string max 255; `email` sometimes email unique on `users` ignoring this user; `phone_number` sometimes nullable unique ignoring this user. No `password`
- [X] T050 [P] [US4] Create `app/Http/Requests/Superadmin/UpdateUserAccessRequest.php`: `role` sometimes `Rule::enum(UserRole::class)`; `group_role` sometimes nullable `Rule::enum(GroupRole::class)`; `hotel_ids` sometimes array; `hotel_ids.*` uuid `Rule::exists('hotels', 'id')->withoutTrashed()`; `staff_role_id` sometimes nullable uuid
- [X] T051 [US4] Move `denyStaffRole` from `app/Http/Controllers/UserController.php` to `app/Services/Superadmin/UserAdministration.php` as `staffRoleError(?string $staffRoleId, ?Hotel $hotel, string $role): ?array`, returning message and status or null, with the same messages and statuses. The tenant controller builds its `apiResponse` from the result, and its tests must pass unchanged
- [X] T052 [US4] In `app/Services/Superadmin/UserAdministration.php`, add `update(User, array)`: applies the fields and records `platform.user.updated` with from/to values of public fields, and nothing when unchanged
- [X] T053 [US4] In `app/Services/Superadmin/UserAdministration.php`, add `changeAccess(User, array)`, implementing all seven "Access change rules" in `data-model.md` in order, in `TenantContext::withoutScope()` and a transaction, including refusing `super_admin` with the same `403`. When replacing memberships, change only `hotel_user` rows for live hotels, so a membership in a soft-deleted hotel survives (FR-015a). Clear ownership with `Hotel::withTrashed()->where('owner_id', $user->id)->update(['owner_id' => null])`, and set it on the home hotel only (research R8). Record `platform.user.access_changed` when something changed
- [X] T054 [US4] In `app/Http/Controllers/Superadmin/UserController.php`, add `index`, `update` and `updateAccess`. `index` excludes super admins, applies the two scopes from `filter`, removes those keys with `$request->merge()`, then calls `GenericQuery::apply()` inside `TenantContext::withoutScope()` with `hotel`, `team`, `staffRole` and `hotels` eager loaded. `update` and `updateAccess` respond through the helper from T031. In `routes/superadmin.php`, add `GET /users`, `PUT /users/{user}`, `PUT /users/{user}/access`

**Checkpoint**: Users can be administered without SQL, except for changing a home hotel.

---

## Phase 7: User Story 5 - Administer accounts and hotels (Priority: P2)

**Goal**: List, edit, move and delete accounts and hotels. Tenant hotel creation and deletion go away, and deleted hotels leave staff reach.

**Independent Test**: Edit and delete accounts and hotels through the API, and confirm counts, slug rules, move rules and deletion rules hold.

### Tests for User Story 5

- [X] T055 [P] [US5] In `tests/Feature/Superadmin/AccountAdministrationTest.php`, test list, edit and delete: `hotels_count` and `users_count` on the list and on the `PUT` response, not null; filter or sort on `contract_value_monthly` or `contract_currency` returns `422`, and `search` doesn't match a contract currency; contract fields on `PUT` are ignored; a slug held by a deleted account returns `422`; delete returns `422` with a live hotel, and succeeds with only deleted hotels or none; `.updated` and `.deleted` events, and no event for an unchanged edit
- [X] T056 [P] [US5] In `tests/Feature/Superadmin/HotelAdministrationTest.php`, test list, edit, move and delete: a `filter[hotel_group_id]` list across accounts; a malformed filter returns `422`; edit accepts only `name`, `slug` and `hotel_group_id`; a slug held by a deleted hotel returns `422`, not `500`; `422` in each of the four refused move cases in research R7; a deleted destination returns `422`; a move with only home-hotel users succeeds, with users and both accounts' seat counts following; soft delete, with `.updated` and `.deleted` events
- [X] T057 [P] [US5] Create `tests/Feature/DeletedHotelAccessTest.php`: after a hotel is soft-deleted, its staff get none of its data on a tenant endpoint, with and without `X-Hotel-Id`; a record created by that user doesn't land in it; a user with another hotel falls back to that hotel; a user with no other hotel can log in and sees nothing; after a restore the same token reaches it again. Include home-hotel, membership and account-wide users
- [X] T058 [US5] Move or rewrite the tenant hotel tests. From `tests/Feature/GenericCrudTest.php` lines 29, 46, 59, 122 and 146, `tests/Feature/HotelControllerTest.php` lines 66, 80, 97, 195 and 207, and `tests/Feature/OperationalDefaultsTest.php` line 37, repoint what is still meaningful to the super admin endpoints, rewriting restore tests from owner to account, and remove what T011 already covers. Add tests in `tests/Feature/HotelControllerTest.php` that tenant `POST /api/hotel` and `DELETE /api/hotel/{hotel}` return `405` for an admin and a super admin, and that the owner can still view and edit

### Implementation for User Story 5

- [X] T059 [P] [US5] Create `app/Http/Requests/Superadmin/AccountIndexRequest.php` subclassing `GenericIndexRequest`: override `modelClass()` to return `HotelGroup::class`, and `availableColumns()` to return the allowlist `id`, `name`, `slug`, `country_code`, `default_currency`, `default_timezone`, `created_at`, `updated_at`. Don't name contract columns, or `CostVisibilityTest` fails
- [X] T060 [P] [US5] Create `app/Http/Requests/Superadmin/UpdateAccountRequest.php` with the rules from T018 as `sometimes`, and slug `Rule::unique('hotel_groups', 'slug')->ignore($account)`
- [X] T061 [P] [US5] Superseded in review (T084): `GenericIndexRequest` now checks filter values by column type, so no `HotelIndexRequest` is needed
- [X] T062 [P] [US5] Create `app/Http/Requests/Superadmin/UpdateHotelRequest.php`: `name` sometimes string max 255; `slug` sometimes string max 255 `Rule::unique('hotels', 'slug')->ignore($hotel)`, which includes deleted rows; `hotel_group_id` sometimes uuid `Rule::exists('hotel_groups', 'id')->withoutTrashed()`
- [X] T063 [US5] In `app/Services/Superadmin/AccountAdministration.php`, add `update(HotelGroup, array)` and `delete(HotelGroup)`. Delete throws `DomainRuleException` with `422` while `$account->hotels()->exists()`. Record `.updated` when something changed, and `.deleted`
- [X] T064 [US5] In `app/Services/Superadmin/HotelAdministration.php`, add `update(Hotel, array)` and `delete(Hotel)`. When `hotel_group_id` changes, throw `DomainRuleException` with `422` in the four cases in research R7. Record `.updated` when something changed, and `.deleted`
- [X] T065 [US5] In `app/Http/Controllers/Superadmin/AccountController.php`, add `index` (`AccountIndexRequest`, `withCount('hotels')`, `withUserCount()`, `GenericQuery::apply()`), `update` and `destroy`. In `routes/superadmin.php`, add `GET /accounts`, `PUT` and `DELETE /accounts/{account}`
- [X] T066 [US5] In `app/Http/Controllers/Superadmin/HotelController.php`, add `index`, `update` and `destroy`. In `routes/superadmin.php`, add `GET /hotels`, `PUT` and `DELETE /hotels/{hotel}`
- [X] T067 [US5] In `app/Http/Controllers/HotelController.php`, delete `store` and `destroy` and any imports only they used. In `routes/api.php` line 146, use `->except(['store', 'destroy', 'edit', 'create'])`. In `app/Policies/HotelPolicy.php`, delete `create`, `delete`, `restore` and `forceDelete`
- [X] T068 [US5] In `tests/Inventory/entry_points.php`, remove the `POST api/hotel` and `DELETE api/hotel/{hotel}` entries
- [X] T069 [US5] In `app/Models/User.php@accessibleHotelIds`, add `hotel_id` only when `Hotel::whereKey($this->hotel_id)->exists()`, skipping the query when it is already in the list. In `app/Http/Middleware/ResolveTenant.php`, use `$user->hotel_id` as the fallback current hotel only when it is in `$available`, otherwise `$available[0] ?? null`. Super admin behaviour doesn't change

**Checkpoint**: All endpoints in the contract exist, and the tenant hotel removal is done.

---

## Phase 8: User Story 6 - Platform actions stay out of hotel staff's sight (Priority: P2)

**Goal**: Prove every write records one event with no hotel attached, and that hotel staff never see it.

**Independent Test**: Record a platform event on a guest, then confirm that hotel's admin doesn't see it on any event log endpoint.

### Tests for User Story 6

- [X] T070 [US6] Create `tests/Feature/Superadmin/PlatformAuditTest.php`: `recordPlatform()` on a `Guest` subject with `TenantContext::setCurrentHotelId($hotelId)` set still writes `hotel_id` null; the actor is the super admin; the row is absent from `GET /api/history/guest/{id}` as that hotel's admin, and from `EventLog::query()` inside `TenantContext::runForHotel($hotelId, ...)`; a dataset walks every write endpoint and asserts exactly one `platform.*` event of the name in `data-model.md`; no `changes` or `context` contains the new password, a hash or a token; a tenant `PUT /api/users/{user}` writes no `platform.*` row; a completed public reset writes none

**Checkpoint**: The audit rules are proven across every write. No new application code should be needed. If a case fails, fix it in the service that wrote the event.

---

## Phase 9: User Story 7 - Access stays restricted (Priority: P2)

**Goal**: Prove the new endpoints refuse everyone but super admins.

**Independent Test**: Call every new address as a hotel admin, as an employee, without a token and without an API key.

### Tests for User Story 7

- [X] T071 [US7] Create `tests/Feature/Superadmin/AccessGuardTest.php` with a dataset of all 17 new routes from the contract: a hotel admin and an employee get `403` or `404` with nothing changed; no token gets `401`; no API key gets `401`
- [X] T072 [P] [US7] In `tests/Feature/PasswordResetTest.php`, test that `POST /api/reset-password` returns `429` after too many attempts, as `/login` does

**Checkpoint**: `CrossTenantRouteTest` and `EntryPointInventoryTest` also pass with the new routes, with no new inventory entries.

---

## Phase 10: Polish & Cross-Cutting Concerns

**Purpose**: Documentation and final verification

- [X] T073 [P] Create `docs/superadmin-accounts-api-documentation.md` from `contracts/superadmin-administration.md`, covering accounts, hotels, users and reset access
- [X] T074 [P] Update `docs/hotel-api-documentation.md`: creation and deletion removed, and deleted hotels can't be reached
- [X] T075 [P] Update `docs/user-management-api-documentation.md`: `super_admin` refused for every caller, and super admin targets refuse account and hotel links
- [X] T076 [P] Update `docs/auth-api-documentation.md`: `POST /api/reset-password` with first-password (72 hours) and reset (60 minutes) links
- [X] T077 [P] Update `docs/whatsapp-device-api-documentation.md`: revoking access and completing a reset unpair the device
- [X] T078 Create `docs/latest-changes-2026-11-06.md`, or the date of implementation, with the breaking changes: tenant hotel routes removed, `super_admin` refused for super admins too, deleted hotels unreachable, and new `FRONTEND_URL` setting
- [X] T079 Run `./vendor/bin/pint`
- [X] T080 Run `php artisan test --parallel` and fix anything this feature broke. Confirm `CostVisibilityTest`, `EntryPointInventoryTest`, `CrossTenantRouteTest` and `QueueLaneTest` pass unchanged, other than T068
- [ ] T081 Run the scenarios and the manual check in `specs/023-superadmin-administration/quickstart.md`, and report any that don't hold
- [X] T082 Include `FRONTEND_URL` in the required deployment settings in `docs/latest-changes-2026-11-06.md`, and in any production settings list under `deploy/`
- [X] T083 Report in the final summary that the frontend still needs a reset page at `/reset-password?token=…&email=…`, which `/frontend-apply-changes` can hand off once the backend is done. Don't start that handoff unless the user asks

- [X] T084 Fix the code review findings of 2026-11-06: tenant hotel edits drop `owner_id` and `hotel_group_id` in `app/Http/Controllers/HotelController.php`; `app/Support/RequestRules/ModelColumnRules.php` checks soft-deleted rows when the unique index covers them; `app/Http/Controllers/UserController.php` refuses changing a super admin role; `MeteringService::safely` uses a savepoint inside a transaction; `app/Http/Requests/Generic/GenericIndexRequest.php` checks filter values by column type; `app/Services/Superadmin/UserAdministration.php` makes revoke and resend atomic with their events, keeps earlier links on a failed resend, and checks only added memberships; `app/Models/User.php` forgets password links when an email changes or a user is deleted; `app/Support/Audit/EventLogger.php` has one row writer

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies. T001 comes first.
- **Foundational (Phase 2)**: Depends on Setup and blocks every story.
- **US1 (Phase 3)**: Depends on Foundational. It creates the controllers and services the other stories extend.
- **US2, US3, US4, US5**: Depend on US1. They don't depend on each other, except that they edit the same files, so run them one at a time.
- **US6, US7**: Depend on US1 to US5, because they walk every write and every route.
- **Polish**: Depends on all stories.

### Within Each Story

- Write tests first and see them fail.
- Requests and models come before services, services before controllers, and controllers before routes.

### Parallel Opportunities

- **Phase 1**: T002, T003, T004.
- **Phase 2**: T005, T006, T008, T009. T007 is on its own.
- **US1 tests**: T010, T011, T012, T013.
- **US1 implementation**: T015 to T022 together, and T029 to T031 together after the services.
- **US4 implementation**: T047 to T050.
- **US5 tests and requests**: T055 to T057, and T059 to T062.
- **Polish docs**: T073 to T077.

Tasks in `UserAdministration.php`, `UserAdministrationTest.php` and `routes/superadmin.php` touch the same file, so they aren't parallel with each other even between stories.

### Parallel Example: User Story 1

```text
T010 AccountAdministrationTest
T011 HotelAdministrationTest
T012 UserAdministrationTest
T013 PasswordResetTest
```

Then:

```text
T015 HotelGroup scopes    T016 Hotel restored hook    T017 AccountResource
T018 StoreAccountRequest  T019 StoreHotelRequest      T020 StoreUserRequest
T021 ResetPasswordRequest T022 UserInvitationNotification
```

## Implementation Strategy

### MVP

Phases 1 to 3 give a working CUJ 1, which is the purpose of the feature. Stop there to validate it, but don't ship it alone: until US2 is done a super admin can still grant the role, and until US5 is done the tenant hotel creation path, with its weaker restore rule, still exists.

### Incremental Delivery

1. Setup and Foundational.
2. US1: onboarding works.
3. US2: the role is closed.
4. US3: lockout works, and all P1 stories are done.
5. US4 and US5: full administration, tenant removal and deleted-hotel access.
6. US6 and US7: proof across every write and route.
7. Polish: docs and the full suite.

The design ships Stage 2 as one PR, after Stage 1. A frontend handoff follows the backend.
