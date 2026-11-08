# Research: Account-Level Hotel Ownership

The Technical Context has no open unknowns. These decisions settle the design questions the spec leaves to planning. V numbers refer to [plan-verification.md](plan-verification.md).

## R1: Reach in policies

**Decision**: `HotelPolicy::view` and `update` are `$user->isAdmin() && in_array($hotel->getKey(), $user->accessibleHotelIds() ?? [], true)`. Super admins return earlier through `before()`.

**Rationale**: `EnsureNotSuspended` narrows `TenantContext`, so the narrowed list would answer a suspended hotel with a plain `403`. `HotelController` then gives the reachable caller the suspended `403`, and an unreachable caller never gets that far.

**Alternatives**: `allows()`, which reads the narrowed list and would need a permission. Hotel settings are deliberately outside the enum.

## R2: Reach for other records

**Decision**: No policy changes. `allows()`, `ProactiveMessagePolicy:30`, `ReservationImportPreviewPolicy:54`, `StaffRolePolicy:59` and `UserPolicy::managesUser` all compare a record with `$user->hotel?->id`, and R3 makes that relation the hotel the request works in.

**Rationale**: With the scope and the relation both on one hotel, the existing check already means the right thing, for admins and employees, and for AI tools running inside `TenantContext::runForHotel`. No policy tests a role, so this also removes the Principle IV conflict the first design had.

**Alternatives**: A reach check with a role branch in `allows()`, the first design. It kept lists across hotels, but needed the dashboard, lists and every implicit hotel stamp fixing one by one (V19). Per-hotel staff roles, declined in clarification.

## R3: Applying X-Hotel-Id

**Decision**: A new `App\Http\Middleware\UseChosenHotel`, alias `hotel_choice`, added to the tenant group in `routes/api.php` after `not_suspended`. Its steps:

1. With a `null` scope, pass on. This covers super admins.
2. If `X-Hotel-Id` is sent and isn't in `TenantContext::hotelIds()`, return a plain `403`. A suspended hotel of the user's already got the suspended `403` from `EnsureNotSuspended`, so anything left here is unreachable.
3. For an employee, pass on: their scope and relation don't change.
4. For an admin with a current hotel: if it differs from the stored `hotel_id`, call `$user->loadMissing('team')` when they have a `team_id`, while their home hotel is still in scope, as `EnsureNotSuspended::narrow` does, then `$user->setRelation('hotel', Hotel::find($currentId))`. In every case, call `TenantContext::setHotelIds([$currentId])`. An admin with no hotels keeps `[]`.

`bootstrap/app.php` adds the alias and `prependToPriorityList(SubstituteBindings::class, UseChosenHotel::class)` last, which places it directly before binding, as spec 024 confirmed for its own entry. So a record in another hotel is simply not found. Four routes opt out, and `SuspensionRouteInventoryTest` checks them by name:

- `POST /api/logout`, so a stale header can't stop a logout.
- `GET /api/hotel`, through `withoutMiddlewareFor('index', 'hotel_choice')`, which keeps listing every active hotel, and is how a client recovers from a stale choice. `HotelController::index` doesn't change.
- `POST /api/connect` and `GET /api/check-paired`, because `WhatsAppDevice` is hotel-scoped and a device belongs to the home hotel. Narrowing would report a paired admin as unpaired.

`HotelController::update` runs `invalidHousekeepingDefaults` inside `TenantContext::runForHotel($hotel->getKey())`, because `$hotel->teams()` is hotel-scoped: editing hotel B with hotel A current would otherwise refuse B's own teams.

**Rationale**: `ResolveTenant` already works out the current hotel, and `resolveHotel()`, every policy and the AI tools already read `$user->hotel`. Narrowing the scope as well means the dashboard, whose figures have no hotel filter and whose cache key is that scope, needs no change. Nor do lists, or any create that relies on `BelongsToHotel` stamping the current hotel, such as `VoucherEmailRequest::create`, since its parent record is by then in that hotel. Comparing with the stored attribute means the relation isn't loaded on the normal path, so there is no new query. A deleted or missing home hotel also differs, so an owner in that state gets their current hotel rather than "You do not belong to any hotel" (V18).

