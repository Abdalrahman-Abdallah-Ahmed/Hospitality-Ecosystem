# Implementation Plan: Knowledge Documents and RAG

**Branch**: `008-knowledge-rag` (spec directory; the work currently sits on `main`, so
branch before implementing) | **Date**: 2026-10-07 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/008-knowledge-rag/spec.md`. This is Phase 8 of
the master plan: SPEC-060 (Knowledge Documents & Extraction), SPEC-064 (Retrieval
Precedence & Citations) and SPEC-065 (Global Knowledge Management).

**Depends on**: Phase 1 only (master plan §4: a parallel track). Phase 7 is already on
`main`, so this plan updates the Concierge's current instructions.

## Summary

**Documents (SPEC-060)**:
- A `KnowledgeDocument` model on the existing, unused `knowledge_documents` table,
  extended by a new migration (R1).
- Hotel routes under `knowledge_documents.*` cover upload, list, edit, replace, download,
  text correction, re-index, delete, restore. There are 5 new `Permission` cases and none
  is an employee default (R15).
- Uploads are type-checked from content, duplicate-checked by SHA-256 and stored on the
  private disk (R7, R8).

**Pipeline**:
- `IndexKnowledgeDocumentJob` runs: detect → extract to **located segments** (page,
  sheet, section) → normalize (NFKC, header/footer stripping) → chunk per segment → embed
  → **atomic swap** of the chunks (R2–R6).
- Extractors:
  - PDF text: `smalot/pdfparser` (the only new dependency);
  - DOCX: ZipArchive + DOM;
  - XLSX and CSV: PhpSpreadsheet (already installed);
  - TXT and MD: native.
- Images and scanned PDF pages go to a new `DocumentVisionAgent` (Gemini, structured
  output). A 50-page cap is checked before any call (R4).
- Pipeline rules:
  - idempotent through `WithoutOverlapping` plus an input fingerprint;
  - 3 retries for transient errors;
  - a failure never touches the file or the live chunks (R5, R19).
- `ChunkSynchronizer` is fixed so it embeds **before** deleting. Today it deletes first,
  so a failed embed wipes an article's chunks (R5).

**Lifecycle**:
- Replace keeps the old version live until the new one indexes (pending columns, R9).
- Corrections are edited per segment and survive re-index and rebuild (R10).
- Deactivate and delete flip `knowledge_chunks.is_active` in the same request (R13).
- Restore is possible for 30 days, then a daily purge job removes the row, chunks and
  files (R11).

**Retrieval and citations (SPEC-064)**:
- `KnowledgeSearchTool` runs two filtered vector queries: 5 hotel results, 3 global.
  Hotel results always come first and are labelled `hotel` / `general` (R13).
- Each result carries `{scope, source_type, title, location, last_updated, content}`.
  Title and date are read live from the source (R14).
- A `GUEST` audience removes general titles and locations in code (clarification Q6).
- The Concierge, Admin Advisor and Recommendation agents get citation and precedence
  instructions.

**Global management (SPEC-065)**:
- `routes/admin.php` gains global documents, global articles and index rebuilds with
  progress (R12, R16).
- A super admin can no longer write global articles through hotel routes. This is a
  breaking change, documented.

**Security fix**: the `hotel_id` foreign keys on chunks, documents and articles move from
`nullOnDelete` to `cascadeOnDelete`. Otherwise a force-deleted hotel's knowledge would
silently become global (R17).

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**:
- Laravel 13, `laravel/ai ^0.10` (agents, structured output, image and document
  attachments, embeddings), Sanctum, Pest 4;
- `phpoffice/phpspreadsheet` (present, through `maatwebsite/excel`);
- **new**: `smalot/pdfparser`;
- PHP extensions `intl`, `zip`, `fileinfo`.

**Storage**:
- PostgreSQL + pgvector (existing `vector(1536)` HNSW index).
- 4 migrations:
  - extend `knowledge_documents`;
  - `knowledge_chunks.is_active`;
  - `knowledge_index_rebuilds`;
  - cascading hotel foreign keys.
- Files on the private `local` disk (`storage/app/private/knowledge/...`), swappable
  through `config('knowledge.disk')`.

**Testing**:
- Pest feature tests on real Postgres (`Hospitality_Ecosystem_testing`).
- `Embeddings::fake()`, agent fakes for `DocumentVisionAgent`, `Storage::fake`,
  `Queue::fake`.
- Binary fixtures in `tests/Fixtures/knowledge/` (R20).

**Target Platform**: Laravel JSON API and queue workers. The frontend slice is in
[frontend-changes.md](frontend-changes.md).

**Project Type**: Multi-tenant hospitality PMS backend (web service)

**Performance Goals**:
- 95% of documents up to 10 MB are indexed within 5 minutes (SC-001). Text-layer
  extraction takes seconds. Embedding is one batched call per document; a vision call
  takes about 10–60 s.
- Search adds one extra filtered vector query and one eager load of at most 8 sources.
  This is negligible next to the LLM turn.
- Deactivate and delete take effect within the request: a single `UPDATE` on chunks
  (SC-005).

**Constraints**:
- **Tenant isolation**: search names `hotel_id` explicitly; jobs run in
  `TenantContext::runForHotel()`, or `withoutScope()` for global or purge work; hotel
  routes 404 global rows.
- **Live data never comes from RAG** (FR-029). This is stated in the instructions, and
  is already a constitution rule.
- **Source file immutability**: no code path writes to a stored file. Deletions happen
  only on purge, after a swap, or when a pending file is superseded (R8).
- **No AI vision past the cap**, and no provider calls in tests.
- **No ledger writes** (D11).

**Scale/Scope**: A few to a few hundred documents per hotel. The work is 4 migrations and
about 30 new classes:
- 1 model each for documents and rebuilds;
- 8 enums;
- 6 extractors and their registry;
- the normalizer;
- 1 service;
- 2 jobs;
- 1 agent;
- 3 controllers;
- about 6 requests;
- 2 resources;
- 1 policy;
- config and lang files.

About 12 existing classes change.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle / rule | Status | How |
| --- | --- | --- |
| I. PMS is system of record | ✅ | RAG serves descriptive knowledge only. The Concierge and Advisor instructions keep live data on PMS tools (FR-029) |
| II. Preserve and evolve | ✅ | Reuses the existing table, `ChunkSynchronizer`, `TextChunker`, `KnowledgeSearchTool` (same name), the article and policy observers, `SyncKnowledgeChunksJob`, metering, `AiCostContext` and the `knowledge:sync` command. Articles and policies are not merged into documents. The one removal is the super-admin global-article path on hotel routes, which moves to the admin file (SPEC-065) and is announced |
| III. Tenant isolation | ✅ | `BelongsToHotel` on documents; explicit `hotel_id` predicates in search; jobs set tenant context explicitly; global rows 404 on hotel routes; R17 fixes the `nullOnDelete` leak. Isolation tests cover requests and jobs (SC-002) |
| IV. Permission-based auth | ✅ | 5 `knowledge_documents.*` cases through `ChecksPermissions::allows()`; none in `employeeDefaults()`; global and rebuild actions in the `super_admin`-guarded file, outside the enum (CLAUDE.md rule 4); added to `PermissionAuthorizationTest` and the staff-roles doc |
| V. AI through tools | ✅ | Agents read knowledge only through `KnowledgeSearchTool`. The guest redaction of global titles is enforced in the tool, not the prompt. `DocumentVisionAgent` has no tools and writes nothing; the job writes |
| VI. Auditability | ✅ | `RecordsEvents` plus explicit document and rebuild events (data model). Indexing failures are recorded with their code. Usage is logged through `AiCostContext` and metered |
| VII. Integrity and idempotency | ✅ | Embed first, then delete and insert in one transaction with a row lock and a fingerprint check; `WithoutOverlapping`; dispatch after commit; partial unique indexes for duplicates; atomic counter increments for rebuilds |
| VIII. Tested at domain boundary | ✅ | 12 new and 5 extended test files ([quickstart](quickstart.md)); AI tested at the tool and job boundary with fakes; RAG isolation tested |
| IX. Spec-driven | ✅ | Spec plus 6 clarifications → this plan |
| Knowledge/RAG: formats PDF, DOCX, TXT, MD, images, Excel, CSV | ✅ | R3, R4. XLS and DOC are out by an explicit spec assumption |
| Knowledge/RAG: index rebuildable; failures never corrupt the source | ✅ | Rebuilds from stored segments (R12); files are never written (R8); the atomic swap keeps live chunks on failure (R5) |
| Knowledge/RAG: hotel precedence, isolation | ✅ | Two-query ordering and labels (R13); clarification Q2 accepts instruction-level conflict handling |
| Knowledge ops audited; Hotel Admin manages hotel knowledge, Super Admin manages global | ✅ | R15, R16 |
| Usage measurable; provider cost hidden from hotel admins | ✅ | Existing usage log; new `knowledge_pages_read` meter; no cost in hotel resources |
| Jobs establish tenant context explicitly | ✅ | R11, R19 |
| Rule 25: incrementally deployable | ⚠️ justified | The super-admin article flow changes, and the search tool's output format changes. See Complexity Tracking |

**Post-design re-check**: ✅. The one item marked ⚠️ is justified below. Every technical
question is resolved in research R1–R20; there are no NEEDS CLARIFICATION markers.

## Project Structure

### Documentation (this feature)

```text
specs/008-knowledge-rag/
├── spec.md
├── plan.md                              # this file
├── research.md                          # decisions R1–R20
├── data-model.md
├── quickstart.md
├── frontend-changes.md                  # ecosystem-frontend slice (D13)
├── contracts/
│   ├── knowledge-documents-api.md       # hotel routes
│   ├── admin-knowledge-api.md           # global documents, articles, rebuilds
│   └── knowledge-search-tool.md         # tool I/O, audiences, agent instructions
├── checklists/requirements.md
└── tasks.md                             # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Ai/
│   ├── Agents/
│   │   ├── DocumentVisionAgent.php                 # NEW: structured page transcription (R4)
│   │   ├── GuestConciergeAgent.php                 # GUEST audience + citation rules (R14)
│   │   ├── AdminAdvisorAgent.php                   # citation rules
│   │   └── RecommendationAgent.php                 # precedence rule
│   └── Tools/KnowledgeSearchTool.php               # two-query, citations, audience (R13, R14)
├── Console/Commands/SyncKnowledgeBaseCommand.php   # --hotel, --documents via rebuild service (R12)
├── Enums/
│   ├── KnowledgeDocumentStatus.php                 # NEW
│   ├── KnowledgeDocumentFailure.php                # NEW
│   ├── KnowledgeContentSource.php                  # NEW
│   ├── KnowledgeSourceType.php                     # NEW
│   ├── KnowledgeScope.php                          # NEW
│   ├── KnowledgeAudience.php                       # NEW
│   ├── KnowledgeRebuildScope.php                   # NEW
│   ├── KnowledgeRebuildStatus.php                  # NEW
│   ├── MeterFeature.php                            # + KNOWLEDGE_PAGES_READ (R18)
│   └── Permission.php                              # + 5 knowledge_documents.* cases (R15)
├── Http/
│   ├── Controllers/
│   │   ├── KnowledgeDocumentController.php         # NEW: hotel routes
│   │   ├── KnowledgeBaseArticleController.php      # super admin must name hotel (R16)
│   │   └── Admin/
│   │       ├── GlobalKnowledgeDocumentController.php   # NEW
│   │       ├── GlobalKnowledgeBaseArticleController.php # NEW
│   │       └── KnowledgeRebuildController.php      # NEW
│   ├── Requests/Knowledge/
│   │   ├── StoreKnowledgeDocumentRequest.php       # NEW (R7)
│   │   ├── UpdateKnowledgeDocumentRequest.php      # NEW
│   │   ├── ReplaceKnowledgeDocumentFileRequest.php # NEW
│   │   ├── CorrectKnowledgeDocumentTextRequest.php # NEW (R10)
│   │   └── StartKnowledgeRebuildRequest.php        # NEW
│   └── Resources/
│       ├── KnowledgeDocumentResource.php           # NEW
│       └── KnowledgeIndexRebuildResource.php       # NEW
├── Jobs/
│   ├── IndexKnowledgeDocumentJob.php               # NEW (R5, R19)
│   ├── PurgeDeletedKnowledgeDocumentsJob.php       # NEW (R11)
│   └── SyncKnowledgeChunksJob.php                  # rebuild counter, source_type metadata
├── Models/
│   ├── KnowledgeDocument.php                       # NEW
│   ├── KnowledgeIndexRebuild.php                   # NEW
│   └── KnowledgeChunk.php                          # + is_active
├── Policies/KnowledgeDocumentPolicy.php            # NEW (ChecksPermissions; before(): global → deny, super admin → allow)
├── Services/Knowledge/
│   ├── KnowledgeDocumentService.php                # NEW: upload, replace, edit, activate, correct, discard, reindex, delete, restore
│   └── KnowledgeRebuildService.php                 # NEW: enumerate + dispatch + counters (R12)
└── Support/Knowledge/
    ├── ChunkSynchronizer.php                       # syncSegments(), embed-before-swap (R5)
    ├── TextChunker.php                             # unchanged
    ├── TextNormalizer.php                          # NEW (R6)
    └── Extraction/
        ├── Extractor.php                           # NEW interface → list<Segment>
        ├── Segment.php                             # NEW value object {location, text}
        ├── ExtractionResult.php                    # NEW {segments, pageCount, scannedPages}
        ├── ExtractionFailed.php                    # NEW exception carrying KnowledgeDocumentFailure
        ├── ExtractorRegistry.php                   # NEW
        ├── PdfExtractor.php                        # NEW (smalot; scanned-page detection)
        ├── DocxExtractor.php                       # NEW
        ├── SpreadsheetExtractor.php                # NEW (XLSX + CSV)
        ├── PlainTextExtractor.php                  # NEW (TXT + MD)
        └── VisionExtractor.php                     # NEW (images + scanned PDF pages)
