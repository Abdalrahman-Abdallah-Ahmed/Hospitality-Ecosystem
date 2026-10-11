# Contract: AI Activity and Record History (SPEC-083)

Same middleware stack and `{ message, code, body }` envelope as every tenant route.

## AI activity list — `GET /api/ai-activity`

**Authorization**: `EventLogPolicy::viewAny`, which means `history.view`. Admins hold it,
employees only through a staff role, and super admins bypass. A super admin passes
`hotel_id`. Entries are scoped to the hotel by `BelongsToHotel`.

Read-only. There are no write routes.

| Param | Notes |
| --- | --- |
| `category` | `data_change`, `guest_outreach`, `ai_run` (comma-separated allowed) |
| `agent` | `concierge`, `admin_advisor`, `recommendation`, `insights`, `turn_signal`, `document_vision`, `guest_memory`, `knowledge`, `unattributed` |
| `tool` | Exact tool or job name (e.g. `CreateBookingTool`) |
| `result` | `succeeded`, `refused`, `failed` |
| `conversation_id` | Exact |
| `ai_run_id` | Exact: everything one turn or job did |
| `acted_for_type` + `acted_for_id` | `guest` or `user`, plus the id |
| `target_type` | `booking`, `stay`, `reservation`, `task`, `room`, `recommendation`, `guest`, `guest-memory`, `ai-insights`, `knowledge-base-article`, `proactive-message`, … |
| `from`, `to` | `Y-m-d`, hotel-local, inclusive, on `occurred_at` |
| `per_page`, `page` | Default 25, max 100 |
| `hotel_id` | Super admin only |

Sort: `occurred_at` desc (fixed).

Item (`AiActivityResource`):

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
  "occurred_at": "2026-10-05T21:40:03+03:00"
}
```

**Guest outreach** examples:
- `"action": "pitch.offered"` with `target: recommendation`;
- `"pitch.declined"`;
- `"proactive.skipped"` with `"reason": "quiet_hours"` and `target: proactive-message`.
  `ai_run_id` is null for proactive sends, since no AI is used.

**AI run** example:

```json
{
  "id": "uuid",
  "category": "ai_run",
  "agent": "concierge",
  "tool": null,
  "action": "run.completed",
  "result": "succeeded",
  "trigger_kind": "guest_message",
  "conversation_id": "0199f3a2-…",
  "ai_run_id": "uuid",
  "acted_for": { "type": "guest", "id": "uuid", "name": "Sara Khan" },
  "usage": { "requests": 3, "input_tokens": 5120, "output_tokens": 410, "cached_tokens": 2048 },
  "occurred_at": "…"
}
```

- `cost_usd` is added **only for super admins**. It is the sum over the run's
  `ai_usage_logs`.
- A refused run (spend ceiling) has `"result": "refused"`, `"reason": "spend_ceiling"`
  and `"usage": null`.
- No entry contains conversation text, prompts, model output or memory content (FR-024).

## Per-record history — `GET /api/history/{type}/{id}` (extended)

These new `{type}` values are added to the existing list: `booking`, `room`, `room-type`
and `guest-memory`.
- `guest-memory`: the history of a deleted memory is still readable when the
  tenant-scoped log has entries for it. It is `404` otherwise, which covers another
  hotel's id and an id that never existed.
- AI entries now always carry
  `context.ai = { agent, tool, tool_call_id, conversation_id, run_id }`.
- `actor` is the person the AI acted for: a user, or now also a guest for Concierge writes.
