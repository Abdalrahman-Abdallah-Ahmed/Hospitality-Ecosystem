# Feature Specification: Activity Availability and Booking Workflow

**Feature Branch**: `006-activity-bookings`

**Created**: 2026-10-05

**Status**: Draft

**Input**: Phase 6 — Activities + Bookings, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's two backlog
specs as one feature: SPEC-041 Activity Availability & Capacity, and SPEC-043 Booking Staff
Workflow & Cancellation Requests.

## Overview

Activities already describe when they can be done: a season (available from / until),
opening times per weekday, closure periods, how many days they take and how many people
they can take per day. Nothing checks any of it. A booking can be taken for a closed day,
outside opening hours, or for the hundredth guest on a boat that seats twelve, whether it
comes from the front desk or from the WhatsApp Concierge. Staff can move a booking through
its statuses but cannot correct its date or party size, and a guest who wants to cancel has
no path except asking the Concierge, which has no way to pass it on.

This feature makes bookings respect the activity's real availability and gives staff the
workflow around them:

- **Availability at booking time.** Every booking for a catalogue activity is checked
  against the season, opening times, closure periods and remaining capacity, on every path:
  staff, Admin AI and Guest Concierge.
- **Availability lookup.** Staff and the Concierge can ask what is free for an activity on
  a given date before offering it.
- **Staff workflow.** Staff confirm, edit (date, time, party size, notes) and cancel
  bookings, with each change re-checked against availability where it matters.
- **Reservation link.** A booking can be tied to a guest's reservation before they arrive,
  not only to a stay after check-in.
- **Guest cancellation requests.** When a guest asks to cancel, the Concierge creates a
  request for staff. It never cancels the booking itself. Staff act on the request from a
  queue.

**Terms**:

- *Catalogue booking*: a booking linked to an activity in the hotel's catalogue. A
  *free-text booking* names something not in the catalogue and is not availability-checked.
- *Season*: the activity's available-from / available-until dates.
- *Opening times*: the time windows the activity runs on each weekday, in hotel local time.
  A weekday with no window is closed.
- *Closure period*: a date range when the activity is closed (for example maintenance).
- *Daily capacity*: the most people the activity can take on one date. Empty means
  unlimited.
- *Booked load*: the people (party size) of all bookings for that activity and date that
  still hold a place: pending, confirmed and realised. Cancelled and no-show bookings free
  their places.
- *Remaining capacity*: daily capacity minus booked load.
- *Cancellation request*: a guest's request to cancel a booking, held as a staff task linked
  to that booking until staff decide.
- *Hotel day*: the date in the hotel's local time.

**In scope**: availability rules for catalogue bookings (season, weekday opening times,
closure periods, daily capacity, multi-day activities); an availability lookup per activity
and date; enforcement on staff, Admin AI and Guest Concierge booking paths; staff confirm,
edit and cancel; an explicit, permission-gated capacity override for staff; linking a
booking to a reservation; Guest Concierge cancellation requests and the staff queue;
booking list and calendar data filtered by activity and date; permissions, audit, tenant
isolation and documentation.

**Out of scope (later specs)**: notifying the guest on WhatsApp when staff act on their
cancellation request, and the 24-hour messaging window (Phase 7, SPEC-044 / SPEC-053).
Concierge read tools for the guest's own bookings and the full guest action tool set
(Phase 7, SPEC-052 / SPEC-053). Admin AI booking tools beyond the availability check
(Phase 9, SPEC-055). Recommendation approval and pitching rules (Phase 10). Payments,
deposits, cancellation fees and refunds (D11, finance flag). Waitlists, resource or
guide scheduling, dynamic pricing and per-age pricing (post-MVP).

## Clarifications

### Session 2026-10-05

- Q: Should activity capacity be counted per day, or separately for each time slot in the
  day? → A: Per day. A booking must start inside an opening window, but all windows on a
  date share the one daily capacity. Per-slot capacity and fixed bookable slots are
  post-MVP.
- Q: Should a pending booking, one staff haven't confirmed yet, use up places straight
  away? → A: Yes. Pending bookings hold places from creation, like confirmed ones, and are
  never released automatically; staff free them by cancelling.
