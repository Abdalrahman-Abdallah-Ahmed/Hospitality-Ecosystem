# Quickstart: Validating Conversation Visibility, Guest Memories, and AI Audit

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d   # Postgres + pgvector (required)
php artisan migrate                              # dev DB
php artisan test                                 # tests use Hospitality_Ecosystem_testing
```

The tests fake every AI agent (`GuestConciergeAgent::fake()`, `GuestMemoryAgent::fake()`,
…), so no provider key is needed.

## Automated validation (primary)

```bash
php artisan test --filter=ConversationViewerTest        # US1: list/show, filters, paging, access event, 403/404
php artisan test --filter=AdminGuestMessagesRemovalTest # US1 #9 / FR-033
php artisan test --filter=AiActivityCoverageTest        # US2: every write tool wrapped + one complete row each
php artisan test --filter=AiActivityRecorderTest        # succeeded / refused / failed / rollback / permission
php artisan test --filter=AiRunTest                     # run row per turn/job, usage sums, ceiling = refused
php artisan test --filter=AiActivityApiTest             # list filters, categories, cost only for super admin
php artisan test --filter=GuestMemoryExtractionTest     # US3: trigger, idempotency, skips, guards, cap, retry
php artisan test --filter=ConciergeMemoryTest           # US3: memories in instructions, switch, cross-hotel, FR-037
php artisan test --filter=GuestMemoryApiTest            # US4: list/delete, access event, cascade on guest delete
php artisan test --filter=HistoryControllerTest         # new types incl. deleted guest-memory history
php artisan test --filter=TenantIsolationTest           # new isolation cases (conversations, memories, ai activity)
php artisan test --filter=PermissionAuthorizationTest   # 3 new permissions: allowed with, 403 without
./vendor/bin/pint --test
```

Expected: all green. `AiActivityCoverageTest` should fail if you temporarily register a
new `WritesData` tool unwrapped, or add a tool class with no marker interface.

## Manual walk-through (dev DB, real agents optional)

Use an admin token for hotel A (`X-API-KEY` + Bearer) and a seeded guest who has a
WhatsApp conversation.

1. **Conversation viewer**
   - `GET /api/conversations?guest_id={guest}` → one entry with the message count and
     last activity.
   - `GET /api/conversations/{id}` → the guest and Concierge messages in order, with
     proactive ones flagged, and no tool payloads.
   - `GET /api/history/guest/{guest}` → the latest entry is `guest.conversation_viewed`.
   - As an employee whose role lacks `conversations.view` → `403`.
   - As an admin of hotel B → `404`.
2. **Admin AI cannot show messages**: ask the advisor "what did guests write today?". The
   reply points to the conversation viewer and no guest text is returned.
3. **AI activity**
   - Send a guest WhatsApp message that leads to a booking.
   - `GET /api/ai-activity?conversation_id={id}` → an `ai_run` row (with usage and no
     cost), a `data_change` row `booking.created`, and a `guest_outreach` row if a pitch
     was made, all with the same `ai_run_id`.
   - Repeat as a super admin with `hotel_id` → `cost_usd` appears on the run row.
4. **Memory extraction**
   - Check out the guest's stay (`POST` check-out endpoint) and run the queue worker.
   - `GET /api/guest/{guest}/memories` → memories with category, language, confidence and
     source stay.
   - `GET /api/admin/guest-memory-extractions?hotel_id=…` (super admin) → `extracted`.
   - Check out again or re-dispatch the job → no duplicates.
5. **Memory use and requests**
   - The guest writes again. The Concierge's instructions include the memory section; the
     test asserts on the rendered instructions.
   - The guest writes "please forget that I'm vegetarian" → an escalation task titled
     "Memory request — forget: …".
6. **Switch**
   - `PUT /api/hotel/{hotel}` `{ "guest_memories_enabled": false }` → the next check-out
     gives extraction `skipped_disabled`, and the Concierge instructions have no memory
     section.
7. **Deletion**
   - `DELETE /api/guest/{guest}/memories/{memory}` → gone. The history at
     `/api/history/guest-memory/{memory}` still shows `deleted`, with no content.
   - `DELETE /api/guest/{guest}` → all of the guest's memories are gone.
     `POST /api/guest/{guest}/restore`, if available, does not bring them back.

## Done when

- Every spec acceptance scenario maps to a passing test (see tasks.md).
- The docs are updated:
  - new: `conversations-api-documentation.md`, `guest-memories-api-documentation.md`,
    `ai-activity-api-documentation.md`;
  - updated: `history-api-documentation.md`, `hotel-api-documentation.md`,
    `ai-advisor-chat-api-documentation.md`, `staff-roles-api-documentation.md`
    (3 permissions), `latest-changes-<date>.md` (breaking: Admin AI guest-messages tool
    removed).
- The frontend notes are written in `frontend-changes.md`.
