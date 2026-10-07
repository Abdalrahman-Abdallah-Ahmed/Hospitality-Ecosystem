# Contract: Knowledge Search Tool and Agent Citation Rules

`App\Ai\Tools\KnowledgeSearchTool` is changed in place; it keeps its name.

## Construction

```php
new KnowledgeSearchTool(Hotel $hotel, KnowledgeAudience $audience = KnowledgeAudience::STAFF)
```

| Agent | Audience |
| --- | --- |
| `GuestConciergeAgent` | `GUEST` |
| `AdminAdvisorAgent` | `STAFF` |
| `RecommendationAgent` | `STAFF` |
| Any future agent (e.g. Insights, Phase 12) | `STAFF` by default (FR-030) |

## Input schema

| Field | Type | Rule |
| --- | --- | --- |
| `query` | string | required; the question in any language |

## Behavior

1. Embed `query` once (`ChunkSynchronizer::DIMENSIONS`).
2. Run two filtered vector queries in one transaction with widened HNSW search (R13):
   - **hotel**: `hotel_id = $hotel->id AND is_active`, `min_similarity 0.5`, limit
     `knowledge.search.hotel_limit` (5);
   - **general**: `hotel_id IS NULL AND is_active`, the same threshold, limit
     `knowledge.search.global_limit` (3).
3. Eager-load each chunk's source (`chunkable`). Drop chunks whose source is missing,
   soft-deleted or inactive, as a safety net behind `is_active`.
4. Return hotel results first, then general results, each group by similarity.

The tenant scope is lifted on purpose and replaced by the explicit `hotel_id`
predicates. Another hotel's chunks can never match (FR-022). Background callers pass the
hotel explicitly, so there is no ambient context to leak.

## Output

The tool returns a JSON string, an array of results, or the literal
`No relevant results found.` when both queries are empty.

```json
[
  { "rank": 1, "scope": "hotel", "source_type": "document", "title": "House Rules 2026",
    "location": "Page 3", "last_updated": "2026-10-01", "content": "Checkout is at 11:00 …" },
  { "rank": 2, "scope": "general", "source_type": "article", "title": "Standard Check-in and Check-out",
    "location": null, "last_updated": "2026-09-12", "content": "Standard checkout time is 12:00 …" }
]
```

| Field | Staff audience | Guest audience |
| --- | --- | --- |
| `scope` | `hotel` / `general` | `hotel` / `general` |
| `source_type` | `document` / `article` / `policy` | the same for hotel; **`general`** for general |
| `title` | the source's current title | hotel: the title; **general: `null`** |
| `location` | page, sheet or section, or null | hotel: location; **general: `null`** |
| `last_updated` | the source's `updated_at` (date) | the same |
| `content` | passage text | passage text |

Never present in either audience: file names, ids, storage paths, uploader, `hotel_id`,
raw metadata (FR-026).

## Agent instruction changes

**GuestConciergeAgent**, replacing the current knowledge-base bullet:
- Use the knowledge search for hotel information and policies only, never for
  availability, prices on a date, bookings or statuses. Those come from the PMS tools
  (FR-029).
- When you answer from a `hotel` result, name it by its `title`, in the guest's language
  ("According to the hotel's House Rules…").
- When you answer from a `general` result, say it is general information. It has no title
  to name.
- When `hotel` and `general` results disagree, follow the `hotel` result. When two `hotel`
  results disagree, follow the one with the later `last_updated` and mention both.
- Only cite results the tool returned. If it returns no relevant result, say you do not
  have that information and offer to pass the question to staff (FR-028).

**AdminAdvisorAgent**:
- Cite each source as *title — location (updated date)*, plus scope when it is general.
- Follow the same precedence rules and the same no-invention rule.

**RecommendationAgent**: the same precedence rule; citations are not required in its
output.

## Tests at the tool boundary

- **Order**: one hotel chunk and one more-similar global chunk → the hotel result has
  rank 1.
- **Isolation**: chunks from 3 hotels → each hotel sees only its own plus general
  (SC-002).
- **Guest redaction**: the guest audience returns `title = null`, `location = null` and
  `source_type = general` for general results.
- **Inactive, deleted and corrected sources**: excluded, excluded, and corrected text
  returned, respectively.
- **Live title**: renaming a document changes `title` in the next search with no
  re-index.
- **Concierge wiring**: the `GuestConciergeAgent::tools()` instance has audience `GUEST`.
