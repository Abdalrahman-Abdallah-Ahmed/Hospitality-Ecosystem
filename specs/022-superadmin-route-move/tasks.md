---

description: "Task list for the super admin route move"
---

# Tasks: Super Admin Route Move

**Input**: Design documents from `specs/022-superadmin-route-move/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [contracts/superadmin-routes.md](contracts/superadmin-routes.md), [quickstart.md](quickstart.md)

**Tests**: Included. The constitution requires automated tests, and the plan and SC-001, SC-002 and SC-004 require `RouteMoveTest`.

**Organization**: Tasks are grouped by user story. The move itself is one atomic change, so it is foundational: Stories 1 and 3 can't exist without each other, and each story phase adds the tests and cleanup that prove its outcome.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: The user story the task belongs to (US1 to US4)

## Rules for every task

- **Replace the prefix only.** `/api/admin` becomes `/api/superadmin`, `routes/admin.php` becomes `routes/superadmin.php`, and `Controllers\Admin` becomes `Controllers\Superadmin`. URL counts in tasks are matching lines, so replace every occurrence in the file rather than stopping at the count.
- **Don't change logic or expected results.** No controller logic, validation, resource, permission or assertion changes, except where a task says so.
- **Leave `App\Ai\Tools\Admin` alone.** It is a different namespace and doesn't move.
- **Leave history alone.** Don't edit `specs/001`–`021`, dated `docs/latest-changes-*`, `docs/system-design-assessment-2026-09-19.md`, the PGRIP plan, `docs/designs/super-admin-platform.md` or the review reports at the repo root.
- **Don't commit** unless the user asks.

---

## Phase 1: Setup

**Purpose**: Capture the evidence that can't be recreated after the move

- [X] T001 Run `php artisan route:list --json` before any other change and save the output as `routes-before.json` in a temp directory outside the repo, per step 1 of `specs/022-superadmin-route-move/quickstart.md`

---

## Phase 2: Foundational (the move)

**Purpose**: The atomic change. Afterwards `/api/superadmin` answers and `/api/admin` doesn't.

**⚠️ CRITICAL**: Do T002 to T005 together. Tests calling the old URLs fail until Phase 3 and Phase 4 update them.

- [X] T002 `git mv app/Http/Controllers/Admin app/Http/Controllers/Superadmin`, then change the namespace to `App\Http\Controllers\Superadmin` in all six controllers: `AiCostController.php`, `GuestMemoryExtractionController.php`, `KnowledgeBaseArticleController.php`, `KnowledgeDocumentController.php`, `KnowledgeRebuildController.php`, `UsageController.php`. Also update the comments at `AiCostController.php:17`, `GuestMemoryExtractionController.php:17` and `KnowledgeDocumentController.php:17`
- [X] T003 `git mv routes/admin.php routes/superadmin.php`, then update the six imports, the prefix and the file name in the header docblock, and the comment above the `/usage` route, in `routes/superadmin.php`. Keep all 25 routes in their current order, so `knowledge-documents/deleted` stays before `knowledge-documents/{id}`
- [X] T004 In `bootstrap/app.php`, set `->prefix('api/superadmin')` and `base_path('routes/superadmin.php')`, and update the comment at line 31. The middleware array stays exactly `['api', 'api.key', 'auth:sanctum', 'throttle:api', 'tenant', 'super_admin']`. Add no second registration, alias or redirect
- [X] T005 Run `composer dump-autoload` and `php artisan optimize:clear`, then confirm `php artisan route:list --path=api/superadmin` lists 25 routes and `--path=api/admin` lists none

**Checkpoint**: The new addresses answer. Story work can begin.

---

## Phase 3: User Story 1 - Super admin keeps every capability at the new address (Priority: P1) 🎯 MVP

**Goal**: Prove all 25 endpoints work at the new address, with the same results.

**Independent Test**: The existing super admin tests pass with only addresses changed, and `RouteMoveTest` finds all 25 routes.

### Tests for User Story 1

- [X] T006 [US1] `git mv tests/Feature/Admin tests/Feature/Superadmin`, and within it `git mv GuestMemoryExtractionAdminTest.php GuestMemoryExtractionSuperadminTest.php`
- [X] T007 [P] [US1] Replace the 15 `/api/admin` URLs in `tests/Feature/Superadmin/GlobalKnowledgeTest.php` (depends on T006)
- [X] T008 [P] [US1] Replace the 14 `/api/admin` URLs in `tests/Feature/Superadmin/KnowledgeRebuildTest.php` (depends on T006)
- [X] T009 [P] [US1] Replace the 5 `/api/admin` URLs in `tests/Feature/Superadmin/GuestMemoryExtractionSuperadminTest.php` (depends on T006)
- [X] T010 [P] [US1] Replace the 4 `/api/admin` URLs in `tests/Feature/AccountUsageAiBlockTest.php`
- [X] T011 [P] [US1] Replace the 2 `/api/admin` URLs in `tests/Feature/AiCostCapabilityTest.php`
- [X] T012 [P] [US1] Replace the `/api/admin` URL in `tests/Feature/KnowledgeBaseArticleControllerTest.php`
- [X] T013 [P] [US1] Replace the `/api/admin` URL in `tests/Feature/KnowledgeDocumentVisionTest.php`
- [X] T014 [P] [US1] Replace the 2 `/api/admin` URLs in `tests/Feature/UsageMeteringTest.php`
- [X] T015 [US1] Create `tests/Feature/Superadmin/RouteMoveTest.php` with `uses(RefreshDatabase::class)` and the API key set in `beforeEach`, as `CLAUDE.md` describes. Define one Pest dataset of the 25 method and path pairs from the spec's Endpoint Map, for later tasks to reuse. Assert each pair exists under `api/superadmin`. Do **not** assert the area contains exactly 25: Stage 2 adds routes (research R3)
- [X] T016 [US1] In `tests/Feature/Superadmin/RouteMoveTest.php`, add a test that a super admin's `GET /api/superadmin/knowledge-documents/deleted` returns `200` with a list. No existing test covers this route under the super admin prefix (spec US1 scenario 3). Name any file-local helpers uniquely: Pest test functions are global, and `superAdminUser()` is already declared in `GuestMemoryExtractionSuperadminTest.php`

**Checkpoint**: Story 1 is complete. All super admin behaviour tests pass at the new address.

---

## Phase 4: User Story 2 - Access stays exactly as restricted (Priority: P1)

**Goal**: Prove the protections moved intact and the automated route checks still cover everything.

**Independent Test**: Every route under `api/superadmin` has the full stack, and hotel staff and unauthenticated callers are refused on all 25.

### Tests for User Story 2

- [X] T017 [US2] In `tests/Feature/Superadmin/RouteMoveTest.php`, assert that **every** registered route whose URI starts with `api/superadmin/` has `gatherMiddleware()` exactly equal to `['api', 'api.key', 'auth:sanctum', 'throttle:api', 'tenant', 'super_admin']`. Loop over the route table, not the dataset, so routes added later are covered (FR-009, FR-010)
- [X] T018 [US2] In `tests/Feature/Superadmin/RouteMoveTest.php`, use the dataset to assert on all 25 routes that a hotel admin gets `403`, an employee gets `403`, a request with the API key but no token gets `401`, and a request with no API key gets `401`. Use a random UUID for `{id}`. Use a real `GuestMemoryExtraction` for `{extraction}`, built as `adminExtraction()` does in `GuestMemoryExtractionSuperadminTest.php`, because that route binds a model and the existing refusal test also uses a real record. Use `cmaHotel()`, `cmaAdmin()`, `cmaEmployee()` and `cmaRequest()` from `tests/Pest.php` where they fit

### Implementation for User Story 2

- [X] T019 [P] [US2] In `tests/Feature/CostVisibilityTest.php`, change line 314's allowlist entry to `app/Http/Controllers/Superadmin/`, line 361's filter to `! str_starts_with($uri, 'api/superadmin')`, and line 493's URL. The line 361 filter is what keeps super admin routes out of the hotel-facing scan
- [X] T020 [P] [US2] In `tests/Feature/AiCostAttributionTest.php`, replace the 7 URLs (five `/api/admin/ai-cost` calls and the two entries at line 583) and the `routes/admin.php` comment at line 575. Keep the test title and its expected middleware array unchanged
- [X] T021 [P] [US2] Update the two `routes/admin.php` comments at lines 1914 and 1926 of `tests/Pest.php`. Don't change `crossTenantRuleFor()` logic: it classifies routes by middleware, so `CrossTenantRouteTest` and `EntryPointInventoryTest` need no change

**Checkpoint**: Stories 1 and 2 are complete. The new area is proven as protected as the old one.

---

## Phase 5: User Story 3 - The old address is gone (Priority: P2)

**Goal**: Prove nothing answers under `/api/admin`, and remove the old name from the code.

**Independent Test**: All 25 old addresses return `404`, with and without a super admin login, and a search finds the old name only in `RouteMoveTest`.

### Tests for User Story 3

- [X] T022 [US3] In `tests/Feature/Superadmin/RouteMoveTest.php`, use the dataset to assert each old `/api/admin/...` address returns `404` for a super admin and for a caller with no credentials, with no redirect. Also assert no registered route URI starts with `api/admin` (FR-005, FR-006). `/api/admin/usage` must be among the cases. Also assert the bare `/api/superadmin` returns `404` for a super admin

### Implementation for User Story 3

- [X] T023 [P] [US3] Update the comments in `app/Http/Controllers/KnowledgeBaseArticleController.php` (lines 24, 50, 116), `app/Http/Controllers/KnowledgeDocumentController.php` and `app/Http/Controllers/AccountUsageController.php` (line 13)
- [X] T024 [P] [US3] Update the comments in `app/Services/Knowledge/ArticleCommands.php` (line 12), `app/Policies/KnowledgeDocumentPolicy.php` (line 12), `app/Models/KnowledgeDocument.php` (line 22) and `app/Models/KnowledgeIndexRebuild.php` (line 14)
- [X] T025 [P] [US3] Update the comments at lines 32 and 128 of `database/seeders/AiModelPriceSeeder.php`

**Checkpoint**: The hard cut is proven and the code uses only the new name.

---

## Phase 6: User Story 4 - Frontend and readers can follow the change (Priority: P2)

**Goal**: One complete breaking-change note, and living docs that show only the new addresses.

**Independent Test**: The note alone resolves all 25 old addresses, and living docs mention the old prefix nowhere.

- [X] T026 [P] [US4] Replace the prefix in `docs/global-knowledge-admin-api-documentation.md`. Keep the file name (research R5)
- [X] T027 [P] [US4] Replace the prefix and `routes/admin.php` in `docs/usage-metering-api-documentation.md`
- [X] T028 [P] [US4] Replace the prefix in `docs/ai-cost-attribution-api-documentation.md`
- [X] T029 [P] [US4] Replace the prefix in `docs/guest-memories-api-documentation.md`
- [X] T030 [P] [US4] Replace the prefix in `docs/knowledge-base-article-api-documentation.md`, including the pointer inside the dated notice at line 9. Change only the URL in that notice, not its date or wording
- [X] T031 [P] [US4] Replace the prefix in `docs/knowledge-document-api-documentation.md`
- [X] T032 [P] [US4] Replace the prefix in `docs/auth-api-documentation.md` (line 278)
- [X] T033 [P] [US4] Replace the prefix in `docs/staff-roles-api-documentation.md` (line 45). No permission changes
- [X] T034 [P] [US4] Replace `routes/admin.php` at lines 56 and 465 of `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md`
- [X] T035 [P] [US4] Update lines 87 and 89 in the "Request flow" section of `CLAUDE.md` to `routes/superadmin.php` and `api/superadmin`. Keep the guidance about the file-level guard
- [X] T036 [US4] Create `docs/latest-changes-<today's date>.md` following the most recent `docs/latest-changes-*.md`. Include a table mapping all 25 old addresses to new ones with their methods. State that there is no alias, so old addresses return `404`, that requests, responses, tokens and permissions are unchanged, and that the frontend and backend must deploy together. The frontend must be able to do its work from this note alone (FR-014, FR-015)

