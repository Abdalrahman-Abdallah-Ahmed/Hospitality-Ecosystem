# Feature Specification: Stay Lifecycle and Check-in/out

**Feature Branch**: `004-stay-check-in-out`

**Created**: 2026-09-24

**Status**: Draft

**Input**: Phase 4 — Stay Lifecycle + Check-in/out, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's three
backlog specs as one feature: SPEC-023 Stay per Room, SPEC-024 Check-in, SPEC-025
Check-out.

## Overview

A reservation can now hold several room lines (SPEC-010), but the hotel still keeps only
one stay per reservation, and the only way to check a guest in or out is to change the
reservation's status in the general reservation edit. Nothing checks that the guest has a
room, nothing marks the room occupied, and nothing tells housekeeping that a room needs
cleaning after the guest leaves.

This feature makes the stay the record of a guest being physically in one room:

- **One stay per reservation line.** A reservation with 2 Deluxe rooms has 2 stays.
- **Check-in** for one room or the whole reservation. It checks that the guest can move
  in, then marks the stay in-house and the room occupied.
- **Check-out** for one room or the whole reservation. It marks the stay departed and the
  room available but dirty, and creates a cleaning task for housekeeping.
- Staff and the Admin AI both go through these actions. Changing the status in the general
  reservation edit is no longer the way to check guests in or out.

**Terms**:

- A *line* is one booked room unit on a reservation (SPEC-010).
- A *stay* is one line's guest presence. Its states are *expected* (not arrived yet),
  *in-house*, *departed*, *no-show* and *cancelled*.
- The *hotel day* is the date in the hotel's local time.
- *Arrivals* for a day are expected stays on live lines of pending, confirmed or
  checked-in reservations whose arrival date is that day or earlier. One whose departure
  date is also that day or earlier is shown as *past departure* so staff correct its
  dates or cancel it.
  *Departures* for a day are in-house stays whose departure date is that day or earlier.

**In scope**: one stay per line and moving existing stays to that model; check-in and
check-out per room and per reservation; the room and reservation status changes they
cause; the cleaning task on check-out; linking tasks to stays; arrival, departure and
in-house lists; Admin AI check-in and check-out tools; permissions, audit and
documentation; retiring check-in/out through the general reservation edit.

**Out of scope (later specs)**: assigning rooms ahead of arrival and reassigning them
(SPEC-021); this feature only assigns a room to an unassigned line at the moment of
check-in (FR-007a). The `no_show` reservation status and who may set it (SPEC-012).
Housekeeping status after "dirty" (cleaning → clean → inspected) and how cleaning tasks
move it (SPEC-030). Out-of-order periods and maintenance (SPEC-033). Guest self check-in
through the Concierge. Payments, folios, deposits, keys and ID capture (post-MVP).

## Clarifications

### Session 2026-09-24

- Q: When does a multi-room reservation become checked in? → A: When its first room checks in.
- Q: Can a guest be checked into a room housekeeping has not marked clean? → A: Yes, with a warning in the response; no block, no override.
- Q: Is undoing a mistaken check-in or check-out in scope? → A: No; a manager corrects it, and undo actions are left for a later spec.
- Q: Can staff pick the room at check-in when the line has none assigned? → A: Yes; check-in may name a room for an unassigned line, which is assigned (same type and conflict checks as assignment) and checked in as one action. It cannot replace an already-assigned room.
- Q: What happens when the last in-house room checks out while another line never arrived (still expected)? → A: That check-out is rejected until the remaining expected lines are cancelled; the message lists them.
- Q: Should setting checked_in/checked_out through the general reservation edit still be accepted in this release? → A: Yes; it runs as a whole-reservation check-in/out with the same checks and a deprecation notice, and a later release rejects it.
- Q: Can staff give an earlier actual time when recording a check-in or check-out late? → A: Yes, within the same hotel day only; never in the future or before check-in; the audit records both the given time and when it was entered.
- Q: Should employees without a staff role see stays and the front-desk lists by default? → A: No; `stays.view`, `stays.check_in` and `stays.check_out` are all off by default.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Front desk checks a guest into their room (Priority: P1)

A guest with a confirmed reservation for one Deluxe room arrives. Room 204 was assigned
earlier. The receptionist finds the guest in today's arrivals and checks them in. The
guest's stay becomes in-house, room 204 shows as occupied, the reservation shows as
checked in, and the check-in is recorded with who did it and when.

