# Feature Specification: Reservation Lifecycle and No-Show

**Feature Branch**: `015-reservation-lifecycle`

**Created**: 2026-10-21

**Status**: Draft

**Input**: User description: "for SPEC-012". This is SPEC-012 Reservation Lifecycle from
Phase 2 of docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md (item 4: "add
`no_show`; define allowed transitions and who may trigger them"). It was deferred by
`specs/002-reservation-rooms`, `specs/004-stay-check-in-out` and
`specs/009-admin-ai-pms-tools`, and is a prerequisite for Phase 13
(`specs/013-reservation-import-v2`). The plan leaves this spec one open question:
"exact status transitions, and who may trigger `no_show`."

## Overview

A reservation moves through pending, confirmed, checked in, checked out and cancelled.
Today nothing defines which moves are allowed. Staff can set almost any status, guests
who never arrive have no status of their own, and rooms booked for them stay held until
someone cancels the reservation by hand, which also records the wrong outcome.

This feature:

- **Defines the lifecycle.** One table of allowed status changes and the operation that
  makes each one. Any change not in the table is rejected with a clear reason.
- **Adds `no_show`.** A guest who never arrived is recorded as a no-show. The rooms are
  released, and reports, recommendations and the import can tell a no-show apart from a
  cancellation.
- **Handles one room not arriving.** On a multi-room reservation, staff can mark a single
  room as a no-show while the others check in and out normally.
- **Gives no-show its own permission and operation.** It is a staff decision with
  commercial meaning (a held room that earned nothing), so it is not a free-form status
  edit.

**In scope**: the transition table, the `no_show` reservation status, whole-reservation and
per-room no-show, undoing a mistaken no-show, the permission, the Admin AI tool, effects on
rooms, stays, availability and recommendations, and the docs.

**Out of scope**: recording a stay that already happened (back-dated check-in and
check-out) after a no-show is undone; no-show fees and charges (payments are post-MVP); automatic guest
messages about a no-show; changes to check-in and check-out rules (SPEC-024/025, already
built); the import's mapping of legacy no-show rows (Phase 13 consumes this status).

## Clarifications

### Session 2026-10-21

- Q: Should the system also mark no-shows automatically? → A: No. Manual only; nothing is
  marked a no-show without a person (or the Admin AI acting for one) deciding.
- Q: Until when may a no-show be undone? → A: Any time. Every undo is audited with its
  actor, so later corrections stay traceable.
- Q: Can a single room's no-show be undone after the reservation has been checked out? →
  A: No. A room-level undo is allowed only while the reservation is not yet checked out;
  after that the room's no-show is final. A whole-reservation no-show can still be undone
  at any time.
- Q: Can a no-show reservation be edited without undoing the no-show? → A: Only its guest
  list, guest contact details, special requests and notes. Dates and room lines need the
  no-show undone first. (Reservations have no separate notes field; special requests is
  their free-text field, so "notes" means special requests.)
- Q: What does a no-show become when it is undone after its dates have passed? → A: Its
  earlier status (pending or confirmed), like any undo. Staff can then cancel it or keep it
  as history; recording a stay that already happened is out of scope.
- Q: Should staff record a reason when marking a no-show? → A: Optional free-text reason,
  kept with the no-show in the reservation's history.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mark a guest who never arrived as a no-show (Priority: P1)

The day after arrival, the front desk sees a confirmed reservation whose guest never came.
They mark it as a no-show. The reservation shows "no-show", its rooms are free to sell
again, and it no longer appears in the arrivals list.

**Why this priority**: It is the missing outcome in the lifecycle. Without it, rooms stay
blocked and staff misuse "cancelled", which corrupts occupancy, conversion and
recommendation outcomes.

**Independent Test**: Create a confirmed reservation arriving yesterday; mark it no-show;
confirm status `no_show`, its stays are no-show, its rooms count as available again for
its nights, and the action is audited with the actor.

**Acceptance Scenarios**:

1. **Given** a confirmed or pending reservation whose arrival date has been reached in the
   hotel's local time, **When** staff with the no-show permission mark it a no-show,
   **Then** its status becomes `no_show`, every room's stay becomes no-show, and its rooms
   stop counting against availability.
2. **Given** a reservation whose arrival date is still in the future, **When** staff try
   to mark it a no-show, **Then** it is rejected: a guest can't fail to arrive before
   their arrival day.
3. **Given** a reservation with any room checked in or checked out, **When** staff try to
   mark the whole reservation a no-show, **Then** it is rejected; staff can mark the
   unarrived rooms individually instead (User Story 2).
4. **Given** a reservation marked no-show, **When** staff view it or list reservations,
   **Then** it shows `no_show` and can be filtered by that status.
5. **Given** an employee without the no-show permission, **When** they try, **Then** they
   get 403 and nothing changes.

---

### User Story 2 - One room of a group never arrives (Priority: P1)

A reservation has three rooms. Two families arrive and check in; the third never comes.
Staff mark that one room a no-show. Its room is released, and the reservation can later
be checked out without first cancelling the missing room.

**Why this priority**: `specs/004` left this case blocked ("the last check-out is rejected
until the unarrived line is cancelled; no-show marking waits for SPEC-012"). Cancelling a
room that was really a no-show records the wrong outcome.

**Independent Test**: A three-room reservation with two rooms checked in; mark the third
room no-show; check out the other two; confirm the reservation ends `checked_out`, the
third room's stay is no-show, and its room was released.

**Acceptance Scenarios**:

1. **Given** a multi-room reservation on or after its arrival date, **When** staff mark one
   room that has not arrived as a no-show, **Then** that room's stay becomes no-show and
   its room stops counting against availability; the other rooms are unchanged.
2. **Given** some rooms checked in and every other room a no-show, **When** the last
   checked-in room checks out, **Then** the reservation becomes `checked_out`.
3. **Given** every room of a reservation marked no-show one by one, **When** the last one
   is marked, **Then** the reservation becomes `no_show`.
4. **Given** a room that is checked in or checked out, **When** staff try to mark it a
   no-show, **Then** it is rejected.

---

### User Story 3 - Status changes follow one clear lifecycle (Priority: P1)

Staff, the Admin AI and the import can only move a reservation along allowed paths. A
checked-out reservation can't be put back to pending; a no-show can't be "confirmed"
through a plain edit. Each rejected change says which change was attempted and why.

**Why this priority**: The plan requires "each transition defined". Every later feature
(voucher, import, insights) relies on statuses meaning one thing.

**Independent Test**: Walk every pair of statuses through the staff edit, the dedicated
operations and the Admin AI; confirm the allowed ones succeed through their operation and
every other one is rejected with the attempted change named.

**Acceptance Scenarios**:

1. **Given** a reservation in any status, **When** a change is requested, **Then** it is
   allowed only if it appears in the lifecycle table below (FR-001), and only through the
   operation the table names.
2. **Given** a disallowed change, **When** it is requested, **Then** it is rejected with a
   message naming the current and requested status, and nothing changes.
3. **Given** a staff edit that sets `no_show`, **When** submitted, **Then** it is rejected
   and points to the no-show operation (as check-in and check-out do today).

---

### User Story 4 - Undo a mistaken no-show (Priority: P2)

The guest turns up late in the evening after being marked a no-show. Staff undo the
no-show and check them in as usual.

**Why this priority**: Mistakes happen, especially when no-shows are marked at night. Not
being able to undo one forces staff to create a duplicate reservation.

**Independent Test**: Mark a reservation no-show, undo it, then check it in; confirm it
returns to its previous status, its stays return to expected, and availability is checked
again.

**Acceptance Scenarios**:

1. **Given** a reservation marked no-show, **When** staff with the no-show permission undo
   it, at any time (even after the reservation's dates have passed), **Then** it returns to
   the status it had before (pending or confirmed), and its stays return to expected.
2. **Given** the rooms were sold to someone else in the meantime, **When** staff undo the
   no-show, **Then** it is rejected like any booking that no longer fits, unless they
   hold the overbook permission and choose to overbook.
3. **Given** a single room marked no-show on a reservation that is not yet checked out,
   **When** staff undo that room, **Then** only that room returns to expected.
4. **Given** a reservation that has been checked out with one room marked no-show, **When**
   staff try to undo that room, **Then** it is rejected: the room's no-show is final once
   the reservation is checked out.

---

### User Story 5 - The Admin AI can mark and undo no-shows (Priority: P3)

A manager tells the Admin AI "mark RES-1042 as a no-show" or "room 214 on RES-1042 never
arrived". The AI does it through the same operation and permission as staff, and the
audit log shows the AI as the actor.

**Why this priority**: `specs/009` promised the AI a no-show tool once this ships
(plan R11). Useful but not required for the front desk.

**Independent Test**: Through the Admin AI tools, mark a reservation and a single room as
no-show; confirm the same rules apply, an employee without the permission is refused,
and the audit shows the AI actor.

**Acceptance Scenarios**:

1. **Given** the Admin AI acting for a user with the no-show permission, **When** asked to
   mark a reservation or one room a no-show, **Then** it uses the same operation and
   rules.
2. **Given** the user lacks the permission, **When** the AI is asked, **Then** it refuses
   and nothing changes.
3. **Given** the Guest Concierge, **When** a guest talks to it, **Then** it can never mark
   a no-show.

---

### Edge Cases

- **Pending reservation never confirmed and never arrived**: may be marked no-show from the
  arrival day, the same as confirmed.
- **Arrival day, before the end of the day**: allowed from the start of the arrival day in
  hotel local time; staff decide when it is too late. The system never marks a no-show
  automatically; an unarrived reservation keeps its rooms until someone marks it.
- **Reservation with no room lines (legacy)**: marked no-show as a whole; nothing to
  release.
- **Room assigned to a no-show line**: the assignment is kept for history but the room is
  free for other bookings on those nights; it does not need cleaning.
- **Cancelled reservation**: can't be marked no-show; a no-show can't be cancelled (it
  already holds nothing). Either can be undone back to pending/confirmed through its own
  path.
- **Departure date already passed**: a no-show can still be recorded after the stay's
  dates (late night audit), and a no-show can still be undone then. An undo after the
  dates returns the reservation to its earlier status (pending or confirmed) for those past
  nights; it can't be checked in afterwards, so staff cancel it or keep it as history.
  Recommendation outcomes and reports read the corrected status from then on.
- **Recommendations and proactive messages**: a no-show reservation's pending
  recommendations expire and nothing is pitched, as for a cancellation; outcome matching
  treats it as a no-show, not a cancellation.
- **Guest memories**: no extraction runs for a no-show (there was no stay).
- **Import**: may record `no_show` directly for past reservations, as it records
  checked-out today (Phase 13).
- **Editing a no-show**: the guest list, guest details and special requests can be
  corrected; every other reservation field (dates, party, rooms, code, source, value,
  currency, status) can't be changed until the no-show is undone.
- **Finance flag**: marking a no-show never writes to the transaction ledger.

## Requirements *(mandatory)*

### Functional Requirements

**Lifecycle**

- **FR-001**: Reservation status changes MUST follow this table; every other change MUST
  be rejected:

  | From | To | Through |
  | --- | --- | --- |
  | (new) | pending, confirmed | create |
  | pending | confirmed | edit |
  | confirmed | pending | edit |
  | pending, confirmed | cancelled | edit or cancel operation |
  | cancelled | pending, confirmed | edit (reactivation; availability re-checked) |
  | confirmed | checked_in | check-in operation (first room in); a pending reservation is confirmed first, as check-in requires today |
  | checked_in | checked_out | check-out operation (last room out) |
  | pending, confirmed | no_show | no-show operation (all rooms, or last unarrived room) |
  | no_show | pending, confirmed | undo-no-show operation (back to the status it had) |
  | (import only) | checked_out, no_show | import of past reservations |

- **FR-002**: A rejected change MUST name the current and requested status and the
  operation to use instead, where one exists.
- **FR-003**: The staff edit MUST reject `no_show` as a requested status, pointing to the
  no-show operation. The deprecated check-in/check-out-through-edit behaviour is unchanged
  by this feature.
- **FR-004**: `checked_out` and `no_show` MUST hold no inventory; `pending`, `confirmed`
  and `checked_in` hold it (unchanged).

**No-show**

- **FR-005**: Staff MUST be able to mark a whole reservation a no-show when it is pending
  or confirmed, its arrival date has been reached in the hotel's local time, and none of
  its rooms is checked in or checked out.
- **FR-006**: Staff MUST be able to mark a single room line a no-show when its stay is
  expected and the reservation's arrival date has been reached.
- **FR-007**: Marking a no-show MUST set the affected stays to no-show, release their rooms
  from availability for the reservation's nights, leave room assignments as history, and
  create no cleaning task.
- **FR-008**: When every live room of a reservation is a no-show, the reservation MUST
  become `no_show`. When some rooms checked out and the rest are no-shows, the reservation
  MUST become `checked_out` on the last check-out; a no-show room MUST NOT block that
  check-out.
- **FR-009**: Staff MUST be able to undo a whole-reservation no-show at any time, with no
  time limit, and a single room's no-show only while the reservation is not checked out.
  The
  reservation returns to the status it had before; stays return to expected; availability
  is re-checked like any booking, with the existing overbook override.
- **FR-010**: Pending recommendations of a no-show reservation MUST expire and no pitch or
  proactive message MUST be sent for it; outcome matching MUST read the reservation's
  `no_show` status rather than inferring it from stays.
- **FR-010a**: While a reservation is `no_show`, staff MUST be able to change only its
  guest list (including guests' contact details) and special requests. A change to any
  other reservation field (dates, party, room lines, reservation code, source, value,
  currency or status) MUST be rejected, pointing to the undo-no-show operation.
- **FR-011**: No guest notification is sent when a no-show is marked or undone.
- **FR-011a**: The system MUST NOT mark a reservation or room a no-show on its own; every
  no-show is an explicit staff or Admin AI action.

**Authorization, AI and audit**

- **FR-012**: Marking and undoing a no-show MUST require a new `reservations.no_show`
  permission plus the same-hotel check. It is not granted to employees without a role by
  default. Admins hold it, as they hold every permission.
- **FR-013**: The Admin AI MUST get a tool to mark and undo a no-show (whole reservation or
  one room) through the same operation and permission. The Guest Concierge MUST NOT be able
  to mark or undo a no-show.
- **FR-014**: Every status change, no-show and undo MUST be audited with its actor,
  including the AI actor.
- **FR-014a**: Marking a no-show and undoing one MUST accept an optional free-text reason,
  recorded with that action in the reservation's history and shown there; it is never
  required.

**Visibility and docs**

- **FR-015**: Reservation responses, filters and the Admin AI read tools MUST show
  `no_show` as a status, and room lines MUST show when they are a no-show.
- **FR-016**: Arrivals lists MUST exclude no-show reservations and no-show rooms.
- **FR-017**: The reservations API documentation (with the lifecycle table), the
  permission reference in the staff-roles documentation, a latest-changes note and a
  frontend-changes document MUST be updated.

### Key Entities *(include if feature involves data)*

- **Reservation status** (changed): gains `no_show`, and every change between statuses is
  governed by the lifecycle table.
- **Reservation room line** (changed): can be marked a no-show individually; it then holds
  no inventory.
- **Stay** (unchanged statuses): already has a no-show status; this feature is the first
  to set it.
- **Permission** (new): `reservations.no_show`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of status changes outside the lifecycle table are rejected, across the
  staff API, the Admin AI and the dedicated operations (automated test over every
  from/to pair).
- **SC-002**: After a no-show is marked, its rooms are bookable for those nights
  immediately (the next availability check shows them free).
- **SC-003**: A multi-room reservation with one unarrived room can be fully checked out
  without cancelling anything.
- **SC-004**: No no-show reservation receives a pitch, proactive message or memory
  extraction (automated tests).
- **SC-005**: Every no-show and undo is traceable to its actor in the audit history.
- **SC-006**: An employee without the no-show permission gets 403, and another hotel's
  staff can't see or act on the reservation (automated permission and isolation tests).

## Assumptions

- The arrival "day" is the hotel's local calendar date, as for check-in.
- Confirmed → pending stays allowed (staff sometimes downgrade an unguaranteed booking).
- A no-show is only reversed through the undo operation, never by a plain edit, so the
  audit shows it was a correction.
- Cancelling and reactivating keep their current behaviour; this feature only names them
  in the table.
- No-show fees, deposits and charges are post-MVP (payments are out of scope).
- The reservation import keeps recording past statuses without the transition rules;
  Phase 13 will map legacy no-show labels to `no_show`.
- The frontend slice (no-show actions, status badge, filter) is delivered in
  `ecosystem-frontend` per D13.
