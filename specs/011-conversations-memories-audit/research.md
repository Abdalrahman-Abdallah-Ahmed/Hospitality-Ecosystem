# Research: Conversation Visibility, Guest Memories, and AI Audit

**Feature**: `011-conversations-memories-audit` · **Date**: 2026-10-10 · **Spec**: [spec.md](spec.md)

Each decision below was made after reading the code it touches. "Today" refers to `main`
at `4b42440`.

---

## R1 — Where the AI activity list reads from

**Today**:
- `event_log` holds per-record changes. For AI writes, `context.ai = {agent, tool,
  tool_call_id, conversation_id}` is filled only by `GuardedTool` (Admin AI) and the
  tools that call `EventLogger::asAiAgent()` with `$ai` themselves (check-in/out,
  create-reservation, the two recommendation tools).
- The Concierge turn in `ProcessInboundWhatsAppMessageJob` wraps the whole turn in
  `asAiAgent()` with **no** `$ai` context, so its writes say only "ai_agent".
- `pitch_decisions` and `proactive_messages` are their own records and are not in
  `event_log`.
- `ai_usage_logs` holds one row per provider call. It is not tenant-scoped and carries cost.
- There is no record of an "AI run" (one turn or one job).

**Decision**: a new append-only, hotel-scoped table, `ai_activities`, written alongside
the existing records. Each row has exactly one category: `data_change`, `guest_outreach`
or `ai_run`. The AI activity list is one indexed query on that table.
`event_log` stays the field-level record history; `ai_activities` is the action-level
index over it. A data-change row points at its target record, and the per-record history
(R9) shows the field changes.

**Rationale**:
- Filters (category, agent, tool, result, conversation, acted-for, record type, date) and
  pagination over a single table are simple and fast.
- `pitch_decisions`, `proactive_messages` and `ai_usage_logs` have different shapes. One is
  not tenant-scoped, and one holds cost the hotel must not see.

**Alternatives rejected**:
- *UNION over `event_log` + `pitch_decisions` + `proactive_messages` + usage at read time*:
  filters and stable pagination across four shapes, a cost table without a tenant scope,
  and every new source changes the query.
- *Promote AI columns onto `event_log` and write pitches, proactive messages and runs into
  it*: the audit trail is per-record (`uuidMorphs subject` NOT NULL). Runs and refused
  attempts have no natural subject, and `event_log` would grow columns only AI rows use.

---

## R2 — Tracking an AI run

**Decision**: `App\Support\Ai\AiRun`, a process-static context like `AiCostContext` and
`EventLogger`.
- The **outermost** `AiCostContext::for()` call opens a run. `AiCostContext` is already
  the one point every AI call passes through: it enforces the spend ceiling, and an
  undeclared call is logged as `unattributed`.
- `for()` gains optional `agent` and `conversationId` arguments. Nested contexts (the
  turn-signal classifier, inline recommendation generation, embedding calls) join the
  outer run.
- At open, the run gets a UUID (`AiRun::id()`).
- `AiCostRecorder::record()` stamps `ai_usage_logs.ai_run_id`.
- `EventLogger::context()` adds `ai.run_id`.
- Every `ai_activities` row written inside the run carries `ai_run_id`.
- At close (in `finally`), one `ai_run` row is written:
  - result `succeeded`, or `failed` with the exception class;
  - `usage = {requests, input_tokens, output_tokens, cached_tokens}`, summed from
    `ai_usage_logs` for the run id (one indexed query).
- A ceiling refusal throws before the run opens. It writes an `ai_run` row with result
  `refused`, reason `spend_ceiling`, and no usage.
- `conversationId` can be set late (`AiRun::setConversation()`), because the advisor, and
  the Concierge on a guest's first message, create their conversation inside the run. At
  close, the run back-fills `conversation_id` on its own rows that were written before it
  was known. That is the only update `ai_activities` permits.
- An `AiCostContext::for()` call that names no agent is recorded as agent `unattributed`.
  It is visible, never guessed, the same rule as `AiTriggerKind::UNATTRIBUTED`. Embedding-only
  runs (knowledge sync) use agent `knowledge`.

**Rationale**: run coverage is automatic for every current and future agent, the same way
cost capture already is. Usage is derived from the measured rows, not counted twice.

**Alternatives rejected**:
- *An explicit `AiRun::track()` at each call site*: a forgotten site is invisible.
- *Using `ai_usage_logs` as the run list*: one row per provider call, not per turn; not
  tenant-scoped; it carries cost.

