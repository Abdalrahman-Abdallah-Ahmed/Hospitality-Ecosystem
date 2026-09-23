# Feature Specification: Room-Type Availability

**Feature Branch**: `003-room-type-availability`

**Created**: 2026-09-23

**Status**: Draft

**Input**: Phase 3 — Availability + Room Assignment, first spec (SPEC-020 Room-Type
Availability); read from docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md

## Overview

Reservations are now booked by room type (SPEC-010), but nothing tells staff or the AI how
many rooms of a type are still free for a set of dates, and nothing stops a hotel from
selling more Deluxe rooms than it has. This spec adds **room-type availability**: for a
hotel, a room type and a date range, how many rooms can still be sold each night. It also
uses that number to stop reservations from overselling a room type.

**Terms**:

- A *night* is the date a guest sleeps in the room. A reservation from 12 to 15 March uses
  the nights of 12, 13 and 14 March. The departure date is not a night.
- A *reservation line* (reservation room, SPEC-010) is one booked room unit.
- A *holding line* is a non-cancelled line on a `pending`, `confirmed` or `checked_in`
  reservation. It uses one unit of its room type on each of its nights, whether or not a
  physical room has been assigned to it.
- *Sellable* rooms for a type and night = the type's rooms − its out-of-order rooms − its
  holding lines on that night. It is never shown below zero.

**In scope**: working out availability, showing it to staff as a per-type, per-night view,
an availability read tool for the Admin AI and the Guest Concierge, and blocking
reservation creates and edits that would oversell a room type.

**Out of scope (later specs)**: assigning, reassigning and unassigning physical rooms, the
database guarantee against two reservations sharing one physical room, and room-type
upgrades (SPEC-021). Dated out-of-order periods (SPEC-033). The `no_show` status (SPEC-012;
when it arrives, `no_show` lines stop holding inventory). Rates, pricing, stop-sell rules,
minimum stay and channel managers (post-MVP).

## Clarifications

### Session 2026-09-23

- Q: When a reservation would sell more rooms of a type than are free, what should happen? → A: Block by default; staff with `reservations.overbook` may override, audited; the AI can never override.
- Q: Should `pending` reservations take rooms out of availability like `confirmed` ones? → A: Yes; pending holds rooms exactly like confirmed and is counted as booked (no separate tentative count).
- Q: When a room is out of order today, which future nights is it removed from? → A: Every night looked up, until it is back in service (until SPEC-033 adds dated periods).
- Q: What should the Guest Concierge tell a guest about availability? → A: Only available / not available for the whole stay plus room type details; no counts or scarcity hints.
- Q: Should employees with no staff role get availability lookup by default? → A: Yes; `availability.view` is added to employee defaults (read-only, needed to book safely). `reservations.overbook` stays off by default.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Staff check how many rooms of each type are free (Priority: P1)

A reservations agent gets a call: "Do you have 3 Deluxe rooms from 12 to 15 March?" They
look up availability for those dates and see, for each room type and each night, how many
rooms are free. They can also see how many rooms the type has in total, how many are out
of order and how many are already booked.

**Why this priority**: Every booking decision depends on this answer. Right now staff have
to count rooms and reservations by hand.

**Independent Test**: In a hotel with 5 Deluxe rooms, 1 of them out of order and 2 holding
Deluxe lines on 13 March, look up 12–15 March. Deluxe shows 4 free on 12 and 14 March and
2 on 13 March, and 2 free for the whole stay.

**Acceptance Scenarios**:

1. **Given** a hotel with 5 Deluxe rooms and no reservations, **When** staff look up
   Deluxe for 12–15 March, **Then** each of the three nights shows 5 sellable and the whole
   stay shows 5 sellable.
2. **Given** 1 Deluxe room is out of order, **When** staff look up Deluxe, **Then** every
   night shows 1 out of order and 4 sellable.
3. **Given** a confirmed reservation with 2 Deluxe lines for 13–14 March and no physical
   rooms assigned, **When** staff look up 12–15 March, **Then** 13 March shows 2 booked and
   3 sellable, and the other nights show 5 sellable.
4. **Given** a reservation departing on 13 March and another arriving on 13 March, **When**
   staff look up the night of 13 March, **Then** only the arriving reservation counts.
5. **Given** cancelled and checked-out reservations for the dates, **When** staff look up
   availability, **Then** those reservations use no rooms.
6. **Given** staff look up availability without naming a room type, **When** the result
   comes back, **Then** it lists every active room type of the hotel.