**Checkpoint**: All stories are complete.

---

## Phase 7: Polish & Verification

**Purpose**: Run the checks in `specs/022-superadmin-route-move/quickstart.md`

- [X] T037 Take the after snapshot and compare it with `routes-before.json` from T001, per step 3 of `specs/022-superadmin-route-move/quickstart.md`. Parse both files as JSON before normalising the prefix and namespace, then sort them. Method, URI, action and middleware must match for every route (SC-005)
- [X] T038 Run the leftovers search in step 7 of `specs/022-superadmin-route-move/quickstart.md`. The only matches should be in `tests/Feature/Superadmin/RouteMoveTest.php` (SC-006)
- [X] T039 Review the `tests/` diff with renames detected, per step 6 of `specs/022-superadmin-route-move/quickstart.md`. Confirm no expected status code or response assertion changed (SC-003)
- [X] T040 Run `./vendor/bin/pint`, then `php artisan test --parallel` with the Postgres container up, and report the result with its output
- [X] T041 Resolve all 25 old addresses using only the note from T036, per step 9 of `specs/022-superadmin-route-move/quickstart.md` (SC-007)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Before everything. T001 can't be recovered later without checking out the old code.
- **Foundational (Phase 2)**: Depends on T001. Blocks all stories.
- **User Stories (Phases 3 to 6)**: Depend on Phase 2.
- **Polish (Phase 7)**: Depends on all stories. T037 and T038 need every story finished.

