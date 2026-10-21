# Feature Specification: Reservation Guests and Party Composition

**Feature Branch**: `014-reservation-guests-party`

**Created**: 2026-10-12

**Status**: Draft

**Input**: User description: "for SPEC-011 (reservation guests and children's ages)". This is
SPEC-011 Reservation Guests & Party from Phase 2 of
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md (items 2 and 3, decision D8). It
was deferred by `specs/002-reservation-rooms` and is now a prerequisite for Phase 13
(`specs/013-reservation-import-v2`). Follows the constitution's "Reservations and Stays"
rule: reservations MUST support multiple guests, an explicit primary guest, adults,
children and children's ages.

## Overview

Today a reservation has exactly one guest, plus a count of adults and children. Real
bookings name several people: a couple, a family across two rooms, a group with one
organizer. Staff can't record who else is coming, and nobody knows how old the children
are, even though that matters for beds, activities, meal plans and safety.

This feature adds:

- **A guest list on every reservation.** One or more guests, exactly one of them the
  **primary guest** (the person the booking is for, and the main contact). The others are
  **additional guests**. Each is a full guest profile at the hotel, found or created the
  same way guests are today.
- **Children's ages.** When a reservation has children, staff record each child's age. The
  number of ages always equals the number of children.
- **One shape everywhere.** Staff screens, the Admin AI and the reservation import all
  create and edit reservations with the same guest list and party rules, through the same
  reservation operation.

Every existing reservation keeps its guest as the primary guest, so nothing changes for
existing data until someone edits it.

**In scope**: the guest list with one primary, adding/removing/replacing guests and changing
the primary, children's ages with validation, migrating existing reservations, the
reservation responses and AI reservation tools, and what the Concierge recognizes.

**Out of scope (later specs)**: the `no_show` status and lifecycle (SPEC-012); the
voucher (SPEC-013), which will print the guest list and ages; per-room party breakdown and
per-room pricing (post-MVP); the reservation import itself (Phase 13).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Record everyone on a reservation (Priority: P1)

A receptionist creates a reservation for a family: the mother as primary guest, the father
as an additional guest. They add both by phone or email, the same way a single guest is
added today. Opening the reservation later shows both, with the primary clearly marked.

**Why this priority**: The constitution requires reservations to support several guests
with an explicit primary. It is the core of this feature and the base that the voucher,
import and Concierge build on.

**Independent Test**: Create a reservation with a primary and two additional guests; read
it back and confirm three guests with exactly one primary; confirm an existing guest
matched by phone is reused rather than duplicated.

**Acceptance Scenarios**:

1. **Given** staff with reservation-create permission, **When** they create a reservation
   naming a primary guest and two additional guests, **Then** the reservation lists all
   three guests and exactly one is primary.
2. **Given** a guest who already exists at the hotel with the same phone or email, **When**
   they are named on a new reservation, **Then** the existing guest profile is reused.
