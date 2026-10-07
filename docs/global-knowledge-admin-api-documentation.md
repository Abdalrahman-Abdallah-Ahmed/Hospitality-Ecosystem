# Global Knowledge Admin API Documentation

Global knowledge is platform knowledge that **every hotel's assistant reads**: general hospitality guidance, standard practices, etiquette. Because it changes what every tenant's AI says, it is managed only by super admins, only from `routes/admin.php`.

Every route here is registered under the `api/admin` prefix behind `api.key → auth:sanctum → throttle:api → tenant → super_admin`. Anyone who is not a super admin gets `403`, whatever their staff role grants.

Every record here has `hotel_id = null`. A hotel's own document or article id is a `404` on these routes, just as a global id is a `404` on the hotel routes.

## Global Documents

These are the same actions, request fields, responses and rules as the [hotel knowledge document API](/D:/Hospitality%20Ecosystem/docs/knowledge-document-api-documentation.md), with two differences:

- they act on global documents;
- `hotel_id` is ignored if sent.

| Method & path | Purpose |
| --- | --- |
| `GET /api/admin/knowledge-documents` | List global documents (never hotel ones) |
| `POST /api/admin/knowledge-documents` | Upload a global document (multipart) |
| `GET /api/admin/knowledge-documents/{id}` | Show |
| `PUT /api/admin/knowledge-documents/{id}` | Edit title, category, `is_active` |
| `POST /api/admin/knowledge-documents/{id}/file` | Replace the file |
| `GET /api/admin/knowledge-documents/{id}/download` | Download |
| `GET\|PUT\|DELETE /api/admin/knowledge-documents/{id}/text` | View, correct, discard corrections |
| `POST /api/admin/knowledge-documents/{id}/reindex` | Re-index |
| `DELETE /api/admin/knowledge-documents/{id}` | Delete (restorable for 30 days) |
| `GET /api/admin/knowledge-documents/deleted` | Deleted, restorable |
| `POST /api/admin/knowledge-documents/{id}/restore` | Restore |

Notes:

- **Duplicates** are checked among global documents only. A hotel may hold the same file as a global document.
- **Usage**: AI work on global documents (embedding, vision) is platform cost. It appears in the AI usage log with no account, and records no meter event.
- **In search**: once indexed, a global document is part of every hotel's search, labelled `scope: "general"` and listed after the hotel's own results. Guests are never shown its title or location.

## Global Articles

| Method & path | Purpose |
| --- | --- |
| `GET /api/admin/knowledge-base-articles` | List global articles, including those created through the hotel routes before 2026-10-07 |
| `POST /api/admin/knowledge-base-articles` | Create a global article |
| `GET /api/admin/knowledge-base-articles/{id}` | Show |
| `PUT /api/admin/knowledge-base-articles/{id}` | Update; published articles are re-indexed |
| `DELETE /api/admin/knowledge-base-articles/{id}` | Delete; the article leaves search |

The fields and the article object are those of the [knowledge base article API](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md#the-knowledge-base-article-object). `hotel_id` is always `null` (a sent value is ignored). Only `status: "published"` articles are searchable.

## Index Rebuilds

A rebuild re-indexes knowledge in bulk, for example after a change to how passages are embedded:

- **Documents** are rebuilt from their stored text. Staff corrections are kept, and no file is read and no AI vision is used. A document without stored text is read from its file.
- **Published articles and active hotel policies** are re-synced.
- Search keeps answering throughout: each source swaps its passages all at once.
- A source that fails keeps the passages it had, and is listed with the reason.

### `POST /api/admin/knowledge/rebuilds`

| Field | Rule |
| --- | --- |
| `scope` | required: `all` (every hotel and global), `hotel` (one hotel) or `global` (global only) |
| `hotel_id` | required when `scope` is `hotel`, must exist; not allowed otherwise |

`202`:

```json
{
  "message": "Rebuild started.",
  "code": 202,
  "body": {
    "id": "…",
    "scope": "hotel",
    "hotel_id": "…",
    "status": "running",
    "total": 48,
    "succeeded": 0,
    "failed": 0,
    "failures": [],
    "requested_by": { "id": "…", "name": "Platform Ops" },
    "started_at": "2026-10-07T10:00:00.000000Z",
    "finished_at": null
  }
}
```

A rebuild with nothing in scope comes back `completed` straight away.

### `GET /api/admin/knowledge/rebuilds` and `GET /api/admin/knowledge/rebuilds/{id}`

The list is paginated, newest first (`per_page` 1–100). Each rebuild shows its progress:

- `status` becomes `completed` once `succeeded + failed = total`;
- `failures` lists each source that failed, for example `{"source_type": "document", "source_id": "…", "title": "Lost file", "reason": "file_missing"}`.

### From the command line

`php artisan knowledge:sync [--hotel=<id> | --global] [--documents]` starts the same tracked rebuild:

- without `--documents`, it covers articles and policies only, as before;
- with `--documents`, it covers knowledge documents too.

## Audit

Every write is recorded in the audit trail with the super admin as actor:

- document events, as listed in the [knowledge document API](/D:/Hospitality%20Ecosystem/docs/knowledge-document-api-documentation.md#audit);
- `knowledge_index_rebuild.started` and `knowledge_index_rebuild.completed`.

## Related Docs

- [Knowledge Document API](/D:/Hospitality%20Ecosystem/docs/knowledge-document-api-documentation.md)
- [Knowledge Base Article API](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md)
- [AI Cost Attribution API](/D:/Hospitality%20Ecosystem/docs/ai-cost-attribution-api-documentation.md)
