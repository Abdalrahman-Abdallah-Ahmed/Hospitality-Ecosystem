# Research: Knowledge Documents and RAG

**Feature**: `008-knowledge-rag` · **Date**: 2026-10-07 · **Spec**: [spec.md](spec.md)

Each decision answers an open technical question raised by the spec or by reading the
code. Format: Decision / Rationale / Alternatives considered.

## What exists today

| Piece | File | State |
| --- | --- | --- |
| Chunk store | `knowledge_chunks` (`2026_08_11_090200`) | Polymorphic `chunkable`, nullable `hotel_id` = global, `metadata` json, `vector(1536)` with HNSW. **FK is `nullOnDelete`**: if a hotel is force-deleted, its chunks become *global* (R17). |
| Document table | `knowledge_documents` (`2026_08_11_090100`) | Has hotel_id (nullable, `nullOnDelete`), uploaded_by, title, category, original_filename, disk, path, mime_type, size, `content`, `status` (default `processing`), `error`, soft deletes. **No model, no code reads it.** |
| Chunk writer | `App\Support\Knowledge\ChunkSynchronizer::sync()` | Deletes the old chunks **before** embedding, outside a transaction. An embedding failure leaves the source with no chunks, and searches in between see nothing (R5). |
| Splitter | `App\Support\Knowledge\TextChunker` | Paragraph → sentence → character packing, ~800 tokens. Kept. |
| Sync job | `App\Jobs\SyncKnowledgeChunksJob` | Articles and policies. Runs under `AiCostContext` (`scheduled_job`) and meters `embeddings_generated`. |
| Observers | `KnowledgeBaseArticleObserver`, `HotelPolicyObserver` | Re-sync on save; delete chunks when unpublished, inactive or deleted. |
| Search | `App\Ai\Tools\KnowledgeSearchTool` | One HNSW query over hotel + global, `minSimilarity 0.5`, limit 5. Raises `ef_search`; uses iterative scan on pgvector ≥ 0.8. Returns `category, content, metadata, hotel_id`: no ordering by scope, no citation, no `is_active`. |
| Consumers | `GuestConciergeAgent`, `AdminAdvisorAgent`, `RecommendationAgent` | Construct `new KnowledgeSearchTool($hotel)`. |
| Global articles | `KnowledgeBaseArticleController` | A super admin creates and lists global articles (`hotel_id IS NULL`) through the **hotel-facing** routes. |
| Libraries | `composer.json` | `laravel/ai ^0.10` (agents, attachments, embeddings), `maatwebsite/excel` → `phpoffice/phpspreadsheet`. No PDF or DOCX reader. |

## R1 — One model on the existing table; extend it, don't add a second one

**Decision**: Add `App\Models\KnowledgeDocument` on `knowledge_documents` and extend the
table with a new migration (columns in [data-model.md](data-model.md)). Map the unused
`processing` default to `uploaded`. Keep `content` as the extracted text, joined for
display. Add `segments` (jsonb) as the located text the index is built from.

**Rationale**: The table was created for exactly this purpose (constitution II). No rows
exist, because nothing writes them, so the data step is trivial.

**Alternatives**: A new `documents` table, which would duplicate one that already exists.
Storing documents as `KnowledgeBaseArticle` rows with an attachment: articles have a
draft/published lifecycle and are hand-written; mixing in files muddles both.

## R2 — Located segments are the unit of extraction and citation

**Decision**: Every extractor returns a list of **segments** `{location, text}`:
- PDF: one per page (`"Page 3"`);
- XLSX: one per sheet, split into row ranges (`"Sheet Prices, rows 2–41"`);
- CSV: row ranges;
- DOCX and MD: one per heading section (`"Section: Pool"`);
- TXT: one per document;
- an image: one (`"Image"`).

Each segment is chunked separately by `TextChunker`, so every chunk has exactly one
location (stored in `knowledge_chunks.metadata.location`). The document stores its
segments in `segments` jsonb.