**Why this priority**: Check-in is the moment a booking becomes a guest in the house.
Occupancy, guest services and the Concierge all depend on knowing who is in which room.

**Independent Test**: Create a confirmed reservation with one line and an assigned room
arriving today. Check it in. The stay is in-house with a check-in time, the room is
occupied, the reservation is checked in, and there is one audit entry naming the actor.

**Acceptance Scenarios**:

1. **Given** a confirmed reservation arriving today with one line assigned to an available
   room, **When** staff check in that line, **Then** the stay becomes in-house with the
   current time as check-in time, the room becomes occupied, and the reservation becomes
   checked in.
2. **Given** a line with no room assigned, **When** staff try to check it in without
   naming a room, **Then** it is rejected with a message that a room must be assigned or
   named, and nothing changes. **When** they name an available room of the line's type
   with no overlapping reservation, **Then** the room is assigned and the line checked in
   as one action. **When** the named room fails an assignment check, **Then** nothing
   changes and the message gives the reason.
   **Given** a line that already has a room assigned, **When** staff name a different
   room at check-in, **Then** it is rejected; changing the room is a reassignment
   (SPEC-021), done before check-in.
3. **Given** a line whose room is out of order, **When** staff try to check it in, **Then**
   it is rejected with a message naming the room and its status.
4. **Given** a line whose room already has another in-house stay, **When** staff try to
   check it in, **Then** it is rejected with a message that the room is occupied.
5. **Given** a reservation that is pending, cancelled or checked out, or a cancelled line,
   **When** staff try to check in, **Then** it is rejected with a message naming the
   reason.
6. **Given** a reservation arriving tomorrow, **When** staff try to check it in today,
   **Then** it is rejected because the arrival date has not come yet.
7. **Given** a reservation that arrived yesterday and was not checked in (a late arrival),
   **When** staff check it in before its departure date, **Then** it is accepted.
8. **Given** a line that is already in-house, **When** staff check it in again, **Then**
   nothing changes, the original check-in time is kept, no second audit entry is written,
   and the response shows the current state.
9. **Given** two staff members check in the same line at the same moment, **When** both
   requests finish, **Then** the line is checked in once, with one audit entry.
10. **Given** a line whose room is not clean, **When** staff check it in, **Then**
    the check-in succeeds and the response carries a warning naming the room's
    housekeeping status.

---

### User Story 2 - Front desk checks a guest out (Priority: P1)

The guest in room 204 leaves. The receptionist finds them in today's departures and checks
them out. The stay becomes departed with the check-out time and the real number of nights.
Room 204 becomes available and dirty, and a cleaning task for room 204 appears for the
Housekeeping team. The reservation becomes checked out.

**Why this priority**: Check-out frees the room and starts turnover. Without it,
housekeeping does not know which rooms to clean and the room cannot be sold again.

**Independent Test**: Check out an in-house stay. The stay is departed with nights worked
out from the actual dates, the room is available and dirty, exactly one cleaning task
exists for the room linked to the stay and assigned to the Housekeeping team, and the
reservation is checked out.

**Acceptance Scenarios**:

1. **Given** an in-house stay on a one-line reservation, **When** staff check it out,
   **Then** the stay becomes departed with the current time as check-out time and nights
   counted from the check-in date to the check-out date, the room becomes available with
   housekeeping status dirty, and the reservation becomes checked out.
2. **Given** that check-out, **When** it completes, **Then** one cleaning task exists for
   the room, linked to the departed stay and the reservation, in the hotel's cleaning
   category and assigned to its Housekeeping team.
3. **Given** a guest leaves two days early, **When** staff check them out, **Then** nights
   reflect the actual stay, and the room's remaining nights become sellable again.
4. **Given** a guest is still in-house after their departure date, **When** staff check
   them out, **Then** it is accepted and nights reflect the actual stay.
5. **Given** a stay that is expected, departed or cancelled, **When** staff try to check it
   out, **Then** it is rejected with a message naming its state (a departed stay is
   treated as already done: nothing changes and no second cleaning task is created).
6. **Given** the room was set out of order while the guest was in it, **When** the guest
   checks out, **Then** the room stays out of order, is marked dirty, and the cleaning task
   is still created.