3. **Given** a create request that names only one guest, **When** it is saved, **Then**
   that guest is primary (today's behavior, unchanged).
4. **Given** a create request naming no primary, or two primaries, **When** it is
   submitted, **Then** it is rejected with a clear message.
5. **Given** a create request naming the same person twice, **When** it is submitted,
   **Then** the duplicate is rejected.

---

### User Story 2 - Record children's ages (Priority: P1)

When a reservation has children, staff enter each child's age. The system keeps the ages
consistent with the children count, so a reservation for "2 adults, 2 children" carries
exactly two ages.

**Why this priority**: Decision D8 and the constitution require children's ages. They
drive activity eligibility, bedding and safety, and the voucher and import both need them.

**Independent Test**: Create a reservation with 2 children and ages [4, 9]; confirm it is
saved. Try 2 children with one age, and an age of 18; confirm both are rejected.

**Acceptance Scenarios**:

1. **Given** a reservation with 2 children, **When** staff save ages 4 and 9, **Then** the
   reservation shows children 2 with ages 4 and 9.
2. **Given** a reservation with 2 children, **When** staff send only one age, **Then** it
   is rejected, saying the number of ages must equal the number of children.
3. **Given** a child's age outside 0–17, **When** submitted, **Then** it is rejected.
4. **Given** a reservation with 0 children, **When** ages are sent, **Then** it is rejected.
5. **Given** a reservation's children count changes from 2 to 3, **When** saved without a
   third age, **Then** it is rejected; with three ages it is accepted.

---

### User Story 3 - Change the guest list after booking (Priority: P2)

Plans change. A receptionist adds a friend who is joining, removes someone who cancelled,
or makes the father the primary guest because he is now the contact. Every change is
recorded in the reservation's history.

**Why this priority**: Without edits, the list drifts away from who actually arrives.
Ranked after create because creating it right covers most bookings.

**Independent Test**: On an existing reservation, add a guest, remove a guest, and switch
the primary; confirm the list and primary after each step and an audit entry for each.

**Acceptance Scenarios**:

1. **Given** a reservation, **When** staff with reservation-update permission add a guest,
   **Then** the guest appears as additional.
2. **Given** a reservation with an additional guest, **When** staff remove them, **Then**
   they leave the list; their guest profile and history at the hotel are kept.
3. **Given** a reservation, **When** staff make an additional guest primary, **Then** they
   become primary and the previous primary becomes additional, in one change.
4. **Given** a reservation, **When** staff try to remove the primary without naming a new
   one, **Then** it is rejected: a reservation always has exactly one primary.
5. **Given** any guest-list change, **When** it is saved, **Then** the reservation's audit
   history records who changed what, including an AI actor when the Admin AI did it.
6. **Given** a cancelled or checked-out reservation, **When** staff change its guest list,
   **Then** the change is allowed and audited (correcting records after the fact).

---

### User Story 4 - The Admin AI and the Concierge see the guest list (Priority: P2)

Staff ask the Admin AI "who is on reservation RES-1042?" or "book 2 rooms for the Haddad
family, Rami as the contact, 2 kids aged 5 and 8", and it reads and writes the same guest
list and ages as the staff screens, under the same permissions. On WhatsApp, the Concierge
treats the primary guest as the reservation's owner, as it does today.

**Why this priority**: The AI tools must follow the domain (constitution Principle V); an
AI that ignores the guest list would create reservations staff then have to fix.

**Independent Test**: Through the Admin AI tools, create a reservation with a primary, an
additional guest and children's ages; read it back through the reservation read tool;
confirm the same result as through the staff API, and that an employee without
reservation-create permission is refused.

**Acceptance Scenarios**:

1. **Given** the Admin AI, **When** it creates a reservation with several guests and
   children's ages, **Then** the same validation as the staff API applies.
2. **Given** the Admin AI, **When** it reads a reservation, **Then** it sees the guest list
   with the primary marked, and children's ages.
3. **Given** the primary guest messages on WhatsApp, **When** recognized, **Then** the
   Concierge finds their reservation exactly as it does today.
4. **Given** an additional guest messages on WhatsApp, **When** recognized, **Then** they
   are treated as their own guest profile and are not given the reservation's details,
   services, or authority that belong to the primary guest.

---

### User Story 5 - Existing reservations carry over unchanged (Priority: P1)

On release, every existing reservation shows its current guest as the primary guest and
nothing else changes. Reservations that already have children but no ages stay valid; ages
are asked for only when someone changes the children count.

**Why this priority**: Constitution Principle II (preserve and evolve). A migration that
loses or invalidates live bookings is not acceptable.

**Independent Test**: Before and after migrating a set of reservations, confirm each has
exactly one guest, the same as before, marked primary; confirm a legacy reservation with
children and no ages can still be edited (e.g. dates) without supplying ages.

**Acceptance Scenarios**:

1. **Given** existing reservations, **When** the release runs, **Then** each has exactly
   one guest — its current one — as primary.
2. **Given** a legacy reservation with 2 children and no ages, **When** staff change its
   dates only, **Then** the edit succeeds without ages.
3. **Given** the same reservation, **When** staff change its children count, **Then** ages
   for every child are required.

---

### Edge Cases

- **Guest at another hotel**: a guest can only be added to a reservation at their own
  hotel; another hotel's guest is rejected as not found, never matched across hotels.
- **Guest named by profile and by contact details at once**: an existing guest can be
  named directly; a new one by phone/email. If the contact details match a different
  existing guest than the one named, the request is rejected rather than guessed.
- **Same guest on two overlapping reservations**: allowed (an organizer booking two
  groups); nothing is blocked.
- **More guests than the party**: the guest list cannot be longer than adults + children;
  not every party member has to be named.
- **Guest profile deleted**: a soft-deleted guest leaves the reservation's list views but
  the primary pointer is kept; deleting the primary guest of a live reservation follows
  existing guest-deletion rules, unchanged by this feature.
- **Removing a guest who is the stay's guest**: stays stay linked to the primary guest;
  changing the primary updates the guest on the reservation's stays that are not yet
  departed.
- **Children with an age of 0**: valid (infants).
- **Ages sent in any order**: stored in the order given; no sorting rules.
- **Concurrent edits to one reservation's guest list**: the later save wins as a whole
  list; the reservation never ends up with zero or two primaries.

## Requirements *(mandatory)*

### Functional Requirements

**Guest list**

- **FR-001**: A reservation MUST have one or more guests, exactly one of them primary, at
  all times — after create, every edit, and the migration.
- **FR-002**: Staff MUST be able to name guests on create and update either as an existing
  guest of the hotel or by contact details (phone and/or email, plus names), which are
  matched to an existing guest of the same hotel or create a new one. Matching is by phone
  digits first; when no phone matches, by email only if exactly one guest at the hotel has
  that email. If the phone and the email point to different guests, or the email matches
  several guests, the request is rejected rather than guessed.
- **FR-003**: A request MUST be rejected when it names no primary, more than one primary,
  the same guest twice, a guest of another hotel, or contact details that match a different
  guest from the one named.
- **FR-004**: When a request names a single guest and no primary flag, that guest MUST be
  primary (today's single-guest requests keep working).
- **FR-005**: Staff MUST be able to add guests, remove additional guests and change the
  primary on an existing reservation. Changing the primary MUST demote the previous primary
  to additional in the same change. The primary MUST NOT be removed without naming another.
- **FR-006**: The number of guests on a reservation MUST NOT exceed adults + children.
- **FR-007**: Removing a guest from a reservation MUST NOT delete or alter their guest
  profile.
- **FR-008**: The reservation's primary guest MUST remain the guest the rest of the system
  treats as the reservation's guest (stays, WhatsApp recognition, notifications, bookings,
  recommendations), so all current behaviour that reads "the reservation's guest" keeps
  reading the primary.
- **FR-009**: Changing the primary MUST update the guest on that reservation's stays that
  have not departed, so check-in, the Concierge and service requests follow the new
  primary.

**Children's ages**

- **FR-010**: A reservation MUST carry children's ages as a list of whole numbers from 0 to
  17, one per child.
- **FR-011**: On create, and on any update that sets the children count or the ages, the
  number of ages MUST equal the children count; ages MUST be empty when there are no
  children. **Import exception**: the reservation import records legacy data as it is, so
  an imported reservation that supplies no ages at all is saved with its children count and
  an empty ages list (the same state as a legacy reservation under FR-012). An import row
  that does supply ages must supply exactly one valid age per child.
- **FR-012**: An update that changes neither the children count nor the ages MUST NOT
  require ages (legacy reservations stay editable).
- **FR-013**: Adults, children and ages remain totals for the whole reservation across all
  its rooms (D8); the existing party-capacity rules keep using adults and children.

**Consistency across entry points**

- **FR-014**: The staff API, the Admin AI reservation tools and the reservation import MUST
  create and change guest lists and ages through the same reservation operation and
  validation rules.
- **FR-015**: Reservation responses (detail and list) MUST include the guest list with the
  primary marked, and children's ages. The list view MAY show the primary guest only, with
  a guest count.
- **FR-016**: The Admin AI read tools MUST show the guest list and ages; its create and
  update tools MUST accept them, and MUST require the same permissions as the staff API.
- **FR-017**: The Concierge MUST keep recognizing a reservation through its primary guest.
  An additional guest who messages is recognized only as their own guest profile and MUST
  NOT be given the reservation's details, services, or primary-guest authority.

**Authorization and audit**

- **FR-018**: Managing a reservation's guest list and ages MUST be covered by the existing
  `reservations.create` and `reservations.update` permissions, plus the same-hotel check.
  Naming new guests by contact details on a reservation MUST NOT require the separate
  guest-create permission (as today for the single guest). No new permission is added.
- **FR-019**: Every guest-list change (add, remove, primary change) and every change to
  children's ages MUST be recorded in the reservation's audit history with the actor,
  including the AI actor for AI changes.

**Migration**

- **FR-020**: On release, every existing reservation MUST get its current guest as its only,
  primary guest; existing reservations get an empty ages list. No other reservation data
  changes.

**Docs**

- **FR-021**: The change to the reservation create/update payload and responses MUST be
  documented in the reservations API documentation and summarized in a latest-changes
  note. The change is additive: the existing single-guest fields keep identifying the
  primary guest, and single-guest requests keep working (FR-004). The frontend slice (guest
  list with primary, children's ages inputs) MUST be described in the feature's
  frontend-changes document.

### Key Entities *(include if feature involves data)*

- **Reservation Guest**: a guest's place on a reservation. Links one reservation and one
  guest of the same hotel, with a primary flag. A guest appears at most once per
  reservation; exactly one primary per reservation.
- **Reservation** (changed): gains the guest list and children's ages. Keeps adults and
  children as totals. Its primary guest is the guest every existing feature already uses.
- **Guest** (unchanged): the hotel's guest profile, shared across that guest's
  reservations.
- **Stay** (behaviour change only): its guest follows the reservation's primary until it
  departs.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After release, 100% of existing reservations have exactly one primary guest
  equal to their guest before release, and no reservation changes in any other field.
- **SC-002**: No reservation can be saved, through any entry point, with zero or several
  primary guests, and no reservation can be saved with ages supplied whose count differs
  from its children count. Only the import may save children without any ages (FR-011
  exception). Proved by automated tests against each entry point.
- **SC-003**: A receptionist can record a four-person family (two adults, two children with
  ages) in a single create action, without separate guest-creation steps.
- **SC-004**: Every guest-list and age change is traceable to its actor in the audit
  history (100% of changes in tests).
- **SC-005**: Another hotel's staff cannot see or attach this hotel's guests to a
  reservation (automated isolation test).
- **SC-006**: All existing reservation, stay, Concierge and recommendation tests pass
  unchanged except where they assert the old single-guest response shape.

## Assumptions

- The reservation keeps its existing pointer to its guest, and that pointer always equals
  the primary guest of the list; this answers the plan's open question ("keep
  `reservations.guest_id` as the primary pointer, or derive it") in favour of keeping it,
  so every feature that reads the reservation's guest keeps working (constitution
  Principle II). The plan confirms the mechanism.
- Children are 0–17 years old; 18 and over count as adults.
- Ages are optional for legacy reservations until their children count is changed.
- Guests listed are a subset of the party; naming every adult and child is not required.
- Per-room guest allocation (which guest sleeps in which room) is out of scope; stays
  across all rooms keep the primary guest, as SPEC-023 set.
- Additional guests are hotel guest profiles like any other: they get memories, messages
  and contact history only through their own activity, never by copying the primary's.
- A supplied phone number remains the preferred identity match. When a request supplies an
  email without a phone match, it reuses a same-hotel guest only when that email identifies
  exactly one live guest; an email shared by several guests is ambiguous and is rejected.
  When supplied phone and email resolve to different guests, the request is rejected.
- Notifications that go "to the guest" keep going to the primary only.
- The voucher (SPEC-013) and the import (Phase 13) consume this feature; neither is built
  here.
- The frontend slice is delivered in `ecosystem-frontend` per D13.
