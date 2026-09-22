# Phase 1 Quickstart: Baseline Validation Procedures

**Date**: 2026-09-22
**Feature**: Baseline Test Suite & Frontend Inventory
**Purpose**: Runnable validation scenarios that prove Phase 0 baseline measurement works end-to-end

---

## Prerequisites

Before running any baseline measurement, verify these prerequisites:

- **PHP 8.3+**: `php --version`
- **Composer**: `composer --version`
- **Node.js 18+**: `node --version` and `npm --version`
- **PostgreSQL 15+**: `psql --version` and `docker ps` (see below)
- **Docker**: `docker --version` (for PostgreSQL container)
- **Git**: Current working directory is the repository root

---

## Setup: Start PostgreSQL Container

The baseline measurement requires a running PostgreSQL instance with pgvector.

### Command

```bash
# From repository root
docker compose -f .postgres/compose.yaml up -d

# Verify container is running
docker ps | grep postgres
```

### Expected Output

```
CONTAINER ID   IMAGE     STATUS         PORTS
abc123xyz      postgres  Up X seconds   5432->5432
```

**Validation**: Container is running and port 5432 is accessible.

---

## Task 1: Run Test Suite Baseline

Measure the current state of the Pest test suite.

### Prerequisites

- PostgreSQL container is running (see Setup above)
- Composer dependencies are installed: `composer install`

### Commands

```bash
# Run all tests and capture results
php artisan test

# Alternative: Run tests with verbose output
php artisan test --verbose

# Alternative: Run with test report file (if configured in phpunit.xml)
php artisan test --log-junit=tests/results/junit.xml
```

### Expected Output (Example)

```
   PASS  tests/Feature/HotelControllerTest.php
     ✓ can view hotels
     ✓ can create hotel
     ✓ can update hotel
     ✓ tenant isolation prevents viewing other hotels
     ...

   FAIL  tests/Feature/PaymentProcessingTest.php
     ✗ can process payment (Expected: 0, Received: 500)
     ...

   Tests:  XX passed, YY failed, ZZ skipped
   Duration: XX.XXX seconds
```

### Acceptance Criteria

- [ ] Test suite completes (all tests run; not hung or crashed)
- [ ] Pass/fail counts are printed
- [ ] Failed tests are listed with error summaries
- [ ] Exit code is 0 (tests ran; failures are expected in baseline)

### Record in Baseline

From the output, record:
- Total tests run
- Number passed / failed / skipped
- Duration in seconds
- Any notable failures (first 500 chars of error message)
- Environment: PHP version, Laravel version, Pest version

---

## Task 2: Verify Migrations Run Clean

Measure migration system health by running migrations from scratch.

### Prerequisites

- PostgreSQL container is running (see Setup above)
- Composer dependencies installed: `composer install`

### Commands

```bash
# Drop testing database, re-create, run migrations, and seed
php artisan migrate:fresh --seed --database=testing

# Alternative: Just migrations without seed
php artisan migrate:fresh --database=testing

# Verify schema
php artisan migrate:status --database=testing
```

### Expected Output (Example)

```
Dropped all tables successfully.
Migration table created successfully.
Migrated: 2022_01_01_000000_create_users_table
Migrated: 2022_01_02_000000_create_hotels_table
Migrated: 2022_01_03_000000_create_rooms_table
...
Seeding: DatabaseSeeder
Seeded: UserSeeder (XX users created)
Seeded: HotelSeeder (XX hotels created)
...
Database seeding completed successfully.
```

### Acceptance Criteria

- [ ] All migrations run without error (exit code 0)
- [ ] Seeder runs without error
- [ ] No SQL constraint violations or foreign key errors
- [ ] Duration is under 5 minutes

### Record in Baseline

From the output, record:
- Total migrations run
- Duration in seconds
- Seed data counts (users, hotels, rooms, guests, etc.)
- Table count and index count (from schema inspection)

---

## Task 3: Run Frontend Build and Lint

Measure frontend build and code quality status.

### Prerequisites

- Node.js 18+ and npm are installed
- Frontend source is in `ecosystem-frontend/` directory (may be separate repo)

### Commands

