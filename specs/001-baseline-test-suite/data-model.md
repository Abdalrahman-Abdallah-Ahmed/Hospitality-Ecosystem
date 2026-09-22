# Phase 1 Data Model: Baseline Measurement Artifacts

**Date**: 2026-09-22
**Feature**: Baseline Test Suite & Frontend Inventory
**Purpose**: Define the data structures and relationships used in Phase 0 baseline measurement

---

## Overview

Phase 0 does not introduce new database entities or domain models. Instead, it documents and measures the state of existing infrastructure. This document defines the "measurement artifacts" (outputs) from each baseline task.

---

## Measurement Artifacts

### 1. Test Suite Baseline Report

**Name**: `TestSuiteBaseline`

**Purpose**: Record the pass/fail state of the Pest test suite at the start of Phase 1.

**Data Structure**:
```
{
  "timestamp": "ISO 8601 datetime",
  "environment": {
    "php_version": "string (e.g., 8.3.0)",
    "laravel_version": "string (e.g., 13.8.0)",
    "pest_version": "string (e.g., 4.0.0)",
    "database": "PostgreSQL version",
    "pgvector_available": boolean
  },
  "execution": {
    "total_tests": number,
    "passed": number,
    "failed": number,
    "skipped": number,
    "duration_seconds": number,
    "exit_code": number
  },
  "failures": [
    {
      "test_class": "string",
      "test_method": "string",
      "error_message": "string (first 500 chars)",
      "failure_type": "AssertionError | Exception | Timeout"
    }
  ],
  "notes": "string (human observations, e.g., 'Expected 3 failures in payment processing')"
}
```

**Storage**: `docs/baseline-2026-09.md` (Markdown report with structured data section)

**Validation Rules**:
- `total_tests` = `passed` + `failed` + `skipped`
- `exit_code` = 0 if baseline is recorded (regardless of pass/fail counts)
- `duration_seconds` <= 600 (10 minutes is acceptable baseline)

---

### 2. Migration Baseline Report

**Name**: `MigrationBaseline`

**Purpose**: Record the state of the migration system and database schema.

**Data Structure**:
```
{
  "timestamp": "ISO 8601 datetime",
  "execution": {
    "command": "php artisan migrate:fresh --seed",
    "duration_seconds": number,
    "exit_code": number,
    "success": boolean
  },
  "schema": {
    "table_count": number,
    "index_count": number,
    "constraint_count": number,
    "tables": [
      {
        "name": "string",
        "column_count": number,
        "has_soft_delete": boolean,
        "has_timestamps": boolean
      }
    ]
  },
  "seed_data": {
    "users_count": number,
    "hotels_count": number,
    "rooms_count": number,
    "guests_count": number,
    "other_entities": "string description"
  },
  "notes": "string (e.g., 'All tables present; seed data loaded successfully')"
}
```

**Storage**: `docs/baseline-2026-09.md` (Markdown report with structured data section)

**Validation Rules**:
- `duration_seconds` <= 300 (5 minutes, per spec)
- `success` = true (migrations MUST run cleanly)
- `exit_code` = 0
- All critical tables present (hotels, rooms, guests, reservations, stays, tasks, etc.)

---

### 3. Frontend Build Baseline Report

**Name**: `FrontendBuildBaseline`

**Purpose**: Record the build and lint status of the Next.js frontend.

**Data Structure**:
```
{
  "timestamp": "ISO 8601 datetime",
  "environment": {
    "node_version": "string",
    "npm_version": "string",
    "next_version": "string"
  },
  "build": {
    "command": "npm run build",
    "exit_code": number,
    "success": boolean,
    "duration_seconds": number,
    "output_size_mb": number,
    "warnings_count": number,
    "errors_count": number
  },
  "lint": {
    "command": "npm run lint",
    "exit_code": number,
    "success": boolean,
    "errors_count": number,
    "warnings_count": number,
    "failing_files": ["string (file paths)"]
  },
  "notes": "string (e.g., 'Build successful; 2 linting warnings in config files')"
}
```

**Storage**: `docs/baseline-2026-09.md` (Markdown report with structured data section)

**Validation Rules**:
- Build `exit_code` = 0
- Build `success` = true
- Lint `errors_count` = 0 (errors must be zero; warnings tolerated)

---

### 4. Background Infrastructure Baseline Report

**Name**: `BackgroundInfrastructureBaseline`

**Purpose**: Record the health of queue worker, scheduler, and WhatsApp webhook.

