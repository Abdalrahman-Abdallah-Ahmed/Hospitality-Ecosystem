# Feature Specification: Guest Services and Concierge on the New Domain

**Feature Branch**: `007-guest-services-concierge`

**Created**: 2026-10-06

**Status**: Draft

**Input**: Phase 7 — Guest Services + Concierge on the new domain, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's four backlog
specs as one feature: SPEC-044 Guest Service Requests, SPEC-052 Concierge Read Tools,
SPEC-053 Concierge Action Tools, and SPEC-054 Human Escalation.

## Overview

Guests talk to the hotel through the WhatsApp Concierge. Today the Concierge can create a
staff task for a guest, but the loop stops there: the task may land without a team when
the Concierge does not pick a category, the guest is never told when the work is done,
and the Concierge cannot tell a guest what they already asked for or what they have
booked. Some guest wishes, such as moving to another room, have no path at all. Most
limits on what the Concierge may do are written only in its instructions, where the model
can miss them.

This feature closes the guest-service loop and puts the Concierge fully on the current
hotel domain (reservations with several rooms, stays per room, room types, activity
availability, bookings):

- **Request classification and routing.** Every guest request becomes a staff task in a
  task category (housekeeping, maintenance or other) and goes to that category's team.
- **Closing the loop.** When staff complete a guest's request, the guest is told on
  WhatsApp, or by email once the WhatsApp messaging window has closed.
- **Human escalation.** When the guest needs a person, staff get a task and a notice, and
  the Concierge stops promoting activities to that guest.
- **Guest read tools.** The guest can ask about their own reservation, stays and rooms,
  activities and what is free, their own bookings, their own open requests, and hotel
  information and policies.
- **Guest action tools.** The guest can book an activity, raise a service request or a
  maintenance request, ask for a room change, ask to cancel a booking, and ask for a
  human.
- **Enforced guest limits.** The restrictions are enforced by the system for every guest
  action, not only stated in the Concierge's instructions.
- **Identity.** A number that matches no guest or staff member gets no reply at all.

**Terms**:

- *Guest request*: a staff task the Concierge creates on a guest's behalf: a service
  request, a maintenance request, a room-change request, a booking cancellation request
  or an escalation.
- *Request category*: the task category a request is filed under. Every hotel has
  Housekeeping and Maintenance teams with default categories (D7). *Other* means no
  specific category fits.
- *Recognised guest*: a WhatsApp sender whose number matches a guest record, resolved to
  one hotel by the existing order: in-house stay, then upcoming reservation, then latest
  past stay, then newest guest record (D12).
- *Active reservation*: the recognised guest's in-house or upcoming reservation at the
  resolved hotel. A guest with only past stays has no active reservation.
- *In-house*: the guest has at least one stay currently checked in.
- *Messaging window*: the period after a guest's last message during which the hotel may
  send them free-form WhatsApp messages (24 hours under WhatsApp rules). Outside it, only
  pre-approved message templates may be sent.
- *Notice*: the message that tells a guest the outcome of their request, sent as a
  WhatsApp message or an email. The staff emails about new requests are not notices.
- *Pitching*: the Concierge proactively suggesting activities. The existing pitching gates
  stay in force; this feature only adds to the reasons they close.

**In scope**: classification of guest requests into categories and teams; maintenance and
room-change requests as their own guest actions; guest notification when a guest
request is completed or a booking cancellation request is decided, on WhatsApp or by
email according to the messaging-window rule; human escalation with
staff notification and the pitching pause; Concierge read tools for the guest's own
reservation, stays, rooms, bookings and open requests, plus activities and hotel
information on the current domain; system-enforced guest restrictions on every guest
tool; treatment of unrecognised numbers; audit, tenant isolation and documentation.

**Out of scope (later specs)**: Admin AI tools on the new domain (Phase 9). Knowledge
document upload, retrieval precedence and citations (Phase 8): this feature uses
hotel-information search as it exists today. Recommendation approval and the
decline-retry rule (Phase 10, D9/D10). Conversation visibility, guest memories and AI
audit consistency (Phase 11). Staff carrying out a room change (room assignment already
exists from Phase 3; this feature only creates the request). Guest-facing payments, fees
and refunds (D11). Separate WhatsApp numbers per hotel (D12). New frontend screens:
guest requests appear in the existing task views.