---

## R3 — Recording AI write actions, including refused and failed ones

**Today**:
- Tools report refusals by **returning a string** ("No booking was made…"), not by
  throwing.
- `GuardedTool` returns its permission refusal as a string and writes nothing.
- A thrown exception inside `GuardedTool`'s `DB::transaction` rolls back the changes and
  their `event_log` rows. That part is correct: nothing claims success.

**Decision**: one recorder, `App\Support\Ai\AiActionRecorder::around(Tool|string $action,
callable $write)`, used by every AI write path.
1. Opens `EventLogger::capture()`, a new counter of the subjects `EventLogger::record()`
   wrote in this scope.
2. Runs the write inside `asAiAgent()` and `DB::transaction()`, with the `$ai` context
   (agent, tool, tool call id, conversation, run id).
3. After the transaction ends, writes one `data_change` row:
   - **succeeded**: at least one audited change committed. The target is the first
     recorded subject; `action` is its event type (e.g. `booking.created`); `changes_count`
     is the number of records touched.
   - **refused**: the tool returned without an audited change. The reason is the tool's
     return text, trimmed to 200 characters. These are system-authored messages, not
     model output or conversation text.
   - **failed**: an exception escaped. The transaction has rolled back; the row is
     written **after** the rollback, with the exception's short class name as the reason,
     and then the exception is re-thrown.
4. A `GuardedTool` permission refusal (`refusal()`) writes a `refused` row with reason
   `permission`. The inner tool never runs.

**Call sites**:
- `GuardedTool::handle()` for the Admin AI's write tools.
- A new `AuditedTool` wrapper for every write tool of the Concierge and the Recommendation
  agent. It mirrors `GuardedTool` without the permission part, since those agents act for
  a guest or the system.
- `AiActionRecorder::forJob()` around the write step of `CreateAiInsightsJob`,
  `GenerateActivityRecommendationsJob`, `IndexKnowledgeDocumentJob` (extraction result)
  and `ExtractGuestMemoriesJob`.

**Who the AI acted for**:
- Admin AI: `$this->user`.
- Concierge: the `Guest`.
- Jobs: the staff user who requested the job when there is one, otherwise none.

**Alternatives rejected**:
- *Make tools throw on refusal*: changes ~25 tools and their tests, and turns business
  refusals into exceptions.
- *Infer the result from the tool text*: fragile and language-dependent.

---

## R4 — The automated coverage check

**Decision**:
- Two marker interfaces: `App\Ai\Tools\Contracts\ReadsData` and `WritesData`. Every class
  in `app/Ai/Tools` (excluding `Admin/`, `Concerns/`, `Contracts/`) must implement exactly
  one.
- An architecture test (`AiActivityCoverageTest`) builds every agent's `tools()` with
  fixtures and asserts each `WritesData` tool reaches the model wrapped in `GuardedTool` or
  `AuditedTool`. This is the same reflection style as the existing
  `AdminToolsetArchTest` / `ConciergeToolsArchTest`.
- A behavior dataset runs each write tool once (success path) and asserts one complete
  `data_change` row: every FR-022 field non-null except `conversation_id` for jobs.
- A second dataset covers the AI jobs, pitch decisions, proactive messages and a run.

A new tool that implements neither interface, or a write tool registered unwrapped, fails
the suite.

---

## R5 — Guest outreach entries

**Decision**: model observers write `guest_outreach` rows through `AiActivityRecorder`.
- `PitchDecision`: when `result` becomes `pitched`, write `pitch.offered` (target: the
  recommendation).
- `RecommendationOutcome`: when an outcome is recorded for a recommendation that was
  pitched, write `pitch.accepted`, `pitch.declined` or `pitch.no_answer`.
- `ProactiveMessage`: when its status becomes `sent` or `skipped`, write
  `proactive.sent` or `proactive.skipped` with the skip reason. `ai_run_id` is null,
  because sending uses no AI. The agent is `concierge` and the job is
  `SendProactiveMessageJob`.

**Rationale**: these records are already the source of truth, and each transition is
written in exactly one place today, so an observer on the transition cannot miss one.

---

## R6 — Conversation viewer over the `laravel/ai` tables (D15)

