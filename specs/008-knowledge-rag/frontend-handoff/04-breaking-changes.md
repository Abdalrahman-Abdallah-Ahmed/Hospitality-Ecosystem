# 04 — Changes to existing screens

## 1. Knowledge Base Articles page: super-admin mode (BREAKING)

Before, a super admin opening this page (`/api/knowledge-base-articles`) saw and created **global** articles (`hotel_id: null`). That no longer works.

| Call by a super admin | Before | Now |
| --- | --- | --- |
| `GET /api/knowledge-base-articles` | Global articles | `422 "A hotel_id is required."` unless `?hotel_id=…`; with it, that hotel's articles |
| `POST /api/knowledge-base-articles` | Created a global article | Needs `hotel_id` in the body (`422` without it, `404` for an unknown hotel); creates the article **for that hotel** |
| `GET/PUT/DELETE /api/knowledge-base-articles/{id}` on a global article | Worked | `404` |

What the UI needs to do:

- On this page, for super admins, **add a hotel picker** and send `hotel_id` on list and create.
- **Remove the "global knowledge base" mode** from this page. Global articles live in the new Global Knowledge page → Articles tab ([02](02-global-knowledge-admin.md)).
- Hotel admins and employees: nothing changes.

## 2. Role editor (staff roles)

There is no code change: the editor reads `GET /api/permissions`, which now includes 5 new permissions in a `knowledge_documents` group:

`knowledge_documents.view`, `.create`, `.update`, `.delete`, `.reindex`

Check that:

- they render and are grouped correctly;
- there are labels or help texts. Suggested: "View documents", "Upload documents", "Edit, replace and correct documents", "Delete and restore documents", "Re-index documents";
- none of them is in `employee_defaults`, which needs no UI change.

## 3. Navigation

- New hotel menu item **Knowledge Documents**, next to Knowledge Base and Hotel Policies. Show it when the user has `knowledge_documents.view` (admins always have it).
- New super-admin menu item **Global Knowledge**.

## 4. Usage page

The usage endpoints may now return a new feature, `knowledge_pages_read` (category `cost_driver`, unit `pages`, label "Knowledge pages read by AI"). If the page renders features generically from `label` and `unit`, nothing to do; otherwise add it. `embeddings_generated` ("Knowledge base indexing") now also counts documents. Hotel admins still never see provider cost.

## 5. Nothing else

- The WhatsApp, guest and concierge screens are unchanged.
- How the assistants answer changed (they now cite sources, and hotel sources take precedence over global ones), but no UI shows these results.
