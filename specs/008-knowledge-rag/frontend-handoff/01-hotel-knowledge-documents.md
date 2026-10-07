# 01 — Hotel page: Knowledge Documents

Hotel staff upload files (PDF, Word, Excel, CSV, text, Markdown, images) that the AI assistants answer from. The backend reads each file in the background and shows its status.

## Access

| UI element | Permission |
| --- | --- |
| Menu item and page | `knowledge_documents.view` |
| Upload button | `knowledge_documents.create` |
| Edit metadata, active toggle, replace file, text editor (save and discard) | `knowledge_documents.update` |
| Re-index button | `knowledge_documents.reindex` |
| Delete button, "Deleted" tab, Restore | `knowledge_documents.delete` |

- Admins have every permission.
- An employee without a staff role has **none** of these. Hide the menu item for them.
- Super admins use this page only after picking a hotel. Send `hotel_id` on every call.

## Screen: list

Endpoint: `GET /api/knowledge-documents`. It is paginated, using the Laravel paginator in `body` (`data`, `links`, `meta`).

Columns:

- **Title**: links to the detail page.
- **Category**: optional; one of the knowledge base categories (see 03).
- **Status badge**:
  - `uploaded`: grey, "Waiting";
  - `extracting`: blue with a spinner, "Reading…";
  - `indexed`: green, "Ready";
  - `failed`: red, "Failed", with `failure_message` as a tooltip.
- **Active** toggle (`is_active`): calls `PUT /api/knowledge-documents/{id}` with `{ is_active }`. The change is immediate on the server, so update optimistically.
- **Corrected** marker when `content_source === "corrected"`.
- **Pages**: `page_count`, plus "(n scanned)" when `scanned_page_count > 0`.
- **Last indexed**: `indexed_at`.
- **Uploaded by**: `uploaded_by.name`, or "Removed user" when it is `null`.

Filters and search:

- Filters are query params: `filter[status]=failed`, `filter[category]=…`, `filter[is_active]=1|0`, `filter[content_source]=corrected`.
- Title search uses `search=…`. It is **case-sensitive** (`LIKE`), so pass the user's text as is.
- Sort with `sort=title|created_at|indexed_at`, prefixed `-` for descending.

**Polling**: while any row on the page has `status` `uploaded` or `extracting`, refetch every ~10 s, and stop when none does. The same applies on the detail page.

## Upload dialog

Endpoint: `POST /api/knowledge-documents`, multipart.

| Field | UI | Rule |
| --- | --- | --- |
| `file` | File picker | Accept `.pdf,.docx,.xlsx,.csv,.txt,.md,.jpg,.jpeg,.png,.webp`, max **20 MB** (check client-side too) |
| `title` | Text, required | Max 255. Explain it: "This is the name the assistant uses when it quotes this document." |
| `category` | Select, optional | Knowledge base categories |
| `is_active` | Checkbox, default on | |

Responses:

- `201`: close the dialog, add the row (status `uploaded`), and start polling.
- `409`: a duplicate. Show `message` ("This file was already uploaded as “…”.") with a link to `body.existing.id`.
- `422`: show `errors.file[0]` and the like. Likely messages:
  - "The file type does not match its extension." (for example a renamed file);
  - "Password-protected Office files are not supported.";
  - a size or extension error.

Helper text under the picker: "Scanned PDFs and photos are read by AI, up to 50 scanned pages per document. Old .doc and .xls files must be saved as .docx or .xlsx first. Password-protected files are not supported."

## Screen: detail

Endpoint: `GET /api/knowledge-documents/{id}`.

- **Header**: title, status badge, active toggle, and the actions Download, Replace file, Re-index, Delete (each gated by its permission).
- **Failed banner** (when `status === "failed"`): show `failure_message` (plain language, already in the user's locale), plus a call to action:
  - Re-index for `extraction_unavailable`, `embedding_unavailable` and `usage_limit_reached`;
  - Replace file for `corrupt`, `encrypted`, `no_text`, `too_many_scanned_pages`, `too_many_rows`, `too_large_for_vision`, `file_missing` and `duplicate`;
  - admins also get `debug.error` (internal detail) in a collapsible "Technical details".
- **Pending replacement** (`has_pending_replacement: true`): an info banner, "A new version is being processed. The current version stays live until it is ready."
- **Metadata form**: `title` and `category`, saved with `PUT /api/knowledge-documents/{id}` (no re-processing happens).
- **Download**: `GET /api/knowledge-documents/{id}/download` streams the file. Fetch with the auth headers as a blob and save it with `original_filename`. A plain `<a href>` won't work, because the request needs `X-API-KEY` and the bearer token.
- **Replace file**: `POST /api/knowledge-documents/{id}/file`, multipart, field `file`.
  - `202` → start polling.
  - `409` → duplicate (same handling as upload).
  - Warn before confirming: "Text corrections will be discarded when the new file is ready."
- **Re-index**: `POST /api/knowledge-documents/{id}/reindex` → `202` → start polling.
- **Delete**: `DELETE /api/knowledge-documents/{id}` → `200`, then go back to the list. Confirm text: "The assistant stops using it immediately. You can restore it for 30 days."

## Text editor (corrections)

Endpoint: `GET /api/knowledge-documents/{id}/text` returns `body`:

```json
{ "id": "…", "content_source": "extracted", "corrected_at": null, "corrected_by": null,
  "segments": [ { "location": "Page 1", "text": "…" }, { "location": "Page 2", "text": "…" } ] }
```

- Show one panel per segment, with `location` as its heading ("Page 3", "Sheet Prices, rows 2–41", "Section: Pool", "Image", "Document") and the text in a textarea. Use `dir="auto"`: text may be Arabic.
- `location` is **read-only**. Segments cannot be added, removed or reordered.
- **Save**: `PUT /api/knowledge-documents/{id}/text` with `{ "segments": [ ...all segments, same order, same locations ] }`.
  - `202` → status goes back to processing; poll.
  - An empty textarea is allowed: it drops that page from search.
  - `422` means the locations changed, or there is no text yet. Show `errors.segments[0]`.
- **Discard corrections** (shown only when `content_source === "corrected"`): `DELETE /api/knowledge-documents/{id}/text` → `202`, and the text is read from the file again. Confirm first.
- Show "Corrected by X on DATE" when it is corrected.
- If `segments` is empty (never indexed), show "Text is available once the document is indexed" and disable Save.

## "Deleted" tab

Shown only with `knowledge_documents.delete`.

- Endpoint: `GET /api/knowledge-documents/deleted` (paginated). It lists documents deleted in the last 30 days.
- Columns: title, `deleted_at`, `restorable_until` (show it as "Restorable until DATE").
- **Restore**: `POST /api/knowledge-documents/{id}/restore`.
  - `200` → the document is back in the list, searchable again straight away.
  - `404` → too late (past 30 days).
  - `409` → the same file was uploaded again while this one was deleted. Show `message` and link to `body.existing.id`.

## Empty and loading states

- No documents: "Upload your hotel's documents — house rules, menus, timetables — and the assistant will answer guests from them."
- The upload button is disabled while uploading; show upload progress (files go up to 20 MB).

## Strings to translate (EN and AR)

Status labels, banner texts, confirm dialogs, helper texts and empty states. Failure reasons come translated from the API (`failure_message`, following the request locale), so don't translate failure codes yourself.
