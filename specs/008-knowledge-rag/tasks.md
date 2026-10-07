---

description: "Task list for Knowledge Documents and RAG (Phase 8)"
---

# Tasks: Knowledge Documents and RAG

**Input**: Design documents from `specs/008-knowledge-rag/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: Included. The constitution (VIII, Definition of Done) and CLAUDE.md require
feature tests for every new behaviour, a tenant-isolation test for every tenant-scoped
feature, and an allowed/403 test for every new permission. Write each story's tests
first and confirm they fail before implementing.

**Organization**: Tasks are grouped by user story in priority order: P1 (US1, US2, US3),
then P2 (US4, US5, US6), then P3 (US7, US8). R-numbers refer to
[research.md](research.md).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: The user story the task belongs to (US1–US8)

## Conventions for every task

- PHP 8.3 / Laravel 13, UUID keys, `SoftDeletes`, explicit `$fillable` and `$casts`.
- Controllers stay thin: authorize → validate → delegate to
  `App\Services\Knowledge\KnowledgeDocumentService` → `apiResponse()` with a Resource.
- Jobs load by id with the scope lifted, then run in
  `TenantContext::runForHotel($doc->hotel_id)`. Global documents (`hotel_id = null`)
  run in `TenantContext::withoutScope()`.
- No code path writes to, moves or deletes a stored file, except the three cases in R8.
- Tests are Pest files with `uses(RefreshDatabase::class)` and the `beforeEach` API-key
  setup from CLAUDE.md. Shared fixtures go in `tests/Pest.php`, prefixed `kn`. They run
  on real Postgres and never call a real AI provider.
- Run `./vendor/bin/pint` before committing.

---

## Phase 1: Setup

**Purpose**: Start from a green base and add the one dependency and the fixtures.

- [X] T001 Create branch `008-knowledge-rag` from `main` and run `php artisan test --parallel`. It must be green before any change. Confirm `app/Support/Knowledge/ChunkSynchronizer.php`, `app/Ai/Tools/KnowledgeSearchTool.php` and the `knowledge_documents` table exist.
- [X] T002 Run `composer require smalot/pdfparser` (R3). Confirm `php -m` lists `intl`, `zip` and `fileinfo`, and add them to the `require` block of `composer.json` as `ext-intl`, `ext-zip` and `ext-fileinfo`.
- [X] T003 [P] Create binary fixtures in `tests/Fixtures/knowledge/` (R20), each under 200 KB:
  - `text.pdf`: 3 pages, each with a repeated header "Grand Hotel — House Rules" and a page-specific body; page 2 contains "The pool is open until 22:00".
  - `arabic.pdf`: 1 page of Arabic text.
  - `scanned.pdf`: 2 image-only pages.
  - `mixed.pdf`: page 1 text, page 2 image-only.
  - `protected.pdf`: password-protected.
  - `corrupt.pdf`: truncated bytes.
  - `menu.docx`: 2 headings ("Breakfast", "Dinner") and one table.
  - `shuttle.xlsx`: sheet "Times" with header row `Route, Time, Gate` and sheet "Empty", which is blank.
  - `prices-1256.csv`: `;`-separated, Windows-1256 encoded, with an Arabic header.
  - `notes.md`: 2 `##` sections.
  - `plain.txt`.
  - `sign.png`: an image of printed text.
  - `fake.pdf`: a PNG renamed to `.pdf`.
- [X] T004 [P] Add shared fixtures to `tests/Pest.php`:
  - `knHotel(): array` returns `[$admin, $hotel]`.
  - `knEmployee(Hotel $hotel, array $permissions): User` creates an employee with a `StaffRole` granting exactly `$permissions`.
  - `knFakeEmbeddings(): void` wraps `Embeddings::fake(...)` with the deterministic vectors used in `tests/Feature/KnowledgeSearchToolTest.php`.
  - `knUpload(string $fixture): UploadedFile` builds an upload from `tests/Fixtures/knowledge/`.
  - `knDocument(?Hotel $hotel, array $attributes = []): KnowledgeDocument` creates a document with status `indexed`, segments and chunks, without running the pipeline.
  - `knFakeVision(array $pages): void` fakes `DocumentVisionAgent` structured output.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Config, enums, schema, models, permissions and the shared indexing core that
