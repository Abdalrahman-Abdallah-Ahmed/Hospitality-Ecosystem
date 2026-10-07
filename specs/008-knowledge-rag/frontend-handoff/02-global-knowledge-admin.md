# 02 — Super-admin page: Global Knowledge

Global knowledge is platform knowledge that **every hotel's assistant** reads. Only super admins manage it, under `/api/admin/...`. Everyone else gets `403` there; hide the menu item for any role except `super_admin`.

The page has three tabs.

## Tab 1: Documents

These are the same screens and components as the hotel [Knowledge Documents page](01-hotel-knowledge-documents.md). Reuse them, with three differences:

- the base path is `/api/admin/knowledge-documents` instead of `/api/knowledge-documents`;
- no `hotel_id` is sent (it is ignored), and there is no hotel picker;
- there are no permission checks in the UI: everything is allowed for a super admin.

| Hotel route | Admin route |
| --- | --- |
| `GET /api/knowledge-documents` | `GET /api/admin/knowledge-documents` |
| `POST /api/knowledge-documents` | `POST /api/admin/knowledge-documents` |
| `GET\|PUT\|DELETE /api/knowledge-documents/{id}` | `GET\|PUT\|DELETE /api/admin/knowledge-documents/{id}` |
| `POST …/{id}/file`, `GET …/{id}/download`, `GET\|PUT\|DELETE …/{id}/text`, `POST …/{id}/reindex`, `POST …/{id}/restore`, `GET …/deleted` | the same under `/api/admin/knowledge-documents` |

Every document here has `hotel_id: null`. Add a hint at the top: "Every hotel's assistant reads these documents. Guests see them as general information, never by title."

**Suggestion**: make the documents module take a `scope: { kind: 'hotel', hotelId? } | { kind: 'global' }` prop that sets the base path and whether `hotel_id` is sent.

## Tab 2: Articles

These are global knowledge base articles. They used to be managed on the old Knowledge Base Articles page in "super admin mode"; that mode moves here (see [04](04-breaking-changes.md)).

| Action | Endpoint |
| --- | --- |
| List | `GET /api/admin/knowledge-base-articles` (paginated; same filter, search and sort params as the hotel list) |
| Create | `POST /api/admin/knowledge-base-articles` (JSON) |
| Show | `GET /api/admin/knowledge-base-articles/{id}` |
| Update | `PUT /api/admin/knowledge-base-articles/{id}` |
| Delete | `DELETE /api/admin/knowledge-base-articles/{id}` |

The article object and form fields are the same as the existing hotel Knowledge Base Articles form:

- `title`, required;
- `content`, required;
- `category`, an enum;
- `tags`, an array;
- `status`, `draft` or `published`;
- `version`.

Reuse that form without a hotel field. Only `published` articles are used by the assistants.

## Tab 3: Index rebuilds

A rebuild re-indexes knowledge in bulk. It is rarely needed, so keep it in a secondary tab.

**Start panel**:

- a **Scope** radio:
  - "All hotels and global" → `scope: "all"`;
  - "One hotel" → `scope: "hotel"`, which shows a hotel picker and sends `hotel_id`;
  - "Global only" → `scope: "global"`;
- **Start** → `POST /api/admin/knowledge/rebuilds` with `{ scope, hotel_id? }`;
- `202` → add the rebuild to the history and poll it;
- `422` (for example a hotel scope without a hotel) → show the field errors;
- confirm text: "Every document, article and policy in scope is indexed again. The assistants keep answering throughout."

**History table**: `GET /api/admin/knowledge/rebuilds`, paginated, newest first.

- Columns: started (`started_at`), scope (plus the hotel), requested by (`requested_by.name`, or "System" when `null`, as with CLI rebuilds), progress, status, finished (`finished_at`).
- **Progress**: a bar of `(succeeded + failed) / total`, with "succeeded ✓ / failed ✗ of total".
- **Status**: `running` (poll `GET /api/admin/knowledge/rebuilds/{id}` every ~5 s) or `completed`.
- **Expand row** → the `failures` table: `source_type` (document, article or policy), `title`, `reason`.
  - For documents, the reason is a failure code such as `file_missing` or `no_text`. Show it as is, or map it to the same labels used elsewhere.
  - Each failed source keeps its previous version, which the UI can say: "Failed sources keep their previous version."
