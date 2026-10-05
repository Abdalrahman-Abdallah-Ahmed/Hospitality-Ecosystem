# Feature Specification: Housekeeping and Maintenance

**Feature Branch**: `005-housekeeping-maintenance`

**Created**: 2026-10-04

**Status**: Draft

**Input**: Phase 5 — Housekeeping + Maintenance, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's three
backlog specs as one feature: SPEC-030 Housekeeping Lifecycle, SPEC-033 Maintenance &
Out-of-Order, SPEC-035 Automatic Maintenance Requests. It also includes the parts of
Phase 1 it depends on that have not shipped: SPEC-003 (room status split) and SPEC-004
(default teams and categories).

## Overview

Check-out (SPEC-025) now marks a room dirty and creates a cleaning task, but nothing
happens after that. Starting or finishing the task does not change the room, so the front
desk cannot tell which rooms are ready to sell. Rooms guests are still staying in get no
cleaning task at all. When a housekeeper finds a broken fixture there is no way to hand it
to maintenance, and taking a room out of service has no reason, no owner and no way back.

This feature connects the room's state to the work done on it:

- **Housekeeping lifecycle.** A room moves dirty → cleaning → clean → inspected as its
  housekeeping tasks are started and completed. Inspection is a per-hotel choice.
- **Automatic cleaning tasks** after check-out and for rooms with guests staying on.
- **Maintenance.** Maintenance tasks go to the Maintenance team. A housekeeper can report
  a room issue from their task, which creates a maintenance task automatically.
- **Out of order.** Staff can take a room out of service with a reason. Availability
  drops at once, and the room comes back only through an explicit return-to-service action.
- **Notifications** to housekeeping and maintenance staff on new and reassigned tasks,
  each delivered once.

**Terms**:

- *Housekeeping status* is how a room stands with cleaning: *dirty*, *cleaning*, *clean*
  or *inspected*. It is separate from the *room status* (*available*, *occupied*,
  *out of order*). An occupied room can be dirty or clean.
- *Ready* means a room whose housekeeping status is clean, or inspected when the hotel
  requires inspection.
- A *housekeeping task* is a task in one of the hotel's housekeeping categories: a
  *cleaning task* or an *inspection task*. A *maintenance task* is a task in one of the
  hotel's maintenance categories.
