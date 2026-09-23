# Feature Specification: Reservation Rooms

**Feature Branch**: `002-reservation-rooms`

**Created**: 2026-09-23

**Status**: Draft

**Input**: Phase 2 — Reservation Restructure, first spec (SPEC-010 Reservation Rooms); read from docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md (decision D2)

## Overview

Today a reservation points at exactly one physical room. A real booking is "2 × Deluxe
and 1 × Suite, 12–15 March", and the physical rooms are chosen later. This spec replaces
the single room on a reservation with **reservation rooms**: one line per room unit, each
naming the room type booked and, optionally, the physical room it will occupy.

**Terms**: a *reservation room* is also called a *line*; each line is one *room unit*.

**In scope**: the reservation-room concept, creating and editing a reservation by
room-type lines, migrating existing reservations, keeping room occupancy, stays and the AI
reservation tools working on the new shape.

**Out of scope (later specs)**: additional guests and children's ages (SPEC-011), the
`no_show` status and transition rules (SPEC-012), the voucher (SPEC-013), availability
checks and overbooking protection (SPEC-020), dedicated assign/reassign operations and
overlap constraints (SPEC-021), one stay per room (SPEC-023), check-in/out endpoints
(SPEC-024/025), per-room dates and per-room pricing (post-MVP).

## Clarifications

### Session 2026-09-23

- Q: Until SPEC-021 ships, may staff set a physical room on a line when creating or editing a reservation? → A: Yes, optionally on both create and edit; the room must match the line's room type.
- Q: When a party exceeds the booked rooms' capacity, reject outright or allow override? → A: Reject by default; staff may explicitly override, the override is audited, and the AI can never override.
- Q: Can staff move a checked-in guest to another physical room by editing the line? → A: Yes; on a checked-in reservation only a line's physical room may change, to a room of the same type; old room released, new room occupied, move audited.
- Q: Block deletion of a room type still used by reservations? → A: Block while any non-cancelled line on a current or future reservation uses it; past and cancelled lines do not block.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Staff book several rooms of different types in one reservation (Priority: P1)

A front-desk agent takes a family booking for two Deluxe rooms and one Suite for the same
dates. They create one reservation listing each room type and its quantity, without
choosing physical rooms. The reservation shows three room lines, all sharing the
reservation's arrival and departure dates.

**Why this priority**: This is the core gap. Without it the hotel cannot record multi-room
or room-type bookings, and every later Phase 2–4 feature depends on reservation rooms.

**Independent Test**: Create a reservation with "2 × Deluxe, 1 × Suite" and no physical
rooms; read it back and confirm three room lines with the right types, no physical rooms
and the reservation's dates.

**Acceptance Scenarios**:

1. **Given** a hotel with active room types Deluxe and Suite, **When** staff create a
   reservation requesting 2 Deluxe and 1 Suite, **Then** the reservation is saved with
   exactly three room lines (two Deluxe, one Suite), each without a physical room.
2. **Given** a saved reservation, **When** staff view it, **Then** each room line shows its
   room type, its physical room (or "unassigned") and its status, and the reservation shows
   a per-type summary (e.g. "2 × Deluxe, 1 × Suite").
3. **Given** a request with no room lines, **When** staff submit it, **Then** it is rejected
   with a message that at least one room is required.
4. **Given** a room type that is inactive, deleted, or belongs to another hotel, **When**
   staff request it, **Then** the request is rejected and nothing is saved.
5. **Given** a party of 5 adults, **When** staff request room types whose combined maximum
   occupancy is 4, **Then** the request is rejected with a capacity message.
6. **Given** the same over-capacity request, **When** staff resubmit it with an explicit
   capacity override, **Then** the reservation is saved and the audit entry records the
   override and who made it.
7. **Given** an over-capacity request from the AI, **When** it asks for an override,
   **Then** the request is still rejected.

---

### User Story 2 - Existing reservations keep working after the change (Priority: P1)

The hotel already has reservations tied to single physical rooms. After the upgrade every
one of them appears as a reservation with one room line of that room's type, still linked
to the same physical room, with nothing lost and room occupancy unchanged.

**Why this priority**: The change must not break live data or operations (Preserve and
Evolve; MVP stays deployable).

**Independent Test**: Load a set of legacy reservations (assigned, in-house, cancelled,
past), run the upgrade, and compare: each has exactly one room line with the original
room and its type, and every room's occupancy is the same as before.

**Acceptance Scenarios**:

1. **Given** a legacy reservation for room 101 (a Deluxe), **When** the upgrade runs,
   **Then** the reservation has one room line: type Deluxe, physical room 101.