7. **Given** a departure date on or before the arrival date, a range longer than 90 nights,
   or an arrival date before today, **When** staff look up availability, **Then** the
   request is rejected with a clear message.

---

### User Story 2 - Reservations cannot oversell a room type (Priority: P1)

An agent tries to book 3 Deluxe rooms for 12–15 March, but on 13 March only 2 are free.
The system rejects the reservation and says which room type and nights are short, and by
how many. If the hotel decides to overbook on purpose, an authorized staff member can
override the check. The override is recorded.

**Why this priority**: Overselling means a guest arrives and has no room. Preventing it is
the main reason for this feature.

**Independent Test**: With 2 Deluxe rooms free on 13 March, try to create a reservation
for 3 Deluxe on 12–15 March. It is rejected with a message naming Deluxe, 13 March and a
shortfall of 1. The same request with a staff override is saved and audited.

**Acceptance Scenarios**:

1. **Given** enough rooms of every requested type on every night, **When** staff create
   the reservation, **Then** it is saved.
2. **Given** a requested type is short on at least one night, **When** staff create the
   reservation, **Then** it is rejected, nothing is saved, and the message lists each short
   type with its nights and shortfall.
3. **Given** the same shortfall, **When** a staff member with the overbooking permission
   resubmits with an explicit override, **Then** the reservation is saved and the audit
   entry records the override, the shortfall and who made it.
4. **Given** a staff member without the overbooking permission, **When** they send an
   override, **Then** it is rejected as forbidden and nothing is saved.
5. **Given** the AI creates a reservation that would oversell, **When** it asks for an
   override, **Then** the request is still rejected and the AI is told which type and
   nights are short.
6. **Given** a confirmed reservation with 2 Deluxe lines, **When** staff add a third
   Deluxe line, move the dates or extend the stay, **Then** the change is only saved if
   the added nights and rooms are available. The reservation's own current lines do not
   count against it.
7. **Given** a deleted reservation, **When** staff create it again with the same
   reference, **Then** its new lines are checked like a new reservation.
8. **Given** a reservation's dates get shorter or lines are removed, **When** staff save
   the change, **Then** no availability check is needed and the freed rooms become
   sellable right away.
9. **Given** only 1 Deluxe room is left for a night, **When** two agents submit a Deluxe
   reservation for that night at the same moment, **Then** exactly one is saved and the
   other is rejected as short.

---

### User Story 3 - Staff see availability as a grid across dates (Priority: P2)

The front-office manager opens an availability grid for the next two weeks: one row per
room type, one column per night, each cell showing how many rooms can still be sold.
Nights that are sold out or overbooked stand out.

**Why this priority**: The grid is the daily planning view. It builds on the same
calculation as User Story 1, so it follows it.

**Independent Test**: Look up 14 nights for all room types. Confirm there is one entry per
type per night, that the numbers match User Story 1 for the same data, and that an
overbooked night reports how many rooms it is over.

**Acceptance Scenarios**:

1. **Given** a 14-night range and 4 active room types, **When** staff look up availability,
   **Then** they get 56 type-and-night entries, each with total, out of order, booked and
   sellable.
2. **Given** a night where holding lines are more than the rooms that can be used (from an
   override or legacy data), **When** staff view it, **Then** sellable shows 0 and an
   overbooked count shows by how many.
3. **Given** a room type with no rooms, **When** staff view the grid, **Then** it appears
   with 0 everywhere and is not treated as an error.

---

### User Story 4 - The AI answers availability questions from live data (Priority: P2)

An administrator asks the Admin AI: "How many Suites are free next weekend?" A guest asks
the Concierge on WhatsApp: "Do you have a family room from 3 to 5 May?" Both answers come
from the same availability calculation, not from documents or guesses.

**Why this priority**: The constitution requires live operational data to come from tools.
The AI features in later phases (reservation creation by AI, proactive concierge) need
this tool.

**Independent Test**: Ask the Admin AI tool and the Concierge tool about the same type and
dates. Confirm the Admin AI result matches the staff numbers and the Concierge result says
"available" or "not available" consistently with them.

**Acceptance Scenarios**:

1. **Given** an administrator with the availability permission, **When** the Admin AI
   looks up availability, **Then** it gets the same per-type, per-night numbers staff see.
2. **Given** a staff member without the availability permission, **When** the Admin AI
   tries to look up availability for them, **Then** the tool refuses and says so.
3. **Given** a guest asks the Concierge about dates and a room type, **When** the Concierge
   looks it up, **Then** it learns whether the type is available for the whole stay, and
   the room type's description and capacity, but never room counts or other guests'
   reservations.
