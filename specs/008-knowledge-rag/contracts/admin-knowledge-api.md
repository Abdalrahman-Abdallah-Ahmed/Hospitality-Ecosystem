# Contract: Global Knowledge Admin API

All routes live in `routes/admin.php`, which `bootstrap/app.php` registers with prefix
`api/admin` and the stack `api.key → auth:sanctum → throttle:api → tenant →
super_admin`. Routes are added at the top level of the file; per CLAUDE.md there is no
`Route::group()`. Every route is super-admin only by construction (FR-031); everyone else
gets `403` from the `super_admin` middleware. Every record here has `hotel_id IS NULL`,
except rebuilds with `scope = hotel`.

## Global documents

These are the same actions and request and response shapes as
[knowledge-documents-api.md](knowledge-documents-api.md), on global rows. There is no
`hotel_id` field: a sent one is ignored, and the document is global.

| Method & path | Purpose |
| --- | --- |
| `GET /api/admin/knowledge-documents` | List global documents (FR-032); never hotel rows |
| `POST /api/admin/knowledge-documents` | Upload a global document |
| `GET /api/admin/knowledge-documents/{id}` | Show |
| `PUT /api/admin/knowledge-documents/{id}` | Edit title, category, `is_active` |
| `POST /api/admin/knowledge-documents/{id}/file` | Replace the file |
| `GET /api/admin/knowledge-documents/{id}/download` | Download |
| `GET\|PUT\|DELETE /api/admin/knowledge-documents/{id}/text` | View, correct, discard |
| `POST /api/admin/knowledge-documents/{id}/reindex` | Re-index |
| `DELETE /api/admin/knowledge-documents/{id}` | Delete (restorable for 30 days) |
| `GET /api/admin/knowledge-documents/deleted` | Deleted, restorable |
| `POST /api/admin/knowledge-documents/{id}/restore` | Restore |

A hotel document id on these routes returns `404`. Duplicates are checked among global
documents only.

## Global articles

| Method & path | Purpose |
| --- | --- |
| `GET /api/admin/knowledge-base-articles` | List `hotel_id IS NULL` articles, including those created before this feature (US6-4) |
| `POST /api/admin/knowledge-base-articles` | Create a global article (fields as in `docs/knowledge-base-article-api-documentation.md`, minus `hotel_id`) |
| `GET /api/admin/knowledge-base-articles/{id}` | Show |
| `PUT /api/admin/knowledge-base-articles/{id}` | Update; the existing observer re-syncs chunks |
| `DELETE /api/admin/knowledge-base-articles/{id}` | Delete; the existing observer removes chunks |

Body: `KnowledgeBaseArticleResource`. A hotel article id returns `404`.

## Rebuilds (R12)

### `POST /api/admin/knowledge/rebuilds`

| Field | Rule |
| --- | --- |
| `scope` | required: `all` \| `hotel` \| `global` |
| `hotel_id` | required when `scope = hotel`, must exist; prohibited otherwise |

`202`:

```json
{ "id": "uuid", "scope": "hotel", "hotel_id": "uuid", "status": "running",
  "total": 48, "succeeded": 0, "failed": 0, "failures": [],
  "requested_by": { "id": "uuid", "name": "Platform Ops" },
  "started_at": "2026-10-07T10:00:00Z", "finished_at": null }
```

What a rebuild covers:
- Active, non-deleted documents are re-indexed from stored segments (corrections kept,
  no AI vision). A document without segments is extracted from its file.
- Published articles and active policies in scope are re-synced.
- `all` = every hotel + global; `global` = `hotel_id IS NULL` only.

### `GET /api/admin/knowledge/rebuilds`

A paginated list, newest first.

### `GET /api/admin/knowledge/rebuilds/{id}`

Progress, using the same body as above. `failures`:
`[{ "source_type": "document", "source_id": "uuid", "title": "…", "reason":
"no_text" }]`. Failed sources keep their previous passages.

## Audit

Every write here records an `EventLogger` event with the super admin as actor and
`hotel_id = null` (scope `hotel` rebuilds record the hotel). The events are listed in
[data-model.md](../data-model.md#audit-events-eventlogger).