every story uses.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T005 [P] Create `config/knowledge.php` with these keys and defaults (data-model "Config"):
  - `disk` `env('KNOWLEDGE_DISK','local')`
  - `max_upload_kb` 20480
  - `max_rows` 20000
  - `vision.provider` `'gemini'`
  - `vision.model` `null`
  - `vision.max_pages` 50
  - `vision.max_file_mb` 14
  - `vision.timeout` 120 (seconds; below the job's 300)
  - `search.hotel_limit` 5
  - `search.global_limit` 3
  - `search.min_similarity` 0.5
  - `purge_after_days` 30
- [X] T006 [P] Create `app/Enums/KnowledgeDocumentStatus.php` (`UPLOADED='uploaded'`, `EXTRACTING='extracting'`, `INDEXED='indexed'`, `FAILED='failed'`) and `app/Enums/KnowledgeContentSource.php` (`EXTRACTED='extracted'`, `CORRECTED='corrected'`).
- [X] T007 [P] Create `app/Enums/KnowledgeDocumentFailure.php`:
  - Cases: `UNSUPPORTED_TYPE`, `ENCRYPTED`, `CORRUPT`, `DUPLICATE`, `NO_TEXT`, `TOO_MANY_SCANNED_PAGES`, `TOO_MANY_ROWS`, `TOO_LARGE_FOR_VISION`, `FILE_MISSING`, `USAGE_LIMIT_REACHED`, `EXTRACTION_UNAVAILABLE`, `EMBEDDING_UNAVAILABLE`, with snake_case values.
  - `isPermanent(): bool` is true for every case except `EXTRACTION_UNAVAILABLE` and `EMBEDDING_UNAVAILABLE`.
  - `message(?string $locale = null): string` reads `__('knowledge.failures.'.$this->value)`.
- [X] T008 [P] Create the label enums:
  - `app/Enums/KnowledgeSourceType.php` (`DOCUMENT='document'`, `ARTICLE='article'`, `POLICY='policy'`, plus `static fromModel(Model $m): self`);
  - `app/Enums/KnowledgeScope.php` (`HOTEL='hotel'`, `GENERAL='general'`);
  - `app/Enums/KnowledgeAudience.php` (`STAFF='staff'`, `GUEST='guest'`).
- [X] T009 [P] Create the rebuild enums: `app/Enums/KnowledgeRebuildScope.php` (`ALL='all'`, `HOTEL='hotel'`, `GLOBAL='global'`) and `app/Enums/KnowledgeRebuildStatus.php` (`RUNNING='running'`, `COMPLETED='completed'`).
- [X] T010 [P] Create `lang/en/knowledge.php` and `lang/ar/knowledge.php`:
  - one `failures.<code>` message per `KnowledgeDocumentFailure` value, in plain language, for example `encrypted` → "The file is password-protected. Remove the password and upload it again." and `too_many_scanned_pages` → "This document has more than :max scanned pages, which is the limit for reading scans.";
  - a `duplicate` message: "This file was already uploaded as “:title”."
- [X] T011 Create migration `database/migrations/2026_10_07_000001_extend_knowledge_documents_table.php`, per data-model "KnowledgeDocument":
  - New columns:
    - `content_hash char(64)` (nullable at first, then filled and set NOT NULL; the table is empty);
    - `segments jsonb null`;
    - `content_source string(16) default 'extracted'`;
    - `corrected_by uuid null FK users nullOnDelete`, `corrected_at timestampTz null`;
    - `failure_code string(40) null`;
    - `is_active boolean default true`;
    - `page_count int null`, `scanned_page_count int default 0`, `chunk_count int default 0`;
    - `index_fingerprint char(64) null`, `indexed_at timestampTz null`;
    - `pending_path`, `pending_original_filename`, `pending_mime_type` (string null), `pending_size bigint null`, `pending_content_hash char(64) null`.
  - Change the `status` default to `'uploaded'`, then run `update knowledge_documents set status='uploaded' where status='processing'`.
  - Partial unique indexes:
    - `unique (hotel_id, content_hash) where deleted_at is null and hotel_id is not null`;
    - `unique (content_hash) where deleted_at is null and hotel_id is null`.
  - Indexes `(hotel_id, status)`, `(hotel_id, is_active)` and `(deleted_at)`.
  - Checks:
    - `status in ('uploaded','extracting','indexed','failed')`;
    - `content_source in ('extracted','corrected')`;
    - `(status = 'failed') = (failure_code is not null)`;
    - `(content_source = 'corrected') = (corrected_at is not null)`.
  - Write `down()`.
- [X] T012 [P] Create migration `database/migrations/2026_10_07_000002_add_is_active_to_knowledge_chunks_table.php`: add `is_active boolean not null default true` and the partial index `create index … on knowledge_chunks (hotel_id) where is_active`. Write `down()`.
- [X] T013 [P] Create migration `database/migrations/2026_10_07_000003_create_knowledge_index_rebuilds_table.php`:
  - Columns:
    - `id uuid PK`;
    - `scope string(16)`;
    - `hotel_id uuid null FK hotels cascadeOnDelete`;
    - `requested_by uuid null FK users nullOnDelete`;
    - `status string(16) default 'running'`;
    - `total int default 0`, `succeeded int default 0`, `failed int default 0`;
    - `failures jsonb default '[]'`;
    - `started_at timestampTz`, `finished_at timestampTz null`;
    - timestamps.
  - Checks:
    - `(scope = 'hotel') = (hotel_id is not null)`;
    - `succeeded + failed <= total`.
- [X] T014 [P] Create migration `database/migrations/2026_10_07_000004_cascade_knowledge_hotel_foreign_keys.php` (R17): drop and re-add the `hotel_id` foreign keys of `knowledge_chunks`, `knowledge_documents` and `knowledge_base_articles` with `cascadeOnDelete()`, keeping the columns nullable. `down()` restores `nullOnDelete()`.
- [X] T015 Create `app/Models/KnowledgeDocument.php`:
  - Traits `BelongsToHotel`, `Filterable`, `HasUuids`, `RecordsEvents`, `SoftDeletes`; `$keyType='string'`, `$incrementing=false`.
  - `$fillable` covers every column in data-model except `id` and timestamps.
  - `$casts`: `category`→`KnowledgeBaseCategory`, `status`→`KnowledgeDocumentStatus`, `failure_code`→`KnowledgeDocumentFailure`, `content_source`→`KnowledgeContentSource`, `segments`→`array`, `is_active`→`boolean`, `corrected_at` / `indexed_at`→`datetime`, `size` / `pending_size` / `page_count` / `scanned_page_count` / `chunk_count`→`integer`.
  - `$hidden`: `disk`, `path`, `pending_path`, `content_hash`, `pending_content_hash`, `index_fingerprint`.
  - Relations: `hotel()`, `uploader()` (belongsTo User, `uploaded_by`), `corrector()` (`corrected_by`), `chunks()` (morphMany KnowledgeChunk, `chunkable`).
  - Helpers:
    - `isGlobal(): bool`;
    - `hasPendingReplacement(): bool`;
    - `inputFingerprint(): string`, a sha256 of `pending_content_hash ?? content_hash`, `content_source->value` and `json_encode(segments)` when `content_source` is corrected (R5);
    - `restorableUntil(): ?Carbon`.
  - The audited attributes for `RecordsEvents` are `title`, `category`, `is_active`, `status`, `failure_code` and `content_source`.
  - A `saving` hook sets `failure_code = null` whenever `status` is not `failed`. Without it, moving a failed document back to `uploaded` or `extracting` violates the check `(status = 'failed') = (failure_code is not null)`. Callers still set both explicitly; the hook is the safety net. Test it in T024 or T049.
- [X] T016 [P] Update `app/Models/KnowledgeChunk.php`: add `is_active` to `$fillable` and cast it `boolean`.
- [X] T017 [P] Create `app/Models/KnowledgeIndexRebuild.php`:
  - `HasUuids`, **no** `BelongsToHotel` (a platform record);
  - casts: `scope`→`KnowledgeRebuildScope`, `status`→`KnowledgeRebuildStatus`, `failures`→`array`, `started_at` / `finished_at`→`datetime`;
  - relations `hotel()` and `requester()`;
  - `recordOutcome(bool $succeeded, ?array $failure = null): void` does one atomic `UPDATE … SET succeeded = succeeded + 1` (or `failed + 1`, appending to `failures` with `failures || ?::jsonb`), then sets `status='completed', finished_at=now()` when `succeeded + failed = total`, all in a single statement or a locked transaction.
- [X] T018 Add 5 cases to `app/Enums/Permission.php`, grouped after `HOTEL_POLICIES_*` (R15):
  - `KNOWLEDGE_DOCUMENTS_VIEW='knowledge_documents.view'`;
  - `KNOWLEDGE_DOCUMENTS_CREATE='knowledge_documents.create'`;
  - `KNOWLEDGE_DOCUMENTS_UPDATE='knowledge_documents.update'`;
  - `KNOWLEDGE_DOCUMENTS_DELETE='knowledge_documents.delete'`;
  - `KNOWLEDGE_DOCUMENTS_REINDEX='knowledge_documents.reindex'`.

  Do **not** add any to `employeeDefaults()`. Add a line to its docblock saying knowledge documents are never a default, because they change what the guest AI says.
- [X] T019 Create `app/Policies/KnowledgeDocumentPolicy.php` using `ChecksPermissions`. `ChecksPermissions::allows()` compares `$record->hotel_id` with `$user->hotel?->id`, which is always null for a super admin. Add a `before(User $user, string $ability, mixed $document = null): ?bool`:
  - it returns `false` when `$document instanceof KnowledgeDocument && $document->isGlobal()`, for every user (FR-034);
  - otherwise it returns `true` for a super admin (the controller has already pinned them to the hotel they named);
  - otherwise it returns `null`.

  Abilities:
  - `viewAny` / `view` / `download` / `viewText` → `KNOWLEDGE_DOCUMENTS_VIEW`;
  - `create` → `CREATE`;
  - `update` / `replace` / `correctText` / `discardText` → `UPDATE`;
  - `delete` / `viewDeleted` / `restore` → `DELETE`;
  - `reindex` → `REINDEX`.

  Each check uses `$this->allows($user, Permission::X, $document)`. Register the policy if the app does not auto-discover it. Add a test to T025: a super admin with `hotel_id` can show, update and download that hotel's document.
- [X] T020 [P] Add `KNOWLEDGE_PAGES_READ = 'knowledge_pages_read'` to `app/Enums/MeterFeature.php` (R18), with `unit()` → `'pages'`, `category()` → `'cost_driver'` and `label()` → `'Knowledge pages read by AI'`.
- [X] T021 [P] Create the extraction primitives in `app/Support/Knowledge/Extraction/`:
  - `Segment.php`: readonly value object `{string $location, string $text}`;
  - `ExtractionResult.php`: `{list<Segment> $segments, ?int $pageCount, list<int> $scannedPageNumbers}` (1-based page numbers that have no text layer, used by vision in US8), with `scannedPageCount(): int` returning `count($scannedPageNumbers)`;
  - `ExtractionFailed.php`: an exception carrying `KnowledgeDocumentFailure $failure` and an internal `$detail`;
  - `Extractor.php`: interface `supports(string $mimeType): bool` and `extract(string $absolutePath, string $mimeType): ExtractionResult`.
- [X] T022 [P] Create `app/Support/Knowledge/TextNormalizer.php` (R6). `normalize(string $text): string` runs, in order:
  1. convert to UTF-8;
  2. `Normalizer::normalize($t, Normalizer::FORM_KC)`;
  3. strip control characters except `\n` and `\t`;
  4. normalize line endings to `\n`;
  5. collapse runs of spaces and tabs;
  6. collapse 3+ blank lines into 1;
  7. trim.

  `stripRepeatedEdges(list<Segment>): list<Segment>` removes a first or last non-empty line that appears on ≥ 60% of segments, in documents of ≥ 3 segments. Keep tatweel and diacritics.
- [X] T023 Rework `app/Support/Knowledge/ChunkSynchronizer.php` (R5):
  - Add `syncSegments(Model $source, array $segments, ?string $hotelId, ?string $category, array $metadata, ?Closure $guard = null, ?Closure $afterSwap = null): ?int`, which:
    - chunks each segment separately with `TextChunker::chunk()` and records `metadata.location` per chunk;
    - embeds **all** chunks first, outside a transaction;
    - then, in `DB::transaction`:
      - locks the source with `lockForUpdate()` and re-reads it;
      - runs `$guard($locked)`; when it returns false (the run is stale or the source is soft-deleted), nothing is written and the method returns `null`;
      - deletes the existing chunks for the morph;
      - inserts the new ones with `metadata.source_type = KnowledgeSourceType::fromModel($source)->value` and `is_active` read from the **locked row** (`$locked->is_active ?? true`), so a deactivation made during the run is respected (spec Edge Case);
      - runs `$afterSwap($locked, $count)` inside the same transaction, for the caller's status update;
    - returns the chunk count.
  - Keep `sync()` with its current signature and `int` return type, delegating to `syncSegments()` with one segment whose location is `null` and returning `?? 0`.
  - Add `setActive(Model $source, bool $active): void` (one `UPDATE … where chunkable_type/chunkable_id`).
- [X] T024 Extend `tests/Feature/KnowledgeChunkSyncTest.php`:
  - an embedding exception leaves an article's existing chunks in place (the old code deleted them first);
  - new chunks carry `metadata.source_type` (`article` / `policy`) and `location = null`;
  - a `$guard` returning false writes nothing and returns `null`;
  - a source whose `is_active` flips to false between the embedding and the swap gets its new chunks written with `is_active = false`.

**Checkpoint**: The schema is migrated, the models and permissions exist, and the
indexing core is atomic. User story work can begin.

---

## Phase 3: User Story 1 - A hotel uploads a document and the Concierge answers from it (Priority: P1) 🎯 MVP

**Goal**: Staff with `knowledge_documents.create` upload a PDF, DOCX, XLSX, CSV, TXT or MD
file; it is extracted into located segments, indexed, and found by that hotel's
knowledge search.

**Independent Test**: Upload one fixture of each text format. Each reaches `indexed`, and a
phrase from each is returned by `KnowledgeSearchTool` for that hotel.

### Tests for User Story 1 ⚠️

- [X] T025 [P] [US1] Create `tests/Feature/KnowledgeDocumentControllerTest.php`:
  - Upload `text.pdf` with `title`. Expect `201`, `status: uploaded`, `IndexKnowledgeDocumentJob` queued after commit, and the file stored under `knowledge/{hotel_id}/{id}/`.
  - `fake.pdf` → `422` "type does not match", and nothing stored (no row, no file).
  - A 21 MB file → `422`.
  - A `.exe` → `422`.
  - The same file twice → `409` with `body.existing.id`.
  - The same file at another hotel → `201`.
  - List filters `status`, `category`, `is_active` and title search work.
  - `show` hides `path`, `disk` and the hashes.
  - `download` streams the file with `original_filename`.
  - A super admin without `hotel_id` → `422`, and with it → `201` for that hotel.
- [X] T026 [P] [US1] Create `tests/Feature/KnowledgeDocumentExtractionTest.php`, running the job on the sync queue with `knFakeEmbeddings()`:
  - `text.pdf` gives 3 segments `Page 1..3`, with the repeated header stripped and "The pool is open until 22:00" in the `Page 2` chunk metadata location.
  - `arabic.pdf` gives NFKC-normalized Arabic.
  - `menu.docx` gives sections `Section: Breakfast` and `Section: Dinner`, and its table rows render as `Header: value`.
  - `shuttle.xlsx` gives rows as `Route: …; Time: …; Gate: …` under location `Sheet Times, rows 2–N`, and the "Empty" sheet is skipped.
  - `prices-1256.csv` gives UTF-8 Arabic headers, split on `;`.
  - `notes.md` gives 2 sections; `plain.txt` gives 1 segment.
  - The status goes `uploaded → extracting → indexed`, with `chunk_count`, `page_count` and `indexed_at` set.
  - `protected.pdf` → `failed` with `failure_code = encrypted` and no retry.
  - `corrupt.pdf` → `failed` with `failure_code = corrupt`.
  - An empty `plain.txt` → `no_text`.
  - A CSV over `max_rows` → `too_many_rows`.
  - After every failure, the stored file's sha256 equals the upload's (SC-006).
- [X] T027 [P] [US1] Extend `tests/Feature/TenantIsolationTest.php`: a hotel B admin gets `404` on hotel A's document for show, download, update, file, text (GET, PUT, DELETE), reindex, delete and restore, and hotel A's documents never appear in hotel B's list.
- [X] T028 [P] [US1] Add every `/knowledge-documents` endpoint to the dataset in `tests/Feature/PermissionAuthorizationTest.php` with its permission from [contracts/knowledge-documents-api.md](contracts/knowledge-documents-api.md). Assert that an employee is allowed with the permission, gets `403` without it, and that an employee without a role gets `403` (FR-035, FR-036).

### Implementation for User Story 1

- [X] T029 [P] [US1] Create `app/Support/Knowledge/Extraction/PdfExtractor.php` with `smalot/pdfparser`:
  - one `Segment('Page N', text)` per page, each passed through `TextNormalizer::normalize()`;
  - a page with fewer than 20 visible characters is **scanned**; record its page number in `ExtractionResult::$scannedPageNumbers` (consumed by US8), and until US8 lands, scanned pages are skipped;
  - "Secured pdf" or encryption errors → `ExtractionFailed(ENCRYPTED)`; parse errors → `ExtractionFailed(CORRUPT)`;
  - `pageCount` = the number of pages.
- [X] T030 [P] [US1] Create `app/Support/Knowledge/Extraction/DocxExtractor.php`:
  - opens `word/document.xml` with `ZipArchive` and reads it with `DOMDocument`;
  - each `w:p` with `w:pStyle` `Heading*` or `Title` starts a new `Segment('Section: <heading text>')`, and text before the first heading goes to `Segment('Section: Introduction')`;
  - each `w:tbl` takes its first row as headers and renders the other rows as `Header: value; Header: value`;
  - a missing `document.xml` or an invalid zip → `ExtractionFailed(CORRUPT)`.
- [X] T031 [P] [US1] Create `app/Support/Knowledge/Extraction/SpreadsheetExtractor.php` with PhpSpreadsheet (`setReadDataOnly(true)`):
  - **XLSX**: per non-empty sheet, the first non-empty row is the header row; each later non-empty row becomes `Header: value; …`. Rows are grouped into segments of at most 40 rows, `location = "Sheet <name>, rows <a>–<b>"`.
  - **CSV**: detect encoding (UTF-8 BOM, UTF-16 BOM, then `mb_check_encoding` UTF-8, else Windows-1256, else ISO-8859-1), convert to UTF-8, and sniff the delimiter from `, ; \t |` on the first 5 lines. The location is `Rows <a>–<b>`.
  - More than `config('knowledge.max_rows')` rows in total → `ExtractionFailed(TOO_MANY_ROWS)`. A reader exception → `CORRUPT`.
- [X] T032 [P] [US1] Create `app/Support/Knowledge/Extraction/PlainTextExtractor.php`:
  - TXT: detect encoding as in T031, then 1 segment with location `Document`.
  - MD: one segment per `#`/`##`/`###` heading, `location = "Section: <heading>"`.
- [X] T033 [US1] Create `app/Support/Knowledge/Extraction/ExtractorRegistry.php`:
  - `for(string $mimeType): Extractor` resolves from the container: pdf → `PdfExtractor`; docx → `DocxExtractor`; xlsx and csv → `SpreadsheetExtractor`; txt and md → `PlainTextExtractor`; images → `VisionExtractor` (added in US8, until then `ExtractionFailed(UNSUPPORTED_TYPE)`).
  - `allowedMimeTypes(): array` is the single allow-list used by validation.
- [X] T034 [P] [US1] Create `app/Http/Requests/Knowledge/StoreKnowledgeDocumentRequest.php` (R7):
  - `file` is `required|file|max:config('knowledge.max_upload_kb')|mimetypes:<ExtractorRegistry::allowedMimeTypes()>|extensions:pdf,docx,xlsx,csv,txt,md,jpg,jpeg,png,webp`;
  - an `after()` hook checks the detected type matches the extension; for `application/zip`, it checks for `word/document.xml` (docx) or `xl/workbook.xml` (xlsx); `application/x-ole-storage` → "Password-protected Office files are not supported.";
  - `title` is `required|string|max:255`, `category` is `nullable|Rule::enum(KnowledgeBaseCategory::class)`, `is_active` is `sometimes|boolean`, and `hotel_id` is `nullable|uuid`.
- [X] T035 [US1] Create `app/Services/Knowledge/KnowledgeDocumentService.php` with `upload(UploadedFile $file, array $data, ?Hotel $hotel, User $actor): KnowledgeDocument` (`$hotel` null = global, used in US6):
  - computes the sha256 and rejects a live duplicate in the same scope with a `DuplicateKnowledgeDocument` exception carrying the existing document (the controller maps it to `409`);
  - creates the row in a transaction, stores the file at `knowledge/{hotel_id|global}/{id}/{uuid}.{ext}` on `config('knowledge.disk')`, and sets `mime_type` from the detected type;
  - catches a `23505` race on the partial unique index and rethrows it as a duplicate;
  - dispatches `IndexKnowledgeDocumentJob::dispatch($doc->id, 'extract')->afterCommit()`.

  The `created` audit comes from `RecordsEvents`.
- [X] T036 [US1] Create `app/Jobs/IndexKnowledgeDocumentJob.php` (R5, R19):
  - `__construct(string $documentId, string $mode = 'extract', ?string $rebuildId = null)`; `middleware()` returns `[(new WithoutOverlapping($this->documentId))->releaseAfter(30)->expireAfter(600)]`.
  - `public int $timeout = 300;` (SC-001). Extraction, vision (10–60 s) and embedding together can exceed a worker's default 60 s. The retry settings come in T051.
  - `handle()`:
    1. Load the document with the scope lifted, and return when it is missing or trashed.
    2. Enter tenant context.
    3. Set `status = extracting` and `failure_code = null` in the same write. This also applies when re-running a `failed` document.
    4. Capture `$fingerprint = $doc->inputFingerprint()`.
    5. Get segments: for `extract`, from `ExtractorRegistry` on the pending file if there is one, otherwise the main file; a missing file → `FILE_MISSING`. For `from_segments`, from `$doc->segments`.
    6. Run `stripRepeatedEdges` and drop empty segments; none left → `NO_TEXT`.
    7. Inside `AiCostContext::for(AiTriggerKind::SCHEDULED_JOB, hotel: $doc->hotel, trigger: $doc)`, call `ChunkSynchronizer::syncSegments(..., guard: fn ($locked) => ! $locked->trashed() && $locked->inputFingerprint() === $fingerprint, afterSwap: ...)`. Never pass the `is_active` captured at job start; the synchronizer reads it from the locked row. A `null` return means the run was stale or the document was deleted: stop with no further writes.
    8. On success, inside `afterSwap` (the same guarded transaction), write `segments`, `content` (joined `"{location}\n{text}"`), `status = indexed`, `failure_code = null`, `chunk_count`, `page_count`, `scanned_page_count`, `index_fingerprint`, `indexed_at`, and record the `indexed` event.
    9. Meter `EMBEDDINGS_GENERATED` for hotel documents with `MeteringService::safely()`.
  - Catching `ExtractionFailed` with a permanent failure: set `status = failed` and `failure_code`, write `error`, record `index_failed`, and `$this->fail()`. Never touch chunks or the file.
  - `AiSpendCeilingExceededException` → `USAGE_LIMIT_REACHED` (permanent).
- [X] T037 [P] [US1] Create `app/Http/Resources/KnowledgeDocumentResource.php` with the exact shape in [contracts/knowledge-documents-api.md](contracts/knowledge-documents-api.md):
  - `failure_message` = `$doc->failure_code?->message(app()->getLocale())`;
  - `uploaded_by` = `{id, name}` or `null`;
  - `has_pending_replacement`;
  - `restorable_until` only when trashed;
  - `debug.error` only when `$request->user()->isAdmin() || isSuperAdmin()`;
  - never `path`, `disk` or hashes.
- [X] T038 [US1] Create `app/Http/Controllers/KnowledgeDocumentController.php` with `index` (`GenericIndexRequest` + `GenericQuery::apply()`), `store`, `show` and `download`:
  - every action resolves the hotel with `resolveHotel($user, $request->hotel_id)` and returns `422` for a super admin without one;
  - route-model lookups use `KnowledgeDocument::whereNotNull('hotel_id')->where('hotel_id', $hotel->id)->findOrFail($id)` (404 for global rows or another hotel's);
  - every action authorizes through `KnowledgeDocumentPolicy` and returns `apiResponse()`;
  - `download` returns `Storage::disk($doc->disk)->download($doc->path, $doc->original_filename)`.
- [X] T039 [US1] Register the routes in `routes/api.php`, in the same authenticated `tenant` group as `/knowledge-base-articles`: `GET /knowledge-documents`, `POST /knowledge-documents`, `GET /knowledge-documents/{id}`, `GET /knowledge-documents/{id}/download`. Declare the static `GET /knowledge-documents/deleted` before `{id}` (it is added in US5).

**Checkpoint**: US1 works on its own. Text-format documents upload, index and are found
by the existing Concierge search.

---

## Phase 4: User Story 2 - Answers say where they came from (Priority: P1)

**Goal**: Every search result carries a citation. The Concierge names hotel sources by
title and calls global ones "general information" (enforced in code). The Admin Advisor
cites title, location and date.

**Independent Test**: Index sources with known titles. Search with both audiences and check
the citation fields and the guest redaction, then check the agents' tool wiring and
instructions.

### Tests for User Story 2 ⚠️

- [X] T040 [P] [US2] Extend `tests/Feature/KnowledgeSearchToolTest.php`:
  - Every result has `rank, scope, source_type, title, location, last_updated, content`.
  - A document chunk has `location = "Page 2"` and `source_type = document`; article and policy chunks have `location = null`.
  - Renaming a document changes `title` in the next search with no job queued (FR-007).
  - With the `GUEST` audience, global results have `title = null`, `location = null` and `source_type = general`, while hotel results keep their title.
  - No result contains `original_filename`, `hotel_id`, an id or `path`.
  - Empty → `No relevant results found.`
- [X] T041 [P] [US2] Create `tests/Feature/ConciergeKnowledgeCitationTest.php`:
  - The `KnowledgeSearchTool` in `GuestConciergeAgent::tools()` has the `GUEST` audience, and those in `AdminAdvisorAgent` and `RecommendationAgent` have `STAFF` (read through a public `audience()` accessor).
  - `GuestConciergeAgent::instructions()` contains the rules: cite hotel by title, call global "general information", follow hotel over general, never cite unreturned sources, offer staff when nothing is found.
  - `AdminAdvisorAgent::instructions()` contains the title–location–date citation rule.

### Implementation for User Story 2

- [X] T042 [US2] Update `app/Ai/Tools/KnowledgeSearchTool.php` (R14, [contracts/knowledge-search-tool.md](contracts/knowledge-search-tool.md)):
  - The constructor becomes `__construct(Hotel $hotel, KnowledgeAudience $audience = KnowledgeAudience::STAFF)`, with an `audience()` accessor.
  - Replace the `SimilaritySearch` wrapper with an own `handle()` that keeps the embedding, the `widenIndexSearch()` transaction and the explicit `hotel_id` predicate, adding `->where('is_active', true)`.
  - Eager-load `chunkable` (including trashed, so it can be detected and dropped), and drop chunks whose source is missing, trashed, unpublished or inactive.
  - Map each chunk to `{rank, scope, source_type, title, location, last_updated (Y-m-d of the source's updated_at), content}`. For `GUEST` + global, set `title = null`, `location = null` and `source_type = 'general'`.
  - Return `json_encode($results, JSON_UNESCAPED_UNICODE)`, or `'No relevant results found.'`.
  - Keep `schema()` = `{query: string required}`, and update `description()` to mention citations and the hotel/general labels.
- [X] T043 [US2] Update the knowledge-base bullet in `app/Ai/Agents/GuestConciergeAgent.php`, with the exact rules from [contracts/knowledge-search-tool.md](contracts/knowledge-search-tool.md) "GuestConciergeAgent", and construct `new KnowledgeSearchTool($this->hotel, KnowledgeAudience::GUEST)` in `tools()`.
- [X] T044 [P] [US2] Update the knowledge-base bullet in `app/Ai/Agents/AdminAdvisorAgent.php`: cite each source as *title — location (updated date)*, mention "general" for global sources, never cite unreturned sources, and keep live data on PMS tools.
- [X] T045 [P] [US2] Update the knowledge bullet in `app/Ai/Agents/RecommendationAgent.php`: follow hotel results over general ones when they disagree. Keep the `STAFF` audience (the default).

**Checkpoint**: US1 and US2 work. Answers are cited and guests never see a global title.

---

## Phase 5: User Story 3 - The hotel's own rule wins over the platform default (Priority: P1)

**Goal**: Hotel results always come before global ones; another hotel's knowledge never
appears, from requests or jobs; a deleted hotel's knowledge can never become global.

**Independent Test**: A global source and a conflicting hotel source, searched as that
hotel, as a second hotel without an override, and as a third hotel with unrelated
content. Check the order, the labels and the isolation.

### Tests for User Story 3 ⚠️

- [X] T046 [P] [US3] Create `tests/Feature/KnowledgeRetrievalIsolationTest.php`:
  - Hotels A, B and C plus global, with deterministic vectors where the global chunk is **closer** to the query than A's chunk: A's search returns A's chunk at rank 1, then global.
  - B gets only global; C gets C's chunk and global, never A's.
  - Searching with A's exact text as B returns no A chunk.
  - The same searches through a tool constructed inside a job with no tenant context give the same results (SC-002).
  - More than 5 hotel matches → at most 5 hotel + 3 global results.
  - Force-deleting hotel A deletes its chunks, documents and articles, and global counts are unchanged (R17).

### Implementation for User Story 3

- [X] T047 [US3] Change `app/Ai/Tools/KnowledgeSearchTool.php` to run **two** queries inside the same widened-search transaction (R13):
  - hotel: `where('hotel_id', $this->hotel->id)->where('is_active', true)` limit `config('knowledge.search.hotel_limit')`;
  - global: `whereNull('hotel_id')->where('is_active', true)` limit `config('knowledge.search.global_limit')`;
  - both use `minSimilarity: config('knowledge.search.min_similarity')`.

  Concatenate hotel results then global, each in similarity order, then assign `rank` 1..n and `scope`. Embed the query once.
- [X] T048 [US3] Add the precedence rules to `app/Ai/Agents/GuestConciergeAgent.php` and `app/Ai/Agents/AdminAdvisorAgent.php` instructions if T043/T044 did not already include them verbatim:
  - follow `hotel` over `general` when they disagree;
  - when two `hotel` results disagree, follow the later `last_updated` and mention both (Edge Cases).

**Checkpoint**: All P1 stories work. The MVP is upload, cited answers, and hotel-first,
isolated retrieval.

---

## Phase 6: User Story 4 - Indexing failures are visible and recoverable (Priority: P2)

**Goal**: Failures show a plain reason, transient failures retry three times, staff can
re-index or replace a file, and the live version always stays searchable.

**Independent Test**: Run the failing fixtures, then re-index and replace. Check the status
and reason, that the file bytes are unchanged, that there is one chunk set, and that the
old version stays live during and after a failed replacement.

### Tests for User Story 4 ⚠️

- [X] T049 [P] [US4] Create `tests/Feature/KnowledgeDocumentIndexingTest.php`:
  - an embedding exception on re-index keeps the previous chunks;
  - a transient exception is retried (assert `maxExceptions = 4`, `tries = 0`, `backoff = [30,120,300]` and `timeout = 300` on the job) and then marked `failed` with `embedding_unavailable` by `failed()`;
  - a job released by `WithoutOverlapping` five times while another run holds the lock is still processed afterwards and is not marked `failed`;
  - a permanent failure is not retried;
  - two jobs for the same document run one after the other and leave one chunk set (FR-016);
  - a job whose fingerprint went stale (text corrected mid-run, simulated) writes nothing;
  - deleting the document mid-run leaves no chunks;
  - `POST /knowledge-documents/{id}/reindex` → `202` and the job is queued (`from_segments` when segments exist, else `extract`), and a `reindex_requested` event is recorded;
  - the file's sha256 is unchanged after every case.
- [X] T050 [P] [US4] Create `tests/Feature/KnowledgeDocumentReplaceTest.php`:
  - `POST /knowledge-documents/{id}/file` → `202`, `has_pending_replacement: true`, and the old chunks are still searchable before the job runs;
  - after a successful job, the main columns hold the new file, the old file is deleted, the pending columns are null, `content_source = extracted`, and search returns the new text;
  - a replacement that fails (`corrupt.pdf`) keeps the old chunks searchable, sets `status = failed` and keeps the pending file;
  - a replacement that duplicates another live document → `409`;
  - a replacement whose hash is taken by another upload while it is processing (insert the duplicate before running the job) → `failed` with `failure_code = duplicate`, the old version still searchable, and no further retry;
  - replacing and re-indexing a `failed` document both succeed and clear `failure_code`, with no check-constraint violation.

### Implementation for User Story 4

- [X] T051 [US4] Extend `app/Jobs/IndexKnowledgeDocumentJob.php`:
  - Set `public int $tries = 0;` with `retryUntil()` returning `now()->addHour()`, `public int $maxExceptions = 4;` (the first run plus 3 retries, FR-018), `public array $backoff = [30, 120, 300];` and `public int $timeout = 300;`. `maxExceptions` counts only thrown exceptions. A run released by `WithoutOverlapping` while another run holds the lock does **not** use up a retry, and never reaches `failed()` (the 4-try design would have marked a healthy document `embedding_unavailable` after four overlaps).
  - Set `public bool $failOnTimeout = true;` so a hung provider call ends in `failed()` and not in an endless retry.
  - Rethrow transient exceptions (provider, HTTP or connection exceptions, timeouts) so the queue retries them.
  - `failed(Throwable $e)` sets `status = failed` and `failure_code = EMBEDDING_UNAVAILABLE` (or `EXTRACTION_UNAVAILABLE` when it failed before embedding), writes `error`, and records `index_failed`. This only happens when the document is not already `failed` with a permanent code.
  - On a successful run against a pending file, in the guarded swap transaction: promote `pending_*` to the main columns, clear the corrections (`content_source = extracted`, `corrected_by/at = null`), record `replaced`, and register `DB::afterCommit` to delete the old file.
  - Promoting `pending_content_hash` can hit the partial unique index, when an identical file was uploaded to the same scope while the replacement was processing. Catch the `23505` `UniqueConstraintViolationException` from the swap and roll back, so the live version and its chunks stay. Then, in a new write, set `status = failed` and `failure_code = DUPLICATE`, keep the pending file, record `index_failed`, and call `$this->fail()`. Never rethrow it, or it would retry forever.
- [X] T052 [P] [US4] Create `app/Http/Requests/Knowledge/ReplaceKnowledgeDocumentFileRequest.php`, reusing the `file` rules and the `after()` type check from T034. Extract them into a shared trait `app/Http/Requests/Knowledge/Concerns/ValidatesKnowledgeFile.php` used by both requests.
- [X] T053 [US4] Add to `app/Services/Knowledge/KnowledgeDocumentService.php`:
  - `replaceFile(KnowledgeDocument $doc, UploadedFile $file, User $actor)`: a duplicate check against other live documents in scope; store the file at a new uuid path; when a previous pending file exists, delete it after commit; set the `pending_*` columns, `status = uploaded` and `failure_code = null` in one write; dispatch `extract` after commit.
  - `reindex(KnowledgeDocument $doc, User $actor)`: mode `from_segments` when `segments` is not null and there is no pending replacement, otherwise `extract`; record `reindex_requested`.
- [X] T054 [US4] Add `replace` (`POST {id}/file`, `202`) and `reindex` (`POST {id}/reindex`, `202`) to `app/Http/Controllers/KnowledgeDocumentController.php`, and register both routes in `routes/api.php`.

**Checkpoint**: Failures are visible, retried and recoverable without losing the live
version.

---

## Phase 7: User Story 5 - Staff keep the knowledge base current (Priority: P2)

**Goal**: Staff edit metadata, deactivate and reactivate, correct and discard text, and
delete and restore within 30 days. Search reflects each change in the same request, and
a daily purge removes expired documents.

**Independent Test**: Deactivate, reactivate, rename, correct, discard, delete, restore and
purge an indexed document. After each step, check search and the queued jobs.

### Tests for User Story 5 ⚠️

- [X] T055 [P] [US5] Create `tests/Feature/KnowledgeDocumentLifecycleTest.php`:
  - `PUT {id}` `{is_active:false}` removes the document from search in the same request, with no job queued; `{is_active:true}` brings it back with no job (SC-005).
  - Changing `category` updates the chunks' `category` with no job.
  - `DELETE {id}` hides it from the list and from search.
  - `GET /knowledge-documents/deleted` lists it with `restorable_until`.
  - `POST {id}/restore` brings it back to search with no job.
  - Restoring after 31 days → `404`.
  - `PurgeDeletedKnowledgeDocumentsJob` force-deletes documents deleted > 30 days ago, with their chunks, main file and pending file, records `purged`, keeps the `event_logs` rows, and leaves documents deleted 29 days ago alone.
  - A new upload of the same bytes as a deleted document → `201`.
- [X] T056 [P] [US5] Create `tests/Feature/KnowledgeDocumentCorrectionTest.php`:
  - `GET {id}/text` returns the segments.
  - `PUT {id}/text` with edited page 1 → `202`, `content_source = corrected` with `corrected_by` and `corrected_at` set; the job runs `from_segments` and makes no extractor call (assert the `ExtractorRegistry` mock is not called); search returns the corrected text; the file is unchanged.
  - Changed or reordered locations → `422`; an empty segment text drops that segment's chunks.
  - Re-index keeps the correction.
  - `DELETE {id}/text` → `202`, `content_source = extracted`, and the extracted text returns.
  - Replacing the file drops the correction.
  - A document with no segments → `422`.

### Implementation for User Story 5

- [X] T057 [P] [US5] Create `app/Http/Requests/Knowledge/UpdateKnowledgeDocumentRequest.php` (`title` `sometimes|string|max:255`, `category` `sometimes|nullable|Rule::enum(KnowledgeBaseCategory::class)`, `is_active` `sometimes|boolean`) and `app/Http/Requests/Knowledge/CorrectKnowledgeDocumentTextRequest.php` (`segments` `required|array|min:1`; `segments.*.location` `required|string`; `segments.*.text` `present|nullable|string|max:100000`).
- [X] T058 [US5] Add to `app/Services/Knowledge/KnowledgeDocumentService.php`:
  - `update(doc, data, actor)`, in one transaction:
    - write `title` and `category`; a category change updates the chunks' `category` column with no re-embed;
    - an `is_active` change calls `ChunkSynchronizer::setActive()` and records `activated` / `deactivated`.
  - `delete(doc, actor)`: soft delete plus `setActive(false)` in one transaction, and record `deleted`.
  - `restore(doc, actor)`: refuse with a 404 exception when `deleted_at < now()->subDays(config('knowledge.purge_after_days'))`; otherwise restore plus `setActive($doc->is_active)`, and record `restored`.
  - `correctText(doc, segments, actor)`:
    - `422` when `$doc->segments === null` or the locations differ in count or order;
    - store the segments (dropping empty texts from the index, but keeping their location in the stored list), set `content_source = corrected`, `corrected_by` and `corrected_at`, and refresh `content`;
    - record `text_corrected` and dispatch `from_segments` after commit.
  - `discardCorrections(doc, actor)`: set `content_source = extracted`, null `corrected_by/at`, record `text_correction_discarded`, and dispatch `extract` after commit.
- [X] T059 [US5] Add `update`, `destroy`, `deleted` (`withTrashed()->onlyTrashed()` within the purge window), `restore`, `showText`, `correctText` and `discardText` to `app/Http/Controllers/KnowledgeDocumentController.php`. Register the routes in `routes/api.php` per the contract: `PUT {id}`, `DELETE {id}`, `GET deleted`, `POST {id}/restore`, `GET|PUT|DELETE {id}/text`.
- [X] T060 [US5] Make `IndexKnowledgeDocumentJob` (mode `from_segments`) build the index from `$doc->segments`, skipping empty texts, with no extractor and no vision. The `inputFingerprint()` from T015 includes the corrected segments, so a correction made mid-run supersedes the running job.
- [X] T061 [US5] Create `app/Jobs/PurgeDeletedKnowledgeDocumentsJob.php` (R11):
  - Runs in `TenantContext::withoutScope()` (a platform sweep) and finds `onlyTrashed()` rows with `deleted_at < now()->subDays(config('knowledge.purge_after_days'))`.
  - Per row: record `purged`, delete the chunks, `forceDelete()`, then delete `path` and `pending_path` after commit.
  - Orphan pass: delete directories under `knowledge/{uuid}/` whose hotel id no longer exists in `hotels` (with trashed).
  - Schedule it in `routes/console.php`: `Schedule::job(new PurgeDeletedKnowledgeDocumentsJob)->dailyAt('04:15');`.

**Checkpoint**: Staff fully control what the assistants read.

---

## Phase 8: User Story 6 - The super admin manages global knowledge (Priority: P2)

**Goal**: Global documents and articles are managed only under `/api/admin`. Hotel routes
never expose or create global knowledge, and super admins must name a hotel on hotel
routes.

**Independent Test**: As a super admin, run the global document and article CRUD under
`/api/admin`, and check that every hotel's search sees the change. A hotel admin and an
employee get `403`, and global rows are `404` on hotel routes.

### Tests for User Story 6 ⚠️

- [X] T062 [P] [US6] Create `tests/Feature/Admin/GlobalKnowledgeTest.php`:
  - A super admin can upload, list, show, edit, replace, download, correct and discard text, re-index, delete, list deleted and restore under `/api/admin/knowledge-documents`; the rows have `hotel_id = null`.
  - A sent `hotel_id` is ignored.
  - A hotel document id on admin routes → `404`.
  - The global list never contains hotel rows.
  - Global article CRUD works under `/api/admin/knowledge-base-articles`; an article created before this feature with `hotel_id = null` is listed (US6-4).
  - A hotel admin and an employee with every `knowledge_documents.*` permission get `403` on every admin route.
  - A global document id on `/knowledge-documents/{id}` → `404` for a hotel admin, including download (FR-034).
  - Once indexed, a global document appears in two different hotels' searches.
- [X] T063 [P] [US6] Extend `tests/Feature/KnowledgeBaseArticleControllerTest.php`:
  - a super admin `POST /knowledge-base-articles` without `hotel_id` → `422`, and with it the article gets that `hotel_id`;
  - a super admin `GET` without `hotel_id` → `422`, and with it only that hotel's articles;
  - hotel admins behave exactly as before.

### Implementation for User Story 6

- [X] T064 [US6] Generalise `app/Services/Knowledge/KnowledgeDocumentService.php` so every method works for `$hotel = null` (global):
  - the duplicate scope is `whereNull('hotel_id')`;
  - the storage path prefix is `global`;
  - jobs use `withoutScope()`;
  - no metering for global documents (R18).

  Add `findForScope(?Hotel $hotel, string $id, bool $withTrashed = false): KnowledgeDocument`, used by both controllers (`404` across scopes).
- [X] T065 [P] [US6] Create `app/Http/Controllers/Admin/GlobalKnowledgeDocumentController.php` with the same actions as `KnowledgeDocumentController`:
  - always uses scope `null` via `KnowledgeDocumentService::findForScope(null, …)`;
  - has no policy call, because the `super_admin` middleware guards the file;
  - responds through `KnowledgeDocumentResource` and `apiResponse()`.
- [X] T066 [P] [US6] Create `app/Http/Controllers/Admin/GlobalKnowledgeBaseArticleController.php`:
  - CRUD on `KnowledgeBaseArticle::whereNull('hotel_id')`, reusing `GenericIndexRequest`, `GenericStoreRequest`, `GenericUpdateRequest` and `KnowledgeBaseArticleResource`;
  - `store` forces `hotel_id = null`; a hotel article id → `404`.
- [X] T067 [US6] Add the routes at the **top level** of `routes/admin.php` (no `Route::group()`, per the file's header):
  - every `/knowledge-documents…` route from [contracts/admin-knowledge-api.md](contracts/admin-knowledge-api.md), with `deleted` before `{id}`;
  - `Route::apiResource('/knowledge-base-articles', GlobalKnowledgeBaseArticleController::class)`.
- [X] T068 [US6] Change `app/Http/Controllers/KnowledgeBaseArticleController.php` (R16):
  - `index` and `store` use `resolveHotel($user, $request->hotel_id)`; a super admin without one → `apiResponse('A hotel_id is required.', 422)`;
  - remove the `whereNull('hotel_id')` super-admin branch and the `$hotelId = null` branch;
  - `update` keeps a super admin from moving an article between scopes (`hotel_id` is still unset).

  Update the class comments to point to `/api/admin/knowledge-base-articles` for global knowledge.

**Checkpoint**: Global knowledge has one guarded home.

---

## Phase 9: User Story 7 - The platform rebuilds the index (Priority: P3)

**Goal**: A super admin rebuilds the index for everything, one hotel, or global only, from
stored text (corrections kept), with progress and a failure list. Search keeps working
throughout.

**Independent Test**: Index a mix of documents, articles and policies, run each rebuild
scope, and check the counts, failures, single chunk sets, kept corrections and unchanged
search results.

### Tests for User Story 7 ⚠️

- [X] T069 [P] [US7] Create `tests/Feature/Admin/KnowledgeRebuildTest.php`:
  - `POST /api/admin/knowledge/rebuilds {scope:'hotel', hotel_id}` → `202` with `total` = active, non-deleted documents + published articles + active policies of that hotel.
  - After the jobs run: `status = completed`, `succeeded = total`, every source has one chunk set, a corrected document keeps its correction, no extractor or vision call for documents with segments, and the same fixed queries return the same sources (SC-007).
  - One forced failure → `failed = 1`, with the source listed in `failures` and keeping its previous chunks.
  - A document deleted after dispatch, and a run discarded as stale, both still count, so the rebuild reaches `completed`.
  - The `knowledge_index_rebuild.completed` event is recorded exactly once.
  - `scope:'all'` includes every hotel plus global; `scope:'global'` only `hotel_id IS NULL`.
  - `hotel_id` with `scope:'all'` → `422`.
  - Non-super-admins → `403`.
  - `GET /api/admin/knowledge/rebuilds` and `GET …/{id}` return progress.
  - `php artisan knowledge:sync --hotel=<id> --documents` creates a rebuild row.

### Implementation for User Story 7

- [X] T070 [P] [US7] Create `app/Http/Requests/Knowledge/StartKnowledgeRebuildRequest.php` (`scope` `required|Rule::enum(KnowledgeRebuildScope::class)`; `hotel_id` `required_if:scope,hotel|prohibited_unless:scope,hotel|uuid|exists:hotels,id`) and `app/Http/Resources/KnowledgeIndexRebuildResource.php` (the shape in [contracts/admin-knowledge-api.md](contracts/admin-knowledge-api.md)).
- [X] T071 [US7] Create `app/Services/Knowledge/KnowledgeRebuildService.php`:
  - `start(KnowledgeRebuildScope $scope, ?Hotel $hotel, ?User $actor, bool $includeDocuments = true): KnowledgeIndexRebuild`, running in `TenantContext::withoutScope()`, with sources:
    - active, non-deleted documents in scope → `IndexKnowledgeDocumentJob($id, $doc->segments ? 'from_segments' : 'extract', $rebuild->id)`;
    - `KnowledgeBaseArticle` with `status = 'published'` and `HotelPolicy` with `is_active` in scope → `SyncKnowledgeChunksJob($model, $rebuild->id)`.
  - It creates the row with `total` = the count, records `started`, and dispatches after commit.
  - With `total = 0`, the row is created `completed` straight away.
- [X] T072 [US7] Report rebuild outcomes from the jobs:
  - `IndexKnowledgeDocumentJob` calls `KnowledgeIndexRebuild::find($rebuildId)?->recordOutcome(...)` exactly once, from **every** exit path:
    - success → succeeded;
    - permanent failure → failed, with a failure entry;
    - `failed()` → failed;
    - document missing or soft-deleted at start → succeeded (nothing to rebuild);
    - run discarded because `syncSegments()` returned `null` (stale fingerprint or deleted mid-run) → succeeded (a newer run owns the document).

    Use a `finally`-style `reportRebuildOutcome()` guarded by a `$reported` flag so no path reports twice or not at all. Without this, one discarded run leaves the rebuild `running` forever.
  - Add `public ?string $rebuildId = null` to `app/Jobs/SyncKnowledgeChunksJob.php` and do the same there. A failed article sync keeps its chunks, thanks to T023.
  - When `recordOutcome` completes the rebuild (the update that makes `succeeded + failed = total`), it records `EventLogger::record($rebuild, 'completed', ['succeeded' => …, 'failed' => …])` exactly once. Only the update that set `status = 'completed'` records it (FR-038).
- [X] T073 [US7] Create `app/Http/Controllers/Admin/KnowledgeRebuildController.php` (`store` → `202`, `index` paginated newest first, `show`) and register `POST /knowledge/rebuilds`, `GET /knowledge/rebuilds` and `GET /knowledge/rebuilds/{id}` at the top level of `routes/admin.php`.
- [X] T074 [US7] Update `app/Console/Commands/SyncKnowledgeBaseCommand.php`:
  - signature `knowledge:sync {--hotel= : limit to one hotel} {--global : global knowledge only} {--documents : include knowledge documents}`;
  - delegates to `KnowledgeRebuildService::start()` (actor `null`) and prints the rebuild id and total;
  - without `--documents` it keeps today's behaviour, articles and policies only, but through the service.

**Checkpoint**: The index can be rebuilt safely at any time.

---

## Phase 10: User Story 8 - Pictures and scans become searchable (Priority: P3)

**Goal**: Images and image-only PDF pages are read by AI vision, with a cap of 50 scanned
pages checked before any call; pages with text are never sent; usage is metered.

**Independent Test**: Upload `sign.png`, `scanned.pdf` and `mixed.pdf` with faked vision.
The text is searchable, only scanned pages go to vision, metering is recorded, and a
51-page scan fails with no AI call.

### Tests for User Story 8 ⚠️

- [X] T075 [P] [US8] Create `tests/Feature/KnowledgeDocumentVisionTest.php`, using `knFakeVision()`:
  - `sign.png` → 1 segment `Image` containing the transcribed text and a description; `scanned_page_count = 1`.
  - `scanned.pdf` → the vision agent is called once with pages `[1,2]`; segments `Page 1` and `Page 2`.
  - `mixed.pdf` → the vision prompt lists only page `[2]`, and page 1 comes from the text layer.
  - A faked document of 51 scanned pages (stub `PdfExtractor` to report 51 scanned pages) → `failed`, `too_many_scanned_pages`, with **no** agent call, **no** `ai_usage_logs` row and no meter event.
  - A vision file over `vision.max_file_mb` → `too_large_for_vision`.
  - An empty vision text for every page → `no_text`.
  - A `knowledge_pages_read` meter event with quantity = scanned pages, for hotel documents only.
  - A global document records no meter event.
  - The vision call runs inside `AiCostContext` with the document's hotel.

### Implementation for User Story 8

- [X] T076 [P] [US8] Create `app/Ai/Agents/DocumentVisionAgent.php`, implementing `Agent` and `HasStructuredOutput` with `Promptable` and no tools, modelled on `app/Ai/Agents/TurnSignalAgent.php`:
  - `__construct(public array $pages)` (the page numbers to transcribe; `[1]` for an image).
  - `instructions()`:
    - transcribe the visible text verbatim, in its original language (Arabic stays Arabic, in reading order);
    - add at most two sentences describing what the image shows;
    - never infer, translate or add information not visible;
    - return empty text when no text is visible;
    - only the listed pages.
  - `schema()` → `pages: array of {page: integer required, text: string required, description: string required}` required.
- [X] T077 [US8] Create `app/Support/Knowledge/Extraction/VisionExtractor.php`:
  - `transcribe(string $disk, string $path, string $mimeType, array $pages): list<Segment>`:
    - `count($pages) > config('knowledge.vision.max_pages')` → `ExtractionFailed(TOO_MANY_SCANNED_PAGES)` **before** any call;
    - file size > `vision.max_file_mb` → `ExtractionFailed(TOO_LARGE_FOR_VISION)`;
    - builds the attachment (`Image::fromStorage($path, $disk)` for images, `Document::fromStorage($path, $disk)` for PDFs);
    - calls `(new DocumentVisionAgent($pages))->prompt('Transcribe the listed pages.', attachments: [...], provider: config('knowledge.vision.provider'), model: config('knowledge.vision.model'), timeout: config('knowledge.vision.timeout'))`. `vision.timeout` comes from T005;
    - maps to `Segment('Page N' or 'Image', trim(text."\n\n".description))` through `TextNormalizer`.
  - Also implement `Extractor` for images (`supports` jpeg, png and webp; `scannedPageNumbers = [1]`).
- [X] T078 [US8] Wire vision into the pipeline:
  - In `ExtractorRegistry`, map image MIME types to `VisionExtractor`.
  - In `IndexKnowledgeDocumentJob` `extract` mode, when the PDF result's `scannedPageNumbers` is not empty, call `VisionExtractor::transcribe()` with exactly those page numbers and merge the segments in page order. Set `scanned_page_count = $result->scannedPageCount()`.
  - Only when the vision call succeeds, meter `MeterFeature::KNOWLEDGE_PAGES_READ` with quantity = scanned pages for hotel documents, through `MeteringService::safely()`.
  - Transient provider errors are rethrown (retried); `AiSpendCeilingExceededException` → `USAGE_LIMIT_REACHED`.

**Checkpoint**: Every story is complete.

---

## Phase 11: Polish & Cross-Cutting Concerns

**Purpose**: Documentation, verification and the frontend handoff.

- [X] T079 [P] Create `docs/knowledge-document-api-documentation.md`: every hotel route, its permission, request fields and rules, the `KnowledgeDocumentResource` example, status and failure codes with messages, the lifecycle (replace, correct, delete and restore within 30 days), and the status-code table, from [contracts/knowledge-documents-api.md](contracts/knowledge-documents-api.md).
- [X] T080 [P] Create `docs/global-knowledge-admin-api-documentation.md`: global documents, global articles and rebuilds, from [contracts/admin-knowledge-api.md](contracts/admin-knowledge-api.md).
- [X] T081 [P] Update `docs/knowledge-base-article-api-documentation.md`: a super admin must send `hotel_id`, and global articles moved to `/api/admin/knowledge-base-articles`.
- [X] T082 [P] Update the permission reference in `docs/staff-roles-api-documentation.md` with the 5 `knowledge_documents.*` permissions, their endpoints, and the note "not an employee default".
- [X] T083 [P] Create `docs/latest-changes-2026-10-07.md` with the breaking changes:
  1. super-admin global-article management moved to `/api/admin`;
  2. `KnowledgeSearchTool` returns cited JSON with `hotel`/`general` labels;
  3. the knowledge `hotel_id` foreign keys now cascade on hotel deletion;
  4. the new `knowledge_pages_read` meter.
- [X] T084 [P] Add `knowledge_pages_read` to the feature catalogue in `docs/usage-metering-api-documentation.md`, and describe in `docs/ai-cost-attribution-api-documentation.md` how indexing and vision cost is attributed (hotel account vs platform for global documents).
- [X] T085 Run `./vendor/bin/pint` and `php artisan test --parallel`. The whole suite must be green, including every file in the [quickstart.md](quickstart.md) table.
- [ ] T086 Run the 9-step manual check in [quickstart.md](quickstart.md) locally with `composer dev`, then record the results (pass/fail per step) in the PR description.
- [X] T087 [P] Hand off [frontend-changes.md](frontend-changes.md) to `ecosystem-frontend` as its Phase 8 slice (D13).
- [ ] T088 Run the conflict evaluation for SC-003 / FR-024 and SC-004 against the **real** model (not faked), together with T086. Steps:
  1. Seed one hotel with 10 hotel sources that each contradict a global article on the same topic: checkout time, pool hours, pet policy, smoking, breakfast hours, late checkout fee, visitor policy, quiet hours, towel policy, parking. Seed a second hotel with none.
  2. Ask the Concierge (guest audience) and the Admin Advisor each question once per hotel.
  3. Record in the PR description, per question: the answer, whether it followed the hotel source (pass = 10/10 for the first hotel), whether the cited source was one the tool returned (SC-004), and whether the guest answer named a global title (must be never).

  A failure blocks the merge until the instructions in T043 and T044 are tightened.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup and **blocks every story**. T011 must come before T015; T018 before T019; T021 and T022 before T023.
- **US1 (Phase 3)**: after Foundational. It is the base for every later story: the upload, the job and the controller.
- **US2 (Phase 4)**: after Foundational. It can be built against `knDocument()` fixtures without US1, but end-to-end validation needs US1.
- **US3 (Phase 5)**: after US2, because T047 edits the same `KnowledgeSearchTool` as T042.
- **US4 (Phase 6)**: after US1 (extends the job and controller).
- **US5 (Phase 7)**: after US1. T060 also follows T051 because both edit the job, so US4 before US5 when one person does both.
- **US6 (Phase 8)**: after US1 and US5 (the admin controller mirrors every hotel action).
- **US7 (Phase 9)**: after US5 (the `from_segments` mode) and US6 (the admin routes file).
- **US8 (Phase 10)**: after US1 (registry and job). It is independent of US2–US7.
- **Polish (Phase 11)**: after the stories that are in scope.

### User Story Dependencies

```text
Setup → Foundational ─┬─ US1 ─┬─ US4 ─┐
                      │       ├─ US5 ─┼─ US6 ─ US7
                      │       └─ US8  │
                      └─ US2 ─ US3    │
                                      └─ Polish
```

### Within Each User Story

- Write the tests first, and they must fail.
- Requests and resources, then the service, then the job and controller, then routes.
- A story is complete at its checkpoint before the next priority starts, when working
  sequentially.

### Parallel Opportunities

- Phase 1: T003 ‖ T004.
- Phase 2: T005–T010, T012–T014, T016, T017, T020, T021 and T022 are all [P].
- US1: tests T025–T028 ‖, extractors T029–T032 ‖, then T034 ‖ T037.
- US2: T040 ‖ T041; T044 ‖ T045 after T042.
- US4: T049 ‖ T050 ‖ T052.
- US5: T055 ‖ T056 ‖ T057.
- US6: T062 ‖ T063; T065 ‖ T066 after T064.
- US7: T069 ‖ T070.
- US8: T075 ‖ T076.
- After US1, three developers can take **US2→US3**, **US4→US5**, and **US8** in parallel.
- Polish: T079–T084 and T087 are all [P]. T088 runs with T086, after T085.

---

## Parallel Example: User Story 1

```bash
# Tests first, all different files:
Task: "Create tests/Feature/KnowledgeDocumentControllerTest.php (T025)"
Task: "Create tests/Feature/KnowledgeDocumentExtractionTest.php (T026)"
Task: "Extend tests/Feature/TenantIsolationTest.php (T027)"
Task: "Extend the dataset in tests/Feature/PermissionAuthorizationTest.php (T028)"

# Extractors, independent files:
Task: "PdfExtractor in app/Support/Knowledge/Extraction/PdfExtractor.php (T029)"
Task: "DocxExtractor in app/Support/Knowledge/Extraction/DocxExtractor.php (T030)"
Task: "SpreadsheetExtractor in app/Support/Knowledge/Extraction/SpreadsheetExtractor.php (T031)"
Task: "PlainTextExtractor in app/Support/Knowledge/Extraction/PlainTextExtractor.php (T032)"
```

---

## Implementation Strategy

### MVP First (P1: US1 + US2 + US3)

1. Phase 1 Setup → Phase 2 Foundational.
2. **US1**: upload and index text formats. **Stop and validate**: a document is uploaded,
   indexed and searchable.
3. **US2**: citations and guest redaction.
4. **US3**: hotel-first, isolated retrieval, and the cascade fix.
5. Deploy or demo: hotels can upload documents, and the Concierge cites them and prefers
   them over general knowledge.

### Incremental Delivery

1. MVP (US1–US3).
2. Add US4 (recoverable failures) and US5 (lifecycle and corrections); staff can now
   maintain the knowledge base.
3. Add US6 (global management); this ships the breaking change with its docs (T081,
   T083).
4. Add US7 (rebuilds) and US8 (vision) in either order.
5. Polish (Phase 11).

### Parallel Team Strategy

After Foundational and US1:

- Developer A: US2 → US3 (search tool and agents)
- Developer B: US4 → US5 → US6 → US7 (document lifecycle and admin)
- Developer C: US8 (vision)

---

## Notes

- [P] = different files and no dependency on an unfinished task.
- Every story keeps the rule that no code path writes to a stored file, and every
  failure leaves the live chunks in place.
- Commit after each task or logical group, using `feat:` / `fix:` subjects matching the
  history. No co-author trailer (CLAUDE.md).
- Stop at any checkpoint to validate a story on its own.
