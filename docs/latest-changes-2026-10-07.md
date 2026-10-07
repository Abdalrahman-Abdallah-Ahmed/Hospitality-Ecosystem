# Latest Changes: 2026-10-07

## Knowledge Documents and RAG (SPEC-060, SPEC-064, SPEC-065, Phase 8)

### Summary

Hotels can now upload files for their assistants to answer from: PDF, Word, Excel, CSV, text, Markdown, and images. Each file is read in the background into searchable passages:

- scanned pages and photos are read by AI vision, at most 50 per document;
- staff see each document's status, and get a plain reason when one fails;
- staff can correct the extracted text, replace the file, switch a document off, and delete or restore it for 30 days.

Every knowledge search result now says where it came from, and this hotel's own knowledge always comes before the shared general knowledge:

- the Concierge names hotel sources by title and calls general knowledge "general information";
- the Admin Advisor cites title, page and date.

Global knowledge has its own super-admin area under `/api/admin`, with tracked index rebuilds.

### Breaking changes

1. **Super admins no longer manage global articles through `/api/knowledge-base-articles`.**
   - A super admin must send `hotel_id` on `index` and `store` there (`422` without it), and the article belongs to that hotel.
   - A global article is a `404` on `show`, `update` and `destroy` for every caller.
   - Global articles are managed at `/api/admin/knowledge-base-articles`. Existing ones appear there unchanged.
   - Hotel admins and employees are unaffected.
2. **`KnowledgeSearchTool` returns cited JSON.**
   - Each result is `{rank, scope: "hotel"|"general", source_type, title, location, last_updated, content}`, hotel results first. The old raw rows (`category`, `metadata`, `hotel_id`) are gone.
   - Only our own agents use this tool, and their instructions changed with it.
   - For guests, a general source has `title` and `location` set to `null` and `source_type: "general"`.
3. **Deleting a hotel now deletes its knowledge.**
   - The `hotel_id` foreign keys of `knowledge_chunks`, `knowledge_documents` and `knowledge_base_articles` now cascade. Before, a force-deleted hotel's articles and chunks would have become global, read by every hotel.
   - Hotels are still soft-deleted, so in practice this applies only to a force delete.

### What's new

- **Hotel API** `/api/knowledge-documents`: list, upload, show, edit, replace file, download, view/correct/discard text, re-index, delete, list deleted, restore. See the [knowledge document API](/D:/Hospitality%20Ecosystem/docs/knowledge-document-api-documentation.md).
- **Admin API** `/api/admin/knowledge-documents`, `/api/admin/knowledge-base-articles` and `/api/admin/knowledge/rebuilds`. See the [global knowledge admin API](/D:/Hospitality%20Ecosystem/docs/global-knowledge-admin-api-documentation.md).
- **Uploads**:
  - checked from the file's content, not its name;
  - up to 20 MB;
  - the same file twice in one hotel is a `409` that points to the existing document.
- **Corrections** are made per page or section, kept through re-indexing and rebuilds, and dropped when the file is replaced.
- **Inactive or deleted documents** leave search within the same request, with no re-indexing.
- **Re-indexing is atomic** for every source, articles and policies included. A failed run leaves the previous passages searchable; before, a failed embedding could empty an article's passages.
- **Deleted documents** are removed for good after 30 days by `PurgeDeletedKnowledgeDocumentsJob`, daily at 04:15. Their audit entries remain.
- **`php artisan knowledge:sync`** gains `--hotel=`, `--global` and `--documents`, and creates a tracked rebuild.

### Permissions

Five new permissions, **none of them an employee default**:

- `knowledge_documents.view`
- `knowledge_documents.create`
- `knowledge_documents.update`
- `knowledge_documents.delete`
- `knowledge_documents.reindex`

The role editor picks them up from `GET /api/permissions`. Global knowledge and rebuilds are super-admin only and are not permissions. See the [permission reference](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md#permission-reference).

### Usage

- New meter `knowledge_pages_read` (cost driver, unit `pages`): one per image or scanned PDF page read by AI vision.
- `embeddings_generated` is now also recorded for documents.
- Both are recorded for hotel sources only; global knowledge is logged as platform cost.

### Database

| Migration | Change |
| --- | --- |
| `2026_10_07_000001_extend_knowledge_documents_table` | Status, failure code, segments, correction, pending-replacement and index columns; check constraints; one-live-copy-per-scope unique indexes |
| `2026_10_07_000002_add_is_active_to_knowledge_chunks_table` | `knowledge_chunks.is_active`, a partial index |
| `2026_10_07_000003_create_knowledge_index_rebuilds_table` | Rebuild tracking |
| `2026_10_07_000004_cascade_knowledge_hotel_foreign_keys` | Breaking change 3 |

### Operations

- New dependency: `smalot/pdfparser`. PHP needs `intl`, `zip` and `fileinfo`.
- Queue workers running `IndexKnowledgeDocumentJob` need `--timeout=310` or more: a run may take up to 300 s.
- AI vision uses `KNOWLEDGE_VISION_PROVIDER` (default `gemini`) and optionally `KNOWLEDGE_VISION_MODEL`.
- Files are stored on `KNOWLEDGE_DISK` (default `local`, private).