4. **Given** a guest asks about another hotel's rooms, **When** the Concierge looks them
   up, **Then** it only answers for the guest's own hotel.
5. **Given** a guest asks for a type that is not available, **When** the Concierge
   answers, **Then** it can list other room types that are available for the same dates.

---

### Edge Cases

- A room is out of order today → it is taken out of every night looked up, including
  future nights, until it returns to service (out-of-order periods with dates come with
  SPEC-033).
- A room of the type is deleted → it is no longer counted.
- A room type is made inactive → it is left out of the default list and cannot be booked
  on new lines. Staff can still look it up by name, so they can see existing bookings.
- A checked-in guest stays past their departure date → their line keeps holding one unit
  of its type for tonight until they check out.
- A line has a physical room assigned → it still counts once, against its booked type (it
  is not counted again through the room). SPEC-021 decides how a line placed in a room of a
  different type is counted.
- Legacy lines with the placeholder "Unspecified (migrated)" type → they count only
  against that placeholder type. The placeholder is inactive and has no rooms, so it never
  reduces any real type.
- An import brings in reservations beyond availability → the import records them as they
  are (legacy data) and they show up as overbooked nights.
- A request names another hotel's room type → rejected as an invalid reference, with no
  hint that the room type exists.
- A reservation is cancelled at the same moment another one takes its rooms → rooms freed
  by the cancellation are only sellable after the cancellation is saved.

## Requirements *(mandatory)*

### Functional Requirements

**The calculation**

- **FR-001**: The system MUST work out availability for a hotel, one or more of its room
  types and a date range, by night. The arrival date counts as a night; the departure date
  does not.
- **FR-002**: For each room type and night the system MUST report: *total* (the type's
  rooms that are not deleted), *out of order* (of those, rooms whose status is currently
  out-of-order; counted on every night looked up, including future nights), *booked* (holding lines of that type on that night), *sellable*
  (total − out of order − booked, never below 0) and *overbooked* (how far total − out of
  order − booked falls below 0, otherwise 0).
- **FR-003**: A line MUST hold inventory when it is not cancelled and its reservation is
  `pending`, `confirmed` or `checked_in`. It holds one unit of its booked room type on
  every night from arrival up to, but not including, departure, whether or not a physical
  room is assigned. A `checked_in` line whose departure date has passed MUST also hold
  tonight until it is checked out.
- **FR-004**: For each room type the system MUST also report whether it can be booked for
  the whole range: the lowest sellable number across all nights in the range.
- **FR-005**: The same rules MUST give the same numbers everywhere they are used: the staff
  view, the AI tools and the overbooking check.

**Looking it up**

- **FR-006**: Staff MUST be able to look up availability for a date range and for one
  room type, several room types, or (by default) every active room type of the hotel.
- **FR-007**: A lookup MUST be rejected if the departure date is not after the arrival
  date, if the range is longer than 90 nights, or if the arrival date is before today in
  the hotel's local time.
- **FR-008**: Each room type in the result MUST include its name, maximum occupancy and
  active state, so the result can be shown without another lookup.

**Stopping overselling**

- **FR-009**: Any change that adds booked lines to a room type on a night MUST be rejected
  if, on that night, the type would have more booked lines than rooms it can use. Today
  that means creating a reservation (including re-creating a deleted one), adding lines,
  and moving or extending a reservation's dates. A line's room type cannot be changed and
  a cancelled reservation's lines are not brought back (SPEC-010). If a later spec
  (SPEC-012) allows either, the same rule applies without changes. The reservation's own
  current lines MUST NOT count against it. Changes that only free rooms (fewer lines,
  shorter dates, cancellation) MUST NOT be checked.
- **FR-010**: A rejection MUST list each short room type with the nights it is short and by
  how many rooms, and MUST leave nothing saved.
- **FR-011**: A staff member with the overbooking permission MAY bypass the check with an
  explicit override on that request. The override MUST be recorded in the reservation's
  audit entry with the actor and the shortfall. An override from someone without that
  permission MUST be refused as forbidden.
- **FR-012**: The AI MUST NOT override the check, whoever it is acting for.
- **FR-013**: The reservation import MUST record reservations without this check, like the
  capacity check in SPEC-010 (FR-019 there). Overbooked nights it creates MUST show up as
  overbooked.
- **FR-014**: When several requests compete for the last rooms of a type on a night, the
  system MUST NOT save more lines than there are sellable rooms unless an override is used.
  Requests that lose MUST get the normal shortfall rejection.

**AI tools**

