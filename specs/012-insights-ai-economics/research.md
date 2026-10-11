# Research: Insights Citations and AI Economics Verification

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md) · **Date**: 2026-10-11

Each entry records a decision, why it was taken, and what else was considered. Code
references are to `main` at `9d5b26b`.

---

## R1 — What "AI capability" is, and where it is stored

**Decision**: The capability is the **agent of the AI run** a provider call belongs to
(`AiRun` agent, an `AiAgentName`). `AiCostRecorder::record()` stamps it on a new
`ai_usage_logs.capability` column, read from a new `AiRun::agent()` accessor. A call made
outside any run is stamped `unattributed`. A one-off migration backfills existing rows:
first from the run row in `ai_activities` (joined by `ai_run_id`), then from the agent class
name already stored in `ai_usage_logs.agent`, otherwise `unattributed`.

**Rationale**:
- The run's agent is already the unit of AI work everywhere else (activity list, audit).
- Nested calls go to the capability that caused them: the turn classifier and the knowledge
  query embedding count under the Concierge turn they belong to (spec FR-018). That makes
  "which capability drives the bill" answerable.
- `ai_usage_logs.agent` holds the *class* that made the call (`TurnSignalAgent`,
  `Embeddings`), which answers a different question. A stored column keeps reports to a
  simple `GROUP BY` and lets the coverage test assert on it directly.

**Alternatives considered**:
- Map `ai_usage_logs.agent` class names to capabilities at query time: it splits Concierge
  turns into several capabilities, and labels `Embeddings`/`Reranking` with no owner.
- Join `ai_activities` at query time: it costs a join on every report, and there are no run
  rows before 2026-10-10.

---

## R2 — Hotel-facing AI usage without cost

**Decision**: Add a new `App\Services\Metering\AiUsageSummary`. It is the **only**
hotel-facing reader of `ai_usage_logs`, and it selects an explicit column list:
- `capability`, `hotel_id`;
- `COUNT(*)` as requests;
- `SUM(input_tokens + cached_tokens)` as input tokens;
- `SUM(output_tokens)` as output tokens.

It never reads `cost_usd`, `cost_is_estimated`, `model`, `provider`, `operation` or
`attempt`.

`UsageReport::describe()` gains an `ai` block:
- totals;
- `by_capability`, listing every capability except `unattributed`, with zeros where there
  was no activity;
- `by_hotel`.

All three consumers get it from the same method:
- `GET /api/usage`;
- `GET /api/admin/usage`;
- the Admin AI `GetReportTool` `usage` report.

**Rationale**:
- One projection means one place to audit. The explicit column list makes the guarantee
  structural rather than relying on a later `unset()`.
- Cached tokens are added to the input figure because laravel/ai subtracts them from
  `input_tokens`. Showing them separately would only matter for pricing (spec Assumption).
- `AccountUsageController`'s comment that it "does not touch that table" is updated to:
  "touches it only through `AiUsageSummary`, which never reads cost".

**Alternatives considered**:
- Count tokens in `meter_events` instead: that would duplicate what `ai_usage_logs`
  measures, and the two could drift.
- Use the per-run usage on `ai_activities`: it has no rows for calls outside a run, and none
  before 2026-10-10.

---

## R3 — How the "never show cost" rule is enforced (FR-012, SC-001)

**Decision**: There are three automated layers in `tests/Feature/CostVisibilityTest.php`.

