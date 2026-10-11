---

description: "Task list for Conversation Visibility, Guest Memories, and AI Audit (Phase 11: SPEC-082, SPEC-083, SPEC-084)"
---

# Tasks: Conversation Visibility, Guest Memories, and AI Audit

**Input**: Design documents from `specs/011-conversations-memories-audit/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: Included. The constitution (§VIII) and CLAUDE.md require:
- feature tests for every new behavior;
- a tenant-isolation test for every tenant-scoped feature;
- an allowed/403 test for every permission;
- an automated AI audit coverage check (FR-026).

Write each story's tests first and confirm they fail before implementing.

**Organization**: grouped by user story in spec order: US1 and US2 (P1), then US3 and US4
(P2). The tables and models every story needs are in Foundational, so US4 (admin memory
panel) can ship without US3 (extraction). R-numbers refer to [research.md](research.md).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task).
- **[Story]**: the user story the task belongs to (US1–US4, numbered as in spec.md).

## Conventions for every task

- **Code**:
  - PHP 8.3 / Laravel 13.
  - Controllers are thin: authorize, validate, delegate, return `apiResponse()`. Every body
    goes through a Resource.
  - New models use UUIDs (`HasUuids`, `$keyType = 'string'`, `$incrementing = false`),
    `BelongsToHotel`, and explicit `$fillable` and `$casts`.
  - Jobs run inside `TenantContext::runForHotel()`.
- **FR-024 (hard rule)**: no conversation text, prompts, model output or memory content is
  ever written to `event_log` or `ai_activities`.
- **Tests**:
  - Pest files with `uses(RefreshDatabase::class)` and the `beforeEach` API-key setup from
    CLAUDE.md.
  - Call tools with `(string) $tool->handle(new Laravel\Ai\Tools\Request($args))`.
  - Fake agents with `::fake()`.
  - Shared fixtures go in `tests/Pest.php`, prefixed `cma` (conversations, memories,
    audit).
- **Migrations**: `database/migrations/2026_10_10_00000N_<description>.php`, continuing
  after `000005`. Never edit a shipped one.
- Run `./vendor/bin/pint` before committing.

---

## Phase 1: Setup

**Purpose**: Branch, baseline and shared fixtures.

- [X] T001 Create and switch to branch `011-conversations-memories-audit` from `main`. Run `php artisan test` against the testing DB and record the green baseline. The Postgres container must be up.
- [X] T002 Add shared fixtures to tests/Pest.php, building rows with `Model::create([...])` like the existing `rap*` helpers do (reuse them where they fit):
  - `cmaHotel(): Hotel`, `cmaAdmin(Hotel $h): User`, `cmaEmployee(Hotel $h, array $permissions = []): User`;
  - `cmaGuestConversation(Guest $g, array $messages): string`. It inserts an `agent_conversations` row (participant = the guest morph) plus `agent_conversation_messages` rows. Each message is `['role' => 'user'|'assistant', 'content' => '…', 'at' => Carbon, 'proactive' => bool, 'tool_calls' => []]`. It returns the conversation id.
  - `cmaStaffConversation(User $u): string`;
  - `cmaMemory(Guest $g, array $overrides = []): GuestMemory`;
  - `cmaCheckedOutStay(Hotel $h, array $guest = []): array` (guest, reservation, stay);
  - `cmaRequest($test, User $u, string $method, string $uri, array $payload = []): TestResponse`.

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: Enums, permissions, schema and models used by more than one story.

**⚠️ CRITICAL**: No user story work starts until this phase is complete.

- [X] T003 [P] Create the enums in app/Enums, as string-backed enums with the exact cases from data-model.md:
  - `AiActivityCategory` (`data_change`, `guest_outreach`, `ai_run`);
  - `AiActivityResult` (`succeeded`, `refused`, `failed`);
  - `AiAgentName` (`concierge`, `admin_advisor`, `recommendation`, `insights`, `turn_signal`, `document_vision`, `guest_memory`, `knowledge`, `unattributed`). `knowledge` is for embedding-only runs (knowledge sync). `unattributed` is the visible fallback for a run whose caller named no agent, matching `AiTriggerKind::UNATTRIBUTED`;
  - `GuestMemoryCategory` (`preference`, `communication`, `interest`, `other`);
  - `GuestMemoryConfidence` (`low`, `medium`, `high`);
  - `GuestMemoryExtractionStatus` (`running`, `pending_retry`, `extracted`, `skipped_no_messages`, `skipped_disabled`, `skipped_spend_ceiling`, `failed`);
  - `EscalationRequestType` (`see_memories`, `forget_memory`).
- [X] T004 [P] Add `CONVERSATIONS_VIEW = 'conversations.view'`, `GUEST_MEMORIES_VIEW = 'guest_memories.view'` and `GUEST_MEMORIES_DELETE = 'guest_memories.delete'` to app/Enums/Permission.php, grouped alphabetically with the others. Do **not** add them to `employeeDefaults()`; add a comment there saying conversations and memories are protected guest data (constitution, Conversations and Guest Memories).
- [X] T005 Create migration database/migrations/2026_10_10_000006_create_ai_activities_table.php exactly as in data-model.md:
  - `id` uuid PK;
  - `hotel_id` uuid FK nullable nullOnDelete;
  - `ai_run_id` uuid nullable;
  - `category`, `agent` string; `tool` string nullable; `action` string; `result` string; `reason` string(255) nullable;
  - nullable uuid morphs `target` and `acted_for`;
  - `changes_count` smallint default 0;
  - `conversation_id` string(36) nullable, with no FK (package table);
  - `trigger_kind` string nullable; `usage` json nullable;
  - `occurred_at` timestamp; `created_at` timestamp only (no `updated_at`).

  Indexes: `(hotel_id, occurred_at)`, `(hotel_id, category, occurred_at)`, `(hotel_id, conversation_id)`, `(ai_run_id)`, `(target_type, target_id)`.
- [X] T006 Create migration database/migrations/2026_10_10_000007_create_guest_memories_table.php:
  - `hotel_id` FK cascadeOnDelete; `guest_id` FK guests cascadeOnDelete;
  - `category` string; `content` string(200); `language` string(2);
  - `confidence` string; `source_stay_id` FK stays nullable nullOnDelete;
  - `extracted_at` timestamp; timestamps;
  - index `(hotel_id, guest_id, extracted_at)`.
- [X] T007 Create migration database/migrations/2026_10_10_000008_create_guest_memory_extractions_table.php:
  - `hotel_id`, `guest_id`, `reservation_id` FKs cascadeOnDelete;
  - `status` string; `attempts` smallint default 0; `retry_at` timestamp nullable;
  - `memories_written` smallint default 0; `ai_run_id` uuid nullable; `last_error` string(255) nullable; timestamps;
  - **unique `(hotel_id, guest_id, reservation_id)`**; index `(status, retry_at)`.
- [X] T008 Create migration database/migrations/2026_10_10_000009_add_guest_memories_enabled_to_hotels_table.php (`guest_memories_enabled boolean NOT NULL DEFAULT true`) and database/migrations/2026_10_10_000010_add_ai_run_id_to_ai_usage_logs_table.php (`ai_run_id uuid nullable` + index). Both need a working `down()`.
- [X] T009 [P] Create app/Models/AiActivity.php:
  - `BelongsToHotel`, `HasUuids`, `Filterable`;
  - `const UPDATED_AT = null`;
  - casts for `category`, `result`, `agent`, `trigger_kind` enums, `usage` array and `occurred_at` datetime;
  - `target()` and `actedFor()` morphTo;
  - `booted()` refuses deleting, and refuses updating **except** an update whose only dirty column is `conversation_id` going from null to a value (the run back-fill, T038), with a `RuntimeException`, mirroring app/Models/EventLog.php;
- [X] T010 [P] Create app/Models/GuestMemory.php:
  - `BelongsToHotel`, `HasUuids`, `Filterable`, `RecordsEvents`, **no** `SoftDeletes`;
  - `eventLoggedAttributes()` returns `['category', 'confidence', 'language', 'source_stay_id']`, with a comment that content is deliberately excluded (FR-024);
  - casts for the category and confidence enums and `extracted_at`;
  - `guest()` and `sourceStay()` belongsTo.
- [X] T011 [P] Create app/Models/GuestMemoryExtraction.php: `BelongsToHotel`, `HasUuids`, `status` cast to `GuestMemoryExtractionStatus`, `retry_at` datetime, and `guest()` / `reservation()` belongsTo.
- [X] T012 Update app/Models/Guest.php:
  - add `memories(): HasMany`;
  - in `booted()`, add a `deleted` hook (it fires on soft delete) that runs `GuestMemory::where('guest_id', $guest->id)->get()->each->delete()`, so each deletion is audited by `RecordsEvents` (FR-020);
  - confirm `proactive_opted_out_at` is in `eventLoggedAttributes()` and add it if missing (R9).
- [X] T013 Update app/Models/Hotel.php: add `guest_memories_enabled` to `$fillable` and cast it to `boolean`.
- [X] T014 Run `php artisan migrate` on the testing DB and confirm that the full suite is still green.

**Checkpoint**: Schema, enums, permissions and models are in place.

---

## Phase 3: User Story 1 — An admin reads what the Concierge told a guest (Priority: P1) 🎯 MVP

**Goal**: A read-only conversation viewer gated by `conversations.view`. Opening a
conversation is logged. The Admin AI can no longer return guest message text.

**Independent Test**:
- seed conversations for two hotels;
- list and open them as hotel A's admin: only A's guests appear, messages are in order
  with proactive ones flagged and no tool payloads;
- an employee without the permission gets 403; hotel B gets 404;
- an access event is recorded;
- the Admin AI toolset has no guest-messages tool.

### Tests for User Story 1 ⚠️

- [X] T015 [P] [US1] Write tests/Feature/ConversationViewerTest.php, covering spec US1 scenarios 1–8:
  - **list**: fields (guest name/phone, `message_count`, `started_at`, `last_message_at`), newest activity first, paged 25;
  - **filters**: `guest_id`, `last_active_from`/`last_active_to`; a `search` param is ignored;
  - **show**: oldest first, paged 50; `sender` guest/concierge; `proactive: true` from `meta.proactive`; attachments are `{type, name}` only; tool-only assistant rows and tool rows are omitted; no `tool_calls`/`usage`/`meta` keys anywhere in the JSON;
  - **scoping**: staff (`cmaStaffConversation`) and soft-deleted-guest conversations never listed and 404 on show;
  - **super admin**: without `hotel_id` → 422, with it → 200;
  - **access event**: show page 1 writes exactly one `guest.conversation_viewed` event (subject the guest, `context.conversation_id` set, no message text in `changes`/`context`); page 2 writes none; the list writes none.
- [X] T016 [P] [US1] Add conversation cases to tests/Feature/TenantIsolationTest.php: hotel B's admin lists no hotel-A conversations, and gets 404 on show by id and on `?guest_id=` for A's guest (an empty list).
- [X] T017 [P] [US1] Add `GET /api/conversations` and `GET /api/conversations/{id}` to the dataset in tests/Feature/PermissionAuthorizationTest.php under `conversations.view`: an employee with it gets 200 and without it gets 403.
- [X] T018 [P] [US1] Write tests/Feature/AdminGuestMessagesRemovalTest.php (FR-033, US1 #9):
  - `AdminToolset::for($admin)` contains no tool whose inner is `GetGuestMessagesTool`;
  - `AdminAdvisorAgent` instructions contain the conversation-viewer sentence;
  - `InsightsAgent` and `RecommendationAgent` still register `GetGuestMessagesTool`, and their instructions contain the "never quote … word for word" sentence.

  Update tests/Feature/AdminToolsetArchTest.php (and any test asserting `GetGuestMessagesTool` in the Admin toolset) to the new list.

### Implementation for User Story 1

- [X] T019 [US1] Create app/Services/Guests/ConversationQuery.php (R6):
  - `forHotel(Hotel $hotel)` builds the base query: `Laravel\Ai\Models\Conversation` where `participant_type = (new Guest)->getMorphClass()` and `participant_id IN Guest::query()->where('hotel_id', …)->select('id')`. The `Guest` subquery goes through the tenant scope, so soft-deleted guests are excluded.
  - `list(Hotel, array $filters, int $perPage)` adds a grouped subquery on `agent_conversation_messages` for `message_count`, `MIN(created_at)` and `MAX(created_at)`, filters `guest_id` and the last-active range (hotel-local dates converted with the hotel timezone), orders by `updated_at` desc, and eager-loads the participant guest.
  - `messages(string $conversationId, int $perPage)` keeps only `role IN ('user','assistant')` with non-empty trimmed `content`, oldest first, paginated.
- [X] T020 [P] [US1] Create app/Http/Resources/ConversationResource.php (`id`, `guest {id, name, phone}`, `message_count`, `started_at`, `last_message_at`) and app/Http/Resources/ConversationMessageResource.php:
  - `id`, `sender` (`user`→`guest`, `assistant`→`concierge`), `text`, `sent_at`;
  - `proactive` from decoded `meta['proactive'] ?? false`;
  - `attachments` mapped to `[{type, name}]` from the decoded JSON, with no payload;
  - never output `tool_calls`, `tool_results`, `usage` or `meta`.
- [X] T021 [P] [US1] Create app/Http/Requests/ConversationIndexRequest.php: `guest_id` uuid nullable, `last_active_from`/`last_active_to` `date_format:Y-m-d` nullable (`to` must be after or equal `from`), `per_page` integer 1–100, `page` integer ≥ 1, `hotel_id` uuid nullable.
- [X] T022 [US1] Create app/Policies/ConversationPolicy.php using `ChecksPermissions`:
  - `before()` lets a super admin through;
  - `viewAny(User $user)` returns `$this->allows($user, Permission::CONVERSATIONS_VIEW)`.

  Register it in app/Providers/AppServiceProvider.php as a gate ability on the class string `Laravel\Ai\Models\Conversation`, following how other policies are registered (check the provider first).
- [X] T023 [US1] Create app/Http/Controllers/ConversationController.php:
  - **`index`**: authorize `viewAny` on `Conversation::class`, `$hotel = resolveHotel()`, then `ConversationQuery::list`, then `apiResponse('Conversations fetched successfully.', 200, ConversationResource::collection(...))`.
  - **`show`**: authorize the same way; find the conversation inside `ConversationQuery::forHotel($hotel)`, or `abort(404)`.
    - When `page` is absent or 1, call `EventLogger::record($guest, 'conversation_viewed')`. Its context must carry the conversation id: add an optional `array $extraContext = []` parameter to `EventLogger::record()` in app/Support/Audit/EventLogger.php, merged into `context`.
    - Return `{ conversation, messages }` as in contracts/conversations-api.md.
- [X] T024 [US1] Register `GET /conversations` and `GET /conversations/{conversation}` (a plain string id, not model binding) in routes/api.php inside the tenant group, next to the guest routes.
- [X] T025 [US1] Remove the `GetGuestMessagesTool` entry from app/Ai/Tools/Admin/AdminToolset.php and its `use` if unused. Update the class docblock's "Deliberately absent" list with "reading guest messages (FR-033: the dashboard conversation viewer only)".
- [X] T026 [P] [US1] Add to the instructions in app/Ai/Agents/AdminAdvisorAgent.php: "You cannot read or show guest messages. If asked what guests wrote, say that conversations are available to permitted staff in the dashboard's conversation viewer."
- [X] T027 [P] [US1] Add "Summarize themes; never quote a guest's message word for word." to the instructions in app/Ai/Agents/InsightsAgent.php and app/Ai/Agents/RecommendationAgent.php.

**Checkpoint**: US1 tests pass. The viewer is usable and the Admin AI can't reveal guest
text.

---

## Phase 4: User Story 2 — Every AI change is traceable without reading the chat (Priority: P1)

**Goal**: Every AI run, write action (succeeded, refused or failed) and guest-outreach event
is a categorized `ai_activities` row, linked by run. It is listed at
`GET /api/ai-activity`, and the per-record history covers every AI-writable type. A test
fails when a write tool is unaudited.

**Independent Test**:
- run each AI write tool, an AI job, a pitch and a proactive send, and confirm one complete
  row each with the run link;
- register an unwrapped write tool and see `AiActivityCoverageTest` fail;
- list the rows, with filters; cost appears only for a super admin.

### Tests for User Story 2 ⚠️

- [X] T028 [P] [US2] Write tests/Feature/AiActivityRecorderTest.php (R3):
  - **succeeded**: one `data_change` row with agent, tool, action = the first event type, target = the first recorded subject, `changes_count`, conversation id, `acted_for` and `ai_run_id`;
  - **refused**: the tool returns without an audited change → `refused`, with the reason being the tool text trimmed to 200 characters;
  - **failed**: an exception → the row is still written after rollback with `result=failed` and the short exception class as reason, the exception is re-thrown, and no `event_log` rows from the call remain;
  - **permission**: a `GuardedTool` refusal → `refused` with reason `permission`, and the inner tool is never called;
  - **FR-024**: no row contains any message text from the request (assert on the serialized row).
- [X] T029 [P] [US2] Write tests/Feature/AiRunTest.php (R2):
  - **one row per call**: a faked Concierge turn through `ProcessInboundWhatsAppMessageJob` writes exactly one `ai_run` row with agent `concierge`, `trigger_kind guest_message`, the conversation id, acted_for the guest, and `usage` equal to the sums of the `ai_usage_logs` rows carrying that `ai_run_id`;
  - **nesting**: nested `AiCostContext::for()` calls produce no extra run;
  - **linking**: a write inside the turn carries the same `ai_run_id` on its activity row and in its `event_log.context.ai.run_id`;
  - **spend ceiling**: when it is exceeded → one `ai_run` row `refused` / `spend_ceiling` with `usage` null;
  - **exception**: a thrown agent exception → an `ai_run` row `failed`;
  - **advisor**: an advisor chat turn via `AiAdvisorController` gets agent `admin_advisor` and its conversation id;
  - **first message**: a guest with no prior conversation sends their first message. The `ai_run` row and any `data_change` row from that turn still carry the conversation id the turn created (U1);
  - **fallback agent**: an `AiCostContext::for()` call without `agent` writes a run row with agent `unattributed`, and it reads back through the `AiActivity` enum cast without error.
- [X] T030 [P] [US2] Write tests/Feature/AiActivityCoverageTest.php (R4, FR-026, SC-003):
  - **arch**: every class in app/Ai/Tools (excluding `Admin/`, `Concerns/`, `Contracts/`, `AuditedTool`) implements exactly one of `ReadsData` / `WritesData`;
  - **wrapping**: build `GuestConciergeAgent`, `RecommendationAgent` (with fixtures) and `AdminToolset::for($admin)`, and assert every tool whose (inner) class implements `WritesData` is a `GuardedTool` or `AuditedTool`, while read tools are not required to be;
  - **behavior dataset**: one success case per write tool (Concierge: `CreateBookingTool`, `RequestBookingCancellationTool`, `UpdateRecommendationTool`, `CreateGuestServiceRequestTool`, `RequestRoomChangeTool`, `EscalateToHumanTool`, `SetContactPreferenceTool`, `PitchActivityTool`; Recommendation: `CreateRecommendationTool`; Admin: every `self::write` entry). Each asserts exactly one `data_change` row with every FR-022 field non-null except `conversation_id` where none exists;
  - **jobs dataset**: `CreateAiInsightsJob`, `IndexKnowledgeDocumentJob` (extraction result) and `ExtractGuestMemoriesJob` (stubbed until US3, then enabled) each write their `data_change` row with `tool` = the job name. `GenerateActivityRecommendationsJob` is **not** in this dataset: its writes are recorded per recommendation by `AuditedTool` around `CreateRecommendationTool`, covered by the behavior dataset above. Add one assertion that a run of that job produces exactly one `data_change` row per recommendation created, with tool `CreateRecommendationTool` and none with tool `GenerateActivityRecommendationsJob`.
- [X] T031 [P] [US2] Write tests/Feature/AiActivityOutreachTest.php (R5):
  - a `PitchDecision` reaching `result = pitched` → one `guest_outreach` row `pitch.offered` targeting the recommendation;
  - an outcome on a pitched recommendation → `pitch.accepted` / `pitch.declined` / `pitch.no_answer`;
  - a `ProactiveMessage` reaching `sent` → `proactive.sent`; reaching `skipped` → `proactive.skipped` with the skip reason; `ai_run_id` is null; agent `concierge`, tool `SendProactiveMessageJob`;
  - no duplicate row when the same status is saved twice.
- [X] T032 [P] [US2] Write tests/Feature/AiActivityApiTest.php (contracts/ai-activity-api.md, US2 scenarios 6–8):
  - each filter: category (incl. comma list), agent, tool, result, `conversation_id`, `ai_run_id`, `acted_for_type` + `acted_for_id`, `target_type`, from/to (hotel-local);
  - newest first, paged; `target.history_url` present;
  - `cost_usd` present only for a super admin (with `hotel_id`) and absent for a hotel admin;
  - an employee with `history.view` → 200, without → 403;
  - hotel B sees none of A's rows.

  Add the endpoint to the tests/Feature/PermissionAuthorizationTest.php dataset under `history.view`, and an isolation case to tests/Feature/TenantIsolationTest.php.
- [X] T033 [P] [US2] Extend the history tests (tests/Feature/HistoryControllerTest.php; create it if no history test exists — check `grep -rl "api/history" tests` first):
  - the new types `booking`, `room`, `room-type`, `guest-memory` return their events;
  - a deleted guest memory's history is still readable;
  - another hotel's memory id → 404; an unknown id → 404.

### Implementation for User Story 2

- [X] T034 [P] [US2] Create the marker interfaces app/Ai/Tools/Contracts/ReadsData.php and app/Ai/Tools/Contracts/WritesData.php (empty interfaces, with docblocks per contracts/ai-tools.md). Add `implements ReadsData` or `WritesData` to every class in app/Ai/Tools:
  - **WritesData**: `CreateBookingTool`, `RequestBookingCancellationTool`, `UpdateRecommendationTool`, `CreateGuestServiceRequestTool`, `RequestRoomChangeTool`, `EscalateToHumanTool`, `SetContactPreferenceTool`, `PitchActivityTool`, `CreateRecommendationTool`, and every tool registered with `self::write` in AdminToolset;
  - **ReadsData**: everything else (all `Get*Tool`, `KnowledgeSearchTool`).
- [X] T035 [US2] Create app/Support/Ai/AiActivityRecorder.php, the single writer of `ai_activities`, with:
  - `dataChange(...)`;
  - `outreach(...)`;
  - `run(...)`.

  It builds rows from the current `AiRun` (`ai_run_id`, conversation, agent) and `TenantContext`/hotel. `reason` is truncated with `Str::limit(…, 200, '')`. It never accepts free text other than `reason`.
- [X] T036 [US2] Create app/Support/Ai/AiRun.php, a process-static run context (R2):
  - `open(AiAgentName|string $agent, AiTriggerKind $kind, ?Hotel $hotel, ?Model $actedFor, ?string $conversationId): string` (returns a UUID);
  - `id()`, `setConversation(string $id)`, `current()`;
  - `close(AiActivityResult $result, ?string $reason)`: sums `ai_usage_logs` (`count(*)`, input, output and cached tokens) where `ai_run_id = id`, then writes the `ai_run` row via `AiActivityRecorder::run` with `action = 'run.completed'`;
  - `reset()`.

  Add `AiRun::reset()` to the reset in tests/TestCase.php next to `TenantContext`.
- [X] T037 [US2] Update app/Support/Ai/AiCostContext.php:
  - **new parameters**: `for()` gains optional `?string $agent = null, ?string $conversationId = null`.
  - **outermost call**: when `self::$stack === []`, run the ceiling check inside a try. On `AiSpendCeilingExceededException`, write an `ai_run` row `refused` / `spend_ceiling` (no usage) via `AiActivityRecorder`, then re-throw.
  - **run lifecycle**: otherwise `AiRun::open(...)` before the callback; in `finally`, `AiRun::close(succeeded|failed, exception short class)`.
  - **nested calls** never open a run.
  - **agent fallback**: when `$agent` is null, use `AiAgentName::UNATTRIBUTED`. Do not guess, matching how `AiTriggerKind::UNATTRIBUTED` works.
- [X] T038 [US2] Pass `agent`/`conversationId` at every `AiCostContext::for` call site:
  - app/Jobs/ProcessInboundWhatsAppMessageJob.php:
    - **concierge**: pass `$agent->currentConversation()`, which is null on a guest's first message. After `$agent->prompt()` returns, call `AiRun::setConversation($response->conversationId ?? $agent->currentConversation())`, so the run row (written at close) carries it.
    - **data-change rows already written earlier in that turn**: `AiRun::close()` back-fills `conversation_id` on them with one update `where ai_run_id = id and conversation_id is null`. This is the only update `ai_activities` allows: whitelist it in `AiActivity::booted()` by permitting an update whose only dirty column is `conversation_id` changing from null.
    - **advisor**: via `AiRun::setConversation()` once `whatsAppConversation()` returns.
  - app/Http/Controllers/AiAdvisorController.php: admin_advisor;
  - app/Jobs/CreateAiInsightsJob.php: insights;
  - app/Jobs/GenerateActivityRecommendationsJob.php: recommendation;
  - app/Jobs/IndexKnowledgeDocumentJob.php: document_vision;
  - app/Jobs/SyncKnowledgeChunksJob.php: embeddings, agent `knowledge` (enum case from T003).
- [X] T039 [US2] Update app/Services/AiCost/AiCostRecorder.php to write `ai_run_id => AiRun::id()` on every `AiUsageLog::create`, and add `ai_run_id` to `$fillable` in app/Models/AiUsageLog.php.
- [X] T040 [US2] Update app/Support/Audit/EventLogger.php:
  - **`context()`**: adds `ai.run_id` from `AiRun::id()` when inside `asAiAgent`.
  - **`capture(callable $fn): array`**: returns `[$result, list<array{subject: Model, event_type: string}>]` of the events recorded during `$fn`. Implement it with a static capture stack appended to in `record()`, so nested captures work.
- [X] T041 [US2] Create app/Support/Ai/AiActionRecorder.php (R3):
  - **`around(string $agent, string $tool, ?Model $actedFor, ?string $toolCallId, callable $write): mixed`**:
    - runs `EventLogger::asAiAgent(fn () => EventLogger::capture(fn () => DB::transaction($write)), onBehalfOf: $actedFor, ai: [agent, tool, tool_call_id, conversation_id => AiRun::current conversation, run_id])`;
    - after it returns: captured events ≥ 1 → `dataChange(succeeded, target = first subject, action = first event_type, changes_count = distinct subjects)`; 0 → `dataChange(refused, reason = (string) result)`;
    - on `Throwable`: `dataChange(failed, reason = class_basename($e))` **after** the transaction rolled back, then re-throw.
  - **`refused(string $agent, string $tool, ?Model $actedFor, string $reason)`**: for refusals that happen before the write runs.
  - **`forJob(string $agent, string $job, ?Model $actedFor, callable $write)`**: the same as `around`, with `tool = $job`.
- [X] T042 [US2] Refactor app/Ai/Tools/Admin/GuardedTool.php:
  - **`handle()` write branch**: replace the inline `asAiAgent` + `DB::transaction` with `AiActionRecorder::around('admin_advisor', $this->name(), $this->user, $request->toolCallId(), fn () => $this->inner->handle($request))`.
  - **refusal**: when `refusal()` returns a message for a WRITE tool, call `AiActionRecorder::refused(..., 'permission')` before returning it.
  - **reads** are unchanged.

  Keep `conversation_id` flowing by calling `AiRun::setConversation($this->conversationId)` if the run has none. Existing tests/Feature/AdminAiAuditTest.php must stay green; update its assertions only where `context.ai` gains `run_id`.
- [X] T043 [US2] Create app/Ai/Tools/AuditedTool.php:
  - implements `Tool`;
  - constructor `(Tool $inner, AiAgentName $agent, ?Model $actedFor)`;
  - `name()`, `description()` and `schema()` delegate to the inner tool;
  - `handle()` returns `AiActionRecorder::around($agent->value, class_basename($inner), $actedFor, $request->toolCallId(), fn () => $inner->handle($request))`.
- [X] T044 [US2] In app/Ai/Agents/GuestConciergeAgent.php `tools()`, wrap every `WritesData` tool in `new AuditedTool($tool, AiAgentName::CONCIERGE, $this->guest)`, including the tool from `pitchTools()`. Do the same in app/Ai/Agents/RecommendationAgent.php for `CreateRecommendationTool`, with agent `recommendation` and actedFor `null`: the agent's constructor is `(Hotel, Reservation, RecommendationSource)` and holds no user. Read tools stay unwrapped.
- [X] T045 [US2] **Keep** the Concierge's turn-level `EventLogger::asAiAgent` wrapper in app/Jobs/ProcessInboundWhatsAppMessageJob.php. `PitchCoordinator` and other turn bookkeeping write outside tools and must still be attributed to the AI. Complete it with `onBehalfOf: $this->sender` and `ai: ['agent' => 'concierge', 'conversation_id' => $agent->currentConversation()]` (`run_id` is added by `EventLogger::context()`, T040). Add a comment: tool writes are recorded by `AuditedTool`; this wrapper attributes the rest of the turn.
- [X] T046 [US2] Wrap the write step of each AI job in `AiActionRecorder::forJob(...)`:
  - app/Jobs/CreateAiInsightsJob.php: the `AiInsights` create; agent insights; actedFor the requesting user if the job has one;
  - app/Jobs/GenerateActivityRecommendationsJob.php: **no** `forJob`. Its writes go through `CreateRecommendationTool`, which `AuditedTool` records once per recommendation (T044). Add a comment saying so, to prevent double rows;
  - app/Jobs/IndexKnowledgeDocumentJob.php: the extraction result write, after confirming `KnowledgeDocument` is `RecordsEvents` (it is).
- [X] T047 [US2] Create the outreach observers in app/Observers/PitchDecisionObserver.php, app/Observers/RecommendationOutcomeObserver.php and app/Observers/ProactiveMessageObserver.php, and register them via `#[ObservedBy]` on the models or in AppServiceProvider, matching repo style:
  - **pitch offered**: write on `saved` when `result` changed to `pitched`;
  - **outcome**: write on `saved` when the outcome is set for a recommendation that has a pitched decision; map to `pitch.accepted|declined|no_answer`;
  - **proactive**: write on `saved` when `status` changed to `sent`/`skipped`, with `reason` = the skip reason.

  Use `wasChanged()` so the same status saved twice writes once. Agent `concierge`; `acted_for` is the guest.
