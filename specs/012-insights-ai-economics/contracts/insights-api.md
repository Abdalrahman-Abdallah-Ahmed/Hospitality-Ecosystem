# Contract: AI insights with citations

These are existing endpoints, with unchanged permissions (`ai_insights.view`,
`ai_insights.generate`). The target documentation is `docs/ai-insights-api-documentation.md`.

## `GET /api/ai-insights`

Each insight gains `citations`, which is always present and `[]` when the insight used no
knowledge. A new `category` value, `knowledge`, is also added.

```json
{
  "id": "…",
  "hotel_id": "…",
  "insightable_type": "App\\Models\\Reservation",
  "insightable_id": "019fabcd-…",
  "title": "Late check-out requests are rising against a no-late-check-out policy",
  "description": "Five guests asked for late check-out this week; the published policy says it is not offered.",
  "category": "reservation",
  "insight_type": "general",
  "evidence_level": "L2",
  "evidence_sources": ["019fabcd-…"],
  "citations": [
    {
      "source_type": "policy",
      "source_id": "019fd111-…",
      "title": "Check-in and check-out",
      "location": "Section: Late check-out",
      "scope": "hotel",
      "last_updated": "2026-09-30",
      "available": true
    }
  ],
  "created_at": "…",
  "updated_at": "…"
}
```

| Field | Meaning |
| --- | --- |
| `citations[].source_type` | `document`, `article` or `policy` |
| `citations[].scope` | `hotel` (this hotel's own source) or `general` (shared guidance) |
| `citations[].title`, `location`, `last_updated` | As they were when the insight was generated |
| `citations[].available` | `false` once the source is deleted, deactivated or unpublished. The title is still shown (FR-006) |

`category = knowledge`:
- The insight rests only on knowledge sources.
- `insightable_*` is null.
- `evidence_level` is always `L3`.

The evidence level of every other category is unchanged, and citations never raise it
(FR-005).

Filtering: `GenericQuery` filters on `category` accept `knowledge`. There is no filter on
citations.

Isolation:
- A citation only ever names this hotel's sources or global ones (FR-025, SC-005).
- The isolation test generates insights for two hotels with different knowledge and asserts
  that no cross-citation appears.

## `POST /api/ai-insights`

The request and the `201` response are unchanged. The job now has knowledge search and
writes citations.

## Insights agent tools (internal contract)

| Tool | Change |
| --- | --- |
| `InsightKnowledgeSearchTool` (new, `ReadsData`) | Wraps `KnowledgeSearchTool` (staff audience). Returns results with run-local `ref` (`K1`…) instead of ids, plus scope, type, title, location and last updated. On failure it returns a neutral "unavailable" string and reports the exception |
| Structured output | Each insight gains `citations: string[]` (refs). `category` gains `knowledge` |
| Instructions | Cite only refs returned by the knowledge tool. Prefer `hotel` scope when sources disagree. Use `knowledge` only when no hotel record grounds the insight. Never quote guest messages (unchanged) |
