# Contract: AI Agents and Tools Changes

## Tool classification (R4)

Every class in `app/Ai/Tools` implements exactly one of two marker interfaces.
- `App\Ai\Tools\Contracts\ReadsData`: reads only. It produces no `data_change` row; the
  turn's `ai_run` row covers it.
- `App\Ai\Tools\Contracts\WritesData`: may change hotel data. It must reach the model
  wrapped in `GuardedTool` (Admin AI) or `AuditedTool` (all other agents).

**Write tools by agent:**
- **Concierge**:
  - `CreateBookingTool`, `RequestBookingCancellationTool`, `UpdateRecommendationTool`
  - `CreateGuestServiceRequestTool`, `RequestRoomChangeTool`, `EscalateToHumanTool`
  - `SetContactPreferenceTool`, `PitchActivityTool`
- **Recommendation agent**: `CreateRecommendationTool`.
- **Admin AI**: every `self::write(...)` entry in `AdminToolset` (unchanged).

## `AuditedTool` (new) — `app/Ai/Tools/AuditedTool.php`

```text
new AuditedTool(inner: Tool, agent: AiAgentName, actedFor: ?Model)
// the conversation id comes from the current AiRun, not from the wrapper
```

- `name()`, `description()` and `schema()` are the inner tool's own, so the model sees no
  change.
- `handle()` runs `AiActionRecorder::around($inner, fn () => $inner->handle($request))`.
  The recorder handles `asAiAgent()`, the AI context, `DB::transaction` and the
  `data_change` row, with result `succeeded`, `refused` or `failed`.

`GuardedTool::handle()` replaces its inline `asAiAgent` + transaction with the same
recorder. A permission refusal writes a `refused` row (`reason: permission`).

## Admin AI (FR-033)

- `GetGuestMessagesTool` is removed from `AdminToolset`.
- An instruction is added: *"You cannot read or show guest messages. If asked what guests
  wrote, say that conversations are available to permitted staff in the dashboard's
  conversation viewer."*

## Insights and Recommendation agents (FR-033)

- They keep `GetGuestMessagesTool`.
- An instruction is added: *"Summarize themes; never quote a guest's message word for
  word."*

## Concierge (R8, FR-016–FR-018, FR-037)

A new instructions section, rendered only when the hotel switch is on and the guest has
memories at this hotel:

```text
What you may remember about this guest from earlier stays (hints, not facts):
- [preference] Vegetarian.
- [interest] Loved the sunset camel ride.
Rules: the reservation, booking and availability tools always win over these. Never list,
quote or mention that you keep these notes, their categories or confidence. Use them
naturally, in the guest's current language. If the guest asks what you remember about them,
or asks you to forget or correct something, use the escalation tool with request_type
(see_memories / forget_memory), tell them the hotel will take care of it, and stop using
that note for the rest of this conversation.
```

`EscalateToHumanTool` schema, extended:

| Field | Type | Notes |
| --- | --- | --- |
| `reason` | string, required | unchanged |
| `request_type` | enum, optional | `see_memories`, `forget_memory` |
| `detail` | string, optional | What the guest asked to forget or correct |

`GuestRequestService::escalate()` prefixes the escalation task title with the request type,
e.g. "Memory request — forget: vegetarian", so staff can act on it from the memory panel.

## `GuestMemoryAgent` (new) — structured output

**Input**:
- the stay window's guest and Concierge texts, oldest first;
- the guest's existing memories at this hotel (`id`, `category`, `content`, `language`,
  `confidence`).

**Output**:

```json
{ "operations": [
  { "op": "create",  "category": "preference", "content": "Vegetarian.", "language": "en", "confidence": "high" },
  { "op": "refresh", "id": "uuid", "confidence": "high" },
  { "op": "replace", "id": "uuid", "category": "communication", "content": "يفضّل التواصل مساءً.", "language": "ar", "confidence": "medium" }
] }
```

The server enforces the following no matter what the model returns (R7):
- the category and language are valid enum values;
- the confidence is at least medium;
- the content is at most 200 characters;
- the content matches none of the sensitive patterns;
- every referenced id belongs to this guest and hotel;
- the 20-memory cap.
