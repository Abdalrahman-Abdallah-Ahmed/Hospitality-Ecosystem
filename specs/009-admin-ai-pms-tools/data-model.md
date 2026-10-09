# Data Model: Admin AI PMS Tools

**No new tables and no migrations.** This feature only reads and writes existing domain
records, through existing or extracted services. The new structures are in-code
definitions plus one JSON shape added to an existing column.

## 1. Admin tool entry (in code: `AdminToolset`)

One row per tool the advisor can call. The registry is the single source for the guard,
the architecture test and the docs ([contract](contracts/admin-ai-tools.md)).

| Field | Type | Rules |
| --- | --- | --- |
| `tool` | `Laravel\Ai\Contracts\Tool` | Inner tool, constructed with `Hotel` and acting `User` |
| `kind` | `read` \| `write` | Writes are audited and transactional |
| `permissions` | `Permission[]` | All required at call time. Empty only when `adminOnly` |
| `adminOnly` | bool | Users, roles, settings, usage (R7). Mutually exclusive with `permissions` |
| `confirm` | bool \| `Closure(Request): bool` | FR-017. Per-call for status-dependent tools |
| `writes` | `class-string<Model>[]` | Models a write entry changes. Each must use `RecordsEvents`, so the write is audited. Empty for reads |
| `selfChecked` | bool | Only `GetReportTool`: its permission depends on the `report` argument, so the tool checks it per branch. Exactly one entry may set it (arch test) |

Invariants: see the contract's "Architecture invariants".

## 2. Pending confirmation (existing: `agent_conversation_messages`)

Owned by `laravel/ai`. Stored on the assistant message row that paused.

| Column | Use here |
| --- | --- |
| `conversation_id` | The admin's conversation (ownership already enforced) |
| `approval_state` | JSON. `pending`: `{tool_call_id: summary}`. Resolved results are appended by the framework |
| `tool_calls` | The paused calls with their arguments, which is what runs on confirm |
| `created_at` | Start of the 10-minute window (Q3) |

State transitions per paused batch (≤ 10 calls):

```text
            ┌── confirm ≤10 min ──────────► executed (each: done | refused by rule)
paused ─────┼── confirm >10 min ──────────► rejected "expired"
            ├── decline ──────────────────► rejected "declined"
            ├── any other message ────────► abandoned "not approved before the conversation continued"
            └── >10 calls at pause time ──► rejected "too many", model re-plans
```

A resolved tool call id can never be decided again: the framework throws
`ApprovalMismatchException`, which we map to 409 or 422.

## 3. AI audit context (existing: `event_logs.context`, JSON)

Added to every event a guarded write produces (R3):

```json
{
  "ip": "…", "route": "api/ai-advisor/chat",
  "ai": {
    "agent": "admin_advisor",
    "tool": "CancelReservationTool",
    "tool_call_id": "toolu_01…",
    "conversation_id": "0193…"
  }
}
```

The other `event_logs` columns are used as they are:
- `actor_kind = ai_agent`.
- `actor_type/actor_id` = the acting admin. On WhatsApp this is now set too, through
  `onBehalfOf`.
- `hotel_id`, `subject_*` (the target), `event_type` (the action), `changes`.

## 4. Domain records touched (all existing)

| Record | Read | Written by | Notes |
| --- | --- | --- | --- |
| Guest | ✓ | create, update | Dedupe by phone/email (R9) |
| Reservation | ✓ | create, update, cancel | No lifecycle status writes (FR-012). No no-show (R11) |
| Reservation room | ✓ | assign/change/remove `room_id` | `RoomAssignmentRules` |
| Room type | ✓ | – | |
| Room | ✓ | housekeeping status, out of order, return to service | Room status and housekeeping status stay separate |
| Stay | ✓ | check-in, check-out | `StayLifecycleService` |
| Task | ✓ | create, update, report issue | Linked housekeeping/maintenance side effects |
| Team, task category | ✓ | – | |
| Activity | ✓ | create (existing) | |
| Booking | ✓ | create, status, cancellation decision | Never overrides capacity |
| Knowledge base article | ✓ (search) | create, update (hotel only) | Re-indexed by existing sync |
| User, staff role, hotel settings | ✓ (allow-listed) | **never** | FR-018, FR-020 |
| Event log | – | via services only | Reads are not audited (FR-008) |
| Transaction | **never** | **never** | FR-021 |
