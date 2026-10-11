# Feature Specification: Conversation Visibility, Guest Memories, and AI Audit

**Feature Branch**: `011-conversations-memories-audit`

**Created**: 2026-10-10

**Status**: Draft

**Input**: Phase 11 — Conversation Visibility, Guest Memories, AI Audit, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's three backlog
specs as one feature: SPEC-082 Conversation Visibility, SPEC-084 Guest Memory Extraction &
Retrieval, and SPEC-083 AI Audit Consistency. Follows plan decision D15 (conversations stay
in the existing conversation store; search is post-MVP).

## Overview

The WhatsApp Concierge talks to guests every day, and every conversation is already kept.
But hotel administrators cannot read them: there is no way to open a guest's conversation
and see what the AI told them. Each time a guest returns, the Concierge also starts from
nothing — it does not remember that the guest is vegetarian, prefers a quiet room, or told
it last time how much they loved the desert safari. And while many AI actions are already
recorded in the audit trail, not all are recorded the same way: some say which agent, tool
and conversation acted, some only say "an AI did this", and there is no check that a newly
added AI action is audited at all.

This feature:

- **Conversation visibility.** Hotel administrators can list their hotel's guest
  conversations and read one in full — guest messages, Concierge replies, and proactive
  messages — read-only. Nobody else sees them unless explicitly allowed, and no one sees
  another hotel's conversations. There is no conversation search in this phase.
- **Guest memories.** After a stay is checked out, the system extracts a few durable facts
  about the guest from that stay's conversation (preferences, communication preferences,
  recurring interests) and keeps them as structured, hotel-scoped *guest memories*. The
  Concierge uses relevant memories in later conversations with that guest. Administrators
  can see and delete them. Memories are hints, never facts that override the PMS.
- **AI audit consistency.** Every action an AI agent takes that changes data is recorded in
  the audit trail the same way: who it acted for, which agent and tool, what record, what
  action, which hotel, which conversation, and whether it succeeded. All AI activity — data
  changes, guest outreach, and AI runs — is readable in one categorized *AI activity* list.
  An automated check fails when a new AI write action is added without auditing.

**Terms**:

- *Conversation*: the stored exchange between one guest and the Concierge — guest messages,
  Concierge replies, and proactive messages — kept in the existing conversation store.
- *Conversation viewer*: a read-only view of a hotel's guest conversations.
- *Guest memory*: one durable, structured fact about a guest at one hotel, with a category,
  short content, the stay it came from, a confidence level, and when it was extracted.
- *Memory category*: one of *preference* (room, food, amenities), *communication*
  (language, channel, tone, times), *interest* (activities, experiences), or *other durable*
  (e.g. "travels with a wheelchair user").
- *Extraction*: the one-time, AI-driven pass that reads a checked-out stay's conversation and
  produces that stay's guest memories.
- *AI write action*: any operation an AI agent performs that creates, changes, or deletes
  hotel data — through a Concierge tool, an Admin AI tool, or an AI background job.
- *AI audit entry*: the audit-trail record of one AI write action.

## Clarifications

### Session 2026-10-10

- Q: May a hotel let specific employees view conversations and memories through their
  staff role? → A: Yes. New explicit permissions — view conversations, view guest
  memories, delete guest memories — held by admins, grantable through a staff role, never in
  the defaults for employees without a role (FR-004, FR-019).
- Q: Should guest memories be a per-hotel switch, and what is the default? → A: Per-hotel
  switch, on by default. Off stops extraction and Concierge use; existing memories are kept
  (still viewable and deletable) until deleted (FR-032).
- Q: Should memories be extracted from stays checked out before release? → A: No. Forward
  only: only check-outs after release are extracted; no backfill (FR-010).
- Q: Should reads of conversations and memories be audited? → A: Log opening a single
  conversation and listing one guest's memories (who, when, which guest); the general
  conversation list is not logged (FR-029).
- Q: In which language are memories stored? → A: The language the guest used in that
  conversation; each memory records its language (FR-009, FR-013).
