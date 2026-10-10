# Feature Specification: Recommendation Approval and Proactive Concierge

**Feature Branch**: `010-recommendation-approval-proactive`

**Created**: 2026-10-09

**Status**: Draft

**Input**: Phase 10 — Recommendation Approval + Proactive Concierge, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's three backlog
specs as one feature: SPEC-071 Recommendation Approval, SPEC-073 Proactive WhatsApp, and
SPEC-074 Decline Retry Rule. Follows plan decisions D9 (recommendation approval) and D10
(decline retry).

## Overview

The Recommendation agent suggests activities for each reservation, and the WhatsApp
Concierge offers ("pitches") them to guests when the conversation opens the door. Today a
generated recommendation can be pitched to a guest the moment it exists: nobody at the
hotel reviews what the AI suggests before a guest hears it. When a guest has never been
given recommendations, the Concierge even generates them in the middle of a conversation
and may offer one in the same reply. The constitution requires the opposite: a
recommendation MUST be approved by an administrator before the Concierge may pitch it.

Two more gaps sit next to it. After a guest says "no thanks" once, pitching stops for the
whole stay, which is stricter than the agreed rule (D10). And the Concierge only ever
speaks when the guest writes first, so it cannot remind a guest of an activity they booked
or offer an approved suggestion at a good moment of the stay.

This feature:

- **Approval.** Every recommendation starts as *pending approval*. A permitted staff
  member approves or rejects it, alone or in bulk, from an approval queue. Every decision
  is audited.
- **Approved pool only.** The Concierge pitches only approved recommendations — in
  conversation and proactively. Generation during a conversation still happens, but it
  produces pending recommendations for review and never a pitch in that turn.
- **Decline retry.** After a guest declines a pitch, the Concierge may offer at most one
  different approved activity, within 24 hours of the first pitch. Then the stay is closed
  to unsolicited pitching.
- **Proactive messages.** The Concierge may message an in-house guest first at defined
  moments — a stay milestone, an upcoming booked activity, or a newly approved
  recommendation — under guardrails: hotel switch, stay eligibility, quiet hours, frequency
  caps, duplicate prevention, guest opt-out, and WhatsApp messaging rules.
- **Measurement unchanged.** Outcome tracking and conversion attribution keep working as
  they do today, now also for proactive pitches.

**Terms**:

- *Recommendation*: a suggested activity for one reservation, with a reason, priority and
  confidence, produced by the Recommendation agent.
- *Approval status*: where a recommendation is in review — *pending approval*, *approved*,
  or *rejected by admin*. Only *approved* recommendations can reach a guest.
- *Approver*: a user holding the new "approve recommendations" permission. Hotel admins and
  super admins hold it; employees only through a staff role that grants it.
- *Pitch*: the Concierge offering an approved recommendation's activity to the guest.
- *Unsolicited pitch*: a pitch the guest did not explicitly ask for — a contextual pitch in
  a conversation, or a proactive one. A guest asking "what can I do today?" is an
  *explicit request*, not an unsolicited pitch.
- *Pitch flow*: the first unsolicited pitch of a stay plus, after a decline, at most one
  retry. The flow lasts at most 24 hours from the first pitch.
- *Proactive message*: a WhatsApp message the Concierge sends without the guest having
  written first, caused by a *trigger*.
- *Trigger*: an event that may cause a proactive message — a stay milestone, an upcoming
  booked activity, or an approved recommendation opportunity.
- *Messaging window*: the 24 hours after the guest's last message in which the hotel may
  send free-form WhatsApp messages (same definition as in feature 007).
- *Quiet hours*: the hotel-local period in which no proactive message is sent.
- *Opt-out*: a guest's request to receive no more proactive messages and no more
  unsolicited pitches. Answers to their own questions, including explicit requests for
  suggestions, continue.

## Clarifications

### Session 2026-10-09

- Q: How should proactive messages reach a guest outside the 24-hour WhatsApp window? →
  A: They don't. The message is skipped and the reason recorded; no WhatsApp template and
  no email fallback, for pitches and reminders alike (constitution unchanged).
