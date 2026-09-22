# Implementation Plan: Baseline Test Suite & Frontend Inventory

**Branch**: `001-baseline-test-suite` | **Date**: 2026-09-22 | **Spec**: `specs/001-baseline-test-suite/spec.md`

**Input**: Feature specification from `specs/001-baseline-test-suite/spec.md`

**Note**: This plan executes Phase 0 (research) to resolve infrastructure dependencies and document baseline measurement procedures.

## Summary

Execute a comprehensive baseline health check of the AI Hospitality Ecosystem application before any domain restructuring begins. The baseline covers:
- **Test suite**: Run all Pest tests against PostgreSQL with pgvector and record pass/fail counts
- **Migrations**: Verify migrations run cleanly from scratch with seeding
- **Frontend**: Confirm Next.js build and linting succeed
- **Background work**: Smoke-test queue worker, scheduler, and WhatsApp webhook
- **API contract**: Inventory frontend pages, their API calls, and identify gaps vs. backend

Output all results to `docs/baseline-2026-09.md` for future reference. No domain changes; measurement and documentation only.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13

**Primary Dependencies**: Pest 4 (testing), Laravel (migrations, queue, scheduler), PostgreSQL (storage), pgvector (vector search), Next.js (frontend), Sanctum (API auth)

**Storage**: PostgreSQL with pgvector extension; test database = `Hospitality_Ecosystem_testing` (per `phpunit.xml`)

**Testing**: Pest 4 with RefreshDatabase trait; tests run against real PostgreSQL, never SQLite

**Target Platform**: Local development environment + CI/CD (GitHub Actions or equivalent)

**Project Type**: Multi-tenant web service (Laravel JSON API + Next.js frontend)

**Performance Goals**: Migrations complete in <5 minutes; test suite completes in <10 minutes (baseline measurement)

**Constraints**: Phase 0 is measurement only—no schema changes, no feature work, no breaking changes

**Scale/Scope**: Existing codebase (PHP, Laravel, React/Next.js) across hospitality domain (hotels, guests, reservations, rooms, tasks, activities, recommendations, AI agents, WhatsApp)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

✅ **Principle I (PMS Is the System of Record)**: Phase 0 does not modify PMS data; only measures test health. PASS

✅ **Principle II (Preserve and Evolve)**: Phase 0 establishes baseline of existing functionality; no deletions or replacements. PASS

✅ **Principle III (Tenant Isolation - NON-NEGOTIABLE)**: Phase 0 does not touch tenant boundaries; smoke tests are against development fixtures. PASS

✅ **Principle IV (Permission-Based Authorization - NON-NEGOTIABLE)**: Phase 0 is infrastructure testing, not authorization testing. Baseline documents current state. PASS

✅ **Principle V (AI Acts Through Tools, Never Around Them)**: Phase 0 smoke-tests AI tools (WhatsApp webhook) against local test payloads. PASS

✅ **Principle VI (Auditability and Observability)**: Phase 0 documents test results, logs, and failures in `docs/baseline-2026-09.md`. PASS

✅ **Principle IX (Spec-Driven, Incremental Delivery)**: Phase 0 follows specification, generates research findings, feeds into Phase 1. PASS

✅ **Non-Negotiable Rule 25 (MVP must remain incrementally deployable)**: Phase 0 is non-breaking baseline measurement. PASS

**Result**: All constitution principles and non-negotiable rules pass. Phase 0 is compliant.

## Project Structure

### Documentation (this feature)

```text
specs/001-baseline-test-suite/
├── spec.md              # Feature specification
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output: infrastructure baseline findings
├── quickstart.md        # Phase 1 output: validation procedures
└── tasks.md             # Phase 2 output (/speckit-tasks command)
```

### Source Code (repository root)

The project is a multi-tenant web service with two codebases:

```text
Backend (Laravel API):
app/
├── Http/Controllers/        # API endpoints (not modified for Phase 0)
├── Http/Requests/           # Request validation (not modified for Phase 0)
├── Http/Resources/          # API responses (not modified for Phase 0)
├── Models/                  # Eloquent models (not modified for Phase 0)
├── Services/                # Business logic (not modified for Phase 0)
├── Jobs/                    # Queued work (smoke tested in Phase 0)
├── Policies/                # Authorization (not modified for Phase 0)
└── Support/                 # Framework helpers (not modified for Phase 0)

database/
├── migrations/              # Schema migrations (tested in Phase 0)
└── seeders/                 # Database seeders (tested in Phase 0)

routes/
├── api.php                  # API routes (inventory in Phase 0)
├── console.php              # Scheduled jobs (smoke tested in Phase 0)
└── admin.php                # Super-admin routes (not modified for Phase 0)

tests/
├── Feature/                 # Feature tests (executed in Phase 0)
├── Unit/                    # Unit tests (executed in Phase 0)
└── Pest.php                 # Pest configuration

Frontend (Next.js):
ecosystem-frontend/
├── src/
│   ├── pages/               # Route pages (inventoried in Phase 0)
│   ├── components/          # React components (not modified for Phase 0)
│   └── lib/                 # API client, utilities (inventoried in Phase 0)
└── tests/                   # Frontend tests (executed in Phase 0)
```

**Structure Decision**: Phase 0 is non-intrusive. No new files are created in the source code; only measurement and documentation happen. The backend and frontend remain untouched. All output goes to `docs/baseline-2026-09.md`.

## Complexity Tracking

No constitution violations. Phase 0 is baseline measurement only, non-breaking and compliant with all principles and non-negotiable rules. No complexity justification required.

---

## Phase 0 Output: Research Findings

**File**: `research.md`

Resolves all infrastructure dependencies and confirms:
- Pest 4 test runner is ready (PostgreSQL testing database configured)
- Laravel migrations run cleanly (existing migrations in `database/migrations/`)
- PostgreSQL + pgvector container is available (`.postgres/compose.yaml`)
- Next.js frontend build pipeline is ready (build and lint scripts configured)
- Queue worker and scheduler are operational (existing jobs and schedules)
- WhatsApp webhook is integrated and testable (endpoint ready for local testing)
- Frontend API contract analysis is feasible (source code available for inspection)

**Conclusion**: All Phase 0 infrastructure is in place. No changes or new tools needed.

---

## Phase 1 Output: Design Artifacts

### data-model.md

Defines the five measurement artifacts that Phase 0 will generate:
1. **TestSuiteBaseline**: Pass/fail counts, error list, duration, environment info
2. **MigrationBaseline**: Schema state, seed data counts, execution time
3. **FrontendBuildBaseline**: Build success, bundle size, lint results
4. **BackgroundInfrastructureBaseline**: Queue, scheduler, webhook health status
5. **FrontendAPIContractInventory**: Pages → endpoints mapping, gaps, unused endpoints

Each artifact has:
- Data structure (fields, types, validation rules)
- Storage location (`docs/baseline-2026-09.md`)
- Validation constraints
- Relationships to other artifacts

### quickstart.md

Runnable validation procedures that prove Phase 0 works end-to-end:
- **Task 1**: Run Pest test suite and capture pass/fail counts
- **Task 2**: Run migrations from scratch and verify schema
- **Task 3**: Build frontend and run linting
- **Task 4**: Smoke test queue worker, scheduler, WhatsApp webhook
- **Task 5**: Inventory frontend API calls and identify gaps
- **Integration**: Compile all results into `docs/baseline-2026-09.md` and commit

Each task includes:
- Prerequisites (what must be in place)
- Commands to run
- Expected output (example)
- Acceptance criteria (checklist)
- Results to record (what goes in baseline report)

**Contracts**: Phase 0 defines no new external interfaces. All measurement uses existing infrastructure (Pest, Laravel, Next.js, API endpoints). No contracts directory needed.

---

## Readiness Summary

| Artifact | Status | Location |
|----------|--------|----------|
| **Spec** | ✅ Complete | `spec.md` |
| **Research** | ✅ Complete | `research.md` |
| **Data Model** | ✅ Complete | `data-model.md` |
| **Quickstart** | ✅ Complete | `quickstart.md` |
| **Constitution Check** | ✅ Passed | Inline (§ Constitution Check) |
| **Infrastructure** | ✅ Ready | Existing tools and frameworks |

**Next Step**: Run `/speckit-tasks` to generate `tasks.md` with executable work breakdown for Phase 0 baseline measurement.
