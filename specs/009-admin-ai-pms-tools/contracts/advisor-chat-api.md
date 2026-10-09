# Contract: Advisor chat with confirmations

`POST /api/ai-advisor/chat`. The middleware stack is unchanged: `api.key → auth:sanctum →
throttle:api → tenant`. Hotel admins only, as today. See [research R1](../research.md#r1-confirmation-for-hard-to-reverse-writes-fr-017).

This contract is **additive**. Existing clients that send only `message` and read only
`reply` keep working. When a confirmation is pending, `reply` already contains the list
and the "reply YES / NO" prompt.

## Request

| Field | Type | Rules | Notes |
| --- | --- | --- | --- |
| `message` | string | `required_without:decision`, max 4000 | As today. While a confirmation is pending, a message that is exactly a confirm or decline word counts as that decision (R1). Any other message cancels the pending actions and is handled as a new request. |
| `conversation_id` | string | nullable; must be the caller's own conversation (404 otherwise, as today) | Required with `decision`. |
| `decision` | string | nullable, `in:confirm,decline`, `required_with:pending_ids` | **New.** The explicit answer from the UI's Confirm/Cancel buttons. Wins over `message`. |
| `pending_ids` | string[] | nullable, each a pending id from the last response | **New, optional.** If given, it must equal the full set currently pending, otherwise 409. This guards against confirming a list the admin did not see. Partial approval is not supported: one confirmation covers the listed batch (Q4). |

## Response `200`

```json
{
  "message": "Advisor replied successfully.",
  "code": 200,
  "body": {
    "conversation_id": "0193…",
    "reply": "Please confirm:\n1. Cancel reservation BK-1042 for Layla Haddad — 2 rooms (Deluxe 301, Deluxe 302), arriving 2026-10-14.\nReply YES to confirm or NO to cancel. This expires in 10 minutes.",
    "pending_confirmation": {
      "ids": ["toolu_01…"],
      "items": [
        { "id": "toolu_01…", "tool": "CancelReservationTool", "summary": "Cancel reservation BK-1042 for Layla Haddad — 2 rooms (Deluxe 301, Deluxe 302), arriving 2026-10-14." }
      ],
      "expires_at": "2026-10-09T10:40:00+00:00",
      "locale": "en"
    }
  }
}
```

- `pending_confirmation` is `null` when nothing is waiting.
- `items` has at most 10 entries (Q4).
- `summary` is written by the tool from the stored records, not by the model.
- `reply` and each `summary` are in the admin's language. `locale` is `ar` when the admin's message contains Arabic script, otherwise `en`. A bare `decision` takes its locale from the paused summaries (Arabic script → `ar`).

## Outcomes

| Situation | Status | Effect |
| --- | --- | --- |
| No pending confirmation, normal message | 200 | As today. |
| Pending, `decision=confirm` (or a confirm word), within 10 min | 200 | The listed actions run. `reply` reports each result, including refusals by business rules. `pending_confirmation: null`. |
| Pending, confirm after 10 min | 200 | Nothing runs. `reply` says the confirmation expired and asks again. |
| Pending, `decision=decline` (or a decline word) | 200 | Nothing runs. `reply` acknowledges. |
| Pending, any other message | 200 | Pending actions are dropped without running. The new message is handled normally. |
| `decision` with nothing pending | 422 | `"There is nothing waiting for confirmation."` |
| `pending_ids` not equal to the pending set | 409 | `"The actions waiting for confirmation have changed. Review them again."` The response body carries the current `pending_confirmation`. |
| Not an admin / no hotel / foreign conversation | 403 / 403 / 404 | As today. |

## WhatsApp (staff path)

The same `AdvisorTurn` handles admin messages in `ProcessInboundWhatsAppMessageJob`. There is
no `decision` field there: only the confirm and decline words apply. The reply text equals
`reply` above. The 10-minute window starts when the pause is stored.

## Audit

Each executed action writes the domain's normal audit events with `actor_kind = ai_agent`
and `actor` = the admin. The `context.ai` block holds `{agent, tool, tool_call_id,
conversation_id}` (R3). Confirmations, declines and expiries are not audited separately:
nothing changed.
