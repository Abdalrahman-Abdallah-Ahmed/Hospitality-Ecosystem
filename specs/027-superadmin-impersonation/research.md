# Research: Super Admin Impersonation

Every unknown is resolved. Evidence for the design corrections is in [plan-verification.md](plan-verification.md).

## R1. How a request is recognised as impersonated

**Decision**: A new `App\Support\Impersonation\CurrentImpersonation` with two static methods. `token()` returns the signed-in user's `currentAccessToken()`, checked with `Auth::hasUser()` so it never authenticates as a side effect (V29), when it is a `PersonalAccessToken` with an `impersonator_id`, otherwise null. `impersonator()` returns the super admin, or null. `EventLogger`, `ResolveTenant`, `EnsureNotImpersonating`, `GET /user` and logout all use it. `ImpersonatedRead` keeps its method check and asks it for the token.

**Rationale**: One definition, so the six callers can't disagree. It belongs in `app/Support`, because `EventLogger` is there and shouldn't depend on a service. Plan 5.2 put `current()` in the service.

**Alternatives considered**: A custom token model through `Sanctum::usePersonalAccessTokenModel`, which is more invasive for one column and two helpers. A check repeated in each caller, which is what the request rules out for `ImpersonatedRead`.

## R2. Looking up the super admin without one query per event

**Decision**: `impersonator()` stores the user on the token instance with `setRelation`. A request with many audited writes runs one lookup.

**Rationale**: The token object lives only as long as the request's authenticated user, so nothing can carry over under Octane. A static cache could.

**Alternatives considered**: `User::find` per event, as plan 5.4 has it. It works, but a bulk write adds a query per row.

Superseded by V25: only `GET /api/user` looks the super admin up now, once.

## R3. Where the actor swap goes

**Changed after code review (V25)**: the row takes `actor_id` straight from the token's `impersonator_id`, with no lookup, so R2's cached lookup isn't needed and a deleted super admin is still named.

**Decision**: Once, in `EventLogger::write()`: `actor_id` comes from the token's `impersonator_id`, and `impersonated_user_id` joins the base context. Explicit `context` still merges over it. `resolveActorKind()` and `currentActorKind()` don't change.

**Rationale**: `record()` and `recordPlatform()` both reach `write()` (V1). `actor_kind` still comes from the override or from "an actor exists", so an AI write stays `ai_agent`. A queued job has no signed-in user, so nothing swaps there (R8 in the design).

## R4. Ending a session exactly once

**Decision**: `Impersonation::finish(PersonalAccessToken $token, User $subject)` runs one transaction. It deletes through `PersonalAccessToken::whereKey($id)->delete()` and records the event only if that removed a row. All three endings use it: the end endpoint, the per-user endpoint and logout.

**Rationale**: `SC-006` requires exactly one end event. `$model->delete()` doesn't report whether a row went, so two concurrent requests could both record one. The affected-row count settles this without a lock.

**Running session query**: `whereNotNull('impersonator_id')->where('expires_at', '>', now())`, used by both super admin endpoints. It gives V8 (expired means not found). A row whose `tokenable` is null gives V9: the end endpoint answers `404`. Logout never sees either, because the guard has already returned `401`.

**Logout's subject**: `$request->user()`, loaded before the delete.

## R5. Starting a session

**Decision**: One transaction: `createToken('impersonation', ['*'], now()->addMinutes(30))`, then `accessToken->forceFill(['impersonator_id' => …])->save()`, then the start event. The plain token is returned once, and never passed to the logger.

**Rationale**: Without the transaction, a failed second step would leave a live 30-minute token with no `impersonator_id`, which behaves as a normal login token with no refusals. `impersonator_id` isn't fillable on Sanctum's model, so `forceFill` is needed.

A super admin target throws `DomainRuleException` (`422`), as the existing administration rules do.

## R6. Refusing writes before binding

**Decision**: `EnsureNotImpersonating`, alias `not_impersonating`, returning `apiResponse('This action is not available while impersonating.', 403)`. Applied with `->middleware()` on `POST /connect` and `POST /team/{team}/members`, and with `middlewareFor(['store', 'update', 'destroy'], …)` on the `/users` and `/staff-roles` resources. Registered last in `bootstrap/app.php` with `prependToPriorityList(SubstituteBindings::class, …)`.