- [X] T048 [P] [US2] Create app/Http/Requests/AiActivityIndexRequest.php: every filter from contracts/ai-activity-api.md with enum validation (`Rule::enum`), `from`/`to` `date_format:Y-m-d`, `per_page` 1–100, `hotel_id` uuid nullable.
- [X] T049 [P] [US2] Create app/Http/Resources/AiActivityResource.php in the contract's shape:
  - `target {type (slug), id, history_url}`, using the slug map shared with HistoryController (extract `HistoryController::SUBJECTS` to app/Support/Audit/HistorySubjects.php so both use one map);
  - `acted_for {type, id, name}`;
  - `usage` and `trigger_kind` only on `ai_run` rows;
  - `cost_usd` only when `$request->user()->isSuperAdmin()` and the category is `ai_run`: the sum of `ai_usage_logs.cost_usd` for the run, preloaded in the controller as one grouped query, not per row.
- [X] T050 [US2] Create app/Http/Controllers/AiActivityController.php:
  - **`index`**: authorize `viewAny` on `EventLog::class` (history.view); `resolveHotel()`; query `AiActivity` with filters applied, `occurred_at` desc, paginated;
  - **cost**: for super admins, preload the costs per run id in one query;
  - return `apiResponse('AI activity fetched successfully.', 200, AiActivityResource::collection(...))`.

  Register `GET /ai-activity` in routes/api.php next to `/history`.
