---

description: "Task list for account and hotel suspension"
---

# Tasks: Account and Hotel Suspension

**Input**: Design documents from `specs/024-superadmin-suspension/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [plan-verification.md](plan-verification.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/suspension.md](contracts/suspension.md), [quickstart.md](quickstart.md)

**Tests**: Included. `CLAUDE.md` and the constitution require them, and the spec's success criteria and [quickstart.md](quickstart.md) name what they must prove.

**Organization**: Tasks are grouped by user story. US1 creates the service, controller actions and middleware, and later stories add to them.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: The user story in [spec.md](spec.md) this task belongs to

## Rules for every task

- `User`, `ResolveTenant` and `TenantContext` don't change. Never write the `hotel_id` attribute of a user from suspension code.
- Keep `null` and `[]` distinct for `TenantContext::hotelIds()`: check `!== null`, not truthiness.
- Controllers stay thin and return through `apiResponse()` and Resources. Suspension fields appear only on `App\Http\Resources\Superadmin\*`, never on the tenant `HotelResource`.
- Feature tests: `uses(RefreshDatabase::class)`, set the API key in `beforeEach`, build records with `Model::create([...])`, and call `forgetGuards()` between token requests in one test. Run with `php artisan test --parallel` and no env prefix. Never point tests at the dev database.
- Write each story's tests first, and see them fail before implementing it.
- Run `./vendor/bin/pint` before proposing a commit. Commit only when asked.

---

## Phase 1: Setup

**Purpose**: Branch and baseline

- [X] T001 Create and switch to branch `024-superadmin-suspension` from `main`, leaving the unrelated untracked `storage/framework/lsp-*.php` file out of any commit
- [X] T002 Check `docker ps -a` first. If `hospitality-postgres` is running, use it. Run `docker compose -f .postgres/compose.yaml up -d` only if it isn't, and not while another project's `pilot-postgres` is in use, because they share a Compose project name and that command replaces the container. Never drop or recreate the shared volume. Create the `Hospitality_Ecosystem_testing` database if it is missing. Then run `php artisan test --parallel` to record a passing baseline before any change, reporting existing failures rather than fixing them here

---

## Phase 2: Foundational

**Purpose**: Pieces every story uses. **No story can start until this phase is done.**

- [X] T003 Create `database/migrations/2026_11_07_000001_add_suspension_to_hotel_groups_and_hotels.php`, adding to both `hotel_groups` and `hotels`: `suspended_at` (timestampTz, nullable, indexed), `suspended_by` (uuid, nullable, foreign key to `users`, null on delete) and `suspended_reason` (text, nullable). Do not reuse `is_active`
- [X] T004 Add to `app/Models/HotelGroup.php`: cast `suspended_at` to datetime, keep all three columns out of `$fillable`, and add `isSuspended()` (`suspended_at !== null`) and `scopeSuspended()`, per [research R1](research.md#r1-the-one-rule)
- [X] T005 Add to `app/Models/Hotel.php`: cast `suspended_at`, keep all three columns out of `$fillable`, and add `scopeWithSuspension()` (selects `hotels.*` when no columns are set, plus an `account_suspended_at` subselect on `HotelGroup::withTrashed()`), `isSuspended()`, `scopeNotSuspended()` (`whereNull('hotels.suspended_at')->whereNotIn('hotel_group_id', HotelGroup::withTrashed()->whereNotNull('suspended_at')->select('id'))`, no join) and `scopeSuspended()`, per [research R1](research.md#r1-the-one-rule)
- [X] T006 [P] Create `lang/en/suspension.php` with `account` => "This account has been suspended." and `hotel` => "This hotel has been suspended.", plus `guest_reply` with the exact text of `ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY`. Create `lang/ar/suspension.php` with only `guest_reply` => "عذراً، لا يمكنني الرد على الرسائل الآن. يرجى التواصل مع الفندق مباشرة للمساعدة."
- [X] T007 Create `app/Support/Suspension/SuspendedResponse.php`: one method taking `account` or `hotel` and returning `apiResponse($message, 403, ['reason' => 'suspended', 'scope' => $scope])`, with the message from `lang/en/suspension.php`, plus a helper giving a `Hotel`'s scope (`account` when its account is suspended, otherwise `hotel`)
- [X] T008 Create `app/Support/Suspension/HotelAccess.php`: `for(array $hotelIds, ?User $user)` runs one query, `Hotel::select(['hotels.id', 'hotels.hotel_group_id', 'hotels.suspended_at'])->withSuspension()->whereIn('hotels.id', $hotelIds)`, with `select` first because a later `select()` would drop the subselect and cost a query per hotel, and exposes the removed ids, the active ids in their original order, and the refusal scope, decided per hotel. With no rows it uses `$user->account(withTrashed: true)`, only then. See [research R2](research.md#r2-the-shared-calculation)
- [X] T009 [P] Create `app/Support/Suspension/ImpersonatedRead.php`: `allows(Request)` is true only for `GET` or `HEAD` when `currentAccessToken()` is a `Laravel\Sanctum\PersonalAccessToken` whose `getAttribute('impersonator_id')` is set, per [research R4](research.md#r4-impersonation-branch)
- [X] T010 Create `tests/Feature/SuspensionModelTest.php`: for the four states in the [data model table](data-model.md#suspension-state), `isSuspended()`, `notSuspended()` and `suspended()` agree, including a soft-deleted suspended account, and a mass `update()` can't write any `suspended_*` column
- [X] T011 Run `php artisan migrate` and the existing suite with `php artisan test --parallel`

**Checkpoint**: The migration runs, the model rule is proven, and the existing suite still passes.

---

## Phase 3: User Story 1 - Stop a non-paying account (Priority: P1) 🎯 MVP

**Goal**: A super admin suspends an account or hotel with a reason, and staff are refused on their next request and at login.

**Independent Test**: Suspend an account with two hotels. A signed-in employee's next request and a new login both get the `403` with `scope: account`, and logout still works.

- [X] T012 [P] [US1] Create `tests/Feature/SuspensionAdministrationTest.php`: all four outcomes of suspend for accounts and hotels from [the contract](contracts/suspension.md#super-admin-endpoints), including a missing reason, `422` on already suspended with the original values kept, `404` on a soft-deleted id, `403` for a non-super-admin, and a `platform.*.suspended` event with `hotel_id` null, the actor and `context.reason`
- [X] T013 [P] [US1] Create `tests/Feature/SuspensionTest.php` with the CUJ 2 tests: a real token from `createToken`, not `actingAs`, so the request goes through `ImpersonatedRead` with no `impersonator_id` column (FR-022), gets the `403` with `body.reason` and `body.scope`, login with the right password gets the same and no token is created, a wrong password gets `401`, `POST /api/logout` succeeds, and the effect is on the very next request (FR-009)
- [X] T014 [P] [US1] Create `tests/Feature/SuspensionRouteInventoryTest.php`: every `auth:sanctum` route has `not_suspended` in `gatherMiddleware()` and not in `excludedMiddleware()`, except the `super_admin` routes, `POST api/logout` and `POST logout` from `routes/web.php`. Fail by route name (FR-041, V13)
- [X] T015 [US1] Create `app/Services/Superadmin/Suspension.php` with `suspendAccount` and `suspendHotel`, taking the record, the reason and the acting user. In a transaction: `lockForUpdate`, throw `DomainRuleException(..., 422)` if already suspended in its own right, `forceFill` and save the three columns, then `EventLogger::recordPlatform('platform.account.suspended' or 'platform.hotel.suspended', $record, context: ['reason' => $reason])`, per [research R9](research.md#r9-suspending-and-auditing)
- [X] T016 [P] [US1] Create `app/Http/Requests/Superadmin/SuspendRequest.php` requiring `reason` (string), in the same style as the other requests in that folder
- [X] T017 [P] [US1] Create `app/Http/Resources/Superadmin/HotelResource.php`, extending `App\Http\Resources\HotelResource` and merging `suspended_at`, `suspended_by`, `suspended_reason` and `is_suspended`, the last from `$this->resource->isSuspended()`
- [X] T018 [P] [US1] Add `suspended_at`, `suspended_by` and `suspended_reason` to `app/Http/Resources/Superadmin/AccountResource.php`
- [X] T019 [US1] Add `suspend` to `app/Http/Controllers/Superadmin/AccountController.php` (returning through its existing `resource()` read-back), and to `app/Http/Controllers/Superadmin/HotelController.php`, whose every response now uses `Superadmin\HotelResource` in place of the tenant one
- [X] T020 [US1] Add `POST /accounts/{account}/suspend` and `POST /hotels/{hotel}/suspend` to `routes/superadmin.php`
- [X] T021 [US1] Create `app/Http/Middleware/EnsureNotSuspended.php` with steps 1 to 5 of [research R2](research.md#r2-the-shared-calculation): pass on for a `null` scope and for `ImpersonatedRead`, call `HotelAccess`, and return `SuspendedResponse` with Sentry tagged through `TagsSentryScope::tag(['suspended' => 'true'])` when it refuses
- [X] T022 [US1] In `bootstrap/app.php`, register the alias `not_suspended` and add `prependToPriorityList(SubstituteBindings::class, EnsureNotSuspended::class)` after the two existing entries
- [X] T023 [US1] In `routes/api.php`, add `not_suspended` to the group stack on line 70, and put `->withoutMiddleware('not_suspended')` on `POST /logout`, per [research R3](research.md#r3-applying-the-middleware)
- [X] T024 [US1] In `apiLogin` in `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, after `Auth::attempt` succeeds and before `createToken`: skip for a super admin, otherwise call `HotelAccess` on `$user->accessibleHotelIds()` and return `SuspendedResponse` with no token when it refuses

