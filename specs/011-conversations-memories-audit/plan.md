# Implementation Plan: Conversation Visibility, Guest Memories, and AI Audit

**Branch**: `011-conversations-memories-audit` | **Date**: 2026-10-10 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/011-conversations-memories-audit/spec.md`.
Phase 11 of the master plan: SPEC-082 Conversation Visibility, SPEC-083 AI Audit
Consistency, SPEC-084 Guest Memory Extraction & Retrieval. Governed by D15.

**Depends on**: Phase 4 (stay check-out), Phase 7 (Concierge tools, escalation), Phase 9
(Admin AI `GuardedTool`, AI audit context), Phase 10 (pitch decisions, proactive messages,
`ConversationAppender`). All of them are on `main`.

## Summary

**Conversation viewer (R6)**:
- `GET /api/conversations` and `GET /api/conversations/{id}`, read-only over the
  `laravel/ai` tables.
- Scoped to the hotel's non-deleted guests; staff conversations are excluded.
- Messages are mapped to guest/concierge, with proactive ones flagged. Tool payloads,
  usage and meta are never returned.
- Gated by the new `conversations.view`.
- Opening a conversation (page 1) records `guest.conversation_viewed`.
- The Admin AI loses `GetGuestMessagesTool`, so guest text is only in the dashboard (R11).

**AI audit (R1–R5, R9, R10)**:
- **AI runs**: the outermost `AiCostContext::for()` opens an `AiRun`. Its id is stamped on
  usage logs, `event_log.context.ai` and every activity row. At close, the run writes one
  `ai_run` row with usage summed from `ai_usage_logs`. A spend-ceiling stop is recorded as
  `refused`.
- **Write actions**:
  - every write path goes through one `AiActionRecorder`, which writes one `data_change`
    row per tool call or job write: `succeeded`, `refused` or `failed`;
  - failed rows are written after the rollback;
  - `GuardedTool` uses it, and a new `AuditedTool` wraps every Concierge and
    Recommendation write tool.
- **Pitches and proactive messages**: observers on pitch decisions, outcomes and proactive
  messages write `guest_outreach` rows.
- **AI activity list**: a new `ai_activities` table, listed by `GET /api/ai-activity`
  under `history.view`. Cost appears only for super admins.
- **Per-record history**: gains `booking`, `room`, `room-type` and `guest-memory`.
- **Coverage check**: `ReadsData`/`WritesData` marker interfaces plus an architecture and
  behavior test (R4), which fails on an unwrapped or unclassified write tool.

**Guest memories (R7, R8, R12)**:
- **Storage**: `guest_memories` (audited, but never the content) and
  `guest_memory_extractions` (unique per guest and reservation).
- **Trigger**: a `Stay` → `checked_out` hook dispatches `ExtractGuestMemoriesJob` after
  commit. The last check-out on the reservation does the work.
- **Extraction**: a `GuestMemoryAgent` returns create, refresh and replace operations.
  Code guards enforce the categories, the confidence floor, the length, sensitive
  patterns, ownership and the 20-memory cap.
- **Retry**: 3 immediate retries, then one more round 24 h later via an hourly job, then
  final. A super admin can re-run a final failure.
- **Switch**: `hotels.guest_memories_enabled`, default true.
- **Concierge**: gets a memory section in its instructions.
  `EscalateToHumanTool` gains `request_type` and `detail` for see/forget requests.
- **Endpoints**: list and delete one guest's memories under `guest_memories.view` and
  `guest_memories.delete`. Listing records `guest.memories_viewed`.
- **Guest delete**: soft-deleting a guest deletes the guest's memories.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**:
- Laravel 13, Sanctum, Pest 4.
- `laravel/ai` v0.10.3: agents, tools, the conversation store, `HasStructuredOutput`, and
  `Approvable` from 009.
- No new packages.

**Storage**: PostgreSQL + pgvector.
- Create `ai_activities`, `guest_memories` and `guest_memory_extractions`.
- Alter `hotels` (`guest_memories_enabled`) and `ai_usage_logs` (`ai_run_id`).
- `event_log` is unchanged; the run id goes into its JSON context.

**Testing**:
- Pest 4 feature tests on real Postgres (`Hospitality_Ecosystem_testing`).
- Agent fakes (`::fake()`); tools are called directly for the tool-boundary tests.
- `Queue::fake` / sync queue for the extraction trigger; `Carbon::setTestNow` for the
  24 h retry.

**Target Platform**: Linux server: JSON API, queue worker, scheduler.

**Project Type**: Web service (backend only; the frontend is `ecosystem-frontend`).

**Performance Goals**:
- Conversation list: one query plus one grouped aggregate, under 300 ms for 10k
  conversations per hotel.
- Show page: under 200 ms for 50 messages.
- AI activity list: an indexed `(hotel_id, occurred_at)` scan, under 300 ms at 1M rows.
- Run close: one aggregate query over `ai_usage_logs` by `ai_run_id`.
- Recorder overhead per tool call: one insert.

**Constraints**:
- No conversation text, prompts, model output or memory content in `event_log` or
  `ai_activities` (FR-024).
- Provider cost is never in a hotel user's response.
- Background jobs set tenant context explicitly (`runForHotel`).
- Memories are forward-only, with no backfill.

**Scale/Scope**:
- 5 migrations; 7 enums plus 3 permission cases; 3 models; ~5 support/service classes;
  3 jobs; 1 new agent; 1 new tool wrapper; ~10 tools touched (marker interface).
- 6 endpoints new plus 2 extended; 2 new policies.
- Docs: 3 new API docs and 5 updated, plus latest-changes.

## Constitution Check

*Gate before Phase 0 and re-checked after Phase 1. Constitution v2.1.0.*

| Principle | How this plan complies | Status |
| --- | --- | --- |
| I. PMS is the system of record | Memories are hints. The instruction section says PMS tools win, and memories never hold PMS facts (FR-012 guard). Conversations stay in their store (D15) | ✅ |
| II. Preserve and evolve | Reuses `EventLogger`, `RecordsEvents`, `GuardedTool`, `AiCostContext`, `ConversationAppender` metadata, `GuestRequestService::escalate`, `HistoryController`, `EventLogPolicy`, `GenericQuery`. `GetGuestMessagesTool` is kept for its other agents; removing it from the Admin AI is documented as breaking | ✅ |
| III. Tenant isolation | `ai_activities`, `guest_memories` and `guest_memory_extractions` use `BelongsToHotel`. Conversations are scoped through the tenant-scoped `Guest` subquery. The extraction job runs in `runForHotel`. Isolation tests for every new endpoint | ✅ |
| IV. Permission-based authorization | 3 new `Permission` cases, none in employee defaults, checked through `allows()`. The AI activity list reuses `history.view`. Super-admin re-run is in `routes/admin.php`. The hotel switch is admin-only (rule 4) | ✅ |
| V. AI acts through tools | Every AI write goes through `GuardedTool`/`AuditedTool` → recorder → domain service. Extraction writes only through the job's validated operations. The Concierge cannot delete memories (FR-037) | ✅ |
| VI. Auditability | Every AI write, refusal and failure is a categorized row with its actor. Runs record usage. Viewing protected data is logged. Audit is separate from conversation text (FR-024) | ✅ |
| VII. Integrity and idempotency | Recorder wraps writes in a transaction, and a failure row is written after rollback. The unique extraction key plus `ON CONFLICT`. The `after commit` dispatch | ✅ |
| VIII. Tested at the domain boundary | Tool-, recorder-, job- and endpoint-level tests. The coverage check is enforced by tests. Permission and isolation datasets | ✅ |
| IX. Spec-driven | Spec → 2 clarify sessions (10 answers) → this plan | ✅ |
| Conversations & memories (domain section) | Admin visibility is explicit and extensible (a permission). No search. Extraction runs once after a completed stay. Memories are deletable and cascade with the guest | ✅ |
| AI economics | Run usage is visible to the hotel; cost only to super admins. Extraction is attributed `scheduled_job` | ✅ |
| i18n | Memories are stored in the guest's language (en/ar). The Concierge answers in the current language | ✅ |

**Post-design re-check**: passes. One addition beyond the phase text: the `ai_activities`
table and AI run tracking. The clarify session asked for "all AI activity in one place,
categorized", and R1 shows why existing tables cannot serve it.

## Project Structure

### Documentation (this feature)

```text
specs/011-conversations-memories-audit/
├── plan.md                       # This file
├── research.md                   # R1–R12
├── data-model.md                 # Tables, enums, state machine, audit events
├── quickstart.md                 # Validation guide
├── contracts/
│   ├── conversations-api.md      # list / show
│   ├── guest-memories-api.md     # list / delete / switch / super-admin re-run
│   ├── ai-activity-api.md        # AI activity list + history types
│   └── ai-tools.md               # markers, AuditedTool, Concierge/Admin/Insights changes, GuestMemoryAgent
├── checklists/requirements.md
└── tasks.md                      # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── AiActivityCategory.php, AiActivityResult.php, AiAgentName.php              # NEW
│   ├── GuestMemoryCategory.php, GuestMemoryConfidence.php                         # NEW
│   ├── GuestMemoryExtractionStatus.php, EscalationRequestType.php                 # NEW
│   └── Permission.php                     # + conversations.view, guest_memories.view, guest_memories.delete
├── Models/
│   ├── AiActivity.php                     # NEW: BelongsToHotel, append-only, Filterable
│   ├── GuestMemory.php                    # NEW: BelongsToHotel, RecordsEvents (no content)
│   ├── GuestMemoryExtraction.php          # NEW: BelongsToHotel
│   ├── Guest.php                          # memories(); deleted hook → delete memories; logged attrs check
│   ├── Hotel.php                          # guest_memories_enabled cast/fillable
│   ├── Stay.php                           # checked_out → dispatch ExtractGuestMemoriesJob after commit
│   └── PitchDecision.php, RecommendationOutcome.php, ProactiveMessage.php   # outreach observers (or Observers/)
├── Support/
│   ├── Ai/AiRun.php                       # NEW: run context (R2)
│   ├── Ai/AiCostContext.php               # outermost for() opens/closes the run; agent + conversation args
│   ├── Ai/AiActionRecorder.php            # NEW: around()/forJob() (R3)
│   ├── Ai/AiActivityRecorder.php          # NEW: the single writer of ai_activities rows
│   └── Audit/EventLogger.php              # capture() counter; context.ai.run_id
├── Services/
│   ├── AiCost/AiCostRecorder.php          # stamp ai_run_id
│   ├── Guests/GuestMemoryService.php      # NEW: apply operations + guards + cap (R7)
│   ├── Guests/ConversationQuery.php       # NEW: hotel-scoped list/show queries (R6)
│   └── GuestRequestService.php            # escalate(): request_type/detail in title
├── Ai/
│   ├── Agents/GuestMemoryAgent.php        # NEW: structured output
│   ├── Agents/GuestConciergeAgent.php     # memory section; write tools wrapped in AuditedTool
│   ├── Agents/RecommendationAgent.php     # CreateRecommendationTool wrapped; no-verbatim line
│   ├── Agents/InsightsAgent.php           # no-verbatim line
│   ├── Agents/AdminAdvisorAgent.php       # guest-messages instruction
│   └── Tools/
│       ├── Contracts/ReadsData.php, Contracts/WritesData.php   # NEW markers on every tool
│       ├── AuditedTool.php                # NEW
│       ├── Admin/GuardedTool.php          # use AiActionRecorder; refused row on permission
│       ├── Admin/AdminToolset.php         # − GetGuestMessagesTool
│       └── EscalateToHumanTool.php        # + request_type, detail
├── Jobs/
│   ├── ExtractGuestMemoriesJob.php        # NEW
│   ├── RetryGuestMemoryExtractionsJob.php # NEW, hourly (routes/console.php)
│   ├── ProcessInboundWhatsAppMessageJob.php  # pass agent + conversation id to the run
│   ├── CreateAiInsightsJob.php, GenerateActivityRecommendationsJob.php, IndexKnowledgeDocumentJob.php  # forJob() around writes
│   └── SendProactiveMessageJob.php        # (observer covers it; no change expected)
├── Http/
│   ├── Controllers/ConversationController.php        # NEW: index, show
│   ├── Controllers/GuestMemoryController.php         # NEW: index, destroy
│   ├── Controllers/AiActivityController.php          # NEW: index
│   ├── Controllers/Admin/GuestMemoryExtractionController.php   # NEW: index, rerun
│   ├── Controllers/HistoryController.php             # + booking, room, room-type, guest-memory
│   ├── Controllers/HotelController.php               # guest_memories_enabled + toggled event
│   ├── Requests/{ConversationIndex,AiActivityIndex,GuestMemoryIndex}Request.php   # NEW
│   └── Resources/{Conversation,ConversationMessage,GuestMemory,AiActivity,GuestMemoryExtraction}Resource.php  # NEW
└── Policies/
    ├── ConversationPolicy.php             # NEW (no model: Gate on a class string)
    ├── GuestMemoryPolicy.php              # NEW
    └── EventLogPolicy.php                 # reused as-is for GET /api/ai-activity (history.view)