- Q: When a guest opts out, do unsolicited pitches inside conversations stop too? → A: Yes.
  Opt-out stops proactive messages and unsolicited contextual pitches; explicit requests
  for suggestions are still answered.
- Q: What happens to an approved recommendation that is edited? → A: Changing the
  activity, reason or reservation returns it to *pending approval*; changing only priority
  or confidence keeps it approved.
- Q: Are proactive messages written by the AI or from fixed texts? → A: Fixed texts per
  trigger and language with placeholders filled from hotel data; no AI is called to send
  them.
- Q: May the Admin AI approve or reject recommendations? → A: Yes, only ones the user
  names, after the user confirms, never in bulk, audited as the AI acting for that user.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An admin reviews AI recommendations before any guest sees them (Priority: P1)

The Recommendation agent generates five activity suggestions for a reservation arriving
tomorrow. None of them can reach the guest yet. The hotel admin opens the approval queue,
sees the five suggestions with the guest, stay dates, activity, reason and confidence,
approves three, and rejects two with a short reason ("pool closed for maintenance"). Only
the three approved ones can now be offered.

**Why this priority**: This is the constitutional rule the feature exists for. Without it
the hotel has no control over what an AI tells its guests, and nothing else in this phase
is safe to switch on.

**Independent Test**: Generate recommendations for a reservation; confirm all are pending
approval and are never offered to the guest; approve some and reject others; confirm the
statuses, the audit entries, and that only approved ones become offerable.

**Acceptance Scenarios**:

1. **Given** recommendations are generated for a reservation (by staff request, by the
   Admin AI, or during a guest conversation), **When** they are saved, **Then** every one is
   *pending approval* and none is offered to the guest.
2. **Given** a pending recommendation, **When** an approver approves it, **Then** it becomes
   *approved*, records who approved it and when, and an audit entry is written.
3. **Given** a pending or approved-but-never-offered recommendation, **When** an approver
   rejects it with an optional reason, **Then** it becomes *rejected by admin*, records who,
   when and why, is never offered, and an audit entry is written.
4. **Given** an employee without the approve permission, **When** they try to approve or
   reject, **Then** the request is refused (forbidden) and the recommendation is unchanged.
5. **Given** an employee whose staff role grants the approve permission, **When** they
   approve a recommendation of their own hotel, **Then** it succeeds.
6. **Given** a recommendation of another hotel, **When** an approver of this hotel tries to
   see, approve or reject it, **Then** it is treated as not found.
7. **Given** a recommendation that has already been offered to the guest, accepted,
   declined, expired or rejected by admin, **When** anyone tries to approve or reject it,
   **Then** the request is refused with a clear message and nothing changes.

---

### User Story 2 - The Concierge offers only approved recommendations (Priority: P1)

A guest in house writes "anything fun to do tomorrow?". The reservation has two
recommendations pending approval and one approved. The Concierge offers only the approved
one. For another guest whose reservation has never had recommendations, the Concierge
starts generation, answers the guest normally without offering anything, and the new
suggestions appear in the approval queue.

**Why this priority**: Approval means nothing unless every path that reaches a guest
respects it. This story closes the existing path where a just-generated suggestion is
pitched immediately.

**Independent Test**: For a guest in a conversation, seed a mix of pending, approved and
rejected recommendations and check the candidate list of the turn; then use a reservation
with none and check that generation produces pending records and the turn makes no pitch.

**Acceptance Scenarios**:

1. **Given** a reservation with pending, approved and rejected-by-admin recommendations,
   **When** the Concierge looks for something to offer, **Then** only approved ones that
   were not yet offered and whose activity is still active are candidates.
2. **Given** a reservation with no recommendations at all, **When** a guest turn would
   allow a pitch, **Then** recommendations are generated as *pending approval*, the turn
   records "no candidates", and the guest gets a normal reply without an offer.