**Checkpoint**: Accounts and hotels can be suspended, and fully suspended users are stopped. The route inventory test passes.

---

## Phase 4: User Story 2 - Reactivate with everything intact (Priority: P1)

**Goal**: Reactivating restores access at once, with data kept and each level independent.

**Independent Test**: Suspend and reactivate an account. The old token and login work, and a hotel suspended in its own right stays suspended.

- [X] T025 [P] [US2] Add reactivation tests to `tests/Feature/SuspensionAdministrationTest.php`: `200` with the columns cleared, a `platform.*.reactivated` event, `422` with no event when not suspended in its own right, a hotel suspended inside a suspended account staying suspended with `scope: hotel` after the account returns, and reactivating a hotel in a suspended account leaving `scope: account` (FR-003, FR-005, V6)
- [X] T026 [P] [US2] Add to `tests/Feature/SuspensionTest.php`: after reactivation the old token and login work and the customer's records are unchanged. Add the edge cases: a restored suspended hotel keeps its suspension, a hotel moved into a suspended account counts as suspended, and a hotel or user created in a suspended account counts as suspended
- [X] T027 [US2] Add `reactivateAccount` and `reactivateHotel` to `app/Services/Superadmin/Suspension.php`: same transaction and lock, `DomainRuleException(..., 422)` if not suspended in its own right, clear the three columns, and record `platform.account.reactivated` or `platform.hotel.reactivated`
- [X] T028 [US2] Add `reactivate` to `app/Http/Controllers/Superadmin/AccountController.php` and `app/Http/Controllers/Superadmin/HotelController.php`
- [X] T029 [US2] Add `POST /accounts/{account}/reactivate` and `POST /hotels/{hotel}/reactivate` to `routes/superadmin.php`