**Role check**: the middleware tests `isAdmin()` to decide the scope of work, not whether something is permitted. Employees stay as they are because a staff role is read through the hotel scope (spec 024 V3), so narrowing them to a member hotel would empty their permissions.

**Why not `ResolveTenant`**: `POST /api/logout` runs `tenant`, so a refusal there would stop a user with a stale header logging out (V17). The super admin group also runs it. Why not `EnsureNotSuspended`: it is deliberately about suspension only, and skips impersonated reads.

**Alternatives**: Editing each of the roughly 40 `resolveHotel()` and `$user->hotel` call sites, which is far more change and would miss the next one. Accepting a `hotel_id` field for admins, which many endpoints ignore on purpose.

**Effects checked**:

- `GET /api/user` and login use `loadMissing('hotel')`, so with a header `hotel_id` is the default and `hotel.id` is the chosen hotel, as spec 024 already documents.
- `CreateAiInsightsJob` holds the user in an array, and spec 024 confirmed a set `hotel` relation survives serialization, so it runs for the chosen hotel. A test holds this.
- `ProactiveMessageController:21` reads a timezone from the current hotel, which is now the only hotel in its list.
- `AccountUsageController` reads `MeterEvent`, `UsageCounter` and `AiUsageLog`, none of which is hotel-scoped, so its account totals don't change.

## R4: One owner

**Decision**: `$table->unique(['hotel_group_id', 'group_role'], 'users_one_owner_per_account')`. Postgres treats nulls as distinct, so users with no role or no account never conflict. There is no check before the write. `App\Support\Superadmin\SecondOwner::matches(QueryException $e)` tests SQLSTATE `23505` and the index name, in the style of `RoomHoldConflict::matches`. `UserAdministration::create` and `changeAccess` catch it outside their transaction, and throw `DomainRuleException('This account already has an owner.', 422)`. Anything else is rethrown.

**Rationale**: A check can't close the race, and with the index in place it adds nothing. A sequential second owner takes the same path as the loser of a race, so ordinary tests cover it. Matching the name keeps a `users_email_unique` race from being reported as a second owner.

**Why not a partial index on `hotel_group_id`**: `ModelColumnRules::uniqueColumns` reads any single-column unique index as "this column is unique" and ignores its `WHERE`, so the generic `/api/users` rules would reject a shared account id (V2).

**Alternatives**: `SELECT ... FOR UPDATE` on the account row, which every writer has to remember. A `CHECK` on the role's values, which the cast and request rules already cover.

## R5: Ending ownership

**Decision**: `groupRoleAttributes` returns `['group_role' => null, 'hotel_group_id' => null]` when the role was sent as null, or the user held a role and is losing it. `moveOwnershipWithRole` and `claimOwnership` are removed, while `changeAccess:143` keeps the `hotel_user` role sync. No rule blocks ending the only ownership.

**Rationale**: Without this, moving a registered customer's hotel is refused forever through the super admin API (V5). `User::account()` falls back to the home hotel, so the user keeps the same account, and the `updated` hook recounts seats. `AppliesAuditedChanges` records both columns in the existing event.

## R6: The tenant user endpoint

**Decision**:

- `assignableAttributes` drops `hotel_id`, `hotel_group_id` and `group_role` for every caller. The `403` for a super admin target stays, since it reads the request directly. Casting `group_role` to `GroupRole` makes `ModelColumnRules::typeRules` apply the enum there, so a malformed value gets `422`.
- `UserPolicy::update` adds: if the target is an admin and isn't the caller, the caller must be an owner. `delete` adds the same, and also refuses when the target is an owner, which stops an owner deleting themselves. Only one owner exists per account, and reach keeps the check inside it, so these rules also protect the owner from everyone else. `before()` already lets a super admin through. `view` is unchanged.
- `UserController::store` and `update` refuse with `403` when an admin who isn't an owner sends `role: admin`, on a create or a change to admin, because the policy can't see the payload. An admin already holding the role and re-sending it isn't a change, and a missing role on create stays the employee default.
- `UserController::update` refuses with `403` when an owner who isn't a super admin sends a changed `role` for their own account. An owner changing another admin's role is allowed. When a super admin changes an owner's role, it adds `group_role => null, hotel_group_id => null` to the update.
- `UserController::index` and `store` don't change: they read `$user->hotel`, which is now the hotel the request works in.
- `User::isOwner()` is true when both `group_role` and `hotel_group_id` are set, the same condition under which `accessibleHotelIds()` grants account-wide reach.