- Q: Should authorized staff be able to book an activity past its daily capacity on
  purpose? → A: Yes, through a separate override permission that employees without a role
  never get. The override must be requested explicitly, is audited, covers capacity only
  (never closed days), and is never available to AI.
- Q: Who should receive a guest's cancellation request when the Concierge creates it? →
  A: It is created as a hotel task (no team) and the hotel's admins are emailed about it.
  Staff allowed to change booking statuses act on it from the queue.
- Q: Which dates should the Concierge be allowed to book activities on for a guest? → A:
  Any date from today up to the departure date of the guest's current or upcoming
  reservation in this hotel, so booking before arrival is allowed.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A booking is refused when the activity is not available (Priority: P1)

A front-desk agent books a guest onto the sunset cruise. The system checks the chosen date
and time against the cruise's season, opening times, closure periods and remaining places,
and only saves the booking if all of them pass. If one fails, the agent sees which rule
failed and can pick another date or time.

**Why this priority**: Overselling an activity is the failure the guest feels directly and
the one that costs staff the most to fix. Every other story depends on this check.

**Independent Test**: Create an activity with a season, weekday opening times, a closure
period and a daily capacity of 10. Try bookings that break each rule and one that passes.
Only the valid one is saved.

**Acceptance Scenarios**:

1. **Given** an activity open Mondays 17:00–19:00 with capacity 10 and 6 places booked for
   next Monday, **When** staff book 4 people for next Monday at 17:30, **Then** the booking
   is saved and next Monday shows 0 places left.
2. **Given** the same activity with 0 places left next Monday, **When** staff book 1 more
   person for that date, **Then** the booking is refused with a "fully booked" reason and
   the remaining capacity (0).
3. **Given** an activity closed on Tuesdays, **When** staff book it for a Tuesday, **Then**
   the booking is refused with a "closed that day" reason.
4. **Given** an activity open 17:00–19:00, **When** staff book it at 20:00, **Then** the
   booking is refused with an "outside opening times" reason listing that day's windows.
5. **Given** an activity whose season ends on 31 October, **When** staff book it for
   2 November, **Then** the booking is refused with an "outside season" reason.
6. **Given** a closure period from 10 to 12 November, **When** staff book 11 November,
   **Then** the booking is refused with a "closed" reason that includes the closure reason
   if one was recorded.
7. **Given** an activity with no daily capacity set, **When** any number of people are
   booked on an open day, **Then** capacity never refuses the booking.
8. **Given** an activity that is inactive, **When** anyone books it, **Then** the booking is
   refused with an "activity not offered" reason.
9. **Given** a booking for something not in the catalogue (free text only), **When** staff
   save it, **Then** no availability rule is applied.

---

### User Story 2 - Staff and the Concierge see what is free before offering it (Priority: P1)

Before suggesting the cruise, a staff member or the Concierge asks what is free for that
activity on a date. They get back whether it is open, the opening windows for that day,
and how many places remain, so they never offer something that will then be refused.

**Why this priority**: The Concierge must use live availability instead of guessing
(constitution: live data comes from tools). It is also the data behind the booking
calendar.

**Independent Test**: Request availability for an activity across a range of dates that
covers an open day, a closed weekday, a closure period, an out-of-season date and a fully
booked date. Each date reports the right state and remaining places.

**Acceptance Scenarios**:

1. **Given** an activity with capacity 12 and 5 people booked on a date, **When** staff
   request its availability for that date, **Then** they see "open", that day's windows,
   capacity 12, booked 5, remaining 7.
2. **Given** a date range of up to 31 days, **When** staff request availability for the
   range, **Then** each date in the range is reported with its own state.
3. **Given** a guest asks the Concierge "is the cruise free on Friday?", **When** the
   Concierge checks, **Then** its answer comes from the availability lookup for that hotel
   and date, and states remaining places only when the activity has a capacity.
4. **Given** a date that is closed, **When** availability is requested, **Then** the
   response gives the reason (closed weekday, closure period, out of season, inactive).

---

### User Story 3 - The Concierge books only what is available (Priority: P1)

A guest on WhatsApp agrees to the cruise for four people on Friday. The Concierge books it
through the same availability check staff use. If Friday is full, it does not save a
booking; it tells the guest and offers the nearest open dates instead.

**Why this priority**: Phase 6 explicitly requires the Concierge's activity and booking
actions to follow the availability checks, and the MVP gate (step 7) depends on it.