- Q: What must the Admin AI require before showing guest message text? → A: It must never
  show it. Guest messages are available only through the dashboard conversation viewer; the
  Admin AI's guest-messages tool is removed, for every user including admins. Background
  agents (Insights, Recommendation) that read messages without showing them are unchanged
  (FR-033).
- Q: How should admins read what the AI did? → A: A new hotel-wide AI activity list of all
  AI audit entries, newest first, filterable by agent, tool, result, conversation, person
  acted for, record type and date range, under the existing history permission; plus the
  missing record types added to the per-record history (FR-025, FR-034).
- Q: Should the AI's bookkeeping (pitch decisions, proactive messages, usage) also be in the
  AI activity list? → A: Yes. All AI activity is in one place, categorized: *data change*,
  *guest outreach* (pitches and their outcomes, proactive messages sent or skipped), and
  *AI run* (each agent turn or AI job, with its usage). Provider cost is shown only to super
  admins (FR-022, FR-025, FR-035).
- Q: Should a memory extraction skipped by the spend ceiling or failed after retries be
  tried again? → A: Once, automatically, about 24 hours later; if still blocked or failing
  it is marked final. A super admin can re-run a final failure by hand (FR-011, FR-036).
- Q: What does the Concierge do when a guest asks what it remembers or asks it to forget
  something? → A: It tells the guest the request is passed to the hotel and escalates to
  staff naming the request; for "forget X" it stops using that memory in the current
  conversation; an admin deletes it. The Concierge never lists or deletes memories itself
  (FR-037).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An admin reads what the Concierge told a guest (Priority: P1)

A guest complains at the front desk that "the WhatsApp assistant said breakfast was
included". The hotel admin opens the conversation list, filters to that guest, opens the
conversation, and reads exactly what the guest asked and what the Concierge answered, in
order, with timestamps — including a reminder the Concierge sent on its own the day before.

**Why this priority**: The hotel is responsible for what its AI says to guests. Without a
way to read conversations, staff cannot resolve complaints, check AI quality, or notice
problems. It is also the smallest independent slice: it reads data that already exists.

**Independent Test**: Seed guest conversations for two hotels; as an admin of hotel A, list
and open conversations; confirm only hotel A's guests appear, messages are complete and in
order, nothing can be changed, and an employee without access is refused.

**Acceptance Scenarios**:

1. **Given** a hotel with guest conversations, **When** its admin lists conversations,
   **Then** they see one entry per conversation with the guest's name and phone, the
   number of messages, and when it started and was last active, newest activity first,
   paginated.
2. **Given** the conversation list, **When** the admin filters by guest or by a
   last-active date range, **Then** only matching conversations are listed.
3. **Given** a conversation, **When** the admin opens it, **Then** they see every guest
   message, Concierge reply, and proactive message in chronological order, each with its
   sender (guest / Concierge), time, and whether it was proactive; attachments are listed
   by type and name.
4. **Given** a guest with several conversations, **When** the admin opens the guest's
   record, **Then** they can list just that guest's conversations.
5. **Given** a conversation of another hotel's guest, **When** an admin of this hotel tries
   to list or open it, **Then** it is not listed and opening it is treated as not found.
6. **Given** a super admin, **When** they name a hotel, **Then** they can list and open that
   hotel's conversations.
7. **Given** an employee whose role does not grant *view conversations*, **When** they try to list or open
   conversations, **Then** the request is refused (forbidden).
8. **Given** any user, **When** they try to edit, delete, or post into a conversation
   through the viewer, **Then** no such operation exists.
9. **Given** any staff user, including an admin, **When** they ask the Admin AI to show
   what guests wrote, **Then** the Admin AI does not return message text and points them to
   the conversation viewer in the dashboard.

---

### User Story 2 - Every AI change is traceable without reading the chat (Priority: P1)