7. **Given** a hotel has not set its default Housekeeping team or cleaning category (or
   the chosen ones were deleted), **When** a guest checks out, **Then** the check-out still succeeds and the
   cleaning task is created without that team or category, for a manager to assign.
8. **Given** an in-house stay, **When** staff check it out at 11:00 giving 07:00 today as
   the actual time, **Then** 07:00 is the check-out time and the audit entry shows both
   07:00 and 11:00. A given time from yesterday or in the future is rejected.

---

### User Story 3 - A multi-room reservation is checked in and out room by room (Priority: P1)

A family books 2 Deluxe rooms. Each room has its own stay. The parents arrive first and
are checked into room 204; the teenagers arrive in the evening and are checked into 205.
On departure day, staff can check out one room or both in one action.

**Why this priority**: Multi-room reservations are why the stay model changes. Occupancy,
housekeeping and tasks must be tracked per room, not per reservation.

**Independent Test**: Create a reservation with 2 lines and assign both rooms. Check in one
line, then the other, then check out the whole reservation. Each line has its own stay and
audit entries, each room has its own cleaning task, and the reservation reaches checked out
only when both rooms are out.

**Acceptance Scenarios**:

1. **Given** a reservation is created with 2 lines, **When** it is saved, **Then** it has
   2 expected stays, one per line.
2. **Given** a line is added to a reservation, **When** it is saved, **Then** an expected
   stay is created for it. **Given** a line is cancelled or the reservation is cancelled,
   **Then** the matching expected stays become cancelled.
3. **Given** the reservation's dates change, **When** it is saved, **Then** every expected
   stay's planned dates follow. Stays that are in-house or departed keep what actually
   happened.
4. **Given** 2 lines with rooms assigned, **When** staff check in the whole reservation,
   **Then** both stays become in-house in one action.
5. **Given** one of the 2 lines has no room assigned, **When** staff check in the whole
   reservation, **Then** nothing is checked in and the response lists each line that
   cannot be checked in and why.
6. **Given** one line is in-house and the other is expected, **When** staff check in the
   whole reservation, **Then** only the expected line is checked in and the in-house one
   is left as it is.
7. **Given** one of 2 lines is checked in, **When** staff look at the reservation,
   **Then** its status is checked in: a reservation becomes checked in when its first
   room checks in.
8. **Given** 2 in-house lines, **When** staff check out only one, **Then** the
   reservation stays checked in. **When** the second one is checked out, **Then** the
   reservation becomes checked out.
9. **Given** 2 in-house lines, **When** staff check out the whole reservation, **Then**
   both stays become departed, both rooms become available and dirty, and there are 2
   cleaning tasks, one per room.
10. **Given** one line is in-house and the other is still expected (it never arrived),
    **When** staff check out the in-house line, or the whole reservation, **Then** it is
    rejected, nothing changes, and the message lists the expected lines to cancel first.
    **When** staff cancel the expected line and check out again, **Then** it succeeds and
    the reservation becomes checked out.

---

### User Story 4 - Front desk sees today's arrivals, departures and in-house guests (Priority: P2)

At the start of the shift, the receptionist opens three lists: who is arriving today
(including late arrivals from earlier days), who is leaving today (including guests who
should have left already), and who is in the house now. Each entry shows the guest, the
room type, the room (or that none is assigned yet) and the dates.

**Why this priority**: The lists are the front desk's daily work queue. They use the stays
created by User Stories 1–3.

**Independent Test**: Create expected, in-house and departed stays across several dates.
Each list shows exactly the stays that match its rule for the chosen day, with room and
guest details.

**Acceptance Scenarios**:

1. **Given** expected stays arriving today and one expected stay that arrived yesterday,
   **When** staff open today's arrivals, **Then** all of them are listed, and the late one
   is marked as late.
2. **Given** in-house stays departing today and one that should have departed yesterday,
   **When** staff open today's departures, **Then** all of them are listed, and the
   overdue one is marked as overdue.
3. **Given** in-house stays, **When** staff open the in-house list, **Then** every
   in-house stay is listed with its room.
4. **Given** arrivals where some lines have no room assigned, **When** staff view them,
   **Then** those lines are shown as unassigned so staff can assign a room before
   check-in.