**Rationale**: Citations need a location per passage (FR-014, FR-025). Corrections need a
structure staff can edit without losing pages (R10). Rebuilds need stored text so they do
not re-extract or re-run AI vision (R12).

**Alternatives**: Chunk the whole text and guess pages from character offsets. This
breaks as soon as staff edit the text. Page markers inside `content` (`[[page 3]]`) are
fragile to edit and easy to corrupt.

## R3 — Extractors per format

**Decision**: Use one `App\Support\Knowledge\Extraction\Extractor` interface with one class
per format, chosen by `ExtractorRegistry` from the detected MIME type.

| Format | Extractor | Library |
| --- | --- | --- |
| PDF (text layer) | `PdfExtractor` | **`smalot/pdfparser`** (new, pure PHP). `getPages()` gives per-page text |
| PDF pages without text, images | `VisionExtractor` (R4) | `laravel/ai` document/image attachments |
| DOCX | `DocxExtractor` | `ZipArchive` + `DOMDocument` over `word/document.xml`: paragraphs, heading styles → sections, tables → `Header: value` rows. No new dependency (`ext-zip` is already required by PhpSpreadsheet) |
| XLSX | `SpreadsheetExtractor` | PhpSpreadsheet (present), read-data-only. The first non-empty row is the header row; each row becomes `Header: value; Header: value` |
| CSV | `SpreadsheetExtractor` | PhpSpreadsheet CSV reader. Delimiter sniffed from `, ; \t \|`. Encoding detected (UTF-8, UTF-16 BOM, Windows-1256, ISO-8859-1) and converted to UTF-8 |
| TXT / MD | `PlainTextExtractor` | Native. Encoding is detected as for CSV; MD headings → sections |

A page counts as **scanned** when its text-layer extraction yields fewer than 20 visible
characters after normalization.

**Rationale**:
- `smalot/pdfparser` is the most widely used pure-PHP PDF reader, so no system binary is
  needed on the servers.
- DOCX is a zip of XML, and reading paragraphs and tables does not justify PhpWord.
- PhpSpreadsheet is already installed.

**Risk noted**: smalot can return Arabic in logical order with presentation-form glyphs.
NFKC normalization (R6) fixes the glyph forms. Order problems in unusual PDFs are what
staff corrections (R10) are for. The acceptance fixtures include an Arabic PDF.

**Alternatives**:
- Poppler `pdftotext`: better Arabic, but it is a system binary on every worker, and it
  still cannot read scans.
- Sending every PDF to AI vision: correct output, but every page would cost money,
  against the clarification that only scanned pages use vision.
- PhpWord: a heavier dependency for read-only text.
- XLS and DOC: out of scope (spec Assumptions).

## R4 — AI vision for images and scanned pages

**Decision**:
- A new `App\Ai\Agents\DocumentVisionAgent` (structured output, no tools) receives the
  file as an attachment: `Image::fromStorage()` for images, `Document::fromStorage()` for
  PDFs.
- For a PDF it is told the **list of scanned page numbers** and returns
  `{pages: [{page, text, description}]}`. For an image it returns one entry.
- Instructions:
  - transcribe the visible text verbatim, in its own language (Arabic stays Arabic);
  - add at most two sentences describing the image;
  - never infer or add information (FR-015a);
  - return empty text when no text is visible.
- Provider and model come from `config('knowledge.vision.provider')` (default `gemini`,
  the provider already used for embeddings) and `knowledge.vision.model`. They are passed
  explicitly to `prompt()`, because the app's default text provider is `openai`.
- The call runs inside the indexing job's `AiCostContext`, so it is logged and attributed
  like any other call.

**Limits** (config `knowledge.vision.*`):
- `max_pages` = 50 scanned pages or images per document (FR-015b). The count happens
  **before** any call, and a document over the limit fails with
  `too_many_scanned_pages`.
