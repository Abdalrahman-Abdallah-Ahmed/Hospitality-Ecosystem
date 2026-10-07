# Contract: Hotel Knowledge Documents API

Stack: `api.key → auth:sanctum → throttle:api → tenant → policy`. Every response uses
`apiResponse(message, code, body)` → `{ message, code, body }`. Bodies go through
`KnowledgeDocumentResource`.

The hotel is the user's own. A **super admin** must send `hotel_id`, as a query parameter
or form field; without it the request is `422` (`resolveHotel()`). Rows with
`hotel_id IS NULL` (global) are **404** on every route here (FR-034). Another hotel's
document is **404**, never 403, so no existence is leaked.

| Method & path | Permission | Purpose |
| --- | --- | --- |
| `GET /knowledge-documents` | `knowledge_documents.view` | List |
| `POST /knowledge-documents` | `knowledge_documents.create` | Upload (multipart) |
| `GET /knowledge-documents/{id}` | `knowledge_documents.view` | Show |
| `PUT /knowledge-documents/{id}` | `knowledge_documents.update` | Edit title, category, `is_active` |
| `POST /knowledge-documents/{id}/file` | `knowledge_documents.update` | Replace the file (multipart) |
| `GET /knowledge-documents/{id}/download` | `knowledge_documents.view` | Download the original (stream) |
| `GET /knowledge-documents/{id}/text` | `knowledge_documents.view` | Extracted or corrected segments |
| `PUT /knowledge-documents/{id}/text` | `knowledge_documents.update` | Correct the text |
| `DELETE /knowledge-documents/{id}/text` | `knowledge_documents.update` | Discard corrections → re-extract |
| `POST /knowledge-documents/{id}/reindex` | `knowledge_documents.reindex` | Re-index |
| `DELETE /knowledge-documents/{id}` | `knowledge_documents.delete` | Delete (restorable for 30 days) |
| `GET /knowledge-documents/deleted` | `knowledge_documents.delete` | List deleted, restorable |
| `POST /knowledge-documents/{id}/restore` | `knowledge_documents.delete` | Restore |

## `KnowledgeDocumentResource`

```json
{
  "id": "uuid",
  "hotel_id": "uuid",
  "title": "House Rules 2026",
  "category": "hospitality_best_practices",
  "original_filename": "house-rules-2026.pdf",
  "mime_type": "application/pdf",
  "size": 482113,
  "status": "indexed",
  "failure_code": null,
  "failure_message": null,
  "is_active": true,
  "page_count": 12,
  "scanned_page_count": 2,
  "chunk_count": 31,
  "content_source": "extracted",
  "corrected_at": null,
  "corrected_by": null,
  "has_pending_replacement": false,
  "uploaded_by": { "id": "uuid", "name": "Mona" },
  "indexed_at": "2026-10-07T09:12:44Z",
  "created_at": "…",
  "updated_at": "…",
  "deleted_at": null,
  "restorable_until": null
}
```

- `failure_message` is the plain-language reason in the request locale (`en` or `ar`),
  from `lang/*/knowledge.php`.
- `uploaded_by` is `null` when the user was removed; the frontend shows "Removed user".
- `restorable_until` = `deleted_at + 30 days`, present only on the deleted list.
- `error` (internal) is added only for admins, under `debug.error`.
- `path`, `disk` and hashes are never returned.

## List — `GET /knowledge-documents`

This is `GenericIndexRequest` + `GenericQuery::apply()`:
- filters `status`, `category`, `is_active`, `content_source`;
- search on `title`;
- sort `title`, `created_at`, `indexed_at` (`-` for descending);
- pagination.

Soft-deleted rows are excluded.

## Upload — `POST /knowledge-documents` (multipart)

| Field | Rule |
| --- | --- |
| `file` | required; ≤ 20 MB; type detected from content ∈ {pdf, docx, xlsx, csv, txt, md, jpeg, png, webp} and matching the extension |
| `title` | required, string ≤ 255 |
| `category` | nullable, `KnowledgeBaseCategory` value |
| `is_active` | optional boolean, default true |
| `hotel_id` | super admin only, required for them |

Responses:
- `201`: document with `status: "uploaded"`, returned before extraction (FR-001).
- `409`: `{ message: "This file was already uploaded.", body: { existing: { id, title } } }`.
- `422`: validation, e.g. `file: "The file type does not match its extension."` or
  `"Password-protected Office files are not supported."`.

## Replace file — `POST /knowledge-documents/{id}/file`

Body: `file` (same rules). Responses:
- `202` with `has_pending_replacement: true` and `status: "uploaded"`. Search keeps the
  old version until the swap.
- `409` when the file is a duplicate of another live document.

## Edit — `PUT /knowledge-documents/{id}`

Body: any of `title`, `category`, `is_active`. Responses:
- `200`. A title change is visible in citations at once.
- `is_active` flips the chunks in the same transaction, so search reflects it within the
  request.

## Text — `GET | PUT | DELETE /knowledge-documents/{id}/text`

`GET 200`:

```json
{ "content_source": "corrected", "corrected_at": "…", "corrected_by": { "id": "…", "name": "…" },
  "segments": [ { "location": "Page 1", "text": "…" }, { "location": "Page 2", "text": "…" } ] }
```

`PUT` body:
- `{ "segments": [ { "location": "Page 1", "text": "corrected…" }, … ] }`;
- the locations must equal the stored ones, in order; an empty `text` removes that
  segment from the index.

Responses: `202` (re-index queued from the segments); `422` on a location mismatch or
when there are no segments yet.

`DELETE`: `202`, corrections dropped, re-extraction queued from the stored file.

## Reindex — `POST /knowledge-documents/{id}/reindex`

`202`. The job uses stored segments, or extracts when there are none. A pending
replacement is processed first if present.

## Delete, deleted list, restore

- `DELETE /knowledge-documents/{id}`: `200`. Hidden from search and from the list.
- `GET /knowledge-documents/deleted`: rows deleted in the last 30 days, with
  `restorable_until`.
- `POST /knowledge-documents/{id}/restore`: `200`. Searchable again if active. `404` after
  30 days.

## Download — `GET /knowledge-documents/{id}/download`

`200` streams the current file as an attachment with `original_filename`. It is never a
public URL.

## Status codes summary

`401` no token · `403` missing permission · `404` not in your hotel, global, or purged ·
`409` duplicate · `422` validation · `202` accepted (async work queued).

## Breaking change — `/knowledge-base-articles` (R16)

A super admin calling `index` or `store` **must** send `hotel_id`; the article belongs to
that hotel. Global articles are no longer listed or created here: use
`/api/admin/knowledge-base-articles`. Hotel users are unaffected.