5. **Given** staff choose a different day, **When** they open arrivals or departures,
   **Then** the lists are for that day.
6. **Given** stays of another hotel, **When** staff open any list, **Then** none of them
   appear.
7. **Given** an expected stay whose departure date has already passed, **When** staff open
   today's arrivals, **Then** it is listed and marked as past departure.

---

### User Story 5 - The Admin AI checks guests in and out (Priority: P2)

An administrator tells the Admin AI: "Check in the Hassan reservation, rooms 204 and 205"
or "Check out room 310." The AI uses the same check-in and check-out actions as staff, with
the same checks and permissions, and reports what happened or why it could not.

**Why this priority**: The constitution requires the Admin AI to operate the PMS through
the same domain actions and permissions as people. It depends on User Stories 1–3.

**Independent Test**: Through the Admin AI tools, check in and check out a reservation.
The results match the staff actions exactly, and the audit entries name the AI as the
actor on behalf of the user.

**Acceptance Scenarios**:

1. **Given** an administrator, **When** the Admin AI checks in a ready line, **Then** the
   result is the same as a staff check-in, and the audit entry records the AI actor and the
   user it acted for.
2. **Given** a user without the check-in permission, **When** the Admin AI tries to check
   in for them, **Then** the tool refuses and says so.
3. **Given** a line that fails a check-in rule, **When** the Admin AI tries it, **Then** it
   gets the same reason staff would, and reports it rather than retrying another way.
4. **Given** a guest on WhatsApp asks the Concierge to check them in or out, **When** the
   Concierge answers, **Then** it cannot do it, and it tells the guest to contact the front
   desk.

---

### User Story 6 - Tasks are linked to the stay they belong to (Priority: P3)

When a task is about a guest's time in a room — the cleaning after check-out, an extra
towels request from the Concierge, a staff-created task for an in-house guest — it is
linked to that stay. Managers can list a stay's tasks and filter tasks by stay.

**Why this priority**: Stay attribution supports later reporting and guest memory work.
It is not needed to run check-in and check-out.

**Independent Test**: Check out a stay and create a Concierge request for another in-house
guest. Both tasks carry the right stay, and filtering tasks by that stay returns them.

**Acceptance Scenarios**:

1. **Given** a check-out, **When** its cleaning task is created, **Then** the task is
   linked to the departed stay.
2. **Given** a guest with one in-house stay, **When** the Concierge creates a service
   request for them, **Then** the task is linked to that stay and its room.
3. **Given** a guest with in-house stays in two rooms, **When** the Concierge creates a
   request that names a room, **Then** the task is linked to that room's stay; if no room
   is named, it is linked to the reservation and guest but not a specific stay.
4. **Given** staff create or edit a task, **When** they link it to a stay, **Then** the
   stay must belong to the same hotel, and a room or reservation on the task must match
   the stay's.

---

### Edge Cases

- The general reservation edit is used to set `checked_in` or `checked_out` → handled as
  described in FR-020.
- A reservation is cancelled while one of its lines is in-house → rejected; in-house
  guests must be checked out first.
- A line is removed from a reservation while its stay is in-house → rejected; the stay
  must be checked out first.
- A room is reassigned while its stay is in-house → a room move; SPEC-021 decides it. The
  stay follows the line's room, and the old room is left dirty.
- An expected stay's line has a room assigned, then the room is deleted → check-in is
  rejected because no room is assigned.
- The reservation's dates are moved while a line is in-house → the in-house stay keeps its
  actual check-in; its planned departure follows the new departure date.
- One room of a reservation never arrives and the others are leaving → the last check-out
  is rejected until the unarrived line is cancelled (FR-012a); no-show marking waits for
  SPEC-012.
- Check-in and check-out happen on the same day (day use) → allowed, nights = 0.
- A check-out is entered after midnight for a guest who left the evening before → the
  earlier time is rejected (it is not on the current hotel day); the check-out is recorded
  at entry time, and a manager corrects the nights if needed.
- A check-out is requested for a stay that has never been checked in (a data error from
  before this feature) → rejected; staff correct the reservation instead.
- A deleted reservation → its stays are deleted with it and disappear from every list.
  Deleting a reservation that has an in-house stay is rejected (FR-021).
- Check-in when the hotel day has already moved past the departure date → rejected; the
  reservation's dates must be corrected first.