**Rationale**: `managesUser` otherwise lets any admin at the owner's home hotel set the owner's password, change their role or delete them (V8). With no delete route on the super admin API, stage 6 inherits one (added to its inventory in the verification).

## R7: Advisor conversations

**Decision**: Add a nullable `agent_conversations.hotel_id`. `AdvisorTurn::startConversation` writes `$admin->hotel->id` after `storeConversation`. `AiAdvisorController`'s ownership check adds `where('hotel_id', $user->hotel->id)`, so a conversation from another hotel is the existing `404`. WhatsApp conversations stay null, because that path doesn't use the check and is fixed to the default hotel.

**Rationale**: A conversation is keyed only by participant, and `AdminToolset::for` builds tools from `$user->hotel` on each request. So "create a room", confirmed with hotel B selected, would run in hotel B after being proposed in hotel A, and the admin would have approved something else. Keeping history out of the other hotel also stops the model mixing up two hotels.

**Alternatives**: Storing the hotel in `approval_state`, which vendor code writes and which leaves the history problem. Ignoring the header for the advisor, which would leave an owner unable to use it at another hotel.

## R8: Article updates

**Decision**: `ArticleCommands::update` stops writing `hotel_id`, and drops its `$hotel` parameter. `KnowledgeBaseArticleController::update` drops its `$hotel` lookup, and `UpdateKnowledgeArticleTool:58` stops passing one. Both callers are covered by tests of the same behaviour.

**Rationale**: It writes the caller's hotel onto the article. With one hotel per request the two now match on every route, but `AdminToolset` and the command itself shouldn't depend on that, so this stays as a safeguard. A search of `'hotel_id' => $hotel` in controllers and services found this as the only stamp on an update. `GuestRegistrar:61` revives a guest it found within that hotel, and the rest are creates. Other update paths validate against `$record->hotel` already (`invalidRelation` at 10 sites).

## R9: WhatsApp stays on the default hotel

**Decision**: No change. `SenderRecognitionService` and `WhatsAppController::pair` load their own `User`, with no tenant middleware, so `$user->hotel` is the stored hotel. A test holds this.

**Rationale**: A message can't choose a hotel (spec 024 V10). The relation is set per request, on the request's own user instance.

## R10: Registration

**Decision**: In the existing transaction, `$user->update(['hotel_id' => $hotel->id, 'hotel_group_id' => $hotel->hotel_group_id, 'group_role' => GroupRole::OWNER])`. `Hotel::create` drops `owner_id`.

**Rationale**: The `creating` hook sets `hotel_group_id` before the row is written, so the value is available, and the account is always new, so the index can't conflict.

## R11: Tests

**Decision**:

- `AccountOwnershipTest` covers US2 to US6, including the second owner on both super admin paths and a distinct email conflict.
- `MultiHotelAccessTest` covers US1 and US8. Its route-driven part reuses `crossTenantRoutesFor('bound')` and `tenantOwnedModelFactories()`: an owner's `GET` on hotel B's records, with hotel B chosen, isn't `403` or `404`, leaving out routes behind a disabled feature; and an admin assigned to A and B gets `403` or `404` on hotel C for every method. Without the header, the owner gets `404` on hotel B's records.
- `crossTenantAdmin`, `TenantIsolationTest:320,570` and `PermissionAuthorizationTest:207,478` look up the hotel's admin through `$hotel->users()`.
- `SuspensionTest:336` changes to a plain `403` with no `body.reason`, and `DeletedHotelAccessTest:47` changes to `403`.

**Rationale**: The route inventory already drives the isolation tests, so a new module gets covered without a new line.

## R12: Known risks left in place

- **`GET /api/usage`** gives any admin the account's totals, naming no hotel (spec 024 V15). Unchanged, and listed in the spec's Known Limits.
- **Employee lists** show member hotels' records that then refuse to open, as clarified.
- **No combined view across hotels** for owners, which was the cost of one hotel per request.