**Decision**: read-only queries through the package models
(`Laravel\Ai\Models\Conversation` / `ConversationMessage`).
- **Hotel scope**: `participant_type = Guest morph AND participant_id IN (guest ids of
  the hotel)`. This is the pattern already used by `Guest::hotelConversationMessagesQuery`.
  The `Guest` subquery goes through its own tenant scope, so soft-deleted guests drop out
  (spec edge case). Staff/advisor conversations (participant `User`) are never included.
- **List**:
  - per conversation: guest, message count, first and last message time;
  - sorted by `updated_at` desc;
  - filters `guest_id`, `last_active_from`, `last_active_to`;
  - page size 25;
  - aggregates computed with one grouped subquery on `agent_conversation_messages`,
    using the existing `conversation_index`.
- **Show**: the conversation header plus its messages, paged, oldest first, 50 per page.
  - Only `role ∈ {user, assistant}` with non-empty `content` is shown. Tool-call-only
    assistant rows and tool rows are dropped.
  - `user` → `guest`, `assistant` → `concierge`.
  - `proactive = meta.proactive === true` (written by `ConversationAppender`).
  - attachments are reduced to `{type, name}`.
  - `tool_calls`, `tool_results`, `usage` and `meta` are never returned.
- **Super admin**: `hotel_id` is required, through `resolveHotel()`.
- **Access logging (FR-029)**: show writes one access event, but only for page 1 (or no
  `page` param), so paging through one conversation is a single "opened" event. It is
  `EventLogger::record($guest, 'conversation_viewed', reason: null)` with
  `context.conversation_id`. The list is not logged.

**Alternative rejected**: copying conversations into our own tables. D15 keeps the package
tables for MVP.

---

## R7 — Guest memories: storage, trigger, extraction

**Storage**: new `guest_memories` table: `BelongsToHotel`, `HasUuids`, `RecordsEvents`,
no soft deletes.
- `eventLoggedAttributes()` = `category`, `confidence`, `language`, `source_stay_id`.
  It deliberately leaves out **content** (FR-024).
- New `guest_memory_extractions` table, unique on `(hotel_id, guest_id, reservation_id)`.
  This is the idempotency key.

**Trigger**:
- `Stay` `updated` hook: when the status changes to `checked_out`, it dispatches
  `ExtractGuestMemoriesJob(guest, reservation)` **after commit**.
- It fires on the model, so check-out by staff endpoint, by Admin AI or by bulk check-out
  are all covered.
- The job returns early while the guest still has a live stay on that reservation. The
  last check-out does the work (spec edge case: multi-room).
- The job first inserts the extraction row with `ON CONFLICT DO NOTHING`. Only a fresh
  row, or a row in `pending_retry`, proceeds. That handles retry, redo and duplicates.
- Forward-only (spec Q): only check-outs after deploy dispatch the job. There is no
  backfill command.

**Skips**, which are recorded without an AI call:
- `disabled`: the hotel switch is off;
- `no_messages`: no guest-authored message between `checked_in_at` and `checked_out_at`
  in the guest's conversations.

**Extraction**: a new `GuestMemoryAgent` with structured output (`HasStructuredOutput`,
as `TurnSignalAgent` already does). The input is:
- the window's guest and Concierge texts;
- the guest's existing memories at this hotel (`id`, `category`, `content`, `language`,
  `confidence`).

It returns operations: `create {category, content, language, confidence}`,
`refresh {id, confidence}`, and `replace {id, category, content, language, confidence}`.

**Code-side guards**, applied after the model and never trusted to the prompt alone:
- valid category and language;
- confidence ≥ medium;
- content ≤ 200 characters;
- the content is rejected if it matches the sensitive patterns: a 13–19 digit run passing
  Luhn, passport/ID-like tokens, or email/phone numbers;
- referenced ids must belong to this guest and hotel;
- the 20-per-guest cap: evict the oldest, lowest-confidence memories first.

**Retry (FR-036)**:
- The job has `tries = 3` with backoff, for immediate retries.
- In `failed()`, or when the spend ceiling blocks it, a row on its first round becomes
  `pending_retry` with `retry_at = now + 24h`. A row on its second round becomes final:
  `failed` or `skipped_spend_ceiling`.
- An hourly `RetryGuestMemoryExtractionsJob` re-dispatches due rows.
- A super admin can re-run a final `failed` / `skipped_spend_ceiling` row from
  `routes/admin.php`.