routes/api.php       # conversations, guest memories, ai-activity
routes/admin.php     # guest-memory-extractions index + rerun
routes/console.php   # RetryGuestMemoryExtractionsJob hourly

tests/Feature/       # ConversationViewerTest, AdminGuestMessagesRemovalTest, AiActivityCoverageTest,
                     # AiActivityRecorderTest, AiRunTest, AiActivityApiTest, GuestMemoryExtractionTest,
                     # ConciergeMemoryTest, GuestMemoryApiTest; extend HistoryControllerTest,
                     # TenantIsolationTest, PermissionAuthorizationTest, AdminToolsetArchTest, AdminAiAuditTest

docs/                # conversations-, guest-memories-, ai-activity-api-documentation.md (new);
                     # history, hotel, ai-advisor-chat, staff-roles, guest (memories link); latest-changes-<date>.md
```

**Structure Decision**: the single Laravel application, in its existing layers (see
CLAUDE.md). Frontend slices (conversation viewer, guest memory panel, AI activity screen,
hotel switch) are described in `frontend-changes.md` during tasks, per D13.

## Complexity Tracking

| Addition | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| `ai_activities` table alongside `event_log` | One categorized list of all AI activity (clarify Q2) with fast filters | A UNION over 4 sources can't paginate stably, mixes in a cost table with no tenant scope, and changes with every new source. `event_log` needs a subject per row, and runs and refused attempts have none (R1) |
| `AiRun` context | Runs, and linking a turn's changes to its usage | A per-call-site wrapper can be forgotten; hooking the outermost `AiCostContext::for()` covers every agent automatically (R2) |