```bash
# Navigate to frontend
cd ecosystem-frontend

# Install dependencies (if not already done)
npm install

# Run build
npm run build

# Run linting
npm run lint

# Return to repo root
cd ..
```

### Expected Output (Example)

**Build**:
```
> next build

  ▲ Next.js 16.2.11
  - Local:        http://localhost:3000

  ✓ Compiled successfully
  ✓ Linting and checking validity of types
  ✓ Collecting page data
  ✓ Generating static pages (X/X)
  ✓ Finalizing page optimization

  Route (pages)                              Size       First Load JS
  ┌ ○ /                                      XXX KB     XXX KB
  ├ ○ /404                                   XXX KB     XXX KB
  ...

  ✓ Build complete.
```

**Linting**:
```
> next lint

✓ No ESLint errors

  warnings:  X (tolerated by config)
```

### Acceptance Criteria

- [ ] Build exits with code 0 (success)
- [ ] No build errors reported
- [ ] Linting exits with code 0
- [ ] Linting shows 0 errors (warnings are okay)
- [ ] Output size is reasonable (bundles present)

### Record in Baseline

From the output, record:
- Build duration in seconds
- Build size (total JS bundle size)
- Linting error count (should be 0)
- Linting warning count
- Node version, npm version, Next.js version

---

## Task 4: Smoke Test Background Infrastructure

Verify queue worker, scheduler, and WhatsApp webhook function without fatal crashes.

### Prerequisites

- PostgreSQL container is running
- Composer dependencies installed
- Backend is runnable: `php artisan serve` (optional; for webhook test)

### 4A. Queue Worker

```bash
# Process one queued job and exit (ensures worker doesn't hang)
php artisan queue:work --once

# Alternative: Queue a test job first
php artisan tinker
# In tinker shell:
# \App\Jobs\ExampleJob::dispatch('test-payload');
# exit

# Then process it
php artisan queue:work --once
```

### Expected Output

```
Processing: App\Jobs\ExampleJob
Processed:  App\Jobs\ExampleJob
```

**Acceptance Criteria**:
- [ ] Worker exits without fatal error (exit code 0)
- [ ] Job is processed (or queue is empty and worker exits normally)
- [ ] No timeout or crash

### 4B. Scheduler

```bash
# Trigger the scheduler to run due tasks
php artisan schedule:run

# Or list scheduled commands
php artisan schedule:list
```

### Expected Output

```
Running scheduled command: Illuminate\Console\Scheduling\ScheduleRunCommand
Running scheduled command: App\Console\Commands\SomeScheduledCommand
... (commands that are due)
```

**Acceptance Criteria**:
- [ ] Scheduler exits without fatal error (exit code 0)
- [ ] Any due tasks execute or are skipped gracefully
- [ ] No timeout or crash

### 4C. WhatsApp Webhook

Test the webhook endpoint with a mock payload.

```bash
# Option 1: Using curl (if server is running locally)
php artisan serve &   # Start server in background

# Send test payload
curl -X POST http://localhost:8000/api/whatsapp/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "object": "whatsapp_business_account",
    "entry": [{
      "id": "test",
      "changes": [{
        "value": {
          "messaging_product": "whatsapp",
          "metadata": {
            "display_phone_number": "1234567890",
            "phone_number_id": "102929..."
          },
          "messages": [{
            "from": "5551234567",
            "id": "wamid.test123",
            "text": { "body": "Test message" }
          }]
        }
      }]
    }]
  }'

# Kill server
kill %1

# Option 2: Feature test
php artisan test tests/Feature/WhatsAppWebhookTest.php
```

### Expected Output

```
HTTP/1.1 200 OK
Content-Type: application/json

{"success":true}
```

**Acceptance Criteria**:
- [ ] Webhook responds with HTTP 200
- [ ] No fatal error in logs
- [ ] Message is logged or processed

### Record in Baseline

For all three (queue, scheduler, webhook):
- Status: working | failed | timeout
- Any error messages or warnings

---

## Task 5: Inventory Frontend API Contract

Document which pages exist in the frontend and which API endpoints they call.

### Prerequisites