**Metering**:
- The run is attributed as `AiTriggerKind::SCHEDULED_JOB` with agent `guest_memory`, so
  usage and cost are captured automatically.
- A dedicated `MeterFeature` case is left to Phase 12 item 3, as the spec allows.

---

## R8 — The Concierge reading memories, and memory requests

**Decision**:
- `GuestConciergeAgent::instructions()` gets a "What you may remember about this guest"
  section with all of the guest's memories for this hotel (at most 20, which is a small
  token cost). Memories are not served through a tool, because a tool would cost a model
  round-trip on every turn and the list is small.
- The section is only rendered when the hotel switch is on and memories exist.
- Rules in the section:
  - they are hints;
  - PMS tools win;
  - never list, quote or mention stored memories, categories or confidence;
  - use them in the guest's current language.
- **Memory requests (FR-037)**: `EscalateToHumanTool` gains an optional `request_type`
  enum (`see_memories`, `forget_memory`) and a `detail` field (what to forget).
  - `GuestRequestService::escalate()` puts the type into the escalation task's
    title/description, so staff can act on it from the memory panel.
  - The instructions tell the Concierge to call it for such requests and to stop using the
    matching memory for the rest of the conversation. The latter is prompt-level and
    tested with a faked agent and an assertion on the instructions.
- **Cross-hotel**: memories are loaded through `BelongsToHotel` within `runForHotel`
  (FR-016). The guest record is already per hotel.

---

## R9 — Per-record history types

**Decision**: add `booking`, `room`, `room-type` and `guest-memory` to
`HistoryController::SUBJECTS`. Rooms, room types and bookings are already `RecordsEvents`.
- Guest service requests are `Task`, which is already present.
- Contact preference lives on `Guest`. Add `proactive_opted_out_at` to
  `Guest::eventLoggedAttributes()` if it is missing; verify during implementation.
- `guest-memory`: the subject may have been deleted (memories have no soft delete). When
  `find()` misses, the controller still returns the events if the tenant-scoped
  `event_log` has rows for that subject. 404 only when there are none, which covers ids of
  another hotel and ids that never existed.

---

## R10 — The AI activity endpoint and cost visibility

**Decision**:
- `GET /api/ai-activity`, authorized with `EventLogPolicy::viewAny` (`history.view`).
  Super admin passes `hotel_id`.
- `ai_activities` uses `BelongsToHotel`.
- The resource adds `cost_usd` (the sum of `ai_usage_logs.cost_usd` for the run) only on
  `ai_run` rows and only when the caller is a super admin. Hotel users get the usage
  numbers without cost. This matches the constitution's AI-economics rule and Phase 12's
  check.
- Filters go through `GenericIndexRequest` / `GenericQuery` with explicit allowed fields.

---

## R11 — Removing the Admin AI's guest-messages tool (FR-033)

**Decision**:
- Delete the `GetGuestMessagesTool` entry from `AdminToolset`. The class stays, for
  `InsightsAgent` and `RecommendationAgent`.
- Add a line to `AdminAdvisorAgent` instructions: it cannot show guest messages; the
  conversation viewer is in the dashboard.
- Add a line to the Insights and Recommendation instructions: summarize themes, never
  quote a guest message verbatim.
- Tests:
  - `AdminToolsetArchTest`: the tool is absent.
  - Instruction assertions for all three agents.

---

## R12 — Permissions and the hotel switch

**Permissions** (`App\Enums\Permission`, grouped):
- `conversations.view`
- `guest_memories.view`
- `guest_memories.delete`

None of them is in `employeeDefaults()`. Conversations and memories are protected guest
data (constitution: "regular staff MUST NOT automatically receive it").

**Switch**: a new `hotels.guest_memories_enabled boolean NOT NULL DEFAULT true`, so
existing hotels are on.
- It is a dedicated column rather than a key in `ai_preferences`. `ai_preferences` is free
  JSON (with secrets filtered for the AI), and the switch needs a validated boolean and a
  default.
- It is written through the existing hotel update, which is admin-only.
- A change records `EventLogger::record($hotel, 'guest_memories_toggled', changes)`.
  `Hotel` is not `RecordsEvents`, so the event is recorded explicitly.

---

## Open items carried into tasks

- Verify `proactive_opted_out_at` is in `Guest::eventLoggedAttributes()` (R9).
- Confirm `IndexKnowledgeDocumentJob` writes through an audited model, so its extraction
  result can be a `data_change` (R3).
