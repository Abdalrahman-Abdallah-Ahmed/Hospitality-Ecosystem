# Research: Account and Hotel Suspension

The Technical Context has no open unknowns. These decisions settle the design questions the spec leaves to planning. V numbers refer to [plan-verification.md](plan-verification.md).

## R1: The one rule

**Decision**:

- `HotelGroup::isSuspended()` is `suspended_at !== null`.
- `Hotel::scopeWithSuspension()` adds `account_suspended_at`, a subselect on `HotelGroup::withTrashed()`, keeping the hotel's own columns as `scopeWithUserCount` does.
- `Hotel::isSuspended()` is true when `suspended_at` is set, or `account_suspended_at` is set when that attribute was selected. Otherwise it runs one `withTrashed` lookup of the group.
- `Hotel::scopeNotSuspended()` is the design's `whereNull` plus `whereNotIn` subquery, with no join, because both tables have `id` and `is_active`. `hotel_group_id` is NOT NULL, so `NOT IN` is safe.
- `Hotel::scopeSuspended()` is its inverse, for the list filter.

**Rationale**: The `hotelGroup` relation and `User::account()` hide a soft-deleted account by default, so an unqualified read would count one account differently on different paths (V11). Selecting the account's state in the same query keeps lists and the middleware to one query.

**Alternatives**: A join, which makes `id` and `is_active` ambiguous in every caller. A database view, which is more than one rule needs.

## R2: The shared calculation

**Decision**: `App\Support\Suspension\HotelAccess::for(array $hotelIds, User $user)` runs the one query, calling `select()` before `withSuspension()` because a later `select()` would drop the subselect. `accountIsSuspended()` then falls back to a lookup per hotel, which stays correct but breaks the one-query limit, so a test counts the queries. It returns the hotels removed, the ids still active in their original order, and how to refuse. Login and `EnsureNotSuspended` both call it.

The middleware's steps, for a non-`null` scope:

1. An impersonated `GET` or `HEAD` passes on (R4).
2. Read the rows. With none, call `$user->account(withTrashed: true)`, and refuse with scope `account` only if it is suspended. Otherwise pass on, leaving `[]` as `[]`.
3. With nothing suspended, pass on. This is the normal path, with one query.
4. If `X-Hotel-Id` names a suspended row, refuse. A header naming anything else was already handled by `ResolveTenant`, so a stranger learns nothing.
5. If no hotels remain, refuse.
6. If the user's `hotel_id` is among the removed and they have a `staff_role_id`, call `loadMissing('staffRole')` while the scope still includes that hotel.
7. Narrow with `setHotelIds()`, and move `setCurrentHotelId()` to the first active hotel if the current one was removed.
8. If the user's `hotel_id` is among the removed, `setRelation('hotel', Hotel::find($currentId))`. Never touch the attribute.
9. Re-tag Sentry's `hotel_id` if the current hotel changed.

`scope` is decided per hotel. A refusal over a named hotel takes that hotel's scope: `account` if its account is suspended. A refusal because none remain takes `account` if any removed hotel's account is suspended, and `hotel` otherwise.

**Rationale**: Stage 2 refuses links across accounts, so with one account this matches the design exactly. If such a link exists anyway, a per-hotel answer never refuses a user over the wrong account, and it needs no separate assumption.

The role must be read before narrowing because `StaffRole` uses `BelongsToHotel` and `staffRoleError` ties a role to the default hotel (V3). After narrowing it would read as null and `permissions()` would grant nothing. `Auth::user()`, `$request->user()` and the Gate's user are one instance, so `setRelation` reaches policies and `resolveHotel()`.

Employees get "only hotels they are assigned to" without new narrowing: Stage 2 limits `group_role` to admins, so an employee's reach is their default hotel plus `hotel_user` rows.

**Consequences handled**:

- `apiLogin` and `GET /user` use `loadMissing`, so the relation stays.
- `GET /api/users/{self}` binds a separate instance, so its nested `staffRole` reads as null for that employee. Permissions are unaffected. Known limit.

**Alternatives**: Restoring the role's visibility by widening the scope would also expose the suspended hotel's data. Changing `User::permissions()` is ruled out by the request.

## R3: Applying the middleware

