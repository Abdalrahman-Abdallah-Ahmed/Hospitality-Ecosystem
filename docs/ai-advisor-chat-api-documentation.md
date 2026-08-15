# AI Advisor Chat API Documentation

This document describes the AI Advisor chat endpoint, defined by `App\Http\Controllers\AiAdvisorController` and backed by `App\Ai\Agents\AdminAdvisorAgent`.

The advisor is a conversational assistant for a **hotel admin** (not a super admin, not an employee — see [Who Can Call This Endpoint](#who-can-call-this-endpoint)). It answers questions grounded in the admin's own hotel data (reservations, tasks, guest messages) and the knowledge base (this hotel's own articles/policies plus the shared global knowledge base). It is a single `POST` action, not a CRUD resource — there is no `index`/`show`/`update`/`destroy` for conversations through this API.

## Base URL

`/api`

- `POST /api/ai-advisor/chat`

## Required Headers

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

Same rules as every other authenticated endpoint in this app: `X-API-KEY` is checked by the `api.key` middleware, and `Authorization: Bearer {login_token}` is required because the route sits inside `auth:sanctum`. A missing/invalid token returns HTTP `401` before any controller logic runs.

## Who Can Call This Endpoint

There is no policy class here — the controller checks the role directly:

| Caller | Result |
| --- | --- |
| Regular admin (`role: admin`) with an owned hotel | Allowed. |
| Regular admin with no owned hotel | `403`, `"You do not belong to any hotel."` |
| Employee | `403`, `"This action is unauthorized."` |
| Super admin | `403`, `"This action is unauthorized."` |

**Super admins are rejected**, not given global-only access. This is different from the knowledge-base-article endpoints, where a super admin gets a global-KB view — the advisor's other tools (reservations, tasks, guest messages) only make sense for a specific hotel, so this endpoint is entirely hotel-admin-scoped. If a super-admin-facing assistant is ever needed, it would be a separate endpoint/agent, not this one.

## Response Format

Successful responses use the standard wrapper:

```json
{
  "message": "Advisor replied successfully.",
  "code": 200,
  "body": {
    "conversation_id": "01977e3a-2b1c-7000-9000-abcdef123456",
    "reply": "Guests can check out as late as 2pm without a fee, per your hotel's cancellation policy..."
  }
}
```

- `body.conversation_id`: always present on success. Save it and send it back on the next call to continue the same conversation.
- `body.reply`: the advisor's free-form text answer. There is no structured/JSON reply shape — this is plain conversational text, not a field to parse.

**Validation errors (`422`) use Laravel's default shape**, not this wrapper — see [Validation Errors](#validation-errors).

## Request Body

```json
{
  "message": "What's our policy on late checkouts?",
  "conversation_id": null
}
```

| Field | Rules | Notes |
| --- | --- | --- |
| `message` | required, string, max 4000 characters | The admin's question or message. |
| `conversation_id` | optional, string | Omit or send `null` to start a **new** conversation. Send a previously-returned `conversation_id` to **continue** an existing one. |

### Starting vs. continuing a conversation

- **Omit `conversation_id`** (or send it as `null`) → the backend starts a brand-new conversation for this admin and returns a fresh `conversation_id` in the response. Use this for the first message in a chat session.
- **Send a `conversation_id`** you previously received → the backend loads that conversation's recent history (up to the last 10 messages — see [Context Window](#context-window)) and continues it, so the advisor has memory of prior turns.
- **Ownership is enforced.** A `conversation_id` that doesn't belong to the calling admin — whether it's malformed, someone else's, or simply doesn't exist — returns `404 "Conversation not found."`, not the other admin's history. There is no way to read or continue another admin's conversation through this endpoint, even by guessing a valid UUID.
- The frontend is responsible for persisting `conversation_id` between messages (e.g. in the chat UI's local/session state) — the backend does not infer "the admin's current conversation" automatically; every request either starts fresh or explicitly names the conversation to continue.

### Context window

The advisor only keeps the **last 10 messages** of a conversation in context (`AdminAdvisorAgent::maxConversationMessages()`). Older turns are still stored (nothing is deleted) but are no longer sent to the model, so very long-running conversations will gradually "forget" their earliest messages. There is no summarization — it's a hard cutoff by message count, not by relevance.

## What the Advisor Can Do

The advisor has tool access to real data, scoped to the calling admin's own hotel — it does not invent numbers or guess at policies:

- **Reservations** — today's arrivals (guest name, room, status, party size).
- **Tasks** — the hotel's task list.
- **Guest messages** — recent guest conversations.
- **Rooms** — the hotel's rooms (room number, type, floor, status).
- **Knowledge base search** — semantic search over this hotel's own articles/policies *and* the shared global knowledge base (see [Knowledge Base Article API Documentation](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md)).

If none of the tools have relevant information for a question, the advisor is instructed to say so rather than fabricate an answer — but this is a model instruction, not a hard guarantee; treat replies as advisory, not authoritative source-of-truth data.

## Success Response

HTTP `200 OK`:

```json
{
  "message": "Advisor replied successfully.",
  "code": 200,
  "body": {
    "conversation_id": "01977e3a-2b1c-7000-9000-abcdef123456",
    "reply": "..."
  }
}
```

## Error Responses

### Unauthenticated

HTTP `401` — missing/invalid bearer token.

### Not a hotel admin

HTTP `403`, custom wrapper — see the [role table](#who-can-call-this-endpoint) above for the exact message per caller type.

### Unknown or foreign `conversation_id`

HTTP `404`:

```json
{
  "message": "Conversation not found.",
  "code": 404,
  "body": null
}
```

### Validation Errors

For a missing/oversized `message`, Laravel's default shape (not the `message/code/body` wrapper):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "message": ["The message field is required."]
  }
}
```

## Example cURL Requests

### Start a new conversation

```bash
curl -X POST http://your-domain.com/api/ai-advisor/chat \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "message": "What'\''s our policy on late checkouts?" }'
```

### Continue that conversation

```bash
curl -X POST http://your-domain.com/api/ai-advisor/chat \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "message": "And what about early check-in?",
    "conversation_id": "01977e3a-2b1c-7000-9000-abcdef123456"
  }'
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- Only regular hotel admins can use this endpoint — employees and super admins both get `403`.
- This is **synchronous**: the HTTP response doesn't come back until the model has finished replying. Build the chat UI around a request that can take several seconds, not an instant response — there is no streaming and no separate "poll for the reply" step.
- Persist `conversation_id` client-side between turns; omit it (or send `null`) to start over.
- `reply` is free-form text, not structured data — render it as a chat bubble, don't try to parse fields out of it.
- A conversation only remembers its last 10 messages — don't expect perfect recall in very long threads.
- A `conversation_id` you don't own returns `404`, same as if it didn't exist.

## Related Docs

- [Knowledge Base Article API Documentation](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md)
- [Auth API Documentation](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md)
