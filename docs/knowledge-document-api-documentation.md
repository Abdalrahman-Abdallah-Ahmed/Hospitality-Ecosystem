# Knowledge Document API Documentation

Knowledge documents are files a hotel uploads so its AI assistants can answer from them: house rules as a PDF, a spa menu as a Word file, a shuttle timetable as a spreadsheet, a photo of a price board. The system reads each file in the background, splits it into searchable passages, and shows the document's indexing status.

Defined by `App\Http\Controllers\KnowledgeDocumentController` (the shared actions live in `App\Http\Controllers\Concerns\HandlesKnowledgeDocuments`), gated by `App\Policies\KnowledgeDocumentPolicy`, with the business rules in `App\Services\Knowledge\KnowledgeDocumentService`. Indexing is `App\Jobs\IndexKnowledgeDocumentJob`.

These routes are always about **one hotel's** documents. Global documents (shared platform knowledge, `hotel_id = null`) are managed by super admins under `/api/admin` — see the [global knowledge admin API](/D:/Hospitality%20Ecosystem/docs/global-knowledge-admin-api-documentation.md). A global document id is a `404` on every route here.

## Base URL and Headers

All endpoints are under `/api` and need:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
```

Uploads (`POST /knowledge-documents`, `POST /knowledge-documents/{id}/file`) are `multipart/form-data`; everything else is JSON.

## Endpoints and Permissions

| Method & path | Permission | Purpose |
| --- | --- | --- |
| `GET /api/knowledge-documents` | `knowledge_documents.view` | List |
| `POST /api/knowledge-documents` | `knowledge_documents.create` | Upload |
| `GET /api/knowledge-documents/{id}` | `knowledge_documents.view` | Show |
| `PUT /api/knowledge-documents/{id}` | `knowledge_documents.update` | Edit title, category, active flag |
| `POST /api/knowledge-documents/{id}/file` | `knowledge_documents.update` | Replace the file |
| `GET /api/knowledge-documents/{id}/download` | `knowledge_documents.view` | Download the original file |
| `GET /api/knowledge-documents/{id}/text` | `knowledge_documents.view` | Read the extracted (or corrected) text |
| `PUT /api/knowledge-documents/{id}/text` | `knowledge_documents.update` | Correct the text |
| `DELETE /api/knowledge-documents/{id}/text` | `knowledge_documents.update` | Discard corrections |
| `POST /api/knowledge-documents/{id}/reindex` | `knowledge_documents.reindex` | Index again |
| `DELETE /api/knowledge-documents/{id}` | `knowledge_documents.delete` | Delete (restorable for 30 days) |
| `GET /api/knowledge-documents/deleted` | `knowledge_documents.delete` | List deleted documents that can still be restored |
| `POST /api/knowledge-documents/{id}/restore` | `knowledge_documents.delete` | Restore |

Admins hold every permission. Employees hold what their [staff role](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md) grants; **an employee without a role has none of these**.

**Super admins** must name the hotel with `hotel_id` (query string, or form field on uploads). Without it every route answers `422 A hotel_id is required.`; an unknown hotel is `404`.

**Another hotel's document is always `404`, never `403`**, so the API does not reveal that it exists.

## The Knowledge Document Object

```json
{
  "id": "01a116cf-ebaa-7090-a386-5a6e5680687c",
  "hotel_id": "01a116cf-eaae-7034-97d6-a38c265b4e7d",
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
  "uploaded_by": { "id": "…", "name": "Mona" },
  "indexed_at": "2026-10-07T09:12:44.000000Z",
  "created_at": "…",
  "updated_at": "…",
  "deleted_at": null
}
```

Field notes:

- `status` is where the latest indexing run is: `uploaded` → `extracting` → `indexed`, or `failed`. It does **not** say whether the document is searchable: while a document is re-indexed, or after a replacement fails, its previous passages stay live.
- **Searchable** means: not deleted, `is_active: true`, and `chunk_count > 0`.
- `failure_code` / `failure_message`: set only when `status` is `failed`. The message is plain language, in English or Arabic per the request locale. See [Failure codes](#failure-codes).
- `scanned_page_count`: pages (or the image) read by AI vision on the last extraction. Each one is metered as `knowledge_pages_read` usage.
- `content_source`: `corrected` when staff have corrected the text (`corrected_at` and `corrected_by` say when and by whom).
- `has_pending_replacement`: a new file was uploaded and is waiting to be indexed; the current version is still the one searched and downloaded.
- `uploaded_by` is `null` when the user was removed — show "Removed user".
- `restorable_until` appears only in the deleted list.
- `debug.error` (internal detail) is included for admins only.
- Storage paths, disks and file hashes are never returned.

## Allowed Files

| Extension | Notes |
| --- | --- |
| `pdf` | Text pages are read directly. Pages with no text layer (scans) are read by AI vision, at most **50 such pages per document**. |
| `docx` | Each heading starts a section; tables are kept as "Header: value" rows. Old `.doc` is not supported. |
| `xlsx` | Every sheet; the first row is the header row. Old `.xls` is not supported. Up to 20,000 rows. |
| `csv` | Comma, semicolon, tab or pipe separated; UTF-8, UTF-16 or Windows-1256 (Arabic Excel exports). Up to 20,000 rows. |
| `txt`, `md` | Markdown is split by heading. |
| `jpg`, `jpeg`, `png`, `webp` | Read by AI vision: the visible text plus a short description. |

The limit is **20 MB per file**. The type is checked from the file's content, not just its name, so a PNG renamed `.pdf` is refused. Password-protected files are not supported.

## 1. List — `GET /api/knowledge-documents`

| Param | Example | Behavior |
| --- | --- | --- |
| `filter[status]` | `filter[status]=failed` | Exact match. Any real column can be filtered (`is_active`, `category`, `content_source`…). |
| `search` | `search=Spa` | Title search (case-sensitive `LIKE`). |
| `sort` | `sort=-indexed_at` | Any real column; `-` for descending. |
| `page`, `per_page` | `per_page=25` | Pagination (1–100, default 15). |

Deleted documents are not listed. `body` is a paginator; `body.data` holds document objects.

## 2. Upload — `POST /api/knowledge-documents` (multipart)

| Field | Rule |
| --- | --- |
| `file` | required; ≤ 20 MB; an [allowed file](#allowed-files) whose content matches its extension |
| `title` | required, string, max 255 — this is the name the AI cites |
| `category` | optional, one of the [knowledge base categories](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md#allowed-category-values) |
| `is_active` | optional boolean, default `true` |
| `hotel_id` | super admins only, required for them |

Responses:

- `201`: the document with `status: "uploaded"`. The upload returns at once; indexing runs in the background. Poll `GET /api/knowledge-documents/{id}` until it is `indexed` or `failed`.
- `409`: the same file is already uploaded to this hotel:

  ```json
  { "message": "This file was already uploaded as “House Rules 2026”.", "code": 409, "body": { "existing": { "id": "…", "title": "House Rules 2026" } } }
  ```

- `422`: validation, for example `"The file type does not match its extension."` or `"Password-protected Office files are not supported."`. Nothing is stored.

## 3. Show — `GET /api/knowledge-documents/{id}`

`200` with the document object.

## 4. Edit — `PUT /api/knowledge-documents/{id}`

Send any of `title`, `category`, `is_active`. Nothing is re-indexed:

- a new title is cited from the next answer on;
- `is_active: false` removes the document from every search **within the same request**; `true` brings it back.

## 5. Replace the File — `POST /api/knowledge-documents/{id}/file` (multipart)

Body: `file` (same rules as upload). `202` with `has_pending_replacement: true`.

- The current version stays searchable until the new one is indexed; then the new one replaces it and the old file is deleted.
- If the new file cannot be read (corrupt, encrypted, no text…), it is discarded: the current version stays live, and the document shows `failed` with the reason until it is indexed again. Re-indexing and corrections then work on the current version; upload a fixed file to try the replacement again. A replacement that failed only for a temporary reason (or the usage limit) is kept, so a re-index retries it.
- Staff corrections are dropped: a new file starts from its own text.
- `409` when the new file is identical to another document of this hotel.

## 6. Download — `GET /api/knowledge-documents/{id}/download`

Streams the current file as an attachment under its original name. There is no public link to stored files.

## 7. Extracted Text — `GET | PUT | DELETE /api/knowledge-documents/{id}/text`

The text is kept as **segments**, one per page, sheet row range or section, which is what citations point to.

`GET` → `200`:

```json
{
  "id": "…",
  "content_source": "extracted",
  "corrected_at": null,
  "corrected_by": null,
  "segments": [
    { "location": "Page 1", "text": "Welcome to the Grand Hotel.\nCheck-in starts at 15:00." },
    { "location": "Page 2", "text": "The pool is open until 22:00." }
  ]
}
```

`PUT` corrects the text (for example a time AI vision misread):

```json
{ "segments": [ { "location": "Page 1", "text": "…" }, { "location": "Page 2", "text": "The pool is open until 21:00." } ] }
```

- Send every segment, with the same `location`s in the same order; only `text` may change. Otherwise `422`.
- An empty `text` takes that page out of the index (its place is kept).
- `202`: the document is re-indexed from the corrected text. The stored file is never changed.
- Corrections are kept through re-indexing and platform rebuilds. They are dropped when the file is replaced or staff discard them.
- `422` when the document has no extracted text yet.

`DELETE` discards the corrections: `202`, and the text is extracted again from the stored file.

## 8. Re-index — `POST /api/knowledge-documents/{id}/reindex`

`202`. Uses the stored (or corrected) text when there is one; otherwise the file is read again. A pending replacement file is processed first. Useful after a temporary failure.

## 9. Delete, Deleted List, Restore

- `DELETE /api/knowledge-documents/{id}` → `200`. The document leaves search and the list at once.
- `GET /api/knowledge-documents/deleted` lists documents deleted in the last 30 days, each with `restorable_until`.
- `POST /api/knowledge-documents/{id}/restore` → `200`. It is searchable again straight away (if active), with no re-indexing. `404` after 30 days.
- After 30 days a nightly job removes the document, its passages and its files for good. Its audit entries remain.
- A deleted document does not block uploading the same file again.

## Failure Codes

| `failure_code` | Meaning | Staff can… |
| --- | --- | --- |
| `unsupported_type` | No reader for this file type | Upload a supported format |
| `encrypted` | Password-protected | Remove the password, replace the file |
| `corrupt` | The file cannot be read | Save it again, replace the file |
| `duplicate` | The replacement became identical to another document while it was processed | Keep the other one, or replace with a different file |
| `no_text` | No readable text was found | Replace the file, or add an article instead |
| `too_many_scanned_pages` | More than 50 scanned pages (checked before any AI is used) | Split the scan into smaller files |
| `too_many_rows` | More than 20,000 spreadsheet rows | Split the file |
| `too_large_for_vision` | Too large to send for scanned-page reading (over 14 MB) | Upload a smaller scan |
| `file_missing` | The stored file is gone | Replace the file |
| `usage_limit_reached` | The account's AI usage limit was reached | Re-index later |
| `extraction_unavailable` / `embedding_unavailable` | Still failing after 3 automatic retries | Re-index later |

A failure never changes or deletes the stored file, and never removes passages that were live before.

## How the AI Uses Documents

The Concierge, the Admin Advisor and the Recommendation agent search documents together with knowledge base articles, hotel policies and the global knowledge. Every result is cited:

```json
{ "rank": 1, "scope": "hotel", "source_type": "document", "title": "House Rules 2026", "location": "Page 3", "last_updated": "2026-10-01", "content": "Checkout is at 11:00." }
```

- This hotel's results (`scope: "hotel"`) are always listed before general ones (`scope: "general"`), and the assistants are told to follow the hotel source when the two disagree.
- The Concierge names hotel sources by title to guests and calls general knowledge "general information"; guests are never given a global source's title, file name or location.
- Knowledge is never used for live data: availability, bookings, prices on a date and statuses always come from the PMS.

## Audit

Every action is recorded in the audit trail with the actor, under subject `knowledge_document`:

- `created` and `updated`;
- `activated`, `deactivated`;
- `replacement_uploaded`, `replaced`;
- `text_corrected`, `text_correction_discarded`;
- `reindex_requested`, `indexed`, `index_failed` (with the failure code);
- `deleted`, `restored`, `purged`.

## Example cURL

```bash
curl -X POST http://your-domain.com/api/knowledge-documents \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -F "file=@house-rules-2026.pdf" \
  -F "title=House Rules 2026"
```

## Related Docs

- [Global Knowledge Admin API](/D:/Hospitality%20Ecosystem/docs/global-knowledge-admin-api-documentation.md)
- [Knowledge Base Article API](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md)
- [Staff Roles API](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md) — permission reference
- [Usage Metering API](/D:/Hospitality%20Ecosystem/docs/usage-metering-api-documentation.md) — `knowledge_pages_read`
