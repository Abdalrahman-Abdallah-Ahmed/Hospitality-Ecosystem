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
- **Task categories** — the categories and the team each belongs to.
- **Guest messages** — recent guest conversations.
- **Rooms** — the hotel's rooms (room number, type, floor, status).
- **Activities** — what the hotel offers, with category and price.
- **Knowledge base search** — semantic search over this hotel's own articles/policies *and* the shared global knowledge base (see [Knowledge Base Article API Documentation](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md)).

If none of the tools have relevant information for a question, the advisor is instructed to say so rather than fabricate an answer — but this is a model instruction, not a hard guarantee; treat replies as advisory, not authoritative source-of-truth data.

### What the Advisor Can Create

A chat turn can **write to the hotel's data**. Six create tools are available,
and a single message may produce real records:

| Tool | Creates | Notes |
| --- | --- | --- |
| `CreateReservationTool` | A reservation | Matches or creates the guest by phone number |
| `CreateRoomTool` | A room | Room numbers are unique per hotel; a duplicate is refused, not overwritten |
| `CreateActivityTool` | An activity | Immediately recommendable to guests |
| `CreateTaskTool` | A staff task | Optionally assigned to a team/person and linked to a room |
| `CreateGuestTool` | A guest | Matched by phone; an existing guest is returned **unchanged**, never duplicated |
| `CreateHotelPolicyTool` | A hotel policy | Becomes guest-facing grounding data — see the warning below |

#### The hotel is never taken from the model

Every tool is constructed with `$this->user->hotel` — the authenticated admin's
own hotel. No argument the model produces can change which property is written
to.

Ids passed *inside* arguments are a different matter, and are verified rather
than trusted: a category, team, staff member, or room belonging to another
hotel is **dropped**, and the record is created without it. A model can emit
any plausible-looking uuid it has seen, and assigning one hotel's task to
another hotel's team would put a staff member's work list in front of the
wrong property. An unassigned task is visible and fixable; a misrouted one is
not. `tests/Feature/AdminCreateToolsTest.php` asserts this.

#### Two consequences worth knowing before enabling this in a UI

**Policies are quoted to guests.** The guest concierge searches hotel policies
when answering and is instructed to let what it finds override its own
judgment. A policy written through this endpoint therefore becomes the hotel's
own word to guests. The advisor is told to record only what the admin actually
stated and to ask rather than fill in a plausible-sounding cancellation window
— but that is a model instruction, not a guarantee. **Show the admin what was
written and let them confirm it.**

**Creating a policy costs money.** Saving an active policy dispatches
`SyncKnowledgeChunksJob`, which embeds the content with the AI provider. That
is a real charge, metered as `embeddings_generated` and attributed to the
account (see [AI Cost Attribution](/D:/Hospitality%20Ecosystem/docs/ai-cost-attribution-api-documentation.md)).

#### Nothing here replaces the REST endpoints

These tools exist so an admin can act mid-conversation, not as an alternative
API. They apply no policy authorization, return no validation error shape, and
skip the `Http/Requests` rules the REST endpoints enforce. Build UI against the
REST endpoints; treat the advisor as a convenience path with a human reading
every confirmation it returns.

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
- **A chat turn can create records** — reservations, rooms, activities, tasks, guests, policies. The reply names what was created and its id. Refresh any list the user is looking at after a turn, and surface the confirmation rather than burying it: this is the only signal that data changed.

## Related Docs

- [Knowledge Base Article API Documentation](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md)
- [AI Cost Attribution API Documentation](/D:/Hospitality%20Ecosystem/docs/ai-cost-attribution-api-documentation.md)
- [Auth API Documentation](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md)