A room shows as checked in and nobody at reception did it. The admin opens the audit trail
for that stay and sees: the action was taken by the Admin AI, acting for the duty manager,
through the check-in tool, in a named conversation, at 22:14, and it succeeded. A booking
the WhatsApp Concierge created for a guest shows the same kind of entry, naming the
Concierge, the guest it acted for, and the conversation.

**Why this priority**: The constitution requires that what the AI *did* be traceable
separately from what it *said*. AI actions already change reservations, stays, bookings,
tasks and rooms; if any of them is unaudited or audited inconsistently, the hotel cannot
answer "who changed this?" — a security and accountability gap.

**Independent Test**: Run each AI write action once (Concierge, Admin AI, AI background
jobs) and confirm each produced an audit entry with all required fields; then add a write
action without auditing and confirm the automated coverage check fails.

**Acceptance Scenarios**:

1. **Given** any AI write action that succeeds, **When** it completes, **Then** the audit
   trail holds an entry naming: the AI as actor kind, the person it acted for (the staff
   user, or the guest for the Concierge, or none for a scheduled job), the agent, the tool
   or job, the target record, the action, the hotel, the conversation (when there is one),
   and the result *succeeded*.
2. **Given** an AI write action that is refused (permission, validation, or business rule)
   or fails, **When** it ends, **Then** an audit entry records the attempt with the result
   *refused* or *failed* and a short reason, and no partial change is left behind.
3. **Given** an AI audit entry, **When** an admin reads it, **Then** it contains no
   conversation text — only identifiers and the fields above — and the conversation, if
   needed, is opened separately through the conversation viewer.
4. **Given** the full set of AI write actions in the system, **When** the automated
   coverage check runs, **Then** it lists every one and confirms each records an AI audit
   entry; a write action added later without auditing makes the check fail.
5. **Given** a human performs the same operation through the normal staff screens,
   **When** the audit trail is read, **Then** the human and AI entries are distinguishable
   by actor kind and the AI entry carries the extra AI fields.
6. **Given** AI write actions happened today across bookings, stays and tasks, **When** an
   admin opens the AI activity list filtered to today, **Then** they see every one of them,
   newest first, can narrow by agent, result or conversation, and can open each target
   record's full history.
7. **Given** one Concierge turn that pitched an activity and created a booking, **When** the
   admin filters the AI activity list to that conversation, **Then** they see the AI run
   (with its usage), the pitch (*guest outreach*), and the booking (*data change*), each
   categorized and linked to the run; no provider cost is shown to the hotel admin.
8. **Given** AI activity in two hotels, **When** an admin of one hotel opens the AI
   activity list, **Then** only their hotel's entries appear; an employee without the
   history permission is refused (forbidden).

---

### User Story 3 - The Concierge remembers a returning guest (Priority: P2)

On her first stay, a guest tells the Concierge she is vegetarian, asks for a quiet room
away from the lift, and books the sunset camel ride. A day after check-out, the system
extracts three memories from that conversation. Four months later she books again; when she
writes "hi, what's good for dinner?", the Concierge suggests the vegetarian tasting menu and
asks whether she would like the camel ride again.

**Why this priority**: This is the guest-facing value of the phase, but it depends on
conversations being reliable and visible (Story 1) and on the AI being accountable
(Story 2). The hotel can run without it.

**Independent Test**: Give a guest a checked-out stay with a conversation containing clear
preferences; run extraction; confirm the expected memories exist with category, source stay
and confidence; start a new conversation and confirm the Concierge receives those memories
and not other guests' or other hotels' memories.

**Acceptance Scenarios**:

1. **Given** a stay is checked out and its guest had a conversation during the stay,
   **When** extraction runs, **Then** durable facts from that conversation are saved as
   guest memories, each with category, content, source stay, confidence, and extraction
   time, scoped to the stay's hotel.
2. **Given** a stay already extracted, **When** extraction is triggered again (retry,
   re-checkout, duplicate event), **Then** no duplicate memories are created.
3. **Given** a stay with no guest messages, **When** extraction would run, **Then** it is
   skipped and nothing is called or created.