**Independent Test**: Run the Concierge's booking action against a full date, a closed
date and an open date. Only the open date produces a booking; the others return the
reason and alternatives.

**Acceptance Scenarios**:

1. **Given** an activity with places left on Friday, **When** the Concierge books it for
   the guest, **Then** a pending booking is saved for that guest, linked to their current
   reservation and to their stay if they are checked in.
2. **Given** Friday is full, **When** the Concierge tries to book it, **Then** no booking is
   saved and the Concierge receives the reason plus up to 3 nearest dates with enough
   places for the party within the next 14 days.
3. **Given** the guest's reservation ends on Thursday, **When** the Concierge tries to book
   Friday, **Then** it is refused because the date is outside the guest's reservation.
4. **Given** the Concierge sends the same booking twice for the same guest, activity, date
   and time within a short window (a retried message), **Then** only one booking exists.
5. **Given** the Concierge passes an activity from another hotel, **When** it books,
   **Then** the booking is refused and nothing is saved.
6. **Given** a guest with no reservation in this hotel, **When** the Concierge tries to
   book, **Then** it is refused (no privileged action for unidentified guests).

---

### User Story 4 - Staff confirm, edit and cancel bookings (Priority: P1)

The activities desk works through incoming bookings. They confirm pending ones, move a
booking to another date or change the party size when the guest asks, and cancel with a
reason when needed. Changing the date, time or party size is re-checked against
availability; cancelling frees the places immediately.

**Why this priority**: The booking is an operational commitment; staff must be able to
keep it accurate. Without editing, staff cancel and re-create bookings, which breaks the
link to the recommendation that produced them.

**Independent Test**: Confirm a pending booking, change its party size within and beyond
remaining capacity, move it to a closed day, cancel it, and check remaining capacity after
each step.

**Acceptance Scenarios**:

1. **Given** a pending booking, **When** staff confirm it, **Then** it becomes confirmed
   and the confirmation time is recorded.
2. **Given** a confirmed booking for 2 people on a date with 1 place left, **When** staff
   raise it to 3 people, **Then** the change is saved (the booking's own 2 places count as
   available to itself) and remaining capacity becomes 0.
3. **Given** the same booking, **When** staff raise it to 4 people, **Then** the change is
   refused as over capacity and the booking is unchanged.
4. **Given** a booking, **When** staff move it to a date when the activity is closed,
   **Then** the change is refused with the reason.
5. **Given** a booking, **When** staff change only its notes, **Then** no availability
   check is run.
6. **Given** a confirmed booking for 4 people, **When** staff cancel it with a reason,
   **Then** it becomes cancelled, the reason and time are recorded, and 4 places are free
   again on that date.
7. **Given** a cancelled, realised or no-show booking, **When** staff try to edit its date,
   time or party size, **Then** the edit is refused.
8. **Given** any change, **Then** an audit entry records who made it and what changed.

---

### User Story 5 - A guest asks to cancel and staff decide (Priority: P1)

A guest messages "please cancel my cruise tomorrow". The Concierge finds the guest's
booking and creates a cancellation request for staff, telling the guest the request has
been passed on. The activities desk sees it in the cancellation-request queue, cancels the
booking or declines the request with a note.

**Why this priority**: The constitution forbids the Concierge from cancelling bookings
directly in MVP; this is the only route a guest has to cancel.

**Independent Test**: Through the Concierge's cancellation action, request cancellation of
the guest's own booking; check a request appears in the queue and the booking is still
active. Then cancel it as staff and check the request closes.

**Acceptance Scenarios**:

1. **Given** a guest with a confirmed booking, **When** they ask the Concierge to cancel
   it, **Then** a cancellation request is created for staff, linked to the booking and the
   guest, carrying the guest's stated reason, and the booking status does not change.
2. **Given** an open cancellation request for a booking, **When** the guest asks again,
   **Then** no second request is created and the Concierge is told one is already open.
3. **Given** a booking belonging to another guest, **When** the Concierge requests its
   cancellation, **Then** the request is refused.
4. **Given** a booking that is already cancelled, realised or no-show, **When** a
   cancellation is requested, **Then** it is refused with the booking's status.