- [X] T051 [US2] Update app/Http/Controllers/HistoryController.php. Guest service requests are already covered as `task`: `GuestRequestService` creates `Task` records.
  - use app/Support/Audit/HistorySubjects.php;
  - add `booking` → Booking, `room` → Room, `room-type` → RoomType, `guest-memory` → GuestMemory;
  - for a subject not found (incl. deleted guest memories), return the events if the tenant-scoped `EventLog` has any for that subject, and 404 otherwise.

**Checkpoint**: US2 tests pass. `AiActivityCoverageTest` is green, and it fails when a write
tool is unwrapped (verify by temporarily unwrapping one).

---

## Phase 5: User Story 3 — The Concierge remembers a returning guest (Priority: P2)

**Goal**: After the last check-out on a reservation, extract durable memories once. Then
feed them to the Concierge as hints; memory requests escalate to staff.

**Independent Test**:
- check out a guest whose conversation has clear preferences, with `GuestMemoryAgent` faked;
- expected memories exist, with no duplicates on re-trigger;
- skips and guards hold; the retry flow works;
- the next Concierge turn's instructions include only this guest's memories at this hotel;
- a forget request escalates.

### Tests for User Story 3 ⚠️

- [X] T052 [P] [US3] Write tests/Feature/GuestMemoryExtractionTest.php (spec US3 1–5, FR-010–FR-015, FR-032, FR-036):
  - **trigger and window**: a `Stay` → `checked_out` (via `StayLifecycleService::checkOut`) dispatches `ExtractGuestMemoriesJob` after commit; the job reads only guest/concierge messages between `checked_in_at` and `checked_out_at`;
  - **multi-room**: a guest with two stays on one reservation is extracted only after the second check-out;
  - **idempotency**: re-trigger, re-dispatch and a duplicate job leave one extraction row and no duplicate memories;
  - **skips**: `skipped_no_messages` with no AI call (assert `GuestMemoryAgent` was never prompted); `skipped_disabled` when `guest_memories_enabled = false`;
  - **guards**: low confidence dropped; content > 200 chars dropped; a card number (Luhn-valid 16 digits), an email and a passport-like token dropped; an operation referencing another guest's memory id ignored; refresh updates `source_stay_id`, `extracted_at` and confidence (kept or raised); replace overwrites content and language (Arabic → English case);
  - **cap**: the 21st memory evicts the oldest lowest-confidence one;
  - **retry and finality**:
    - a provider exception after 3 tries → `pending_retry`, `retry_at` ≈ +24 h; `RetryGuestMemoryExtractionsJob` at +24 h re-runs it; a second failure → `failed` (final);
    - spend ceiling → `pending_retry`, then `skipped_spend_ceiling`;
    - `skipped_no_messages` / `skipped_disabled` are never retried;
  - **audit**: the extraction writes a `data_change` row (`tool = ExtractGuestMemoriesJob`) and an `ai_run` row with agent `guest_memory`; `guest_memory.created` events contain no content.
