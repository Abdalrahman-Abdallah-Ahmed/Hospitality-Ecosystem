# Feature Specification: Physical Room Assignment & Reassignment

**Feature Branch**: `020-room-assignment`

**Created**: 2026-10-30

**Status**: Draft (clarified)

**Input**: User description: "SPEC-021 Physical Room Assignment & Reassignment (master plan Phase 3, items 3–6), scoped to what specs 002, 003 and 004 left to it: dedicated assign, reassign and unassign of a physical room per reservation line; the `reservations.assign_room` permission; a guarantee in the data itself that one room cannot hold two overlapping reservations; the room-type upgrade decision; moving an in-house guest to another room; and an unassigned-arrivals list."

## Clarifications

### Session 2026-10-30

- Q: Are room-type upgrades on assignment in scope for the MVP? → A: Yes. Any staff member
  with `reservations.assign_room` may upgrade; there is no separate upgrade permission.

## Context

A reservation books room *types*; each booked unit is a **line**. A line may also name the
**physical room** the guest will sleep in. Earlier specs put the pieces around that choice
in place and each left the assignment itself to this spec:

| Where a room is put on a line today | Rules applied today |
| --- | --- |
| Creating or editing a reservation (spec 002, FR-014, "interim") | Same hotel, line's room type, not twice in one reservation. **No check against other reservations.** |
| Admin AI "assign rooms" tool (spec 009) | Goes through the reservation edit, so the same as above. **No check against other reservations**, although the tool tells the AI the room is "free for the nights". |
| Check-in of a line with no room (spec 004, FR-007a) | Same hotel, room type, and no other reservation holding the room on overlapping nights |
| Reservation import (spec 013) | Its own clash check |

So today two reservations can hold the same physical room on the same nights, and nothing
in the stored data prevents it. The MVP completion gate (master plan §6, step 4) requires
"Physical rooms are assigned with no overlap possible."

Already in place and **not** rebuilt here: availability per room type (spec 003); check-in,
check-out and stays (spec 004); out-of-order rooms (spec 005); the guest Concierge's
"request a room change" tool, which only creates a staff task (spec 007).

**In scope**: assigning, reassigning and unassigning a room on a line through one set of
rules used by every path; the guarantee against overlaps in the stored data; the
assignment permission; room-type upgrades on assignment; moving a guest who is already in
house; the unassigned-arrivals list; audit, Admin AI alignment and documentation.

**Out of scope (later specs)**: automatic or suggested room allocation; dated out-of-order
periods (SPEC-033); per-room date ranges inside one reservation (post-MVP); charging for
upgrades or any finance effect (post-MVP, ledger off per D11); guest self-selection of rooms.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Staff assign a room ahead of arrival (Priority: P1)

A confirmed reservation for two Deluxe rooms arrives on Friday. On Wednesday the front-desk
supervisor opens it and puts rooms 204 and 206 on its two lines. Both are Deluxe rooms and
neither is booked by anyone else on those nights, so both assignments are saved together.

**Why this priority**: This is the core action of the spec and the step the MVP gate names.
Check-in, housekeeping and the Concierge all read which guest is in which room.

**Independent Test**: Create a confirmed reservation with two Deluxe lines. Assign two free
Deluxe rooms in one action. Both lines hold their rooms, one audit entry per change names
the actor, and availability per type is unchanged.

**Acceptance Scenarios**:

1. **Given** a confirmed reservation with an unassigned Deluxe line, **When** staff with
   the assignment permission assign a Deluxe room free on all its nights, **Then** the line
   holds that room.
2. **Given** a room held by another reservation on at least one overlapping night,
   **When** staff assign it, **Then** it is rejected with a message naming the room and the
   other reservation's code, and nothing changes.
3. **Given** a reservation leaving on the 10th, **When** staff assign its room to another
   reservation arriving on the 10th, **Then** it is accepted: departure day and the next
   arrival day do not overlap.
