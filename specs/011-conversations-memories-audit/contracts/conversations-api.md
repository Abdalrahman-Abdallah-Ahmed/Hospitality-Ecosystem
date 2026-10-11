# Contract: Conversation Viewer API (SPEC-082)

Same middleware stack and `{ message, code, body }` envelope as every tenant route.
Read-only: there are no create, update or delete routes (FR-006).

**Authorization**: `ConversationPolicy::viewAny` →
`allows($user, Permission::CONVERSATIONS_VIEW)`. Admins hold it; an employee holds it only
through a staff role. A super admin must pass `hotel_id` (`resolveHotel()`).

**Scope**: only conversations whose participant is a non-deleted `Guest` of the resolved
hotel. Advisor (staff) conversations are never listed (FR-008).

## List — `GET /api/conversations`

| Param | Notes |
| --- | --- |
| `guest_id` | Optional. Limits the list to one guest; this is how the guest page lists a guest's conversations (FR-007). Another hotel's guest → empty list |
| `last_active_from`, `last_active_to` | Optional `Y-m-d`, hotel-local, inclusive |
| `per_page` | Default 25, max 100 |
| `page` | 1-indexed |
| `hotel_id` | Super admin only, required for them |

There is no `search` parameter (FR-002); one that is sent is ignored.

Sort: last activity, newest first (fixed).

Item (`ConversationResource`):

```json
{
  "id": "0199f3a2-…",
  "guest": { "id": "uuid", "name": "Sara Khan", "phone": "+9665…" },
  "message_count": 14,
  "started_at": "2026-10-02T09:12:00+03:00",
  "last_message_at": "2026-10-05T21:40:00+03:00"
}
```

Not logged as an access event.

## Show — `GET /api/conversations/{conversation}`

| Param | Notes |
| --- | --- |
| `page`, `per_page` | Messages page; default 50, max 200; oldest first |
| `hotel_id` | Super admin only |

Another hotel's conversation, a staff conversation, or an unknown id → `404`.

```json
{
  "conversation": { "id": "…", "guest": { "id": "uuid", "name": "Sara Khan", "phone": "+9665…" },
                    "message_count": 14, "started_at": "…", "last_message_at": "…" },
  "messages": {
    "data": [
      { "id": "…", "sender": "guest", "text": "Is breakfast included?", "sent_at": "…",
        "proactive": false, "attachments": [] },
      { "id": "…", "sender": "concierge", "text": "Your rate includes breakfast…", "sent_at": "…",
        "proactive": false, "attachments": [] },
      { "id": "…", "sender": "concierge", "text": "Reminder: your desert safari is tomorrow at 16:00.",
        "sent_at": "…", "proactive": true, "attachments": [] }
    ],
    "meta": { "current_page": 1, "per_page": 50, "total": 14, "last_page": 1 }
  }
}
```

- `sender`: `guest` (role `user`) or `concierge` (role `assistant`). Tool rows, and
  assistant rows that hold only tool calls with no text, are omitted.
- `attachments`: `[{ "type": "image", "name": "receipt.jpg" }]`. There is never a payload
  or URL.
- `tool_calls`, `tool_results`, `usage` and `meta` are never returned.
- **Access event (FR-029)**: page 1, or a request without `page`, records
  `guest.conversation_viewed` (subject: the guest; `context.conversation_id`; actor: the
  caller). Later pages are not logged.

## Errors

| Case | Code |
| --- | --- |
| No `conversations.view` | 403 |
| Super admin without `hotel_id` | 422 |
| Other hotel / staff conversation / unknown | 404 |
