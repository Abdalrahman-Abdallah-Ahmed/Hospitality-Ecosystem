# Implementation Plan: Admin AI PMS Tools

**Branch**: `009-admin-ai-pms-tools` (spec directory; the work currently sits on `main`, so
branch before implementing) | **Date**: 2026-10-09 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/009-admin-ai-pms-tools/spec.md`. This is Phase 9
of the master plan, SPEC-055 Admin AI PMS Tools, governed by D17.

**Depends on**: Phases 1–8 (all on `main`): room types, reservation rooms, availability,
stay lifecycle, housekeeping and maintenance, activity bookings, knowledge.

## Summary

The Admin AI advisor gets a complete toolset over the current domain:
- 19 read tools: 9 new, 4 extended, 6 kept;
- 22 write tools: 15 new, 3 moved onto shared services, 4 kept.

All of it sits behind one guard, so permissions, tenancy, audit and confirmation are
enforced in one place ([toolset contract](contracts/admin-ai-tools.md)).

**Guard (R2)**:
- `AdminToolset` is the registry; `GuardedTool` is a decorator applied to every Admin tool.
- At call time the guard:
  - re-reads the acting user's permissions;
  - refuses without the declared `Permission` (or admin-only);
  - runs the tool inside `TenantContext::runForHotel`.
- Writes are wrapped in a transaction and in `EventLogger::asAiAgent(onBehalfOf, ai
  context)`.
- Shared tools are wrapped only for the Admin agent, so the Concierge, Insights and
  Recommendation agents are unchanged.

**Confirmation (R1)**:
- Uses `laravel/ai`'s native tool approvals (`Approvable`): the framework pauses a gated
  call before it runs and stores the pause on the conversation.
- A new `AdvisorTurn` service decides the admin's next message:
  - confirm within 10 minutes → approve all;
  - confirm after 10 minutes → reject as expired;
  - decline → reject;
  - anything else → the framework abandons the pause.
- More than 10 pending calls are rejected so the model re-plans in batches.
- The confirmation text is the tools' own summaries, not model prose.
- The HTTP chat gains optional `decision` and `pending_ids` fields and a
  `pending_confirmation` block. The change is additive
  ([chat contract](contracts/advisor-chat-api.md)). WhatsApp uses the same service with
  confirm and decline words.

**Parity (R4)**:
- Business rules that live in controllers today move, unchanged, into services that both
  the controller and the tool call:
  - guests;
  - reservations: create, update, cancel, assign;
  - tasks;
  - booking stay ↔ reservation consistency;
  - knowledge articles;
  - reports: dashboard, conversion, housekeeping board, maintenance list.
- The existing controller tests are the safety net and must pass unchanged.

**Audit (R3)**: `event_logs.context.ai = {agent, tool, tool_call_id, conversation_id}`,
with the acting admin as actor. This also fixes WhatsApp AI writes, which recorded no human
actor. The conversation is created before the turn, so its id is known while tools run.

**Limits**:
- No deletes.
- No user, role, permission or settings writes.
- No ledger and no provider cost.
- No overrides.
- Reads capped at 50 with a total.
- No reservation no-show: it is unbuilt, so it is deferred to SPEC-012 (R11), and the spec
  is updated.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**:
- Laravel 13, `laravel/ai` v0.10.3 (agents, tools, `Approvable` tool approvals,
  conversation store), Sanctum, Pest 4.
- No new packages.

**Storage**:
- PostgreSQL + pgvector, existing tables only.
- `agent_conversation_messages.approval_state` (framework) holds pending confirmations.
- `event_logs.context` (JSON) holds the AI audit block.
- **No migrations.**

**Testing**: Pest 4 feature tests against real Postgres (`Hospitality_Ecosystem_testing`).
- Tools are tested directly with `new Request($args)`.
- The advisor flow uses `AdminAdvisorAgent::fake()` with
  `AgentResponse::fakeWithPendingApprovals` and `Carbon::setTestNow`.

**Target Platform**: Linux server (JSON API + queue worker for WhatsApp).

**Project Type**: Web service (backend only; the frontend is the separate
`ecosystem-frontend` repo).

**Performance Goals**: Tool calls add no more than one indexed query per filter. Reads stay
under 50 rows, so a turn's latency is dominated by the model, as today.

**Constraints**:
- The confirmation window is 10 minutes, with at most 10 actions per batch (Q3, Q4).
- Reads return at most 50 records (FR-007).
- The model's tool-step budget is `round(tools × 1.5)` (framework default), about 60.
  Acceptable for this tool count.

**Scale/Scope**:
- 41 tools, about 12 service extractions, 1 new turn service, 1 decorator and registry.
- About 6 controllers slimmed.
- Docs: advisor chat, staff roles, latest changes.

## Constitution Check

*Gate before Phase 0 and re-checked after Phase 1. Constitution v2.0.0.*

| Principle | How this plan complies | Status |
| --- | --- | --- |
| I. PMS is the system of record | Live data only from read tools over PMS records. Knowledge never supplies availability or statuses (FR-023) | ✅ |
| II. Preserve and evolve | Existing tools are kept or extended, and shared tools are unchanged for other agents. Controller rules are moved, not rewritten. Additive chat contract. No migrations | ✅ |
| III. Tenant isolation | The hotel comes from the user. The guard runs every tool in `runForHotel`. Foreign ids give "not found". An isolation test per tool | ✅ |
| IV. Permission-based authorization | Each tool declares the `Permission` its endpoint's policy uses and is checked at call time. Admin-only resources stay outside the enum (CLAUDE.md). Allowed/403 tests per tool | ✅ |
| V. AI acts through tools | Every write goes through the same domain service as the API (R4). No overrides. No direct status writes | ✅ |
| VI. Auditability | Every AI write records the AI actor, the acting user, tool, target, action, hotel and conversation. Kept separate from conversation text | ✅ |
| VII. Data integrity and idempotency | Multi-record writes run in transactions. Duplicate pre-checks (R9). Single-use confirmations. Retries cannot double-run approved calls | ✅ |
| VIII. Tested at the domain boundary | Tool-level tests plus architecture, permission, isolation and parity datasets | ✅ |
| IX. Spec-driven | Spec → clarify → this plan. Breaking changes: none | ✅ |
| Domain: Admin AI reads users/roles/settings, never writes (D17) | No write tool for User, StaffRole or Hotel. Asserted by test | ✅ |
| AI economics: no provider cost to hotel admins | The usage report projection has no cost. No cost or ledger tool | ✅ |
| i18n: English and Arabic | The advisor replies in the admin's language. Confirmation words in both. Summaries rendered in the admin's language | ✅ |

**Post-design re-check**: still passes. The design adds no table, no permission case and no
new dependency. The one scope change, no-show deferred to SPEC-012, *narrows* scope, keeps
FR-010 intact, and is written into the spec.

## Project Structure

### Documentation (this feature)

```text
specs/009-admin-ai-pms-tools/
├── plan.md                         # This file
├── research.md                     # R1–R13 decisions
├── data-model.md                   # Registry, pending confirmation, audit context
├── quickstart.md                   # Validation guide
├── contracts/
│   ├── advisor-chat-api.md         # POST /api/ai-advisor/chat with confirmations
│   └── admin-ai-tools.md           # Tool catalog, permissions, invariants
├── checklists/requirements.md
└── tasks.md                        # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Ai/
│   ├── Agents/AdminAdvisorAgent.php            # tools() → AdminToolset; instructions rewritten (R12)
│   └── Tools/
│       ├── Admin/                              # NEW
│       │   ├── AdminToolset.php                # registry: tool, kind, permissions, adminOnly, confirm
│       │   ├── GuardedTool.php                 # decorator: permission, tenancy, audit, transaction, Approvable
│       │   ├── ConfirmsBeforeRunning.php       # interface: confirmationSummary(Request, locale)
│       │   └── ListResult.php                  # {total, returned, partial, items} helper (R6)
│       ├── Get{Guest,Reservation,RoomTypes,HousekeepingBoard,Maintenance,Bookings,Report,Staff,HotelSettings}Tool.php   # NEW reads
│       ├── Get{Guests,Reservations,Rooms,Tasks}Tool.php                 # EXTEND filters + bound
│       ├── {UpdateGuest,UpdateReservation,CancelReservation,AssignRooms,UpdateTask,
│       │    SetHousekeepingStatus,SetRoomOutOfOrder,UpdateOutOfOrder,ReturnRoomToService,
│       │    ReportTaskIssue,CreateActivityBooking,UpdateBookingStatus,DecideBookingCancellation,
│       │    CreateKnowledgeArticle,UpdateKnowledgeArticle}Tool.php          # NEW writes
│       └── Create{Guest,Reservation,Task}Tool.php                       # MOVE onto services
├── Services/
│   ├── Ai/AdvisorTurn.php                      # NEW: pause lookup, decision, 10-min window, ≤10 batch, reply text (R1)
│   ├── Guests/GuestRegistrar.php               # NEW (from GuestController)
│   ├── Reservations/ReservationCommands.php    # NEW (from ReservationController + ReservationCreator)
│   ├── Tasks/TaskCommands.php                  # NEW (from TaskController)
│   ├── Knowledge/ArticleCommands.php           # NEW (from KnowledgeBaseArticleController)
│   ├── Reports/{DashboardSummary,ConversionReport,HousekeepingBoard,MaintenanceList}.php  # NEW (from controllers)
│   └── BookingService.php                      # EXTEND: reservationFor()
├── Support/Audit/EventLogger.php               # EXTEND asAiAgent(onBehalfOf, ai) (R3)
├── Http/
│   ├── Controllers/AiAdvisorController.php     # delegate to AdvisorTurn; pending_confirmation
│   ├── Controllers/{Guest,Reservation,Task,Booking,KnowledgeBaseArticle,Dashboard,Analytics,
│   │               HousekeepingBoard,MaintenanceTask}Controller.php   # slimmed onto services
│   └── Requests/AiAdvisorChatRequest.php       # + decision, pending_ids
└── Jobs/ProcessInboundWhatsAppMessageJob.php   # admin branch → AdvisorTurn