- [X] T053 [P] [US3] Write tests/Feature/ConciergeMemoryTest.php (US3 6–11, FR-016–FR-018, FR-037):
  - **instructions**: they contain the memory section with this guest's memories at this hotel;
  - **exclusions**: no section when the switch is off or there are no memories; never another guest's or another hotel's memories (same phone, two hotels);
  - **deleted memories**: a deleted memory is absent on the next build (SC-008);
  - **wording**: the section text contains the "tools always win" and "never list" rules;
  - **escalation**: `EscalateToHumanTool` with `request_type = forget_memory`, `detail = 'vegetarian'` creates an escalation task whose title starts "Memory request — forget: vegetarian"; `see_memories` gives "Memory request — see memories"; no memory is deleted by either.

### Implementation for User Story 3

- [X] T054 [P] [US3] Create app/Ai/Agents/GuestMemoryAgent.php with `HasStructuredOutput`, following app/Ai/Agents/TurnSignalAgent.php:
  - **instructions**: extract only durable facts (preferences, communication preferences, recurring interests, lasting needs); never PMS facts (dates, room numbers, prices, booking statuses), payment data, ID numbers, or health details beyond a stated accessibility need; write each fact in the language the guest used; prefer `refresh`/`replace` over `create` for existing memories;
  - **schema**: `operations[]` with `op` ∈ create/refresh/replace, `id`, `category`, `content`, `language` ∈ en/ar, `confidence` ∈ low/medium/high.