- A reservation imported from another system as already checked in or out → the import
  sets the matching stay states directly, without the room checks, as it records history
  (see FR-022).

## Requirements *(mandatory)*

### Functional Requirements

**One stay per line (SPEC-023)**

- **FR-001**: Every line of a reservation MUST have exactly one stay. No two stays may
  belong to the same line.
- **FR-002**: Creating a reservation or adding a line MUST create an expected stay for each
  new line. Cancelling a line or the reservation MUST cancel the matching expected stays.
  Bringing a cancelled reservation or line back MUST make its stays expected again.
- **FR-003**: While a stay is expected, its planned dates, guest and room MUST follow its
  reservation and line. Once it is in-house or departed, its check-in time, check-out time
  and nights MUST NOT be changed by reservation edits.
- **FR-004**: Existing data MUST be moved without loss. Each reservation's existing stay
  MUST be linked to its first line (the line the SPEC-010 migration created). Any other
  lines MUST get a stay whose state matches the reservation's current status. Stay
  history (check-in, check-out, nights and links from transactions, bookings, pitching and
  recommendation outcomes) MUST be kept.
- **FR-005**: Occupancy (how many rooms were occupied on a date) MUST be counted from stays:
  one room per in-house or departed stay whose dates cover that night.

**Check-in (SPEC-024)**

- **FR-006**: Authorized staff MUST be able to check in a single line, or all lines of a
  reservation in one action.
- **FR-007**: A line MUST only be checked in when all of these are true: the reservation is
  confirmed or already checked in; the line is not cancelled; its stay is expected; a
  physical room is assigned, or named in the check-in request for a line with none
  (FR-007a); that room is not out of order; that room has no other
  in-house stay; and the hotel day is on or after the arrival date and before the
  departure date.
- **FR-007a**: A check-in of an unassigned line MAY name a room. The room MUST pass the
  same checks as assigning it (same hotel, same room type as the line, no overlapping
  active line on that room), and the assignment and check-in MUST be saved together or
  not at all. The assignment MUST be audited like any other. Naming a room for a line that
  already has a different room assigned MUST be rejected. In a whole-reservation check-in,
  rooms MAY be named per line.
- **FR-008**: A check-in MUST, as one action: make the stay in-house with the check-in
  time, make the room occupied, make the reservation checked in if it is not already (its
  first room checking in is enough), and write an audit entry naming the actor. If any
  part fails, nothing is saved. A room's housekeeping status MUST NOT block check-in; when
  the room is not clean, the response MUST carry a warning naming its status.
- **FR-009**: A whole-reservation check-in MUST check in every expected line, or none. If
  any line fails FR-007, the response MUST list each failing line and its reason. Lines
  already in-house MUST be left unchanged.
- **FR-010**: Checking in a line that is already in-house MUST change nothing, keep the
  original check-in time, write no new audit entry, and return the current state.
  Simultaneous check-ins of the same line MUST have one effect.
- **FR-010a**: A check-in or check-out MAY give an earlier actual time instead of the
  moment of the action. That time MUST fall on the current hotel day, MUST NOT be in the
  future, and for a check-out MUST NOT be before the stay's check-in time; otherwise the
  request is rejected. Nights are counted from the given times. The audit entry MUST
  record both the given time and when the action was entered. The same rule applies to
  the Admin AI tools.

**Check-out (SPEC-025)**

- **FR-011**: Authorized staff MUST be able to check out a single line, or all in-house
  lines of a reservation in one action. A stay MUST only be checked out while in-house.
- **FR-012**: A check-out MUST, as one action: make the stay departed with the check-out
  time and nights counted by calendar date from check-in to check-out; make the room
  available (unless it is out of order, which it keeps) and dirty; create one cleaning
  task for the room; make the reservation checked out when none of its lines are still
  in-house (see FR-012a); and write an audit entry naming the actor. If any part fails,
  nothing is saved.
- **FR-012a**: A check-out (single line or whole reservation) that would leave the
  reservation with no in-house lines while any of its lines is still expected MUST be
  rejected, with nothing saved, and the message MUST list those expected lines so staff
  cancel them first. Checking out a line while another line is still in-house MUST NOT be
  affected by expected lines.