- `max_file_mb` = 14. Inline attachments are base64-encoded, which adds about 33%, and
  the provider's inline request limit is about 20 MB. A larger file that needs vision
  fails with `too_large_for_vision`. Larger files with a text layer are unaffected.
- A PDF over the limit is sent once, whole, with the page list; one call per page is not
  possible without a PDF splitter.

**Rationale**: This follows clarification Q1. Gemini reads PDFs and images natively,
including Arabic, so no OCR binary is needed. Structured output gives per-page text that
fits R2 directly.

**Alternatives**:
- Splitting the PDF per page (FPDI): its free parser cannot read compressed PDFs, which
  most are.
- The provider Files API for larger files: possible later. `laravel/ai` supports
  `ProviderDocument`, but it adds lifecycle and cleanup for an edge case.

## R5 — Atomic, idempotent indexing (fixes the delete-first bug)

**Decision**: Rework `ChunkSynchronizer` into `syncSegments(source, segments, hotelId,
category, metadata)` and have `sync()` delegate to it:
1. chunk every segment;
2. call `Embeddings::for(...)` **first**, outside any transaction;
3. then, in **one transaction**: lock the source row (`lockForUpdate`), check the run is
   still current (see below), delete the source's existing chunks, insert the new ones,
   and update the source's status.

The swap is all-or-nothing, so a search never sees a half-indexed source (FR-017), and a
failure before the swap leaves the old chunks in place (FR-018, US4-4).

Concurrency (FR-016):
- `IndexKnowledgeDocumentJob` uses `WithoutOverlapping($documentId)` with `releaseAfter`,
  so two runs never overlap.
- Each run records the **input fingerprint** it started from: a hash of the file hash,
  the content source and the segments. Inside the swap transaction, the run commits only
  if the document's current fingerprint still matches. If it does not (the file was
  replaced or the text corrected meanwhile), it discards its result; the newer run owns
  the document.

Articles and policies get the same atomic swap through `sync()`.

**Rationale**: Re-embedding happens outside the transaction, so no transaction is held
open across a provider call. A lock plus a fingerprint is the standard
"last-writer-is-the-newest-input" rule.

**Alternatives**:
- A generation column on chunks, with search filtered to the current generation. This
  needs a join from chunks to every polymorphic source on every search.
- `ShouldBeUnique` alone drops a run queued while one is in flight, so a correction made
  during indexing would be lost.

## R6 — Normalization

**Decision**: `TextNormalizer` applies, in order:
1. convert to UTF-8;
2. Unicode NFKC (`Normalizer`, ext-intl), which turns Arabic presentation forms back into
   letters;
3. strip control characters except `\n` and `\t`;
4. normalize line endings and collapse runs of spaces;
5. collapse three or more blank lines into one;
6. remove repeated header and footer lines: a line that is the first or last non-empty
   line on at least 60% of pages, in documents of 3 or more pages (FR-013);
7. trim.

Tatweel and diacritics are kept, because they can change meaning in names.

**Rationale**: These are cheap, deterministic rules. Determinism matters, because the
same input must give the same chunks (SC-007).

**Alternatives**: Asking an LLM to clean up the text. That is non-deterministic, costs
money, and risks altering content.

## R7 — Upload validation and type detection

**Decision**: `StoreKnowledgeDocumentRequest`:
- `file` must be `required|file|max:<knowledge.max_upload_kb, default 20480>` and pass
  `mimetypes:` (content-based, via finfo) **and** `extensions:` for the allow-list.
- A mismatch between the detected type and the extension is rejected (FR-003).
- DOCX and XLSX may be detected as `application/zip`. In that case the request opens the
  zip and checks for `word/document.xml` or `xl/workbook.xml`.
- Encrypted OOXML files are CFB containers (`application/x-ole-storage`); they are
  rejected as `encrypted` at upload time.
