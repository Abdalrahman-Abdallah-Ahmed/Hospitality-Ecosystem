# Tasks: Baseline Test Suite & Frontend Inventory

**Input**: Design documents from `specs/001-baseline-test-suite/`

**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, quickstart.md

**Tests**: Not requested for Phase 0 baseline measurement. This is pure infrastructure health check and documentation.

**Organization**: Tasks are grouped by user story (US1–US5) to enable independent execution and measurement of each baseline component.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3, US4, US5)
- Include exact file paths and commands in descriptions

## Path Conventions

Single repository structure:
- Backend: `app/`, `database/`, `routes/`, `tests/`
- Frontend: `ecosystem-frontend/` (separate directory, may be separate repo)
- Output: `docs/baseline-2026-09.md`

---

## Phase 1: Setup (Infrastructure Preparation)

**Purpose**: Prepare local environment for Phase 0 baseline measurement

**Prerequisites**: Git repository available, Docker installed (for PostgreSQL container)

- [ ] T001 Start PostgreSQL container with pgvector extension via `docker compose -f .postgres/compose.yaml up -d`
- [ ] T002 [P] Install Composer dependencies in repository root via `composer install`
- [ ] T003 [P] Verify PostgreSQL container is running and accessible: `docker ps | grep postgres` and `psql -h localhost -U postgres -d Hospitality_Ecosystem_testing -c "SELECT version()"`
- [ ] T004 Create `docs/` directory if not present: `mkdir -p docs/`
- [ ] T005 Verify frontend directory exists (ecosystem-frontend/) and has package.json

**Checkpoint**: Local environment ready for baseline measurement; PostgreSQL running; dependencies installed

---

## Phase 2: User Story 1 - Run Complete Test Suite Against Postgres (Priority: P1) 🎯

**Goal**: Execute the full Pest test suite against PostgreSQL and record baseline pass/fail counts, failures, and execution metadata.

**Independent Test**: Run `php artisan test` with PostgreSQL running; verify all tests execute and results are captured.

**Success Criteria**:
- Test suite completes (no hang or crash)
- Pass/fail counts are printed
- Failed tests are listed with error summaries
- Results can be recorded in baseline report
- No errors due to missing PostgreSQL or pgvector

### Implementation for User Story 1

- [ ] T006 [US1] Execute test suite via `php artisan test` in repository root, capture full output to temporary file
- [ ] T007 [US1] Extract and record from test output: total test count, passed count, failed count, skipped count, duration in seconds
- [ ] T008 [US1] Extract failure list from test output: for each failed test, record test class, test method, error message (first 500 chars), and failure type (AssertionError | Exception | Timeout)
- [ ] T009 [US1] Record environment metadata: PHP version (`php --version`), Laravel version (from `composer.lock`), Pest version (from `composer.lock`), PostgreSQL version (`psql --version`), pgvector available check
- [ ] T010 [US1] Record execution metadata: command run (`php artisan test`), exit code, success status (boolean), duration in seconds
- [ ] T011 [US1] Create data structure in TestSuiteBaseline per data-model.md section "1. Test Suite Baseline Report" with all fields populated
- [ ] T012 [US1] Verify exit code is 0 (tests ran; failures are expected and recorded, not fatal)

**Checkpoint**: Test Suite Baseline artifact created with all test results recorded; data structure complete per data-model.md

---

## Phase 3: User Story 2 - Verify Migrations Run Clean from Scratch (Priority: P1) 🎯

**Goal**: Execute all database migrations from a fresh state and record schema baseline, seed data counts, and execution metadata.

**Independent Test**: Run `php artisan migrate:fresh --seed` against testing database; verify no errors and schema is valid.

**Success Criteria**:
- Migrations run without error (exit code 0)
- All migrations complete in sequence
- Seeder runs without error
- Schema is valid (all expected tables/columns present)
- Execution time is under 5 minutes
- Seed data is successfully loaded