4. **Given** several assignments sent in one action where one breaks a rule, **When** the
   action runs, **Then** none is saved and the message names the failing line and reason.
5. **Given** a room of another hotel, or a room that does not exist, **When** staff assign
   it, **Then** it is rejected as "not available" without revealing the other hotel's data.
6. **Given** a reservation that is cancelled, checked out or a no-show, or a cancelled line,
   **When** staff try to assign a room, **Then** it is rejected with the reason.
7. **Given** two staff members assigning the same room to two different reservations for
   overlapping nights at the same moment, **When** both actions run, **Then** exactly one
   succeeds and the other is rejected as in scenario 2.

---

### User Story 2 - Staff change or remove an assigned room before arrival (Priority: P1)

Room 204 needs a repair before Friday. The supervisor moves the guest's line from 204 to
208, and on another booking takes the room off a line entirely so it can be decided at
check-in.

**Why this priority**: Plans change daily; a room chosen in advance is only useful if staff
can correct it with the same safety as the first assignment.

**Independent Test**: Assign room 204 to a line, reassign it to 208, then unassign it. The
line follows each step, 204 is free for others after the first change, and each change has
its own audit entry with the old and new room.

**Acceptance Scenarios**:

1. **Given** a line holding room 204 whose guest has not checked in, **When** staff
   reassign it to a free room of an allowed type, **Then** the line holds the new room and
   204 is immediately free for other reservations on those nights.
2. **Given** two lines of the same reservation holding rooms 204 and 206, **When** staff
   swap them in one action, **Then** the swap succeeds; the reservation does not clash with
   itself during the swap.
3. **Given** a line holding a room whose guest has not checked in, **When** staff unassign
   it, **Then** the line has no room and still counts against its room type's availability.
4. **Given** a line whose guest is in house, **When** staff try to unassign its room,
   **Then** it is rejected: an in-house guest must always have a room (see User Story 4).
5. **Given** a line whose guest has checked out or was a no-show, **When** staff try to
   change its room, **Then** it is rejected: past stays keep their room as recorded.

---

### User Story 3 - Staff see which arrivals still need a room (Priority: P2)

Every morning the front desk opens the list of arrivals for the next few days that have no
room yet, works through it, and assigns rooms.

**Why this priority**: Assignment is only reliable if staff can find the gaps; without the
list, unassigned arrivals are discovered at the desk with the guest waiting.

**Independent Test**: Create three reservations arriving within the window, one fully
assigned, one partly assigned, one unassigned, and one arriving after the window. The list
shows exactly the unassigned lines of the second and third, grouped by reservation, with
arrival date, room type and guest name.

**Acceptance Scenarios**:

1. **Given** live lines without a room, whose guest has not arrived yet, on pending,
   confirmed or checked-in reservations arriving in a date window, **When** staff open the list for that window, **Then** each such line
   appears once, with reservation code, primary guest, arrival and departure dates, room
   type, and reservation status.
2. **Given** no window is given, **When** staff open the list, **Then** it covers today
   (hotel time) and the next 7 days.
3. **Given** a late arrival (arrival date passed, not yet checked in, not a no-show),
   **When** staff open the list, **Then** its unassigned lines are included and marked late.
4. **Given** another hotel's unassigned arrivals, **When** staff open the list, **Then**
   they never appear.

---

### User Story 4 - Staff move an in-house guest to another room (Priority: P2)

A guest in room 204 reports that the air conditioning has failed. The duty manager moves
them to room 210 for the rest of their stay.

**Why this priority**: Room moves happen in every hotel; spec 004 deferred them here, and
the Concierge's "room change request" task has no staff action to complete it today.

**Independent Test**: Check a guest into 204, move them to free room 210. The line and the
in-house stay both point to 210, 210 is occupied, 204 is no longer occupied and is dirty
with a cleaning task, and the audit names old room, new room, actor and reason.

**Acceptance Scenarios**:

1. **Given** an in-house line in room 204, **When** staff move it to a room of an allowed
   type that is free on every night of the stay, not out of order and not occupied, **Then**
   the line and its stay point to the new room, the new room is occupied, the old room is
   no longer occupied and is marked dirty with a cleaning task, and the move is audited
   with its reason.
2. **Given** the target room is occupied, out of order, or held by another reservation on
   any night of the stay, **When** staff try the move, **Then** it is rejected with the
   reason and nothing changes.
3. **Given** an open room-change request task from the guest for this stay, **When** the
   move is made, **Then** the response points to that task so staff can complete it; the
   task is not closed automatically.
4. **Given** a move, **When** it completes, **Then** no message is sent to the guest; staff
   tell the guest themselves.

---

### User Story 5 - Upgrade a guest to a room of another type (Priority: P3)

The hotel is full in Deluxe but has a free Suite. The manager puts a Deluxe guest in a
Suite room as a complimentary upgrade.

**Why this priority**: Common in practice and needed to handle sold-out types, but the core
safety of assignment does not depend on it.

**Independent Test**: Assign a Suite room to a Deluxe line as an upgrade. The line keeps
Deluxe as its booked type and shows the Suite room; Deluxe availability is unchanged,
Suite availability is reduced by one on those nights, and the audit marks it an upgrade.

**Acceptance Scenarios**:

1. **Given** a Deluxe line and a free Suite room, **When** staff assign it without marking
   it as an upgrade, **Then** it is rejected because the room is not of the line's type.
2. **Given** the same, **When** staff with the assignment permission assign it and mark it
   as an upgrade, **Then** the line keeps its booked type (Deluxe), holds the Suite room, and
   is shown as upgraded.
3. **Given** an upgraded line, **When** availability is read, **Then** the line releases
   its booked type and counts against the type of the room it holds, so neither type is
   oversold.
4. **Given** the room type of the target room would be oversold on any night by the
   upgrade, **When** staff try it, **Then** it is rejected unless they also hold the
   existing overbook permission and confirm the override.

---

### Edge Cases

- A reservation's dates are changed while its lines hold rooms → each held room is
  re-checked for the new nights; if any now clashes, the date change is rejected naming the
  room and the other reservation. The change is not silently accepted with a clash.
- A reservation that was cancelled is un-cancelled and its rooms have been given away in
  the meantime → the restored lines lose those rooms (the other reservation keeps them) and
  the response warns that rooms must be reassigned; same rule as the no-show undo.
- A no-show is undone and its room was assigned to someone else → unchanged from spec 015.
- A room is deleted while held on future lines → those lines become unassigned and appear
  in the unassigned-arrivals list.
- A room is currently out of order → it may be assigned for a future arrival, with a
  warning in the response; check-in still refuses an out-of-order room (spec 004).
- A room is marked out of order while an in-house guest is in it → no automatic move; staff
  use User Story 4.
- The same room is named on two lines of one request → rejected (unchanged).
- A line's room is already the requested room → accepted, nothing changes, no audit entry.
- The reservation import names a room that clashes → unchanged from spec 013 (reported as
  a row problem); after this spec the stored-data guarantee also applies to it.
- Existing stored data already contains overlapping assignments when this ships → the
  overlaps are found and reported to the platform owner before the guarantee is switched
  on; they are not silently removed. See FR-012.

## Requirements *(mandatory)*

### Functional Requirements

**One set of assignment rules**

- **FR-001**: The system MUST apply one set of assignment rules to every path that puts a
  physical room on a line: dedicated assignment, the reservation create and edit, check-in
  of an unassigned line, the Admin AI, the reservation import and any future path.
- **FR-002**: A room MAY be put on a line only when all hold:
  (a) the room belongs to the line's hotel and exists;
  (b) the room's type is the line's booked type, or the assignment is marked as an upgrade
  (FR-015);
  (c) no other live line on a reservation that holds inventory (pending, confirmed,
  checked in) holds the room on any night between the reservation's arrival and departure,
  departure day excluded; lines whose stay is a no-show or has departed hold nothing;
  (d) the line is live and its reservation is pending, confirmed or checked in, and the
  line's own stay is not departed or a no-show.