**Checkpoint**: A customer can be stopped and restored through the API.

---

## Phase 5: User Story 3 - Suspend one hotel of a multi-hotel user (Priority: P1)

**Goal**: A user with other hotels carries on in them, even when the suspended hotel is their default.

**Independent Test**: Give a user hotels A (default) and B, suspend A, then read, list and create with no header and with `X-Hotel-Id: B`.

- [X] T030 [P] [US3] Add the CUJ 3 tests to `tests/Feature/SuspensionTest.php`: login succeeds with `user.hotel.id` = B, `GET /api/user` agrees and the stored `hotel_id` stays A, a dashboard read, a policy-guarded `GET` and a create all act on B with no header and with `X-Hotel-Id: B`, `X-Hotel-Id: A` gets the `403` with `scope: hotel`, and A's record id with no header gets `404` (FR-021)
- [X] T031 [US3] Add employee tests to `tests/Feature/SuspensionTest.php`: an employee of A with a `hotel_user` row for B and a staff role in A keeps that role's permissions in B, and one with no such row gets the `403` even with other active hotels in the account (V3). Add a user whose hotels are all suspended getting `scope: hotel`
- [X] T032 [US3] Add a Sentry test to `tests/Feature/SuspensionTest.php`, setting a fake `sentry.dsn` per [research R10](research.md#r10-testing-sentry-tags): a blocked request has `suspended = 'true'`, and a request moved from A to B has `hotel_id` = B (V14)
- [X] T033 [US3] Add steps 6 to 9 of [research R2](research.md#r2-the-shared-calculation) to `app/Http/Middleware/EnsureNotSuspended.php`: when the user's `hotel_id` is removed and they have a `staff_role_id`, `loadMissing('staffRole')` before narrowing; narrow with `setHotelIds()` and `setCurrentHotelId()`; `setRelation('hotel', Hotel::find($currentId))` when `hotel_id` was removed; and re-tag Sentry's `hotel_id` when the current hotel changed
- [X] T034 [US3] In `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, when the default hotel is suspended and another is active, set the `hotel` relation to the first active hotel, and change `load` to `loadMissing`
- [X] T035 [US3] In the `GET /user` closure in `routes/api.php`, change `load` to `loadMissing`

**Checkpoint**: Multi-hotel users keep working, and the route-bound record of a suspended hotel isn't found.

---

## Phase 6: User Story 4 - AI and guests during a suspension (Priority: P1)

**Goal**: Guests and admins of a suspended hotel get one fixed reply a day with no AI run, and STOP still works.

**Independent Test**: Suspend a hotel, send messages from a guest and a paired admin, and check the replies, the number of sends and that no AI run was recorded.

- [X] T036 [P] [US4] Create `tests/Feature/SuspensionWhatsAppTest.php` with the [WhatsApp scenarios](quickstart.md#whatsapp), using a mocked sender and an AI fake: fixed reply with no AI run or usage, in Arabic for a guest whose `preferred_language` is `ar` and otherwise in English, with `__('suspension.guest_reply', [], 'en')` equal to `UNAVAILABLE_REPLY`, a repeat within 24 hours sends nothing with status `throttled`, the reply returns after 24 hours (`$this->travel`), a retried send still goes out, STOP applied and confirmed, an unpaired admin's not-paired reply, a paired admin's fixed reply including one whose default is suspended with other active hotels, and an admin with a soft-deleted default hotel in a suspended account
- [X] T037 [US4] In `app/Jobs/ProcessInboundWhatsAppMessageJob.php`, add the check in `replyFor()` after the not-paired and keyword checks and before the `generation_started_at` check, with the sender fallback and `Cache::add` limit from [research R8](research.md#r8-whatsapp). Change the return type to `?string`, and in `handle()` set `InboundMessageStatus::THROTTLED` and return before the send on a null reply. Build the reply with `__('suspension.guest_reply', [], $locale)`, with `$locale` as `ar` for a `Guest` whose `preferred_language` is `ar` and `en` otherwise. The job doesn't use `SkipIfHotelSuspended`
- [X] T038 [P] [US4] Update the `THROTTLED` comment in `app/Enums/InboundMessageStatus.php` to cover a suppressed fixed reply as well as the rate limit

**Checkpoint**: A suspended hotel costs no AI on WhatsApp.

---

## Phase 7: User Story 5 - Background work stops (Priority: P1)

**Goal**: Hotel jobs don't run for a suspended hotel, and leave no failure record or alert.

**Independent Test**: Queue one of each hotel job, suspend the hotel, process the queue, and check that nothing ran and no `failed_jobs` row exists.

- [X] T039 [P] [US5] Create `tests/Feature/JobSuspensionInventoryTest.php` in the style of `tests/Feature/QueueLaneTest.php`: each class from `EntryPointDiscovery::queuedJobs()` must be a job implementing `HasHotel` whose source contains `SkipIfHotelSuspended`, a looping job (the 6 in [research R7](research.md#r7-looping-and-exempt-jobs)) whose source contains `notSuspended`, or on the exempt list of 5 with a stated reason. Fail by class name
- [X] T040 [P] [US5] Create `tests/Feature/SuspensionJobTest.php` with the [Jobs scenarios](quickstart.md#jobs): each of the 10 jobs skipped for a suspended hotel and for a suspended account, an import and a voucher email ending failed, a proactive message staying `SCHEDULED` and then, after reactivation, sent by the next `EvaluateProactiveTriggersJob` run if still valid and not sent if past `valid_until` (FR-032), no `failed_jobs` row and no `JobFailed` event, a throwing `failed()` handler leaving no row and reaching `report()`, global items running, a rebuild finishing with suspended items counted as failed, each looping job skipping a suspended hotel, guest memory retries resuming after reactivation, and purge keeping a suspended hotel's documents while removing global ones and those of soft-deleted hotels. Add a Sentry test for `suspended = 'true'` per [research R10](research.md#r10-testing-sentry-tags)
- [X] T041 [P] [US5] Create `app/Contracts/HasHotel.php` declaring `hotelId(): ?string`
- [X] T042 [P] [US5] Create `app/Exceptions/HotelSuspended.php`, a `RuntimeException` taking the hotel id
- [X] T043 [US5] Create `app/Jobs/Middleware/SkipIfHotelSuspended.php` per [research R6](research.md#r6-blocking-jobs): a null id or missing hotel runs the job. For a suspended one, tag Sentry, call `$job->failed(new HotelSuspended($id))` when the method exists inside `try` with `report()` on failure, log `job.skipped_suspended`, and return without calling `$next`
- [X] T044 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/CreateAiInsightsJob.php`, with `hotelId()` returning `$this->data['user']->hotel?->id`
- [X] T045 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/ExtractGuestMemoriesJob.php`, with `hotelId()` returning `$this->hotelId`
- [X] T046 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/GenerateActivityRecommendationsJob.php`, with `hotelId()` returning `$this->reservation->hotel_id`
- [X] T047 [P] [US5] Implement `HasHotel` in `app/Jobs/IndexKnowledgeDocumentJob.php`, with `hotelId()` reading the document's `hotel_id` with `withoutGlobalScope('hotel')`, and put the middleware before the existing `WithoutOverlapping`
- [X] T048 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/PrepareReservationImportJob.php`, with `hotelId()` reading the import's `hotel_id` without the `hotel` scope
- [X] T049 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/WriteReservationImportJob.php`, with `hotelId()` reading the import's `hotel_id` without the `hotel` scope
- [X] T050 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/SendGuestRequestNoticeJob.php`, with `hotelId()` reading the task's `hotel_id` without the `hotel` scope
- [X] T051 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/SendProactiveMessageJob.php`, with `hotelId()` reading the message's `hotel_id` without the `hotel` scope
- [X] T052 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/SendReservationVoucherEmailJob.php`, with `hotelId()` reading the request's `hotel_id` without the `hotel` scope
- [X] T053 [P] [US5] Implement `HasHotel` and add the middleware to `app/Jobs/SyncKnowledgeChunksJob.php`, with `hotelId()` reading the chunkable's `hotel_id` the way `handle()` does, so a deleted one gives null
- [X] T054 [P] [US5] Add `->notSuspended()` to the hotel query in `app/Jobs/EvaluateProactiveTriggersJob.php`
- [X] T055 [P] [US5] Add `->notSuspended()` to the hotel query in `app/Jobs/ExpireRecommendationsJob.php`
- [X] T056 [P] [US5] Add `->notSuspended()` to both branches of the hotel query in `app/Jobs/MatchRecommendationOutcomesJob.php`, including the single-hotel path
- [X] T057 [P] [US5] Add `->notSuspended()` to the hotel query in `app/Jobs/StartHousekeepingDayJob.php`
- [X] T058 [P] [US5] Add `whereIn('hotel_id', Hotel::notSuspended()->select('id'))` to the query in `app/Jobs/RetryGuestMemoryExtractionsJob.php`
- [X] T059 [P] [US5] Add `where(fn ($q) => $q->whereNull('hotel_id')->orWhereIn('hotel_id', Hotel::withTrashed()->notSuspended()->select('id')))` to the purge query in `app/Jobs/PurgeDeletedKnowledgeDocumentsJob.php`

**Checkpoint**: All P1 stories are done. A suspended hotel does no background work and sets off no alert.

---

## Phase 8: User Story 6 - Nothing about suspension leaks or can be undone by a tenant (Priority: P2)

**Goal**: Only a super admin changes suspension, only someone with real access learns of it, and the reason stays hidden.

**Independent Test**: Write the suspension fields through tenant requests, request a suspended hotel as a stranger, and read tenant responses for any suspension field.

- [X] T060 [P] [US6] Add to `tests/Feature/SuspensionTest.php`: tenant `PUT /api/hotel/{id}` can't write a `suspended_*` column, a stranger's `GET /api/hotel/{id}` and `X-Hotel-Id` on a suspended hotel give no `reason = suspended`, a suspended hotel's owner gets the suspended `403` on `GET` and `PUT`, an active hotel's owner with no membership gets `200`, no tenant hotel or user response contains a `suspended_*` key, a user with no hotels gets today's behaviour, an owner with no hotels in a suspended account gets `scope: account`, a super admin reads a suspended hotel through `GET /api/hotel/{id}` and `GET /api/superadmin/hotels/{hotel}`, and a tenant event log never shows a suspension event
- [X] T061 [US6] In `show` and `update` in `app/Http/Controllers/HotelController.php`, right after `authorize`: return `SuspendedResponse` with the hotel's scope when `TenantContext::hotelIds() !== null`, `$hotel->isSuspended()` is true, and, on `show`, `ImpersonatedRead::allows($request)` is false. `show` gains a `Request` parameter (FR-023, V2)

**Checkpoint**: A suspended hotel's state reaches only those authorized to see it.

---

## Phase 9: User Story 7 - Staff can list their own hotels (Priority: P2)

**Goal**: Admins and employees holding `hotels.view` list their own active hotels, with employees seeing a short record.

**Independent Test**: Call `GET /api/hotel` as an admin, an employee with and without `hotels.view`, and users in several hotels.

- [X] T062 [P] [US7] Add `GET /api/hotel` to the dataset in `tests/Feature/PermissionAuthorizationTest.php` under `hotels.view`: allowed with it, `403` without it, including an employee with no role
- [X] T063 [P] [US7] Change the list expectations in `tests/Feature/HotelControllerTest.php` (the test at line 38) and `tests/Feature/GenericCrudTest.php` (the test at line 49) so an admin gets `200` with only reachable hotels, and rename both. Leave the non-owner update test at `GenericCrudTest.php:82` as `403`
- [X] T064 [US7] Add to `tests/Feature/HotelControllerTest.php`: CUJ 3's list shows only B, another customer's hotel is never listed, an employee with `hotels.view` gets exactly `id`, `name`, `slug`, `timezone`, `currency` and `city`, that employee's `filter[email]` and `sort=email` get `422` and a search on an email value finds nothing, the same permission doesn't open `GET` or `PUT /api/hotel/{id}`, and an admin gets the full record (V16)
- [X] T065 [US7] Add `case HOTELS_VIEW = 'hotels.view';` to `app/Enums/Permission.php`, grouped with the others and not in `employeeDefaults()`, and change the docblock to name this read as the exception to hotel settings staying with admins
- [X] T066 [US7] In `app/Policies/HotelPolicy.php`, add `use ChecksPermissions`, and make `viewAny` return `$this->allows($user, Permission::HOTELS_VIEW)`. Leave `before`, `view` and `update` as they are
- [X] T067 [P] [US7] Add `scopeSearchColumns(Builder $query, ?string $term, array $columns)` to `app/Models/Concerns/Filterable.php`, and have `scopeSearch` call it with no change in behaviour
- [X] T068 [P] [US7] Create `app/Http/Resources/HotelSummaryResource.php` with exactly `id`, `name`, `slug`, `timezone`, `currency` and `city`
- [X] T069 [P] [US7] Create `app/Http/Requests/HotelListRequest.php`, extending `GenericIndexRequest`: `showsFullRecords()` is true for admins and super admins only, and `availableColumns()` returns the short record's fields for anyone else
- [X] T070 [US7] Change `index` in `app/Http/Controllers/HotelController.php` to take `HotelListRequest`, add `whereIn('hotels.id', $ids)` when `TenantContext::hotelIds() !== null`, and, when `showsFullRecords()` is false, search `name`, `slug` and `city` through `searchColumns` (clearing `search` from the request first) and return `HotelSummaryResource`, per [research R5](research.md#r5-lists-and-filters)

**Checkpoint**: The hotel list works for each caller, and `GET /api/permissions` shows the new permission.

---

## Phase 10: User Story 8 - The operator can see and find what is suspended (Priority: P3)

**Goal**: A super admin finds suspended customers and sees why each was stopped.

**Independent Test**: Suspend one account and another account's hotel, then filter both lists and read the records.

- [X] T071 [P] [US8] Add to `tests/Feature/SuspensionAdministrationTest.php`: `filter[suspended]` of `true`, `false`, `1` and `0` on both lists, `banana` and an array value get `422`, a hotel in a suspended account is included with `is_suspended` true and its own fields null, the list runs no per-row query for `is_suspended`, and `sort=suspended` gets `422`
- [X] T072 [US8] Create `app/Http/Requests/Superadmin/Concerns/FiltersBySuspension.php`: adds `suspended` to `filterColumns()`, a rule for `filter.suspended` accepting `true`, `false`, `1`, `0` or empty, and `suspendedFilter(): ?bool`. Laravel's `boolean` rule refuses the string `true`, so don't use it, per [research R5](research.md#r5-lists-and-filters)
- [X] T073 [P] [US8] Use `FiltersBySuspension` in `app/Http/Requests/Superadmin/AccountIndexRequest.php`, keeping its allowlist
- [X] T074 [P] [US8] Create `app/Http/Requests/Superadmin/HotelIndexRequest.php`, extending `GenericIndexRequest` and using `FiltersBySuspension`
- [X] T075 [US8] In `index` in `app/Http/Controllers/Superadmin/AccountController.php`, apply `suspended()` or `whereNull('suspended_at')` from `suspendedFilter()`
- [X] T076 [US8] In `index` in `app/Http/Controllers/Superadmin/HotelController.php`, take `HotelIndexRequest`, use `withSuspension()`, and apply `suspended()` or `notSuspended()` from `suspendedFilter()`

**Checkpoint**: All stories are done.

---

## Phase 11: Polish & Cross-Cutting

**Purpose**: Documentation and final checks

- [X] T077 [P] Update `docs/superadmin-accounts-api-documentation.md`: the four endpoints, the filter and the new fields, from [the contract](contracts/suspension.md)
- [X] T078 [P] Update `docs/auth-api-documentation.md`: the suspended login response, and `user.hotel_id` differing from `user.hotel.id`
- [X] T079 [P] Update `docs/hotel-api-documentation.md`: the list's audience and short record (lines 54 and 167), and the suspended `403` on a hotel's own routes
- [X] T080 [P] Update `docs/staff-roles-api-documentation.md`: `hotels.view` in the permission reference, and the admin-only text at lines 19–22 and 359 naming this read as the exception
- [X] T081 [P] Update `docs/whatsapp-device-api-documentation.md`: the fixed reply and its limit
- [X] T082 [P] Create `docs/latest-changes-2026-11-07.md`: the new `403` shape every client must handle, the list's new audience, and `hotel_id` differing from `hotel.id`
- [X] T083 [P] Update access control item 4 in `CLAUDE.md` to name the hotel list as the exception to hotel settings staying admin-only
- [X] T084 Run `php artisan test --parallel --filter=EntryPointInventoryTest` and `--filter=CrossTenantRouteTest`, and add anything they name to `tests/Inventory/entry_points.php` with the test that covers it
- [X] T085 Walk through [quickstart.md](quickstart.md), including the manual check, and confirm every scenario has a passing test
- [X] T086 Run `./vendor/bin/pint` and `php artisan test --parallel`, and report the result with any failures

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: After Setup. Blocks every story.
- **US1 (Phase 3)**: After Foundational. US2, US3, US6 and US8 build on what it creates.
- **US2 (Phase 4)**: After US1, using its service and controllers.
- **US3 (Phase 5)**: After US1, extending its middleware and login.
- **US4 (Phase 6)**: After Foundational only.
- **US5 (Phase 7)**: After Foundational only. Its tests use US2's reactivate, or can reset the columns directly.
- **US6 (Phase 8)**: After US1. T061 and US7's T070 edit the same controller, so do them one at a time.
- **US7 (Phase 9)**: After Foundational only. Its CUJ 3 test needs US3.
- **US8 (Phase 10)**: After US1.
- **Polish (Phase 11)**: After all stories.

### Within Each Story

Tests first, then models and services, then requests and resources, then controllers and routes.

### Parallel Opportunities

- US4, US5 and US7 can run alongside each other once Phase 2 is done.
- `tests/Feature/SuspensionTest.php` is shared by US1, US2, US3 and US6, and `SuspensionAdministrationTest.php` by US1, US2 and US8. Tasks on those two files aren't marked [P] against each other within a phase, and stories working at the same time must take turns on them.
- T044 to T059 each touch one job file, so all can run in parallel once T041 to T043 are done.
- T077 to T083 each touch one document.

### Parallel Example: User Story 5

```text
# First, the shared types and both test files:
T039 tests/Feature/JobSuspensionInventoryTest.php
T040 tests/Feature/SuspensionJobTest.php
T041 app/Contracts/HasHotel.php
T042 app/Exceptions/HotelSuspended.php

# After T043 creates the middleware, every job at once:
T044–T053 the ten hotel jobs
T054–T059 the six looping jobs
```

## Implementation Strategy

### MVP First

1. Phases 1 and 2.
2. US1, which stops a fully suspended customer at the API.
3. Stop and validate with the CUJ 2 tests.

US1 alone isn't safe to ship. Until US3, a hotel suspension doesn't remove that hotel from a multi-hotel user, and until US4 and US5, AI and jobs keep running. All P1 stories belong in the same pull request.

### Incremental Delivery

1. US1 + US2: stop and restore through the API.
2. US3: multi-hotel users.
3. US4 + US5: AI and background work stop. Stage 3's commercial goal is met.
4. US6 + US7: hotel routes and the list. CUJ 3's `GET /api/hotel` step passes here.
5. US8: operator search.
6. Polish, then one pull request for the stage.

## Notes

- Out of scope, so no tasks: the dashboard, impersonation endpoints and tokens, removing super admin bypasses, and gating queued notifications and pairing codes.
- The frontend's changes follow, from a changes document after implementation.
