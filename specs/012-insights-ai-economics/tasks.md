---

description: "Task list for Insights Citations and AI Economics Verification"
---

# Tasks: Insights Citations and AI Economics Verification

**Input**: Design documents from `specs/012-insights-ai-economics/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: Required. The spec makes the automated checks part of the feature (FR-012,
FR-022, FR-023), and CLAUDE.md requires feature tests plus tenant-isolation tests. Tests are
Pest 4 feature tests on real Postgres:
- `uses(RefreshDatabase::class)` in each file;
- `putenv('API_KEY=test-api-key')` + `config(['app.api_key' => 'test-api-key'])` in
  `beforeEach`;
- the `X-API-KEY` header on requests;
- models built with `Model::create([...])`; only `UserFactory` exists.

**Organization**: Tasks are grouped by user story so each story can be implemented and
tested on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup

**Purpose**: Confirm the baseline before changing shared AI and metering code.

- [X] T001 Start Postgres (`docker compose -f .postgres/compose.yaml up -d`) and run `php artisan test` on `main`. Record any pre-existing failures in `specs/012-insights-ai-economics/quickstart.md` under a "Baseline" note, so later failures can be told apart.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Capability attribution on every provider call (research R1) and the shared
enum methods. US1, US2 and US4 all read `ai_usage_logs.capability`.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Create migration `database/migrations/2026_10_11_000002_add_capability_to_ai_usage_logs_table.php`:
  - **Column**: `capability` `string(32)`, "not null, default `'unattributed'`".
  - **Index**: `(hotel_group_id, occurred_at, capability)`.
  - **Backfill** in the same `up()`, as bulk `UPDATE`s, in order:
    1. Set `capability = ai_activities.agent` where `ai_usage_logs.ai_run_id = ai_activities.ai_run_id AND ai_activities.category = 'ai_run'`.
    2. For remaining `unattributed` rows, map `agent` class basenames:
       - `GuestConciergeAgent`→`concierge`
       - `AdminAdvisorAgent`→`admin_advisor`
       - `RecommendationAgent`→`recommendation`
       - `InsightsAgent`→`insights`
       - `TurnSignalAgent`→`concierge`
       - `DocumentVisionAgent`→`document_vision`
       - `GuestMemoryAgent`→`guest_memory`
    3. Leave everything else `unattributed`.
  - **`down()`**: drops the index and the column.
- [X] T003 [P] Add `capability` to `$fillable` and cast it to `App\Enums\AiAgentName` in `app/Models/AiUsageLog.php`.
- [X] T004 [P] Add a `public static function agent(): ?AiAgentName` accessor (returns `self::$current['agent'] ?? null`) in `app/Support/Ai/AiRun.php`.
- [X] T005 Stamp `'capability' => (AiRun::agent() ?? AiAgentName::UNATTRIBUTED)->value` in `AiCostRecorder::record()` in `app/Services/AiCost/AiCostRecorder.php` (depends on T003, T004).
- [X] T006 [P] Add `case GUEST_MEMORY_EXTRACTIONS = 'guest_memory_extractions';` under "Cost drivers" in `app/Enums/MeterFeature.php`:
  - unit `extractions`, category `cost_driver`, label `Guest memory extractions`;
  - add the arms in `unit()`, `category()` and `label()`;
  - do not rename any existing code.
- [X] T007 Add three methods to `app/Enums/AiAgentName.php` (depends on T006):
  - **`label()`**:
    - Concierge
    - Admin AI
    - Recommendations
    - Insights
    - Turn classification
    - Document reading
    - Guest memories
    - Knowledge indexing
    - Unattributed
  - **`reportable(): array`**: every case except `UNATTRIBUTED` and `TURN_SIGNAL`. Turn classification always runs inside a Concierge turn, so its calls are stamped `concierge` (R1) and a separate row would always read 0. Document this on the method.
  - **`meterFeatures(): array`**: an exhaustive `match` with no `default`, per research R7:
    - `CONCIERGE`→[`AI_MESSAGES`, `RECOMMENDATIONS_GENERATED`]
    - `ADMIN_ADVISOR`→[`AI_MESSAGES`]
    - `RECOMMENDATION`→[`RECOMMENDATIONS_GENERATED`]
    - `INSIGHTS`→[`AI_INSIGHTS_GENERATED`]
    - `TURN_SIGNAL`→[`AI_MESSAGES`]
    - `DOCUMENT_VISION`→[`KNOWLEDGE_PAGES_READ`, `EMBEDDINGS_GENERATED`]
    - `GUEST_MEMORY`→[`GUEST_MEMORY_EXTRACTIONS`]
    - `KNOWLEDGE`→[`EMBEDDINGS_GENERATED`]
    - `UNATTRIBUTED`→[]
- [X] T008 Add a test to `tests/Feature/AiCostAttributionTest.php`:
  - a call made inside `AiCostContext::for(agent: AiAgentName::INSIGHTS, …)` stores `capability = insights`;
  - a nested call (an embedding inside a Concierge context) stores `concierge`;
  - a call outside any context stores `unattributed`;
  - the T002 backfill maps a pre-seeded row from its run row and from its class basename.

**Checkpoint**: every new `ai_usage_logs` row carries a capability, and stories can start.

---

## Phase 3: User Story 1: Hotel admin sees AI usage without provider cost (Priority: P1) 🎯 MVP

**Goal**:
- Hotel admins see AI requests and tokens per capability and per hotel.
- No hotel-facing surface exposes cost, price, margin, contract value, model or provider.
- The two leaks found in research R4 are closed.

**Independent Test**: `php artisan test --filter="CostVisibilityTest|AccountUsageAiBlockTest"`.
- Seeded sentinel cost, model and provider values never appear in any hotel-facing `GET`
  response or Admin AI read-tool output.
- `body.ai` sums are exact.
- An employee gets `403` on `/api/usage`.

### Tests for User Story 1

- [X] T009 [P] [US1] Create `tests/Feature/AccountUsageAiBlockTest.php` per [contracts/usage-api.md](contracts/usage-api.md):
  - Seed `ai_usage_logs` rows across two hotels of one account and several capabilities, plus rows for a second account.
  - As an admin, `GET /api/usage` and assert:
    - `body.ai.requests`, `input_tokens` (= `input_tokens + cached_tokens`), `output_tokens` and `total_tokens` are correct;
    - `by_capability` lists every `AiAgentName::reportable()` key, zero-filled;
    - `unattributed` appears only when non-zero;
    - `by_capability` and `by_hotel` each sum exactly to the totals;
    - the second account's rows are excluded.
  - Assert an employee gets `403`.
  - Assert `GET /api/admin/usage` as a super admin includes the same `ai` block per account, and that an admin and an employee holding every permission get `403` there (FR-017).
  - Assert `GET /api/usage` for account A never includes account B's AI usage (FR-025; moved here from T030 because it depends only on US1).
- [X] T010 [P] [US1] Create `tests/Feature/CostVisibilityTest.php` per [contracts/cost-visibility.md](contracts/cost-visibility.md).
  - **Shared seed helper**: `ai_usage_logs` rows with model `sentinel-model-x9`, provider `sentinel-provider` and `cost_usd` `987.654321`; an `ai_model_prices` row for that model; a hotel-group `contract_value_monthly` of `4242.42`; a breached daily spend ceiling.
  - **(a) Static check**: a file-scan test over `app/`.
    - Strip PHP comments (`token_get_all`, dropping `T_COMMENT`/`T_DOC_COMMENT`) before matching.
    - Only the allowlist below may reference `AiUsageLog`, `AiModelPrice`, `cost_usd`, `price_per_million`, `contract_value` or `gross_margin`. This list is the single source; research R3 points here.
      - `app/Services/AiCost/*`
      - `app/Jobs/AiCost/*` (`FlagAiCostOverrunsJob`, log-only alerts)
      - `app/Support/Ai/AiRun.php`
      - `app/Support/Ai/AiCostContext.php`
      - `app/Support/Audit/EventLogger.php` (excludes `AiUsageLog` from auditing)
      - `app/Listeners/RecordAiUsage.php`
      - `app/Services/Metering/AiUsageSummary.php`
      - `app/Http/Controllers/Admin/*`
      - `app/Http/Controllers/AiActivityController.php` and `app/Http/Resources/AiActivityResource.php` (super-admin-only `cost_usd`)
      - `app/Models/*`
      - `app/Exceptions/AiSpendCeilingExceededException.php`
    - A reference anywhere else fails with the file name.
  - **(b) Route sweep**:
    - Iterate `Route::getRoutes()` for every `GET` route under `api/` that is not under `api/admin/`.
    - Resolve path parameters from seeded records, and fail listing any route that cannot be resolved.
    - Call each route as a hotel admin and as an employee whose staff role holds every `Permission` case.
    - **Values**: no body contains any sentinel value: `sentinel-model-x9`, `sentinel-provider`, `987.65`, `4242.42`.
    - **Keys**: no body contains the JSON keys `cost`, `cost_usd`, `cost_eur`, `cost_per_call_eur`, `price_per_million`, `input_price_per_million`, `output_price_per_million`, `cached_input_price_per_million`, `margin`, `gross_margin`, `gross_margin_pct`, `contract_value`, `contract_value_monthly`, `model` or `provider`.
    - Do **not** deny the key `price`: activity and booking prices are legitimate hotel data (`ActivityResource`, `GetActivitiesTool`, `GetOwnBookingsTool`, `GetRecommendationsTool`). Model prices are caught by the sentinel values.
  - **(c) Tool sweep**: call `handle()` on every `ReadsData` tool from `AdminToolset` and `InsightsAgent::tools()` with valid arguments, and apply the same value and key assertions to the output.
  - **(d) Messages and notifications** (FR-012, US1.7, SC-001), with the ceiling breached:
    - The guest WhatsApp reply from `ProcessInboundWhatsAppMessageJob` is `UNAVAILABLE_REPLY`, with no sentinel or `$` amount.
    - The rendered `toArray()`/`toMail()`/WhatsApp payload of every class in `app/Notifications/` (built with seeded models) contains no sentinel value.
    - *As built*: notifications are covered by check (a) instead of being rendered one by one. No notification class is on the allowlist, so none can read cost data at all, and the test asserts that `app/Notifications/` stays off it. The knowledge-document ceiling `error` is asserted in `KnowledgeDocumentVisionTest`, which drives a real indexing run into the ceiling.
    - No export endpoint exists today. Record that in a comment, so a future export is added to this test.
  - **Leak-fix and counter-checks**:
    - `POST /api/ai-advisor/chat` with the ceiling breached returns `503` and the neutral message, with no amount.
    - A knowledge document stopped by the ceiling has the neutral `error`.
    - As a super admin, `/api/admin/ai-cost` shows the sentinel model and cost, and `/api/ai-activity` shows `cost_usd`.

### Implementation for User Story 1

- [X] T011 [US1] Create `app/Services/Metering/AiUsageSummary.php` (research R2, data-model "Projections"):
  - **Method**: `forAccount(HotelGroup $account, Carbon $from, Carbon $to): array`.
  - **Query**: reads `ai_usage_logs` with an explicit select list only:
    - `capability`, `hotel_id`
    - `COUNT(*) AS requests`
    - `SUM(input_tokens + cached_tokens) AS input_tokens`
    - `SUM(output_tokens) AS output_tokens`

    It must never select `cost_usd`, `cost_is_estimated`, `model`, `provider`, `operation` or `attempt`.
  - **Returns**:
    - `requests`, `input_tokens`, `output_tokens`, `total_tokens`;
    - `by_capability`: every `AiAgentName::reportable()` key zero-filled, each with `label` from `AiAgentName::label()`; `unattributed` only when non-zero;
    - `by_hotel`: every hotel of the account zero-filled, with the hotel name;
    - `basis`: the text from contracts/usage-api.md.
- [X] T012 [US1] Extend `app/Services/Metering/UsageReport.php` so `describe()` adds the `ai` key from `AiUsageSummary::forAccount()`. Pass `$from`/`$to` through, and update the class docblock: "touches ai_usage_logs only through AiUsageSummary, which never reads cost" (depends on T011).
- [X] T013 [US1] Pass the date range into `describe()` and update the class docblock rule 2 to the `AiUsageSummary` wording in `app/Http/Controllers/AccountUsageController.php` (depends on T012).
- [X] T014 [P] [US1] Pass the date range into `describe()` so each `accounts[]` entry gets the `ai` block in `app/Http/Controllers/Admin/UsageController.php` (depends on T012).
- [X] T015 [P] [US1] Include `'ai' => $described['ai']` in the `usage()` report output in `app/Ai/Tools/GetReportTool.php`, keeping "counts only, never cost" (depends on T012).
- [X] T016 [P] [US1] Extend the "Never show AI costs…" instruction line in `app/Ai/Agents/AdminAdvisorAgent.php` with: "If asked what AI costs, say cost is not available in this workspace and offer the usage report instead. Never estimate cost from token counts or any other figure."
- [X] T017 [P] [US1] Add `public function publicMessage(): string` to `app/Exceptions/AiSpendCeilingExceededException.php`. It returns `'The daily AI usage limit was reached; try again tomorrow.'`. Keep the constructor message, which is for logs.
- [X] T018 [US1] In the `catch (AiSpendCeilingExceededException $e)` branch of `app/Jobs/IndexKnowledgeDocumentJob.php`, call `report($e)` and pass `$e->publicMessage()` (not `$e->getMessage()`) to `failPermanently()` (depends on T017).
- [X] T019 [US1] Create data migration `database/migrations/2026_10_11_000003_redact_ceiling_errors_on_knowledge_documents.php`. It sets `error = 'The daily AI usage limit was reached; try again tomorrow.'` where `failure_code = 'usage_limit_reached'`, including soft-deleted rows. It is idempotent, and `down()` is a no-op.
- [X] T020 [US1] Catch `AiSpendCeilingExceededException` in `app/Http/Controllers/AiAdvisorController.php` around the `AiCostContext::for(...)` call. Return `apiResponse('The AI assistant is temporarily unavailable. Try again later.', 503)` and do not meter `AI_MESSAGES` on that path.
- [X] T021 [US1] Run `php artisan test --filter="CostVisibilityTest|AccountUsageAiBlockTest|UsageMeteringTest|KnowledgeDocument"` and fix failures. Update any existing assertion in `tests/Feature/UsageMeteringTest.php` that expects the old `/api/usage` shape so it allows the additive `ai` key.

**Checkpoint**: US1 is fully functional. Hotel admins see AI usage, and no cost reaches them.

---

## Phase 4: User Story 2: Every AI operation is metered and cost-attributed (Priority: P1)

**Goal**:
- Guest memory extraction is metered once per completed extraction.
- The insights meter is retry-safe.
- Later-added features report `measured_since`.
- A coverage check fails on any unmapped or unattributed capability.

**Independent Test**: `php artisan test --filter="AiCapabilityCoverageTest|UsageMeteringTest"`.
- Every reportable capability writes attributed `ai_usage_logs` rows and its mapped meter
  event.
- No row is `unattributed`.

### Tests for User Story 2

- [X] T022 [P] [US2] Create `tests/Feature/AiCapabilityCoverageTest.php` (research R7):
  - **Mapping check**: for each `AiAgentName::reportable()` case, assert `meterFeatures()` is non-empty and contains no seat (`! $f->isSeat()`).
  - **Behaviour check**: drive each capability once with agent fakes and `Embeddings::fake()`:
    - a Concierge WhatsApp turn creating a service request (`ProcessInboundWhatsAppMessageJob`);
    - an advisor web turn and a WhatsApp advisor turn;
    - `CreateAiInsightsJob`;
    - `GenerateActivityRecommendationsJob`;
    - an inline pitch generation;
    - `SyncKnowledgeChunksJob`;
    - `IndexKnowledgeDocumentJob` on an image document;
    - `ExtractGuestMemoriesJob`.

    For each one, assert at least one `ai_usage_logs` row with the expected `capability` and `trigger_kind != 'unattributed'`, plus a `meter_events` row for one of its `meterFeatures()`.
  - **Final check**: `AiUsageLog::where('capability','unattributed')->orWhere('trigger_kind','unattributed')->count() === 0`.
- [X] T023 [P] [US2] Add three tests to `tests/Feature/UsageMeteringTest.php` (*as built*: they went into `tests/Feature/GuestMemoryExtractionTest.php`, next to the extraction fixtures they use):
  - **Counted once**: an extraction reaching `EXTRACTED` (including zero memories written) records exactly one `guest_memory_extractions` event with idempotency key `guest-memory-extraction:{extraction id}`. Re-dispatching the job adds none.
  - **Not counted**: `SKIPPED_NO_MESSAGES`, the switched-off path, `SKIPPED_SPEND_CEILING` and `FAILED` record none.
  - **`measured_since`**: `/api/usage` reports `measured_since` for `guest_memory_extractions` and null for the others. A `note` appears only when `from` is earlier than that date.

### Implementation for User Story 2

- [X] T024 [US2] In `extract()` of `app/Jobs/ExtractGuestMemoriesJob.php`, after a successful `AiCostContext::for(...)` and before `finish(..., EXTRACTED, ...)`, record the usage event (FR-019, research R8):
  - call `app(MeteringService::class)->safely(fn ($m) => $m->recordForHotel(...))`;
  - arguments: `hotel: $hotel`, `feature: MeterFeature::GUEST_MEMORY_EXTRACTIONS`, `source: $guest`, `idempotencyKey: 'guest-memory-extraction:'.$extraction->id`, `metadata: ['memories_written' => $written]`, `actorKind: ActorKind::AI_AGENT`;
  - do not meter on skip, ceiling or failure paths.
- [X] T025 [P] [US2] Make the insights meter call retry-safe in `app/Jobs/CreateAiInsightsJob.php` (research R13):
  - add a `public string $meterKey` set in the constructor to `'ai-insights:'.Str::uuid7()`;
  - pass `idempotencyKey: $this->meterKey` to the `AI_INSIGHTS_GENERATED` `recordForHotel()` call.
- [X] T026 [US2] Add `public function measuredSince(): ?CarbonImmutable` to `app/Enums/MeterFeature.php` (research R9). It returns a fixed `CarbonImmutable::parse('YYYY-MM-DD')` for `GUEST_MEMORY_EXTRACTIONS` and null for every other case.
  - **Which date**: the date of the commit that adds `GUEST_MEMORY_EXTRACTIONS` (T006), as a literal, not computed at runtime.
  - **Comment**: "the day this feature started being measured; events before it were never recorded".
  - **Test**: T023 asserts against `MeterFeature::GUEST_MEMORY_EXTRACTIONS->measuredSince()`, not a hard-coded date.
- [X] T027 [US2] In `everyFeature()` of `app/Services/Metering/UsageReport.php`, add `measured_since` (`toDateString()` or null) to every feature entry. Add `note` = "Measured from YYYY-MM-DD; earlier activity was not counted." only when the report's `from` is earlier than `measured_since` (depends on T012, T026).
- [X] T028 [US2] Run `php artisan test --filter="AiCapabilityCoverageTest|UsageMeteringTest|GuestMemor"`. Fix any capability that writes `unattributed` rows by passing `agent:` to its `AiCostContext::for()` call site (search `AiCostContext::for` in `app/`).

**Checkpoint**: US1 and US2 both work. Usage and cost data are complete and attributed.

---

## Phase 5: User Story 3: Insights cite the knowledge sources they used (Priority: P2)

**Goal**:
- Insights can search hotel and global knowledge and store citation snapshots that trace to
  sources actually returned during the run.
- Knowledge-only insights are L3, and citations show availability.

**Independent Test**: `php artisan test --filter="InsightCitationsTest|TenantIsolationTest"`.
- A policy-grounded insight cites the policy.
- Unknown refs are dropped.
- There are no cross-hotel citations.

### Tests for User Story 3

- [X] T029 [P] [US3] Create `tests/Feature/InsightCitationsTest.php` per [contracts/insights-api.md](contracts/insights-api.md). Use `InsightsAgent::fake()` with structured output, plus `Embeddings::fake()` with seeded `knowledge_chunks`.
  - **(a)** A policy plus reservations: the insight citing `K1` stores a snapshot with `source_type: policy`, `scope: hotel`, title, location and `last_updated`, and its `evidence_level` comes from the reservation (L2).
  - **(b)** A ref not returned by the tool (`K9`) is dropped and a warning is logged.
  - **(b2)** A source returned by the tool but soft-deleted before the insights are saved is not cited.
  - **(c)** Duplicate refs collapse; more than 10 are truncated.
  - **(d)** `category: knowledge` stores null `insightable_*` and `L3`.
  - **(e)** An insight with no citations returns `citations: []`.
  - **(f)** After the cited policy is soft-deleted, `GET /api/ai-insights` returns the citation with `available: false` and its stored title. An unpublished article and an inactive document behave the same.
  - **(g)** When knowledge search throws, insights are still saved without citations and the job succeeds.
  - **(h)** `GET /api/ai-insights` runs at most one availability query per source type, regardless of page size.
- [X] T030 [P] [US3] Add cases to `tests/Feature/TenantIsolationTest.php` (*as built*: in `tests/Feature/InsightCitationsTest.php`, which owns the insights fixtures):
  - hotel A's insights never cite hotel B's knowledge sources;
  - global sources may be cited with `scope: general`.

### Implementation for User Story 3

- [X] T031 [US3] Create migration `database/migrations/2026_10_11_000001_add_citations_to_ai_insights_table.php` adding `citations` `jsonb` "not null, default `'[]'`". *As built*: `ai_insights.category` is a Postgres check constraint (from `$table->enum`), so this migration also recreates `ai_insights_category_check` to admit `knowledge`. Do not backfill; existing rows read as `[]`. `down()` drops the column.
- [X] T032 [P] [US3] Add `case KNOWLEDGE = 'knowledge';` to `app/Enums/AiInsightCategories.php`.
- [X] T033 [P] [US3] Add `citations` to `$fillable` and `'citations' => 'array'` to `$casts` in `app/Models/AiInsights.php`, and leave `eventLoggedAttributes()` unchanged (depends on T031).
- [X] T034 [US3] Add `public function searchWithSources(string $query): array` to `app/Ai/Tools/KnowledgeSearchTool.php`:
  - It returns the same results as `search()`, each with an extra `source_class` (model class) and `source_id` (key).
  - Refactor `search()` to call it and strip those two keys, so the public tool output stays byte-identical for the Concierge and Admin AI.
  - `KnowledgeSearchToolTest` and `ConciergeKnowledgeCitationTest` must stay green.
- [X] T035 [US3] Create `app/Ai/Tools/InsightKnowledgeSearchTool.php` (implements `ReadsData`, `Tool`; research R10, R12) (depends on T034):
  - **Constructor**: `Hotel $hotel`; wraps `new KnowledgeSearchTool($hotel, KnowledgeAudience::STAFF)`.
  - **`handle()`**:
    - calls `searchWithSources()`;
    - assigns each distinct `(source_class, source_id)` a run-local ref `K1`, `K2`, …, stable within the run;
    - returns JSON results with `ref`, `scope`, `source_type`, `title`, `location`, `last_updated` and `content`, and no ids;
    - stores ref → snapshot `{source_type, source_id, title, location, scope, last_updated}` in a public registry.
  - **`citation(string $ref): ?array`**: reads the registry.
  - **On `Throwable`**: `report($e)` and return "Knowledge search is unavailable right now; continue from the operational data."
  - **Description**: tells the model to cite results by `ref`.
- [X] T036 [US3] Update `app/Ai/Agents/InsightsAgent.php` (depends on T032, T035):
  - **Tool**: build one `InsightKnowledgeSearchTool($this->user->hotel)` in the constructor, expose it as a public `knowledgeTool` property, and add it to `tools()`.
  - **Schema**:
    - add `citations` (`array` of `string`, required, may be empty, described as "refs returned by the knowledge tool");
    - the `category` enum now includes `knowledge`.
  - **Instructions**:
    - cite only refs the knowledge tool returned;
    - prefer `hotel` scope when sources disagree;
    - use `knowledge` only when no hotel record grounds the insight, with `source_id` = the hotel id;
    - keep the no-verbatim guest-message rule.
- [X] T037 [US3] Update `saveInsights()` in `app/Jobs/CreateAiInsightsJob.php` (depends on T033, T036):
  - **Citations**: map each insight's `citations` refs through `$agent->knowledgeTool->citation($ref)`:
    - drop unknown refs and duplicates;
    - drop refs whose source no longer exists at save time: one `whereKey([...])->pluck('id')` query per source type, without the global scope, excluding trashed rows (FR-004 "still exists");
    - keep at most 10;
    - `Log::warning` with the dropped count when anything was dropped;
    - store the result in `citations`.
  - **Knowledge category**: `insightableModelFor()` returns null, so `insightable_*` is null and `evidence_level = L3`.
  - **Evidence**: the rules for other categories are unchanged.
- [X] T038 [US3] In `index()` of `app/Http/Controllers/AiInsightsController.php`, after `GenericQuery::apply()`, batch-load citation availability for the page:
  - group all citations by `source_type`;
  - per type, run one query on `KnowledgeDocument`, `KnowledgeBaseArticle` or `HotelPolicy`, using `withoutGlobalScope('hotel')->withTrashed()->whereKey(...)` and selecting `id`, `is_active`/`status` and `deleted_at`;
  - liveness rules are the same as `KnowledgeSearchTool::isLive`: not trashed; articles must be `published`; otherwise `is_active`;
  - attach a `citation_availability` map to each model with `setAttribute`.
- [X] T039 [US3] Add `'citations'` to `app/Http/Resources/AiInsightsResource.php`. Map each stored snapshot to its fields plus `available` (from `citation_availability`, defaulting to false when missing), and always return an array, never null (depends on T038).
- [X] T040 [US3] Run `php artisan test --filter="InsightCitationsTest|TenantIsolationTest|KnowledgeSearchToolTest|ConciergeKnowledgeCitationTest|AiInsights"` and fix failures.

**Checkpoint**: US1–US3 work. Insights carry traceable citations.

---

## Phase 6: User Story 4: Super admin sees cost by capability, model, account and margin (Priority: P2)

**Goal**: `/api/admin/ai-cost` adds `by_capability` per account, with the same keys and labels
as the hotel-facing usage report.

**Independent Test**: `php artisan test --filter=AiCostCapabilityTest`.
- Per-capability cost and calls sum to the account totals.
- Unpriced-model groups are flagged estimated.
- Non-super-admins get `403`.

### Tests for User Story 4

- [X] T041 [P] [US4] Create `tests/Feature/AiCostCapabilityTest.php` per [contracts/ai-cost-api.md](contracts/ai-cost-api.md):
  - **Sums**: seed rows for Concierge, insights and guest memory on one account. Assert:
    - `by_capability` keys and `label`s match `AiAgentName::label()`;
    - the `ai_cost_usd` values sum to the account total within 0.000001;
    - `calls` sum to `calls`;
    - only capabilities with calls are listed.
  - **Estimated flag**: an `cost_is_estimated` row makes its group `cost_is_estimated: true`.
  - **Margin unchanged**: with no contract value, `gross_margin` is null.
  - **Access**: an admin and an employee with every permission get `403`.

### Implementation for User Story 4

- [X] T042 [US4] Add `'by_capability' => $this->byCapability($rows)` in `describe()` of `app/Http/Controllers/Admin/AiCostController.php`.
  - The new `byCapability()` groups by `capability` (enum value) and returns, per key:
    - `label`, `calls`, `input_tokens` (`input_tokens + cached_tokens`), `output_tokens`;
    - `ai_cost_usd` (via `money()`) and `ai_cost_eur` (via `toEur()`);
    - `cost_is_estimated` (any row estimated).
  - It reuses the existing helpers, and every existing key stays unchanged.
- [X] T043 [US4] Run `php artisan test --filter="AiCostCapabilityTest|AiCostAttributionTest"` and fix failures.

**Checkpoint**: all four stories are independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T044 [P] Update `docs/ai-insights-api-documentation.md`:
  - `citations` and its fields, plus `available`;
  - the `knowledge` category and the rule that citations never raise the evidence level;
  - replace the stale "role must be admin" access table with `ai_insights.view` / `ai_insights.generate`.
- [X] T045 [P] Update `docs/usage-metering-api-documentation.md`:
  - the `ai` block (with an example from contracts/usage-api.md);
  - `measured_since`/`note`;
  - the `guest_memory_extractions` row under "Cost drivers" (recorded at `ExtractGuestMemoriesJob`);
  - replace the "`ai_usage_logs` is not read here at all" paragraph with the `AiUsageSummary` rule;
  - the no-model/no-provider rule.
- [X] T046 [P] Update `docs/ai-cost-attribution-api-documentation.md`:
  - the `capability` column and how it is stamped and backfilled;
  - `by_capability`.
- [X] T047 [P] Document the `503` "temporarily unavailable" response on `POST /api/ai-advisor/chat` in `docs/ai-advisor-chat-api-documentation.md`.
- [X] T048 [P] Document the neutral ceiling `error` text in `docs/knowledge-document-api-documentation.md` and `docs/global-knowledge-admin-api-documentation.md`.
- [X] T049 [P] Create `docs/latest-changes-2026-10-11.md`:
  - no breaking changes;
  - the additive fields (`ai`, `measured_since`, `citations`, `knowledge` category, `by_capability`);
  - the new advisor `503`;
  - the neutral ceiling error;
  - a link to `specs/012-insights-ai-economics/frontend-changes.md`.
- [X] T050 Run `./vendor/bin/pint`, then the full `php artisan test`, and confirm green against the T001 baseline.
- [X] T051 Walk through [quickstart.md](quickstart.md) §2 and confirm each expected outcome is covered by a passing test. Note any manual-only item in the quickstart.

---

## Review follow-ups (2026-10-12, after `/code-review`)

All fixed and covered by tests:

- **Usage breakdown fixes**:
  - `by_hotel` keeps deleted or moved hotels, and adds an `unassigned` row for calls with no hotel;
  - unknown capability values add to `unattributed` instead of overwriting it;
  - the super-admin usage list eager-loads hotels (one query).
- **Insight citations**:
  - a `knowledge` insight whose citations all fail to trace is not saved;
  - source existence is checked once per run inside `InsightKnowledgeSearchTool::citation()`.
- **Shared source rules**: citation availability moved into `AiInsightsResource` (a page-wide map passed by the controller, or its own lookup otherwise). `KnowledgeSourceType::modelClass()` and `App\Support\Knowledge\KnowledgeSources` now hold the one source-to-model map and the one liveness rule, which `KnowledgeSearchTool` and `SyncKnowledgeChunksJob` also use.
- **Redaction migration**: it also matches the old ceiling message text, which catches documents re-queued after a ceiling stop.
- **Capability mapping**: the capability → usage-feature map moved from `AiAgentName::meterFeatures()` into `tests/Feature/AiCapabilityCoverageTest.php` (`capabilityMeters()`), since no runtime code read it. T007 and research R7 describe the original placement.
- **`CreateAiInsightsJob` layout**: fixed (constant, then property, then constructor docblock).

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: after Setup. It blocks every story, because all of them read
  `capability` or the new enum methods.
- **US1 (Phase 3)** and **US2 (Phase 4)**: after Foundational. US2's T027 edits
  `UsageReport.php` after US1's T012 (same file), so run T012 before T027.
- **US3 (Phase 5)**: after Foundational only. It is independent of US1/US2.
- **US4 (Phase 6)**: after Foundational only.
- **Polish (Phase 7)**: after the stories being shipped.

### User Story Dependencies

- **US1 (P1)**: Foundational → independent.
- **US2 (P1)**: Foundational. It shares `UsageReport.php` with US1 (sequential edits).
- **US3 (P2)**: Foundational → independent.
- **US4 (P2)**: Foundational → independent.

### Within Each User Story

- Write the tests first and see them fail, then implement.
- Migrations and enums come before models, models before services and jobs, and services
  before controllers and resources.
- Each story ends with its focused test run.

### Parallel Opportunities

- Phase 2: T003, T004 and T006 run in parallel. T005 waits for T003 and T004, and T007 for
  T006.
- US1: T009 and T010 run in parallel. After T012, so do T014, T015 and T016, and T017 can
  run alongside them.
- US2: T022, T023 and T025 run in parallel.
- US3: T029, T030, T032 and T033 run in parallel (T033 after T031).
- With Phase 2 done, US1, US3 and US4 can run in parallel; US2 slots in after T012.
- Polish: T044–T049 run in parallel.

---

## Parallel Example: User Story 1

```bash
# Tests first, together:
Task: "Create tests/Feature/AccountUsageAiBlockTest.php"
Task: "Create tests/Feature/CostVisibilityTest.php"

# After T012 (UsageReport ai block):
Task: "Admin UsageController ai block in app/Http/Controllers/Admin/UsageController.php"
Task: "GetReportTool usage ai block in app/Ai/Tools/GetReportTool.php"
Task: "Advisor cost instruction in app/Ai/Agents/AdminAdvisorAgent.php"
Task: "publicMessage() in app/Exceptions/AiSpendCeilingExceededException.php"
```

## Parallel Example: User Story 3

```bash
Task: "Create tests/Feature/InsightCitationsTest.php"
Task: "Isolation cases in tests/Feature/TenantIsolationTest.php"
Task: "Add KNOWLEDGE to app/Enums/AiInsightCategories.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 → Phase 2 (capability attribution).
2. Phase 3 (US1): hotel usage with tokens, leak fixes, `CostVisibilityTest`.
3. **Stop and validate** with `CostVisibilityTest` and `AccountUsageAiBlockTest`. This
   alone satisfies the constitution's MUST on cost confidentiality.

### Incremental Delivery

1. US1: cost-free usage plus leak fixes (MVP).
2. US2: metering coverage and guest memory meter. The reports become complete.
3. US3: insight citations.
4. US4: super-admin per-capability cost.
5. Polish: docs, latest-changes, full suite.

---

## Notes

- [P] = different files, no dependency on an incomplete task.
- Never rename an existing `MeterFeature` code, and never edit a shipped migration.
- Do not add `Co-Authored-By` or any AI attribution to commits (CLAUDE.md).
- Commit after each phase checkpoint, and only when asked.
