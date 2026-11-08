---

description: "Task list for super admin impersonation"
---

# Tasks: Super Admin Impersonation

**Input**: Design documents from `specs/027-superadmin-impersonation/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [plan-verification.md](plan-verification.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/impersonation.md](contracts/impersonation.md), [quickstart.md](quickstart.md)

**Tests**: Included. `CLAUDE.md` and the constitution require them, and FR-032 and [quickstart.md](quickstart.md) name what they must prove.

**Organization**: Tasks are grouped by user story, in the spec's order. Phase 2 creates the column and the helper, and User Story 1 creates the session every other story needs. All feature tests go in one file, so tasks in that file aren't marked parallel.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: The user story in [spec.md](spec.md) this task belongs to

## Rules for every task

- `HotelAccess`, `UseChosenHotel`, `UserPolicy`, `UserResource`, `UserAdministration::clearAccess` and `revoke-tokens` don't change. No permission case. No shipped migration is edited. No design doc edit.
- Never put the plain token in an event, a log, a Sentry tag or anywhere but the start response.
- Whether a token is a session is decided only by `CurrentImpersonation`, never by the token's name.
- Feature tests: `uses(RefreshDatabase::class)`, set the API key in `beforeEach` (`putenv('API_KEY=test-api-key')` and `config(['app.api_key' => 'test-api-key'])`), and use `makeHotel()`, `apiHeaders()`, `spAccount()`, `spUser()` and `spSuspend()` from `tests/Pest.php`. Use real Bearer tokens, not `Sanctum::actingAs`, whose `TransientToken` never counts as a session. Call `app('auth')->forgetGuards()` before each request with a token. Run with `php artisan test --parallel`.
- Helper functions in test files are global, so names in the new file start with `im`.
- Write each story's tests first, and see them fail before implementing it. T031, T032 and T033 are exempt, because earlier tasks or existing code already implement what they test.
- Don't ship a build with User Story 1 but without User Story 4: until then a session can set a lasting password.
- Run `./vendor/bin/pint` before proposing a commit. Commit only when asked.

---

## Phase 1: Setup

**Purpose**: Branch and baseline

- [X] T001 Create and switch to branch `027-superadmin-impersonation` from `main`, bringing the untracked `specs/027-superadmin-impersonation/` with it
- [X] T002 Check `docker ps -a` first. If `hospitality-postgres` is running, use it. Run `docker compose -f .postgres/compose.yaml up -d` only if it isn't, and not while another project's `pilot-postgres` is in use, because they share a volume. Then run `php artisan test --parallel` for a baseline, and note any existing failures

---

## Phase 2: Foundational

**Purpose**: What every story uses. **No story can start until this phase is done.**

- [X] T003 Create `database/migrations/2026_11_08_000001_add_impersonator_to_personal_access_tokens.php`, adding `impersonator_id`: "uuid, nullable, indexed", "Foreign key to `users.id`, cascade on delete". `down()` drops the foreign key and the column
- [X] T004 [P] Create `app/Support/Impersonation/CurrentImpersonation.php` with static `token(): ?PersonalAccessToken`, returning `Auth::user()?->currentAccessToken()` only when it is an instance of `Laravel\Sanctum\PersonalAccessToken` with a non-null `impersonator_id`, and static `impersonator(): ?User`, which looks the user up once and keeps it on the token with `setRelation('impersonator', …)`. No static property, so nothing outlives the request (research R1, R2)
- [X] T005 Update `app/Support/Suspension/ImpersonatedRead.php`: keep the `GET`/`HEAD` check, ask `CurrentImpersonation::token()` instead of reading the attribute itself, and correct the docblock's "stage 5 adds" wording. Its behaviour doesn't change
- [X] T006 Create `tests/Feature/Superadmin/ImpersonationTest.php` with the file setup above and these helpers: `imSuperAdmin()`; `imStart($test, User $superAdmin, User $target): array` (calls the start endpoint and returns the body); `imRequest($test, string $token, string $method, string $uri, array $payload = [], array $headers = [])` (calls `forgetGuards()`, then sends `apiHeaders()` and the Bearer token); `imSentryTags()` (copy `spSentryTags` from `tests/Feature/SuspensionTest.php:44`, which is local to that file)

**Checkpoint**: The column exists, and a token can be recognised as a session.

---

## Phase 3: User Story 1 - Start a session and see what the user sees (Priority: P1)

**Goal**: A super admin starts a session and uses hotel endpoints with the user's permissions and hotels.

**Independent Test**: Start a session for an employee with a known staff role, then call an endpoint the role allows and one it doesn't.

### Tests for User Story 1

- [X] T007 [US1] In `tests/Feature/Superadmin/ImpersonationTest.php`, write one test per acceptance scenario in Story 1 (#1–#13): the response shape and a 30-minute `expires_at` under frozen time; an employee's permissions allowed and denied; another hotel's record `404`; an owner with `X-Hotel-Id` inside and outside the account; an admin's default hotel with no header; an employee seeing both hotels; a super admin target and the caller as target `422` with no token and no event; unknown user `404`; admin and employee `403`; an impersonation token `403` on `api/superadmin` routes, including starting another session; `impersonation` on `GET /api/user`; no key with the user's own token; and no key on `GET /api/users` entries. Also assert that the row has `impersonator_id` set and name `impersonation`, and that the plain token appears in no `event_log` column (FR-004)

### Implementation for User Story 1

- [X] T008 [US1] Create `app/Services/Superadmin/Impersonation.php` with `start(User $superAdmin, User $target): NewAccessToken`. Throw `DomainRuleException` (`422`) for a super admin target. In one `DB::transaction`, call `createToken('impersonation', ['*'], now()->addMinutes(30))`, then `accessToken->forceFill(['impersonator_id' => $superAdmin->id])->save()`, then `EventLogger::recordPlatform('platform.impersonation.started', $target, context: ['impersonated_user_id' => …, 'token_id' => …, 'expires_at' => …])`, with `expires_at` as ISO 8601. Hold the name and 30 minutes in constants (research R5)
- [X] T009 [US1] Create `app/Http/Controllers/Superadmin/ImpersonationController.php` with `start(User $user)`, returning `apiResponse('Impersonation started successfully.', 200, ['token', 'token_id', 'expires_at'])`, and add `Route::post('/users/{user}/impersonate', …)` to `routes/superadmin.php` (FR-001, FR-003, FR-006; [contract](contracts/impersonation.md))
- [X] T010 [US1] In the `GET /user` closure in `routes/api.php`, when `CurrentImpersonation::token()` is set, return `[...UserResource::make(…)->resolve(), 'impersonation' => ['impersonator' => ['id', 'name'], 'expires_at' => …]]`. Otherwise leave the response as it is. `UserResource` doesn't change (FR-008, FR-009)

**Checkpoint**: A session works as the user. It isn't safe to ship until User Story 4 is done.

---

## Phase 4: User Story 2 - Sessions end on time and on request (Priority: P1)

**Goal**: A session stops at 30 minutes and can be ended sooner, with exactly one end event, and can't be extended.

**Independent Test**: Start a session, move time past 30 minutes and call an endpoint. In a second session, end it and call again.

### Tests for User Story 2

- [X] T011 [US2] In `tests/Feature/Superadmin/ImpersonationTest.php`, write one test per acceptance scenario in Story 2 (#1–#15): the token working at 29:59 and `401` at 30:01 with `$this->travel()`, not at exactly 30 minutes because `isPast()` is false at that instant, and no event; end by id, including by another super admin; `404` and the token still there for a login token's id, a pairing code's id and an unknown id; a second end; expired but unpruned; a deleted target, with no `500`; logout; logout with an expired token; two sessions with independent expiry; the prune with `$this->artisan('sanctum:prune-expired', ['--hours' => 1])`; `end-impersonation` with two sessions, a login token and a device; `ended: 0` with none or only an expired one; `404` for an unknown user and a super admin; and `revoke-tokens` still unpairing the device. Assert that `end` reports `422` for a missing or non-integer `token_id`. Assert the event count in every case

### Implementation for User Story 2

- [X] T012 [US2] Add to `app/Services/Superadmin/Impersonation.php`: a private query for running sessions, `whereNotNull('impersonator_id')->where('expires_at', '>', now())`; a private `finish(PersonalAccessToken $token, User $subject): bool`, which in one transaction deletes through `PersonalAccessToken::whereKey($id)->delete()` and records `platform.impersonation.ended` with `context: ['impersonated_user_id', 'token_id']` only if a row was removed; `end(int $tokenId): bool`, which is false if there's no running session or `tokenable` is null; `endAllFor(User $user): int`; and `endCurrent(PersonalAccessToken $token, User $user): void` for logout (research R4; FR-010, FR-011, FR-011a, FR-012)
- [X] T013 [P] [US2] Create `app/Http/Requests/Superadmin/EndImpersonationRequest.php`, with `token_id` required and an integer
- [X] T014 [US2] Add `end(EndImpersonationRequest $request)` to `app/Http/Controllers/Superadmin/ImpersonationController.php`, returning `200` or `404` through `apiResponse()`, and add `Route::post('/impersonation/end', …)` to `routes/superadmin.php`
- [X] T015 [US2] Add `endImpersonation(User $user, Impersonation $impersonation)` to `app/Http/Controllers/Superadmin/UserController.php`, using `administrable()` so a super admin target gets `404`, and returning `['ended' => $count]`. Add `Route::post('/users/{user}/end-impersonation', …)` to `routes/superadmin.php`. `UserAdministration` doesn't change (FR-011a)
- [X] T016 [US2] In `apiLogout` in `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, when `CurrentImpersonation::token()` is set, end it through `Impersonation::endCurrent`. Any other token keeps the existing delete, including a `TransientToken` in existing tests
- [X] T017 [P] [US2] Add `Schedule::command('sanctum:prune-expired --hours=1')->hourly();` with a comment to `routes/console.php`, and add exactly `'sanctum:prune-expired --hours=1'` to the `scheduled` list in `tests/Inventory/entry_points.php` (FR-014, research R10)