### User Story Dependencies

- **US1 (P1)**: Starts after Phase 2. T015 creates the test file and dataset.
- **US2 (P1)**: T017 and T018 depend on T015. T019 to T021 are independent of US1.
- **US3 (P2)**: T022 depends on T015. T023 to T025 are independent.
- **US4 (P2)**: Independent of the other stories.

### Within Each Story

- **Sequential in `RouteMoveTest.php`**: T015 → T016 → T017 → T018 → T022, because they share one file.
- **Folder move first**: T006 before T007 to T009.

### Parallel Opportunities

- **T007 to T014**: URL updates in separate test files.
- **T019 to T021**: separate files with no dependency between them.
- **T023 to T025**: comment updates.
- **T026 to T035**: doc updates.

---

## Parallel Example: User Story 4

```text
Task: "Replace the prefix in docs/usage-metering-api-documentation.md"
Task: "Replace the prefix in docs/ai-cost-attribution-api-documentation.md"
Task: "Replace the prefix in docs/guest-memories-api-documentation.md"
```

---

## Implementation Strategy

### MVP

There is no partial release of this feature. Phases 1 to 5 together are the smallest working result, since the suite stays red until every test URL is updated, and Phase 6 is required by the project's documentation rule. Ship all of it in one change.

### Order of Work

1. T001: snapshot.
2. T002 to T005: the move.
3. T006 to T021: green suite, new guard tests.
4. T022 to T025: old address proof, comments.
5. T026 to T036: docs and the note.
6. T037 to T041: verification.

---

## Notes

- **Tests after the move, on purpose.** `RouteMoveTest` proves a rename, and the existing suite already goes red the moment the move lands, so there's no separate failing-first step.
- **Frontend**: not part of these tasks. After the backend is built, `/frontend-apply-changes` hands the note to the frontend. Environments people actually use need both deployed together.
- **Deployment**: no extra step. `.github/workflows/deploy.yml` rebuilds the class map and route cache.
- **Branch**: work is on `022-superadmin-route-move`.