- **FR-003**: Each rejection MUST say which line and room failed and why. A clash MUST name
  the other reservation's code. Rooms or reservations of other hotels MUST NOT be revealed.

**Assign, reassign, unassign**

- **FR-004**: Staff MUST be able to assign, reassign or unassign the rooms of one or more
  lines of a reservation in one action. The action succeeds as a whole or changes nothing.
- **FR-005**: Within one action, rules are checked against the final state, so lines of the
  same reservation can swap rooms.
- **FR-006**: Unassigning MUST be allowed only for lines whose guest has not checked in.
  An unassigned line keeps counting against its room type's availability.
- **FR-007**: Assigning a room that is currently out of order MUST succeed with a warning
  in the response; it is not blocked.
- **FR-008**: Re-sending an assignment that is already in place MUST succeed and change
  nothing (no new audit entry).

**Guarantee in the stored data**

- **FR-009**: The stored data itself MUST make it impossible for one room to be held by two
  live, inventory-holding lines on overlapping nights, whichever path writes it, including
  two simultaneous requests.
- **FR-010**: When that guarantee rejects a write, the user MUST receive the same clear
  message as FR-003, never a generic server error.
- **FR-011**: Changing a reservation's dates, un-cancelling it or undoing a no-show MUST be
  checked against the guarantee; handling follows the Edge Cases above.
- **FR-012**: Before the guarantee is switched on, existing overlaps MUST be detected and
  listed (hotel, room, reservations, nights). Release MUST NOT proceed while any remain;
  they are resolved by staff, not removed automatically.

**Moving an in-house guest**

- **FR-013**: Staff MUST be able to move an in-house line to another room, with a required
  reason. The target room MUST satisfy FR-002 for every night of the stay (arrival through
  departure; a room still held by another live booking on an earlier night is a real
  conflict), not be out of order, and not have another in-house stay.
- **FR-014**: A move MUST, together or not at all: point the line and its in-house stay to
  the new room; mark the new room occupied; mark the old room not occupied and dirty and
  create its cleaning task as check-out does (spec 004); and record the move in the audit
  with old room, new room, reason and actor. The stay's check-in time is unchanged.

**Upgrades**

- **FR-015**: A line MAY hold a room of another type only when the assignment is explicitly
  marked as an upgrade. Any staff member who may assign rooms may upgrade; there is no
  separate upgrade permission. The line keeps its booked type and is marked upgraded;
  responses show both the booked type and the room's type.
- **FR-016**: Availability MUST count an upgraded line against the type of the room it
  holds, not its booked type, for the nights it holds that room. An upgrade that would
  oversell the room's type MUST be rejected unless the user holds `reservations.overbook`
  and confirms the override, as for reservation creates (spec 003).
- **FR-017**: Reassigning or unassigning an upgraded line back to its booked type removes
  the upgrade mark.

**Unassigned arrivals**

- **FR-018**: Staff MUST be able to list live lines without a room, whose guest has not
  arrived yet, on pending, confirmed or checked-in reservations (a multi-room reservation
  is checked in once its first room is) arriving within a date window (default: today through 7 days ahead, hotel
  time), plus late arrivals not yet checked in and not no-shows, marked late. Each entry
  shows reservation code, primary guest, arrival and departure, booked room type and
  reservation status. The list supports the standard paging and sorting of list screens.

**Permissions**

- **FR-019**: New permissions, grouped with the reservation permissions:
  `reservations.assign_room` (assign, reassign, unassign, view the unassigned-arrivals
  list, upgrade) and `reservations.move_room` (move an in-house guest). Employees without a staff role get none of them by default.
- **FR-020**: Setting a room on a line through the reservation create or edit MUST require
  `reservations.assign_room` in addition to the create or update permission. A request that
  sets no room is unaffected.