**Checkpoint**: Every ending is tested, and each writes at most one event.

---

## Phase 5: User Story 3 - The audit trail names the real super admin (Priority: P1)

**Goal**: Writes in a session's request name the super admin and the user, the five unaudited record types are recorded during a session, hotel readers see only the super admin's id and name, and Sentry is tagged.

**Independent Test**: Start a session, create an audited record with the token, and read its event row.

### Tests for User Story 3

- [X] T018 [US3] In `tests/Feature/Superadmin/ImpersonationTest.php`, write Story 3 #1–#9. Create a guest with the token and check `actor_id`, `actor_kind = user`, `context.impersonated_user_id` and `hotel_id`; the user's own token has no new key; start and end rows have `hotel_id = null` and the listed context; logout's end event has `hotel_id = null`, the super admin as actor and the target as subject, in a request where a current hotel is set; start and end rows don't appear on any hotel's history; and a job's later write stays with the hotel user, by running the write outside a request with `EventLogger::actingAs`. For the AI case (#4), follow `tests/Feature/AdminAiAuditTest.php`, with `Auth::setUser($user->withAccessToken($token))` and the real `CreateGuestTool` from `aatTool()`, and check `actor_kind = ai_agent`
- [X] T019 [US3] Same file, Story 3 #13–#15, with a dataset covering hotel settings (`PUT /api/hotel/{id}`) and create, update and delete of team, task category, activity category and hotel policy. Check one row each (a guest memories toggle also keeps its own named event), `context.changed_fields` holding names, `changes` null, no field value anywhere in the row, and the record's hotel as `hotel_id`. The same writes with the user's own token write no row. An update that changes nothing, and a refused write, write no row
- [X] T020 [US3] Same file, Story 3 #16–#18, through `GET /api/history/guest/{id}`: a hotel admin reads `actor` as only `id` and `name`, with no `ip` in `context` and `impersonated_user_id` still there; an impersonation token reads the same; a super admin's own token reads the full row; a hotel user's row is unchanged; and the stored row still has the IP. `context.ip` is only set outside the console, so build the stored row directly with an `ip` in its `context` rather than relying on the request to write it
- [X] T021 [US3] Same file, Story 3 #10–#12: with `sentry.dsn` set and `pushScope`/`popScope` as in `tests/Feature/SuspensionTest.php:276-289`, a session's request has `impersonating = 'true'` and `impersonator_id`, the next normal request has neither, and with no DSN nothing fails

