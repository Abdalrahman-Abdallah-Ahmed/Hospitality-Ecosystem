# Frontend Handoff — Knowledge Documents & RAG (Phase 8, backend branch `008-knowledge-rag`)

Backend source of truth: `D:\Hospitality Ecosystem` (Laravel). The backend work is done and tested. These files describe what `ecosystem-frontend` needs to build or change.

## Files in this handoff

| File | What it covers |
| --- | --- |
| [01-hotel-knowledge-documents.md](01-hotel-knowledge-documents.md) | **New** hotel page: Knowledge Documents (list, upload, detail, replace, text editor, deleted tab) |
| [02-global-knowledge-admin.md](02-global-knowledge-admin.md) | **New** super-admin page: Global Knowledge (documents, articles, index rebuilds) |
| [03-api-reference.md](03-api-reference.md) | Endpoints, request and response shapes, TypeScript types, error shapes |
| [04-breaking-changes.md](04-breaking-changes.md) | **Changes to existing screens**: the Knowledge Base Articles page for super admins, the role editor, usage |

## Work list

1. **API layer**: types and client functions for `/api/knowledge-documents/*`, `/api/admin/knowledge-documents/*`, `/api/admin/knowledge-base-articles/*` and `/api/admin/knowledge/rebuilds/*` (see 03).
2. **Hotel: Knowledge Documents module** (see 01). Gated by `knowledge_documents.view`; each action is gated by its own permission.
3. **Super admin: Global Knowledge page** (see 02), with tabs for Documents, Articles and Rebuilds.
4. **Existing Knowledge Base Articles page**: a super admin must pick a hotel there, and the old "global" mode is removed (see 04).
5. **Role editor**: nothing to code. The 5 new permissions come from `GET /api/permissions`. Check that they render and are grouped under `knowledge_documents` (see 04).
6. **Usage screen**: a new meter `knowledge_pages_read` may appear. Check that it renders by its `label` (see 04).
7. **i18n**: new strings for English and Arabic. Failure reasons come already translated from the API (`failure_message`); don't hard-code them.

## Ground rules from the backend

- Every response is `{ message, code, body }`, except `422` validation errors, which use Laravel's shape `{ message, errors: { field: [..] } }`.
- Uploads are `multipart/form-data`.
- **Processing is asynchronous**: an upload returns `status: "uploaded"`. Poll the document until it is `indexed` or `failed`. There is no websocket.
- Another hotel's document, and any global document on the hotel routes, is **404, not 403**. Treat both as "not found".
- A super admin using the **hotel** routes must send `hotel_id` (query string, or form field on uploads), or gets `422 A hotel_id is required.`