3. **Given** a guest explicitly asks for suggestions and nothing is approved, **When** the
   Concierge replies, **Then** it may describe activities from the hotel's live activity
   list, but it does not present any unapproved recommendation as the hotel's suggestion.
4. **Given** every recommendation was approved and later offered, **When** the guest asks
   again, **Then** no recommendation is offered twice.

---

### User Story 3 - One more try after a "no", then stop (Priority: P1)

At 10:00 the Concierge suggests a sunset cruise. The guest replies "no thanks, not into
boats". Later that afternoon the guest mentions they love food; the Concierge may suggest
the approved cooking class — a different activity. The guest says no again. For the rest
of the stay the Concierge makes no more unsolicited suggestions. If the guest had not been
offered the cooking class until 11:00 the next day, past the 24 hours from the first
pitch, it would not have been offered.

**Why this priority**: It replaces today's "one refusal ends pitching for the stay" with
the agreed rule (D10), and it is the main protection against pushy behavior once proactive
messages exist.

**Independent Test**: Simulate a first pitch and a decline, then turns at different times
with different and identical activities; check which turns may pitch.

**Acceptance Scenarios**:

1. **Given** the first unsolicited pitch of the stay was declined, **When** an opening
   appears within 24 hours of that first pitch, **Then** at most one approved recommendation
   for a *different activity* may be pitched.
2. **Given** the declined activity, **When** candidates are chosen for the retry, **Then**
   any recommendation for the same activity is excluded.
3. **Given** the retry has been made (whatever the guest answers), **When** a later opening
   appears, **Then** no unsolicited pitch is made for the rest of the stay.
4. **Given** the first pitch was declined and no retry happened, **When** 24 hours have
   passed since the first pitch, **Then** the stay is closed to unsolicited pitching.
5. **Given** the stay is closed to unsolicited pitching, **When** the guest explicitly asks
   for suggestions, **Then** the Concierge may still answer from approved recommendations;
   this does not reopen unsolicited pitching.
6. **Given** a decision row is recorded for a blocked turn, **When** staff read it,
   **Then** it says which rule blocked it (retry used, retry window passed, or same
   activity).

---

### User Story 4 - The Concierge reaches out at the right moment (Priority: P2)

A guest checked in yesterday. This morning at 10:00 hotel time the Concierge sends a short
message in the guest's language: hoping the first night went well, and mentioning the
approved snorkeling trip with its short description from the activity list. Both messages
come from fixed message texts, filled in with the hotel's data. Another guest has a desert safari
booked for tomorrow at 08:00; this evening at 18:00 they get a reminder with the meeting
time and place. Neither message is sent at 23:00, neither is sent twice, and a guest who
wrote "please stop messaging me" gets none.

**Why this priority**: Proactive outreach is the revenue and service upside of the phase,
but it depends on stories 1–3 being in place and on guardrails being trustworthy.

**Independent Test**: With proactive messaging switched on for one hotel, run the trigger
evaluation at chosen hotel-local times for guests in different situations (just checked
in, booked activity tomorrow, newly approved recommendation, opted out, in quiet hours,
departing today, escalated) and check exactly which messages are sent, deferred or skipped
and why.

**Acceptance Scenarios**:

1. **Given** proactive messaging is off for a hotel (the default), **When** any trigger
   fires, **Then** no proactive message is sent and the skip is recorded.
2. **Given** a guest in house since yesterday with an approved, not-yet-offered
   recommendation, **When** the first-morning milestone is reached, **Then** one message is
   sent, in the guest's language, offering that recommendation, and it counts as the stay's
   first unsolicited pitch.
3. **Given** a guest with a confirmed activity booking starting within the reminder lead
   time, **When** the reminder trigger runs, **Then** one reminder is sent with the
   activity, date, time and, when recorded, the meeting point, and it does not count as a
   pitch.
4. **Given** a recommendation is approved for an in-house guest who has not had a pitch
   this stay, **When** the opportunity trigger runs, **Then** a message offering it may be
   sent, subject to every guardrail.
