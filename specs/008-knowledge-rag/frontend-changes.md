# Frontend Slice: Knowledge Documents and RAG (`ecosystem-frontend`)

D13: each phase lists its frontend slice. The work runs in `ecosystem-frontend` against
the contracts in [contracts/](contracts/).

## Hotel: Knowledge Documents page

Shown when the user has `knowledge_documents.view`; actions are gated by the matching
permission.

- **List**: title, category, status badge (*uploaded*, *extracting*, *indexed*,
  *failed*), active toggle, corrected marker, `indexed_at`, uploader (shows "Removed user"
  when null). Filters: status, category, active; title search.
- **Polling**: refresh every 10 s while any row is `uploaded` or `extracting`. No
  realtime transport is needed (constitution).
- **Upload dialog**: file (20 MB; PDF, DOCX, XLSX, CSV, TXT, MD, JPEG, PNG, WebP), title,
  category. A 409 shows "Already uploaded as *title*" with a link to it.
- **Detail**: metadata edit, replace file (shows "new version processing; current version
  still live"), download, re-index, delete.
- **Failed reason**: show `failure_message`, plus a "Re-index" or "Replace file" call to
  action.
- **Text editor**: one editable panel per segment (`location` as the heading); save →
  "Re-indexing…"; "Discard corrections" when `content_source = corrected`.
- **Deleted tab** (`knowledge_documents.delete`): shows `restorable_until` and a Restore
  button.
- **Role editor**: picks up the 5 new permissions from `GET /api/permissions` with no
  frontend change.

## Super admin: Global Knowledge page

- Tabs for **Documents** (the same UI as above, against `/api/admin/knowledge-documents`)
  and **Articles** (against `/api/admin/knowledge-base-articles`).
- A **Rebuild** panel: scope picker (all, one hotel, global); progress bar from
  `succeeded`, `failed` and `total`; a failures table.
- Remove the old "global" mode from the hotel Knowledge Base Articles page. A super admin
  there must pick a hotel (R16).