### Implementation for User Story 3

- [X] T022 [US3] In `write()` in `app/Support/Audit/EventLogger.php`, make the actor `CurrentImpersonation::impersonator() ?? Auth::user() ?? self::$onBehalfOf`, and when `CurrentImpersonation::token()` is set, add `impersonated_user_id => Auth::id()` to the base context, so explicit `context` still merges over it. `record()`, `recordPlatform()`, `resolveActorKind()` and `currentActorKind()` don't change. Widen `record()`'s `$context` docblock to allow `list<string>`. The class docblock gains a line on this (research R3; FR-015, FR-018)
- [X] T023 [US3] Create `app/Models/Concerns/RecordsImpersonatedWrites.php`, with `bootRecordsImpersonatedWrites()` hooking `created`, `updated` and `deleted`. Return at once unless `CurrentImpersonation::token()` is set. Otherwise call `EventLogger::record($model, $verb, context: ['changed_fields' => …])`, with names from `getChanges()` on update and `getAttributes()` on create, without `id`, `created_at` and `updated_at`, and an empty list on delete. Record nothing on an update where only timestamps changed. Never pass values (research R7; FR-019b)
- [X] T024 [US3] Add `use RecordsImpersonatedWrites` to `app/Models/Hotel.php`, `app/Models/Team.php`, `app/Models/TaskCategory.php`, `app/Models/ActivityCategory.php` and `app/Models/HotelPolicy.php`
- [X] T025 [P] [US3] In `app/Http/Resources/EventLogResource.php`, when `$request->user()` isn't a super admin and the row's actor is a user who is a super admin, or `actor_type` is a user with no actor row left, return `actor` as `{id, name}` (or null) and `context` without `ip`. Change nothing else, and don't lazy-load: `HistoryController` eager-loads `actor`. If an existing test in `tests/Feature/EventLogTest.php` or the history tests reads a super admin actor as a hotel user, fix the test only if the spec requires the new answer, and say so (research R8; FR-019c)
- [X] T026 [P] [US3] In `app/Http/Middleware/ResolveTenant.php`, add `impersonating` (`'true'` or null) and `impersonator_id` (id or null) to the existing `TagsSentryScope::tag` call, reading the token through `CurrentImpersonation` (research R9; FR-029)