### Implementation for User Story 2

- [ ] T013 [US2] Execute migration command: `php artisan migrate:fresh --seed --database=testing` in repository root, capture output (note: `--database=testing` uses the connection alias in `config/database.php`; verify it maps to `Hospitality_Ecosystem_testing` per `phpunit.xml`)
- [ ] T014 [US2] Verify exit code is 0 (migrations successful) and seed data is loaded
- [ ] T015 [US2] Record execution metadata: command run, exit code, success status, duration in seconds (verify duration <= 300 seconds per spec requirement)
- [ ] T016 [US2] Query testing database for schema information: count total tables, count indexes, count constraints (foreign key, unique, check)
- [ ] T017 [US2] List all tables with column counts, soft_delete status (if TrashedBy present), timestamps status (created_at/updated_at present)
- [ ] T018 [US2] Query seed data: user count, hotel count, room count, guest count, reservation count, task count, activity count, booking count (use `DB::table()` or tinker)
- [ ] T019 [US2] Create data structure in MigrationBaseline per data-model.md section "2. Migration Baseline Report" with all fields populated
- [ ] T020 [US2] Verify critical tables are present: users, hotels, rooms, guests, reservations, stays, tasks, activities, bookings (use `Schema::hasTable()`)

**Checkpoint**: Migration Baseline artifact created with schema state and seed data counts recorded; all tables verified

---

## Phase 4: User Story 3 - Run Frontend Build and Lint (Priority: P2)

**Goal**: Execute Next.js frontend build and linting to verify frontend codebase is clean and buildable.

**Independent Test**: Run `npm run build` and `npm run lint` in ecosystem-frontend/; verify build succeeds and lint errors are zero.

**Success Criteria**:
- Build exits with code 0
- No build errors reported
- Linting exits with code 0
- Linting errors count = 0 (warnings tolerated)
- Build produces output bundles (Next.js .next/ directory)
- Frontend build time recorded for baseline

### Implementation for User Story 3

- [ ] T021 [US3] Navigate to ecosystem-frontend/ directory and verify package.json exists
- [ ] T022 [US3] Install frontend dependencies: `npm install` (or `pnpm install` if project uses pnpm; check package-lock.json or pnpm-lock.yaml)
- [ ] T023 [US3] Execute frontend build: `npm run build` in ecosystem-frontend/, capture full output and exit code
- [ ] T024 [US3] Verify build exit code is 0 and build output directory (.next/) is created and non-empty
- [ ] T025 [US3] Extract build metadata: build duration (from output), bundle/output size in MB (measure .next/ directory size with `du -sh .next/`)
- [ ] T026 [US3] Count and record build warnings from output (exact count; can be zero)
- [ ] T027 [US3] Execute frontend linting: `npm run lint` in ecosystem-frontend/, capture full output and exit code
- [ ] T028 [US3] Verify lint exit code is 0 (no linting errors; errors must equal 0 per spec)
- [ ] T029 [US3] Extract lint metadata: error count (must be 0), warning count (from output), list any files with linting violations (if error count > 0, list files)
- [ ] T030 [US3] Record Node version (`node --version`), npm version (`npm --version`), Next.js version (from package.json)
- [ ] T031 [US3] Create data structure in FrontendBuildBaseline per data-model.md section "3. Frontend Build Baseline Report" with all fields populated

**Checkpoint**: Frontend Build Baseline artifact created with build and lint results; all metadata recorded

---

## Phase 5: User Story 4 - Smoke Test Background Infrastructure (Priority: P2)

**Goal**: Verify that queue worker, scheduler, and WhatsApp webhook are operational and process work without fatal crashes.

**Independent Test**: Run queue worker once, trigger scheduler, and send test payload to webhook; verify no fatal errors for any component.