5. **Given** a trigger fires during quiet hours, **When** it is evaluated, **Then** nothing
   is sent then; the message is sent at the end of quiet hours if it is still valid, or
   skipped with the reason recorded.
6. **Given** a guest who opted out, **When** any trigger fires, **Then** nothing is sent.
7. **Given** a trigger has already produced a message for this stay and this event,
   **When** it fires again (retry, duplicate schedule run, or job retry), **Then** no second
   message is sent.
8. **Given** the guest has already received the daily maximum of proactive messages,
   **When** another trigger fires the same hotel-local day, **Then** it is deferred to the
   next day if still valid, or skipped.
9. **Given** the guest is departing today, has checked out, was escalated to a human this
   stay, or has an open complaint, **When** a pitch trigger fires, **Then** no pitch is
   sent.
10. **Given** the guest's last message was more than 24 hours ago, **When** any trigger
    fires, **Then** nothing is sent on any channel and the skip is recorded as "outside
    messaging window".
11. **Given** the guest replies to a proactive message, **When** the reply arrives,
    **Then** it is handled as a normal Concierge conversation, and a reply to a proactive
    pitch is recorded as that recommendation's outcome as today.

---

### User Story 5 - Guests can stop proactive messages and offers (Priority: P2)

A guest replies "stop sending me offers". The Concierge confirms in one line that they
will get no more proactive messages or unprompted offers, and none are made for the rest of their relationship
with the hotel unless they ask to resume. Staff can see that the guest opted out and can
clear it only when the guest asks.

**Why this priority**: Guests must be able to refuse outreach easily. It is a guardrail of
story 4, but separable and testable on its own.

**Independent Test**: Send an opt-out message from a guest; check the guest's opt-out
state, the confirmation reply, the audit entry, and that triggers skip the guest; then
record an opt-in and check triggers resume.

**Acceptance Scenarios**:

1. **Given** a guest writes a clear request to stop proactive messages (in any supported
   language, or a standard keyword such as STOP), **When** the Concierge handles it,
   **Then** the guest is marked opted out, receives one confirmation, and an audit entry
   is written.
2. **Given** an opted-out guest writes to the Concierge, **When** they ask a question,
   **Then** they get normal answers, with no unsolicited pitch attached; if they explicitly
   ask for suggestions, they get approved ones.
3. **Given** an opted-out guest asks to receive messages again, **When** the Concierge or a
   permitted staff member records it, **Then** proactive messages and unsolicited pitches
   may resume.
4. **Given** an opted-out guest, **When** a request they made is completed (feature 007
   completion notice), **Then** that notice is still sent: it is a reply to the guest's own
   request, not a proactive message.

---

### User Story 6 - Approval queue shows what needs attention (Priority: P3)

An admin with 40 pending recommendations filters the queue to guests arriving in the next
three days, sorts by arrival, selects ten that look right and approves them in one action.
Suggestions for guests who already left disappear from the queue on their own.

**Why this priority**: Makes approval practical at hotel volume. The single-item flow in
story 1 already satisfies the rule.

**Independent Test**: Seed pending recommendations across stays with different dates and
statuses; filter, sort and bulk-approve; check that departed stays' pending items have
expired.

**Acceptance Scenarios**:

1. **Given** pending recommendations, **When** an approver lists them, **Then** they can
   filter by approval status, guest, reservation, activity, arrival date range and
   generation source, and sort by arrival date, priority or confidence.
2. **Given** a selection of recommendations, **When** an approver approves or rejects them
   in one action, **Then** each eligible one is decided and audited individually, and the
   response says which were decided and which were skipped and why.
3. **Given** a pending or approved recommendation whose stay has departed or whose
   reservation was cancelled, **When** expiry runs, **Then** it becomes *expired* and leaves
   the queue.
4. **Given** the Admin AI is asked by a permitted user "approve the cooking class for room
   204", **When** it acts, **Then** it names the recommendation, asks the user to confirm,
   and only after confirmation applies the approval through the same rules, permission
   check and audit as the queue, recorded as the AI acting for that user.