- **FR-021**: Naming a room at check-in for an unassigned line (spec 004, FR-007a) keeps
  requiring only the check-in permission, but MUST apply the rules of FR-002.

**AI**

- **FR-022**: The Admin AI's room assignment MUST follow FR-002 through FR-010 and the
  same permissions as the staff member it acts for; its description MUST state only rules
  that are actually enforced.
- **FR-023**: The Admin AI MAY move an in-house guest only if the acting staff member holds
  `reservations.move_room`, with the same reason requirement. It MAY upgrade only when the
  admin explicitly asks for a room of another type, under the same rules as staff.
- **FR-024**: The guest Concierge MUST NOT assign, change or move rooms; it keeps creating a
  room-change request task only (unchanged).

**Audit, isolation, documentation**

- **FR-025**: Every assignment change (assign, reassign, unassign, move, upgrade) MUST be
  audited with actor (staff or AI on behalf of staff), reservation, line, old room, new
  room and, for moves, the reason. An upgrade MUST be recorded as an upgrade, naming the
  booked type and the room's type.
- **FR-026**: Every operation in this spec MUST be limited to the acting user's hotel; a
  record of another hotel is reported as not found.
- **FR-027**: No assignment change sends a message to the guest.
- **FR-028**: The reservations, stays and staff-roles API documentation MUST describe the
  new actions, permissions, rules and errors, and the breaking change in FR-020 MUST be
  listed in a latest-changes note.

### Key Entities *(include if feature involves data)*

- **Reservation line**: one booked room unit of a reservation. Has a booked room type,
  optionally a physical room, and (new) whether its room is an upgrade.
- **Physical room**: a numbered room of one room type, with operational status (available,
  occupied, out of order) and housekeeping status.
- **Room hold**: the fact that a live line on an inventory-holding reservation holds a room
  for the nights from arrival to the night before departure. The stored-data guarantee is
  that two holds on one room never share a night.
- **Stay**: one per line (spec 004); follows the line's room when an in-house guest moves.
- **Room move**: an audited event on an in-house stay: old room, new room, reason, actor,
  time.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In an automated test that sends two simultaneous assignments of one room to
  different reservations for overlapping nights, repeated 20 times, exactly one succeeds
  every time.
- **SC-002**: Zero overlapping room holds exist in any hotel's data after release, verified
  by the overlap report of FR-012 run against production data.
- **SC-003**: Every path that puts a room on a line (assignment, create, edit, check-in,
  Admin AI, import) rejects the same clashing room with the same reason, verified by one
  test per path.
- **SC-004**: A front-desk user can find and assign rooms for all of a day's unassigned
  arrivals from one list, without opening each reservation first.
- **SC-005**: A room move is completed in a single staff action, and afterwards room
  status, housekeeping status and the guest's stay are all correct without further steps.
- **SC-006**: Staff from another hotel cannot see or change any assignment, and an employee
  without the new permissions is refused every new action, each verified by a test.

## Assumptions

- Assignment is allowed for pending as well as confirmed reservations; a pending booking
  already holds inventory (spec 003).
- A clash is any shared night; the departure day is not a night, so back-to-back bookings
  of the same room are allowed.
- The current room status (out of order now) is the only out-of-order information
  available; dated out-of-order periods are SPEC-033, so FR-007 warns instead of blocking.
- Moving an in-house guest does not change the reservation's dates, booked types, or
  price; any charge for a move or upgrade is post-MVP (finance off, D11).
- The interim room choice on reservation create and edit (spec 002, FR-014) stays, now
  under the full rules and permission; removing it is not worth breaking the frontend.
- The reservation import keeps its own row-level reporting; the stored-data guarantee is a
  safety net under it.
- No existing overlapping assignments are expected in development data; FR-012 still
  applies before production.
- Depends on: spec 002 (lines), spec 003 (availability and overbook override), spec 004
  (stays, check-in, cleaning on check-out), spec 005 (out-of-order rooms), spec 007
  (room-change request task), spec 009 (Admin AI tools), spec 015 (no-show and its undo).