**Decision**: Add `not_suspended` to the group stack on `routes/api.php` line 70, and put `->withoutMiddleware('not_suspended')` on `POST /logout`. Register the alias and `prependToPriorityList(SubstituteBindings::class, EnsureNotSuspended::class)` after the existing two.

**Rationale**: This follows the Stage 1 principle: a route added to the group is covered by construction. It is one line instead of re-indenting the whole group under a nested one. `addToMiddlewarePriorityRelative` inserts each entry directly before `SubstituteBindings`, so the order is `ResolveTenant`, `EnsureFeatureEnabled`, `EnsureNotSuspended`, then binding. A disabled feature's `404` comes first, which reveals nothing.

**Consequence**: `Route::gatherMiddleware()` still lists an excluded alias, so the inventory test must also read `excludedMiddleware()`, and a behaviour test proves a suspended user can log out.

## R4: Impersonation branch

**Decision**: `ImpersonatedRead::allows(Request)` is true for `GET` or `HEAD` when `currentAccessToken()` is a `PersonalAccessToken` whose `getAttribute('impersonator_id')` is set. `EnsureNotSuspended` and `HotelController::show` use it.

**Rationale**: The column doesn't exist until stage 5. No model strict mode is on, so `getAttribute` returns null, and `actingAs` tests give a `TransientToken`, which the `instanceof` covers. Stage 5's tests cover the true branch.

## R5: Lists and filters

**Decision**:

- `FiltersBySuspension` adds `suspended` to `filterColumns()` and a rule accepting `true`, `false`, `1`, `0`, or empty for "no filter". It provides `suspendedFilter(): ?bool`. `AccountIndexRequest` and the new `Superadmin\HotelIndexRequest` use it, and the controllers apply it.
- `Superadmin\HotelResource` extends the tenant `HotelResource` and adds `suspended_at`, `suspended_by`, `suspended_reason` and `is_suspended`, so existing fields can't drift. Its list uses `withSuspension()`, so there is no N+1.
- `HotelListRequest` is the tenant list's request. For a caller who isn't an admin or super admin, `availableColumns()` returns the short record's fields only.
- `HotelController::index` adds `whereIn('hotels.id', $ids)` when `hotelIds() !== null`. For the short record, it searches only `name`, `slug` and `city`, through a new `Filterable::scopeSearchColumns`, which `scopeSearch` also uses. It clears `search` from the request first, as `Superadmin\UserController::index` does with its filter.