5. **Given** a user asks the Admin AI to "approve everything pending", **When** it
   responds, **Then** it refuses to approve in bulk and points the user to the approval
   queue.

### Edge Cases

- **Approval after the moment passed**: a recommendation is approved after its activity was
  deactivated. It stays approved but is not a candidate while the activity is inactive.
- **Rejecting after approval**: an approved recommendation that was never offered may be
  rejected (withdrawn). One that was already offered cannot be withdrawn; its outcome is
  the guest's.
- **Editing after approval**: an approved recommendation whose activity, reason or
  reservation is changed goes back to *pending approval* (FR-006a), so nothing unreviewed
  is offered. Changing only priority or confidence keeps it approved.
- **Concurrent decisions**: two approvers decide the same recommendation at once. Exactly
  one decision wins; the other gets a clear "already decided" response; one audit entry
  per real change.
- **Approval racing a pitch**: an admin rejects a recommendation while a Concierge turn has
  shortlisted it. A recommendation rejected before the reply is sent is not pitched.
- **Existing recommendations at rollout**: recommendations that were waiting to be offered
  when this feature ships have never been reviewed. They become *pending approval*.
  Offered, accepted, declined and closed ones keep their status.
- **Regeneration**: staff generate again for a reservation that already has pending items.
  New items are added as pending; existing decisions are not changed.
- **Multi-room reservation**: one reservation with several stays and one primary guest.
  Pitch flows, caps and proactive triggers apply per guest per reservation, so the same
  guest is not pitched once per room.
- **Explicit request during a closed stay**: allowed (story 3, scenario 5); it never counts
  as the first pitch or the retry.
- **Decline of a proactive pitch**: counts exactly like a decline in conversation and
  starts the same retry rule.
- **Accepted first pitch**: the decline-retry rule does not apply; the existing per-stay
  cap on unsolicited pitches decides whether another pitch flow may start.
- **Guest is mid-conversation**: a proactive message is not sent while the guest wrote in
  the last 30 minutes; any pitch then happens in the conversation itself. The trigger is
  deferred, not lost.
- **Guest with no WhatsApp number**: no proactive message. No fallback channel for
  promotional content.
- **Hotel timezone**: milestones, quiet hours and daily caps use the hotel's local time;
  a hotel without a timezone uses UTC.
- **Booking cancelled after the reminder was scheduled**: the reminder is skipped at send
  time; triggers are checked against current data when they fire, not when they are
  scheduled.
- **Pre-arrival guests**: no proactive pitches before arrival (consistent with today's
  pitching rule). An upcoming-activity reminder may only go to a guest with a stay in
  house or arriving before the activity.
- **Send failure**: the attempt is recorded as failed with the reason; it does not count
  toward caps and is retried at most once while still valid.
- **Pitching switched off platform-wide**: no contextual or proactive pitches; reminders
  are governed by the hotel's proactive setting alone.

## Requirements *(mandatory)*

### Functional Requirements

**Approval lifecycle (SPEC-071)**

- **FR-001**: Every newly created recommendation MUST start as *pending approval*, whatever
  created it: a staff generation request, the Admin AI, the Recommendation agent, or
  generation during a guest conversation.
- **FR-002**: The recommendation lifecycle MUST include *pending approval*, *approved* and
  *rejected by admin*, in addition to the existing guest-outcome states (offered (`sent`), accepted,
  declined, purchased, ignored, expired, cancelled). Only *approved* recommendations may
  move on to being offered.
- **FR-003**: Allowed approval transitions: *pending approval* → *approved*; *pending
  approval* → *rejected by admin*; *approved* (never offered) → *rejected by admin*. Every
  other approval change MUST be refused with a clear message and no change.
- **FR-004**: An approval decision MUST record who decided, when, and for a rejection an
  optional reason (up to 500 characters).
