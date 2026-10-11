# Latest Changes: 2026-10-11

## Insights Citations and AI Economics Verification (Phase 12: SPEC-090, SPEC-091)

### Summary

- **Insights cite their knowledge sources.** While generating insights, the AI can now search
  the hotel's knowledge base. An insight that relied on a policy, article or document lists it
  in a new `citations` array, with its title, location, scope (hotel or general guidance),
  last-updated date and whether the source is still available. Only sources the search
  actually returned can be cited, and never another hotel's. Citations never raise an
  insight's evidence level. A new category, `knowledge`, marks insights that rest on knowledge
  alone; they are always `L3`.
- **Hotel admins see their AI usage, never our cost.** `GET /api/usage` gains an `ai` block:
  requests and tokens, in total, per AI capability (Concierge, Admin AI, Recommendations,
  Insights, Document reading, Guest memories, Knowledge indexing) and per hotel. It shows no
  cost, price, margin, model or provider. The Admin AI's usage report carries the same block,
  and the Admin AI now declines cost questions instead of estimating.
- **Super admins see cost per capability.** `GET /api/admin/ai-cost` gains `by_capability`
  per account (calls, tokens, cost, estimated flag), under the same keys and labels as the
  usage report. `GET /api/admin/usage` gains the same `ai` block per account.
- **Guest memory extraction is metered.** There is a new usage feature,
  `guest_memory_extractions`, counted once per completed extraction from 2026-10-11, with no
  backfill. Every feature entry now has `measured_since`, plus a `note` when the requested
  range starts before it.
- **Two cost leaks closed.**
  - A knowledge document stopped by the daily AI limit used to store, in its admin-visible
    `error`, how much the account had spent and its limit. It now reads "The daily AI usage
    limit was reached; try again tomorrow.", and stored errors were rewritten.
  - The Admin AI web chat used to fail with an unhandled error when the limit was reached.
    It now answers `503` with a neutral message.
- **Automated checks.**
  - `CostVisibilityTest` fails if any hotel-facing route or Admin AI read tool shows cost,
    model or provider, or if an unexpected class reads cost data.
  - `AiCapabilityCoverageTest` fails if an AI capability is not metered or leaves
    unattributed cost.

### Breaking changes

None. All response changes are additive.

### What the frontend must handle

1. `POST /api/ai-advisor/chat` can return **503** with
   `"The AI assistant is temporarily unavailable. Try again later."`. Show it inline and allow
   a retry; it is not a logout or a fatal error.
2. **Insights**:
   - `citations` is always present (`[]` when unused);
   - `category` can be `knowledge`.
3. **Usage page**:
   - the new `ai` block;
   - `measured_since` and `note` on feature entries.
4. **Super-admin cost report**: the new `by_capability` table.

Details: [frontend changes](../specs/012-insights-ai-economics/frontend-changes.md),
[AI insights](ai-insights-api-documentation.md), [usage metering](usage-metering-api-documentation.md),
[AI cost attribution](ai-cost-attribution-api-documentation.md),
[AI advisor chat](ai-advisor-chat-api-documentation.md).

### Data changes (migrations)

| Migration | Change |
| --- | --- |
| `2026_10_11_000001_add_citations_to_ai_insights_table` | `ai_insights.citations` (jsonb, default `[]`); the category check admits `knowledge` |
| `2026_10_11_000002_add_capability_to_ai_usage_logs_table` | `ai_usage_logs.capability` + index; existing rows backfilled from their AI run, then their agent class |
| `2026_10_11_000003_redact_ceiling_errors_on_knowledge_documents` | Rewrites stored spend-limit errors to the neutral text |

No permission changes.