**Success Criteria**:
- Queue worker processes one job without fatal error (exit code 0 or job processed)
- Scheduler runs due tasks without fatal error (exit code 0)
- WhatsApp webhook accepts test payload and responds with HTTP 200
- No fatal crashes in logs or output
- All three components operational

### Implementation for User Story 4

#### Queue Worker Testing

- [ ] T032 [US4] Execute queue worker: `php artisan queue:work --once` in repository root, capture output and exit code
- [ ] T033 [US4] Verify queue worker exit code indicates success (0 = job processed or queue empty, no fatal error)
- [ ] T034 [US4] Record queue worker status: "working" (exit code 0) or "failed" (if errors in output), list any error messages
- [ ] T035 [US4] Record queue worker execution: start time, end time, duration in seconds, job count processed (0 if queue empty, that's OK)

#### Scheduler Testing

- [ ] T036 [US4] List scheduled commands: `php artisan schedule:list` in repository root (optional; informational)
- [ ] T037 [US4] Trigger scheduler: `php artisan schedule:run` in repository root, capture output and exit code
- [ ] T038 [US4] Verify scheduler exit code indicates success (0 = tasks run or no tasks due, no fatal error)
- [ ] T039 [US4] Record scheduler status: "working" (exit code 0) or "failed" (if fatal errors), list any error messages
- [ ] T040 [US4] Record scheduler execution: number of jobs executed (from output; can be zero if none due), any failures or skips

#### WhatsApp Webhook Testing

- [ ] T041 [US4] Create mock WhatsApp payload file (or inline) with valid structure: object, entry, changes, messaging_product, metadata, messages (see quickstart.md Task 4C for payload structure)
- [ ] T042 [US4] Check if server is already running on port 8000: `lsof -i :8000`; if yes, skip startup. If no, start local server: `php artisan serve &` in background
- [ ] T043 [US4] Send test payload to webhook via curl: `curl -X POST http://localhost:8000/api/whatsapp/webhook -H "Content-Type: application/json" -d '{payload}'`, capture HTTP response code and response body
- [ ] T044 [US4] Verify webhook responds with HTTP 200 status code
- [ ] T045 [US4] Record webhook execution: endpoint called, response time in milliseconds (from curl output), response status, response body (first 200 chars), any errors in logs
- [ ] T046 [US4] Verify webhook does not crash or log fatal errors (check logs/laravel.log for ERROR level entries)

#### Integration

- [ ] T047 [US4] Create data structure in BackgroundInfrastructureBaseline per data-model.md section "4. Background Infrastructure Baseline Report" with all fields populated
- [ ] T048 [US4] Record status for all three components: queue_worker, scheduler, whatsapp_webhook with status enum ("working" | "failed" | "timeout") and error lists

**Checkpoint**: Background Infrastructure Baseline artifact created; queue, scheduler, and webhook all operational

---

## Phase 6: User Story 5 - Inventory Frontend API Contract (Priority: P2)

**Goal**: Document all frontend pages, the API endpoints each page calls, and identify gaps (missing/unused endpoints).

**Independent Test**: Analyze frontend source code for API calls; cross-reference against backend routes; produce inventory with gap analysis.

**Success Criteria**:
- All frontend pages are identified and listed
- All API endpoints called by pages are documented
- All backend endpoints are listed with usage status
- Gaps are identified: unused endpoints, undefined endpoints
- Inventory is complete and accurate enough for Phase 1 planning

### Implementation for User Story 5

#### Frontend Analysis

- [ ] T049 [US5] List all frontend pages: find `pages/` directory in ecosystem-frontend/src, list all .tsx/.ts files (excluding _app, _document, etc. if using Next.js conventions)
- [ ] T050 [US5] For each frontend page, search for API calls: grep for "fetch(", "axios.", "client.", "api." calls in page files and referenced services
- [ ] T051 [US5] Extract endpoint information from each API call: HTTP method (GET/POST/PUT/DELETE/PATCH), endpoint path (/api/hotels, /api/guests, etc.), request/response structure (if documented in code)
- [ ] T052 [US5] Create preliminary mapping: page name → list of endpoints called, with method and path

#### Backend Analysis

- [ ] T053 [US5] List all backend API routes: open routes/api.php and routes/admin.php, extract all Route:: definitions
- [ ] T054 [US5] For each route, record: HTTP method, path, controller method (or closure), authentication/authorization requirements if visible
- [ ] T055 [US5] Create backend endpoint list: path → method → controller mapping

#### Cross-Reference & Gap Analysis

- [ ] T056 [US5] Cross-reference frontend calls against backend routes: for each endpoint in frontend mapping, mark as "in-use" if defined in backend, "undefined" if not found
- [ ] T057 [US5] Identify unused endpoints: for each backend endpoint, mark as "unused" if not called by any frontend page, "in-use" if called
- [ ] T058 [US5] Identify deprecated endpoints: if endpoint is defined but marked as deprecated in code comments, note it
- [ ] T059 [US5] Create gaps report: list undefined endpoints (called but not defined), unused endpoints (defined but not called)
- [ ] T060 [US5] Create data structure in FrontendAPIContractInventory per data-model.md section "5. Frontend API Contract Inventory" with all fields populated

#### Documentation

- [ ] T061 [US5] Create markdown table for Frontend Pages: page name, route path, list of endpoints called
- [ ] T062 [US5] Create markdown table for Backend Endpoints: method, path, usage status (in-use | unused | undefined), called by pages (list)
- [ ] T063 [US5] Create markdown section for Gaps: undefined endpoints (with called-by page), unused endpoints (with reason if known)
- [ ] T064 [US5] Record summary counts: total pages, total endpoints, in-use endpoints, undefined endpoints, unused endpoints

**Checkpoint**: Frontend API Contract Inventory artifact created with complete mapping and gap analysis

---

## Phase 7: Integration & Documentation

**Purpose**: Compile all baseline artifacts into final report and commit to version control

**Prerequisite**: All user stories (US1–US5) complete with baseline artifacts generated

- [ ] T065 Create `docs/baseline-2026-09.md` file (or append if exists) with following sections in order:
  - Header: "Baseline Report — 2026-09-22"
  - Test Suite Baseline (from US1 / data-model.md)
  - Migration Baseline (from US2 / data-model.md)
  - Frontend Build Baseline (from US3 / data-model.md)
  - Background Infrastructure Baseline (from US4 / data-model.md)
  - Frontend API Contract Inventory (from US5 / data-model.md)
  - Summary section with all-clear statement
- [ ] T066 Format baseline report with Markdown headings, tables, and code blocks for readability per quickstart.md formatting examples
- [ ] T067 Include raw structured data (JSON or YAML code blocks) for each artifact to enable programmatic parsing in future phases
- [ ] T068 Verify baseline report includes all required fields per data-model.md:
  - Test Suite: timestamp, environment, execution, failures list, notes
  - Migration: timestamp, execution, schema, seed_data, notes
  - Frontend Build: timestamp, environment, build results, lint results, notes
  - Background Infra: timestamp, queue_worker, scheduler, whatsapp_webhook, notes
  - API Inventory: timestamp, summary counts, frontend_pages, backend_endpoints, gaps, notes
- [ ] T069 Run git status to verify no uncommitted changes except docs/baseline-2026-09.md
- [ ] T070 Add and commit baseline report: `git add docs/baseline-2026-09.md` and `git commit -m "docs: Phase 0 baseline report [2026-09-22]"`
- [ ] T071 [P] (Optional) Create git tag for baseline: `git tag baseline-2026-09-22` (for easy reference; not required for Phase 0 completion)
- [ ] T072 Verify commit is successful: `git log -1` shows baseline commit

**Checkpoint**: Baseline report complete, committed to git, and ready for Phase 1 development

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - start immediately ✅
  - PostgreSQL must be running before tests/migrations run
  - Composer dependencies must be installed before PHP commands
- **Phase 2 (US1 - Test Suite)**: Depends on Setup ✅
  - PostgreSQL running (from T001)
  - Composer dependencies installed (from T002)
  - Can run as soon as Setup complete
- **Phase 3 (US2 - Migrations)**: Depends on Setup ✅
  - PostgreSQL running (from T001)
  - Composer dependencies installed (from T002)
  - Can run independently of US1; no data dependency
- **Phase 4 (US3 - Frontend Build)**: Depends on Setup ✅
  - Frontend directory present (verified in T005)
  - Node.js and npm installed (assumed available)
  - Can run in parallel with US1 and US2; separate codebase
- **Phase 5 (US4 - Background Infra)**: Depends on Setup ✅
  - PostgreSQL running (from T001)
  - Composer dependencies installed (from T002)
  - Can run in parallel with US1, US2, US3
- **Phase 6 (US5 - API Inventory)**: Depends on Setup ✅
  - Frontend source available (from T005)
  - No code execution needed; only analysis
  - Can run in parallel with all other stories
- **Phase 7 (Integration)**: Depends on all user stories (US1–US5) complete ✅

### User Story Independence

All five user stories are **independently testable**:
- **US1 (Test Suite)**: Can be executed alone; validates Pest + PostgreSQL
- **US2 (Migrations)**: Can be executed alone; validates schema + seeds
- **US3 (Frontend Build)**: Can be executed alone; validates frontend infrastructure
- **US4 (Background Infra)**: Can be executed alone; validates queue/scheduler/webhook
- **US5 (API Inventory)**: Can be executed alone; requires no execution, only analysis

### Parallel Opportunities

**Within Setup (Phase 1)**:
- T002 (Composer install) and T005 (Frontend check) can run in parallel ✅

**Within User Stories**:
- **US1 (Test Suite)**: No internal parallelism (single test run) ⚠️
- **US2 (Migrations)**: No internal parallelism (single migration sequence) ⚠️
- **US3 (Frontend Build)**: Build and lint can run sequentially (build must finish first) ⚠️
- **US4 (Background Infra)**: Three components can run in parallel (queue, scheduler, webhook) ✅
  - T032–T035 (Queue) vs. T036–T040 (Scheduler) vs. T041–T046 (Webhook)
- **US5 (API Inventory)**: Can run frontend analysis and backend analysis in parallel ✅
  - T049–T052 (Frontend pages) vs. T053–T055 (Backend routes)

**Between User Stories** (after Setup complete):
- All five user stories can run in parallel by different team members ✅
  - US1 (test suite)
  - US2 (migrations)
  - US3 (frontend build)
  - US4 (background infrastructure)
  - US5 (API inventory)

### Execution Timeline (Single Developer)

```
Setup (Phase 1): T001–T005                 → 5 min (Postgres start + install)
US1 (Phase 2):   T006–T012 (sequential)   → 5–10 min (test suite)
US2 (Phase 3):   T013–T020 (sequential)   → 2–5 min (migrations)
US3 (Phase 4):   T021–T031 (sequential)   → 3–5 min (frontend build + lint)
US4 (Phase 5):   T032–T048 (parallel-able) → 3–5 min (queue, scheduler, webhook)
US5 (Phase 6):   T049–T064 (parallel-able) → 10–15 min (analysis and documentation)
Integration:     T065–T073               → 5 min (compile and commit)

Total: ~40–60 minutes for complete baseline measurement
```

### Execution Timeline (Team of 5)

```
T001–T005 (Setup):         All → 5 min
├─ Developer A: US1 (T006–T012)     → 5–10 min (in parallel)
├─ Developer B: US2 (T013–T020)     → 2–5 min (in parallel)
├─ Developer C: US3 (T021–T031)     → 3–5 min (in parallel)
├─ Developer D: US4 (T032–T048)     → 3–5 min (in parallel)
└─ Developer E: US5 (T049–T064)     → 10–15 min (in parallel)
T065–T073 (Integration):   All → 5 min

Total: ~30 minutes (most time spent in setup and US5 analysis)
```

---

## Implementation Strategy

### MVP First (Just the Measurement)

1. ✅ Complete Setup (Phase 1) - 5 min
2. ✅ Execute all user stories (Phases 2–6) in any order - 45–55 min
3. ✅ Compile and commit baseline report (Phase 7) - 5 min
4. **STOP and VALIDATE**: Verify baseline-2026-09.md is complete and committed
5. Ready for Phase 1 development

**Total MVP effort**: 55–65 minutes

### Incremental Measurement (If preferred)

1. Setup once (Phase 1)
2. Run user stories one at a time:
   - Run US1, record results
   - Run US2, record results
   - Run US3, record results
   - Run US4, record results
   - Run US5, record results
3. Compile after each story, or compile once at end

### Parallel Team Strategy (Maximize velocity)

1. All developers run Setup together (T001–T005)
2. Once Setup done, split into 5 groups:
   - Group 1: Execute US1 (5–10 min)
   - Group 2: Execute US2 (2–5 min)
   - Group 3: Execute US3 (3–5 min)
   - Group 4: Execute US4 (3–5 min, parallel sub-tasks)
   - Group 5: Execute US5 (10–15 min, parallel analysis)
3. After all groups complete (~15–20 min), one person compiles and commits (5 min)

---

## Success Criteria Summary

**Phase 0 baseline is complete and valid when all of the following are true**:

✅ **Test Suite Baseline** (US1 complete):
- Test suite executes without hang or crash
- Pass/fail counts recorded
- Failed test list with error summaries recorded
- Environment metadata recorded
- Data structure per data-model.md § 1

✅ **Migration Baseline** (US2 complete):
- Migrations run cleanly with exit code 0
- Execution time recorded (verify <= 300 seconds)
- Schema state (table count, indexes, constraints) recorded
- Seed data counts recorded (users, hotels, rooms, guests, etc.)
- All critical tables verified present
- Data structure per data-model.md § 2

✅ **Frontend Build Baseline** (US3 complete):
- Frontend build succeeds with exit code 0
- Build bundle produced in .next/ directory
- Linting succeeds with exit code 0 and error count = 0
- Build and lint metadata recorded
- Environment metadata recorded
- Data structure per data-model.md § 3

✅ **Background Infrastructure Baseline** (US4 complete):
- Queue worker processes without fatal crash
- Scheduler runs without fatal crash
- WhatsApp webhook responds with HTTP 200 to test payload
- Status recorded for all three components
- Error messages captured (if any)
- Data structure per data-model.md § 4

✅ **Frontend API Contract Inventory** (US5 complete):
- All frontend pages identified and listed
- All API endpoints called by pages documented
- All backend endpoints listed with usage status
- Gaps identified (undefined endpoints, unused endpoints)
- Comprehensive mapping created
- Data structure per data-model.md § 5

✅ **Integration Complete** (Phase 7):
- All baseline artifacts compiled into `docs/baseline-2026-09.md`
- Report is human-readable Markdown with structured data sections
- Report is committed to git with message: `"docs: Phase 0 baseline report [2026-09-22]"`
- No uncommitted changes remain (except potentially baseline report)

✅ **Overall Gate**: All five user story artifacts + integration report = **Phase 0 COMPLETE** ✅

---

## Notes

- Each task ID (T001–T073) is unique and sequentially numbered
- [P] markers indicate tasks that can run in parallel (different files, no inter-dependencies)
- [Story] labels map tasks to their user story (US1–US5) for traceability
- Phase 0 is measurement and documentation only; no features are added
- All baseline data is recorded in structured format for future programmatic analysis
- Baseline establishes foundation for Phase 1 work (domain restructuring begins after this)