- **FR-005**: Approving and rejecting MUST require a new "approve recommendations"
  permission, checked together with the same-hotel rule. It MUST NOT be granted to
  employees without a staff role by default (approval is a judgment of what the hotel
  tells guests). Admins and super admins hold it as they hold every permission.
- **FR-006**: The general recommendation edit MUST NOT be able to change approval status or
  approval fields; only the approve/reject actions can.
- **FR-006a**: Editing the activity, reason or reservation of an *approved* recommendation
  that was never offered MUST return it to *pending approval*, clear its approval record,
  and write an audit entry. Editing only its priority or confidence MUST keep it approved.
  A recommendation that was already offered, or is in any final state, MUST NOT be
  editable in those fields.
- **FR-007**: Every approval decision MUST be written to the audit log with actor, actor
  type (human, or AI acting for a user), recommendation, previous and new status, and
  reason.
- **FR-008**: Approvers MUST be able to approve or reject several recommendations in one
  action (up to 100). Each one MUST be checked and audited individually; ineligible items
  are skipped and reported, not fail the whole action.
- **FR-009**: The recommendation list MUST support filtering by approval status, guest,
  reservation, activity, arrival date range and generation source, and sorting by arrival
  date, priority and confidence.
- **FR-010**: Pending and approved recommendations that were never offered MUST become
  *expired* once their stay has departed or their reservation is cancelled or a no-show.
  Until reservations get a no-show status (SPEC-012), a no-show is read from the
  reservation's stays.
- **FR-011**: Recommendations existing before this feature that were still waiting to be
  offered MUST become *pending approval*. Recommendations in any other status MUST keep it.
- **FR-012**: The Admin AI MUST be able to list recommendations awaiting approval and to
  approve or reject them when the acting user holds the permission, through the same rules
  and audit as the API, with these limits:
  - only recommendations the user identified (by guest, room, reservation or activity),
    one decision per recommendation; no bulk approval or rejection through the AI;
  - the user MUST confirm each decision before it is applied;
  - each decision is audited as the AI acting for that user.

**Pitching from the approved pool (D9)**

- **FR-013**: The Concierge's pitch candidates — contextual, explicit-request and proactive
  — MUST come only from *approved* recommendations that were never offered and whose
  activity is active.
- **FR-014**: Generation started during a guest conversation MUST create *pending approval*
  recommendations only. It MUST NOT make anything offerable in that turn or any later turn
  until an approver approves it.
- **FR-015**: A recommendation rejected or expired after being shortlisted but before the
  reply is sent MUST NOT be pitched.
- **FR-016**: The existing pitch decision record MUST continue to be written for every
  guest turn, and MUST show when a turn had no candidates because nothing was approved.

**Decline retry (SPEC-074, D10)**

- **FR-017**: The rule "any decline closes pitching for the stay" MUST be replaced by the
  pitch-flow rule: after the guest declines an unsolicited pitch, at most one unsolicited
  pitch of a *different activity* may follow, and only within 24 hours of the flow's first
  pitch.
- **FR-018**: Once the retry has been made, or 24 hours have passed since the first pitch
  of a flow that had a decline, the stay MUST be closed to further unsolicited pitches
  (contextual and proactive) for the rest of the stay.
- **FR-019**: An activity the guest declined MUST NOT be pitched to that guest again during
  the same stay, explicitly requested or not.
- **FR-020**: Explicit guest requests for suggestions MUST NOT start, count toward, or be
  blocked by a pitch flow (apart from FR-019).
- **FR-021**: The existing per-stay cap on unsolicited pitches MUST keep applying to first
  pitches of a flow; the one allowed retry does not count toward it.
- **FR-022**: Each blocked turn MUST record which retry rule blocked it: retry already used,
  retry window passed, or declined activity.
- **FR-023**: A declined proactive pitch MUST count exactly like a declined contextual
  pitch.

**Proactive messages (SPEC-073)**

- **FR-024**: Proactive messaging MUST be switched off by default for every hotel, and only
  a hotel admin or super admin can switch it on or change its settings.