config/knowledge.php                                # NEW
database/migrations/
├── 2026_10_07_000001_extend_knowledge_documents_table.php
├── 2026_10_07_000002_add_is_active_to_knowledge_chunks_table.php
├── 2026_10_07_000003_create_knowledge_index_rebuilds_table.php
└── 2026_10_07_000004_cascade_knowledge_hotel_foreign_keys.php
lang/{en,ar}/knowledge.php                          # NEW: failure messages
routes/
├── api.php                                         # knowledge-documents routes
├── admin.php                                       # global documents, articles, rebuilds
└── console.php                                     # PurgeDeletedKnowledgeDocumentsJob daily 04:15
tests/
├── Fixtures/knowledge/                             # NEW binary fixtures (R20)
└── Feature/                                        # see quickstart.md table
docs/
├── knowledge-document-api-documentation.md         # NEW
├── global-knowledge-admin-api-documentation.md     # NEW
├── knowledge-base-article-api-documentation.md     # super-admin change
├── staff-roles-api-documentation.md                # + 5 permissions
└── latest-changes-2026-10-07.md                    # breaking changes
```

**Structure Decision**: The existing single Laravel application, in the layers CLAUDE.md
sets out:
- `KnowledgeDocumentService` is in `app/Services`, because it has two callers: the hotel
  and admin controllers.
- Extractors and the normalizer are framework-adjacent helpers in
  `app/Support/Knowledge`, next to `ChunkSynchronizer` and `TextChunker`.
- Queued work is in `app/Jobs`, with its schedule in `routes/console.php`.
- Every new status, type or scope column gets an enum.
- Admin controllers go in `app/Http/Controllers/Admin`, as the existing usage and cost
  controllers do.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Breaking change: a super admin can no longer create or list **global** articles through `/knowledge-base-articles`; they must name a hotel there, and global articles move to `/api/admin/knowledge-base-articles` | SPEC-065 (FR-031, FR-033): global knowledge is written only inside the `super_admin`-guarded file | Keeping both paths leaves a second, unguarded way to change what every tenant's assistant says |
| Breaking change: `KnowledgeSearchTool` output changes from raw rows to cited JSON | Citations and scope labels (FR-023, FR-025) need a stable shape | The tool's only consumers are our own agents; their instructions change in the same release. No external client depends on it |
| New dependency `smalot/pdfparser` | No PDF reader is installed, and PDFs are the most common hotel document | Poppler needs a binary on every worker; vision for every PDF costs money on pages that already have text (R3) |
| Foreign key behaviour change on three tables (`nullOnDelete` → `cascadeOnDelete`) | `hotel_id IS NULL` means global, so the current rule turns a deleted hotel's private knowledge into everyone's (R17) | Leaving it as it is means relying on hotels never being force-deleted, which is a latent cross-tenant leak |
