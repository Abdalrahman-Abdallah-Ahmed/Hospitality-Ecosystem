# Implementation Plan: Insights Citations and AI Economics Verification

**Branch**: `012-insights-ai-economics` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/012-insights-ai-economics/spec.md`.
Phase 12 of the master plan: SPEC-090 Insights Citations and SPEC-091 Usage Visibility
Audit, plus metering for the Phase 7–11 AI operations. Governed by the constitution's "AI
Measurement, Usage, and Cost" section.

**Depends on** (all on `main`):
- Phase 8: `KnowledgeSearchTool` with scopes and citations.
- Phase 10: proactive messages.
- Phase 11: `AiRun`, `ai_activities`, `ExtractGuestMemoriesJob`, `ai_usage_logs.ai_run_id`.

## Summary

**Capability attribution (R1)**:
- New `ai_usage_logs.capability` column, stamped from the AI run's agent by
  `AiCostRecorder`.
- Backfilled from the run rows, then from the agent class.
- It is the shared key for the usage and cost reports.

**Hotel-facing usage (R2, R9)**:
- New `AiUsageSummary`: the one cost-free projection over `ai_usage_logs` (requests and
  tokens by capability and by hotel).
- `UsageReport::describe()` adds an `ai` block and per-feature `measured_since`/`note`.
- Used by `GET /api/usage`, `GET /api/admin/usage` and the Admin AI usage report.
- No model or provider names (Clarification Q1).

**Cost visibility (R3, R4, R5)**:
- **Fixes for the two leaks found**:
  - the ceiling message with dollar amounts stored in `knowledge_documents.error`, which is
    shown to admins: neutral text, plus a data migration for existing rows;
  - the uncaught ceiling exception in the advisor chat: now a `503` with a neutral message.
- **Advisor instruction**: refuse to estimate cost.
- **`CostVisibilityTest`**:
  - a static allowlist of who may touch cost tables or columns;
  - a sweep of every hotel-facing `GET` route;
  - a sweep of every Admin and Insights read tool;
  - all three against seeded sentinel cost, model and provider values.

**Super-admin cost (R6)**: `by_capability` (calls, tokens, cost, estimated flag) on each
account in `/api/admin/ai-cost`.

**Metering coverage (R7, R8, R13)**:
- New `MeterFeature::GUEST_MEMORY_EXTRACTIONS`, recorded once per completed extraction
  (idempotency key = extraction id, no backfill).
- `AiAgentName::meterFeatures()`: an exhaustive capability → features map.
- `AiCapabilityCoverageTest`:
  - fails on a capability with no mapping;
  - proves each capability writes both a meter event and attributed cost rows;
  - asserts zero `unattributed`.
- The insights meter call gains an idempotency key.

**Insight citations (R10–R12)**:
- **Tool**: `InsightsAgent` gets `InsightKnowledgeSearchTool`, a decorator over
  `KnowledgeSearchTool::searchWithSources()` with the staff audience. It hands the model
  run-local refs (`K1…`) and keeps a registry.
- **Output**: each insight returns `citations: [ref]`. The job keeps only refs in the
  registry and stores snapshots in the new `ai_insights.citations` JSON column.
- **Evidence**: new category `knowledge` (always L3). The evidence rules are otherwise
  unchanged.
- **List**: the list adds per-citation `available`, batch-loaded per page.
- **Failure**: a knowledge search failure leaves insights uncited, and the run doesn't fail.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**:
- Laravel 13, Sanctum, Pest 4.
- `laravel/ai` v0.10.3: agents, tools, `HasStructuredOutput`, `Embeddings`, and fakes.
- No new packages.

**Storage**: PostgreSQL + pgvector.
- Alter `ai_insights` (+`citations` jsonb) and `ai_usage_logs` (+`capability`, with an
  index and a backfill).
- One data migration on `knowledge_documents.error`.

**Testing**:
- Pest 4 feature tests on real Postgres (`Hospitality_Ecosystem_testing`).
- Agent fakes; `Embeddings::fake()` for knowledge search.
- Pest `arch()` for the static allowlist.
- Route sweep via `Route::getRoutes()`.

**Target Platform**: Linux server: JSON API, queue worker, scheduler.

**Project Type**: Web service (backend only; the frontend is `ecosystem-frontend`).

**Performance Goals**:
- `AiUsageSummary`: two grouped aggregates per request on the new
  `(hotel_group_id, occurred_at, capability)` index. Under 300 ms at 1M usage rows per
  account per month.
- Insight citation availability: at most 3 queries per page (one per source type).
- Recorder overhead: none added. The capability is read from in-memory `AiRun` state.

**Constraints**:
- Provider cost, prices, margin, contract value, model and provider never appear in a
  hotel-facing response, tool output, message or notification (FR-012).
- Meter codes are permanent, and `ai_usage_logs` stays append-only (the backfill is a
  schema migration).
- Insights jobs keep `TenantContext::runForHotel`. Knowledge search keeps its explicit
  `hotel_id` filters.

**Scale/Scope**:
- 3 migrations; 3 enums touched (+1 case each on `AiInsightCategories` and `MeterFeature`,
  methods on `AiAgentName`, `MeterFeature`, and the exception).
- 1 new service (`AiUsageSummary`) and 1 new tool (`InsightKnowledgeSearchTool`).
- About 9 classes touched, no new endpoints and no new permissions.
- 5 new test files.
- Docs: 6 updated plus latest-changes.

## Constitution Check

*Gate before Phase 0 and re-checked after Phase 1. Constitution v2.1.0.*

| Principle | How this plan complies | Status |
| --- | --- | --- |
| I. PMS is the system of record | Knowledge citations are stored separately from evidence and never raise the evidence level. Operational facts still come from PMS tools (FR-005) | ✅ |
| II. Preserve and evolve | Reuses `UsageReport`, `MeteringService`, `AiCostRecorder`, `AiRun`, `AiCostController::by()` and `KnowledgeSearchTool` (decorated, not forked). All changes are additive; no endpoint is removed | ✅ |
| III. Tenant isolation | Usage is keyed by the token's account. Citations come only from the hotel-filtered search registry. There are isolation tests for both. The insights job stays in `runForHotel` | ✅ |
| IV. Permission-based authorization | No new permissions. Usage stays admin/group-role (rule 4, admin-only commercial data), and the cost report stays on the `routes/admin.php` super-admin stack. Insights keep `ai_insights.view`/`.generate` | ✅ |
| V. AI acts through tools | The insights agent reads knowledge through a `ReadsData` tool. No AI write paths are added | ✅ |
| VI. Auditability and observability | Every provider call now carries a capability. The coverage test makes unattributed spend a test failure. Dropped citations are logged | ✅ |
| VII. Integrity and idempotency | The memory and insights meter calls are keyed. The backfill is a single bulk statement in a migration | ✅ |
| VIII. Tested at the domain boundary | Endpoint sweeps, tool sweeps, job-level coverage tests and isolation datasets | ✅ |
| IX. Spec-driven | Spec → clarify (2 answers) → this plan | ✅ |
| AI economics (domain section) | Hotel admins see usage (requests, tokens, features). Super admins see cost, model, capability, account and margin. Cost is never exposed to hotel admins, and this is enforced by tests | ✅ |

**Post-design re-check**: passes. Nothing beyond the phase text was added, except fixing
the two leaks found during research (R4). Those are in scope under FR-012.

## Project Structure

### Documentation (this feature)

```text
specs/012-insights-ai-economics/
├── plan.md                    # This file
├── research.md                # R1–R13
├── data-model.md              # migrations, citation snapshot, enums, projections
├── quickstart.md              # validation guide
├── frontend-changes.md        # ecosystem-frontend slice
├── contracts/
│   ├── usage-api.md           # /api/usage, /api/admin/usage, GetReportTool usage
│   ├── ai-cost-api.md         # /api/admin/ai-cost by_capability
│   ├── insights-api.md        # citations, knowledge category, insights tools
│   └── cost-visibility.md     # leak fixes + CostVisibilityTest
├── checklists/requirements.md
└── tasks.md                   # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── AiAgentName.php            # + meterFeatures(), label(), reportable()
│   ├── AiInsightCategories.php    # + KNOWLEDGE
│   └── MeterFeature.php           # + GUEST_MEMORY_EXTRACTIONS, measuredSince(); unit/category/label arms
├── Exceptions/
│   └── AiSpendCeilingExceededException.php   # + publicMessage()
├── Support/Ai/
│   └── AiRun.php                  # + agent() accessor
├── Services/
│   ├── AiCost/AiCostRecorder.php  # stamp capability
│   └── Metering/
│       ├── AiUsageSummary.php     # NEW: cost-free requests/tokens projection
│       └── UsageReport.php        # ai block; measured_since/note per feature
├── Ai/
│   ├── Agents/InsightsAgent.php   # + InsightKnowledgeSearchTool; schema citations[]; instructions
│   ├── Agents/AdminAdvisorAgent.php   # cost-question instruction
│   └── Tools/
│       ├── KnowledgeSearchTool.php        # + searchWithSources() (public output unchanged)
│       ├── InsightKnowledgeSearchTool.php # NEW: refs + registry + failure fallback
│       └── GetReportTool.php              # usage report includes ai block
├── Jobs/
│   ├── CreateAiInsightsJob.php    # map refs → citations; knowledge category; meter idempotency key
│   ├── ExtractGuestMemoriesJob.php    # meter GUEST_MEMORY_EXTRACTIONS on EXTRACTED
│   └── IndexKnowledgeDocumentJob.php  # store publicMessage() on ceiling stop
├── Http/
│   ├── Controllers/AccountUsageController.php    # ai block (via UsageReport); docblock
│   ├── Controllers/AiInsightsController.php      # batch citation availability
│   ├── Controllers/AiAdvisorController.php       # catch ceiling → 503
│   ├── Controllers/Admin/UsageController.php     # ai block per account
│   ├── Controllers/Admin/AiCostController.php    # by_capability
│   └── Resources/AiInsightsResource.php          # citations (+available)
└── Models/
    ├── AiInsights.php             # citations fillable/cast; eventLoggedAttributes unchanged
    └── AiUsageLog.php             # capability fillable/cast