- **FR-025**: The system MUST support three triggers, each enabled per hotel:
  - *Stay milestone*: the first morning after check-in, and the middle of stays of four or
    more nights. A milestone message offers an approved recommendation if one is offerable,
    and is skipped otherwise.
  - *Upcoming activity*: a reminder of the guest's confirmed booking, sent a set lead time
    before it starts (default: the evening before for morning activities, otherwise 4
    hours before). It is informational, not a pitch.
  - *Approved recommendation opportunity*: a recommendation was approved for an in-house
    guest who has not yet been pitched this stay.
- **FR-026**: Before sending, every proactive message MUST pass the same eligibility rules
  as a contextual pitch (stay in house, not departing today, no escalation this stay, no
  open complaint, pitching enabled), plus the decline-retry rule for pitches. Reminders
  need only an in-house or arriving stay and a still-confirmed booking.
- **FR-027**: No proactive message may be sent during the hotel's quiet hours (default
  21:00–09:00 hotel local time, configurable per hotel). A message due in quiet hours MUST
  be deferred to the end of quiet hours if still valid, otherwise skipped.
- **FR-028**: Frequency caps MUST apply per guest: at most one proactive message per
  hotel-local day (configurable per hotel), and proactive pitches count toward the per-stay
  cap on unsolicited pitches. A reminder takes precedence over a pitch on the same day.
- **FR-029**: Each trigger MUST produce at most one message per guest, reservation and event
  (milestone, booking or recommendation), however often it fires or is retried.
- **FR-030**: Proactive messages MUST follow WhatsApp messaging rules. A free-form proactive
  message MUST NOT be sent outside the messaging window. For a guest outside the window,
  the proactive message MUST be skipped and recorded with the reason "outside messaging
  window". No WhatsApp template and no email is used instead, for pitches and reminders
  alike. A trigger skipped this way is not deferred: a later guest message does not revive
  it.
- **FR-031**: A proactive message MUST NOT be sent while the guest wrote within the last 30
  minutes; it is deferred instead.
- **FR-032**: Proactive messages MUST be built from fixed message texts, one per trigger
  per supported language, with placeholders filled from the hotel's records (guest first
  name, hotel name, activity name and short description, date, time, meeting point). No AI
  writes or rewrites them. The text is chosen in the guest's language, falling back to
  English. Each message MUST be stored in the guest's conversation like any Concierge
  message, so a reply continues the same conversation with the Concierge.
- **FR-032a**: A placeholder whose value is missing (for example, no meeting point) MUST be
  left out cleanly using a variant of the text without it. A required value that is missing
  (activity name, date or time) MUST skip the message with the reason recorded.
- **FR-033**: Every trigger evaluation that ends in send, defer or skip MUST be recorded
  with the trigger, guest, stay, target, result and reason, visible to hotel admins.
- **FR-034**: Sending a proactive message MUST NOT call an AI model. Proactive messages
  sent MUST be counted per hotel in usage reporting. AI usage when the guest replies is
  measured as for any guest turn.

**Opt-out**

- **FR-035**: A guest MUST be able to opt out of proactive messages by asking the Concierge
  in their own words or with a standard keyword (STOP and its equivalents in supported
  languages). The opt-out takes effect before any further proactive message or unsolicited
  pitch and is confirmed once.
- **FR-035a**: An opted-out guest MUST receive no unsolicited pitches, contextual or
  proactive. Each blocked turn records the opt-out as the reason. Explicit requests for
  suggestions are still answered from approved recommendations.
- **FR-036**: Opt-out MUST apply to the guest across all their reservations at the hotel
  until they ask to resume. Staff with guest-update permission MAY record a resume at the
  guest's request; every opt-out and resume is audited.
- **FR-037**: Opt-out MUST NOT affect replies to the guest's own messages or the
  request-completion notices of feature 007.

**Measurement**

- **FR-038**: Outcome tracking and conversion attribution MUST keep working unchanged, and
  MUST record whether a pitch was contextual, explicit or proactive, and which trigger
  caused a proactive one.

**Isolation and authorization**