- [X] T055 [US3] Create app/Services/Guests/GuestMemoryService.php:
  - **`apply(Guest $guest, Stay $sourceStay, array $operations): int`** (count written), in one transaction. The guards from R7 are enforced in code:
    - valid enums; confidence ≥ medium; `mb_strlen(content) ≤ 200`;
    - reject content matching sensitive patterns: a 13–19 digit run (spaces/dashes allowed) passing Luhn, an email regex, a phone-number regex, and a passport-like `[A-Z]{1,2}\d{6,9}`;
    - ids must belong to this guest and hotel; refresh never lowers confidence.
  - **cap**: enforce 20 per guest per hotel by deleting the surplus, ordered by confidence asc then `extracted_at` asc.
  - **`forConcierge(Guest $guest, Hotel $hotel): Collection`**: empty when `! $hotel->guest_memories_enabled`, otherwise the guest's memories ordered by category.
- [X] T056 [US3] Create app/Jobs/ExtractGuestMemoriesJob.php:
  - `(string $hotelId, string $guestId, string $reservationId)`; `tries = 3`, `backoff = [60, 300]`; runs in `runForHotel`.
  - **Steps**:
    1. Return early if the guest still has a live stay on the reservation.
    2. Insert or claim the `guest_memory_extractions` row (`insertOrIgnore` on the unique key, then a conditional update claiming `status IN (new, pending_retry)` → `running`, `attempts + 1`). Return if not claimed.
    3. If the switch is off → `skipped_disabled`.
    4. Collect the guest's messages in the window from their conversations (role user/assistant, non-empty) across the reservation's stays. If none are from the guest → `skipped_no_messages`.
    5. Otherwise call `AiCostContext::for(kind: SCHEDULED_JOB, hotel, trigger: $guest, agent: 'guest_memory', callback: fn () => AiActionRecorder::forJob('guest_memory', 'ExtractGuestMemoriesJob', null, fn () => GuestMemoryService::apply(...)))`, with the agent's structured response feeding `apply`.
    6. Then `extracted`, `memories_written`, `ai_run_id`.
  - **Errors**:
    - `AiSpendCeilingExceededException` → `attempts < 2 ? pending_retry (retry_at = now()->addDay()) : skipped_spend_ceiling`, without failing the job;
    - `failed(Throwable $e)` → `attempts < 2 ? pending_retry : failed`, with `last_error = class_basename($e)`.