- `title` is required, max 255. `category` is a nullable `KnowledgeBaseCategory` value,
  reused so documents, articles and policies share one category list.

Duplicates (FR-005): the request computes a SHA-256 `content_hash`. The service rejects a
match among non-deleted documents in the same scope with `409`, returning the existing
document's id and title. Partial unique indexes back this (data model). Deleted documents
are excluded (FR-010b).

**Alternatives**: Trusting the client's MIME type (spoofable). A separate validation
step after storing the file: the spec says nothing is stored for a rejected upload.

## R8 — Storage

**Decision**:
- Use the private `local` disk (root `storage/app/private`), set by
  `config('knowledge.disk')` so S3 can be swapped in later.
- Path: `knowledge/{hotel_id|global}/{document_id}/{uuid}.{ext}`.
- Files are never in `public` and are served only by the authorized `download` endpoint,
  which uses `Storage::download` and the original file name (FR-011).
- Extraction reads the file and never writes, moves or deletes it (FR-018).
- Files are deleted only in three cases:
  - by the purge (R11);
  - when a replacement's index swap commits (the old file);
  - when a pending replacement is superseded by another replacement.

**Alternatives**: Signed temporary URLs. They are unnecessary for an API client, and they
leak if forwarded.

## R9 — Replacing a file keeps the old version live

**Decision**:
- A replacement is stored as **pending columns** on the document (`pending_path`,
  `pending_original_filename`, `pending_mime_type`, `pending_size`,
  `pending_content_hash`). Indexing processes the pending file.
- On a successful swap, the pending columns are promoted to the main ones, the old file is
  deleted after commit, and `content_source` resets to `extracted`. A replacement drops
  corrections (FR-015d).
- On a **permanent** failure (corrupt, encrypted, no text, duplicate…), the pending file
  is discarded, so later re-indexes, corrections and rebuilds work on the live version
  instead of retrying a file that can never be read. On a temporary failure or the usage
  limit, it is kept so a re-index can retry it. Either way the status is `failed` with a
  reason, and the **old chunks stay searchable** (FR-008). A stored-text
  (`from_segments`) run never reads a pending file. *(Changed after code review.)*
- Download always returns the current, promoted file.

**Rationale**: The spec needs exactly one previous version, kept only until the new one
indexes. A versions table is more than that.

**Alternatives**: A `knowledge_document_versions` table (version history is not asked
for), or overwriting the file in place (loses the live version on failure).

## R10 — Staff corrections

**Decision**:
- `PUT /knowledge-documents/{id}/text` takes `segments: [{location, text}]`. It must have
  the same locations, in the same order, as the stored segments; only `text` may change,
  and an empty text drops that segment from the index.
- The service stores the edit, sets `content_source = corrected`, `corrected_by` and
  `corrected_at`, refreshes `content`, and queues indexing **from segments**, with no
  extraction and no vision.
- `DELETE /knowledge-documents/{id}/text` discards the corrections: it sets
  `content_source = extracted` and queues a full re-extraction from the stored file.
- Re-index and rebuild use the stored segments, and so keep corrections (FR-015d).
  Replacing the file drops them (R9).

**Rationale**: Fixed locations keep citations correct after an edit. Editing per segment
maps onto a per-page editor in the frontend.

**Alternatives**: One free-text blob (locations lost), or letting staff add or remove
locations (citation drift, with little benefit).

## R11 — Delete, restore, purge

**Decision**:
- Delete is a soft delete. The service sets `is_active = false` on the document's chunks
  in the same transaction; they are kept for a restore.
- Restore (within 30 days, `delete` permission) un-deletes the document and sets its
  chunks active again if the document is active. There is no re-extraction.
- `PurgeDeletedKnowledgeDocumentsJob` runs daily at 04:15 (`routes/console.php`) and, per
  document deleted more than 30 days ago (`knowledge.purge_after_days`):
  1. records `knowledge_document.purged`;
  2. deletes the chunks;
  3. force-deletes the row;
  4. deletes the files (main and pending) after commit.