**Rationale**: `update` registers `PUT` and `PATCH` together, so three actions cover all four methods. Priority places it directly before binding and after `hotel_choice` (V14).

## R7. Recording writes to the five unaudited record types (FR-019b)

**Decision**: A new model trait, `App\Models\Concerns\RecordsImpersonatedWrites`, on `Hotel`, `Team`, `TaskCategory`, `ActivityCategory` and `HotelPolicy`. It hooks `created`, `updated` and `deleted`, returns at once unless `CurrentImpersonation::token()` is set, and otherwise calls `EventLogger::record($model, $verb, context: ['changed_fields' => […]])`.

- **Events**: `hotel.updated`, `team.created`, `task_category.deleted` and so on, from `record()`'s naming.
- **`changed_fields`**: attribute names from `getChanges()` on update and `getAttributes()` on create, leaving out `id` and timestamps. Empty on delete.
- **No change, no row**: Eloquent fires `updated` only when something is dirty. If only timestamps changed, the trait records nothing.
- **Refused writes**: they never reach the model.
- **Rollback**: the event commits or rolls back with the write.

**Rationale**:

- Model events catch every write path to these records, including a future endpoint or tool, with no call to remember, as `RecordsEvents` does. No agent is given `CreateHotelPolicyTool` today (`tests/Inventory/ai_tools.php:15`), so this isn't needed for AI writes yet.
- A second trait, rather than `RecordsEvents`, is what keeps other callers unaffected. `RecordsEvents` records for everyone and stores values.
- `changed_fields` goes in `context`, because `changes` means `{field: {from, to}}` everywhere else. `record()`'s docblock type widens to allow a list of strings.

**`PUT /api/hotel/{id}` can write two rows**: a guest memories toggle already records `hotel.guest_memories_toggled`, and it stays. That is a distinct event, not a duplicate.

**Alternatives considered**: Explicit calls in five controllers, which are easy to forget in a new endpoint.

## R8. Trimming history for hotel readers (FR-019c)

**Decision**: In `EventLogResource`. When the reader isn't a super admin and the row's actor is a `User` who is a super admin, `actor` becomes `{id, name}` and `context` drops `ip`. The same applies when `actor_type` is a user and the actor row no longer exists, because a deleted super admin can't be told apart from a deleted hotel user and the IP shouldn't appear either way.

**Rationale**: `EventLogResource` is the only place event rows leave the API, and only `HistoryController` uses it, with `actor` eager-loaded. `AiActivityResource` exposes no actor, context or IP. The reader comes from `$request->user()`, so an impersonation token counts as a hotel reader. No stored row changes.

## R9. Sentry

**Decision**: `ResolveTenant` adds `impersonating` and `impersonator_id` to its existing `TagsSentryScope::tag` call, passing `null` for both on a normal request (V13).

**Rationale**: It runs on both stacks. `tag()` removes a null key, which stops a tag surviving to the next request on a long-running process. `'true'` is a string, as `suspended` already is.

## R10. Inventory and route tests

**Decision**: Add `'sanctum:prune-expired --hours=1'` to the `scheduled` list. Add no route entries. Add three rows to the `administration routes` dataset in `AccessGuardTest`.

**Rationale**: `EntryPointDiscovery::tenantRoutes` includes super admin routes, and `crossTenantRuleFor` classifies them through `super_admin`, so the inventory needs nothing for them. `AccessGuardTest` then proves each refuses non-super-admins, tokenless calls and calls with no API key.

## R11. Test harness

**Decision**: A new `tests/Feature/Superadmin/ImpersonationTest.php`, using real tokens from the start endpoint sent as Bearer headers.

- `Sanctum::actingAs` gives a `TransientToken`, which never counts as impersonation.
- Call `app('auth')->forgetGuards()` between requests, or the first request's user is reused and a stale token still passes (`AuthTest.php:197`).
- Expiry uses `$this->travel()`.
- Sentry follows `SuspensionTest.php:276-289`: set `sentry.dsn`, then `pushScope`/`popScope`.
- The expiry boundary is asserted at 29:59 (works) and 30:01 (`401`), not at exactly 30 minutes: Sanctum's `isPast()` is false at that exact instant, so a frozen clock would give `200`.