- [X] T057 [US3] Create app/Jobs/RetryGuestMemoryExtractionsJob.php: select `pending_retry` rows with `retry_at <= now()` (`withoutScope`, deliberately cross-hotel, with a comment) and dispatch `ExtractGuestMemoriesJob` for each. Schedule it `->hourly()->withoutOverlapping()` in routes/console.php.
- [X] T058 [US3] In app/Models/Stay.php, add an `updated` hook: when `status` was changed to `StayStatus::CHECKED_OUT`, call `ExtractGuestMemoriesJob::dispatch(...)->afterCommit()`. Add a comment that this is forward-only (spec Q: no backfill) and covers every check-out path.
- [X] T059 [US3] Update app/Ai/Agents/GuestConciergeAgent.php `instructions()`:
  - render the memory section from contracts/ai-tools.md only when `GuestMemoryService::forConcierge()` is non-empty;
  - list memories as `- [category] content`, without confidence;
  - include the rules text verbatim from the contract.
- [X] T060 [US3] Extend app/Ai/Tools/EscalateToHumanTool.php with optional schema fields `request_type` (enum `EscalationRequestType`) and `detail` (string), and pass them to `GuestRequestService::escalate()` in app/Services/GuestRequestService.php. That method gains `?EscalationRequestType $type = null, ?string $detail = null` and prefixes the task title:
  - `forget_memory` → "Memory request — forget: {detail}";
  - `see_memories` → "Memory request — see memories".

  Existing tests/Feature/GuestEscalationTest.php must stay green.
