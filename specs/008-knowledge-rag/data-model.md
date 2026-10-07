# Data Model: Knowledge Documents and RAG

**Feature**: `008-knowledge-rag` · **Date**: 2026-10-07 · Decisions referenced as R# are in
[research.md](research.md).

Migrations (new; shipped migrations are never edited):

| File | Purpose |
| --- | --- |
| `2026_10_07_000001_extend_knowledge_documents_table.php` | New document columns, indexes and checks; map `processing` → `uploaded` |
| `2026_10_07_000002_add_is_active_to_knowledge_chunks_table.php` | `is_active` plus a partial index |
| `2026_10_07_000003_create_knowledge_index_rebuilds_table.php` | Rebuild tracking (R12) |
| `2026_10_07_000004_cascade_knowledge_hotel_foreign_keys.php` | `nullOnDelete` → `cascadeOnDelete` on chunks, documents and articles (R17) |

## KnowledgeDocument (`knowledge_documents`, extended)

Model: `App\Models\KnowledgeDocument`. It uses `BelongsToHotel`, `HasUuids`,
`SoftDeletes`, `Filterable` and `RecordsEvents`. `$keyType = 'string'`,
`$incrementing = false`. `hotel_id = null` means global.

| Column | Type | Existing? | Notes |
| --- | --- | --- | --- |
| `id` | uuid PK | ✓ | |
| `hotel_id` | uuid null FK hotels, **cascade** | ✓ (FK changed, R17) | null = global |
| `uploaded_by` | uuid null FK users, nullOnDelete | ✓ | null after the user is deleted ("removed" uploader) |
| `title` | string(255) | ✓ | Shown in citations; editable without re-index |
| `category` | string null | ✓ | `KnowledgeBaseCategory` value (cast) |
| `original_filename` | string | ✓ | Never sent to guests |
| `disk` | string | ✓ | `config('knowledge.disk')` |
| `path` | string | ✓ | Current (indexed or first) file |
| `mime_type` | string | ✓ | Detected type, not the client's |
| `size` | bigint | ✓ | bytes |
| `content_hash` | char(64) | **new** | SHA-256 of the current file (R7) |
| `content` | longText null | ✓ | Extracted or corrected text, joined, for display |
| `segments` | jsonb null | **new** | `[{location: string, text: string}]`, what the index is built from (R2) |
| `content_source` | string(16) default `extracted` | **new** | `KnowledgeContentSource`: `extracted` / `corrected` |
| `corrected_by` | uuid null FK users, nullOnDelete | **new** | |
| `corrected_at` | timestampTz null | **new** | |
| `status` | string | ✓ (default changed) | `KnowledgeDocumentStatus`: `uploaded` / `extracting` / `indexed` / `failed`; default `uploaded` |
| `failure_code` | string(40) null | **new** | `KnowledgeDocumentFailure`, set only when `failed` |
| `error` | text null | ✓ | Internal detail for staff and support; never shown to guests |
| `is_active` | boolean default true | **new** | false = never returned by search |
| `page_count` | int null | **new** | Pages (PDF), sheets (XLSX) or 1 |
| `scanned_page_count` | int default 0 | **new** | Pages or images read by AI vision on the last extraction |
| `chunk_count` | int default 0 | **new** | Chunks live in the index |
| `index_fingerprint` | char(64) null | **new** | Input hash of the run that owns the live chunks (R5) |
| `indexed_at` | timestampTz null | **new** | Last successful swap |
| `pending_path` | string null | **new** | Replacement waiting to be indexed (R9) |
| `pending_original_filename` | string null | **new** | |
| `pending_mime_type` | string null | **new** | |
| `pending_size` | bigint null | **new** | |
| `pending_content_hash` | char(64) null | **new** | |
| `created_at`, `updated_at`, `deleted_at` | timestamps | ✓ | `deleted_at` drives restore and purge (R11) |

**Indexes and constraints**:
- `unique (hotel_id, content_hash) where deleted_at is null and hotel_id is not null`
  (hotel duplicates, FR-005);
- `unique (content_hash) where deleted_at is null and hotel_id is null` (global
  duplicates);
- `index (hotel_id, status)`, `index (hotel_id, is_active)`, `index (deleted_at)` for the
  purge;
- `check (status in ('uploaded','extracting','indexed','failed'))`;
- `check (content_source in ('extracted','corrected'))`;
- `check ((status = 'failed') = (failure_code is not null))`;
- `check ((content_source = 'corrected') = (corrected_at is not null))`;
- data step: `update knowledge_documents set status = 'uploaded' where status =
  'processing'`.

