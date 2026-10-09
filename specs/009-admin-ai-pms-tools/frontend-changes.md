# Frontend changes: Admin AI PMS Tools (SPEC-055, Phase 9)

For `ecosystem-frontend`. Backend branch: `009-admin-ai-pms-tools`.
Full API reference: `D:\Hospitality Ecosystem\docs\ai-advisor-chat-api-documentation.md`.

Only the **AI advisor chat** screen changes. No new screens, routes or permissions.

---

## 1. What changed in the backend

The admin advisor (`POST /api/ai-advisor/chat`) can now read and change almost everything in the hotel: guests, reservations, room assignment, check-in/out, tasks, housekeeping, out-of-order rooms, activity bookings and knowledge articles.

Some actions are hard to undo, so the backend **pauses them and asks the admin to confirm** before running them:

- cancelling a reservation;
- removing rooms from a reservation;
- checking out;
- putting a room out of order;
- cancelling an activity booking;
- approving a guest's request to cancel a booking.

The change is additive: the current chat keeps working, because `reply` already contains the question "Reply YES to confirm or NO to cancel". The work below replaces typing YES or NO with buttons.

---

## 2. API contract

### Request: `POST /api/ai-advisor/chat`

```json
{
  "message": "Cancel reservation BK-1042",
  "conversation_id": "01977e3a-…",
  "decision": "confirm",
  "pending_ids": ["toolu_01…"]
}
```

| Field | Rules | Notes |
| --- | --- | --- |
| `message` | required **unless** `decision` is sent; string, max 4000 | Unchanged otherwise. |
| `conversation_id` | optional; **required with `decision`** | Unchanged otherwise. |
| `decision` | optional: `"confirm"` or `"decline"` | **New.** Sent by the Confirm / Cancel buttons. |
| `pending_ids` | optional: array of strings | **New.** Send the `ids` you displayed. If they no longer match what is waiting, the backend answers `409`. |

### Response `200`

```json
{
  "message": "Advisor replied successfully.",
  "code": 200,
  "body": {
    "conversation_id": "01977e3a-…",
    "reply": "Please confirm:\n1. Cancel reservation BK-1042 for Layla Haddad: 2 room(s) (Deluxe 301, Deluxe 302), arriving 2026-10-14.\nReply YES to confirm or NO to cancel. This expires in 10 minutes.",
    "pending_confirmation": {
      "ids": ["toolu_01…"],
      "items": [
        { "id": "toolu_01…", "tool": "CancelReservationTool", "summary": "Cancel reservation BK-1042 for Layla Haddad: 2 room(s) (Deluxe 301, Deluxe 302), arriving 2026-10-14." }
      ],
      "expires_at": "2026-10-09T10:40:00+00:00",
      "locale": "en"
    }
  }
}
```

- `pending_confirmation` is **new**. It is `null` when nothing is waiting.
- `items` holds at most 10 entries. Each `summary` is written by the backend from the stored records, so display it as-is.
- `locale` is `"en"` or `"ar"`. Use RTL when it is `"ar"`.
- `expires_at` is 10 minutes after the pause. After that, a confirmation does nothing.

### Error responses on this endpoint

| Status | `message` | When | What the UI does |
| --- | --- | --- | --- |
| `409` | `The actions waiting for confirmation have changed. Review them again.` | `pending_ids` do not match what is waiting | Replace the card with `body.pending_confirmation`, or hide it when that is `null` |
| `409` | `The previous message in this conversation is still being answered. Try again in a moment.` | Another request for the same conversation is still running (double click, retry) | Show it as a toast; keep the card; re-enable the buttons |
| `422` | `There is nothing waiting for confirmation.` | `decision` sent when nothing is waiting (already answered or dropped) | Hide the card |
| `403` / `404` / `401` | unchanged | — | unchanged |

---

## 3. UI to build

### 3.1 Confirmation card (new component)

Show it under the advisor bubble whenever `body.pending_confirmation` is not `null`.

```
┌─────────────────────────────────────────────────────┐
│ Confirm these actions                    09:41 left  │
│                                                      │
│ 1. Cancel reservation BK-1042 for Layla Haddad:      │
│    2 room(s) (Deluxe 301, Deluxe 302), arriving …    │
│                                                      │
│                       [ Cancel ]   [ Confirm ]       │
└─────────────────────────────────────────────────────┘
```

- List every `items[].summary`, numbered.
- Show a countdown to `expires_at`.
- **Confirm** sends `{ conversation_id, decision: "confirm", pending_ids: ids }`, with no `message`.
- **Cancel** sends `{ conversation_id, decision: "decline", pending_ids: ids }`.
- While a request is in flight, disable both buttons, so a double click cannot send twice.
- When the countdown reaches 0, disable both buttons and show "Expired — ask the advisor again".
- Render the card RTL when `locale === "ar"`.
- Keep showing `body.reply` as the normal advisor bubble. It already lists the actions in text.

### 3.2 Card lifecycle

| Event | Card |
| --- | --- |
| Response has `pending_confirmation` | Show a new card. Disable the buttons on any older card. |
| Admin types and sends a normal message instead | Hide the card. The backend drops the waiting actions when another message arrives. |
| Confirm/Cancel returns `200` | Hide the card and show the new `reply` bubble. |
| `409` "have changed" | Swap in the returned `pending_confirmation`, or hide the card |
| `409` "still being answered" | Keep the card and show a toast |
| `422` "nothing waiting" | Hide the card |

Only the latest card can be answered.

### 3.3 Refresh after every advisor turn

A turn can now change reservations, rooms, stays, tasks, bookings, guests and knowledge articles. After each `200` response, refresh or invalidate the queries for whatever list or detail screen is open, as you already do after the advisor's create actions.

### 3.4 Composer

`message` can now be empty only when `decision` is sent. The text input itself is unchanged: an empty send stays blocked.

---

## 4. Types (TypeScript)

```ts
export type AdvisorDecision = 'confirm' | 'decline';

export interface PendingConfirmationItem {
  id: string;
  tool: string;
  summary: string | null;
}

export interface PendingConfirmation {
  ids: string[];
  items: PendingConfirmationItem[];
  expires_at: string; // ISO 8601
  locale: 'en' | 'ar';
}

export interface AdvisorChatRequest {
  message?: string;
  conversation_id?: string | null;
  decision?: AdvisorDecision;
  pending_ids?: string[];
}

export interface AdvisorChatBody {
  conversation_id: string;
  reply: string;
  pending_confirmation: PendingConfirmation | null;
}
```

---

## 5. Out of scope here

- WhatsApp confirmations (YES/NO by text) are handled entirely in the backend.
- No permission or role-editor changes: no new permission was added.
- Activities and knowledge articles now appear in the audit history (`GET /api/history/{type}/{id}`) like other records. If the history screen filters by subject type, allow `activity` and `knowledge_base_article`.

---

## 6. Acceptance checklist

- [ ] Asking the advisor to cancel a reservation shows the card with the summary and a countdown.
- [ ] Confirm sends `decision` and `pending_ids`; the card closes and the reply appears; the reservations list refreshes.
- [ ] Cancel closes the card, and nothing changes.
- [ ] Typing a new message instead hides the card.
- [ ] A double click on Confirm sends only one request.
- [ ] After 10 minutes the buttons are disabled with "Expired".
- [ ] Each `409` and `422` case behaves as in §3.2.
- [ ] An Arabic conversation renders the card RTL.