4. **Given** a conversation that contains only temporary requests ("towels to room 204",
   "what time is it?") or nothing durable, **When** extraction runs, **Then** no memories
   are created.
5. **Given** a new fact repeats or confirms an existing memory, **When** extraction runs,
   **Then** the existing memory is refreshed (newer source stay, confidence kept or raised)
   instead of duplicated; a fact that contradicts an older memory replaces it.
6. **Given** a guest with memories writes to the Concierge, **When** the Concierge
   answers, **Then** it has the guest's relevant memories available and may use them to
   personalize the answer.
7. **Given** a memory that disagrees with PMS data (memory: "prefers sea-view room";
   reservation: garden-view room), **When** the Concierge answers about the reservation,
   **Then** it states the PMS data and does not present the memory as the booking.
8. **Given** a guest of hotel A who is also a guest of hotel B in the same group, **When**
   the Concierge talks to them for hotel B, **Then** it sees only hotel B's memories.
9. **Given** the Concierge's answer, **When** it uses a memory, **Then** it does so
   naturally and never recites the stored memory list or confidence values to the guest.
10. **Given** a guest with a "vegetarian" memory writes "please forget that I'm
    vegetarian", **When** the Concierge answers, **Then** it says the hotel will take care
    of it, raises a human escalation naming the forget request, and no longer uses that
    memory in the conversation; the memory stays until an admin deletes it.
11. **Given** a guest asks "what do you know about me?", **When** the Concierge answers,
    **Then** it does not list memories, says the request is passed to the hotel, and raises
    a human escalation naming a *see memories* request.

---

### User Story 4 - An admin reviews and removes what the AI remembers (Priority: P2)

Before a VIP arrival, the hotel admin opens the guest's page and sees the memory panel:
"Prefers feather-free pillows (preference, high confidence, from stay in June)". One memory
reads "Celebrating divorce" — the admin deletes it as inappropriate to keep. Separately, a
guest asks the hotel to erase their data; when the guest record is deleted, their memories
go with it.

**Why this priority**: Memories are protected guest data. The hotel must be able to see
what the AI keeps and remove it; without that, Story 3 cannot ship responsibly.

**Independent Test**: Create memories for guests of two hotels; as an admin, list a guest's
memories, delete one, confirm it is gone for the Concierge too, confirm another hotel's
memories are invisible, and confirm deleting the guest removes their memories.

**Acceptance Scenarios**:

1. **Given** a guest with memories, **When** an admin opens the guest's memories, **Then**
   they see each memory's category, content, language, confidence, source stay (with dates), and when
   it was extracted, newest first, filterable by category.
2. **Given** a memory, **When** an admin deletes it, **Then** it is permanently removed, the
   Concierge no longer receives it, and the deletion is audited without storing the
   memory's content in the audit entry.
3. **Given** a guest with memories, **When** the guest record is deleted, **Then** all of
   that guest's memories are permanently removed in the same operation, and restoring the
   guest later does not bring them back.
4. **Given** a memory of another hotel's guest, **When** an admin of this hotel tries to see
   or delete it, **Then** it is treated as not found.
5. **Given** an employee without the matching memory permission, **When** they try to view or delete memories,
   **Then** the request is refused (forbidden).
6. **Given** any user, **When** they try to create or edit a memory by hand, **Then** no
   such operation exists; memories come only from extraction.

---

### Edge Cases

- **Conversation without a resolvable guest** (guest soft-deleted, or participant missing):
  it is not shown to anyone, super admins included, because the guest's data was deleted.
  The conversation itself is retained (constitution: conversations kept indefinitely).
- **Guest at several hotels on the shared WhatsApp number**: each hotel's guest record is
  separate; a hotel's admin sees only conversations whose participant is that hotel's
  guest record.
- **Very long conversations**: the viewer pages through messages instead of returning
  thousands at once.
- **Messages with tool calls or internal metadata**: the viewer shows what the guest and the
  Concierge said; internal tool payloads and model usage figures are not shown.