**Checkpoint**: Attribution, trimmed history and Sentry are proven. `EventLogTest` and `PlatformAuditTest` pass unedited, unless T025 records a reason.

---

## Phase 6: User Story 4 - A session cannot gain lasting access (Priority: P1)

**Goal**: A session can't set a password, change an email, role or membership, issue a credential or pair a device.

**Independent Test**: With an owner's session, send every refused method to every refused route, including a password change, and check nothing changed.

### Tests for User Story 4

- [X] T027 [US4] In `tests/Feature/Superadmin/ImpersonationTest.php`, write Story 4 #1–#10, with a dataset of every refused method and path from the [contract](contracts/impersonation.md). Each gets `403` with the contract's message, in an active hotel, because other checks answer first elsewhere. `PUT /users/{self}` with `password` on an admin's session, and `PUT /users/{staff}` with `password` and `email` on an owner's: the old password still logs in through `POST /api/login` and nothing changed. `/connect` leaves no pairing code. `POST /team/{team}/members` leaves `team_id` unchanged. An unknown id, and another hotel's id, also get `403`. Reads still work. The admin's own token and a super admin's own token behave as before

### Implementation for User Story 4

- [X] T028 [P] [US4] Create `app/Http/Middleware/EnsureNotImpersonating.php`, returning `apiResponse('This action is not available while impersonating.', 403)` when `CurrentImpersonation::token()` is set
- [X] T029 [US4] In `bootstrap/app.php`, add the alias `'not_impersonating' => EnsureNotImpersonating::class`, and add `$middleware->prependToPriorityList(SubstituteBindings::class, EnsureNotImpersonating::class);` after the others, with a comment saying why it runs before binding (research R6; FR-023)
- [X] T030 [US4] In `routes/api.php`, add `->middleware('not_impersonating')` to `POST /connect` and `POST /team/{team}/members`, and `->middlewareFor(['store', 'update', 'destroy'], 'not_impersonating')` to the `/users` and `/staff-roles` resources. Leave `GET` routes open (FR-020 to FR-025)