1. **Static allowlist (file scan, comments stripped)**. Only allowlisted classes may
   reference `AiUsageLog`, `AiModelPrice`, `cost_usd`, `price_per_million`,
   `contract_value` or `gross_margin`.
   - The authoritative list is in tasks.md T010(a). Besides the new `AiUsageSummary`, it
     covers every existing reference found on `main`:
     - the AI cost services and jobs, including the log-only `FlagAiCostOverrunsJob`;
     - `AiRun`, `AiCostContext` and `RecordAiUsage`;
     - `EventLogger` (audit exclusion);
     - the `Admin\` controllers;
     - `AiActivityController` and `AiActivityResource` (super-admin-only cost);
     - the models;
     - the ceiling exception.
   - Comments are stripped first, so prose docblocks (such as `AccountUsageController`'s)
     don't trip it.

   A new reference anywhere else fails the test with the file name.
2. **Response sweep**:
   - Seed `ai_usage_logs` and `ai_model_prices` with sentinel values: model
     `sentinel-model-x9`, provider `sentinel-provider`, cost `987.654321`, and a contract
     value `4242.42`.
   - Call every `GET` route registered in `routes/api.php` as a hotel admin and as an
     employee holding every permission.
   - Assert that no response body contains a sentinel value, or any key on the denylist in
     contracts/usage-api.md rule 4.
   - The plain key `price` is deliberately not denied: activity and booking prices are
     hotel data (`ActivityResource`, `GetActivitiesTool`, …). Model prices are caught by
     the sentinel values instead.
   - Routes needing a path parameter use the seeded record. The test lists any route it
     cannot resolve, so silent skips are impossible.
3. **Admin AI tool sweep**: call every `ReadsData` tool in the Admin and Insights toolsets
   with the same seed, and assert the same about the returned text.

**Rationale**:
- Layer 1 catches the leak before it is written.
- Layers 2 and 3 catch leaks through paths nobody thought of, such as resources, error
  messages and tool output.
- Sentinels make value leaks detectable even under an innocent key name.

**Alternatives considered**:
- Per-endpoint assertions only: new endpoints would silently escape the check.
- A response middleware that strips cost keys: it hides the bug instead of failing on it,
  and it cannot see values hidden in text.

---

## R4 — Leaks found during research, and their fixes

1. **Knowledge document `error`**:
   - **Leak**: `IndexKnowledgeDocumentJob` stores `$e->getMessage()` of
     `AiSpendCeilingExceededException` in `knowledge_documents.error`.
     `KnowledgeDocumentResource` returns `error` to hotel admins. The message contains the
     dollar amount spent today and the ceiling.
   - **Fix**: the exception gains `publicMessage()`, with neutral text: "The daily AI usage
     limit was reached; try again tomorrow." The job stores that text. The full message
     still goes to the log via `report()`.
2. **Admin AI web chat**:
   - **Leak**: `AiAdvisorController` doesn't catch the ceiling exception. With
     `APP_DEBUG=true` the JSON error body carries the message.
   - **Fix**: catch it and return `apiResponse('The AI assistant is temporarily
     unavailable. Try again later.', 503)`. This mirrors the WhatsApp path, which already
     returns `UNAVAILABLE_REPLY`.
3. **Exception message itself**: kept as-is for logs. The sweep in R3 seeds a ceiling breach
   and asserts that neither response shows an amount.

---

## R5 — Admin AI and cost questions (FR-013)

**Decision**: Two changes, no new tool.
- **Advisor instruction**: the existing "Never show AI costs…" line gains: "If asked what AI
  costs, say that cost is not available in this workspace and offer the usage report
  instead. Never estimate cost from token counts."
- **Usage report**: `GetReportTool`'s `usage` report includes the new `ai` block (R2), so
  usage questions are answered from measured figures.

**Rationale**: The tool layer already makes cost unreachable, because no tool returns it.
The instruction only stops the model from inventing a cost.

**Alternatives considered**: A refusal tool for cost questions adds a moving part for no
gain.

---

## R6 — Super-admin per-capability breakdown (FR-015, FR-016)

**Decision**:
- `AiCostController::describe()` gains `by_capability`. For each capability: `calls`,
  `input_tokens`, `output_tokens`, `ai_cost_usd`, `ai_cost_eur` and `cost_is_estimated`.
  The flag is true when any row in the group is estimated, which covers unpriced models
  (spec US4.4).
- Capabilities are listed by their `AiAgentName` value, the same keys as R2.
- The existing breakdowns are unchanged: `by_trigger`, `by_model`, `by_hotel` and margin.

**Rationale**: It reuses the existing `by()` grouping helper. The same keys in the usage
and cost reports let the frontend put the two side by side.

---

## R7 — The capability coverage check (FR-018, FR-022, FR-023)

**Decision**:
- `AiAgentName` gains `meterFeatures(): array`, an exhaustive `match` listing the usage
  features each capability is counted by:

  | Capability | Counted by |
  | --- | --- |
  | `concierge` | `ai_messages`, plus `recommendations_generated` for inline pitches |
  | `admin_advisor` | `ai_messages` |
  | `recommendation` | `recommendations_generated` |
  | `insights` | `ai_insights_generated` |
  | `turn_signal` | `ai_messages` (it runs inside the Concierge turn) |
  | `document_vision` | `knowledge_pages_read`, `embeddings_generated` |
  | `guest_memory` | `guest_memory_extractions` (new) |
  | `knowledge` | `embeddings_generated` |
  | `unattributed` | none; it is the failure marker |

- `tests/Feature/AiCapabilityCoverageTest.php` does three things:
  1. For every case except `unattributed`, asserts that `meterFeatures()` is non-empty and
     names only non-seat features. A new case that is missing from the `match` throws
     `UnhandledMatchError`, which fails the test with the case name.
  2. Drives each capability once with agent fakes: a Concierge turn with a service request,
     an advisor turn (web and WhatsApp), insights, scheduled and inline recommendations,
     knowledge sync, a vision document and memory extraction. It asserts that each produced
     at least one `ai_usage_logs` row with the expected `capability` and a
     non-`unattributed` `trigger_kind`, plus the mapped meter event.
  3. Asserts that the suite-wide count of `unattributed` capability and trigger rows is
     zero after step 2.
- Proactive messages are rendered from templates (`ProactiveMessageRenderer`), with no AI
  call. They stay a value signal (`proactive_messages_sent`) and need no capability.

**Rationale**: A missing `match` arm fails at compile-like speed in the test run. The
behavioral half proves the declared mapping is actually recorded.

---

## R8 — Guest memory extraction metering (FR-019, FR-021)

**Decision**:
- **New feature**: `MeterFeature::GUEST_MEMORY_EXTRACTIONS = 'guest_memory_extractions'`,
  unit `extractions`, category `cost_driver`, label "Guest memory extractions".
- **Where it is recorded**: in `ExtractGuestMemoriesJob::extract()` after the AI call
  succeeds (status `EXTRACTED`, including zero memories written), through
  `MeteringService::safely()`.
- **Idempotency key**: `guest-memory-extraction:{extraction id}`. The extraction row is
  unique per guest and reservation, so a retry round or a re-run never counts twice.
- **Not counted**: `SKIPPED_NO_MESSAGES` and the switched-off path, because no AI call was
  made. Spend-ceiling stops and failures are not counted either: no completed operation.

**Rationale**: The meter counts completed work, and the cost table already records every
paid attempt (spec US2.5).

---

## R9 — "Measured since" for features added later (Clarification Q2)

**Decision**:
- `MeterFeature` gains `measuredSince(): ?CarbonImmutable`. It returns null for every
  feature measured from the start, and the release date for `guest_memory_extractions`.
  The date is set in the enum in the same change that adds the feature.
- `UsageReport::everyFeature()` adds `measured_since` (`YYYY-MM-DD` or null) to every
  feature entry. When the requested `from` is earlier, it also adds a `note`: "Measured
  from YYYY-MM-DD; earlier activity was not counted."
- There is no backfill.

**Rationale**: The rule is stated in code next to the feature, so the next added feature
follows the same pattern. A date derived from the first recorded event would move if
nobody used the feature in the first weeks.

---

## R10 — Insight citations: how the AI names sources it actually saw (FR-002–FR-004)

**Decision**:
- **Tool**: `InsightsAgent` gets a new `InsightKnowledgeSearchTool($hotel)`. It is a
  `ReadsData` decorator over `KnowledgeSearchTool` with the `STAFF` audience.
  - It calls a new `KnowledgeSearchTool::searchWithSources()`. This returns the same
    results as `search()` plus the source model class and key. The public tool output is
    unchanged, so the Concierge and Admin AI see no difference.
  - It gives each distinct source a run-local reference (`K1`, `K2`, …), returns results
    with `ref` instead of an id, and keeps a registry of ref → citation snapshot (source
    type, source id, title, location, scope, last updated).
- **Structured output**: each insight gains `citations: string[]` of refs, and the
  instructions say to cite only refs the tool returned.
- **Saving**: `CreateAiInsightsJob::saveInsights()` maps refs through the registry. Refs
  that are not in the registry are dropped, along with duplicates, and the job logs a
  warning with the count. The registry only ever holds this hotel's sources or global ones,
  because it comes from the hotel-filtered search, so a foreign source cannot be cited.

**Rationale**:
- The model never handles real ids, so it cannot invent one that happens to exist.
- "Returned during this run" (FR-004) holds by construction. "Still exists" is checked at
  save time with one key lookup per source type, so a source deleted mid-run is not cited.
- This matches how `source_id` is already verified for evidence.

**Alternatives considered**:
- Let the model emit source ids and verify them against the database: an existing but
  unseen source would pass.
- Cite whatever the search returned, without the model choosing: that over-cites sources
  the insight didn't use.

---

## R11 — Citation storage and the evidence rule (FR-003, FR-005–FR-007)

**Decision**:
- **Storage**: a new JSON column `ai_insights.citations` holding an array of snapshot
  objects. It is not null and defaults to `[]`. Existing rows read as `[]` and are not
  backfilled.
- **Knowledge-only insights**: a new `AiInsightCategories::KNOWLEDGE = 'knowledge'`.
  - It has no insightable record, so `insightable_*` is null and the evidence level is L3.
    The current `general` → hotel-id path gives L2, which is right for "about the hotel as
    a whole" but would let a knowledge-only claim pass as a strong inference.
  - The instructions say: use `knowledge` when the insight rests only on knowledge sources.
- **Mixed insights**: an insight in another category keeps its existing evidence rule, and
  its citations are listed separately (spec US3.2).
- **Display**: `AiInsightsController::index()` loads availability for the page in one query
  per source type (`withTrashed`, liveness as in `KnowledgeSearchTool::isLive`).
  `AiInsightsResource` adds `available: bool` to each citation, and the stored title is
  always shown.

**Rationale**:
- There are at most 5 insights × a few citations, never queried by citation, and they are
  immutable snapshots, so a JSON column is enough (as for `evidence_sources`).
- Batch availability avoids N+1 (the same pattern as `AiActivityController::attachRunCosts`).

**Alternatives considered**:
- An `ai_insight_citations` table: it adds a model and a join for no query need.
- Reusing `evidence_sources` for citations: it mixes "about" with "based on", which
  FR-005 forbids.

---

## R12 — Knowledge search failures and guest text in insights (FR-008)

**Decision**:
- `InsightKnowledgeSearchTool::handle()` catches `Throwable`, calls `report()`, and returns
  "Knowledge search is unavailable right now; continue from the operational data." The
  insights run continues with no citations.
- The existing no-verbatim instruction for guest messages is kept unchanged.

**Rationale**: Knowledge improves insights, but they don't depend on it.

---

## R13 — Insights meter retry safety (FR-021)

**Decision**: The `AI_INSIGHTS_GENERATED` meter call gains the idempotency key
`ai-insights:{job uuid}` (`$this->job?->uuid()`, falling back to a UUID generated in the
constructor for sync dispatch). A retried job counts once.

**Rationale**: It closes the one retry gap among the metered AI paths:
- **Already keyed**: inbound WhatsApp (`whatsapp-inbound:*`), proactive messages
  (`proactive:*`), bookings and delivery.
- **Retry-safe without a key**:
  - Recommendation generation records a before/after delta.
  - Knowledge sync and document indexing meter only as the last step of a completed swap.
    A retry after that point re-does and re-pays the work, so counting it again is correct.
- **Unsafe today**: insights record `count(insights)` after saving, so a retry after the
  meter call would count again.