- **FR-039**: Approval queue entries, decisions, proactive records and opt-out state MUST be
  hotel-scoped; another hotel's users MUST NOT see or change them.
- **FR-040**: The new permission MUST be listed by the permissions endpoint and documented
  in the permission reference.

### Key Entities

- **Recommendation** (existing, extended): gains the approval statuses and the approval
  record — who approved or rejected it, when, why, and where it came from (staff request,
  Admin AI, Recommendation agent, in-conversation generation).
- **Approval decision**: the audit trail of each approve/reject, kept in the existing audit
  log rather than a new store.
- **Pitch decision** (existing, extended): now also records which retry rule blocked a turn
  and whether the pitch was proactive.
- **Pitch flow**: derived, not stored — the first unsolicited pitch of a stay, its decline,
  and the optional retry, computed from pitch decisions and recommendation outcomes.
- **Proactive message record**: one per guest, stay, trigger and event — trigger type,
  target (booking, recommendation or milestone), status (sent, deferred, skipped, failed),
  reason, scheduled and sent times, link to the conversation message.
- **Hotel proactive settings**: on/off, enabled triggers, quiet hours, daily cap, reminder
  lead time.
- **Proactive message text**: the fixed wording for each trigger in each supported
  language, with named placeholders. The platform provides it; it is not AI-written.
- **Guest contact preference**: opted out or not, since when, how (guest message or staff),
  and the last change.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 recommendations reach a guest (contextual, explicit or proactive) without
  having been approved, across the full automated test suite and in pilot audit samples.
- **SC-002**: 100% of approval decisions appear in the audit log with actor and reason.
- **SC-003**: An approver can review and decide 20 pending recommendations in under 5
  minutes using filters and bulk actions.
- **SC-004**: No guest receives more than two unsolicited pitches in a pitch flow that
  contains a decline, and none after the flow closes.
- **SC-005**: 0 proactive messages are sent during quiet hours, to opted-out guests, to
  guests outside the allowed channel rules, or twice for the same event.
- **SC-006**: Opt-out takes effect for 100% of guests before their next scheduled proactive
  message.
- **SC-007**: Every proactive trigger evaluation has a recorded result and reason that a
  hotel admin can read.
- **SC-008**: Once enabled at a pilot hotel, proactive pitches produce a measurable accept
  rate in the existing outcome reports within the first week, with no increase in guest
  escalations attributed to outreach.

## Assumptions

- Status names are final here: *pending approval*, *approved*, *rejected by admin*. The
  existing *pending* state ("waiting to be offered") is replaced by *approved*.
- One permission ("approve recommendations") covers both approve and reject; bulk actions
  use it too. It is not in the employee defaults.
- Approval is per recommendation, not per reservation or per activity. A hotel-wide
  auto-approval rule is out of scope.
- No notification is sent to admins when new recommendations await approval in this
  feature; the queue and its count are the signal.
- The existing per-stay cap on unsolicited pitches (default 1) and the existing gates
  (escalation, open complaint, departure day, pre-arrival off) are kept unchanged.
- "Different activity" means a different activity record, not only a different
  recommendation for the same activity.
- Proactive message texts are provided by the platform in the supported languages (English
  and Arabic). Hotels cannot edit them in this feature.
- Proactive messages go to the reservation's primary guest only, through the shared
  platform WhatsApp number (D12).
- Opt-out is per guest per hotel. Guests in other hotels sharing the same number are
  unaffected.
- Upcoming-activity reminders are governed by proactive settings and opt-out, but not by
  pitching rules or the pitch cap.
- Outcome tracking and conversion attribution are reused as they are (plan Phase 10, item
  5); only the source of a pitch is added.
- Frontend slice (in `ecosystem-frontend`): approval queue with bulk actions,
  recommendation status filters, proactive settings on the hotel settings page, opt-out
  indicator on the guest page, and proactive message log.
- Depends on Phase 7 (Concierge on the new domain, messaging-window handling) and Phase 6
  (bookings), both complete.