5. **Given** an open cancellation request, **When** staff cancel the booking (from the
   request or from the booking), **Then** the request is closed as approved.
6. **Given** an open cancellation request, **When** staff decline it with a note, **Then**
   the request is closed as declined and the booking stays as it was.
7. **Given** a new cancellation request, **Then** each admin of the hotel receives one
   email about it, and the booking shows that a cancellation has been requested.
8. **Given** the Concierge has no cancel action at all, **When** a guest insists on an
   immediate cancellation, **Then** the only outcome available is a request.

---

### User Story 6 - Booking list, calendar and cancellation queue (Priority: P2)

The activities manager opens a calendar view: bookings by activity and date, with each
date's booked load against capacity. A separate queue lists open cancellation requests,
oldest first.

**Why this priority**: The frontend slice for this phase. The data mostly exists; it
needs filters by activity and date range and the capacity figures alongside.

**Independent Test**: Seed bookings across activities and dates; filter by one activity
and a week; check the list contains only those bookings and the per-date totals match.

**Acceptance Scenarios**:

1. **Given** bookings for several activities, **When** staff list bookings filtered by an
   activity and a date range, **Then** only matching bookings are returned, ordered by
   scheduled time.
2. **Given** the same filter, **When** staff request the activity's availability for the
   range, **Then** each date's booked load equals the sum of party sizes of that date's
   pending, confirmed and realised bookings.
3. **Given** open and closed cancellation requests, **When** staff open the queue, **Then**
   only open ones are listed, oldest first, each with guest, booking, activity, date and
   reason.
4. **Given** a user in another hotel, **When** they list bookings or the queue, **Then**
   they see none of this hotel's records.

---

### User Story 7 - Authorized staff override capacity on purpose (Priority: P3)

A manager knows the boat can take one more person today and books them anyway. They must
say so explicitly and must hold the permission to do it. The Concierge can never do this.

**Why this priority**: Real operations need a controlled exception, mirroring room
overbooking. Without it, staff raise the capacity for one day and forget to lower it.

**Independent Test**: As an employee without the override permission, book past capacity
with the override flag and get refused; with the permission, the same request succeeds
and is audited as an override.

**Acceptance Scenarios**:

1. **Given** a full date, **When** a staff member holding the override permission books
   with an explicit override, **Then** the booking is saved and the audit entry marks it
   as a capacity override.
2. **Given** a full date, **When** a staff member without the permission sends an
   override, **Then** the request is refused as forbidden.
3. **Given** a closed day or out-of-season date, **When** anyone sends an override,
   **Then** the booking is still refused: override applies to capacity only.
4. **Given** any AI path, **Then** override is never available.

---

### Edge Cases

- **Multi-day activities**: an activity that takes N days needs every day from the start
  date through day N to be open and to have enough places; the party counts against
  capacity on each of those days.
- **Overnight windows**: an opening window cannot cross midnight (current validation
  requires end after start), so a booking time belongs to one weekday.
- **Booking with no time**: a catalogue booking always needs a date. A time is required
  when the activity has opening windows on that weekday and must fall inside one; an
  activity with no opening times set is open all day and takes a date only.
- **Time inside a window**: the start time must be at or after the window start and before
  its end. Activity length within the day is not modeled, so a start at 18:55 in a
  17:00–19:00 window is accepted.
- **Past dates**: bookings for a date before the current hotel day are refused for new
  bookings and date changes. Staff can still record status (realised, no-show) on past
  bookings.
- **Concurrent bookings for the last places**: two bookings racing for the last place
  never both succeed; one is refused as over capacity.
- **Capacity lowered after bookings exist**: existing bookings are kept; the date simply
  shows a negative or zero remaining figure and refuses new bookings until it recovers.
- **Activity rule changes** (closure added over booked dates, season shortened): existing
  bookings are not cancelled automatically. The availability lookup flags dates that
  have bookings while closed, so staff can contact the guests.
- **Activity deactivated or deleted**: existing bookings remain visible and can be
  confirmed, realised or cancelled; no new bookings or date moves.
- **Party size**: must be at least 1. A party larger than the daily capacity is refused
  with a message saying it can never fit, not "fully booked".
- **Reservation and stay consistency**: a booking's reservation, stay and guest must all
  belong to the same hotel, and the stay (if any) must belong to the linked reservation.