2. **Given** a legacy reservation whose room was later deleted, **When** the upgrade runs,
   **Then** the line keeps that room and its type.
3. **Given** a legacy reservation with no room, **When** the upgrade runs, **Then** it gets
   one line with the hotel's placeholder "Unspecified (migrated)" room type and no physical
   room, and the upgrade reports how many such reservations exist per hotel.
4. **Given** the upgrade has run once, **When** it runs again, **Then** no duplicate room
   lines are created.
5. **Given** a legacy reservation whose room belongs to another hotel, **When** the upgrade
   runs, **Then** it gets the placeholder type with no physical room, and is counted in the
   report.
6. **Given** room 101 was occupied before the upgrade, **When** the upgrade completes,
   **Then** room 101 is still occupied.

---

### User Story 3 - Staff change the rooms on an existing reservation (Priority: P2)

A guest calls to add a third room, or swap one Deluxe for a Suite. The agent edits the
reservation's room lines. Lines that are unchanged keep their identity and any physical
room already on them.

**Why this priority**: Changes are common, but a reservation can be cancelled and rebooked
as a workaround, so this follows creation and migration.

**Independent Test**: Take a reservation with 2 Deluxe (one with room 101), add one Suite
and remove the unassigned Deluxe; confirm the Deluxe with room 101 is untouched, one Suite
line exists, and the removed line is cancelled.

**Acceptance Scenarios**:

1. **Given** a pending or confirmed reservation, **When** staff add a room line, **Then** the
   new line is created (unassigned unless staff choose a room for it) and existing lines
   are unchanged.
2. **Given** a pending or confirmed reservation with more than one line, **When** staff
   remove a line, **Then** the line is cancelled and any physical room on it is released.
3. **Given** a reservation with one remaining line, **When** staff try to remove it,
   **Then** the request is rejected (a reservation needs at least one room; cancel the
   reservation instead).
4. **Given** a checked-out or cancelled reservation, **When** staff try to change its room
   lines, **Then** the request is rejected.
5. **Given** a checked-in reservation, **When** staff try to add or remove a line or change a
   line's room type, **Then** the request is rejected (only room moves are allowed, see
   User Story 4).
6. **Given** a reservation, **When** staff change its arrival or departure date, **Then** all
   room lines follow the new dates (there are no per-room dates).

---

### User Story 4 - Physical room on a line before dedicated assignment exists (Priority: P2)

Until dedicated room assignment ships (SPEC-021), staff still need to put a guest in a
specific room so check-in and occupancy keep working. When creating or editing a
reservation, staff may optionally put a physical room on any line.

**Why this priority**: Protects day-to-day operations during the gap between Phase 2 and
Phase 3.

**Independent Test**: Create a reservation with one Deluxe line and room 101 on it; confirm
the line holds room 101. Edit a second, unassigned line to add room 102; confirm it is
saved and the first line is unchanged.

**Acceptance Scenarios**:

1. **Given** a Deluxe room 101, **When** staff create a reservation with a Deluxe line and
   choose room 101, **Then** the line is saved with room 101.
2. **Given** an unassigned Deluxe line on a pending or confirmed reservation, **When** staff
   edit it to set Deluxe room 102, **Then** the line holds room 102.
3. **Given** a line for type Deluxe, **When** staff set a physical room of type Suite on it,
   **Then** the request is rejected (the room's type must match the line).
4. **Given** a checked-in guest in Deluxe room 101, **When** staff change the line to Deluxe
   room 105, **Then** room 101 is released, room 105 becomes occupied, and the move is in the
   audit log.
5. **Given** a checked-in guest in a Deluxe line, **When** staff try to move them to a
   Suite room, **Then** the request is rejected.
6. **Given** a reservation, **When** staff put the same physical room on two of its lines,
   **Then** the request is rejected.

---

### User Story 5 - AI and imports create reservations the same way (Priority: P2)

The Admin AI creates a reservation from "book 2 doubles for Ahmed, 3–5 May", and the
reservation import brings in legacy bookings. Both produce the same room lines, with the
same rules, as a reservation created by staff.

**Why this priority**: The constitution requires AI to act through the same domain
operation and authorization as humans; a second creation path would drift.

**Independent Test**: Create equivalent reservations through staff, the AI tool and the
import; confirm identical room lines and identical rejections for invalid input.

**Acceptance Scenarios**:

1. **Given** an admin asks the AI to book 2 rooms of one type, **When** the AI creates the
   reservation, **Then** it has two lines of that type and the audit log names the AI actor.
2. **Given** a guest asks the Concierge about their booking, **When** it reads the
   reservation, **Then** it sees the room types booked and assigned room numbers only for
   that guest's own reservation.
3. **Given** an import row naming a physical room, **When** it is imported, **Then** the
   reservation gets one line of that room's type with that room.
4. **Given** the same import file is run twice, **When** it finishes, **Then** no duplicate
   reservations or lines exist.

---

### Edge Cases

- Requesting 0 or a negative quantity of a room type → rejected.
- Requesting more than 50 room units in one reservation → rejected (larger groups are split
  into several reservations).
- A room type is deactivated after a reservation was made with it → existing lines are
  unaffected; the type cannot be added to new lines.
- A room type is deleted while current or future non-cancelled lines use it → the deletion
  is rejected with a message naming the number of affected reservations (FR-028). Past or
  cancelled lines do not block it.
- The reservation is cancelled → every line becomes cancelled and its physical room is
  released; the lines are kept for history.
- A physical room on a line is later deleted → the line keeps its history; the room is
  shown as removed.
- A migrated reservation's party is larger than its line's capacity (legacy data) → it stays
  as is; the next edit that changes lines, adults or children needs a staff capacity
  override.
- Two staff edit the same reservation's lines at the same time → last write wins (same as
  the rest of the reservation today).