- **FR-013**: The cleaning task MUST be linked to the room, the reservation, the guest and
  the departed stay, use the hotel's default cleaning category, and be assigned to the
  hotel's default Housekeeping team. Both defaults are hotel settings chosen by an
  administrator (SPEC-004 later fills them for every hotel); they MUST NOT be found by
  name, so they work in any language. If either is not set, the task MUST still be
  created without it.
- **FR-014**: Checking out a stay that is already departed MUST change nothing, create no
  second cleaning task, write no new audit entry, and return the current state.
- **FR-015**: A departed stay's line MUST stop holding availability from the check-out
  date on, even while other lines of the reservation are still in-house, so rooms freed by
  an early departure can be sold again.

**Lists**

- **FR-016**: Staff MUST be able to list, for a chosen hotel day (default today): arrivals,
  departures and in-house stays, as defined in *Terms*. Each entry MUST show the guest,
  reservation reference, room type, assigned room or "unassigned", planned dates, stay
  state, and whether it is late (arrivals) or overdue (departures).
- **FR-017**: Staff MUST be able to view a single stay, and list stays filtered by state,
  dates, room, guest and reservation, with search, sorting and pagination like other
  lists.

**Tasks linked to stays**

- **FR-018**: A task MAY be linked to one stay of the same hotel. When a task names a
  room or reservation as well as a stay, they MUST match the stay's. Tasks MUST be
  filterable by stay.
- **FR-019**: When the Concierge creates a service request for a guest, the task MUST be
  linked to the guest's in-house stay when there is exactly one, or to the stay of the room
  the request names.

**Retiring check-in/out through the reservation edit**

- **FR-020**: Setting a reservation to `checked_in` or `checked_out` through the general
  reservation edit MUST no longer skip the checks. In this release it MUST be handled as a
  whole-reservation check-in or check-out, with the same checks, effects and audit, and
  the response MUST say it is deprecated and name the replacement. This path cannot name
  rooms (FR-007a), so every line must already have a room assigned. It MUST require the
  same `stays.check_in` / `stays.check_out` permission as the new actions, in addition to
  the reservation edit permission. The documentation MUST
  announce that a later release will reject it.
- **FR-020a**: Creating a reservation with status `checked_in` MUST be handled the same
  way: it is created `confirmed`, then checked in as a whole with the same checks and the
  same deprecation notice. Creating one with status `checked_out` MUST be rejected; only
  the import records past stays (FR-022). The Admin AI's reservation tool MUST only create
  `pending` or `confirmed` reservations and use the check-in tool for arrivals.
- **FR-021**: Cancelling or deleting a reservation, or removing or cancelling a line, while
  any of its stays is in-house MUST be rejected with a message to check out first.
- **FR-022**: The reservation import MUST record check-in and check-out history as given,
  without the room checks of FR-007 (it records the past, like SPEC-010 and SPEC-020 do
  for capacity and availability). It MUST NOT create cleaning tasks for history.

**AI**

- **FR-023**: The Admin AI MUST have a check-in tool and a check-out tool, for one line or
  a whole reservation, that use the same actions, rules, permissions and messages as
  staff. Their audit entries MUST name the AI as actor and the user it acted for.
- **FR-024**: The Admin AI MUST be able to read arrivals, departures and in-house stays
  with the same rules as FR-016, so it can find the stay it is asked about.
- **FR-025**: The Guest Concierge MUST NOT check guests in or out. Agent instructions MUST
  say so and point the guest to the front desk.

**Authorization, tenancy, audit and documentation**

- **FR-026**: New permissions MUST gate the new actions: `stays.view` (lists and single
  stays), `stays.check_in` and `stays.check_out`. Administrators hold them automatically.
  Employees without a staff role MUST NOT get any of them by default: the lists expose
  guest names, rooms and dates, and check-in/out change room status and occupancy, so a
  hotel grants them on purpose to front-desk roles.
- **FR-027**: Stays, check-ins and check-outs MUST be limited to the actor's hotel. A user,
  AI or guest of one hotel MUST NOT see or change another hotel's stays, and a reference to
  another hotel's stay or reservation MUST be refused like any other hotel resource
  (403 from the same-hotel permission check), and another hotel's room named at
  check-in MUST read as "not available", revealing nothing about it. A super administrator
  MUST name the hotel.