tests/Feature/
├── AdminToolsetArchTest.php                    # invariants
├── AdminToolPermissionTest.php                 # dataset: every tool with and without its permission
├── AdminToolIsolationTest.php                  # foreign ids → not found
├── AdminToolParityTest.php                     # API vs tool
├── AdminReadToolsTest.php                      # filters, 50 bound, hotel-timezone dates
├── AdminWriteToolsTest.php                     # each write: success, rule refusal, duplicate
├── AdvisorConfirmationTest.php                 # R1 flows (HTTP + WhatsApp)
├── AdminAiAuditTest.php                        # ai context + acting user
└── (existing controller tests unchanged)

docs/
├── ai-advisor-chat-api-documentation.md        # chat contract + tool catalog
├── staff-roles-api-documentation.md            # permission reference: Admin AI tools per permission
└── latest-changes-2026-10-<dd>.md
```

**Structure Decision**: This is the existing single Laravel backend. The new code goes in
`app/Ai/Tools/Admin` (the guard), `app/Services/{Ai,Guests,Reservations,Tasks,Reports}`
(extracted operations) and new tools beside the existing ones in `app/Ai/Tools`. That
matches how tools are organised today. The frontend slice is small: render
`pending_confirmation` as a Confirm/Cancel card in the advisor chat. It is tracked for
`ecosystem-frontend` in `tasks.md`.

## Delivery order

Each step leaves the app working and the tests green.

1. **Guard and audit:** `EventLogger::asAiAgent(onBehalfOf, ai)`, `GuardedTool`,
   `AdminToolset`, plus the architecture test. Register the existing 17 admin tools in it.
   This alone fixes the uneven permission checks (User Story 4).
2. **Service extractions (R4)**, one area at a time, each followed by the full suite.
3. **Read tools** (User Story 1, plus Stories 5 and 6 reads).
4. **Front-desk writes** (User Story 2), then the confirmation flow: `AdvisorTurn`, the
   chat contract and WhatsApp.
5. **Housekeeping, maintenance, tasks and booking writes** (User Story 3), then knowledge
   article writes (User Story 6).
6. **Instructions rewrite** (R12), docs, then the quickstart walkthrough.

## Complexity Tracking

No constitution violations. Two deliberate choices are recorded for review:

| Choice | Why needed | Simpler alternative rejected because |
| --- | --- | --- |
| Decorator + registry instead of per-tool checks | One enumerable place for permission, audit and confirmation, which makes SC-002, SC-004 and SC-009 testable by enumeration | Per-tool checks are what exist today, and only 4 of 17 tools have them |
| Extracting controller rules into services | FR-010 and SC-005 need one code path per operation | Copying the guards into tools is what FR-010 forbids. Calling the HTTP endpoints internally re-enters auth and middleware |
