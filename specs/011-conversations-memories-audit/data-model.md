# Data Model: Conversation Visibility, Guest Memories, and AI Audit

**Feature**: `011-conversations-memories-audit` · **Date**: 2026-10-10

Migrations follow the repo's `YYYY_MM_DD_NNNNNN_description.php` sequence. No shipped
migration is edited.

## New tables

### `ai_activities` (append-only, hotel-scoped) — R1

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK hotels, nullable, nullOnDelete | `BelongsToHotel`; null only for a run with no hotel (should not happen for hotel AI) |
| `ai_run_id` | uuid, nullable, indexed | The run this entry belongs to; `id` of the `ai_run` row itself is equal to its run id |
| `category` | string | `AiActivityCategory`: `data_change`, `guest_outreach`, `ai_run` |
| `agent` | string | `AiAgentName`: `concierge`, `admin_advisor`, `recommendation`, `insights`, `turn_signal`, `document_vision`, `guest_memory`, `knowledge` (embedding-only runs), `unattributed` (caller named no agent) |
| `tool` | string, nullable | Tool class basename, or job class basename for jobs |
| `action` | string | e.g. `booking.created`, `pitch.offered`, `proactive.skipped`, `run.completed` |
| `result` | string | `AiActivityResult`: `succeeded`, `refused`, `failed` |
| `reason` | string(255), nullable | Short reason when not succeeded (tool text trimmed, `permission`, `spend_ceiling`, exception class, skip reason) |
| `target_type` / `target_id` | nullable uuid morph | The record changed / pitched / messaged |
| `changes_count` | smallint, default 0 | Number of audited records the write touched |
| `conversation_id` | string(36), nullable | `agent_conversations.id` — package table, no FK |
| `acted_for_type` / `acted_for_id` | nullable uuid morph | `User` (staff) or `Guest`; null for unattended jobs |
| `trigger_kind` | string, nullable | `AiTriggerKind` (on `ai_run` rows) |
| `usage` | json, nullable | `ai_run` only: `{requests, input_tokens, output_tokens, cached_tokens}` — never cost |
| `occurred_at` | timestamp | |
| `created_at` | timestamp | No `updated_at`; model refuses delete and every update except back-filling a null `conversation_id` at run close (R2) |

Indexes: `(hotel_id, occurred_at desc)`, `(hotel_id, category, occurred_at)`,
`(hotel_id, conversation_id)`, `(ai_run_id)`, `(target_type, target_id)`.

Rules:
- Exactly one category (NOT NULL, enum-validated).
- Never stores conversation text, prompts, model output, or memory content (FR-024).
- Cost is not stored here; it is joined from `ai_usage_logs` for super admins only (R10).

### `guest_memories` — R7

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK hotels, cascadeOnDelete | `BelongsToHotel` |
| `guest_id` | uuid FK guests, cascadeOnDelete | Force-delete cascade; soft delete handled by hook (below) |
| `category` | string | `GuestMemoryCategory`: `preference`, `communication`, `interest`, `other` |
| `content` | string(200) | One sentence in `language` |
| `language` | string(2) | `en` or `ar` |
| `confidence` | string | `GuestMemoryConfidence`: `medium`, `high` stored (`low` is never stored, FR-013) — enum keeps `low` for the extractor's output only |
| `source_stay_id` | uuid FK stays, nullOnDelete | The stay whose conversation produced or last refreshed it |
| `extracted_at` | timestamp | Last create/refresh/replace |
| timestamps | | |

Indexes: `(hotel_id, guest_id, extracted_at desc)`.

Model: `HasUuids`, `BelongsToHotel`, `Filterable`, `RecordsEvents` with
`eventLoggedAttributes = [category, confidence, language, source_stay_id]` — **not
content**. No `SoftDeletes` (deletion is permanent, FR-019). No create/update API.

Lifecycle: created / refreshed / replaced only by `ExtractGuestMemoriesJob`; deleted by an
admin (`guest_memories.delete`), by cap eviction, or with the guest.