- **FR-028**: Every check-in and check-out MUST be in the audit log with the actor (user or
  AI), the stay, the line, the room and the time. The room and reservation status changes
  they cause MUST be audited too.
- **FR-029**: No part of this feature MAY write to the transaction ledger while the finance
  feature is off (D11).
- **FR-030**: The new actions, lists and permissions MUST be added to the API documentation
  and the permission reference. The FR-020 / FR-020a deprecation and new rejections MUST
  be announced as a change summary.

### Key Entities

- **Stay** (existing, changed): one line's guest presence. Belongs to exactly one line
  (instead of one per reservation), and through it to the reservation, guest and room.
  Holds planned dates, state, check-in time, check-out time and nights.
- **Reservation line** (existing, SPEC-010): a booked room unit. Has one stay and, once
  assigned (SPEC-021), a physical room.
- **Reservation** (existing): its status moves to checked in and checked out as its lines'
  stays do.
- **Room** (existing): becomes occupied at check-in and available and dirty at check-out;
  out of order is never cleared by check-out.
- **Task** (existing, changed): may be linked to a stay. Check-out creates a cleaning task.
- **Hotel housekeeping defaults** (new hotel settings): the team and task category the
  cleaning task goes to. Set by administrators; SPEC-004 fills them for every hotel.
- **Audit entry** (existing): records each check-in and check-out and its actor.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A receptionist can check a guest in, or out, with one action from the
  arrivals or departures list, without editing the reservation.
- **SC-002**: 0 check-ins succeed for a line with no assigned or named room, a named room
  that fails an assignment check, an out-of-order room, a
  room with another in-house stay, or a reservation that is not confirmed.
- **SC-003**: After every check-out, the room shows as dirty and exactly one cleaning task
  exists for it, and housekeeping sees it without anyone creating it by hand.
- **SC-004**: For every multi-room reservation tested, the number of stays equals the
  number of live lines, and occupancy for any night matches a hand count of rooms in use.
- **SC-005**: 100% of existing stays are still linked to their reservation after the
  change, with their check-in and check-out history unchanged.
- **SC-006**: Repeating any check-in or check-out, or sending it twice at once, produces
  one state change, one audit entry and at most one cleaning task.
- **SC-007**: Every check-in and check-out, by staff or AI, has an audit entry with its
  actor; the Admin AI's results match the staff actions in 100% of tested cases.
- **SC-008**: Today's arrivals, departures and in-house lists load in under 2 seconds for a
  hotel with 500 rooms.
- **SC-009**: No user, AI or guest of one hotel can see or change another hotel's stays.

## Assumptions

- **Depends on** SPEC-021 (physical room assignment) for assigning rooms ahead of arrival
  and for the room-conflict guarantee; check-in can assign a room itself (FR-007a), using
  the same assignment rules, so SPEC-021's endpoints are not a hard prerequisite. SPEC-003 (room status split: available / occupied / out of order, and a
  separate housekeeping status) for the room statuses used here; and SPEC-004 (default
  Housekeeping team and cleaning category, which fills the hotel's housekeeping defaults
  for every hotel; until then an administrator sets them). If this feature is built
  before one of them, the plan must say how it bridges the gap (for example, today's
  `maintenance` room status stands in for out of order).
- Check-in is allowed from the arrival date. An early arrival on an earlier date means the
  reservation's dates are changed first, which also re-checks availability (SPEC-020).
- Only confirmed reservations can be checked in, as the plan requires. A pending
  reservation is confirmed first.
- The party (adults, children, children's ages) stays on the reservation as totals across
  rooms (D8). Stays do not split the party per room in MVP.
- Each stay's share of room revenue is the reservation's value divided evenly across its
  live lines. Finance is off by default (D11), so this only affects reporting.
- The stay's guest is the reservation's primary guest. SPEC-011 may later name a different
  guest per room.
- No-show handling (marking expected stays no-show) comes with SPEC-012's `no_show`
  status; until then, staff cancel reservations for guests who never arrived.
- Undoing a mistaken check-in or check-out is not part of this feature; staff correct it
  through a manager. Undo actions are left for a later spec.
- Nights and "today" use the hotel's local date.
- The frontend slice (arrivals/departures board, check-in and check-out actions, in-house
  list) is delivered in `ecosystem-frontend` in the same phase (decision D13).