- A request tries to attach a room type or physical room from another hotel → rejected as
  an invalid reference, with no hint that the record exists.

## Requirements *(mandatory)*

### Functional Requirements

**Reservation rooms**

- **FR-001**: Every reservation MUST have one or more reservation rooms. Each reservation
  room represents exactly one room unit ("2 × Deluxe" is two reservation rooms).
- **FR-002**: Each reservation room MUST name a room type and MAY name a physical room.
  When a physical room is named, its type MUST match the line's room type.
- **FR-003**: Each reservation room MUST have a status. This spec defines `reserved` and
  `cancelled`; later specs (assignment, check-in/out, no-show) extend it.
- **FR-004**: All reservation rooms MUST use the reservation's arrival and departure dates.
  A reservation room MUST NOT carry its own dates.
- **FR-005**: The same physical room MUST NOT appear on two non-cancelled lines of the same
  reservation.
- **FR-006**: A reservation MUST NOT hold more than 50 non-cancelled room units.

**Creating and changing**

- **FR-007**: Staff MUST be able to create a reservation by listing room types with a
  quantity for each. The system expands each quantity into individual reservation rooms.
- **FR-008**: Only active, non-deleted room types of the reservation's own hotel MAY be
  used for new lines.
- **FR-009**: The reservation's total adults plus children MUST NOT exceed the combined
  maximum occupancy of its non-cancelled lines, and its adults MUST NOT exceed their
  combined adult capacity. This applies on staff and AI create and update, including when
  lines are removed or the party changes. Staff MAY bypass it with an explicit capacity
  override on that request; the override MUST be recorded in the audit entry with the
  actor. The AI MUST NOT override. The import and migration record legacy data without
  running this check.
- **FR-010**: Staff MUST be able to add and remove lines on a `pending` or `confirmed`
  reservation. Removing a line cancels it and releases any physical room on it. Unchanged
  lines MUST keep their identity and physical room.
- **FR-011**: Lines MUST NOT be changed on a `checked_out` or `cancelled` reservation. On a
  `checked_in` reservation, lines MUST NOT be added, removed or change room type; the only
  allowed change is a room move: replacing a line's physical room with another room of the
  same type. A room move MUST release the old room, mark the new room occupied, and be
  recorded in the audit log. The old room's housekeeping status is not changed by this
  spec (Phase 5).
- **FR-012**: Cancelling a reservation MUST cancel all of its lines and release their
  physical rooms.
- **FR-013**: A reservation, its lines and any occupancy change MUST be saved together or
  not at all; a failure MUST NOT leave a reservation with missing or partial lines.

**Interim physical room choice**

- **FR-014**: Staff MAY optionally set, change or clear the physical room on a line when
  creating or editing a `pending` or `confirmed` reservation, following FR-002 and FR-005.
  No overlap check against other reservations is added here (unchanged from today);
  SPEC-021 adds dedicated assignment and overlap protection and decides whether this
  path stays.

**Existing behavior kept on the new shape**

- **FR-015**: Room occupancy MUST be derived from the physical rooms on the lines of
  in-house reservations, producing the same result as today for single-room reservations.
- **FR-016**: The reservation's stay MUST continue to behave as today (one stay per
  reservation) until SPEC-023 changes it.
- **FR-017**: The reservation view MUST show each line (room type, physical room or
  unassigned, status) and a per-type count summary. The single top-level physical room
  field MUST be removed from reservation requests and responses; this breaking change MUST
  be announced in the latest-changes document and the reservations API documentation.