- Frontend source in `ecosystem-frontend/`
- Backend routes in `routes/api.php` and `routes/admin.php`

### Analysis Process (Manual or Scripted)

```bash
# Option 1: Manual grep and analysis
cd ecosystem-frontend/src

# Find all API endpoint calls
grep -r "fetch(" .                    # Find fetch() calls
grep -r "axios\." .                   # Find axios calls
grep -r "client\." .                  # Find API client calls

# List all pages
find pages -name "*.tsx" -o -name "*.ts" | head -20

# Return to repo root
cd ../../

# List backend routes
grep -r "Route::" routes/api.php | head -20
```

### Expected Output (Example)

```
Frontend Pages:
- /pages/hotels.tsx → GET /api/hotels, POST /api/hotels, GET /api/hotels/{id}
- /pages/guests.tsx → GET /api/guests, GET /api/guests/{id}
- /pages/reservations.tsx → GET /api/reservations, POST /api/reservations
...

Backend Endpoints:
- GET /api/hotels (in-use: HotelsPage)
- POST /api/hotels (in-use: HotelsPage)
- GET /api/hotels/{id} (in-use: HotelsPage)
- GET /api/guests (in-use: GuestsPage)
- DELETE /api/transactions (unused from frontend)
...

Gaps:
- Backend endpoint GET /api/settings exists but is unused
- Frontend calls POST /api/export but endpoint is not defined in routes/api.php
```

### Acceptance Criteria

- [ ] All frontend pages are identified
- [ ] All API calls from pages are documented
- [ ] All backend endpoints are listed
- [ ] Usage status is assigned: in-use / unused / undefined
- [ ] Gaps are identified and documented

### Record in Baseline

Create a markdown table in `docs/baseline-2026-09.md`:

```markdown
## Frontend API Contract Inventory

### Summary
- Frontend Pages: N
- Backend Endpoints: M
- Endpoints In Use: X
- Undefined Endpoints: Y (gaps to fill in Phase 1)
- Unused Endpoints: Z

### Detailed Mapping
| Page | Endpoint | Method | Status |
|------|----------|--------|--------|
| HotelsPage | /api/hotels | GET | in-use |
| HotelsPage | /api/hotels | POST | in-use |
| ... | ... | ... | ... |

### Gaps
| Gap Type | Endpoint | Notes |
|----------|----------|-------|
| Unused | GET /api/transactions | Not called by any page; feature flag disabled |
| Undefined | POST /api/export | Called by ExportPage but not defined in routes |
```

---

## Integration: Compile Baseline Report

After all five tasks complete, compile the final baseline report.

### Commands

```bash
# Create the baseline document (or append to existing)
cat > docs/baseline-2026-09.md << 'EOF'
# Baseline Report — 2026-09-22

## Test Suite Baseline
[Results from Task 1]

## Migration Baseline
[Results from Task 2]

## Frontend Build Baseline
[Results from Task 3]

## Background Infrastructure Baseline
[Results from Task 4]

## Frontend API Contract Inventory
[Results from Task 5]

## Summary
- All baseline measurements complete
- No critical infrastructure failures
- Ready for Phase 1 development
EOF

# Commit to git
git add docs/baseline-2026-09.md
git commit -m "docs: Phase 0 baseline report [2026-09-22]"
```

### Expected Output

```
[001-baseline-test-suite abc1234] docs: Phase 0 baseline report [2026-09-22]
 1 file changed, 150 insertions(+)
 create mode docs/baseline-2026-09.md
```

### Validation

- [ ] Baseline report file exists
- [ ] All five sections are populated
- [ ] Report is committed to git
- [ ] Report is human-readable

---

## Success Criteria (Aggregate)

Phase 0 baseline is complete and valid when:

1. ✅ Test suite executes and baseline counts are recorded
2. ✅ Migrations run cleanly in <5 minutes
3. ✅ Frontend builds without errors and linting passes
4. ✅ Queue worker, scheduler, and webhook are operational
5. ✅ Frontend API contract is inventoried
6. ✅ All results are compiled into `docs/baseline-2026-09.md`
7. ✅ Baseline report is committed to git

All acceptance criteria met → **Phase 0 complete, ready for Phase 1**.