database/migrations/
├── 2026_10_11_000001_add_citations_to_ai_insights_table.php
├── 2026_10_11_000002_add_capability_to_ai_usage_logs_table.php   # + backfill
└── 2026_10_11_000003_redact_ceiling_errors_on_knowledge_documents.php

tests/Feature/
├── CostVisibilityTest.php          # NEW (US1, SC-001)
├── AccountUsageAiBlockTest.php     # NEW (US1, FR-010/011, measured_since)
├── AiCapabilityCoverageTest.php    # NEW (US2, SC-002/003)
├── InsightCitationsTest.php        # NEW (US3, SC-005)
├── AiCostCapabilityTest.php        # NEW (US4)
├── UsageMeteringTest.php           # + guest_memory_extractions idempotency
└── TenantIsolationTest.php         # + citations and ai usage cases
```

**Structure Decision**: this is the existing Laravel monolith layout. There is no new
directory: the new service sits in `app/Services/Metering`, next to `UsageReport`, and the
new tool in `app/Ai/Tools`.

### Documentation to update

- `docs/ai-insights-api-documentation.md`: citations, the `knowledge` category, and the
  evidence rule. Also fix the stale "role must be admin" table, which is now permission
  based.
- `docs/usage-metering-api-documentation.md`: the `ai` block, `measured_since`, the new
  feature, and the "never touches ai_usage_logs" paragraph replaced by the `AiUsageSummary`
  rule.
- `docs/ai-cost-attribution-api-documentation.md`: `capability` and `by_capability`.
- `docs/ai-advisor-chat-api-documentation.md`: the `503` response.
- `docs/knowledge-document-api-documentation.md` and
  `docs/global-knowledge-admin-api-documentation.md`: the neutral ceiling `error`.
- `docs/latest-changes-2026-10-11.md`: there is no breaking change. It lists the additive
  fields and the new 503 for the frontend.
- No permission changes, so `docs/staff-roles-api-documentation.md` is untouched.

## Complexity Tracking

No constitution violations; nothing to justify.