**Checkpoint**: No refused write changes anything. All P1 stories are done, and this is the first build safe to ship.

---

## Phase 7: User Story 5 - Support a suspended hotel without changing it (Priority: P2)

**Goal**: A session reads a suspended hotel's data and can't change it.

**Independent Test**: Suspend a hotel, then send a `GET` and a `POST` with a session for its user.

**Note**: `ImpersonatedRead` already implements this once the column exists, so this phase is proof and has no implementation task unless a test fails. If one does, fix it through the existing path, and don't add a second check (FR-026).

### Tests for User Story 5

- [X] T031 [US5] In `tests/Feature/Superadmin/ImpersonationTest.php`, write Story 5 #1–#11, using `spAccount()`, `spUser()` and `spSuspend()`: only hotel suspended, `GET` and `HEAD` return data and other methods get the suspended `403`; suspended account, with `body.scope = "account"`; `GET /api/hotel/{hotel}`; logout in a suspended hotel. For default A suspended and B active, on both an admin and an employee target: a `GET` with no header reads A; a `POST` with no header creates in B and leaves A untouched; `X-Hotel-Id: A` reads on `GET` and gets the suspended `403` with `body.scope = "hotel"` on `POST`; a `POST` with `X-Hotel-Id: B` succeeds; a `PUT` on A's record with `X-Hotel-Id: B` is `404`; and `PUT /api/hotel/{A}` with `X-Hotel-Id: B` is the suspended `403`, with A unchanged (V2, V3)

**Checkpoint**: Suspension stops every write through a session.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Guard tests, docs and the final checks

