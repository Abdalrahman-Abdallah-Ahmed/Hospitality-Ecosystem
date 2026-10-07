# Quickstart: Validating Knowledge Documents and RAG

This guide shows how to prove the feature works. The contracts are in
[contracts/](contracts/), the schema in [data-model.md](data-model.md), and the decisions
in [research.md](research.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d      # Postgres + pgvector (required)
composer require smalot/pdfparser                   # the only new dependency (R3)
php artisan migrate
php -m | grep -Ei 'intl|zip|fileinfo'                # NFKC, DOCX/XLSX, type detection
```

Queue workers that run `IndexKnowledgeDocumentJob` need `--timeout` of at least 310 seconds,
just above the job's own 300 (`php artisan queue:work --timeout=310`). `composer dev` already
uses `--timeout=0`.

`.env` needs the existing Gemini key (embeddings and vision). Tests fake both, so CI
needs no key.

## Automated suite

```bash
php artisan test --filter=Knowledge
php artisan test --filter=PermissionAuthorizationTest
php artisan test --filter=TenantIsolationTest
./vendor/bin/pint
```

| Test file | Proves | Spec |
| --- | --- | --- |
| `tests/Feature/KnowledgeDocumentControllerTest.php` | Upload → 201 `uploaded`, job queued after commit; type, size and mismatch rejections store nothing; duplicate → 409; list filters; edit title; download streams for permitted users only | US1, US5, FR-001–007, FR-011 |
| `tests/Feature/KnowledgeDocumentExtractionTest.php` | One fixture per format reaches `indexed` with located segments; CSV in Windows-1256 with `;`; XLSX headings kept, two sheets cited; DOCX sections and tables; header/footer stripping; Arabic NFKC | US1, FR-012–014 |
| `tests/Feature/KnowledgeDocumentVisionTest.php` | Images and scanned pages go to vision (faked) with only the scanned page numbers; a text-layer page never does; > 50 scanned pages fails with no AI call and no meter event; `knowledge_pages_read` metered; empty vision result → `no_text` | US8, FR-015/015a/015b |
| `tests/Feature/KnowledgeDocumentIndexingTest.php` | Atomic swap: a failing embed keeps the old chunks; two runs → one chunk set; stale fingerprint discarded; permanent failure = no retry; transient = 3 retries then `failed`; file bytes unchanged after every failure (SC-006); delete mid-run leaves no chunks | US4, FR-016–018 |
| `tests/Feature/KnowledgeDocumentReplaceTest.php` | Old chunks searchable during replace; swap promotes and deletes the old file; a failed replace keeps the old version live | US4-4, FR-008 |
| `tests/Feature/KnowledgeDocumentCorrectionTest.php` | Correct → re-index from segments with no extraction; kept on reindex and rebuild; dropped on replace or discard; location mismatch → 422; `corrected_by/at` shown | US5-9/10, FR-015c/d |
| `tests/Feature/KnowledgeDocumentLifecycleTest.php` | Deactivate and delete remove a document from search in the same request (SC-005); reactivate and restore with no job; deleted list; purge after 30 days removes the row, chunks and files and keeps the audit | US5, FR-009–010b |
| `tests/Feature/KnowledgeSearchToolTest.php` (extended) | Hotel before general; two-query limits; `is_active` filter; citation fields; live title; guest audience redacts general sources | US2, US3, FR-022–026 |
| `tests/Feature/KnowledgeRetrievalIsolationTest.php` | Three hotels plus global: 0 cross-hotel results from requests and from a job (SC-002); a force-deleted hotel's chunks cascade away and never become global (R17) | US3, FR-022, FR-040 |
| `tests/Feature/Admin/GlobalKnowledgeTest.php` | A super admin manages global documents and articles in `/api/admin`; hotel admins and employees get 403; global rows 404 on hotel routes; pre-existing global articles listed | US6, FR-031–034 |
| `tests/Feature/KnowledgeBaseArticleControllerTest.php` (extended) | A super admin must send `hotel_id` on index and store (422 without it) | FR-033, R16 |
| `tests/Feature/Admin/KnowledgeRebuildTest.php` | Rebuild `all`, `hotel` and `global`: counts, failures listed, corrections kept, search answers during the rebuild | US7, FR-020/021 |
| `tests/Feature/ConciergeKnowledgeCitationTest.php` | Agent wiring: Concierge = guest audience; instructions contain the citation and precedence rules | FR-024, FR-026–028 |
| `tests/Feature/PermissionAuthorizationTest.php` (dataset) | Each `knowledge_documents.*` endpoint: allowed with the permission, 403 without it; none in `employeeDefaults()` | FR-035–037 |
| `tests/Feature/TenantIsolationTest.php` (extended) | Another hotel cannot list, show, download, edit, correct, reindex, delete or restore a document | FR-040 |
| `tests/Feature/KnowledgeChunkSyncTest.php` (extended) | Articles and policies: embed-before-delete; `location = null`, `source_type` set | R5, FR-021 |

Fixtures: `tests/Fixtures/knowledge/` (R20).

## Manual end-to-end check (local)

Run `composer dev` (server, queue and vite). Then:

1. **Upload**: as a hotel admin, `POST /api/knowledge-documents` (multipart) with
   `house-rules.pdf`, `title=House Rules 2026`. Expect `201` with `status: uploaded`.
2. **Indexing**: poll `GET /api/knowledge-documents/{id}`. Expect `extracting` →
   `indexed`, with `chunk_count > 0` and `page_count` set.
3. **Concierge answer**: send a WhatsApp test message from an in-house guest: "What time
   is checkout?". Expect an answer that names "House Rules 2026" (US1, US2).
4. **Precedence**: as a super admin, `POST /api/admin/knowledge-base-articles` with
   "Standard checkout is 12:00" and publish it. Ask again. Expect 11:00 from the hotel
   rules. A second hotel with no rules gets 12:00, described as "general information"
   with no title (US3).
5. **Deactivate**: `PUT /api/knowledge-documents/{id}` with `{ "is_active": false }`. Ask
   again. Expect the general 12:00 answer at once (SC-005).
6. **Correct**: `GET …/text`, edit page 1's text, `PUT …/text`. After the job, search
   returns the corrected wording, and the downloaded file is byte-identical to the
   upload.
7. **Scanned**: upload a 2-page scanned PDF. Expect `scanned_page_count: 2` and a
   `knowledge_pages_read` meter event of 2. Upload a 51-page scan: expect `failed`,
   `too_many_scanned_pages`, and no AI usage log row.
8. **Delete / restore**: delete, then confirm it is gone from search; restore, then
   confirm it is back with no job queued.
9. **Rebuild**: `POST /api/admin/knowledge/rebuilds {"scope":"all"}`. Poll until
   `completed`. The same questions return the same sources (SC-007).

## Docs to update (FR-041)

- `docs/knowledge-document-api-documentation.md` (new): hotel routes.
- `docs/global-knowledge-admin-api-documentation.md` (new): admin routes and rebuilds.
- `docs/knowledge-base-article-api-documentation.md`: the super-admin `hotel_id` change.
- `docs/staff-roles-api-documentation.md`: 5 new permissions.
- `docs/latest-changes-2026-10-07.md`: breaking: super-admin article management moved;
  search tool result format changed.