## Clarifications

### Session 2026-10-06

- Q: Should a guest be told when staff cancel (decline) their request, or only when it is
  completed? → A: Only when it is completed. A cancelled request sends no notice; staff
  contact the guest themselves if needed.
- Q: Outside the 24-hour WhatsApp window, how is the completion notice delivered? → A: By
  email to the guest's address on file. No WhatsApp template is used. A guest with no
  email address is not notified, and the task records why.
- Q: What happens when a number that matches no guest and no staff member messages the
  WhatsApp number? → A: No reply at all. The message is not answered, no AI runs, and
  nothing is created in any hotel.
- Q: When staff approve or decline a guest's booking cancellation request, should the
  guest be told? → A: Yes, for both outcomes: on WhatsApp inside the 24-hour window, by
  email outside it.
- Q: When staff close an escalation, should the guest get an automatic completion notice?
  → A: No. Only service, maintenance and room-change requests notify on completion;
  escalations never do, since staff have already contacted the guest.
- Q: Who receives a guest's room-change request? → A: It is a hotel-wide task with no
  team, and the hotel's admins are emailed about it, the same as booking cancellation
  requests.
- Q: Should the Concierge tell a guest the price of each of their bookings? → A: Yes,
  always, with the currency. There is no per-hotel setting to hide it.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A guest's request reaches the right team and the guest hears back (Priority: P1)

An in-house guest writes "the air conditioning in my room is not working". The Concierge
files a maintenance request for the guest's room, the Maintenance team sees it, and the
guest is told it has been passed on. When a technician marks the task completed, the
guest gets a WhatsApp message saying the issue has been dealt with.

**Why this priority**: This is the core of guest service. A request that goes to no team,
or a guest who never hears back, is the failure guests notice most.

**Independent Test**: As an in-house guest, send one housekeeping request, one
maintenance request and one request that fits neither. Check that each task has the
expected category and team, then complete each one and check that the guest is notified.

**Acceptance Scenarios**:

1. **Given** an in-house guest in room 214, **When** they report a broken air conditioner,
   **Then** a maintenance request is created for room 214 in a Maintenance category,
   assigned to the Maintenance team, and the guest is told it was passed on.
2. **Given** an in-house guest, **When** they ask for extra towels, **Then** a service
   request is created in a Housekeeping category and assigned to the Housekeeping team.
3. **Given** an in-house guest, **When** they ask for something no category fits (for
   example a taxi), **Then** a request is created with no category and no team, visible
   to all staff who can see hotel tasks.
4. **Given** a guest request is open, **When** staff mark it completed, **Then** the guest
   receives one notice (WhatsApp inside the window) in their language confirming it is
   done; the message names the kind of request but contains no staff names, internal notes
   or task text staff can edit.
5. **Given** a guest request is open, **When** staff cancel it, **Then** the guest is not
   notified; the task records that no notice was sent because it was cancelled.
6. **Given** a cleaning is already scheduled for the guest's room, **When** the guest
   asks for a cleaning, **Then** no second cleaning task is created and the guest is told
   one is already scheduled (existing rule kept).
7. **Given** a guest who is in-house in two rooms and does not say which, **When** they
   report a maintenance issue, **Then** the Concierge asks which room before filing it.

---

### User Story 2 - The guest is notified even after the messaging window closed (Priority: P1)

A guest reported a dripping tap in the evening and did not write again. The plumber fixes
it the next afternoon, more than 24 hours after the guest's last message. The guest
should still learn that it is done, within what WhatsApp allows.

**Why this priority**: Many requests finish after the window closes. Without a rule, the
completion message either fails silently or breaks WhatsApp's policy.

**Independent Test**: Complete one guest request inside the window, one outside it for a
guest with an email address, and one outside it for a guest without one. Check what is
sent in each case and what is recorded.

**Acceptance Scenarios**:

1. **Given** the guest's last message was 3 hours ago, **When** their request is
   completed, **Then** a free-form completion message is sent.
2. **Given** the guest's last message was 30 hours ago, **When** their request is
   completed, **Then** no WhatsApp message is sent; the completion notice is emailed to
   the guest's address on file, in their language.
3. **Given** the guest's last message was 30 hours ago and they have no email address,
   **When** their request is completed, **Then** nothing is sent and the task records
   that the guest could not be notified (outside window, no email).
4. **Given** a completion notice could not be delivered, **When** staff look at the task,
   **Then** they can see the guest was not notified and why.
5. **Given** a guest asked to cancel a booking, **When** staff approve the request,
   **Then** the guest is told the booking is cancelled, on WhatsApp inside the window or
   by email outside it.
6. **Given** a guest asked to cancel a booking, **When** staff decline the request,
   **Then** the guest is told the booking still stands, under the same rule.
7. **Given** the same request is marked completed twice (re-opened and completed again),
   **When** it is completed the second time, **Then** the guest is not notified again
   for the same request.

---

### User Story 3 - The guest asks for a human (Priority: P1)

A guest is frustrated, or asks for something the Concierge cannot do, or says "let me
talk to a person". The Concierge escalates: staff get a high-priority task and a notice,
the guest is told someone will contact them, and the Concierge stops suggesting
activities to that guest.

**Why this priority**: Escalation is the safety net for every other limit in this
feature. The Concierge may only refuse actions safely if a human is always reachable.

**Independent Test**: Trigger an escalation, then check the task, the staff notice, the
reply to the guest, and that the following replies contain no activity suggestions.

**Acceptance Scenarios**:

1. **Given** a recognised guest, **When** they ask for a human, **Then** a high-priority
   escalation task is created with the reason, linked to the guest and their active
   reservation if any, and the hotel's admins are notified.
2. **Given** an escalation was created, **When** the Concierge replies in that turn and
   later turns of the same stay, **Then** it does not pitch any activity (existing gate).
3. **Given** an open escalation task for the guest, **When** the guest asks for a human
   again, **Then** no second escalation task is created; the guest is told staff already
   have it, and the reason is added to the existing task.
4. **Given** an escalation task, **When** staff complete it, **Then** no completion notice
   is sent to the guest.
5. **Given** hotel policy requires escalation for a topic (for example a medical
   question), **When** the guest raises it, **Then** the Concierge escalates instead of
   answering.

---

### User Story 4 - The guest asks about their own stay, bookings and requests (Priority: P2)

A guest asks "which room am I in?", "what did I book for tomorrow?", "did anyone look at
my AC?" or "is the spa open on Friday?". The Concierge answers from live hotel data,
never from memory, and only about this guest.

**Why this priority**: These answers stop guests from calling the front desk for simple
questions. They depend on the request flow (Story 1) and on bookings (Phase 6).

**Independent Test**: Give a guest a reservation with two rooms, one in-house stay, two
bookings and one open request, and ask the Concierge about each. Check that the answers
match the data and that another guest's data never appears.

**Acceptance Scenarios**:

1. **Given** a guest with an active reservation of two rooms, **When** they ask about
   their reservation, **Then** they get its dates, party, room types and, for each room,
   the room number once assigned and the stay status.
2. **Given** a guest with bookings, **When** they ask what they have booked, **Then**
   they get each of their own bookings with activity, date, time, party size, status and
   reference, soonest first, and each booking's price and currency.
3. **Given** a guest with open requests, **When** they ask about them, **Then** they get
   each open request's subject and status (received, in progress), but no staff names,
   internal notes or team workload.
4. **Given** a guest asks about an activity on a date, **When** the Concierge answers,
   **Then** availability comes from the live availability check (Phase 6), not from
   hotel documents.
5. **Given** a guest asks about a hotel policy, **When** the Concierge answers, **Then**
   it uses the hotel-information search and does not invent policy text.
6. **Given** a guest refers to a booking or request by a reference that belongs to
   another guest, **When** they ask about it, **Then** the answer is the same as for a
   reference that does not exist.

