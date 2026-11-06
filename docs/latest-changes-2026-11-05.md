# Latest changes — 2026-11-05

## Super admin routes moved to `/api/superadmin` (spec 022)

Every super admin endpoint moved from `/api/admin` to `/api/superadmin`. Only the prefix
changes. Spec: `specs/022-superadmin-route-move`.

### Breaking

- **All 25 `/api/admin` endpoints now return `404`.** There is no alias or redirect.
- **The frontend and backend must deploy together.** Until the frontend calls the new
  addresses, its super admin screens get `404`.

### What the frontend needs to do

Change the prefix on every super admin call, using the table below. Nothing else changes:
requests, responses, status codes, permissions and login tokens all stay the same, and
nobody has to log in again.

Hotel-facing endpoints are not affected, including `/api/knowledge-documents`,
`/api/knowledge-base-articles` and the account's own usage report.

| Method | Old address | New address |
| --- | --- | --- |
| GET | `/api/admin/usage` | `/api/superadmin/usage` |
| GET | `/api/admin/ai-cost` | `/api/superadmin/ai-cost` |
| GET | `/api/admin/knowledge-documents/deleted` | `/api/superadmin/knowledge-documents/deleted` |
| GET | `/api/admin/knowledge-documents` | `/api/superadmin/knowledge-documents` |
| POST | `/api/admin/knowledge-documents` | `/api/superadmin/knowledge-documents` |
| GET | `/api/admin/knowledge-documents/{id}` | `/api/superadmin/knowledge-documents/{id}` |
| PUT | `/api/admin/knowledge-documents/{id}` | `/api/superadmin/knowledge-documents/{id}` |
| DELETE | `/api/admin/knowledge-documents/{id}` | `/api/superadmin/knowledge-documents/{id}` |
| POST | `/api/admin/knowledge-documents/{id}/file` | `/api/superadmin/knowledge-documents/{id}/file` |
| GET | `/api/admin/knowledge-documents/{id}/download` | `/api/superadmin/knowledge-documents/{id}/download` |
| GET | `/api/admin/knowledge-documents/{id}/text` | `/api/superadmin/knowledge-documents/{id}/text` |
| PUT | `/api/admin/knowledge-documents/{id}/text` | `/api/superadmin/knowledge-documents/{id}/text` |
| DELETE | `/api/admin/knowledge-documents/{id}/text` | `/api/superadmin/knowledge-documents/{id}/text` |
| POST | `/api/admin/knowledge-documents/{id}/reindex` | `/api/superadmin/knowledge-documents/{id}/reindex` |
| POST | `/api/admin/knowledge-documents/{id}/restore` | `/api/superadmin/knowledge-documents/{id}/restore` |
| GET | `/api/admin/knowledge-base-articles` | `/api/superadmin/knowledge-base-articles` |
| POST | `/api/admin/knowledge-base-articles` | `/api/superadmin/knowledge-base-articles` |
| GET | `/api/admin/knowledge-base-articles/{id}` | `/api/superadmin/knowledge-base-articles/{id}` |
| PUT | `/api/admin/knowledge-base-articles/{id}` | `/api/superadmin/knowledge-base-articles/{id}` |
| DELETE | `/api/admin/knowledge-base-articles/{id}` | `/api/superadmin/knowledge-base-articles/{id}` |
| POST | `/api/admin/knowledge/rebuilds` | `/api/superadmin/knowledge/rebuilds` |
| GET | `/api/admin/knowledge/rebuilds` | `/api/superadmin/knowledge/rebuilds` |
| GET | `/api/admin/knowledge/rebuilds/{id}` | `/api/superadmin/knowledge/rebuilds/{id}` |
| GET | `/api/admin/guest-memory-extractions` | `/api/superadmin/guest-memory-extractions` |
| POST | `/api/admin/guest-memory-extractions/{extraction}/rerun` | `/api/superadmin/guest-memory-extractions/{extraction}/rerun` |

### Access

Unchanged. Only super admins can use these endpoints. A hotel admin or employee gets `403`,
and a request without a valid login token or API key gets `401`.

### Documentation

The API docs now show the new addresses: `docs/usage-metering-api-documentation.md`,
`docs/ai-cost-attribution-api-documentation.md`,
`docs/global-knowledge-admin-api-documentation.md`,
`docs/guest-memories-api-documentation.md`,
`docs/knowledge-base-article-api-documentation.md`,
`docs/knowledge-document-api-documentation.md`, `docs/auth-api-documentation.md` and
`docs/staff-roles-api-documentation.md`.