- **FR-015**: The Admin AI MUST have a read tool that returns the same per-type, per-night
  availability as the staff lookup. It follows the same permission and validation rules as
  the person it acts for, but covers at most 31 nights per call so the answer stays small
  enough for the model.
- **FR-016**: The Guest Concierge MUST have a read tool that, for the guest's own hotel, a
  date range and optionally a room type, says whether each active room type can be booked
  for the whole stay, with its name, description and capacity. It MUST always list every
  active room type, with a named type first, so the Concierge can offer alternatives in one
  call. It MUST NOT reveal room
  counts, "few left" style hints, room numbers or anything about other reservations.
- **FR-017**: AI agent instructions MUST say that availability comes only from these tools
  and is never guessed or taken from knowledge documents.

**Authorization, tenancy and audit**

- **FR-018**: Looking up availability MUST require a new `availability.view` permission.
  Administrators hold it automatically. Employees without a staff role MUST get it by
  default, because it is read-only, reveals nothing sensitive to staff, and is needed to
  book reservations safely.
- **FR-019**: Overriding the overbooking check MUST require a new
  `reservations.overbook` permission, checked on top of the normal reservation create or
  update permission. Employees without a staff role MUST NOT get it by default.
- **FR-020**: The availability check itself (FR-009) MUST run for everyone who creates or
  edits reservations, whether or not they may look up availability. Its rejection message
  only shows the shortfall for the room types in their own request.
- **FR-021**: Availability MUST only ever count the hotel's own rooms and lines. No user or
  AI of another hotel may look up or learn anything about it. A super administrator MUST
  name the hotel.
- **FR-022**: Lookups MUST NOT be written to the audit log. Overrides MUST be (FR-011).
- **FR-023**: The new permissions and the lookup MUST be added to the API documentation
  and the permission reference.

### Key Entities

- **Room Type** (existing, SPEC-002): the category whose availability is worked out. Only
  active types are listed by default.
- **Room** (existing): a physical room of a type. Counts toward the type's total unless
  deleted; counts as out of order while its status is out-of-order.
- **Reservation** and **Reservation Room / line** (existing, SPEC-010): a holding line uses
  one unit of its type on each night of its reservation.
- **Availability** (worked out, not stored): for a room type and night — total, out of
  order, booked, sellable and overbooked — plus the lowest sellable number over the range.
- **Overbooking override** (recorded in the audit log): who allowed a reservation beyond
  availability, for which room types, nights and shortfall.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A staff member can answer "can I sell N rooms of type X for these dates?"
  with a single lookup, without counting rooms or reservations by hand.
- **SC-002**: In every tested scenario, the reported sellable number matches a hand count
  of rooms, out-of-order rooms and holding lines, for every type and night.
- **SC-003**: Without an override, 0 reservations can be saved that make a room type
  overbooked on any night. Requests for the same room type are handled one at a time, so
  when two arrive for the last room, the second sees the first one's booking and is
  rejected.
- **SC-004**: Every override is in the audit log with its actor and shortfall, and 0 AI
  overrides succeed.
- **SC-005**: A 90-night, all-types lookup for a hotel with up to 500 rooms returns in
  under 2 seconds.
- **SC-006**: The staff lookup, the Admin AI tool and the Concierge tool agree for the same
  hotel, types and dates in 100% of tested cases.
- **SC-007**: A user, AI or guest of one hotel can learn nothing about another hotel's
  availability, and the Concierge never reveals room counts.

## Assumptions

- SPEC-010 (reservation rooms) is in place. This spec reads its lines and adds the
  overbooking check to its create and edit rules.
- `pending` reservations hold inventory exactly like `confirmed` ones and are counted as
  booked, so tentative bookings are not sold twice. Staff free the rooms by cancelling
  stale pending reservations.
- Blocking overselling with an audited staff override follows the same pattern as the
  capacity check in SPEC-010. Hotels that never overbook simply do not give anyone the
  `reservations.overbook` permission.
- Out-of-order is a room's current status with no end date in MVP. Treating it as
  affecting every future night is the safe choice until SPEC-033 adds dated periods.
- "Today" and nights use the hotel's local date.
- The 90-night limit covers the planning grid and long stays. It can be raised later.
- Availability is worked out on request and not stored, so it is never stale.
- The Concierge does not create reservations in MVP. It only says whether a type is
  available. Showing yes/no instead of counts avoids revealing the hotel's occupancy.
- The frontend slice (availability grid, override prompt on the reservation form) is
  delivered in `ecosystem-frontend` in the same phase (decision D13).
