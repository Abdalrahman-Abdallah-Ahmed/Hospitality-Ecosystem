# 03 — API reference for the frontend

Every request needs these headers:

```
X-API-KEY: <api key>
Authorization: Bearer <token>
Accept: application/json
```

Uploads are `multipart/form-data`; everything else is JSON.

## Response envelopes

```ts
// Success, and errors raised by the app (401/403/404/409, and 422 "A hotel_id is required.")
type ApiEnvelope<T> = { message: string; code: number; body: T };

// Field validation errors (422) use Laravel's own shape: no `code`, no `body`
type ValidationError = { message: string; errors: Record<string, string[]> };

// Paginated lists: body is a Laravel paginator
type Paginated<T> = {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { current_page: number; from: number | null; last_page: number; per_page: number; to: number | null; total: number; path: string; links: unknown[] };
};

// 409 duplicate (upload, replace, restore)
type DuplicateBody = { existing: { id: string; title: string } };
```

Handling `422`: if the response has `errors`, show the field errors. If it has `code` and `body: null`, show `message`. One case of the second kind is a super admin using the hotel routes without `hotel_id`: "A hotel_id is required."

## Types

```ts
type KnowledgeDocumentStatus = 'uploaded' | 'extracting' | 'indexed' | 'failed';

type KnowledgeDocumentFailure =
  | 'unsupported_type' | 'encrypted' | 'corrupt' | 'duplicate' | 'no_text'
  | 'too_many_scanned_pages' | 'too_many_rows' | 'too_large_for_vision' | 'file_missing'
  | 'usage_limit_reached' | 'extraction_unavailable' | 'embedding_unavailable';

type KnowledgeCategory =
  | 'hospitality_best_practices' | 'guest_personas' | 'communication_guidelines'
  | 'revenue_strategies' | 'destination_knowledge' | 'seasonal_knowledge' | 'recommendation_rules';

type UserRef = { id: string; name: string } | null;

interface KnowledgeDocument {
  id: string;
  hotel_id: string | null;          // null = global (admin area only)
  title: string;
  category: KnowledgeCategory | null;
  original_filename: string;
  mime_type: string;                // e.g. application/pdf, text/markdown, image/png
  size: number;                     // bytes
  status: KnowledgeDocumentStatus;
  failure_code: KnowledgeDocumentFailure | null;
  failure_message: string | null;   // translated, plain language
  is_active: boolean;
  page_count: number | null;        // pages (PDF), sheets (XLSX), 1 otherwise
  scanned_page_count: number;       // pages or images read by AI vision
  chunk_count: number;              // > 0 means it has live passages
  content_source: 'extracted' | 'corrected';
  corrected_at: string | null;
  corrected_by: UserRef;
  has_pending_replacement: boolean;
  uploaded_by: UserRef;             // null → "Removed user"
  indexed_at: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  restorable_until?: string;        // only in the deleted list
  debug?: { error: string };        // admins only, when there is an internal error
}

interface KnowledgeDocumentText {
  id: string;
  content_source: 'extracted' | 'corrected';
  corrected_at: string | null;
  corrected_by: UserRef;
  segments: { location: string | null; text: string }[];
}

interface KnowledgeIndexRebuild {
  id: string;
  scope: 'all' | 'hotel' | 'global';
  hotel_id: string | null;
  status: 'running' | 'completed';
  total: number;
  succeeded: number;
  failed: number;
  failures: { source_type: 'document' | 'article' | 'policy'; source_id: string; title: string | null; reason: string }[];
  requested_by: UserRef;            // null when started from the CLI
  started_at: string;
  finished_at: string | null;
}
```

"Searchable" means `!deleted_at && is_active && chunk_count > 0`. `status` only describes the latest processing run.

## Hotel endpoints

A super admin adds `hotel_id` (query string, or a form field on uploads) to every call below.

| Method | Path | Body | Success | Errors |
| --- | --- | --- | --- | --- |
| GET | `/api/knowledge-documents` | query: `filter[...]`, `search`, `sort`, `page`, `per_page` | 200 `Paginated<KnowledgeDocument>` | 403 |
| POST | `/api/knowledge-documents` | multipart: `file`, `title`, `category?`, `is_active?` | 201 `KnowledgeDocument` (`status: uploaded`) | 403, 409 `DuplicateBody`, 422 |
| GET | `/api/knowledge-documents/{id}` | — | 200 `KnowledgeDocument` | 403, 404 |
| PUT | `/api/knowledge-documents/{id}` | JSON: any of `title`, `category`, `is_active` | 200 `KnowledgeDocument` | 403, 404, 422 |
| POST | `/api/knowledge-documents/{id}/file` | multipart: `file` | 202 `KnowledgeDocument` | 403, 404, 409, 422 |
| GET | `/api/knowledge-documents/{id}/download` | — | 200 file stream (attachment) | 403, 404 |
| GET | `/api/knowledge-documents/{id}/text` | — | 200 `KnowledgeDocumentText` | 403, 404 |
| PUT | `/api/knowledge-documents/{id}/text` | JSON: `{ segments: {location, text}[] }` (same locations, same order) | 202 `KnowledgeDocumentText` | 403, 404, 422 |
| DELETE | `/api/knowledge-documents/{id}/text` | — | 202 `KnowledgeDocument` | 403, 404 |
| POST | `/api/knowledge-documents/{id}/reindex` | — | 202 `KnowledgeDocument` | 403, 404 |
| DELETE | `/api/knowledge-documents/{id}` | — | 200 `body: null` | 403, 404 |
| GET | `/api/knowledge-documents/deleted` | query as for the list | 200 `Paginated<KnowledgeDocument>` (with `restorable_until`) | 403 |
| POST | `/api/knowledge-documents/{id}/restore` | — | 200 `KnowledgeDocument` | 403, 404 (past 30 days), 409 `DuplicateBody` |

Permissions:

- `view`: list, show, download, text;
- `create`: upload;
- `update`: edit, file, text PUT and DELETE;
- `reindex`: reindex;
- `delete`: delete, deleted, restore.

## Admin endpoints (super admin only)

- `/api/admin/knowledge-documents/...`: identical to the hotel endpoints above, for global documents. No `hotel_id`.
- `/api/admin/knowledge-base-articles`: `GET` (list), `POST`, `GET /{id}`, `PUT /{id}`, `DELETE /{id}`, with the existing article object shape and `hotel_id: null`.
- `POST /api/admin/knowledge/rebuilds`, body `{ scope: 'all'|'hotel'|'global', hotel_id?: string }`:
  - `202` → `KnowledgeIndexRebuild`;
  - `422` when `hotel_id` is missing for `hotel`, or is sent with another scope.
- `GET /api/admin/knowledge/rebuilds?page=&per_page=` → `Paginated<KnowledgeIndexRebuild>`.
- `GET /api/admin/knowledge/rebuilds/{id}` → `KnowledgeIndexRebuild`.

## Upload limits (for client-side validation)

- Extensions: `pdf, docx, xlsx, csv, txt, md, jpg, jpeg, png, webp`.
- Max size: 20 MB.
- Scanned PDF pages read by AI: at most 50 per document. This is only known after processing (`too_many_scanned_pages`), so it can't be validated client-side.