**Relationships**:
- `hotel()` belongsTo;
- `uploader()` belongsTo User (`uploaded_by`);
- `corrector()` belongsTo User (`corrected_by`);
- `chunks()` morphMany KnowledgeChunk (`chunkable`).

**Casts**: `category` → `KnowledgeBaseCategory`, `status` → `KnowledgeDocumentStatus`,
`failure_code` → `KnowledgeDocumentFailure`, `content_source` →
`KnowledgeContentSource`, `segments` → `array`, `is_active` → `boolean`, `corrected_at` /
`indexed_at` → `datetime`.

**Hidden from serialization**: `disk`, `path`, `pending_path`, `content_hash`,
`pending_content_hash`, `index_fingerprint`.

**Audited attributes** (`RecordsEvents`): `title`, `category`, `is_active`, `status`,
`failure_code`, `content_source`. Explicit events are listed under Audit events below.

### State transitions — `status`

```text
          upload / replace / reindex / discard-correction / correction
                                  │
  ┌──────────┐   job starts   ┌────────────┐   swap committed   ┌──────────┐
  │ uploaded │ ─────────────► │ extracting │ ─────────────────► │ indexed  │
  └──────────┘                └────────────┘                    └──────────┘
                                  │  permanent error, or retries exhausted
                                  ▼
                              ┌──────────┐   reindex / replace / correction
                              │  failed  │ ─────────────────────────► extracting
                              └──────────┘
```

- `indexed → extracting` happens on reindex, replace, correct or discard. The live chunks
  stay searchable throughout (R5, R9).
- `failed` never deletes live chunks. A document that failed on its first run has none.
- `is_active` and `deleted_at` are separate from `status`. Searchable means: `deleted_at
  is null`, `is_active`, and live chunks exist.

### Lifecycle actions → effects

| Action | Columns | Chunks | Job |
| --- | --- | --- | --- |
| Upload | new row, `uploaded`, file stored | — | `extract` |
| Edit title or category | `title`, `category` | chunk `category` updated in place (`UPDATE … where chunkable_id`), no re-embed | — |
| Replace file | `pending_*` set, `status = uploaded` | unchanged until swap | `extract` (pending file) |
| Swap after replace | `pending_*` → main, `content_source = extracted`, correction cleared, old file deleted after commit | replaced atomically | — |
| Deactivate / activate | `is_active` | `is_active` flipped in the same transaction | — |
| Correct text | `segments`, `content`, `content_source = corrected`, `corrected_by/at` | replaced atomically by the job | `from_segments` |
| Discard correction | `content_source = extracted`, correction cleared | replaced atomically by the job | `extract` |
| Re-index | — | replaced atomically | `from_segments` if segments exist, otherwise `extract` |
| Delete | `deleted_at` | `is_active = false` | — |
| Restore (≤ 30 days) | `deleted_at = null` | `is_active` = document's `is_active` | — |
| Purge (> 30 days) | row force-deleted | deleted | files deleted after commit |

## KnowledgeChunk (`knowledge_chunks`, extended)

| Column | Change | Notes |
| --- | --- | --- |
| `hotel_id` | FK → **cascadeOnDelete** (R17) | null = global |
| `is_active` | **new** boolean default true | Search filters on it (R13) |
| `metadata` | content extended | `{location: ?string, source_type: 'document'\|'article'\|'policy', ...}`. Article and policy rows keep `title/tags/version` |

Index: `index (hotel_id) where is_active` (partial), alongside the existing `hotel_id`
and HNSW indexes. Existing rows default to active, which is correct: today they are only
present while their source is published or active.

`ChunkSynchronizer::syncSegments()` writes `metadata.location` per chunk. Article and
policy chunks get `location = null` and `source_type`.

## KnowledgeIndexRebuild (`knowledge_index_rebuilds`, new)

Model: `App\Models\KnowledgeIndexRebuild`, a platform record. It does **not** use
`BelongsToHotel`: only `routes/admin.php` reads it.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `scope` | string(16) | `KnowledgeRebuildScope`: `all` / `hotel` / `global` |
| `hotel_id` | uuid null FK hotels cascade | Set when `scope = hotel` |
| `requested_by` | uuid null FK users nullOnDelete | |
| `status` | string(16) | `KnowledgeRebuildStatus`: `running` / `completed` |
| `total` | int | Sources dispatched |
| `succeeded` | int default 0 | Atomic increments |
| `failed` | int default 0 | Atomic increments |
| `failures` | jsonb default `[]` | `[{source_type, source_id, title, reason}]`, appended with `jsonb ||` |
| `started_at` | timestampTz | |
| `finished_at` | timestampTz null | Set when `succeeded + failed = total` |
| `created_at`, `updated_at` | | |