**Rationale**: Laravel's `boolean` rule refuses the string `true`, and a query string only has strings. `Filterable` ignores keys that aren't columns, so the virtual key needs no stripping. Hiding fields only in the response would still let their values be inferred through filter, sort and search (V16, the same problem as Stage 2's V2).

**Alternatives**: A `$searchable` list on `Hotel` would also narrow search for super admins and admins, changing a working feature.

## R6: Blocking jobs

**Decision**: `SkipIfHotelSuspended` calls `$job->hotelId()`. With null, it runs the job. Otherwise it reads `Hotel::withTrashed()->withSuspension()->find($id)`. A missing hotel runs the job. A suspended one:

1. tags Sentry `suspended => 'true'`;
2. calls `$job->failed(new HotelSuspended($id))` inside `try`, sending any exception to `report()`;
3. logs `job.skipped_suspended`;
4. returns without calling `$next`.

`CallQueuedHandler` then deletes the job, and no `JobFailed` event fires, so there is no `failed_jobs` row and no `AlertOnRepeatedJobFailures`.

Each of the 10 jobs implements `HasHotel` and puts the middleware first. `hotelId()` reads its record the way `handle()` does, without the `hotel` scope:

| Job | `hotelId()` |
| --- | --- |
| `CreateAiInsightsJob` | `$this->data['user']->hotel?->id`. The array isn't touched by `SerializesModels`, and the relation survives serialization (confirmed), so this is the current hotel |
| `ExtractGuestMemoriesJob` | `$this->hotelId` |
| `GenerateActivityRecommendationsJob` | `$this->reservation->hotel_id` |
| `IndexKnowledgeDocumentJob` | The document's `hotel_id`. Keeps `WithoutOverlapping` second |
| `PrepareReservationImportJob`, `WriteReservationImportJob` | The import's `hotel_id` |
| `SendGuestRequestNoticeJob` | The task's `hotel_id` |
| `SendProactiveMessageJob` | The message's `hotel_id` |
| `SendReservationVoucherEmailJob` | The request's `hotel_id` |
| `SyncKnowledgeChunksJob` | The chunkable's `hotel_id`, using the same non-trashed read as `handle()` |

A deleted record gives null, so the job runs and `handle()` finishes it as it does today, including reporting to a rebuild.

**Rationale**: A throwing handler inside job middleware would fail the job and bring back the row and alert the design rules out (V7).

**Consequences handled**: `IndexKnowledgeDocumentJob::failed` and `SyncKnowledgeChunksJob::failed` report to a rebuild, so it finishes (V9). `SendProactiveMessageJob::failed` only touches `SENDING` rows, so a blocked `SCHEDULED` row stays, and the next `EvaluateProactiveTriggersJob` redispatches it.

**Alternatives**: Alternative E in the design, gating inside `runForHotel`, also blocks the failure paths that run inside it.

## R7: Looping and exempt jobs

**Decision**:

- `->notSuspended()`: `EvaluateProactiveTriggersJob`, `ExpireRecommendationsJob`, `StartHousekeepingDayJob`, and both branches of `MatchRecommendationOutcomesJob` (its single-hotel path too).
- `RetryGuestMemoryExtractionsJob`: `whereIn('hotel_id', Hotel::notSuspended()->select('id'))`.
- `PurgeDeletedKnowledgeDocumentsJob`: the design's filter, keeping global documents and those of soft-deleted hotels. `removeOrphanedHotelDirectories` already keeps any hotel that still has a row.
- Exempt, with reasons held in `JobSuspensionInventoryTest`: `FlagAiCostOverrunsJob`, `RebuildUsageCountersJob`, `PruneReservationImportPreviewsJob`, `SendWhatsAppMessageJob`, `ProcessInboundWhatsAppMessageJob`.

Those 10, 6 and 5 are all 21 classes `EntryPointDiscovery::queuedJobs()` finds.

## R8: WhatsApp

**Decision**: In `replyFor()`, after the not-paired and keyword checks and before the `generation_started_at` check:

- The hotel to test is `$this->hotel`, or else the sender's `hotel_id` read with `withTrashed`. If there is none and the sender is a `User`, test `account(withTrashed: true)`.
- If suspended, `Cache::has('suspended-reply:'.$id.':'.$phone)` decides. It returns null while the entry exists.
- The reply is `__('suspension.guest_reply', [], $locale)`, with `$locale` chosen the way `contactPreferenceReply()` does: `ar` for a `Guest` whose `preferred_language` is `ar`, and `en` for everyone else, admins included (V17).

`replyFor()` becomes `?string`. In `handle()`, a null reply sets `THROTTLED` and returns before the send.

**Rationale**: `reply_text` is stored once decided, so a retried send repeats it without touching the cache. `generation_started_at` stays empty, so `PitchCoordinator::delivered` doesn't run. `SerializesModels` reloads the hotel for each attempt, so the state is fresh. `recordContact` still runs first, because the guest did contact the hotel. The entry is written with `Cache::put` only after the send succeeds, so a crash or failed send doesn't leave the sender untold. `WithoutOverlapping` runs one message per sender at a time, so two can't both pass the check. The check sits below the `generation_started_at` guard, so a turn that already started keeps its existing reply path. The advisor never moves to another hotel (FR-035a).

## R9: Suspending and auditing

**Decision**: `Suspension` has `suspendAccount`, `reactivateAccount`, `suspendHotel` and `reactivateHotel`. Each runs in a transaction: lock the row with `lockForUpdate`, refuse with `DomainRuleException(..., 422)` if already in the target state, `forceFill` and save, then `recordPlatform`. The suspend event carries `context: ['reason' => $reason]`.

**Rationale**: `recordPlatform` has no reason argument, so `event_logs.reason` can't be reached, and `context` needs no signature change (V8). The lock gives the concurrent case its `422`. Rows have a null `hotel_id`, so staff can't see the reason.

## R10: Testing Sentry tags

**Decision**: Tag tests set `config(['sentry.dsn' => ...])` with a fake DSN and read the current scope. `TagsSentryScope::tag` does nothing without one. `QueueIntegration` pushes a scope for each job, so a blocked job's tag doesn't carry over to the next one.