- **FR-018**: Reservation list filters by room type and by physical room MUST match any
  line of the reservation.
- **FR-019**: The staff API, the Admin AI reservation tools, and the reservation import
  MUST all create and read reservations through the same domain operation and rules. The
  import differs only in that it skips the capacity check (FR-009) and, as today, creates
  missing room types and rooms instead of rejecting them.
- **FR-020**: The guest-facing reservation lookup MUST show only the requesting guest's own
  reservation, including its room types and assigned room numbers.

**Authorization, tenancy and audit**

- **FR-021**: Reading lines requires the existing reservation view permission; creating or
  changing lines requires the existing reservation create/update permission. No new
  permission is introduced by this spec.
- **FR-022**: Reservation rooms MUST belong to the reservation's hotel; no user or AI of
  another hotel may see or change them.
- **FR-023**: Creating, adding, removing and cancelling lines MUST be recorded in the audit
  log with the actor (staff user or AI agent). Exceptions: a bulk import records one
  summary event for the whole file (as today), and the one-time migration backfill writes
  no audit entries.

**Migration**

- **FR-024**: The upgrade MUST give every existing reservation (including cancelled and
  soft-deleted ones) exactly one line, using its current physical room and that room's type,
  including rooms that were since deleted.
- **FR-025**: Reservations without a resolvable room (no room, or a room belonging to
  another hotel) MUST get a line with a per-hotel,
  inactive placeholder room type "Unspecified (migrated)", created only for hotels that need
  it. The upgrade MUST report the count per hotel.
- **FR-026**: The upgrade MUST be safe to run more than once without creating duplicates,
  and MUST leave every room's occupancy unchanged.
- **FR-027**: After the upgrade, the reservation's single physical-room link MUST no longer
  be used by any part of the system.

**Room types**

- **FR-028**: Deleting a room type MUST be rejected while any non-cancelled line on a
  reservation whose departure date is today or later uses it, with a message giving the
  number of affected reservations. Lines on past or cancelled reservations do not block
  deletion. This adds to the existing "active rooms" deletion rule from SPEC-002.

### Key Entities

- **Reservation** (existing, changed): the booking — hotel, primary guest, external
  reference, arrival and departure dates, status, adults, children, source, special
  requests, value and currency. It no longer names a single physical room; it has one or
  more reservation rooms.
- **Reservation Room** (new): one booked room unit — its reservation, its hotel, the room
  type booked, an optional physical room, and a status. Shares the reservation's dates.
- **Room Type** (existing, SPEC-002): the category booked on a line. Gains the placeholder
  "Unspecified (migrated)" for legacy data where needed.
- **Room** (existing): a physical room; optionally placed on a line; its occupancy is
  derived from in-house lines.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Staff can record a multi-room, multi-type booking as one reservation in a
  single submission, without choosing physical rooms.
- **SC-002**: 100% of existing reservations have exactly one line after the upgrade, with
  0 changes to any room's occupancy, and 0 duplicate lines after running it twice.
- **SC-003**: The staff, AI and import paths produce identical lines for identical input in
  100% of the tested cases, and staff and AI give identical rejections for the same invalid
  input (the import's differences are limited to those listed in FR-019).
- **SC-004**: 0 reservations can be saved with no line, with a room of the wrong type, with
  another hotel's room type or room, or over the party-capacity limit without an audited
  staff override.
- **SC-005**: A user or AI of one hotel sees 0 reservation rooms of another hotel.
- **SC-006**: Every line change made by staff or the AI appears in the audit log with the
  correct actor (import and backfill exceptions per FR-023).
- **SC-007**: Every existing reservation screen and report keeps working after the upgrade
  once its frontend slice ships, with no lost reservation data.

## Assumptions

- The room-type-and-quantity input shape comes from decision D2; per-room dates and
  per-room pricing are post-MVP, so price stays a reservation total.
- The 50-unit cap per reservation is a sensible default for MVP group sizes; it can be
  raised later without a model change.
- Capacity (FR-009) checks the party against the rooms as a whole, not guest-by-room
  placement, because guest-to-room placement is not modelled in MVP.
- Line changes follow the same last-write-wins behavior as the rest of the reservation.
- No availability or overbooking check is added here; SPEC-020 adds it. Until then, lines
  can be created for a type even if the hotel has no free rooms of that type, as today.
- The breaking API change is coordinated with the frontend slice in the same phase
  (decision D13), so no legacy request shape is kept.
- Guest list and children's ages (SPEC-011) and the status lifecycle (SPEC-012) are built
  on top of this spec and do not change the reservation-room concept.