Checks:
- `check ((scope = 'hotel') = (hotel_id is not null))`;
- `check (succeeded + failed <= total)`.

## Enums (new, `app/Enums`)

| Enum | Cases |
| --- | --- |
| `KnowledgeDocumentStatus` | `UPLOADED`, `EXTRACTING`, `INDEXED`, `FAILED` |
| `KnowledgeDocumentFailure` | `UNSUPPORTED_TYPE`, `ENCRYPTED`, `CORRUPT`, `DUPLICATE` (a replacement that matches another live document by the time it is indexed), `NO_TEXT`, `TOO_MANY_SCANNED_PAGES`, `TOO_MANY_ROWS`, `TOO_LARGE_FOR_VISION`, `FILE_MISSING`, `USAGE_LIMIT_REACHED`, `EXTRACTION_UNAVAILABLE`, `EMBEDDING_UNAVAILABLE`. `isPermanent()` is true for every case except the two `*_UNAVAILABLE` ones. `message(locale)` reads `lang/{en,ar}/knowledge.php` |
| `KnowledgeContentSource` | `EXTRACTED`, `CORRECTED` |
| `KnowledgeSourceType` | `DOCUMENT`, `ARTICLE`, `POLICY`; mapped from the morph class |
| `KnowledgeScope` | `HOTEL` (`"hotel"`), `GENERAL` (`"general"`); the label in results |
| `KnowledgeAudience` | `STAFF`, `GUEST` (R14) |
| `KnowledgeRebuildScope` | `ALL`, `HOTEL`, `GLOBAL` |
| `KnowledgeRebuildStatus` | `RUNNING`, `COMPLETED` |

Changed: `Permission` gains 5 cases (R15), and `MeterFeature` gains
`KNOWLEDGE_PAGES_READ = 'knowledge_pages_read'` (R18).

## Config (`config/knowledge.php`, new)

| Key | Default | Use |
| --- | --- | --- |
| `disk` | `local` | Storage disk (R8) |
| `max_upload_kb` | 20480 | FR-002 |
| `max_rows` | 20000 | Spreadsheet or CSV rows before `too_many_rows` |
| `vision.provider` | `gemini` | R4 |
| `vision.model` | `null` (provider default) | R4 |
| `vision.max_pages` | 50 | FR-015b |
| `vision.max_file_mb` | 14 | R4 |
| `vision.timeout` | 120 | Seconds per vision call; below the job's 300 (R19) |
| `search.hotel_limit` | 5 | R13 |
| `search.global_limit` | 3 | R13 |
| `search.min_similarity` | 0.5 | Unchanged from today |
| `purge_after_days` | 30 | FR-010b |

## Validation rules (from requirements)

| Rule | Where |
| --- | --- |
| File required, ≤ 20 MB, detected type in the allow-list and matching the extension | `StoreKnowledgeDocumentRequest`, `ReplaceKnowledgeDocumentFileRequest` (R7) |
| `title` required on create, string ≤ 255; `category` nullable `KnowledgeBaseCategory` | Store and update requests |
| No duplicate `content_hash` in the same scope among non-deleted rows | Service (409) + partial unique indexes |
| Correction: `segments` is an array of the same length and locations as stored; each `text` is a string ≤ 100k chars | `CorrectKnowledgeDocumentTextRequest` |
| Correction only when the document has segments (status `indexed`, or `failed` with segments) | Service (422) |
| Restore only when `deleted_at` ≥ now − 30 days | Service (404 after that; the row is purged anyway) |
| Rebuild: `scope` in `all` / `hotel` / `global`; `hotel_id` required for `hotel` and must exist | `StartKnowledgeRebuildRequest` |

## Audit events (`EventLogger`)

Subject `knowledge_document`:
- `created` and `updated` from `RecordsEvents`;
- explicit `replaced`, `activated`, `deactivated`, `deleted`, `restored`,
  `text_corrected`, `text_correction_discarded`, `reindex_requested`, `indexed`,
  `index_failed` (with `failure_code` as reason), `purged`.

Subject `knowledge_index_rebuild`: `started`, `completed`.

Global documents record with `hotel_id = null`. The actor is the signed-in user; job-side
events are `system`.