- **Multi-room reservation with several stays for the same guest**: extraction runs once
  per guest per reservation, after the last of that guest's stays on the reservation is
  checked out, so the same conversation is not read several times.
- **Stay checked out, then the check-out is undone and redone**: the duplicate-prevention
  rule applies; memories are not extracted twice.
- **Extraction fails** (AI provider error, timeout): it is retried a few times right away;
  if it still fails, one more attempt runs about 24 hours later (FR-036). If that fails it
  is marked *failed — final*, visible in the AI activity list.
- **Extraction during hotel's AI spend ceiling**: it is skipped and recorded, and gets the
  same single attempt about 24 hours later; still blocked → *skipped — spend ceiling*,
  final.
- **Sensitive content in conversations** (payment card numbers, passport numbers, health
  details beyond accessibility needs): extraction never stores them as memories.
- **Guest opted out of proactive messages**: extraction and memory use are not affected;
  opt-out concerns outbound messages only.
- **Guest memories switched off for the hotel**: a check-out produces no extraction (recorded
  as skipped), and the Concierge answers without memories even if some exist.
- **Guest switches language between stays** (Arabic first stay, English second): an
  equivalent fact is merged, not duplicated; the memory is rewritten in the newer language.
  The Concierge uses memories in either language and always answers in the guest's current
  language.
- **Low-confidence facts**: facts below the minimum confidence are not stored.
- **Too many memories**: a guest keeps a bounded number of memories per hotel; when the
  limit is reached, the oldest lowest-confidence ones are replaced first.
- **AI write action refused before it starts** (missing permission): still audited as
  *refused* (FR-022).
- **AI write action inside a larger transaction that rolls back**: the audit entry must not
  claim *succeeded* for a change that was rolled back.
- **Read-only AI tools**: individual reads are not listed as entries; they are covered by
  the *AI run* entry of the turn that made them.
- **High-volume hotels**: AI runs make the list large; the default view is newest first and
  paged, and filtering by category hides runs when only changes matter.

## Requirements *(mandatory)*

### Functional Requirements

**Conversation visibility (SPEC-082)**

- **FR-001**: The system MUST provide a read-only list of a hotel's guest conversations
  showing guest name and phone, message count, first and last message time, newest activity
  first, paginated.
- **FR-002**: The list MUST support filtering by guest and by last-activity date range. Free
  text search across conversations MUST NOT be offered in this phase.
- **FR-003**: The system MUST provide a read-only view of one conversation listing its
  messages in chronological order, each with sender (guest or Concierge), time, text,
  whether it was proactive, and attachment type/name; long conversations MUST be paged.
- **FR-004**: Access to conversations MUST be governed by a new explicit permission,
  *view conversations*. Hotel Admins (their hotel) and Super Admins (a named hotel) hold it;
  an employee holds it only when their staff role grants it. Employees without a role MUST
  NOT receive it by default.
- **FR-005**: Conversations MUST be limited to those whose guest belongs to the viewer's
  hotel; another hotel's conversation MUST behave as not found.
- **FR-006**: The viewer MUST NOT expose operations that edit, delete, or add messages, and
  MUST NOT show internal tool payloads, model usage, or cost data.
- **FR-007**: A guest's conversations MUST also be listable from that guest's record.
- **FR-008**: Admin Advisor (staff ↔ AI) chats are out of scope for this viewer; it shows
  guest conversations only.
- **FR-033**: Guest message text MUST be available only through the conversation viewer in
  the dashboard. The Admin AI MUST NOT return guest message text (quoted or paraphrased
  message by message) in any reply, for any user including admins and super admins; its
  existing guest-messages tool is removed. Background agents that read guest messages to
  produce insights or recommendations keep doing so, and their outputs MUST NOT quote guest
  messages verbatim.

**Guest memories (SPEC-084)**

- **FR-009**: The system MUST store guest memories as structured, hotel-scoped records with:
  guest, category (*preference*, *communication*, *interest*, *other durable*), content (one
  short sentence, written in the language the guest used in the source conversation),
  language (English or Arabic), source stay, confidence (*medium* or *high*; *low* facts are
  discarded, FR-013), and
  extraction time.