- The job runs `TenantContext::withoutScope()` on purpose: it is a platform sweep, and
  each step names the row explicitly.
- The audit entries stay, because `event_logs` keep `subject_id` with no FK.

**Rationale**: This matches clarification Q (delete) and the repo's `SoftDeletes`
convention.

**Alternatives**: Deleting the chunks on delete. A restore would then pay for
re-embedding, and SC-005 is met either way.

## R12 — Rebuilds

**Decision**:
- A new `knowledge_index_rebuilds` row records the scope (`all` or one `hotel_id`), the
  requester, a status, counts (`total`, `succeeded`, `failed`) and `failures` (jsonb,
  `[{source_type, source_id, title, reason}]`).
- `POST /api/admin/knowledge/rebuilds` creates the row, enumerates the sources in scope
  and dispatches one job per source, carrying the rebuild id:
  - active, non-deleted documents → `IndexKnowledgeDocumentJob`, mode `from_segments`, or
    `extract` when the document has no segments;
  - published articles and active policies → `SyncKnowledgeChunksJob`.
- Each job increments its counter with one atomic `UPDATE ... SET succeeded = succeeded
  + 1`. The row is `completed` when `succeeded + failed = total`.
- Search keeps working during a rebuild, because each source swaps atomically (R5).
  Failed sources keep their old chunks.

Also: `php artisan knowledge:sync` (existing) gains `--hotel=` and `--documents`, and
goes through the same rebuild service, so ops can rebuild from the CLI.

**Alternatives**: `Bus::batch` for progress. Our failures are "the document failed
cleanly", not job exceptions, so batch counters would report them as successes.

## R13 — Retrieval: hotel first, two queries, active only

**Decision**: `KnowledgeSearchTool` embeds the query once, then runs **two** filtered HNSW
queries in the existing widened-search transaction:
- hotel chunks (`hotel_id = :hotel`, `is_active`), limit 5;
- global chunks (`hotel_id IS NULL`, `is_active`), limit 3.

Both use `minSimilarity 0.5`. Results are returned as **hotel results first, then global**,
each group by similarity (FR-023, FR-024). Two hotel results from different sources keep
similarity order. The tool's instructions say the newer `last_updated` wins when two
hotel sources disagree (Edge Cases).

`knowledge_chunks.is_active` (new, default true, partial index) is the switch for
deactivate, delete and restore. It flips in the same request, which meets SC-005 without
re-embedding.

**Rationale**:
- Separate queries guarantee hotel results are never squeezed out of the result set by
  closer global ones. The old single-query approach could return five global chunks and
  no hotel ones.
- The explicit `hotel_id` filter keeps isolation exact without the tenant scope. The
  existing comment explains why that scope must be lifted.

**Alternatives**:
- One query with `ORDER BY (hotel_id IS NULL), distance`. This defeats the HNSW index,
  because the ordering is not by distance alone.
- A similarity boost for hotel results. That is still a ranking, not a guarantee.

## R14 — Citations, resolved at search time; guest variant enforced in code

**Decision**:
- Each result is one JSON object:
  `{scope: "hotel"|"general", source_type: "document"|"article"|"policy", title,
  location, last_updated, content}`.
- `title` and `last_updated` are read **from the source row at search time**: one
  eager-loaded `chunkable` morph per page of results, at most 8 rows. An edited title
  therefore shows right away with no re-index (FR-007). `location` comes from the chunk
  metadata.
- The tool takes a `KnowledgeAudience` (`staff` or `guest`):
  - `GuestConciergeAgent` builds `KnowledgeSearchTool($hotel, KnowledgeAudience::GUEST)`.
  - For guests, global results have `title` and `location` set to null and
    `source_type: "general"`. The model **cannot** name a global title it never
    received (FR-026, clarification Q6).
  - The guest variant never returns file names, ids, storage paths or uploader.