- **Cancellation request on a booking that staff then edit**: the request stays open and
  shows the booking's current details.
- **Booking deleted while a request is open**: bookings are never deleted (only
  cancelled), so this cannot happen.
- **Hotel timezone**: all dates, weekdays, windows and the "past" check use the hotel's
  local time.

## Requirements *(mandatory)*

### Functional Requirements

**Availability rules**

- **FR-001**: The system MUST check every new catalogue booking, and every change to a
  catalogue booking's activity, date, time or party size, against the activity's
  availability before saving it. Free-text bookings are not checked.
- **FR-002**: A date MUST be refused when the activity is inactive, the date is before the
  season starts or after it ends, the date falls inside any closure period, or the weekday
  has no opening window while opening times are set.
- **FR-003**: When the activity has opening windows on the booking's weekday, the booking
  MUST have a time, and that time MUST be at or after a window's start and before its end.
  Windows do not carry their own capacity; all windows on a date share the daily capacity.
- **FR-004**: When the activity has a daily capacity, the booking MUST be refused if its
  party size plus the booked load on that date exceeds the capacity. A booking being
  edited MUST NOT count against itself.
- **FR-005**: Booked load MUST count the party sizes of pending, confirmed and realised
  bookings. Cancelled and no-show bookings MUST NOT count. A pending booking holds its
  places from creation and MUST NOT be released automatically.
- **FR-006**: For an activity that takes more than one day, FR-002 and FR-004 MUST hold
  for every day the activity covers, and the party MUST count against each of those days.
- **FR-007**: New bookings and date changes for a date before the current hotel day MUST
  be refused.
- **FR-008**: An activity with no daily capacity MUST never be refused for capacity.
- **FR-009**: Concurrent bookings for the same activity and date MUST NOT together exceed
  capacity; the check and the save MUST happen as one atomic operation.
- **FR-010**: A refusal MUST state which rule failed (inactive, out of season, closed
  period with its reason, closed weekday, outside opening times with that day's windows,
  fully booked with remaining places, party larger than capacity), in a form both staff
  screens and the Concierge can relay.

**Availability lookup**

- **FR-011**: Staff with permission to view activities MUST be able to request an
  activity's availability for one date or a date range of up to 31 days, getting for each
  date: open or closed, the reason when closed, that day's opening windows, capacity,
  booked load and remaining places.
- **FR-012**: The lookup MUST flag dates that are closed but still have active bookings.
- **FR-013**: The lookup MUST use the same rules as the booking check (FR-002 to FR-008),
  so a date reported open with N places accepts a booking of N people.

**Booking paths**

- **FR-014**: Staff, Admin AI and Guest Concierge booking paths MUST all go through the same
  availability check; none MAY skip it.
- **FR-015**: The Concierge's activity listing MUST be able to report availability for a
  requested date or date range, and its booking action MUST refuse unavailable dates and
  return up to 3 nearest dates within the next 14 days that fit the party.
- **FR-016**: The Concierge MUST only book for the identified guest, only activities of
  this hotel, and only for a date from the current hotel day up to the departure date of
  that guest's current or upcoming reservation in this hotel. Booking before arrival is
  allowed.
- **FR-017**: A repeated Concierge booking for the same guest, activity, date, time and
  party within 10 minutes MUST return the existing booking instead of creating another.
- **FR-018**: A booking MUST be linkable to a reservation. The Concierge MUST link it to the
  guest's current or upcoming reservation, and to the stay when the guest is checked in.
  Staff MAY link either. Guest, reservation and stay MUST belong to the same hotel, and a
  linked stay MUST belong to the linked reservation.

**Staff workflow**

- **FR-019**: Staff MUST be able to confirm, cancel (with a required reason), mark realised
  and mark no-show, keeping the existing forward-only status rules.
- **FR-020**: Staff MUST be able to edit a pending or confirmed booking's date, time, party
  size and notes. Edits to cancelled, realised or no-show bookings MUST be refused.
- **FR-021**: Editing MUST NOT change the booking's guest, recommendation link, origin,
  reference or creator. Staff MAY change its activity, which is checked for availability
  like a date change.
- **FR-022**: Staff holding the capacity-override permission MUST be able to save a booking
  or edit that exceeds capacity by sending an explicit override. Override MUST NOT bypass
  any other rule and MUST be recorded in the audit entry. AI paths MUST NOT override.

