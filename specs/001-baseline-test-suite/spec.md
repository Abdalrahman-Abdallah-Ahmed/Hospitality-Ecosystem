# Feature Specification: Baseline Test Suite & Frontend Inventory

**Feature Branch**: `001-baseline-test-suite`

**Created**: 2026-09-22

**Status**: Draft

**Input**: User description: "read phase 0 from docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md and according to the best practice of the githubs speckit create the first spec"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Run Complete Test Suite Against Postgres (Priority: P1)

The engineering team executes the full Pest test suite against a running PostgreSQL instance with pgvector extension, records which tests pass and which fail, and generates a baseline report of the application's current test health. This establishes a baseline before any domain restructuring begins.

**Why this priority**: Test infrastructure and database compatibility are foundational. The team must know the current state of test coverage and failure modes before modifying the codebase.

**Independent Test**: Run `php artisan test` with Postgres container running, capture results, and verify all tests execute (some may fail, that's expected).

**Acceptance Scenarios**:

1. **Given** the PostgreSQL container is running with pgvector, **When** the engineer runs `php artisan test`, **Then** all tests execute and a summary of pass/fail counts is recorded.
2. **Given** a test fails, **When** the failure is logged, **Then** the error output is captured with full stacktrace for analysis.
3. **Given** all tests complete, **When** the results are recorded, **Then** the baseline report lists each failing test with its failure reason.

---

### User Story 2 - Verify Migrations Run Clean from Scratch (Priority: P1)

The team verifies that the migration system works end-to-end: starting from a fresh database, running all migrations, and seeding data. This confirms the migration pipeline is reliable before restructuring the schema.

**Why this priority**: Migrations are critical infrastructure. A broken migration pipeline would prevent deployment and schema changes during Phase 1+. This must work.

**Independent Test**: Drop the testing database, re-create it, run all migrations, and run the seeder. Verify schema integrity.

**Acceptance Scenarios**:

1. **Given** a fresh database, **When** all migrations run, **Then** all migrations complete without error.
2. **Given** all migrations complete, **When** the seeder runs, **Then** seed data is inserted without error.
3. **Given** seed data exists, **When** the schema is inspected, **Then** all tables, columns, and indexes are present and correct.

---

### User Story 3 - Run Frontend Build and Lint (Priority: P2)

The frontend (Next.js) builds successfully and passes linting rules. This establishes that the frontend source is clean and buildable before making API contract changes in later phases.

**Why this priority**: Frontend readiness is necessary for integrated testing. A broken build blocks verification of API changes. This is lower priority than backend because there are no API changes in Phase 0, but it's essential for Phase 1 onwards.

**Independent Test**: In the `ecosystem-frontend` directory, run the build and lint scripts and capture output. Verify no errors or unresolved warnings.

**Acceptance Scenarios**:

1. **Given** the frontend source, **When** the build script runs, **Then** it completes successfully and produces optimized bundles.
2. **Given** the source, **When** linting runs, **Then** no linting errors are reported (warnings may be tolerated per configuration).

---

### User Story 4 - Smoke Test Background Infrastructure (Priority: P2)

The team confirms that the queue worker, scheduled jobs, and WhatsApp webhook can run without crashing. This is a smoke test, not full functional verification; it confirms the plumbing works.

**Why this priority**: Background work is critical for the system but not changed in Phase 0. A sanity check ensures nothing is obviously broken.

**Independent Test**: Start a queue worker, trigger a scheduled job, send a test WhatsApp webhook payload, and confirm no fatal errors occur.

**Acceptance Scenarios**:

1. **Given** the queue listener is running, **When** a job is queued, **Then** the job is processed without error.
2. **Given** a scheduled job trigger is invoked, **When** the job runs, **Then** it completes without fatal errors.
3. **Given** a test WhatsApp webhook payload, **When** it is sent to the webhook endpoint, **Then** the system responds with a 200 status and logs the message.

---

### User Story 5 - Inventory Frontend API Contract (Priority: P2)

The team documents which pages exist in the frontend, which API endpoints each page calls, which endpoints are missing or deprecated, and which endpoints have no callers. This inventory confirms the actual frontend-backend contract and reveals gaps against the planned §2.17 modules.

**Why this priority**: This is foundational documentation. The gap list informs Phase 1 and later work. It's lower priority than the technical tests because it's documentation, not infrastructure, but it's required before design decisions in Phase 1.

**Independent Test**: Analyze the frontend source code, extract endpoint calls, cross-reference against the backend API, and document gaps. Deliverable: a markdown report.

**Acceptance Scenarios**:

1. **Given** the frontend source, **When** all pages are analyzed, **Then** a list of pages and their API endpoints is produced.
2. **Given** the endpoint list, **When** cross-referenced against the backend API, **Then** missing/deprecated endpoints are identified.
3. **Given** the backend API, **When** all endpoints are enumerated, **Then** unreferenced endpoints (dead code from frontend perspective) are noted.

---

### Edge Cases

- What happens if the Postgres container is not running? (Test should fail gracefully with a clear error.)
- What if a migration is already partially applied? (Migrations should be idempotent or error clearly; schema state must be resolvable.)
- What if the frontend build has unresolved dependencies? (Build should fail with clear error; dependency resolution must be fixed before proceeding.)
- What if the WhatsApp webhook receives a malformed payload? (Handler should log the error and respond gracefully, not crash the worker.)

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Pest test suite MUST execute completely against the PostgreSQL testing database with pgvector extension.
- **FR-002**: The test runner MUST report pass/fail counts and a list of failing tests with their error messages.
- **FR-003**: All migrations MUST run successfully in sequence when applied to a fresh database schema.
- **FR-004**: The Laravel seeder MUST populate seed data without errors after migrations complete.
- **FR-005**: The frontend MUST build without errors using the configured build script (Next.js/Webpack).
- **FR-006**: The frontend MUST pass linting checks with no errors (warnings tolerated per config).
- **FR-007**: The queue worker MUST process queued jobs without fatal crashes.
- **FR-008**: Scheduled jobs MUST run on their schedule without fatal crashes.
- **FR-009**: The WhatsApp webhook endpoint MUST accept a test payload and respond with a 200 status.
- **FR-010**: Frontend API calls MUST be extracted and documented, with endpoints mapped to pages.
- **FR-011**: API endpoints MUST be classified as in-use, deprecated, or unused (from frontend perspective).

### Key Entities

- **Test Suite**: Pest framework, test files in `tests/`, executed against Postgres (already exists; baseline only).
- **Migration Pipeline**: Laravel migrations in `database/migrations/`, seeder in `database/seeders/`.
- **Frontend Build**: Next.js build pipeline in `ecosystem-frontend/` (separate repo).
- **Queue Worker**: Laravel queue worker, processes jobs (already exists; smoke test only).
- **Scheduled Jobs**: Laravel scheduler in `routes/console.php` (already exists; smoke test only).
- **WhatsApp Webhook**: HTTP endpoint that receives inbound WhatsApp messages (already exists; smoke test only).
- **Frontend Inventory**: Markdown document listing pages, API calls, and gaps.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All Pest tests execute and a baseline report is generated listing pass/fail counts.
- **SC-002**: Database migrations run cleanly from scratch and seed data is loaded in under 5 minutes.
- **SC-003**: Frontend builds without errors and linting passes (zero linting errors).
- **SC-004**: Queue worker and scheduler run a smoke test job without errors; WhatsApp webhook accepts a test payload.
- **SC-005**: Frontend inventory document is complete and lists all pages, API endpoints, and identified gaps.
- **SC-006**: Baseline report and inventory are committed to `docs/baseline-2026-09.md` for future reference.

## Assumptions

- PostgreSQL with pgvector extension is available and running locally (confirmed by CLAUDE.md setup steps).
- The frontend repository (`ecosystem-frontend`) is checked out and accessible alongside the main repo (or can be cloned).
- Test configuration in `phpunit.xml` correctly targets the testing database.
- Existing migrations and seeders are syntactically correct (i.e., they worked in the past).
- The frontend build pipeline (Node.js, npm/pnpm) is available and up to date.
- No new features or domain changes are made in Phase 0; only baseline measurement and documentation.
- WhatsApp webhook test does not require live Meta credentials; a local test payload is sufficient.
- The `docs/` directory exists and is writable for baseline report output.