- Agent instructions are updated:
  - **Concierge**: cite hotel sources by title in the guest's language; call global ones
    "general information"; follow hotel over general on conflict; if nothing is found, say
    so and offer staff.
  - **Admin Advisor**: cite title + location + date.
  - **Recommendation**: hotel over general.

**Rationale**: Clarification Q6 is a privacy rule, so it belongs in code, not only in the
prompt (constitution V). Live resolution keeps one source of truth for titles.

**Alternatives**: Copying the title into chunk metadata (stale after a rename, needs a
re-write on every edit). Leaving the hiding to the prompt (unenforced).

## R15 — Permissions and routes

**Decision**: New `Permission` cases, grouped after `HOTEL_POLICIES_*`:
- `knowledge_documents.view`: list, show, download, view text;
- `knowledge_documents.create`: upload;
- `knowledge_documents.update`: edit metadata, replace, activate/deactivate,
  correct/discard text;
- `knowledge_documents.delete`: delete, list deleted, restore;
- `knowledge_documents.reindex`: re-index one document.

None goes in `employeeDefaults()` (FR-036). Rebuilds and global knowledge stay
super-admin only and are **not** enum cases. They live in `routes/admin.php` under the
`super_admin` stack (FR-031), as CLAUDE.md rule 4 asks for anything that changes what
every tenant's assistant says.

`KnowledgeDocumentPolicy` uses `ChecksPermissions::allows()`. Its `before()` denies every
global record to everyone, and otherwise lets a super admin through. Without that,
`allows()` would compare the record's hotel with the super admin's null hotel and deny
them. The hotel-facing controller always resolves a hotel:
- scoped users get their own;
- a super admin must pass `hotel_id` (`resolveHotel()`);
- rows with `hotel_id IS NULL` are a 404 on hotel routes, so hotel staff can never touch
  global sources (FR-034).

**Rationale**: This follows the CLAUDE.md permission procedure exactly. Restore under
`delete` follows the spec's wording, and download under `view` follows FR-006.

**Alternatives**: Reusing `knowledge_base_articles.*`. Uploading files is a different
power from typing articles, and hotels will grant them to different roles.

## R16 — Global management and the article breaking change

**Decision**: `routes/admin.php` gains:
- `knowledge-documents` (the same actions as the hotel routes, scope global);
- `knowledge-base-articles` (CRUD on `hotel_id IS NULL`);
- `knowledge/rebuilds`.

Admin controllers live in `app/Http/Controllers/Admin/`. They share
`App\Services\Knowledge\KnowledgeDocumentService` with the hotel controller, which is
why it is a service (two callers).

`KnowledgeBaseArticleController` (hotel routes) changes:
- a super admin must name `hotel_id` on store and index (`resolveHotel()`); without one
  the request is `422`;
- global articles are no longer listed or created there (FR-033).

Existing global articles need no data change: `hotel_id IS NULL` already marks them, and
they appear in the admin list (US6-4). This is a **breaking change** for any client that
managed global articles through `/knowledge-base-articles`, and goes in
`docs/latest-changes-2026-10-07.md`.

**Alternatives**: Keeping the old super-admin path as well. Two ways to write global
knowledge, one of them outside the guarded file, is what SPEC-065 removes.

## R17 — Fix the `nullOnDelete` tenant leak

**Decision**: A migration changes the `hotel_id` foreign keys of `knowledge_chunks`,
`knowledge_documents` and `knowledge_base_articles` from `nullOnDelete` to
`cascadeOnDelete`.

**Rationale**: `hotel_id IS NULL` means *global* in all three tables. Force-deleting a
hotel (hotels are soft-deleted today, but a force delete or a manual SQL delete is
possible) would turn that hotel's private knowledge into knowledge **every** hotel's
assistant reads. That breaks constitution III and the spec's edge case "a hotel is
deleted … global knowledge is not affected". `hotel_policies` already cascades.

