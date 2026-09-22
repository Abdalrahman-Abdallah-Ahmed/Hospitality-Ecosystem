# Phase 0 Research: Infrastructure Baseline Findings

**Date**: 2026-09-22
**Feature**: Baseline Test Suite & Frontend Inventory
**Purpose**: Document existing infrastructure and tools that support Phase 0 baseline measurement

---

## 1. Pest Test Runner & Configuration

**Decision**: Use existing Pest 4 configuration in `phpunit.xml` and test files in `tests/`.

**Rationale**: 
- Pest is already installed and configured (see `composer.json`)
- Test database configured as `Hospitality_Ecosystem_testing` in `phpunit.xml`
- Tests already use `RefreshDatabase` trait to run against PostgreSQL
- No new test infrastructure needed; only execution and baseline recording

**Alternatives Considered**:
- PHPUnit directly: rejected (Pest is the team's choice and is already integrated)
- In-memory SQLite for faster tests: rejected (constitution requires real PostgreSQL with pgvector)

**Execution Approach**:
```bash
php artisan test                    # Runs all tests in tests/
php artisan test --filter=<name>   # Runs filtered tests
```

**Baseline Recording**:
- Capture stdout/stderr from `php artisan test`
- Extract pass/fail counts and failure list
- Document in `docs/baseline-2026-09.md`

**Verification**: All tests execute (some may fail; that's expected for baseline)

---

## 2. Laravel Migration System

**Decision**: Use existing migrations in `database/migrations/` with standard Laravel commands.

**Rationale**:
- Migrations already exist and follow Laravel conventions
- `phpunit.xml` defines testing database name
- Standard `php artisan migrate` runs migrations in sequence
- Seeders in `database/seeders/` already exist and are idempotent

**Alternatives Considered**:
- Manual schema creation: rejected (migrations are the authoritative source)
- Snapshot/backup restore: rejected (migrations must be testable from scratch)

**Execution Approach**:
```bash
# Drop and recreate testing database
php artisan migrate:fresh --database=testing

# Or with seeding
php artisan migrate:fresh --seed --database=testing
```

**Baseline Recording**:
- Time migration execution (target: <5 minutes)
- Capture schema state (table count, index count, constraint definitions)
- Document in `docs/baseline-2026-09.md`

**Verification**: Migrations run, schema is valid, seed data loads

---

## 3. PostgreSQL with pgvector

**Decision**: Use existing PostgreSQL container (see `.postgres/compose.yaml`) with pgvector extension.

**Rationale**:
- Container is already defined in `.postgres/compose.yaml`
- pgvector is installed and available per CLAUDE.md requirements
- Connection string is in `.env` / config
- Testing database is separate from development database

**Alternatives Considered**:
- SQLite for testing: rejected (pgvector is not available; constitution requires Postgres)
- In-memory H2/other: rejected (not applicable to PHP/Laravel stack)

**Execution Approach**:
```bash
docker compose -f .postgres/compose.yaml up -d   # Start container
```

**Verification**: 
- Container is running
- pgvector extension is available
- Testing database exists and is accessible

---

## 4. Frontend Build (Next.js)

**Decision**: Use existing Next.js build pipeline in `ecosystem-frontend/` with configured build and lint scripts.

**Rationale**:
- Frontend is already a Next.js 16 application (per CLAUDE.md)
- `package.json` has `build` and `lint` scripts
- Node.js and npm/pnpm are required development tools
- No framework changes needed; only execution and baseline recording

**Alternatives Considered**:
- Webpack directly: rejected (Next.js build abstracts complexity)
- Skip frontend for Phase 0: rejected (spec requires frontend readiness check)

**Execution Approach**:
```bash
cd ecosystem-frontend
npm run build              # Or: pnpm build
npm run lint              # Or: pnpm lint
```

**Baseline Recording**:
- Capture build output (bundle size, warnings, errors)
- Capture lint output (error count, warning count, file violations)
- Document in `docs/baseline-2026-09.md`

**Verification**: Build succeeds without errors; linting passes (warnings tolerated per config)

---

## 5. Queue Worker & Scheduler

**Decision**: Use existing Laravel queue worker and scheduler via `php artisan queue:work` and `php artisan schedule:run`.

**Rationale**:
- Queue system already exists and is configured in `config/queue.php`
- Jobs are defined in `app/Jobs/` and already exist
- Scheduler is defined in `routes/console.php`
- No new queue infrastructure needed; only smoke testing

**Alternatives Considered**:
- Standalone test jobs: rejected (use existing jobs to test real infrastructure)
- Skip background work testing: rejected (spec requires smoke test)

**Execution Approach**:
```bash
php artisan queue:work --once                    # Process one job and exit
php artisan schedule:run                         # Run scheduled commands
```

**Baseline Recording**:
- Queue worker: queue a test job and verify processing
- Scheduler: trigger a scheduled job and verify execution
- Capture any errors or warnings
- Document in `docs/baseline-2026-09.md`

**Verification**: Queue processes jobs without fatal crashes; scheduler runs without fatal crashes

---

## 6. WhatsApp Webhook & Idempotency

**Decision**: Test the WhatsApp webhook endpoint with a local mock payload (no live Meta credentials required).

**Rationale**:
- Webhook endpoint exists at `routes/api.php` (WhatsApp route)
- Webhook handler is in the codebase and processes inbound messages
- Idempotency is built-in via message deduplication
- Testing against local mock payload is sufficient for smoke test

**Alternatives Considered**:
- Live Meta credentials and test messages: rejected (overkill for baseline smoke test; requires live setup)
- Skip webhook testing: rejected (spec requires WhatsApp infrastructure check)

**Execution Approach**:
```bash
# Mock WhatsApp inbound message (example payload structure)
curl -X POST http://localhost:8000/api/whatsapp/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "object": "whatsapp_business_account",
    "entry": [{
      "id": "ENTRY_ID",
      "changes": [{
        "value": {
          "messaging_product": "whatsapp",
          "metadata": {
            "display_phone_number": "1234567890",
            "phone_number_id": "102929..."
          },
          "messages": [{
            "from": "1234567890",
            "id": "wamid.xxx",
            "text": { "body": "Test message" }
          }]
        }
      }]
    }]
  }'
```

**Baseline Recording**:
- Verify endpoint returns 200 status
- Check for errors in logs
- Document in `docs/baseline-2026-09.md`

**Verification**: Webhook accepts request and processes without fatal error

---

## 7. Frontend API Contract Inventory

**Decision**: Analyze frontend source code to extract API endpoint calls and cross-reference against backend routes.

**Rationale**:
- Frontend is a separate Next.js application with API client code
- API calls are made via HTTP (axios, fetch, or similar)
- Backend routes are defined in `routes/api.php` and `routes/admin.php`
- Inventory provides gap analysis for later phases

**Alternatives Considered**:
- Automated code parsing: rejected (manual analysis is sufficient for Phase 0; not a tool-building phase)
- Skip inventory: rejected (spec requires frontend → backend contract documentation)

**Execution Approach**:
1. Analyze `ecosystem-frontend/src/lib/` and `pages/` for API calls (grep for fetch/axios)
2. Extract endpoint names and methods (GET, POST, etc.)
3. Enumerate backend routes in `routes/api.php` and `routes/admin.php`
4. Cross-reference: which endpoints are called by frontend? Which backend endpoints have no callers?
5. Document in `docs/baseline-2026-09.md` with a table/matrix

**Baseline Recording**:
- List of frontend pages and the endpoints they call
- List of backend endpoints and their usage status (in-use, unused, deprecated)
- Gaps: endpoints defined in backend but not called by frontend, or called endpoints that don't exist

**Verification**: Inventory is complete, accurate, and identifies gaps for Phase 1 work

---

## Summary of Research Findings

All Phase 0 infrastructure is already in place and requires no changes:

| Component | Tool/Framework | Status | Note |
|-----------|---|---|---|
| **Test Runner** | Pest 4 + phpunit.xml | ✅ Ready | Configured for PostgreSQL testing database |
| **Database** | PostgreSQL + pgvector | ✅ Ready | Docker container defined in `.postgres/compose.yaml` |
| **Migrations** | Laravel migrations | ✅ Ready | Existing migrations in `database/migrations/` |
| **Frontend Build** | Next.js 16 | ✅ Ready | Build and lint scripts in `package.json` |
| **Queue Worker** | Laravel Queue | ✅ Ready | Jobs defined in `app/Jobs/` |
| **Scheduler** | Laravel Schedule | ✅ Ready | Scheduled commands in `routes/console.php` |
| **WhatsApp Webhook** | HTTP endpoint | ✅ Ready | Webhook handler already integrated |
| **API Inventory** | Manual analysis | ✅ Ready | Code analysis of `ecosystem-frontend/` and `routes/` |

**Conclusion**: Phase 0 can proceed directly to task execution. No infrastructure gaps or NEEDS CLARIFICATION items remain.