- A *room issue* is a fault a staff member records against a room (for example "shower
  leaking"). It becomes a maintenance task.
- A *stay-over room* is a room with an in-house stay that is not departing that hotel day.
- The *hotel day* is the date in the hotel's local time.

**In scope**: the room status split and moving existing statuses to it (SPEC-003, D5);
default Housekeeping and Maintenance teams and categories for every hotel (SPEC-004, D7);
the housekeeping status lifecycle driven by tasks; per-hotel inspection
setting; automatic cleaning tasks on check-out and for stay-over rooms; staff setting a
room's housekeeping status directly; maintenance tasks routed to the Maintenance team;
reporting a room issue from a housekeeping task; taking a room out of order and returning
it to service; availability reflecting out-of-order rooms; housekeeping and maintenance
notifications without duplicates; the housekeeping board and maintenance list data;
permissions, audit and documentation.

**Out of scope (later specs)**: guests requesting housekeeping or reporting faults through
the WhatsApp Concierge, and notifying the guest when their request is done (Phase 7,
SPEC-044 / SPEC-053). Admin AI housekeeping and maintenance tools (Phase 9, SPEC-055).
Moving an in-house guest to another room (SPEC-021 reassignment). Linen and minibar
inventory, lost and found, preventive maintenance schedules, spare parts and contractor
costs, staff shift planning and room-credit workload balancing (post-MVP).

## Clarifications

### Session 2026-10-04

- Q: When does a stay-over room get an automatic cleaning task? → A: Every day, for every
  stay-over room that is not out of order.
- Q: Is out of order open-ended or a dated period? → A: Open-ended until returned to
  service, with an optional expected end date that staff see but availability does not use.
- Q: SPEC-003 (status split) and SPEC-004 (default teams) have not shipped. Separate spec
  or part of this one? → A: Part of this feature.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A room becomes ready again as housekeeping cleans it (Priority: P1)

After a guest checks out, a housekeeper opens the cleaning task for that room and starts
it. The room shows as *cleaning*. When the housekeeper completes the task, the room shows
as *clean*, and the front desk can see it is ready for the next guest.

**Why this priority**: Without it the front desk has no reliable way to know which vacated
rooms can be sold again. It closes the check-out → dirty → clean loop in the MVP gate.

**Independent Test**: Check a guest out, start and then complete the cleaning task that
was created, and confirm the room's housekeeping status goes dirty → cleaning → clean with
one audit entry for each change.

**Acceptance Scenarios**:

1. **Given** a dirty room with a pending cleaning task, **When** the task is started,
   **Then** the room's housekeeping status becomes *cleaning*.
2. **Given** a room in *cleaning*, **When** its cleaning task is completed and the hotel
   does not require inspection, **Then** the room becomes *clean*.
3. **Given** a room in *cleaning*, **When** its cleaning task is cancelled or deleted,
   **Then** the room goes back to *dirty*.
4. **Given** a cleaning task that is completed a second time (repeated request), **When**
   it is processed, **Then** the room's status and the audit trail change only once.
5. **Given** an occupied room (stay-over), **When** its cleaning task is completed,
   **Then** the housekeeping status becomes *clean* and the room status stays *occupied*.

---

### User Story 2 - A supervisor inspects cleaned rooms (Priority: P2)

A hotel that wants a quality check turns inspection on. When a cleaning task is
completed, the room becomes *clean* and an inspection task is created for the
housekeeping supervisors. A pass makes the room *inspected*. A fail sends the room back to
*dirty* with a new cleaning task and the supervisor's note.

**Why this priority**: Many hotels only sell rooms a supervisor has checked, but others
don't inspect at all, so it is valuable but not needed for the basic loop.

**Independent Test**: With inspection on, complete a cleaning task, then pass the
inspection task (room → inspected), and in a second run fail it (room → dirty, new
cleaning task carrying the note).

**Acceptance Scenarios**:

1. **Given** inspection is on, **When** a cleaning task is completed, **Then** the room
   becomes *clean* and exactly one inspection task is created for it.
2. **Given** an open inspection task, **When** it is completed as passed, **Then** the
   room becomes *inspected*.
3. **Given** an open inspection task, **When** it is completed as failed with a note,
   **Then** the room becomes *dirty* and a new cleaning task is created containing the
   note.
4. **Given** inspection is off, **When** a cleaning task is completed, **Then** no
   inspection task is created and the room counts as ready at *clean*.
5. **Given** inspection is turned off while inspection tasks are open, **When** the
   setting is saved, **Then** those tasks stay open and still work, and no new ones are
   created.

---

### User Story 3 - Rooms with guests staying on get a daily cleaning task (Priority: P2)

Each morning, every stay-over room gets a cleaning task automatically, so housekeeping
starts the day with the full worklist, not just the check-outs.

**Why this priority**: Daily service is part of normal operations, and today those rooms
are marked dirty overnight with no task to clean them.

**Independent Test**: With a guest in-house for three nights, run the start-of-day
process and confirm the room is dirty with one stay-over cleaning task; run it again the
same day and confirm nothing new is created.

**Acceptance Scenarios**:

1. **Given** a stay-over room, **When** the start-of-day process runs, **Then** the room
   becomes *dirty* and one stay-over cleaning task is created for it.
2. **Given** a room that already has an open cleaning task, **When** the start-of-day
   process runs, **Then** no second cleaning task is created.
3. **Given** a room departing that hotel day, **When** the start-of-day process runs,
   **Then** no stay-over task is created (check-out will create one).
4. **Given** an out-of-order room, **When** the start-of-day process runs, **Then** its
   status is not changed and no task is created.
5. **Given** the process ran once for a hotel day, **When** it runs again for the same
   day, **Then** no status or task changes.

---

### User Story 4 - A housekeeper reports a room issue (Priority: P1)

While cleaning room 204 a housekeeper finds the shower leaking. From the cleaning task they
report the issue with a short description and say whether the room can still be sold. A
maintenance task is created for the Maintenance team, and if the room cannot be sold it
goes out of order at once.

**Why this priority**: The constitution requires that issues found in housekeeping create
maintenance work automatically. Without it, faults are lost and broken rooms get sold.

**Independent Test**: Report an issue from a cleaning task with "room cannot be sold",
and confirm a maintenance task exists, linked to the room and the cleaning task, assigned
to the Maintenance team; the room is out of order; and availability for that room type
dropped by one.

**Acceptance Scenarios**:

1. **Given** an open or just-completed housekeeping task, **When** a room issue is
   reported on it, **Then** one maintenance task is created for that room, in the hotel's
   default maintenance category, assigned to the Maintenance team, linked to the
   housekeeping task, and recording who reported it.
2. **Given** the issue is reported as "room cannot be sold" on an unoccupied room,
   **When** it is saved, **Then** the room becomes *out of order* with the issue as the
   reason, in the same action.
3. **Given** the issue is reported as "room cannot be sold" on an occupied room, **When**
   it is saved, **Then** the maintenance task is created, the room stays occupied, and
   the response says the room could not be taken out of order while a guest is in it.
4. **Given** the same report is sent twice, **When** it is processed, **Then** only one
   maintenance task exists.
5. **Given** the reporter can create tasks but cannot take rooms out of order, **When**
   they report an issue as "room cannot be sold", **Then** the maintenance task is
   created and the room is not taken out of order, and the response says so.

---

### User Story 5 - Staff take a room out of order and return it to service (Priority: P1)

A manager takes room 310 out of order because of a broken air conditioner. They give a
reason and, optionally, the maintenance task that tracks it. The room disappears from
assignable stock and availability for its type drops immediately. When the repair is
finished, completing the maintenance task does not reopen the room on its own. A manager
returns it to service, and it comes back as available and dirty with a cleaning task.

**Why this priority**: Selling a broken room is the most visible failure a hotel can have,
and availability (SPEC-020) depends on the out-of-order count being right.

**Independent Test**: Take an unoccupied room out of order, confirm availability falls by
one and the room cannot be assigned; complete its maintenance task and confirm the room is
still out of order; return it to service and confirm it is available, dirty, has a
cleaning task, and availability is back.

**Acceptance Scenarios**:

1. **Given** an unoccupied room, **When** it is taken out of order with a reason, **Then**
   its room status becomes *out of order*, the reason, actor and time are recorded, and
   availability for its room type drops by one at once.
2. **Given** an occupied room, **When** someone tries to take it out of order, **Then** it
   is rejected with a message naming the in-house stay.
3. **Given** a room assigned to future reservation lines, **When** it is taken out of
   order, **Then** it succeeds and the response lists the affected lines so staff can
   reassign them.
4. **Given** an out-of-order room whose maintenance task is completed, **When** nothing
   else happens, **Then** the room stays out of order and the response to completing the
   task says it is ready to return to service.
5. **Given** an out-of-order room, **When** it is returned to service, **Then** it becomes
   *available*, its housekeeping status becomes *dirty*, and one cleaning task is created.
6. **Given** an out-of-order room, **When** someone tries to assign it or check a guest
   into it, **Then** it is rejected (existing rule, unchanged).

---

### User Story 6 - Housekeeping and maintenance staff are told about their tasks (Priority: P2)

When a cleaning, inspection or maintenance task is created or reassigned, the person it is
assigned to is notified. If it is assigned only to a team, the team's active members are
notified. Nobody gets the same notice twice.

**Why this priority**: Automatic tasks are only useful if someone sees them, but staff can
also work from the board, so it follows the core lifecycle.

**Independent Test**: Check a guest out (cleaning task to the Housekeeping team) and
confirm each active team member gets one notice; assign the task to one housekeeper and
confirm only they get a new notice; resend the same assignment and confirm no new notice.

**Acceptance Scenarios**:

1. **Given** a new task assigned to a team and no person, **When** it is created, **Then**
   every active member of that team is notified once.
2. **Given** a new task assigned to a person, **When** it is created, **Then** only that
   person is notified, once.
3. **Given** a task is reassigned to a different person, **When** it is saved, **Then**
   the new person is notified once and the previous one is not notified again.
4. **Given** a task is saved again with the same assignee, **When** it is processed,
   **Then** no notice is sent.
5. **Given** a task is cancelled or deleted before its notice goes out, **When** delivery
   runs, **Then** nothing is sent.

---

### User Story 7 - The housekeeping board and maintenance list (Priority: P2)

Housekeeping supervisors see every room grouped by housekeeping status, with its room
status, the guest's departure if occupied, and today's open housekeeping tasks.
Maintenance staff see open maintenance tasks with their room, reporter, age and whether the
room is out of order.

**Why this priority**: These views are how staff run the day, but the lifecycle works
without them.

**Independent Test**: With rooms in each status and open tasks of each kind, load the
board and the list and confirm the counts and grouping match the data, and that another
hotel's rooms and tasks never appear.

**Acceptance Scenarios**:

1. **Given** rooms in each housekeeping status, **When** the board is loaded, **Then**
   each room appears once under its status, with its room status, its departure date if
   occupied, and its open housekeeping task if any.
2. **Given** filters by floor, building, housekeeping status or team, **When** they are
   applied, **Then** only matching rooms are shown.
3. **Given** open maintenance tasks, **When** the maintenance list is loaded, **Then** it
   shows each with room, priority, reporter, age and the room's out-of-order state, oldest
   urgent first.
4. **Given** a user from another hotel, **When** they load either view, **Then** they see
   none of this hotel's rooms or tasks.

---

### User Story 8 - Staff correct a room's housekeeping status by hand (Priority: P3)

A supervisor sees that a room marked dirty was in fact cleaned without a task. They set its
housekeeping status to clean directly, giving a reason.

**Why this priority**: Tasks drive status normally, so this is a correction path for when
reality and the system disagree.

**Independent Test**: Set a dirty room to clean by hand and confirm the change, reason and
actor appear in the audit trail, and that an open cleaning task for it is reported in the
response but not changed.

**Acceptance Scenarios**:

1. **Given** a user allowed to update housekeeping status, **When** they set a room's
   status with a reason, **Then** the room changes and the audit records the old and new
   status, reason and actor.
2. **Given** the room has an open housekeeping task, **When** its status is set by hand,
   **Then** the task is left as is and the response lists it.
3. **Given** a user without that permission, **When** they try, **Then** they get a
   permission error.

---

### User Story 9 - Every hotel has Housekeeping and Maintenance teams ready to use (Priority: P1)

A new hotel is created and already has a Housekeeping team (with Cleaning and Inspection
categories) and a Maintenance team (with a Maintenance category), and the hotel's settings
point at them. Existing hotels get the same when the feature is released. Rooms that were
"blocked" or "maintenance" before now show as out of order.

**Why this priority**: Every automatic task in this feature is routed to these teams and
categories, and every out-of-order rule reads the new room status.

**Independent Test**: Create a hotel and confirm both teams, their categories and the
hotel's default settings exist. Run the release step on a hotel that had a blocked room and
confirm the room is out of order and dirty.

**Acceptance Scenarios**:

1. **Given** a new hotel is created, **When** creation finishes, **Then** it has a
   Housekeeping team with Cleaning and Inspection categories, a Maintenance team with a
   Maintenance category, and its default team and category settings point at them.
2. **Given** an existing hotel whose admin already chose a housekeeping team or cleaning
   category, **When** the release step runs, **Then** those choices are kept and only the
   missing defaults are created.
3. **Given** the release step has already run, **When** it runs again, **Then** no
   duplicate teams or categories are created.
4. **Given** a room with housekeeping status *blocked* or room status *maintenance* and no
   in-house stay, **When** the release step runs, **Then** its room status becomes *out of
   order* (reason "Migrated from previous status") and its housekeeping status *dirty*.
5. **Given** a default team or category, **When** an admin renames it, **Then** automatic
   tasks still go to it, because it is found through the hotel setting, not by name.

---

### Edge Cases

- A room has a stay-over cleaning task open when the guest checks out: check-out does not
  create a second cleaning task. The open task is kept and marked as a check-out clean.
- A cleaning task is completed while its room is out of order: the housekeeping status
  changes as usual. The room stays out of order.
- A housekeeping task is moved to a non-housekeeping category, or a task in another
  category is moved into a housekeeping category: only the task's state from then on drives
  the room; earlier changes are not replayed.
- A completed cleaning task is reopened: the room goes back to *cleaning* if the task is in
  progress, or *dirty* if pending, unless the room has another open housekeeping task. In
  that case the newer task controls the room.
- A cleaning or inspection task has no room: it is a normal task and changes no room.
- The hotel's Housekeeping or Maintenance team, or its default category, is missing,
  deleted or inactive: the automatic task is still created, without that team or
  category, and the hotel's admins are told once that defaults need fixing.
- The Maintenance team has no active members: the task is created and assigned to the
  team, and the hotel's admins are notified instead.
- A room is taken out of order twice: the second request changes nothing and says so.
- A room not out of order is returned to service: rejected with a clear message.
- An out-of-order room has a check-out still running in the same moment: the actions are
  serialized. Whichever finishes second sees the other's result.
- Two staff start the same cleaning task at once: one change, one audit entry.
- An issue is reported on a room that already has an open maintenance task for the same
  issue text: a second maintenance task is created. Staff decide whether they are
  duplicates, because the system cannot tell two faults apart reliably.
- The start-of-day process runs late, after the hotel day has begun: it uses the hotel day
  it was meant for, so the result is the same as an on-time run.
- A hotel in a different time zone: its start-of-day processing follows its own local
  date.
- A *blocked* or *maintenance* room that has an in-house stay at release: it becomes
  occupied and dirty, not out of order, and is listed in the release report so staff can
  handle it.
- An out-of-order room's expected end date passes and nobody returns it to service: it
  stays out of order, and the maintenance list flags it as overdue.

## Requirements *(mandatory)*

### Functional Requirements

**Room status split (SPEC-003, D5)**

- **FR-037**: A room's status MUST be one of *available*, *occupied* or *out of order*, and
  its housekeeping status one of *dirty*, *cleaning*, *clean* or *inspected*. The two MUST
  be separate values.
- **FR-038**: Existing data MUST be moved without loss. Room status *maintenance* and
  housekeeping status *blocked* become room status *out of order* with housekeeping status
  *dirty*. A room with an in-house stay becomes occupied and dirty instead, and is
  reported. Every other value keeps its meaning.
- **FR-039**: Everything that reads the old values MUST use the new ones: availability,
  assignment, check-in, check-out, overnight dirtying, AI tools, filters and API
  responses. Clients that send an old value MUST get a rejection naming the new values.

**Default teams and categories (SPEC-004, D7)**

- **FR-040**: Every hotel MUST have a Housekeeping team with Cleaning and Inspection
  categories, and a Maintenance team with a Maintenance category. They MUST be created
  when a hotel is created, and once for every existing hotel.
- **FR-041**: The hotel's settings MUST name its default housekeeping team, cleaning
  category, inspection category, maintenance team and maintenance category. Creating the
  defaults MUST fill only empty settings and MUST NOT overwrite an admin's choice.
- **FR-042**: Creating the defaults MUST be idempotent: running it again creates nothing.
  The defaults are ordinary teams and categories that admins may rename, edit or
  deactivate.

**Housekeeping lifecycle (SPEC-030)**

- **FR-001**: Each room MUST carry a housekeeping status of *dirty*, *cleaning*, *clean*
  or *inspected*, separate from its room status.
- **FR-002**: The housekeeping status MUST change only through one domain operation used
  by every path that moves it: task changes, check-out, the start-of-day process, issue
  reports, return to service and manual correction. The same rules apply to staff and to
  any AI action.
- **FR-003**: Starting a cleaning task (pending → in progress) MUST set its room to
  *cleaning*. Completing it MUST set the room to *clean*. Cancelling or deleting an open
  cleaning task MUST set a room in *cleaning* back to *dirty*.
- **FR-004**: Each hotel MUST have an "inspection required" setting, off by default.
  When it is on, completing a cleaning task MUST create one inspection task for the room
  in the hotel's inspection category, assigned to the Housekeeping team.
- **FR-005**: Completing an inspection task MUST record a pass or a fail. A pass MUST set
  the room to *inspected*. A fail MUST require a note, set the room to *dirty*, and create
  a new cleaning task containing the note.
- **FR-006**: The system MUST expose for each room whether it is *ready*: clean when
  inspection is off, inspected when it is on (clean also counts as ready for a room whose
  last cleaning was completed before inspection was turned on).
- **FR-007**: A room MUST have at most one open cleaning task and at most one open
  inspection task at a time. Any automatic step that would create a second one MUST reuse
  the open one instead.
- **FR-008**: Only events on a room's current open housekeeping task, or on the task just
  completed, MUST change the room. A change on an older task that a newer one replaced
  MUST NOT move the room.
- **FR-009**: Users with `rooms.update_housekeeping_status` MUST be able to set a room's
  housekeeping status directly with a required reason. It MUST NOT change any task. Open
  housekeeping tasks for the room MUST be listed in the response.
- **FR-010**: Every housekeeping status change MUST be audited with old and new status,
  the cause (task, check-out, start of day, issue, return to service, manual), the task if
  any, and the actor (staff, system or AI).

**Automatic cleaning tasks (SPEC-030)**

- **FR-011**: Check-out MUST keep creating a cleaning task (SPEC-025), now subject to
  FR-007. If a stay-over task is open, check-out MUST reuse it and mark it as a check-out
  clean.
- **FR-012**: A start-of-day process MUST run once per hotel per hotel day. For each
  stay-over room that is not out of order it MUST set the room *dirty* and create a
  stay-over cleaning task. Every stay-over room gets one each hotel day.
- **FR-013**: The start-of-day process MUST be idempotent per hotel day. Running it again,
  late or concurrently MUST NOT create more tasks or status changes.
- **FR-014**: Automatic cleaning tasks MUST use the hotel's housekeeping team and cleaning
  category, carry the room, stay, reservation and guest, and have a due time on the same
  hotel day.

**Maintenance and out of order (SPEC-033)**

- **FR-015**: A task created in one of the hotel's maintenance categories with no team
  MUST be assigned to the Maintenance team. Likewise a task in a housekeeping category with
  no team MUST be assigned to the Housekeeping team. A team chosen explicitly MUST be kept.
- **FR-016**: Users with `rooms.set_out_of_order` MUST be able to take a room out of order
  with a required reason and, optionally, the maintenance task that tracks it and an
  expected end date. The room status MUST become *out of order*. Who did it, when, the
  reason and the expected end date MUST be kept on the room until it returns to service.
  The expected end date MAY be changed while the room is out of order.
- **FR-017**: Taking a room out of order MUST be rejected while it has an in-house stay.
  It MUST succeed when the room is assigned to future lines, and the response MUST list
  those lines.
- **FR-018**: Out-of-order rooms MUST be excluded from availability and from room
  assignment and check-in from the moment the change is saved, on every date, until it is
  returned to service. The expected end date is for staff information only, and
  availability MUST NOT use it.
- **FR-019**: Completing a maintenance task MUST NOT return its room to service. If the
  room is out of order and the task is linked to it, the response MUST say the room is
  ready to return to service.
- **FR-020**: Users with `rooms.set_out_of_order` MUST be able to return an out-of-order
  room to service. The room MUST become *available*, its housekeeping status *dirty*, and
  one cleaning task MUST be created (FR-007 applies).
- **FR-021**: Taking a room out of order and returning it to service MUST each be audited
  with actor, reason and linked maintenance task.

**Automatic maintenance requests (SPEC-035)**

- **FR-022**: Users who can update a housekeeping task MUST be able to report a room issue
  on it, with a description, an optional priority and a "room cannot be sold" flag. This
  works while the task is open and on the hotel day it was completed.
- **FR-023**: A reported issue MUST create one maintenance task for that room in the
  hotel's default maintenance category, assigned to the Maintenance team, linked to the
  housekeeping task it came from, and recording the reporter. The issue report and the
  task MUST be saved together or not at all.
- **FR-024**: When the issue is flagged "room cannot be sold" and the reporter also has
  `rooms.set_out_of_order`, the room MUST be taken out of order in the same action, with
  the issue as the reason and the new task linked (FR-016, FR-017 apply). Without that
  permission, or if FR-017 rejects it, the task MUST still be created and the response
  MUST say the room was not taken out of order and why.
- **FR-025**: Repeating the same issue report (same request sent twice) MUST NOT create a
  second maintenance task.

**Notifications**

- **FR-026**: When a housekeeping or maintenance task is created or its assignee changes,
  the system MUST notify the assigned person, or, if only a team is assigned, every active
  member of that team.
- **FR-027**: Each recipient MUST get at most one notice per task per assignment. Saving a
  task without changing its assignee, retrying delivery, or a task assigned to a team and
  then to a member of it MUST NOT notify the same person twice for the same assignment.
- **FR-028**: Notices MUST be sent only after the change is saved. Nothing is sent for a
  task cancelled or deleted before delivery.
- **FR-029**: When an automatic task cannot be routed (no team, or the team has no active
  members), the hotel's admins MUST be notified, at most once per hotel per hotel day for
  the same cause.

**Views**

- **FR-030**: The system MUST provide a housekeeping board listing the hotel's rooms with
  housekeeping status, room status, readiness, departure date if occupied, and open
  housekeeping task, filterable by housekeeping status, room status, floor, building and
  team, with counts per housekeeping status.
- **FR-031**: The system MUST provide a maintenance list of maintenance tasks filterable by
  status, priority, room and out-of-order state, showing room, reporter, source
  housekeeping task, age, and the room's out-of-order reason and expected end date. Rooms
  whose expected end date has passed MUST be flagged.

**Permissions, tenancy and audit**

- **FR-032**: Add `rooms.update_housekeeping_status` and `rooms.set_out_of_order`. Task
  actions (start, complete, cancel, report issue, view the maintenance list) use the
  existing `tasks.*` permissions; the housekeeping board uses `rooms.view`. Inspection
  required is a hotel setting, admin-only.
- **FR-033**: Employees without a staff role MUST NOT get the new permissions by default.
- **FR-034**: Every action, view, task, notice and background process in this feature MUST
  be limited to one hotel. No user, process or AI action may read or change another
  hotel's rooms or tasks.
- **FR-035**: Every operation that changes several records (task + room, issue +
  maintenance task + out of order, return to service + cleaning task) MUST complete fully
  or not at all, and MUST be safe to repeat.
- **FR-036**: The API documentation for rooms, tasks and hotel settings, and the
  permission reference, MUST be updated.

### Key Entities

- **Room**: room status (available / occupied / out of order) and housekeeping status
  (dirty / cleaning / clean / inspected) as separate values. Gains the out-of-order reason,
  who set it and when, an optional expected end date, and a link to the maintenance task
  tracking it.
- **Task**: the common work unit. Housekeeping and maintenance work are tasks whose kind
  comes from their category. Gains: a cleaning kind (check-out / stay-over / re-clean
  after failed inspection), an inspection result (pass / fail and note), and a link to the
  task a maintenance task came from.
- **Task Category**: belongs to a team. The hotel's Housekeeping team's categories are
  housekeeping categories; the Maintenance team's are maintenance categories.
- **Hotel settings**: inspection required (default off), and the default
  housekeeping team, cleaning category, inspection category, maintenance team and
  maintenance category.
- **Room issue**: what a staff member reported, by whom, from which task, and whether the
  room can still be sold. Recorded as the maintenance task it creates, not as a separate
  record.
- **Housekeeping audit entry**: a room's status change with cause, task, actor and time.
- **Start-of-day run**: a record that a hotel's start-of-day process has run for a hotel
  day, so it never runs twice.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After every check-out, a room goes dirty → cleaning → clean (→ inspected
  when required) using only the housekeeping task's start and complete actions, with no
  manual room edit.
- **SC-002**: At the start of each hotel day, 100% of stay-over rooms that are not out of
  order have exactly one open cleaning task, and re-running the day adds 0 tasks.
- **SC-003**: No room ever has more than one open cleaning task or more than one open
  inspection task.
- **SC-004**: A room taken out of order stops being offered by availability and assignment
  immediately after the change is saved, and 0 check-ins into an out-of-order room succeed.
- **SC-005**: A housekeeper can report a room issue from their task in one action, and the
  Maintenance team is notified without anyone re-entering the issue.
- **SC-006**: Every assignee gets exactly one notice per task assignment across retries,
  re-saves and repeated requests.
- **SC-007**: Every housekeeping status change, out-of-order change and return to service
  has an audit entry naming its actor and cause.
- **SC-008**: The housekeeping board loads in under 2 seconds for a hotel with 500 rooms.
- **SC-009**: No user, process or AI action from one hotel can see or change another
  hotel's rooms, housekeeping tasks or maintenance tasks.
- **SC-010**: After release, 100% of hotels have both default teams and their categories,
  and 100% of previously blocked or maintenance rooms are out of order or listed in the
  release report.

## Assumptions

- SPEC-003 (room status split) and SPEC-004 (default teams) had not shipped, so they are
  part of this feature (Clarifications). The plan should deliver them first because the
  rest builds on them.
- Default team and category names are created in English. Admins can rename them, and
  everything finds them through the hotel settings, not by name.
- Stay-over rooms are cleaned daily. Letting guests decline service, and per-hotel
  intervals, are post-MVP.
- Inspection is optional per hotel and off by default, as the plan states. Hotels that
  turn it on sell rooms only once inspected (readiness, FR-006). Check-in still only warns
  for a room that is not ready (SPEC-024 decision); it is not blocked.
- Completing a maintenance task never reopens a room by itself. Returning to service is an
  explicit staff action, as the plan states.
- A room returned to service always needs a clean, because repair work leaves it unfit for
  a guest.
- Out of order is refused for an occupied room. Moving the guest first is a staff room
  change (SPEC-021); there is no "occupied and out of order" state.
- The overnight job that marks slept-in rooms dirty is replaced by the start-of-day
  process, which also creates the tasks.
- Notifications use the existing staff channel (email). In-app or push channels are not
  added here.
- Housekeeping or maintenance requests from guests, and telling guests a request is done,
  come in Phase 7. Admin AI tools for this domain come in Phase 9. Both will call the same
  operations as staff (FR-002).
- The frontend slice (housekeeping board, maintenance list, task actions including "report
  issue", out-of-order and return-to-service actions, inspection setting) is delivered in
  `ecosystem-frontend` in the same phase (decision D13).