Document files orphaned by such a cascade are removed by an `orphans` pass in the purge
job: files under `knowledge/{hotel_id}/` whose hotel no longer exists.

**Alternatives**: Leaving the foreign keys alone and relying on soft deletes. A latent
cross-tenant leak is not acceptable.

## R18 — Usage metering and attribution

**Decision**:
- Indexing runs inside `AiCostContext::for(SCHEDULED_JOB, hotel: $document->hotel,
  trigger: $document)`, so embedding and vision calls are logged with the hotel's
  account. For global documents the hotel and account are null, which the usage log
  already treats as platform cost.
- Metering, hotel documents only, through `MeteringService::safely()`:
  - `embeddings_generated` (existing), quantity = chunks;
  - new **`knowledge_pages_read`** (`cost_driver`, unit `pages`, label "Knowledge pages
    read by AI"), quantity = scanned pages or images sent to vision.
- Global sources record no meter event, because meter events need an account; the usage
  log still has the cost.
- `AiSpendCeilingExceededException` fails the document with `usage_limit_reached`. It is
  not retried, so it can be re-indexed later.

**Rationale**: This follows FR-039 and the existing metering conventions. Feature codes
are permanent, so the new one is added, not reused.

**Alternatives**: Counting vision under `embeddings_generated`, which mixes two cost
drivers with different prices.

## R19 — Job design, retries and status

**Decision**:
- `IndexKnowledgeDocumentJob(documentId, mode: extract|from_segments, rebuildId?)`;
  `maxExceptions = 4` (the first run plus 3 retries, FR-018), with `tries = 0` and
  `retryUntil()` 1 hour, `backoff = [30, 120, 300]`, `timeout = 300`,
  `failOnTimeout = true`. `maxExceptions`, not `tries`: a run released by
  `WithoutOverlapping` would otherwise use up a retry and end up marked `failed`.
- It runs `TenantContext::runForHotel($doc->hotel_id)`, or `withoutScope()` for global
  documents, after loading by id with the scope lifted.
- Status: `uploaded` → `extracting` at the start of the run, then `indexed` (on swap) or
  `failed` (with `failure_code`). The internal error goes to `error` for staff and support
  and is never sent to guests.
- **Permanent** failures call `$this->fail()` without retry: `encrypted`, `corrupt`,
  `duplicate` (a unique violation when the replacement is promoted),
  `no_text`, `too_many_scanned_pages`, `too_many_rows`, `too_large_for_vision`,
  `file_missing`, `usage_limit_reached`.
- **Transient** failures rethrow (provider or HTTP errors, timeouts). `failed()` records
  `extraction_unavailable` or `embedding_unavailable` after the last try.
- The job is dispatched `afterCommit()` by the service.
- A deleted document found at the start or at the swap ends the run with no chunks
  (Edge Cases).
- A document deactivated during the run gets its new chunks written with
  `is_active = false`.

Plain-language reasons are `lang/{en,ar}/knowledge.php` keys per `failure_code`, returned
as `failure_message` in the resource.

**Alternatives**: Storing the message text. It cannot be translated, and the wording
changes over time.

## R20 — Tests and fixtures

**Decision**:
- Small binary fixtures go in `tests/Fixtures/knowledge/`: a PDF with a text layer, a
  scanned PDF, a protected PDF, a DOCX, an XLSX with 2 sheets, a semicolon CSV in
  Windows-1256, MD, TXT, a PNG, a corrupt PDF, and an Arabic PDF.
- `Embeddings::fake()` is used as in `KnowledgeSearchToolTest`, plus the agent's fake
  for `DocumentVisionAgent`.
- `Storage::fake('local')`; `Queue::fake` for dispatch assertions; the sync queue for
  pipeline tests.

The test files are listed in [quickstart.md](quickstart.md).

**Rationale**: Testing at the domain boundary (constitution VIII), with no real provider
calls in CI.