---

### User Story 5 - The guest asks to change rooms (Priority: P2)

A guest writes "my room is too noisy, can I move?". The Concierge does not move them. It
files a room-change request with the reason, staff decide and do the move through the
normal room-assignment flow, and the guest is told the request was passed on.

**Why this priority**: Room changes are common and sensitive. They must reach staff, but
the guest AI must never be able to change a room itself.

**Independent Test**: As an in-house guest, ask for a room change and check that a
room-change request task exists, that no room assignment changed, and that a second ask
does not create a duplicate.

**Acceptance Scenarios**:

1. **Given** an in-house guest, **When** they ask to change rooms with a reason, **Then**
   a room-change request is created for their current room with the reason and any
   preference they gave (for example "higher floor"), with no team, the hotel's admins
   are emailed, and the guest is told staff will decide.
2. **Given** a room-change request is created, **When** anyone checks the guest's room
   assignment, **Then** it is unchanged.
3. **Given** an open room-change request for that stay, **When** the guest asks again,
   **Then** no duplicate is created and the guest is told it is already with staff.
4. **Given** a guest with an upcoming reservation who is not in-house, **When** they ask
   for a different room, **Then** a room-change request is created against the
   reservation (no room yet), so staff can handle it before arrival.
5. **Given** a guest with no active reservation, **When** they ask for a room change,
   **Then** no request is created and the guest is told it needs a current or upcoming
   reservation.

---

### User Story 6 - Guest limits hold even if the AI tries to break them (Priority: P1)

Whatever the guest writes and whatever the Concierge's model decides, a guest action can
never cancel a booking, change a room assignment, read another guest's data or staff
conversations, or get around hotel policy rules such as an activity's availability.

**Why this priority**: These are safety rules (constitution V). If they live only in the
instructions, one confused model reply is enough to break them.