Guest deletion (FR-020): `Guest::deleted` hook (fires on soft delete) deletes all of the
guest's memories in the same transaction; restore does not recreate them. FK cascade covers
force delete.

### `guest_memory_extractions` — R7

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK hotels, cascadeOnDelete | `BelongsToHotel` |
| `guest_id` | uuid FK guests, cascadeOnDelete | |
| `reservation_id` | uuid FK reservations, cascadeOnDelete | |
| `status` | string | `GuestMemoryExtractionStatus` (below) |
| `attempts` | smallint, default 0 | Rounds started (1 = first, 2 = the 24 h retry) |
| `retry_at` | timestamp, nullable | Set when `pending_retry` |
| `memories_written` | smallint, default 0 | created + refreshed + replaced |
| `ai_run_id` | uuid, nullable | Last run |
| `last_error` | string(255), nullable | Exception class / reason, no content |
| timestamps | | |

Unique: `(hotel_id, guest_id, reservation_id)` — the idempotency key (FR-011, SC-005).
Index: `(status, retry_at)`.

State transitions:

```text
(new) ──► running ──► extracted                       (final)
   │         ├──────► skipped_no_messages             (final, not re-runnable)
   │         ├──────► failed / ceiling, attempts=1 ──► pending_retry ──(retry_at)──► running
   │         └──────► failed / ceiling, attempts=2 ──► failed | skipped_spend_ceiling (final)
   └──► skipped_disabled                              (final, not re-runnable)

super admin re-run: failed | skipped_spend_ceiling ──► pending_retry (attempts = 1, so one try and then final, retry_at = now) ──► running
```

## Altered tables

| Table | Change | Why |
| --- | --- | --- |
| `hotels` | `guest_memories_enabled boolean NOT NULL DEFAULT true` | FR-032 switch (R12) |
| `ai_usage_logs` | `ai_run_id uuid nullable` + index | Sum usage/cost per run (R2) |

`event_log` is **not** altered: the run id goes into `context.ai.run_id` like the existing
AI context keys.

## Existing records read (unchanged)

- `agent_conversations` / `agent_conversation_messages` (D15): read-only (R6).
- `pitch_decisions`, `recommendation_outcomes`, `proactive_messages`: observed for
  `guest_outreach` rows (R5).

## Enums (new) — `app/Enums`

| Enum | Cases |
| --- | --- |
| `AiActivityCategory` | `data_change`, `guest_outreach`, `ai_run` |
| `AiActivityResult` | `succeeded`, `refused`, `failed` |
| `AiAgentName` | `concierge`, `admin_advisor`, `recommendation`, `insights`, `turn_signal`, `document_vision`, `guest_memory`, `knowledge`, `unattributed` |
| `GuestMemoryCategory` | `preference`, `communication`, `interest`, `other` |
| `GuestMemoryConfidence` | `low`, `medium`, `high` |
| `GuestMemoryExtractionStatus` | `running`, `pending_retry`, `extracted`, `skipped_no_messages`, `skipped_disabled`, `skipped_spend_ceiling`, `failed` |
| `EscalationRequestType` | `see_memories`, `forget_memory` (R8) |

`Permission` gains `CONVERSATIONS_VIEW`, `GUEST_MEMORIES_VIEW`, `GUEST_MEMORIES_DELETE`.

## Audit events (event_log)

| Event | Subject | When |
| --- | --- | --- |
| `guest.conversation_viewed` | Guest | Conversation show, page 1 (`context.conversation_id`) |
| `guest.memories_viewed` | Guest | Memory list for one guest |
| `guest_memory.created/updated/deleted` | GuestMemory | Automatic (`RecordsEvents`), no content |
| `hotel.guest_memories_toggled` | Hotel | Switch changed |
| existing model events | … | Now carry `context.ai.run_id` and complete `context.ai` for every AI write |
