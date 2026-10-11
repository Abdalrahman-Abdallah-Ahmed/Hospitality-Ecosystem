# Data Model: Insights Citations and AI Economics Verification

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

There are three migrations (two schema, one data) and no new tables. The data migration,
`2026_10_11_000003_redact_ceiling_errors_on_knowledge_documents.php`, is described in
[contracts/cost-visibility.md](contracts/cost-visibility.md). Everything else is enum, projection or
response-shape changes.

---

## Migrations

### `2026_10_11_000001_add_citations_to_ai_insights_table.php`

| Column | Type | Notes |
| --- | --- | --- |
| `citations` | `jsonb`, not null, default `'[]'` | Array of citation snapshots (below). Existing rows get `[]`, with no backfill (spec Edge Cases) |

### `2026_10_11_000002_add_capability_to_ai_usage_logs_table.php`

| Column | Type | Notes |
| --- | --- | --- |
| `capability` | `string(32)`, not null, default `'unattributed'` | An `AiAgentName` value. Stamped at write time from `AiRun::agent()` (R1) |
| index | `(hotel_group_id, occurred_at, capability)` | Serves the per-capability usage and cost reports |

The backfill runs in the same migration, as a bulk `UPDATE`, in order:
1. `capability = ai_activities.agent`, where `ai_usage_logs.ai_run_id = ai_activities.ai_run_id`
   and the activity is the run row (`category = 'ai_run'`).
2. Remaining rows map from `agent` (class basename):

   | Class | Capability |
   | --- | --- |
   | `GuestConciergeAgent` | `concierge` |
   | `AdminAdvisorAgent` | `admin_advisor` |
   | `RecommendationAgent` | `recommendation` |
   | `InsightsAgent` | `insights` |
   | `TurnSignalAgent` | `concierge` (it only runs inside a Concierge turn) |
   | `DocumentVisionAgent` | `document_vision` |
   | `GuestMemoryAgent` | `guest_memory` |

3. Everything else stays `unattributed`. This covers `Embeddings` and `Reranking` outside a
   run. They are historic and visible as such in the cost report.

`down()` drops the index and the column. `ai_usage_logs` stays append-only: the backfill
is a one-time schema migration, not an application write.

---

## Citation snapshot (`ai_insights.citations[]`)

| Field | Type | Rule |
| --- | --- | --- |
| `source_type` | `document` \| `article` \| `policy` | `KnowledgeSourceType` |
| `source_id` | uuid | The cited record's id. It always belongs to this hotel or to global knowledge (R10) |
| `title` | string | Title at generation time, always shown even if the source is later removed |
| `location` | string \| null | Page, sheet or section from the chunk metadata |
| `scope` | `hotel` \| `general` | `KnowledgeScope` |
| `last_updated` | date \| null | The source's `updated_at` date at generation time |

Validation (in `CreateAiInsightsJob`, before save):
- Each ref must exist in the run's `InsightKnowledgeSearchTool` registry. Otherwise it is
  dropped.
- Duplicate refs on one insight collapse to one entry.
- There are at most 10 citations per insight. Extra refs are dropped and a warning logged.

The response adds one computed field to each citation:
- `available: bool`. It is false when the source is soft-deleted, inactive, or (for
  articles) unpublished.
- It is computed per page in one query per source type (R11) and never stored.

---

## Enum changes

### `AiInsightCategories` (+1)

| Case | Value | Insightable | Evidence level |
| --- | --- | --- | --- |
| `KNOWLEDGE` | `knowledge` | none (`insightable_*` null) | always `L3` |

The existing rules are unchanged:
- A verified `reservation`, `task` or `guest_message` → L2.
- `general` with this hotel's id → L2.
- Anything unverified → L3.

Citations never change the level (FR-005).

### `MeterFeature` (+1, plus a method)

| Case | Code | Unit | Category | Label | `measuredSince()` |
| --- | --- | --- | --- | --- | --- |
| `GUEST_MEMORY_EXTRACTIONS` | `guest_memory_extractions` | `extractions` | `cost_driver` | Guest memory extractions | release date (set in the enum in this change) |

- `measuredSince(): ?CarbonImmutable` returns null for every existing feature.
- Codes are permanent (FR-020).

### `AiAgentName` (methods only, no new cases)

- `meterFeatures(): array<MeterFeature>` is an exhaustive `match`. The table is in
  research R7; `UNATTRIBUTED` returns `[]`.
- `label(): string` gives the display name used by both reports:
  - Concierge
  - Admin AI
  - Recommendations
  - Insights
  - Turn classification
  - Document reading
  - Guest memories
  - Knowledge indexing
  - Unattributed
- `reportable(): array<self>` returns every case except `UNATTRIBUTED` and `TURN_SIGNAL`.
  Turn classification always runs inside a Concierge turn, so its calls are stamped
  `concierge`, and a separate row would always read 0. It is the fixed
  row set for the hotel-facing `by_capability`.

---

## Projections (read models, no storage)

### `AiUsageSummary` (hotel-facing; R2)

Source: `ai_usage_logs`, filtered by `hotel_group_id` and `occurred_at` in the range.

| Output | Expression |
| --- | --- |
| `requests` | `COUNT(*)` |
| `input_tokens` | `SUM(input_tokens + cached_tokens)` |
| `output_tokens` | `SUM(output_tokens)` |
| `total_tokens` | `input_tokens + output_tokens` |

These are grouped three ways:
- account total;
- `capability`, with all `reportable()` capabilities always present (zero-filled);
- `hotel_id`, with every hotel in the account always present.

`unattributed` rows count in the totals and appear in `by_capability` only when non-zero,
as `unattributed`. That keeps the sums exact (FR-011) without hiding a gap.

The summary never selects `cost_usd`, `cost_is_estimated`, `model`, `provider`,
`operation` or `attempt`. Tests enforce this (R3).

### `AiCostController` `by_capability` (super admin; R6)

The same grouping as above, plus `ai_cost_usd`, `ai_cost_eur` and `cost_is_estimated`
(true if any row in the group is estimated).

---

## Unchanged entities (verified, not modified)

- `meter_events` and `usage_counters`: append-only, with the idempotency key unique per
  account.
- `ai_model_prices`: effective-dated.
- `ai_activities`: cost is attached for super admins only (`AiActivityController`).
- `guest_memory_extractions`: the extraction id is the meter idempotency key
  (`guest-memory-extraction:{id}`).