- [X] T061 [US3] Enable the `ExtractGuestMemoriesJob` case in the jobs dataset of tests/Feature/AiActivityCoverageTest.php (T030).

**Checkpoint**: US3 tests pass. A returning guest's Concierge turn carries their memories.

---

## Phase 6: User Story 4 — An admin reviews and removes what the AI remembers (Priority: P2)

**Goal**: Admins (or role-granted employees) list and delete a guest's memories, toggle the
hotel switch, and see guest deletion remove memories. Super admins can re-run final
extraction failures.

**Independent Test**:
- create memories for guests of two hotels;
- list (logged) and delete one as hotel A's admin;
- hotel B gets 404; permissions give 403;
- deleting the guest removes the rest;
- toggle the switch;
- re-run a failed extraction as a super admin.

### Tests for User Story 4 ⚠️

- [X] T062 [P] [US4] Write tests/Feature/GuestMemoryApiTest.php (spec US4 1–6, FR-019–FR-021, FR-029, FR-032):
  - **list**: shape per contracts/guest-memories-api.md, `extracted_at` desc, `?category=` filter, `guest_memories_enabled` flag in the body;
  - **access event**: each list call writes one `guest.memories_viewed` event with no content;
  - **delete**: removes the row, writes `guest_memory.deleted` without content, and a memory of a different guest via the URL → 404;
  - **no create/update routes**: `POST`/`PUT` → 405;
  - **guest delete**: soft-deleting the guest via `DELETE /api/guest/{id}` removes all of its memories, and restoring (if the route exists) does not bring them back;
  - **switch**: `PUT /api/hotel/{hotel}` `guest_memories_enabled=false` as an admin → 200, a `hotel.guest_memories_toggled` event, and `HotelResource` shows `false`; as an employee → 403, like other hotel settings.