- **FR-010**: After a guest's stay is checked out, the system MUST extract memories once, in
  the background, from that guest's conversation messages sent between the stay's arrival
  and check-out, as defined in Edge Cases for multi-room reservations. Only check-outs that
  happen after this feature is released are extracted; stays checked out earlier are never
  extracted (no backfill).
- **FR-011**: Extraction MUST be idempotent: retries or repeated triggers for the same
  guest and reservation MUST NOT create duplicate memories.
- **FR-036**: An extraction that failed after its immediate retries, or was skipped by the
  spend ceiling, MUST get exactly one more automatic attempt about 24 hours later. If that
  attempt also fails or is blocked, the result is final and no further automatic attempt
  runs. A super admin MAY re-run a final failed or ceiling-skipped extraction by hand (one attempt, no further automatic retry);
  *skipped — no messages* and *skipped — disabled* are final and not re-runnable.
- **FR-012**: Extraction MUST keep only durable facts (preferences, communication
  preferences, recurring interests, lasting needs) and MUST NOT store temporary requests,
  operational facts already in the PMS (dates, room numbers, prices, booking statuses),
  payment data, identity-document numbers, or sensitive health information beyond an
  accessibility need the guest stated.
- **FR-013**: Extraction MUST discard facts below medium confidence, merge a fact into an
  existing equivalent memory instead of duplicating it, and replace an existing memory the
  new fact contradicts — including when the existing memory is in the other language (the
  merged or replacing memory takes the newer conversation's language).
- **FR-014**: A guest MUST hold at most a bounded number of memories per hotel (default 20);
  over the limit, the oldest lowest-confidence memories are dropped first.
- **FR-015**: Extraction MUST be skipped without an AI call when the stay has no guest
  messages, and MUST respect the hotel's AI spend ceiling and usage metering like other AI
  work.
- **FR-016**: The Concierge MUST receive the guest's memories for the current hotel when
  answering that guest, and MUST NOT receive memories of other guests or other hotels.
- **FR-017**: The Concierge MUST treat memories as hints: when a memory and PMS data differ,
  PMS data wins, and the Concierge MUST NOT present a memory as a booking, status, or
  availability fact.
- **FR-018**: The Concierge MUST NOT recite stored memories, categories, or confidence
  values to the guest.
- **FR-037**: When a guest asks the Concierge what it remembers about them, or asks it to
  forget or correct something, the Concierge MUST tell the guest the request is being passed
  to the hotel and MUST raise a human escalation that names the request type (*see memories*
  or *forget/correct*) and, for a forget/correct request, what the guest asked to forget.
  For a forget/correct request it MUST stop using the matching memory for the rest of that
  conversation. The Concierge MUST NOT list memories to the guest or delete or change them;
  an admin acts on the escalation through the memory panel.
- **FR-019**: Admins MUST be able to list a guest's memories (filterable by category) and
  delete any single memory; deletion is permanent and audited without the memory content.
  Memory access uses two new explicit permissions, *view guest memories* and *delete guest
  memories*, held by Hotel Admins and Super Admins and grantable to employees only through a
  staff role, never by default.
- **FR-020**: Deleting a guest MUST permanently delete all of that guest's memories in the
  same operation; restoring the guest MUST NOT restore them.
- **FR-021**: Memories MUST NOT be created or edited by hand through the API; extraction is
  the only writer.
- **FR-032**: Each hotel MUST have a *guest memories* setting, on by default (including for
  existing hotels). While off, no extraction runs for that hotel's check-outs and the
  Concierge receives no memories; existing memories are kept and remain viewable and
  deletable. Changing the setting is admin-only and audited. Check-outs that happen while
  it is off are not extracted later when it is turned back on.

**AI audit consistency (SPEC-083)**

- **FR-022**: Every AI write action — every Concierge write tool, every Admin AI write tool,
  and every AI background job that writes hotel data (recommendation generation, insights,
  memory extraction) — MUST record an AI audit entry with: actor kind *AI*, the person acted
  for (staff user, guest, or none for scheduled jobs), agent, tool or job name, target record
  type and id, action, hotel, conversation id when one exists, and result (*succeeded*,
  *refused*, *failed*) with a short reason when not succeeded.
- **FR-023**: Refused and failed AI write attempts MUST be audited with no partial change
  left behind; a change rolled back MUST NOT be recorded as *succeeded*.
- **FR-024**: AI audit entries MUST NOT contain conversation text, prompts, model output, or
  memory content — only identifiers and the fields in FR-022.
- **FR-025**: The system MUST provide a hotel-wide, read-only *AI activity* list — the one
  place to see everything the AI did for the hotel — newest first, paginated, filterable by
  activity category (FR-035), agent, tool or job, result, conversation, person acted for
  (staff user or guest), target record type, and date range. Access uses the existing *view history* permission (admins hold it; employees
  through a staff role) and the hotel's tenant scope; a super admin names a hotel.
- **FR-034**: The per-record history MUST also cover every record type an AI write action
  can change, adding those missing today — at least activity bookings, rooms, guest service
  requests, contact preferences, and guest memories — so any AI entry in the AI activity
  list can be followed to its record's full history. A deleted guest memory's history
  remains readable without its content (FR-024).
- **FR-035**: Every AI activity entry MUST carry exactly one category:
  - *Data change*: an AI write action on hotel data (FR-022).
  - *Guest outreach*: a pitch offered to a guest and its outcome (accepted, declined,
    no answer), and a proactive message sent or skipped with its reason.
  - *AI run*: one agent turn or AI job run (Concierge reply, Admin AI reply, recommendation
    generation, insights, memory extraction), with agent, trigger, conversation, result, and
    usage (requests, tokens). Provider cost MUST be visible only to super admins, never to
    hotel users.
  Each data-change and guest-outreach entry MUST link to the AI run it happened in, when
  there is one (a proactive message is sent without an AI run and has no link), so an
  admin can see everything one turn did. Entries never contain conversation text, prompts,
  or model output (FR-024).
- **FR-026**: An automated check MUST enumerate every AI write action registered in the
  system — hotel data, pitch outcomes, proactive messages, and AI runs — and fail if any one
  of them does not produce a complete, categorized AI activity entry.
- **FR-027**: Existing audited behavior (entries already written by AI tools and jobs today)
  MUST keep working; this feature fills gaps and makes the fields uniform, it does not
  rewrite past entries.

**Cross-cutting**

- **FR-028**: Every new endpoint MUST enforce tenant isolation and have a test proving
  another hotel cannot see or change its data.
- **FR-029**: Opening a single conversation and listing one guest's memories MUST each record
  an access event in the audit trail (who, when, which guest, which conversation where
  applicable), with no message or memory content. Listing conversations in general (with or
  without filters) MUST NOT be logged.
- **FR-030**: API documentation MUST be updated for the conversation viewer, guest memories,
  the AI activity list, the new per-record history types, and the audit trail's AI fields; the permission reference MUST list the three new
  permissions (FR-004, FR-019).
- **FR-031**: Each new permission MUST have a test showing an employee is allowed with it
  (through a staff role) and refused (forbidden) without it.

### Key Entities *(include if feature involves data)*

- **Conversation** (existing): one guest's exchange with the Concierge; has a participant
  (the guest), a title, and timestamps. Read-only in this feature.
- **Conversation Message** (existing): one message in a conversation; has role (guest or
  Concierge), text, attachments, time, and a marker when proactive.
- **Guest Memory** (new): belongs to one hotel and one guest; has category, content,
  language, confidence, source stay, and extraction time. Deleted with its guest.
- **Memory Extraction Record** (new, or a marker on an existing record): notes that a
  guest's reservation has been extracted, when, the number of attempts, and the result
  (*pending retry*, *extracted*, *skipped — no messages*, *skipped — disabled*, *skipped —
  spend ceiling*, *failed*), so a completed extraction is never repeated and a blocked or
  failed one is retried at most once automatically (FR-036).
- **AI Audit Entry** (existing audit trail entry, extended): an audit entry with actor kind
  AI and the AI fields from FR-022. Listed hotel-wide in the AI activity list (FR-025) and
  per record in the record history (FR-034).
- **AI Activity Entry** (view over AI audit entries, pitch decisions, proactive messages,
  and AI runs): one categorized item in the AI activity list — *data change*, *guest
  outreach*, or *AI run* — linked to the run it belongs to.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An admin can find and open a given guest's conversation in under 30 seconds
  from the guest's record.
- **SC-002**: 100% of conversations shown to a hotel's admin belong to that hotel's guests,
  verified by isolation tests across at least two hotels.
- **SC-003**: 100% of AI write actions in the system — hotel data, pitch outcomes,
  proactive messages, and AI runs — are covered by the automated coverage check, and each
  produces a categorized entry with every required field.
- **SC-004**: An admin can answer "who changed this record, and was it the AI?" from the
  audit trail alone, without opening any conversation, for every AI change; and can answer
  "what did the AI change today?" from one list in under 1 minute.
- **SC-005**: Extraction runs at most once per guest per reservation; zero duplicate
  memories after repeated triggers.
- **SC-006**: Memories are available to the Concierge within 24 hours of check-out when
  extraction is not blocked, and within 48 hours when the single retry is needed.
- **SC-007**: In a review sample of extracted memories, at least 90% are judged durable and
  correct by hotel staff, and none contains payment data, identity-document numbers, or PMS
  operational facts.
- **SC-008**: A deleted memory or a deleted guest's memories are never used by the
  Concierge afterwards (0 occurrences in tests).

## Assumptions

- Conversations stay in the existing conversation store (D15); no new conversation storage,
  no search, no per-reservation linking (post-MVP).
- Conversations are tied to a hotel through their guest; the guest record is per hotel.
- Only guest ↔ Concierge conversations are in scope for the viewer. Admin Advisor chats
  keep their existing endpoints.
- Extraction reads the guest's conversation messages during the stay window only; bookings,
  service requests and other PMS records are not memory sources, because they are already
  structured data the Concierge reads through its tools.
- Memories have no automatic expiry in MVP; admins remove them, and guest deletion cascades.
- Memory confidence uses three levels rather than a number, so staff can read it directly.
- Memory extraction is a metered AI operation; its metering feature is added in Phase 12
  (plan Phase 12, item 3) if not added here.
- Guest requests about memories (see, forget, correct) and "forget me" requests are
  handled by staff through the existing human escalation (FR-037), not by the AI deleting
  data itself.
- The Admin AI and other agents do not gain new tools to read memories in this phase; the
  Concierge is the only agent that receives memories.
- Removing the Admin AI's guest-messages tool is a deliberate capability removal (FR-033),
  recorded as a change in the Admin AI documentation; the Insights and Recommendation
  agents keep their own read access to recent guest messages.
- The existing audit trail (actor kind, AI context) is reused and extended; it is not
  replaced.
- Some rules govern what the AI says rather than what the system does, and can only be
  enforced through the agents' instructions:
  - the Concierge stops using a memory the guest asked it to forget (FR-037);
  - background agents don't quote guest messages verbatim (FR-033).

  They are verified by tests that the instructions carry the rule, and by review during the
  quickstart walk-through, not by a guarantee on model output. Everything the system does —
  escalation, no deletion by the AI, no message text returned by the Admin AI's tools — is
  enforced in code.
- A super admin's manual re-run of a final extraction (FR-036) gets one attempt and no
  further automatic retry.
- Frontend work (conversation viewer, guest memory panel) happens in `ecosystem-frontend`
  and is described in this feature's frontend notes, per D13.
- Depends on Phase 4 (stays and check-out) and Phase 7 (Concierge on the new domain), both
  shipped.
