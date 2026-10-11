# Conversations API Documentation

*Added 2026-10-10 (SPEC-082).* Read what guests and the WhatsApp Concierge said to each other.
Read-only: conversations are written by the Concierge and the proactive messages, never
through this API. There is no search (post-MVP).

This dashboard viewer is the **only** place guest message text is shown. The Admin AI can no
longer read guest messages (see [AI Advisor Chat](ai-advisor-chat-api-documentation.md)).

## Base URL and Headers

Same as every tenant endpoint: `X-API-KEY`, `Authorization: Bearer <token>`, `Accept: application/json`.
Responses use `{ message, code, body }`.

## Who Can Call This

- **Hotel admins**, for their own hotel.
- **Employees** only if their [staff role](staff-roles-api-documentation.md) grants
  `conversations.view`. Employees without a role never get it.
- **Super admins**, naming the hotel with `hotel_id` (required for them; `422` without it).

Only conversations with **guests of the hotel** are visible. Staff conversations with the
Admin AI, and conversations of a deleted guest, are never shown.

## List Conversations

`GET /api/conversations`

| Param | Notes |
| --- | --- |
| `guest_id` | Optional. Only this guest's conversations — use it on the guest page. Another hotel's guest gives an empty list. |
| `last_active_from`, `last_active_to` | Optional, `Y-m-d`, hotel-local, inclusive, on the last message's time |
| `per_page` | Default 25, max 200 |
| `page` | 1-indexed |
| `hotel_id` | Super admin only |

Sorted by last activity, newest first. Opening the list is not recorded in the audit trail.

```json
{
  "message": "Conversations fetched successfully.",
  "code": 200,
  "body": {
    "data": [
      {
        "id": "0199f3a2-…",
        "guest": { "id": "uuid", "name": "Sara Khan", "phone": "+9665…" },
        "message_count": 14,
        "started_at": "2026-10-02T06:12:00+00:00",
        "last_message_at": "2026-10-05T18:40:00+00:00"
      }
    ],
    "links": { "…": "…" },
    "meta": { "current_page": 1, "per_page": 25, "total": 1, "last_page": 1 }
  }
}
```

`message_count` counts only what the guest and the Concierge said (see below).

## Read One Conversation

`GET /api/conversations/{id}`

| Param | Notes |
| --- | --- |
| `page`, `per_page` | The messages, oldest first. Default 50 per page, max 200. |
| `hotel_id` | Super admin only |

```json
{
  "message": "Conversation fetched successfully.",
  "code": 200,
  "body": {
    "conversation": { "id": "0199f3a2-…", "guest": { "id": "uuid", "name": "Sara Khan", "phone": "+9665…" },
                      "message_count": 3, "started_at": "…", "last_message_at": "…" },
    "messages": {
      "data": [
        { "id": "…", "sender": "guest", "text": "Is breakfast included?", "sent_at": "…", "proactive": false, "attachments": [] },
        { "id": "…", "sender": "concierge", "text": "Yes, your rate includes breakfast.", "sent_at": "…", "proactive": false, "attachments": [] },
        { "id": "…", "sender": "concierge", "text": "Reminder: your desert safari is tomorrow at 16:00.", "sent_at": "…", "proactive": true, "attachments": [] }
      ],
      "links": { "…": "…" },
      "meta": { "current_page": 1, "per_page": 50, "total": 3, "last_page": 1 }
    }
  }
}
```

- `sender` is `guest` or `concierge`.
- `proactive: true` marks a message the Concierge sent first (see
  [Proactive Messages](proactive-messages-api-documentation.md)).
- `attachments` lists each attachment's `type` and `name` only, never its content. Either may
  be `null`.
- `guest` is `null` when the guest record is gone. `started_at`, `last_message_at` and a
  message's `sent_at` can be `null`.
- Internal data is never returned: the AI's tool calls and their results, token usage, and
  rows that held only tool calls.

**Audit.** Opening a conversation (page 1, or no `page`) records `guest.conversation_viewed`
in the guest's [history](history-api-documentation.md): who opened it, when, and the
conversation id. Turning pages is the same opening and is not recorded again.

## Errors

| Case | Code |
| --- | --- |
| No `conversations.view` | 403 |
| Super admin without `hotel_id` | 422 |
| Another hotel's conversation, a staff conversation, a deleted guest's, or an unknown id | 404 |
| `POST`, `PUT` or `DELETE` | 405 |