**Cancellation requests**

- **FR-023**: The Guest Concierge MUST NOT be able to cancel a booking. It MUST be able to
  create a cancellation request for a pending or confirmed booking belonging to the
  identified guest, with the guest's reason.
- **FR-024**: At most one open cancellation request MAY exist per booking; a repeat request
  MUST return the open one.
- **FR-025**: A cancellation request MUST be created as a hotel task, not assigned to a
  team, linked to the booking and guest and visible in the cancellation-request queue. Its
  creation MUST send one email to each admin of the hotel; a repeated request (FR-024) MUST
  NOT send another.
- **FR-026**: Cancelling the booking MUST close any open cancellation request as approved.
  Staff MUST be able to decline a request with a note, which closes it and leaves the
  booking unchanged.
- **FR-027**: A booking MUST show whether it has an open cancellation request.

**Listing and calendar**

- **FR-028**: The booking list MUST filter by activity, status, guest, reservation and a
  scheduled date range, sorted by scheduled time.
- **FR-029**: The cancellation-request queue MUST list open requests oldest first with
  guest, booking reference, activity, scheduled date and reason.

**Authorization, tenancy and audit**

- **FR-030**: Viewing availability MUST require the activity view permission. Editing
  booking details MUST require a new booking update permission. Overriding capacity MUST
  require a new, separate override permission that employees without a role never get.
  Acting on a cancellation request MUST require the booking status permission.
- **FR-031**: All bookings, availability figures and cancellation requests MUST be scoped
  to the hotel. Another hotel's users and the Concierge serving another hotel MUST NOT see
  or affect them.
- **FR-032**: Every booking create, edit, status change, override and cancellation request
  (created, approved, declined) MUST be audited with the actor, including the AI actor for
  Concierge actions.
- **FR-033**: No step in this feature MAY write to the transaction ledger while the finance
  flag is off.

### Key Entities

- **Activity** (existing): the bookable item. Uses its existing season, weekday opening
  windows, closure periods, duration in days, daily capacity, audience and active flag.
  No new attributes are required.
- **Booking** (existing): the commitment. Gains a link to a reservation and editable notes;
  keeps guest, stay, activity, recommendation, status, scheduled time, party size, origin,
  channel and reference.
- **Activity availability** (derived, not stored): for an activity and date — open/closed,
  reason, windows, capacity, booked load, remaining places, and whether closed dates carry
  bookings.
- **Cancellation request**: a staff task linked to one booking and its guest, with the
  guest's reason, an open/approved/declined outcome, the deciding staff member and an
  optional note.
- **Reservation / Stay / Guest** (existing): define whose booking it is and the dates the
  Concierge may book within.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Across the automated test scenarios, 0 bookings are saved that break any
  availability rule without an audited staff override.
- **SC-002**: When two or more bookings compete for the last places on a date, the total
  booked never exceeds capacity in 100% of concurrent test runs.
- **SC-003**: For every date the availability lookup reports open with N places, a booking
  of N people succeeds and a booking of N+1 is refused (lookup and check never disagree).
- **SC-004**: 100% of Concierge cancellation attempts result in a staff request and 0 in
  a cancelled booking.
- **SC-005**: A staff member can find and act on a guest's cancellation request from the
  queue in under 1 minute.
- **SC-006**: When a requested date is full, the Concierge offers at least one available
  alternative whenever one exists in the next 14 days.
- **SC-007**: Every booking change and cancellation request appears in the audit log with
  its actor; another hotel sees none of them.

## Assumptions

- Hotels can later route cancellation-request tasks to a team through the task category;
  no default team is created for them.
- Staff may book any future date, including for guests without a reservation
  (walk-ups).
- **Audience** (adults only, family, etc.) remains a pitching attribute and is not enforced
  at booking time in this feature.
- **Guest notification** when staff approve or decline a request is delivered in Phase 7;
  here, the outcome is recorded on the request so Phase 7 can send it.
- Existing booking statuses, forward-only transitions, metering, recommendation crediting
  and the "booking status never depends on payment" rule are kept unchanged.
- Phases 1–4 (room types, reservation rooms, stays and check-in/out) have shipped, so
  reservation and stay links are reliable.