**Data Structure**:
```
{
  "timestamp": "ISO 8601 datetime",
  "queue_worker": {
    "command": "php artisan queue:work --once",
    "status": "working | failed | timeout",
    "errors": ["string (error messages)"]
  },
  "scheduler": {
    "command": "php artisan schedule:run",
    "status": "working | failed | timeout",
    "jobs_executed": number,
    "errors": ["string (error messages)"]
  },
  "whatsapp_webhook": {
    "endpoint": "/api/whatsapp/webhook",
    "test_payload_sent": boolean,
    "http_status": number,
    "response_time_ms": number,
    "errors": ["string (error messages)"]
  },
  "notes": "string (e.g., 'All background work systems operational')"
}
```

**Storage**: `docs/baseline-2026-09.md` (Markdown report with structured data section)

**Validation Rules**:
- Queue worker `status` = "working" (jobs process without fatal errors)
- Scheduler `status` = "working" (scheduled tasks run without fatal errors)
- WhatsApp webhook `http_status` = 200 (accepts request)

---

### 5. Frontend API Contract Inventory

**Name**: `FrontendAPIContractInventory`

**Purpose**: Document the contract between the frontend and backend API.

**Data Structure**:
```
{
  "timestamp": "ISO 8601 datetime",
  "summary": {
    "frontend_pages_total": number,
    "backend_endpoints_total": number,
    "endpoints_in_use": number,
    "endpoints_unused": number,
    "endpoints_missing": number
  },
  "frontend_pages": [
    {
      "page_name": "string (e.g., 'HotelsPage')",
      "route": "string (e.g., '/hotels')",
      "endpoints_called": ["string (e.g., 'GET /api/hotels')"]
    }
  ],
  "backend_endpoints": [
    {
      "method": "GET | POST | PUT | DELETE | PATCH",
      "path": "string (e.g., '/api/hotels')",
      "frontend_usage": "in-use | unused | deprecated",
      "called_by_pages": ["string (page names)"]
    }
  ],
  "gaps": {
    "missing_endpoints": [
      "string (endpoints defined in backend but not called)"
    ],
    "undefined_endpoints": [
      "string (endpoints called by frontend but not defined in backend)"
    ]
  },
  "notes": "string (e.g., 'Frontend modules match planned scope; no major gaps')"
}
```

**Storage**: `docs/baseline-2026-09.md` (Markdown report with detailed tables)

**Validation Rules**:
- All frontend pages are inventoried
- All backend endpoints are categorized
- Gaps are identified and documented
- No undefined endpoints in production code (dev/debug code exceptions tolerated)

---

## Relationships & Dependencies

```
TestSuiteBaseline
  ├── Measures: all tests in tests/ directory
  ├── Depends on: PostgreSQL, phpunit.xml configuration
  └── Feeds into: Phase 1 test improvement plans

MigrationBaseline
  ├── Measures: database schema and seed data
  ├── Depends on: PostgreSQL, database/migrations/, database/seeders/
  └── Feeds into: Phase 1 schema restructuring decisions

FrontendBuildBaseline
  ├── Measures: Next.js build and lint status
  ├── Depends on: Node.js, npm/pnpm, package.json
  └── Feeds into: Phase 1 frontend readiness for API changes

BackgroundInfrastructureBaseline
  ├── Measures: Queue worker, scheduler, webhook health
  ├── Depends on: Laravel queue config, scheduler config, webhook endpoint
  └── Feeds into: Phase 1 background work planning

FrontendAPIContractInventory
  ├── Measures: frontend → backend API usage
  ├── Depends on: ecosystem-frontend/ source, routes/api.php, routes/admin.php
  └── Feeds into: Phase 1 API contract validation and endpoint gap filling
```

---

## Validation & Constraints

**Overall Validation Rules**:
- All baseline reports are dated and timestamped
- All reports include environment/version information
- All status fields use consistent enum values (success | failure | error | skipped)
- All measurements are objective (counts, durations, exit codes) rather than subjective
- Notes sections document any deviations from expected baseline state
- Reports are human-readable Markdown with optional structured data sections (JSON, YAML)

**Data Integrity**:
- Baseline reports are append-only (never deleted or overwritten after first generation)
- Future phases reference Phase 0 baseline for comparison (e.g., "test count increased from X to Y")
- Baseline acts as a snapshot for regression analysis

---

## Summary

Phase 0 establishes five measurement artifacts that form the baseline for the hospitality platform. These artifacts are:

1. **Test Suite Baseline**: Pass/fail state of Pest tests
2. **Migration Baseline**: Schema and seed data state
3. **Frontend Build Baseline**: Build and lint status
4. **Background Infrastructure Baseline**: Queue, scheduler, webhook health
5. **Frontend API Contract Inventory**: Frontend ↔ Backend API usage mapping

All artifacts are generated and committed to `docs/baseline-2026-09.md` for future reference and regression analysis.
