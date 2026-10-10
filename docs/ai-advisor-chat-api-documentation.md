# AI Advisor Chat API Documentation

This document describes the AI Advisor chat endpoint, defined by `App\Http\Controllers\AiAdvisorController` and backed by `App\Services\Ai\AdvisorTurn` and `App\Ai\Agents\AdminAdvisorAgent`.

The advisor is a conversational operations assistant for a **hotel admin** (not a super admin, not an employee — see [Who Can Call This Endpoint](#who-can-call-this-endpoint)). It reads and changes the admin's own hotel through tools (SPEC-055): guests, reservations, rooms, availability, stays, tasks, housekeeping, maintenance, activities, bookings, reports and the knowledge base. Hard-to-reverse changes wait for the admin's confirmation. It is a single `POST` action, not a CRUD resource.

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

`X-API-KEY` is checked by the `api.key` middleware; `Authorization: Bearer {login_token}` is required because the route sits inside `auth:sanctum`. A missing/invalid token returns HTTP `401` before any controller logic runs.

## Who Can Call This Endpoint

| Caller | Result |
| --- | --- |
| Regular admin (`role: admin`) with a hotel | Allowed. |
| Regular admin with no hotel | `403`, `"You do not belong to any hotel."` |
| Employee | `403`, `"This action is unauthorized."` |
| Super admin | `403`, `"This action is unauthorized."` |

Every tool still checks the acting admin's permission each time it runs (see [Tool catalog](#tool-catalog)), and runs only inside the admin's own hotel. Admins hold every permission, so for them this never refuses today; it keeps the tools safe if the advisor is ever opened to employees.

## Request Body

```json
{
  "message": "Cancel reservation BK-1042",
  "conversation_id": "01977e3a-2b1c-7000-9000-abcdef123456",
  "decision": null,
  "pending_ids": null
}
```

| Field | Rules | Notes |
| --- | --- | --- |
| `message` | required unless `decision` is sent; string; max 4000 | The admin's message. While actions wait for confirmation, a message that is exactly a confirm word (`yes`, `ok`, `confirm`, `go ahead`, `نعم`, `موافق`…) or a decline word (`no`, `cancel`, `لا`…) answers them. Anything else is a new request and drops the waiting actions. |
| `conversation_id` | optional string; required with `decision` | Omit or `null` to start a new conversation; send one you received to continue it. Someone else's id returns `404`. |
| `decision` | optional, `confirm` or `decline` | **New.** The Confirm / Cancel buttons' answer to the actions waiting for confirmation. Wins over `message`. |
| `pending_ids` | optional array of strings | **New.** The ids the UI showed as waiting. If sent, they must be exactly what is waiting, otherwise `409` — so the admin never confirms a list they did not see. |

### Starting vs. continuing a conversation

- **Omit `conversation_id`** → a new conversation is created for this admin before the turn runs (so the audit trail can name it) and its id is returned.
- **Send a `conversation_id`** → the conversation's last 10 messages are loaded and continued (`AdminAdvisorAgent::maxConversationMessages()`); older turns are kept but no longer sent to the model.
- **Ownership is enforced**: a `conversation_id` that is not the caller's returns `404 "Conversation not found."`.

## Response Format

```json
{
  "message": "Advisor replied successfully.",
  "code": 200,
  "body": {
    "conversation_id": "01977e3a-2b1c-7000-9000-abcdef123456",
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

- `body.reply`: free-form text, in the admin's language (English or Arabic). Render it as a chat bubble; do not parse it.
- `body.pending_confirmation`: `null` unless hard-to-reverse actions are waiting. Then:
  - `items` lists at most 10 actions; each `summary` is written by the tool from the stored records, not by the model, so it is exactly what will run;
  - `expires_at` is 10 minutes after the pause;
  - `locale` is `ar` when the admin wrote in Arabic, otherwise `en`.

The change is additive: a client that sends only `message` and reads only `reply` keeps working, because `reply` already contains the list and the YES/NO question.

## Confirming Hard-to-Reverse Actions

These actions pause before they run until the admin confirms (FR-017):

- cancelling a reservation (`CancelReservationTool`);
- removing rooms from a reservation (`UpdateReservationTool` with a room list that leaves rooms out);
- checking out (`CheckOutTool`);
- putting a room out of order (`SetRoomOutOfOrderTool`);
- cancelling an activity booking (`UpdateBookingStatusTool` with `status: cancelled`);
- approving a guest's request to cancel a booking (`DecideBookingCancellationTool` with `decision: approve`);
- approving or rejecting an activity recommendation (`DecideRecommendationTool`, *2026-10-10*). One named recommendation per call: bulk approval happens only in the approval queue, and the advisor declines it.

The pause uses `laravel/ai` tool approvals: the model cannot approve its own call. Only the admin's **very next message** can confirm, and only **within 10 minutes**.

| Situation | Status | Effect |
| --- | --- | --- |
| No action waiting, normal message | 200 | Normal turn. |
| Waiting, `decision: confirm` (or a confirm word), within 10 min | 200 | The listed actions run; `reply` reports each result, including any refusal by a business rule; `pending_confirmation: null`. |
| Waiting, confirm after 10 min | 200 | Nothing runs; `reply` says the confirmation expired and asks again. |
| Waiting, `decision: decline` (or a decline word) | 200 | Nothing runs; `reply` acknowledges. |
| Waiting, any other message (including "yes, but change the date") | 200 | The waiting actions are dropped without running; the new message is handled normally. |
| `decision` sent with nothing waiting (including a second confirm of the same actions) | 422 | `"There is nothing waiting for confirmation."` A confirmation is used once. |
| Another message in the same conversation is still being answered (double click, retry) | 409 | `"The previous message in this conversation is still being answered. Try again in a moment."` Only one turn runs per conversation, so a confirmation can never run twice. |
| `pending_ids` not equal to what is waiting | 409 | `"The actions waiting for confirmation have changed. Review them again."` `body.pending_confirmation` holds what is waiting now. |
| More than 10 actions in one pause | 200 | Refused; the advisor asks again in batches of at most 10, each confirmed separately. |

### WhatsApp

Paired admins talking to the advisor on WhatsApp get the same flow, in a WhatsApp conversation of its own: a "yes" on WhatsApp never answers actions waiting in the web chat, or the other way round. the reply lists the actions and asks them to answer **YES** or **NO** (in Arabic when they write in Arabic). Only the confirm and decline words apply there.

## Tool Catalog

Each tool checks, every time it runs, the permission the matching staff endpoint checks. A tool given another hotel's id, code or room number answers as if it does not exist. Reads return at most 50 records with the total (`{"total","returned","partial","items"}`); the advisor says "showing 50 of N" when a list is partial.

### Read

| Tool | Permission | Reads |
| --- | --- | --- |
| `KnowledgeSearchTool` | `knowledge_base_articles.view` | Hotel and general knowledge, with citations |
| `GetGuestsTool` | `guests.view` | Guests by name, phone or email |
| `GetGuestTool` | `guests.view` | One guest: profile, stays, reservations, bookings |
| `GetGuestMessagesTool` | `guests.view` | Recent guest messages |
| `GetReservationsTool` | `reservations.view` | By arrival/departure/stay date, status, guest, code |
| `GetReservationTool` | `reservations.view` | One reservation: party, room lines, assigned rooms, stays |
| `GetRoomTypesTool` | `room_types.view` | Room types and room counts |
| `GetRoomsTool` | `rooms.view` | Rooms by type, floor, building, room status, housekeeping status |
| `GetAvailabilityTool` | `availability.view` | Sellable rooms per type and night |
| `GetStaysTool` | `stays.view` | Arrivals, departures, in-house for a date |
| `GetTasksTool` | `tasks.view` | Tasks by team, assignee, category, status, priority, room, due date |
| `GetTaskCategoriesTool` | `task_categories.view` | Task categories and their teams |
| `GetHousekeepingBoardTool` | `rooms.view` | The housekeeping board |
| `GetMaintenanceTool` | `tasks.view` | Maintenance tasks and out-of-order rooms |
| `GetActivitiesTool` | `activities.view` | Activities and their availability |
| `GetBookingsTool` | `bookings.view` | Activity bookings, incl. pending cancellation requests |
| `GetRecommendationsForReviewTool` | `recommendations.view` | Recommendations awaiting approval (or another status) by guest, room, reservation, activity or arrival dates (*2026-10-10*) |
| `GetReportTool` | per report: `dashboard`/`occupancy` → `dashboard.view`; `conversion` → `recommendations.view`; `insights` → `ai_insights.view`; `usage` → admins only | Dashboard figures, occupancy per night (≤ 31 nights), recommendation conversion (no ledger values), AI insights, AI usage (no cost) |
| `GetStaffTool` | admins only | Users (name, email, role, staff role, team, effective permissions) and staff roles. No passwords or tokens. |
| `GetHotelSettingsTool` | admins only | Allow-listed settings; AI preferences that look like secrets are dropped |

### Write

| Tool | Permission | Confirm | Does what the staff screen does |
| --- | --- | --- | --- |
| `CreateGuestTool` | `guests.create` | – | Register a guest; a known phone/email returns the existing guest |
| `UpdateGuestTool` | `guests.update` | – | Update profile fields the admin gave |
| `CreateReservationTool` | `reservations.create` | – | Book room types; a known platform code is reported, not booked twice |
| `UpdateReservationTool` | `reservations.update` | **when removing rooms** | Dates, party, room types, requests. No status field. |
| `CancelReservationTool` | `reservations.update` | **yes** | Cancel the reservation and its rooms (never deleted) |
| `AssignRoomsTool` | `reservations.update` | – | Assign, change or remove rooms on lines, all or nothing |
| `CheckInTool` | `stays.check_in` | – | Check in |
| `CheckOutTool` | `stays.check_out` | **yes** | Check out |
| `CreateRoomTool` | `rooms.create` | – | Add a room |
| `SetHousekeepingStatusTool` | `rooms.update_housekeeping_status` | – | Correct housekeeping status |
| `SetRoomOutOfOrderTool` | `rooms.set_out_of_order` | **yes** | Take a room out of order |
| `UpdateOutOfOrderTool` | `rooms.set_out_of_order` | – | Change the reason or expected end |
| `ReturnRoomToServiceTool` | `rooms.set_out_of_order` | – | Return to service (with a cleaning task) |
| `CreateTaskTool` | `tasks.create` | – | Create a task; the same open task from the last day is reported, not duplicated |
| `UpdateTaskTool` | `tasks.update` | – | Assignee, team, status, priority, due date, description |
| `ReportTaskIssueTool` | `tasks.update` | – | Report a room issue on a housekeeping task |
| `CreateActivityTool` | `activities.create` | – | Add an activity |
| `CreateActivityBookingTool` | `bookings.create` | – | Book an activity; never past capacity; the same guest/activity/day is reported, not duplicated |
| `UpdateBookingStatusTool` | `bookings.update_status` | **when cancelling** | Confirm, realised, no-show, cancel |
| `DecideBookingCancellationTool` | `bookings.update_status` | **when approving** | Approve or decline a guest's cancellation request |
| `DecideRecommendationTool` | `recommendations.approve` | **yes** | Approve or reject one recommendation, with an optional reason (*2026-10-10*) |
| `CreateKnowledgeArticleTool` | `knowledge_base_articles.create` | – | Save the admin's own text as a hotel article (published unless a draft is asked for) |
| `UpdateKnowledgeArticleTool` | `knowledge_base_articles.update` | – | Correct a hotel article |

Writes go through the same services as the staff endpoints, with the same rules and the same refusal messages (`Not done: <reason>. Nothing was changed.`). A successful write returns `{"ok": true, "ids": {...}, "changed": {...}}`.

### Never

- No delete of any kind — the advisor offers to cancel instead.
- No change to users, staff roles, permissions or hotel settings.
- No hotel policy, knowledge document or shared global knowledge writes.
- No transaction ledger, no AI provider cost or margin.
- No override of overbooking or activity capacity.
- No reservation no-show yet: it arrives with SPEC-012.

### Audit trail

Every AI write is in the audit trail as `actor_kind: ai_agent` with the admin as the actor — also on WhatsApp, where no one is signed in — and `context.ai = {agent, tool, tool_call_id, conversation_id}`. Reads are not audited. Confirmations, declines and expiries are not audited either: nothing changed.

### Emails

`CreateReservationTool` emails every admin of the hotel about the reservation, and `CreateTaskTool` emails the admins and the assigned staff member — from the web chat and WhatsApp alike. Task updates notify a newly assigned person, as the task screen does.

## Error Responses

| Status | When | Body |
| --- | --- | --- |
| `401` | Missing/invalid bearer token | — |
| `403` | Not a hotel admin, or no hotel | `{"message": "...", "code": 403, "body": null}` |
| `404` | `conversation_id` not the caller's | `"Conversation not found."` |
| `409` | `pending_ids` do not match what waits | `"The actions waiting for confirmation have changed. Review them again."`, `body.pending_confirmation` |
| `422` | `decision` with nothing waiting | `"There is nothing waiting for confirmation."` |
| `422` | Validation (missing `message` and `decision`, etc.) | Laravel's default shape: `{"message": "...", "errors": {...}}` |

## Example cURL Requests

### Start a new conversation

```bash
curl -X POST http://your-domain.com/api/ai-advisor/chat \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "message": "Who arrives tomorrow without a room assigned?" }'
```

### Confirm the waiting actions

```bash
curl -X POST http://your-domain.com/api/ai-advisor/chat \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "conversation_id": "01977e3a-2b1c-7000-9000-abcdef123456",
    "decision": "confirm",
    "pending_ids": ["toolu_01…"]
  }'
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`. Only hotel admins can use the endpoint.
- The request is **synchronous** and can take several seconds; there is no streaming.
- Persist `conversation_id` between turns; omit it to start over.
- When `pending_confirmation` is not `null`, show its `items` as a Confirm / Cancel card with a countdown to `expires_at`. Send `decision` plus `pending_ids` from the buttons. Keep showing `reply` as a bubble.
- **A turn can change hotel data.** Refresh any list the admin is looking at after a turn, and surface what the reply says changed.

## Related Docs

- [Staff Roles API Documentation](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md) — permissions the tools check
- [Knowledge Base Article API Documentation](/D:/Hospitality%20Ecosystem/docs/knowledge-base-article-api-documentation.md)
- [AI Cost Attribution API Documentation](/D:/Hospitality%20Ecosystem/docs/ai-cost-attribution-api-documentation.md)
- [Auth API Documentation](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md)
