# Knowledge Base Article API Documentation

This document describes the knowledge-base-article management APIs, defined by `App\Http\Controllers\KnowledgeBaseArticleController` and gated by `App\Policies\KnowledgeBaseArticlePolicy`.

A "knowledge base article" is a hotel-scoped content entry (e.g. an FAQ, a destination guide, a communication-tone guideline) used to ground the hotel's AI assistant. It is conceptually similar to a [hotel policy](/D:/Hospitality%20Ecosystem/docs/hotel-policy-api-documentation.md), but with an editorial `status` (`draft` / `published`) instead of an `is_active` flag, and a `category` restricted to a fixed, code-level enum rather than a free-text column.

It covers `index`, `store`, `show`, `update`, and `destroy` — all five standard resource actions are enabled (`Route::resource('/knowledge-base-articles', ...)->except(['edit', 'create'])`), unlike hotel policies, which have no `show` route.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `GET /api/knowledge-base-articles`
- `POST /api/knowledge-base-articles`
- `GET /api/knowledge-base-articles/{id}`
- `PUT /api/knowledge-base-articles/{id}`
- `DELETE /api/knowledge-base-articles/{id}`

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

Notes:

- `X-API-KEY` is checked by the `api.key` middleware.
- `Authorization: Bearer {login_token}` is required because every knowledge-base-article route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login`.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before controller/policy logic ever runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\KnowledgeBaseArticlePolicy`. The policy has a `before()` hook: **a user whose `role` is `super_admin` passes every check below, unconditionally.**

| Action | Rule (non-super-admin) | Super admin |
| --- | --- | --- |
| `index` (list) | The user's `role` must be `admin`. | Allowed. |
| `show` (view one) | The user must be `admin`, **and** the article's `hotel_id` must equal the hotel the user owns. | Allowed for any hotel's article. |
| `store` (create) | The user's `role` must be `admin`, **and** they must have an associated hotel (see below). | Allowed, but see the same associated-hotel caveat under [Create](#2-create-a-knowledge-base-article). |
| `update` / `destroy` | The user must be `admin`, **and** the article's `hotel_id` must equal the hotel the user owns. | Allowed for any hotel's article. |

Practical implications for the UI:

- A non-admin user should never reach this screen; treat a `403` here as "shouldn't be on this page."
- `index` is additionally query-scoped to the caller's own hotel for regular admins (see below) — a super admin's scoping is different, see the caveat there.
- A `403` on `show`/`update`/`destroy` for a specific article id most likely means it belongs to a different hotel — treat it like a 404.

## Response Format

Successful custom API responses use this structure:

```json
{
  "message": "Some message",
  "code": 200,
  "body": {}
}
```

- `message`: human-readable message, safe to show in a toast.
- `code`: repeats the HTTP status code.
- `body`: the actual payload (an article object, a paginated list, or `null`).

**Validation errors (`422`) are the one exception** — they use Laravel's default shape, not the `message/code/body` wrapper (see [Validation Errors](#validation-errors)).

## The Knowledge Base Article Object

```json
{
  "id": "019facde-2222-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "title": "Airport Transfer FAQ",
  "category": "hospitality_best_practices",
  "content": "Guests can request airport pickup at least 3 hours before arrival...",
  "tags": ["airport", "transfer", "faq"],
  "status": "draft",
  "version": 1,
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z",
  "deleted_at": null,
  "hotel": {
    "id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
    "name": "Grand Harbor Hotel"
  }
}
```

Field notes for the UI:

- `id` and `hotel_id` are UUID strings, not integers.
- `tags` is a JSON array field, returned as a parsed array, not a string.
- `category` **is** restricted server-side to a fixed enum (`App\Enums\KnowledgeBaseCategory`) — unlike `hotel_policy.category`, which is free text. See [Allowed `category` Values](#allowed-category-values). Defaults to `"hospitality_best_practices"` at the database level when omitted on create.
- `status` is **not** restricted to a fixed set server-side (it's a plain string column, not a DB enum) — the backend will accept any string here. Defaults to `"draft"` at the database level when omitted on create. Constrain input client-side to `draft` / `published`.
- `version` is an integer, defaults to `1`. It is not auto-incremented by the backend on update — if the product needs version history, the frontend must send an incremented `version` explicitly.
- `hotel` is always eager-loaded on `index`, `store`, `show`, and `update` responses.
- Articles use `SoftDeletes`, so `DELETE` does **not** permanently erase the row.

## Allowed `category` Values

- `hospitality_best_practices` (default)
- `guest_personas`
- `communication_guidelines`
- `revenue_strategies`
- `destination_knowledge`
- `seasonal_knowledge`
- `recommendation_rules`

Sending any other value returns a `422` (`Rule::enum` is enforced server-side, unlike `hotel_policy.category`).

## 1. List Knowledge Base Articles

### Endpoint

`GET /api/knowledge-base-articles`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[status]=published` | Exact match on any real `knowledge_base_articles` column. |
| `search` | string | `search=airport` | Partial (`LIKE %term%`) match across the article's string/text columns. |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the `knowledge_base_articles` table's real columns; an unknown key returns a `422`.

**Scoping behavior:** the query is hardcoded to `where('hotel_id', <the caller's own hotel>)`. For a regular admin this correctly shows only their hotel's articles. **For a super admin, who has no owned hotel, this returns the global knowledge base** (every article with `hotel_id = null`) rather than every hotel's articles — a `null`-valued `where()` compiles to `WHERE hotel_id IS NULL`, which is exactly the global-article set. This is intentional: a super admin manages the global KB through this same endpoint, not a cross-hotel view of every admin's private articles. (Unlike [hotel-policy `index`](/D:/Hospitality%20Ecosystem/docs/hotel-policy-api-documentation.md#1-list-hotel-policies), which has no concept of a hotel-less policy and so genuinely returns nothing for a super admin.)

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object; `body.data` contains [article objects](#the-knowledge-base-article-object).

### Error: Unknown Filter/Sort Column

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "filter.not_a_column": ["Unknown filter column [not_a_column]."]
  }
}
```

## 2. Create a Knowledge Base Article

### Endpoint

`POST /api/knowledge-base-articles`

### Request Body

```json
{
  "title": "Airport Transfer FAQ",
  "category": "hospitality_best_practices",
  "content": "Guests can request airport pickup at least 3 hours before arrival.",
  "tags": ["airport", "transfer"],
  "status": "draft",
  "version": 1
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **Do not send this** — see below. |
| `title` | required, string, max 255. |
| `category` | optional. If sent, must be one of the [allowed values](#allowed-category-values). Defaults to `"hospitality_best_practices"` if omitted. |
| `content` | required, string (long-text column, no practical length cap). |
| `tags` | optional, JSON array. |
| `status` | optional, string, max 255. Defaults to `"draft"` if omitted. Not restricted to a fixed list server-side — enforce `draft` / `published` client-side. |
| `version` | optional, integer. Defaults to `1` if omitted. |

**Important — `hotel_id` is always server-resolved, never client-supplied:** the backend ignores whatever you send and overwrites it with `<the caller's own hotel>`. Simplest correct behavior: don't include `hotel_id` in the request body at all.

**Important — hotel association depends on role:** a regular admin without a hotel gets the same `403` as always. **A super admin creates a global article instead** — the backend sets `hotel_id: null` on the created article regardless of what (if anything) was sent, rather than requiring an owned hotel. There is currently no way for a super admin to create an article scoped to a *specific* hotel through this endpoint — only their own regular-admin flow, or the global KB.

**Backend note on `status` (not a frontend contract concern, but worth knowing):** only `status: "published"` articles are embedded and made searchable for the AI assistant; a `draft` article is not. This is enforced correctly — creating a draft does not trigger embedding generation.

### Success Response

HTTP `201 Created`. `body` is the created [article object](#the-knowledge-base-article-object), with `hotel_id` set to the caller's own hotel.

### Error: Missing Required Fields

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."]
  }
}
```

### Error: Invalid Category

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "category": ["The selected category is invalid."]
  }
}
```

### Error: No Associated Hotel

Custom `message/code/body` wrapper, not the validation shape:

HTTP `403`:

```json
{
  "message": "You do not belong to any hotel.",
  "code": 403,
  "body": null
}
```

### Error: Non-Admin

HTTP `403`, Laravel's default authorization-failure shape:

```json
{ "message": "This action is unauthorized." }
```

## 3. Show a Knowledge Base Article

### Endpoint

`GET /api/knowledge-base-articles/{id}`

Unlike hotel policies, a single-resource `GET` **does** exist for articles.

### Success Response

HTTP `200 OK`. `body` is the [article object](#the-knowledge-base-article-object).

### Error: Not Found / Wrong Hotel

`404` if the id doesn't exist. `403` (Laravel's default authorization-failure shape) if the article belongs to a different hotel than the caller's — treat this the same as a 404 in the UI. Super admins bypass this entirely.

## 4. Update a Knowledge Base Article

### Endpoint

`PUT /api/knowledge-base-articles/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change:

```json
{
  "status": "published",
  "content": "Updated: guests can request airport pickup at least 4 hours before arrival."
}
```

### Validation Rules

Same field-level rules as [create](#validation-rules), except every field is optional.

**`hotel_id` behaves the same as on create — it is always forced server-side, this time to the article's own existing `hotel_id`.** Sending a different `hotel_id` in the payload has no effect; it's silently overwritten back to the record's original hotel before saving. Simplest correct behavior: don't include `hotel_id` in the request body.

### Success Response

HTTP `200 OK`. `body` is the updated [article object](#the-knowledge-base-article-object).

### Error: Not Found

HTTP `404` if the id doesn't exist:

```json
{ "message": "No query results for model [App\\Models\\KnowledgeBaseArticle] {id}" }
```

### Error: Belongs to a Different Hotel

HTTP `403` for a regular admin whose own hotel doesn't match the article's hotel — Laravel's default authorization-failure shape:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a 404 in the UI. Super admins bypass this entirely.

## 5. Delete a Knowledge Base Article

### Endpoint

`DELETE /api/knowledge-base-articles/{id}`

This is a **soft delete** — the row is marked deleted via `deleted_at`, not permanently removed.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Article deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [update](#4-update-a-knowledge-base-article): `404` if the id doesn't exist, `403` if it belongs to a different hotel (bypassed for super admins).

## Validation Errors

For any field-shape validation failure (missing required field, wrong type, invalid `category`), Laravel returns its default shape — **not** the custom `message/code/body` wrapper:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Human-readable error message."]
  }
}
```

The UI should distinguish this from the custom-wrapper business-rule error `"You do not belong to any hotel."` (`403` on create), which has no `errors` key.

## Example cURL Requests

### List

```bash
curl -X GET "http://your-domain.com/api/knowledge-base-articles?search=airport&sort=-created_at" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Create

```bash
curl -X POST http://your-domain.com/api/knowledge-base-articles \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "title": "Airport Transfer FAQ",
    "content": "Guests can request airport pickup at least 3 hours before arrival."
  }'
```

### Show

```bash
curl -X GET http://your-domain.com/api/knowledge-base-articles/019facde-2222-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Update (partial)

```bash
curl -X PUT http://your-domain.com/api/knowledge-base-articles/019facde-2222-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "status": "published" }'
```

### Delete

```bash
curl -X DELETE http://your-domain.com/api/knowledge-base-articles/019facde-2222-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- Unlike hotel policies, a single-resource `GET /api/knowledge-base-articles/{id}` exists — use it for the detail/edit view instead of relying on the list row.
- Never send `hotel_id` on create or update — it's always forced server-side and any value you send is silently ignored (not rejected, not applied).
- `create` requires a regular admin to have an associated hotel; a super admin instead creates a global article (`hotel_id: null`) — there is no way for a super admin to create an article for an arbitrary *specific* hotel through this endpoint.
- A super admin's `index` returns the **global knowledge base** (`hotel_id = null` articles), not every hotel's articles — build a super-admin "manage the global KB" screen against this, not a cross-hotel browse screen.
- `category` **is** enforced server-side to the fixed enum list — safe to build a plain `<select>` around it without extra client-side guarding.
- `status` is free-text server-side — enforce `draft` / `published` client-side.
- `DELETE` is soft-delete, not hard-delete.
- `version` is not auto-incremented by the backend — if the product wants version tracking, the frontend owns incrementing it on update.

## Related Docs

- [Auth API Documentation](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md)
- [Hotel Policy API Documentation](/D:/Hospitality%20Ecosystem/docs/hotel-policy-api-documentation.md) — closest analog to this module.