**Independent Test**: Call each guest tool directly with inputs that try to break a rule
(another guest's booking, a status of "cancelled", a room id, a closed activity date) and
check that each attempt is refused and nothing changes.

**Acceptance Scenarios**:

1. **Given** any guest tool, **When** it is given another guest's booking, request,
   reservation or room, **Then** it acts as if the record does not exist and changes
   nothing.
2. **Given** the guest tool set, **When** it is listed, **Then** no tool can cancel a
   booking, change a booking's status, assign or change a room, or check a guest in or
   out.
3. **Given** a booking request for a closed or full activity, **When** the guest insists,
   **Then** the booking is refused with the same rules that apply to staff (Phase 6), and
   the guest is offered the alternative dates.
4. **Given** a guest tries to read staff notes, other guests' messages or staff
   conversations, **When** they ask, **Then** no tool returns them.
5. **Given** every guest action, **When** it succeeds, **Then** the audit trail records
   it as done by the AI on the guest's behalf.

---

### User Story 7 - An unknown number gets no reply (Priority: P2)

Someone messages the shared hotel number from a phone that matches no guest record.

**Why this priority**: The WhatsApp number is shared by all hotels (D12). An unknown
sender must never get another person's data or be able to act in a hotel's name.

**Independent Test**: Message from an unrecognised number and check that no reply is
sent, no AI runs, and no task, booking or other hotel record is created.

**Acceptance Scenarios**:

1. **Given** a number that matches no guest or paired staff member, **When** it sends a
   message, **Then** no reply is sent (the current fixed "contact the hotel" reply is
   removed) and no AI conversation turn runs.
2. **Given** an unknown number, **When** it asks to book, request service or reach a
   human, **Then** nothing is created in any hotel.
3. **Given** a recognised staff member or guest, **When** they message, **Then** they are
   handled as before; only unrecognised numbers are ignored.
4. **Given** an unknown number sends the same message twice, **When** the second copy
   arrives, **Then** it is ignored as well.

---

### User Story 8 - Guests with only past stays get limited help (Priority: P3)

A guest who stayed last year writes again. They are recognised, but have no active
reservation.

**Why this priority**: Rare, but without a rule the Concierge could raise service tasks
for a room the guest no longer occupies.

**Independent Test**: As a guest with only past stays, try each action and check which
are refused.

**Acceptance Scenarios**:

1. **Given** a guest with only past stays, **When** they ask for service, a maintenance
   fix, a room change or a booking, **Then** nothing is created and they are told these
   need a current or upcoming reservation.
2. **Given** a guest with only past stays, **When** they ask for a human, **Then** an
   escalation is still created, so they can always reach the hotel.
3. **Given** a guest with only past stays, **When** they ask about hotel information or
   activities, **Then** they get answers.

### Edge Cases

- The guest reports a problem in a room that is not theirs ("the room next door is
  loud"). The request is filed against the guest's own stay, and the other room number is
  written only in the description. No tool takes another guest's room as input.
- The category the Concierge chose was deleted or belongs to another hotel. The request
  is filed with no category and no team, rather than failing or crossing hotels.
- A category has no team. The request is filed in the category with no team.
- A maintenance request is filed for a room. Whether the room goes out of order stays a
  staff decision (Phase 5); a guest request never changes room status by itself.
- A guest sends several messages about the same issue in a row. The Concierge checks the
  guest's open requests first and adds to an existing one rather than filing a duplicate.
  A second request of the same kind for the same room is still allowed when the guest
  says it is a different problem.
- The guest checks out while a request is open. The request stays open for staff, and its
  completion notice follows the messaging-window rule.
- A guest's language is not set. Messages use the hotel's default language.
- A guest's phone number is removed or changed before their request completes. No
  WhatsApp message can be sent, so the notice goes by email; with no email either, the
  task shows the guest could not be notified.
- The guest's email bounces or sending fails. The failure is recorded on the task like a
  WhatsApp failure.
- WhatsApp sending fails. The failure is recorded on the task and retried a limited number
  of times; it never blocks staff from completing the task.
- A task created by staff (not by a guest) is completed. The guest is not notified; only
  service, maintenance and room-change requests trigger completion notices.
- Two hotels have a guest record with the same number. The guest's hotel is resolved
  once per message (D12), and every tool uses that hotel only.
- The guest asks the Concierge to cancel a booking "right now, it's urgent". It still
  only files a cancellation request (Phase 6), and may escalate as well.

## Requirements *(mandatory)*

### Functional Requirements

**Classification and routing (SPEC-044)**

- **FR-001**: Every guest request the Concierge creates MUST be filed under a task
  category of the guest's hotel, or under no category when none fits.
- **FR-002**: A request filed under a category MUST be assigned to that category's team.
  A request with no category, or a category with no team, MUST be visible to all staff
  allowed to view the hotel's tasks.
- **FR-003**: The system MUST validate the chosen category against the guest's hotel and
  MUST file the request with no category when the category is missing, deleted or from
  another hotel.
- **FR-004**: Housekeeping, maintenance and other service requests MUST be distinguishable
  in the task list, so staff can filter guest requests by kind.
- **FR-005**: Guest requests about a room MUST be linked to the guest's stay and room when
  the guest is in-house, and to the reservation when they are not. When the guest is in
  several rooms and does not say which, the Concierge MUST ask before filing.
- **FR-006**: Before filing a new request, the Concierge MUST check the guest's open
  requests, and MUST add to an existing open request of the same kind for the same room
  when the guest describes the same problem. Deciding that two messages describe "the same
  problem" is AI guidance, verified by manual review; the system guarantees only that
  adding to a request works on the guest's own open request of the same kind.

**Guest notification (SPEC-044)**

- **FR-007**: When a guest request covered by FR-009 is completed, the system MUST notify
  the guest once, by the channel FR-010 sets, in Arabic when the guest's language is
  Arabic and in English otherwise.
- **FR-008**: The notice MUST name the kind of request in plain words (for example "your
  maintenance request") and MUST NOT contain staff names, internal notes, team names,
  staff-editable task text or other guests' data.
- **FR-009**: Only service, maintenance and room-change requests MUST trigger a completion
  notice. Escalations, booking cancellation requests (see FR-010c) and tasks created by
  staff MUST NOT.
- **FR-010**: A notice MUST follow the messaging-window rule: a WhatsApp message inside the
  window; outside it, an email to the guest's address on file. A WhatsApp message MUST NOT
  be sent outside the window.
- **FR-010a**: When the guest is outside the window and has no email address, no notice
  MUST be sent and the reason MUST be recorded.
- **FR-010b**: A cancelled guest request MUST NOT notify the guest.
- **FR-010c**: When staff approve or decline a booking cancellation request, the guest
  MUST be notified of the outcome (cancelled, or still booked) under the same
  WhatsApp-inside-window / email-outside-window rule, recorded like any other notice.
- **FR-011**: Every notice attempt, sent, skipped or failed, MUST be recorded against the
  task with its reason and be visible to staff.
- **FR-012**: A request completed more than once MUST NOT notify the guest more than once.
- **FR-013**: Sending a notice MUST NOT block or undo staff completing the task.

**Human escalation (SPEC-054)**

- **FR-014**: The guest MUST be able to ask for a human at any time, including guests with
  only past stays.
- **FR-015**: An escalation MUST create a high-priority task with the reason, linked to the
  guest and their active reservation if any, and MUST notify the hotel's admins.
- **FR-016**: An escalation MUST close pitching for that guest's stay (existing gate) and
  in the same reply.
- **FR-017**: While an escalation for the guest is open, a new escalation MUST add its
  reason to the open one instead of creating another.
- **FR-018**: The Concierge MUST escalate when the guest explicitly asks for a person, when
  hotel policy says a topic needs a person, or when it cannot help after trying. This is AI
  guidance, verified by manual review; the system guarantees only that escalation is always
  available (FR-014).

**Read tools (SPEC-052)**

- **FR-019**: The guest MUST be able to see their own reservation (the one resolved for
  them): dates, party, room types and, per room, the assigned room number and stay status,
  marked as current or past so the Concierge knows whether actions are possible.
- **FR-020**: The guest MUST be able to see their own bookings at the hotel, upcoming first,
  with activity, date, time, party size, status, reference, price and currency.
- **FR-021**: The guest MUST be able to see their own open guest requests with subject and
  a guest-facing status (received, in progress), without staff names or internal notes.
- **FR-022**: Activity information and availability MUST come from the live activity
  catalogue and the availability check, never from documents.
- **FR-023**: Hotel information and policy answers MUST come from the hotel-information
  search, and the Concierge MUST NOT state a policy it did not find.
- **FR-024**: Read tools MUST NOT return another guest's data, staff conversations or staff
  notes. A record that belongs to someone else MUST be treated as not found.

**Action tools (SPEC-053)**

- **FR-025**: The guest MUST be able to book an activity, under the Phase 6 availability
  rules and date limits.
- **FR-026**: The guest MUST be able to raise a service request and a maintenance request.
  A maintenance request MUST be filed in a maintenance category and go to the Maintenance
  team.
- **FR-027**: The guest MUST be able to ask for a room change. It MUST create a room-change
  request for staff with the reason and any preference, and MUST NOT change any room
  assignment.
- **FR-027a**: A room-change request MUST be filed with no team, visible to all staff
  allowed to view the hotel's tasks, and the hotel's admins MUST be emailed when it is
  created.
- **FR-028**: At most one room-change request MUST be open per stay (or per reservation
  before arrival).
- **FR-029**: The guest MUST be able to ask to cancel a booking, which creates a
  cancellation request (Phase 6) and never cancels the booking.
- **FR-030**: Service, maintenance and room-change requests and bookings MUST require an
  active reservation at the guest's hotel. Escalation MUST NOT.

**Guest restrictions**

- **FR-031**: Guest tools MUST NOT be able to cancel a booking, change a booking status,
  assign or change a room, change room status, or check a guest in or out.
- **FR-032**: Every guest tool, read or write, MUST act only on the recognised guest's own
  records in the resolved hotel, whatever ids or references it is given (the read side is
  FR-024).
- **FR-033**: The hotel rules in FR-025, FR-028 and FR-030 MUST be enforced by the system
  on guest actions, not only by the Concierge's instructions.
- **FR-034**: Every guest action that changes data MUST be recorded in the audit trail as
  done by the AI on the guest's behalf.

**Identity**

- **FR-035**: A sender that matches no guest and no staff member MUST get no reply, MUST
  NOT start an AI turn, and MUST NOT be able to create any record or read any data in any
  hotel. The existing fixed reply to unknown senders is removed.
- **FR-035a**: Message de-duplication and per-sender rate limiting MUST keep working for
  unknown senders, so ignoring them cannot be used to flood the system.
- **FR-036**: The hotel for a recognised guest MUST be resolved by the existing order
  (D12), and every tool in that turn MUST use that hotel only.

**Isolation and documentation**

- **FR-037**: Guest requests, notices and escalations MUST be hotel-scoped; staff of one
  hotel MUST NOT see another hotel's guest requests.
- **FR-038**: The guest-facing behaviour and any new staff-visible request fields MUST be
  documented with the matching API documentation.

### Key Entities *(include if feature involves data)*

- **Guest request (task)**: an existing staff task created for a guest. Gains a clear
  kind (service, maintenance, room change, cancellation, escalation), its category and
  team, the guest, reservation, stay and room it concerns, and the guest notification
  record.
- **Room-change request**: a guest request of kind *room change*, holding the guest's
  reason and preference, linked to the stay (or reservation before arrival). Staff act
  on it through room assignment; the request itself changes nothing.
- **Guest notification record**: per guest request, whether the guest was notified on
  completion, when, how (WhatsApp or email), or why not (cancelled, outside window with no
  email, no phone, send failed).
- **Task category / Team**: existing. Categories map requests to teams; Housekeeping and
  Maintenance exist for every hotel (D7).
- **Guest, Reservation, Stay, Room, Booking, Activity**: existing; read by the guest tools
  only within the guest's own records.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of guest requests that fit a housekeeping or maintenance category reach
  that category's team without staff re-routing, in an acceptance run of at least 20
  sample requests.
- **SC-002**: Guests are notified within 2 minutes of staff completing their request, for
  at least 95% of completions inside the messaging window.
- **SC-003**: 0 WhatsApp notices are sent outside the messaging window, and 100% of
  completions outside the window for guests with an email address produce an email.
- **SC-004**: In a test of every guest tool against another guest's records and every
  forbidden action, 0 attempts change data or reveal another guest's data.
- **SC-005**: 0 duplicate escalations or room-change requests are created for the same
  guest stay while one is open.
- **SC-006**: Messages from unrecognised numbers get 0 replies, run 0 AI turns and create 0
  records in any hotel.
- **SC-007**: A guest gets a correct answer about their own room, bookings or open
  requests without staff help in at least 90% of sample questions.
- **SC-008**: Every guest action that changes data appears in the audit trail with the AI
  as actor.

## Assumptions

- The guest's hotel is resolved per message by the existing order (D12); one shared
  WhatsApp number is kept.
- Default Housekeeping and Maintenance teams and categories exist for every hotel (D7,
  Phase 1/5).
- Activity availability, bookings and cancellation requests from Phase 6 are reused as
  they are.
- Room changes are carried out by staff with the existing room-assignment flow (Phase 3).
- Only completion triggers a guest notice by default; progress updates (for example "a
  technician is on the way") are not sent.
- Completion notices, on WhatsApp and by email, are fixed, translated messages in English
  or Arabic, not generated by the AI, so their wording is predictable.
- Email notices use the platform's existing outgoing mail setup and show the hotel's
  name. No WhatsApp message templates are needed for this feature.
- Hotel information search works as it does today; precedence and citations arrive in
  Phase 8.
- Escalation notices go to the hotel's admins by the existing notification channel.
- Staff handle guest requests with existing task permissions; no new permission is needed
  unless planning finds a new staff endpoint.
- An unknown sender is not told why they got no reply. This is accepted, as they are
  not a guest or staff member of any hotel.
- Notice delivery is retried up to 3 times before it is recorded as failed.
