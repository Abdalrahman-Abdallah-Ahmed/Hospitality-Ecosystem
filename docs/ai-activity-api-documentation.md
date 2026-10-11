# AI Activity API Documentation

*Added 2026-10-10 (SPEC-083).* Everything the AI did for the hotel, in one list: each time an
agent ran, each change it made or tried to make, and everything the Concierge offered or sent
a guest. Read-only.

## Base URL and Headers

Same as every tenant endpoint: `X-API-KEY`, `Authorization: Bearer <token>`, `Accept: application/json`.
Responses use `{ message, code, body }`.

## Who Can Call This

The same people who can read [record history](history-api-documentation.md): admins, and
employees whose [staff role](staff-roles-api-documentation.md) grants `history.view`. Super
admins name the hotel with `hotel_id` (required for them).

## What Is In the List

Every entry has one **category**:

| Category | What it is | `action` examples |
| --- | --- | --- |
| `ai_run` | One agent turn or AI job: a Concierge reply, an Admin AI reply, recommendation generation, insights, document reading, memory extraction, knowledge indexing | `run.completed` |
| `data_change` | One write the AI made or attempted through a tool or job | `booking.created`, `stay.checked_in`, `task.created`, `refused`, `failed` |
| `guest_outreach` | An activity offered to a guest and how they answered; a proactive message sent or skipped | `pitch.offered`, `pitch.accepted`, `pitch.declined`, `pitch.no_answer`, `proactive.sent`, `proactive.skipped` |

`result` is `succeeded`, `refused` (nothing changed: no permission, or a rule said no — the
`reason` says why) or `failed` (an error; the change was rolled back).

Entries made during the same turn share an `ai_run_id`, so filtering by it shows everything one
turn did. Proactive messages are sent without AI and have no run.

No entry contains message text, prompts, AI output or memory content — only identifiers.

## List AI Activity

`GET /api/ai-activity`

| Param | Notes |
| --- | --- |
| `category` | `data_change`, `guest_outreach`, `ai_run`; several comma-separated |
| `agent` | `concierge`, `admin_advisor`, `recommendation`, `insights`, `turn_signal`, `document_vision`, `guest_memory`, `knowledge`, `unattributed` |
| `tool` | Exact tool or job name, e.g. `CreateBookingTool` |
| `result` | `succeeded`, `refused`, `failed` |
| `conversation_id` | Exact |
| `ai_run_id` | Exact |
| `acted_for_type` + `acted_for_id` | `guest` or `user`, and the id |
| `target_type` | `booking`, `stay`, `reservation`, `task`, `room`, `room-type`, `recommendation`, `guest`, `guest-memory`, `ai-insights`, `activity`, `knowledge-base-article`, `transaction`, `proactive-message` |
| `from`, `to` | `Y-m-d`, hotel-local, inclusive |
| `per_page`, `page` | Default 25, max 100 |
| `hotel_id` | Super admin only |

Newest first.

```json
{
  "id": "uuid",
  "category": "data_change",
  "agent": "concierge",
  "tool": "CreateBookingTool",
  "action": "booking.created",
  "result": "succeeded",
  "reason": null,
  "target": { "type": "booking", "id": "uuid", "history_url": "/api/history/booking/uuid" },
  "changes_count": 1,
  "conversation_id": "0199f3a2-…",
  "ai_run_id": "uuid",
  "acted_for": { "type": "guest", "id": "uuid", "name": "Sara Khan" },
  "occurred_at": "2026-10-05T18:40:03+00:00"
}
```

An `ai_run` entry also carries `trigger_kind` (`guest_message`, `staff_request`,
`scheduled_job`, …) and `usage`:

```json
{ "usage": { "requests": 3, "input_tokens": 5120, "output_tokens": 410, "cached_tokens": 2048 } }
```

- **Provider cost** (`cost_usd`) is added to `ai_run` entries **for super admins only**. Hotel
  users never see what the AI cost.
- A run stopped by the account's daily AI spend ceiling is `refused` with `reason:
  spend_ceiling` and no usage.
- `acted_for` is the staff user the AI acted for, the guest for the Concierge, or `null` for
  a scheduled job.
- `target.history_url` is `null` for a target with no history of its own (a proactive message).
- `target` is `null` when the entry has no target, and `target.type` is `null` for a record
  type with no public slug.
- On `ai_run` entries, `trigger_kind` and `usage` are always present but may be `null`; other
  categories leave both keys out. `cost_usd` is a number (rounded to 6 decimals), not a string,
  and is `null` when the run logged no usage.

## Errors

| Case | Code |
| --- | --- |
| No `history.view` | 403 |
| Super admin without `hotel_id` | 422 |
| `POST` | 405 |