- [X] T032 [P] Add `impersonate` (`POST /users/{user}/impersonate`), `end impersonation` (`POST /impersonation/end`) and `end all impersonation` (`POST /users/{user}/end-impersonation`) to the `administration routes` dataset in `tests/Feature/Superadmin/AccessGuardTest.php`. `assertNothingChanged` needs no change
- [X] T033 In `tests/Feature/Superadmin/ImpersonationTest.php`, test the spec's Edge Cases: hard-deleting the super admin removes the token, with no end event; `revoke-tokens` and the public password reset end a session with no `platform.impersonation.ended`; an impersonation token presented to `POST /api/pair` is refused; a target with no hotels; a session from each of two super admins; a hotel suspended mid-session, where reads go on and writes stop; revoking a super admin's own tokens leaves their session running
- [X] T034 [P] Create `docs/superadmin-impersonation-api-documentation.md`: the three endpoints, the events, the refused routes, what a session can do in a suspended hotel, sending `X-Hotel-Id` throughout a session, every known limit in the spec, including that `expires_at` is the latest possible end, and that only audited writes and the five record types are attributed. Copy shapes from the [contract](contracts/impersonation.md) (FR-030)
- [X] T035 [P] Update the other docs (FR-030): `docs/auth-api-documentation.md` (the `impersonation` key and the logout event), `docs/whatsapp-device-api-documentation.md` (`/connect` is refused), `docs/user-management-api-documentation.md` and `docs/staff-roles-api-documentation.md` (writes are refused; the permission reference doesn't change), `docs/task-management-api-documentation.md` (`POST /api/team/{id}/members` is refused), `docs/history-api-documentation.md` (the trimmed actor), `docs/hotel-api-documentation.md` (the settings event during a session)
- [X] T036 [P] Create `docs/latest-changes-2026-11-08.md` with what the frontend needs: the three new endpoints, the `impersonation` key for a banner, the refusals to handle, sending `X-Hotel-Id` throughout a session, and the trimmed `actor` in history, which changes an existing response (FR-031)
- [X] T037 [P] Update `CLAUDE.md`: note `not_impersonating` beside the request flow, and add a short impersonation paragraph covering `CurrentImpersonation` as the only check and `RecordsImpersonatedWrites`
- [X] T038 Run every command in [quickstart.md](quickstart.md)'s automated checks, then `./vendor/bin/pint`, and confirm `EntryPointInventoryTest`, `CrossTenantRouteTest`, `SuspensionTest`, `EventLogTest` and `PlatformAuditTest` pass. Compare with the T002 baseline, and report any failure with its output. Check [quickstart.md](quickstart.md)'s scenario table against the test file, row by row
- [X] T039 Run `/code-review` on the branch, and record any decision that changes the design in a "Changed after code review" section in `specs/027-superadmin-impersonation/plan-verification.md`, as spec 026 did. Also go through the write routes in `routes/api.php`, list any other unaudited write a session can make, and add them to the spec's Known Limits

---

## Phase 9: Second code review

**Purpose**: Fixes from the second `/code-review`. They overrule the earlier tasks where they differ, including T015 and T022. Details are in [plan-verification.md](plan-verification.md), V15 and V20 to V28.

- [X] T040 [US4] Refuse `POST /api/ai-advisor/chat` during a session in `routes/api.php`, with a row in the refused writes dataset in `tests/Feature/Superadmin/ImpersonationTest.php` (FR-022a)
- [X] T041 [US3] Record the hotel settings a session clears by deleting a team or task category, in `app/Models/Concerns/RecordsImpersonatedWrites.php`, `app/Models/Team.php` and `app/Models/TaskCategory.php` (FR-019b)
- [X] T042 [US3] Take `actor_id` from the token in `app/Support/Audit/EventLogger.php`, with no fallback to the hotel user
- [X] T043 [US4] Refuse user writes and token creation during a session in `app/Models/User.php` (FR-022b)
- [X] T044 [US3] Keep session writes out of captures with `EventLogger::recordSessionWrite` in `app/Support/Audit/EventLogger.php`
- [X] T045 List `ImpersonationController` among the plain-array exceptions in `CLAUDE.md`
- [X] T046 Use one body and one `apiResponse` call in the `GET /user` closure in `routes/api.php`
- [X] T047 Move `end-impersonation` to `app/Http/Controllers/Superadmin/ImpersonationController.php`, and restore `app/Http/Controllers/Superadmin/UserController.php`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: None
- **Foundational (Phase 2)**: After Setup. Blocks every story
- **User Story 1 (Phase 3)**: After Foundational. Blocks every other story, because they all need a session
- **User Stories 2, 3, 4 and 5 (Phases 4–7)**: After User Story 1, and independent of each other, apart from sharing one test file and `routes/superadmin.php`. The exceptions are T018 (Story 3 #6–7) and T031 (Story 5 #5), which need the ending routes from T012, T014 and T016
- **Polish (Phase 8)**: After all the stories

### Task Dependencies

- T005 needs T004. T006 stands alone
- T008 needs T003. T009 needs T008. T010 needs T004
- T012 needs T008. T014 and T015 need T012. T014 needs T013. T016 needs T012
- T022 needs T004. T023 needs T022. T024 needs T023. T019 can't pass without T024
- T029 needs T028. T030 needs T029
- T032 needs T009, T014 and T015

### Parallel Opportunities

Tasks in `ImpersonationTest.php`, `routes/superadmin.php` and `Impersonation.php` are sequential, because they edit one file.

```text
Phase 2:  T003 and T004 together
Story 2:  T013 and T017 together, after T012 or alongside it
Story 3:  T025 and T026 together, alongside T022
Story 4:  T028 alongside the tests
Polish:   T032, T034, T035, T036 and T037 together
```

With more than one person, Stories 2, 3 and 4 can run side by side after Story 1, each adding to the test file in its own block.

## Implementation Strategy

### MVP

Phases 1–6: all four P1 stories. Story 1 alone isn't a safe stopping point, because a session could set a lasting password until Story 4 is done, and it would have no attribution until Story 3. If the work must pause mid-way, don't merge before T030.

### Incremental delivery

1. Phases 1–3: a session works, on the branch only.
2. Phase 4: sessions end properly.
3. Phase 5: the audit trail, trimmed history and Sentry.
4. Phase 6: the refusals. Safe to ship from here.
5. Phase 7: proof for suspended hotels.
6. Phase 8: guard tests, docs and review.

## Notes

- Stage 6 depends on this feature, and the bypasses stay until then.
- The frontend work follows from `docs/latest-changes-2026-11-08.md`, after implementation.