- [X] T063 [P] [US4] Add memory isolation cases to tests/Feature/TenantIsolationTest.php (hotel B lists/deletes A's guest memories → 404). Add `GET /api/guest/{guest}/memories` (`guest_memories.view`) and `DELETE /api/guest/{guest}/memories/{memory}` (`guest_memories.delete`) to the tests/Feature/PermissionAuthorizationTest.php dataset.
- [X] T064 [P] [US4] Write tests/Feature/Admin/GuestMemoryExtractionAdminTest.php:
  - a super admin lists extractions filtered by `hotel_id`/`status`;
  - re-run of `failed`/`skipped_spend_ceiling` → 202, status `pending_retry`, `attempts` = 1; a failing re-run ends `failed` with no further retry, job dispatched;
  - re-run of `extracted`/`skipped_no_messages`/`skipped_disabled` → 422;
  - a hotel admin → 403 (`super_admin` guard).

### Implementation for User Story 4

- [X] T065 [P] [US4] Create app/Policies/GuestMemoryPolicy.php with `ChecksPermissions` (super admin `before()`):
  - `viewAny(User, Guest)` → `allows($user, Permission::GUEST_MEMORIES_VIEW, $guest)`;
  - `delete(User, GuestMemory)` → `allows($user, Permission::GUEST_MEMORIES_DELETE, $memory)`;
  - `create`/`update` → `false`.

  Register it the way existing policies are registered.
- [X] T066 [P] [US4] Create app/Http/Requests/GuestMemoryIndexRequest.php (`category` `Rule::enum(GuestMemoryCategory::class)` nullable, `per_page` 1–50) and app/Http/Resources/GuestMemoryResource.php (`id`, `category`, `content`, `language`, `confidence`, `source_stay {id, arrival_date, departure_date}`, `extracted_at`).
- [X] T067 [US4] Create app/Http/Controllers/GuestMemoryController.php:
  - **`index(GuestMemoryIndexRequest, Guest $guest)`**: authorize; `EventLogger::record($guest, 'memories_viewed')`; return the paginated memories plus `guest_memories_enabled`.
  - **`destroy(Guest $guest, GuestMemory $memory)`**: `abort_unless($memory->guest_id === $guest->id, 404)`; authorize; `$memory->delete()`; `apiResponse('Guest memory deleted successfully.', 200, null)`.

  Register `GET /guest/{guest}/memories` and `DELETE /guest/{guest}/memories/{memory}` in routes/api.php next to the guest routes.
- [X] T068 [US4] Extend the hotel update in app/Http/Controllers/HotelController.php and its request (find the request class used by `update`):
  - validate `guest_memories_enabled` as `sometimes|boolean`;
  - when it changes, call `EventLogger::record($hotel, 'guest_memories_toggled', ['guest_memories_enabled' => ['from' => …, 'to' => …]])`;
  - add `guest_memories_enabled` to app/Http/Resources/HotelResource.php.
- [X] T069 [US4] Create app/Http/Controllers/Admin/GuestMemoryExtractionController.php with:
  - **`index`**: filters `hotel_id` and `status`; newest first; paged; app/Http/Resources/GuestMemoryExtractionResource.php;
  - **`rerun(GuestMemoryExtraction $extraction)`**: 422 unless the status is `failed` or `skipped_spend_ceiling`. Otherwise set `attempts = 1`, `status = pending_retry` and `retry_at = now()`, dispatch `ExtractGuestMemoriesJob` (the job's normal claim path picks it up), and return 202 with the new status. With `attempts = 1`, the job claim makes it attempt 2, so a re-run that fails again is final immediately and gets no further automatic round (FR-036).

  The model is tenant-scoped, so resolve it with `withoutScope` deliberately: the super-admin route is cross-hotel by design (add a comment). Register both routes in routes/admin.php, with no own group (CLAUDE.md).

**Checkpoint**: all four stories pass independently.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T070 [P] Write docs/conversations-api-documentation.md from contracts/conversations-api.md, in the format of the existing docs (headers, who can call, params, examples, errors).
- [X] T071 [P] Write docs/guest-memories-api-documentation.md from contracts/guest-memories-api.md, including the hotel switch and the super-admin extraction routes.
- [X] T072 [P] Write docs/ai-activity-api-documentation.md from contracts/ai-activity-api.md (categories, filters, cost visibility).
- [X] T073 [P] Update docs/history-api-documentation.md (new types, deleted-memory history, `context.ai.run_id`, guest actor for Concierge writes), docs/hotel-api-documentation.md (`guest_memories_enabled`) and docs/guest-api-documentation.md (memories link).
- [X] T074 [P] Update docs/staff-roles-api-documentation.md: add `conversations.view`, `guest_memories.view` and `guest_memories.delete` to the permission reference, with their endpoints, and note they are not employee defaults.
- [X] T075 [P] Update docs/ai-advisor-chat-api-documentation.md: the Admin AI can no longer read guest messages (FR-033).
- [X] T076 Write docs/latest-changes-2026-10-10.md, or append to it if it exists:
  - **breaking**: the Admin AI guest-messages tool is removed;
  - **new**: the endpoints, the 3 permissions, the `hotel.guest_memories_enabled` default true;
  - AI audit context now includes `run_id`;
  - Concierge-write audit actor is now the guest.
- [X] T077 [P] Write specs/011-conversations-memories-audit/frontend-changes.md for `ecosystem-frontend` (D13):
  - conversation viewer (list with guest/date filters, thread view with proactive badge);
  - guest page memory panel (category filter, delete, switch-off notice) and a "Conversations" tab;
  - AI activity screen (category tabs, filters, link to record history, cost column only for super admin);
  - hotel settings toggle;
  - role editor picks up the 3 permissions automatically via `GET /api/permissions`.
- [X] T078 Run `./vendor/bin/pint` and the full `php artisan test`. Fix failures and record the results.
- [ ] T079 Check the plan's performance goals (plan.md, Performance Goals). Write a throwaway seeder under the scratchpad, not committed, that loads on the testing DB:
  - one hotel with 10k guest conversations (avg 20 messages);
  - 1M `ai_activities` rows over 3 hotels.

  Run `EXPLAIN ANALYZE` on the queries behind:
  - `GET /api/conversations` (first page, and filtered by guest);
  - `GET /api/conversations/{id}` (page 1);
  - `GET /api/ai-activity` (no filter, `category=data_change`, `conversation_id=…`).

  Confirm the indexes from data-model.md are used and the targets hold (list < 300 ms, show < 200 ms, AI activity < 300 ms). Add a missing index in a new migration if one is needed. Record the timings in quickstart.md under "Performance check".
- [ ] T080 Walk through specs/011-conversations-memories-audit/quickstart.md manual steps 1–7 on the dev DB, verifying with the dev DB name explicitly (memory: no `.env.testing`). Update quickstart.md with any deviation found.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: no dependencies.
- **Foundational (Phase 2)**: depends on Setup and **blocks all stories**.
- **US1 (Phase 3)**: depends only on Foundational.
- **US2 (Phase 4)**: depends only on Foundational. It touches `GuardedTool`/`AdminToolset`, which US1's T025 also edits. Do T025 first, or coordinate.
- **US3 (Phase 5)**: depends on Foundational. Its audit assertions (T052) need US2's recorder and run. If US3 is built before US2, skip those assertions and enable them after US2.
- **US4 (Phase 6)**: depends only on Foundational (table and model). The re-run endpoint (T069) needs US3's job.
- **Polish (Phase 7)**: after the stories it documents.

### Within each story

Tests first (they fail), then models/services, then controllers/routes, then agent wiring.

### Key task dependencies

- T036 (AiRun) → T037 (AiCostContext) → T038 (call sites), T039 (usage stamp).
- T040 (EventLogger capture) → T041 (AiActionRecorder) → T042 (GuardedTool), T043 (AuditedTool) → T044 (agents).
- T034 (markers) → T030 (coverage test can pass).
- T055 (GuestMemoryService) → T056 (job) → T057 (retry), T058 (Stay hook), T059 (Concierge).
- T049 (resource) needs the HistorySubjects extraction, which T051 also uses. Do the extraction once in T049.

## Parallel Example: Foundational

```text
T003 enums  |  T004 permissions  |  T009 AiActivity model  |  T010 GuestMemory model  |  T011 extraction model
(after T005–T008 migrations are written; T014 runs last)
```

## Parallel Example: User Story 2

```text
Tests: T028 | T029 | T030 | T031 | T032 | T033
Then:  T034 markers | T048 request | T049 resource      (independent files)
Chain: T035 → T036 → T037 → T038/T039 ; T040 → T041 → T042/T043 → T044–T046 ; T047 ; T050 ; T051
```

## Parallel Example: User Story 4

```text
Tests: T062 | T063 | T064
Then:  T065 policy | T066 request+resource  →  T067 controller → T068 hotel switch → T069 super-admin routes
```

## Implementation Strategy

### MVP first (US1)

1. Phase 1 + Phase 2.
2. Phase 3 (US1): the conversation viewer, and the Admin AI stops leaking guest text.
3. **Stop and validate** with T015–T018. Deployable on its own.

### Incremental delivery

1. **US2 (AI audit)**: the second P1. Ship it next; it makes every later AI feature
   traceable.
2. **US4 (memory panel)**: safe to ship before extraction. The panel is empty until US3,
   which lets the frontend build against it.
3. **US3 (extraction and Concierge use)**: switches the guest-facing behavior on (switch
   default true).
4. **Polish**: docs, frontend notes, full suite, quickstart walk-through.

### Notes

- Every `[P]` task touches a different file from the other `[P]` tasks in its group.
- Commit after each task or logical group. Run Pint before every commit.
- Never use `withoutScope`/`runForHotel` to make an isolation test pass. The only
  deliberate cross-hotel uses are T057 and T069, each with a comment.

---

## Implementation notes (2026-10-10)

- **Retries (T056)**: a round makes three quick attempts in-process (`retry()`), not three
  queue tries. A queue retry could not re-claim a row already marked `running`. The
  behaviour matches FR-036: three quick attempts, one more round about 24 h later, then
  final. A new extraction row is inserted as `pending_retry` due now, so every row always
  has a valid status and one claim path serves new rows, retries and super-admin re-runs.
- **Super-admin re-run (T069)**: sets `attempts = 1`, so the re-run is the last round
  (spec FR-036, analysis I4).
- **Write detection (T040, T041)**: a capture counts audited events, Eloquent model
  events, and writes declared with `EventLogger::notice()`. `PitchActivityTool` stages its
  offer with conditional bulk updates, which fire no model events, so it declares the
  write itself.
- **`AiActivity` back-fill (T009, T038)**: the model refuses every update. The run's
  conversation back-fill is a bulk query, and deliberately bypasses the guard (analysis
  re-run I1).
- **Stays without a reservation** (legacy rows) are never extracted. The `Stay` hook
  requires `reservation_id`.
- **History ordering**: ties on `occurred_at` are now broken by id (UUIDv7) in
  `HistoryController`.
- **Test helpers**: `toolOfClass()` and `toolClasses()` in tests/Pest.php find a tool
  whether or not it is wrapped. Five existing tests were updated to use them.
- **T079 (performance) not completed**: seeding 10k conversations (200k messages) into
  the testing DB crashed the local Postgres container, and then Docker Desktop stopped.
  No timings were recorded. Re-run with a smaller batch, or with more memory given to
  Docker/WSL. The script is in the session scratchpad (`perf.php`); it refuses to run
  against any DB but `Hospitality_Ecosystem_testing`.
- **T080 (manual walk-through) not done**: it needs Docker and the dev server.
- **Dev DB**: the dev database (`Hospitality_Ecosystem`) already has this phase's
  migrations, applied in batch 2 together with Phase 10's. That was not done by the
  implementation steps; the data was intact when checked.
