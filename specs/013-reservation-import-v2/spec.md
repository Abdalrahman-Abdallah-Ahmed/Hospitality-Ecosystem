# Feature Specification: Reservation Import v2 and Reconciliation

**Feature Branch**: `013-reservation-import-v2`

**Created**: 2026-10-12

**Status**: Draft

**Input**: Phase 13 — Import + Migration (D16), read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's two backlog
specs as one feature: SPEC-100 Reservation Import v2 and SPEC-101 Import Reconciliation.
Follows decision D16 (the in-app reservation import is the only MVP import path;
normalization moves into the application; the external Python ETL is reference only), the
constitution's "Import and Migration" rules, Principle VII (imports MUST be idempotent), and
closes open infrastructure item F11 (queued imports).

## Overview

Hotels moving onto the platform bring years of reservations from their previous PMS. Today
they can upload a spreadsheet, but the import expects one tidy row per reservation with one
room and one guest, runs while the uploader waits, matches guests by phone only, and answers
with a count and a list of skipped row numbers. Real exports are messier: a reservation
spans several rows (one per room or per guest), phone numbers come in a dozen formats, dates
and statuses use the old system's wording, and the same file is often uploaded twice.

This feature turns the import into a migration tool a hotel can trust:

- **One pipeline, in clear stages.** Every file goes through parse → normalize → validate →
  map → import → report. Each stage either passes a row on or records exactly why it
  stopped it.
- **Legacy rows become the real domain.** Rows are grouped into reservations with
  room-type lines (one per room unit), several guests with one primary, and party
  composition. Activity bookings in the file are attached to the reservation when the
  activity can be identified.
- **Guests are matched conservatively.** A guest is reused only on a confident match
  (same phone digits, same email, or same external guest id). When signals disagree, the
  import does not guess.
- **Re-importing is safe.** Uploading the same file twice never creates a second
  reservation, guest or booking. The external reservation reference is the key.
- **Imports run in the background.** The upload returns immediately with an import id;
  staff check its progress and, when it finishes, read a reconciliation report listing
  every row as imported, skipped, duplicate, invalid or unresolved, with a reason.

What is already in place and is **kept**: the `reservations.import` permission, per-hotel
scoping of everything an import writes, reuse of the same reservation-creation rules every
other entry point uses, importing legacy data "as it is" (no capacity or availability
rejection — overbooking shows in the availability grid), auto-creating rooms and room types
named in the file, accepting historical statuses (including checked-out) only through the
import, suppressing guest/staff notifications for imported records, and one audit entry per
import run.

## Clarifications

### Session 2026-10-12

- Q: When a re-imported reservation's data differs from what is stored, skip or update?
  → A: Leave it unchanged and report a duplicate (noting that the data differs); staff
  fix changed reservations by hand.
- Q: Is the dry run part of this MVP feature? → A: Yes, it is included.
- Q: How are dates that could be read two ways (e.g. "12/09/2025") resolved? → A: The
  uploader picks day-first or month-first for the whole file at upload; day-first is the
  default.
- Q: Can a reservation be imported when its primary guest has no phone number? → A: Yes —
  a phone, an email or an old-system guest id is enough; each imported guest without a
  phone is listed in the report as a warning.
- Q: Should importing a guest who is in the hotel right now mark their room occupied? → A:
  Yes — create the stay and mark the assigned room occupied, with no notifications or
  tasks; an in-house row with no room is imported with a "no room assigned" warning.
- Q: When two reservations put the same physical room on overlapping nights, what happens
  to the second? → A: It is imported with that room unassigned, and the report warns about
  the clash, naming the other reservation.
- Q: Can a hotel run more than one import at the same time? → A: No — one at a time per
  hotel; a new upload waits as "queued" until the current one finishes. Different hotels
  run in parallel.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Upload a legacy export and get a reconciliation report (Priority: P1)

A hotel admin uploads the reservation export from their previous PMS. The upload is
accepted straight away and they are given an import id. They can check the import's
progress, and once it has finished they see totals (imported, skipped, duplicate, invalid,
unresolved) and a row-by-row list explaining what happened to every row that was not
imported cleanly.

**Why this priority**: Without a background run and a trustworthy report, a hotel cannot
migrate at all — large files time out today, and a bare "skipped row 418" does not tell
anyone what to fix. This is also the API contract change the frontend depends on.

**Independent Test**: Upload a file mixing good rows, rows missing required data, and rows
with unparseable dates; confirm the upload returns an import id immediately, the status
moves from queued to completed, and the report's totals add up to the number of data rows
with a reason for each non-imported row.

**Acceptance Scenarios**:

1. **Given** an admin with import permission, **When** they upload a valid file,
   **Then** they receive an import id and a "queued" status without waiting for the rows to
   be processed.
2. **Given** a queued or running import, **When** the admin checks its status, **Then**
   they see its stage, rows processed so far out of the total, and when it started.
3. **Given** a finished import, **When** the admin opens its report, **Then** every data
   row in the file appears in exactly one outcome — imported, skipped, duplicate, invalid
   or unresolved — and every non-imported row shows its row number, the external reference
   (if any) and a human-readable reason.
4. **Given** an import that failed as a whole (e.g. the file cannot be read), **When** the
   admin checks its status, **Then** it shows "failed" with the reason and nothing from the
   file was written.
5. **Given** a hotel's list of past imports, **When** the admin opens it, **Then** they see
   each import's file name, uploader, start and finish time, status and totals.

---

### User Story 2 - Legacy rows are normalized and mapped to the new reservation model (Priority: P1)

The previous PMS writes the same thing many ways: "+20 100 123 4567" and "01001234567",
"12/09/2025" and "2025-09-12", "Checked Out" and "CO", "Dbl Deluxe" and "Deluxe Double".
It also spreads one reservation across several rows. The import normalizes these values,
groups rows that share an external reservation reference into a single reservation with
one room-type line per room unit, attaches every guest named on those rows (one marked
primary), and records adults, children and children's ages.

**Why this priority**: The reference dataset (the September 2025 export) cannot be imported
correctly without it; importing it one-row-per-reservation would create duplicate
reservations and lose guests.

**Independent Test**: Import a file in which one reservation spans three rows (two rooms of
one type, one of another, two named guests), with mixed date and phone formats; confirm one
reservation with three room lines, two guests with the right one primary, and normalized
dates, phone numbers and status.

**Acceptance Scenarios**:

1. **Given** three rows sharing one external reservation reference, **When** imported,
   **Then** exactly one reservation is created with a room-type line per room unit listed
   on those rows.
2. **Given** rows naming several guests for one reservation, **When** imported, **Then**
   all guests are linked to the reservation and exactly one is primary (the one flagged
   primary in the file, otherwise the first listed).
3. **Given** a date in any of the supported formats, **When** normalized, **Then** it is
   stored as the same calendar date. "12/09/2025" is read as 12 September under the
   default day-first order and as 9 December when month-first was chosen at upload. An
   unparseable date makes the row invalid rather than guessed.
4. **Given** a legacy status label, **When** normalized, **Then** it maps to one of the
   platform's reservation statuses through a known mapping; an unknown label makes the row
   invalid.
5. **Given** a room number or room type the hotel does not have yet, **When** imported,
   **Then** it is created (as today) and listed in the report as created by this import.
6. **Given** rows of one reservation that disagree on reservation-level data (e.g.
   different arrival dates), **When** imported, **Then** the whole reservation is reported
   invalid with the conflicting fields named, and none of its rows are written.
7. **Given** a row with an activity booking whose activity matches one of the hotel's
   activities, **When** imported, **Then** the booking is attached to the reservation;
   when the activity cannot be identified, the reservation is still imported and the
   booking is reported as unresolved.

---

### User Story 3 - Re-importing the same file is safe (Priority: P1)

Migrations are rarely one-shot: a hotel uploads a file, fixes some rows, and uploads it
again — or uploads a newer export that overlaps the old one. Reservations whose external
reference already exists at the hotel are not created again; they are reported as
duplicates. Guests and bookings are never duplicated by a re-run.

**Why this priority**: Constitution Principle VII makes idempotent imports mandatory; a
duplicate reservation corrupts availability, occupancy and every downstream AI behavior.

**Independent Test**: Import a file, then import the identical file again; confirm the
second report shows every previously imported reservation as duplicate and the number of
reservations, guests and bookings at the hotel is unchanged.

**Acceptance Scenarios**:

1. **Given** a reservation already imported with external reference R, **When** a file
   containing R is imported again, **Then** no new reservation is created and R is
   reported as duplicate.
2. **Given** a re-run where an earlier run left some rows invalid, **When** those rows were
   corrected in the new file, **Then** they are imported and the previously imported rows
   are reported as duplicates.
3. **Given** a row without any external reservation reference, **When** an equivalent row
   (same primary guest, arrival, departure and room types) was already imported, **Then**
   it is reported as duplicate rather than created again.
4. **Given** the same external reference used by another hotel, **When** this hotel
   imports it, **Then** it is treated as new — references are unique per hotel.
5. **Given** a reservation already imported with reference R, **When** a newer file
   contains R with a different departure date, **Then** the stored reservation is not
   changed and R is reported as duplicate with a note that the file's data differs.
6. **Given** a file uploaded twice at the same moment for the same hotel, **When** both
   are accepted, **Then** the second waits as "queued" until the first finishes, runs
   afterwards, and reports every reservation the first created as duplicate.

---

### User Story 4 - Conservative guest matching (Priority: P2)

When an imported guest looks like a guest the hotel already has, the import reuses the
existing guest only when it is sure: same phone digits, same email (case-insensitive), or
same external guest id from the old system. When one signal points at one guest and another
signal points at a different guest, the import does not pick one; the reservation is
reported as unresolved so staff can decide.

**Why this priority**: A wrong merge attaches one guest's history, memories and WhatsApp
conversations to another person — a privacy failure that is hard to undo. A missed match
only costs a duplicate guest profile.

**Independent Test**: Seed a guest with a phone and email; import rows that match by
formatted phone only, by email only, by external guest id only, and one row whose phone
matches guest A and email matches guest B; confirm the first three reuse the existing guest
and the last is reported unresolved.

**Acceptance Scenarios**:

1. **Given** an existing guest with phone "+201001234567", **When** a row has
   "0020 100 123 4567", **Then** the existing guest is reused (digits match after
   normalization).
2. **Given** an existing guest with email "Sara@Example.com", **When** a row has
   "sara@example.com" and no phone, **Then** the existing guest is reused.
3. **Given** a row whose phone matches guest A and whose email matches guest B, **When**
   imported, **Then** no guest is merged or created, the reservation is reported unresolved
   naming both candidates, and nothing from that reservation is written.
4. **Given** a matched guest whose stored name differs from the row, **When** imported,
   **Then** the existing guest's details are not overwritten; missing details (e.g. an
   empty email) may be filled in.
5. **Given** a guest at another hotel with the same phone, **When** this hotel imports,
   **Then** that guest is never matched — matching is per hotel.

---

### User Story 5 - Validate a file before committing it (Priority: P3)

Before writing anything, the admin can run an import as a dry run. It goes through every
stage except writing, and produces the same reconciliation report (what would be imported,
skipped, duplicate, invalid or unresolved), so they can fix the file first.

**Why this priority**: It is in MVP scope and most useful for a first migration of a
large, messy file. It is ranked last because the idempotent re-import in Story 3 already
makes "import, fix, re-import" safe, so the other stories deliver value without it.

**Independent Test**: Run a dry run on a file and confirm the report matches what a real
import of the same file then produces, and that no reservation, guest, room, room type or
booking was created by the dry run.

**Acceptance Scenarios**:

1. **Given** a file, **When** imported as a dry run, **Then** a full report is produced
   and nothing is written to the hotel's data.
2. **Given** a dry run followed by a real import of the same file with no data changed in
   between, **Then** both reports show the same outcome for every row.

---

### Edge Cases

- **Empty file or header only**: the import completes with zero rows and says so; it is not
  an error.
- **Missing required columns** (no recognizable reservation reference/guest/date columns at
  all): the whole import fails before any row is processed, naming the missing columns.
- **Unrecognized columns**: ignored and listed once in the report, not per row.
- **Departure on or before arrival**: invalid (today this is silently accepted).
- **Children count and ages disagree** (e.g. 2 children, one age): invalid, unless no ages
  are given at all, in which case the count is kept and ages left empty.
- **Guest without a phone**: imported when an email or old-system guest id is present, and
  flagged as a warning in the report; a guest with no phone, email or guest id makes the
  reservation invalid.
- **Party missing**: defaults to one adult, zero children, as today.
- **Imported reservation marked checked-in or checked-out**: stays are recorded to match
  the status, without notifications or housekeeping tasks. Past departures leave room
  status alone; guests in-house now make their assigned rooms occupied.
- **Same room on overlapping nights**: the later reservation (in file order, or the one
  being imported when the other already exists) is imported with that line unassigned and
  a clash warning naming the other reservation. If it is in-house, its line counts as "no
  room assigned" and no room is marked occupied for it.
- **In-house guest without a room number**: the reservation is imported with the line
  unassigned and a "no room assigned" warning, so staff can assign a room.
- **Cancelled or no-show reservations in the file**: imported with that status so history
  is complete; they hold no inventory.
- **A matched reservation reference that was soft-deleted**: restored, following the
  existing reservation-creation rule, and reported as imported (restored).
- **A row fails while writing** (unexpected error): only that reservation is rolled back
  and reported invalid with the error; the import continues with the next reservation.
- **The background run crashes mid-file**: the import is marked failed with how far it
  got; reservations already written stay, and re-running the same file finishes the rest
  without duplicates (Story 3).
- **Monetary values**: the reservation value and currency are kept on the reservation; the
  import never writes to the transaction ledger, whatever the finance flag says.
- **Very large file**: rejected at upload if above the size limit; otherwise processed in
  the background without a request timeout.
- **Uploader loses permission or is deleted mid-run**: the run finishes under the hotel it
  started for; the report stays visible to anyone with the import permission.

## Requirements *(mandatory)*

### Functional Requirements

**Pipeline and background run**

- **FR-001**: The system MUST process every reservation import through the ordered stages
  parse → normalize → validate → map → import → report, and record per row the stage at
  which it stopped, if any.
- **FR-002**: Uploading a file MUST create an import record and return its id immediately
  with a "queued" status; rows MUST be processed in the background.
- **FR-003**: An import MUST have a status of queued, running, completed or failed, plus
  progress (rows processed / total rows) while running, and start/finish times.
- **FR-004**: Staff with the import permission MUST be able to list their hotel's imports
  (newest first, paginated) and view any one import's status and report.
- **FR-005**: The synchronous import response MUST be replaced by the queued contract; the
  change MUST be documented as breaking.
- **FR-006**: An import MUST run entirely within the hotel it was started for; nothing it
  reads for matching or writes may belong to another hotel.

**Normalization and validation**

- **FR-007**: The system MUST normalize, before validation: column headers (case, spacing,
  punctuation and known legacy synonyms), phone numbers (to digits with country code),
  emails (trimmed, lower-cased), dates (supported formats listed in the import
  documentation), legacy status labels (through a documented mapping), room type names
  (case- and whitespace-insensitive), and numeric party fields.
- **FR-008**: The system MUST NOT guess ambiguous values: an unparseable date, an unknown
  status label, or a non-numeric party count makes the reservation invalid with the field
  named.
- **FR-008a**: When uploading (real or dry run), staff MUST choose the file's date order —
  day-first or month-first, defaulting to day-first. Dates with a numeric day and month are
  read in that order for the whole file. The chosen order is stored on the import and shown
  in its report.
- **FR-009**: The system MUST reject a reservation whose departure is not after its
  arrival, or whose children's ages do not match the children count when ages are given.
- **FR-010**: Validation MUST NOT reject legacy data for capacity, availability or a
  deactivated room type (kept from today's import).

**Mapping**

- **FR-011**: Rows sharing an external reservation reference MUST be grouped into one
  reservation; rows of one reservation that disagree on reservation-level fields MUST make
  the whole reservation invalid.
- **FR-012**: Each room unit on a reservation's rows MUST become one room-type line; a
  room number, when given, MUST assign that room to the line.
- **FR-012a**: The import MUST NOT assign a physical room for nights it is already assigned
  to another live reservation, whether that reservation is already at the hotel or earlier
  in the same file (cancelled and no-show reservations hold no room). The clashing line
  MUST be imported unassigned, and the report MUST warn about the clash, naming the room
  and the other reservation. The no-overlap rule for room assignment holds for imported
  data the same as for any other entry point.
- **FR-013**: All guests named on a reservation's rows MUST be linked to it, with exactly
  one primary (flagged in the file, otherwise the first listed).
- **FR-014**: Adults, children and children's ages MUST be recorded on the reservation as
  totals across its rooms.
- **FR-015**: Rows that name an activity booking MUST attach it to the reservation when the
  activity matches one of the hotel's activities by name; otherwise the reservation is
  imported and the booking reported as unresolved.
- **FR-016**: Rooms and room types named in the file but missing at the hotel MUST be
  created (kept from today's import) and listed in the report.
- **FR-017**: Reservations imported as checked-in or checked-out MUST get stays consistent
  with that status, without triggering notifications or housekeeping tasks. Past departures
  MUST NOT change any room's present-day status.
- **FR-017a**: A reservation imported as checked-in with a departure still in the future
  (in-house) MUST mark each assigned room occupied, the same as a front-desk check-in but
  without notifications or tasks. An in-house room line with no room number MUST be
  imported unassigned and listed in the report as a "no room assigned" warning.

**Guest matching**

- **FR-018**: A guest MUST be reused only when matched within the hotel by normalized phone
  digits, by normalized email, or by external guest id from the source system.
- **FR-019**: When different signals point to different existing guests, the system MUST
  NOT merge or create a guest; the reservation MUST be reported as unresolved, naming the
  candidate guests, and nothing from it written.
- **FR-019a**: Every guest on a reservation MUST have at least one identifier: a phone, an
  email or an old-system guest id. A guest with none makes the reservation invalid. A guest
  imported without a phone MUST be listed in the report as a warning ("no phone — cannot be
  recognized on WhatsApp"); a warning does not change the row's outcome.
- **FR-020**: Importing MUST NOT overwrite an existing guest's stored details; it MAY fill
  in fields that are empty.

**Idempotency**

- **FR-021**: A reservation whose external reference already exists at the hotel MUST NOT
  be created again; it MUST be reported as duplicate. This holds even when the file's data
  differs from what is stored (e.g. a new departure date or status): the stored reservation
  is left unchanged, and the report notes that the file's data differs so staff can fix it
  by hand.
- **FR-022**: A reservation without an external reference MUST be checked against the
  hotel's reservations by primary guest, arrival, departure and room types; an equivalent
  one MUST be reported as duplicate. Imported reservations without a reference MUST be given
  a generated one, as today.
- **FR-023**: A hotel MUST run at most one import (real or dry run) at a time. A new upload
  while one is queued or running MUST be accepted and wait as "queued", then run in upload
  order. Imports for different hotels MAY run in parallel.
- **FR-024**: Each reservation MUST be written all-or-nothing: its room lines, guests, stays
  and bookings are created together or not at all.

**Reconciliation report**

- **FR-025**: Every finished import MUST produce a report with totals for imported,
  skipped, duplicate, invalid and unresolved, which together MUST equal the number of data
  rows in the file.
- **FR-026**: Each non-imported row MUST appear in the report with its row number, external
  reference (if any), outcome, the field(s) involved and a human-readable reason.
- **FR-027**: The report MUST also list rooms and room types created, unrecognized columns,
  unresolved bookings, and warnings (such as guests imported without a phone, or in-house
  lines with no room assigned, or room clashes).
- **FR-028**: Staff MUST be able to download a finished report as a spreadsheet of the
  non-imported rows with their outcome and reason, so they can fix the file and re-import.
- **FR-029**: Reports MUST be retained with the import record and remain viewable after the
  import finishes.

**Dry run**

- **FR-030**: Staff MUST be able to start an import as a dry run that executes every stage
  except writing and produces the same report. The dry run is part of this MVP feature.

**Authorization, audit, docs**

- **FR-031**: Starting an import, a dry run, and viewing imports and reports MUST require
  the existing `reservations.import` permission plus the same-hotel check; employees
  without it get 403. Super admins MUST name the hotel the import is for.
- **FR-032**: Each import run MUST produce one audit entry identifying the actor, hotel,
  file name and totals; per-record audit entries are not written for imported rows (kept
  from today).
- **FR-033**: Imported reservations MUST NOT send guest or staff notifications and MUST NOT
  write to the transaction ledger.
- **FR-034**: The import documentation MUST describe the stages, supported columns and
  synonyms, date formats, status mapping, matching rules, outcomes and the new endpoints;
  the frontend slice MUST cover upload, progress, history and report views.

### Key Entities *(include if feature involves data)*

- **Reservation Import**: one upload for one hotel. Uploader, hotel, original file name,
  dry-run flag, date order (day-first / month-first), status (queued / running / completed / failed), progress, failure reason,
  start/finish times, outcome totals.
- **Import Row Result**: what happened to one data row of an import. Row number, external
  reference, outcome (imported / skipped / duplicate / invalid / unresolved), stage
  reached, fields involved, reason, and the reservation it produced or matched, if any.
- **Normalization mapping**: the documented translation tables the import uses — header
  synonyms, legacy status labels, date formats. Platform-wide for MVP.
- **External reference**: the source system's reservation code, unique per hotel, used as
  the idempotency key; and the source system's guest id, stored on the guest for future
  matching.
- **Existing entities reused**: reservations, reservation room lines, reservation guests,
  guests, rooms, room types, stays, activity bookings.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The September 2025 reference export imports with 100% of its rows accounted
  for in the report (totals equal data rows) and no reservation created twice.
- **SC-002**: Re-importing any already-imported file creates zero new reservations, guests
  or bookings and reports every previously imported reservation as duplicate.
- **SC-003**: An admin receives an import id within 2 seconds of upload regardless of file
  size within the limit, and a 5,000-row file completes within 10 minutes.
- **SC-004**: Every non-imported row's reason names the field and problem clearly enough
  that a hotel admin can fix it without contacting support (verified on the reference
  dataset: no reason reads only as an internal error code).
- **SC-005**: Zero wrong guest merges in automated tests covering conflicting signals; every
  conflict is reported as unresolved.
- **SC-006**: An import started for one hotel never reads or writes another hotel's data,
  proven by an automated isolation test.
- **SC-007**: A crash mid-import followed by a re-run of the same file ends with the same
  data as a single clean run.
- **SC-008**: After any import, no physical room is assigned to two live reservations on the
  same night.

## Assumptions

- The reference dataset (the September 2025 export) is supplied by the project owner during
  planning; it is not in the repository. Its column set defines the header synonyms and
  status mapping, extended by the columns today's import already accepts.
- The external Python ETL repository is consulted for its normalization rules only; no
  code or process from it runs in production (D16).
- File formats (xlsx, xls, csv) are unchanged; the upload size limit stays at its current
  value unless the reference dataset needs more, in which case planning raises it.
- Normalization mappings are platform-wide, not editable per hotel, for MVP.
- Guest matching uses the hotel's existing guest identity rules (phone digits and email)
  plus the new external guest id; names are never used for matching. Guests may now exist
  without a phone; they are not reachable on WhatsApp until staff add one.
- Reservations with no external reference get a generated one, as today; their duplicate
  check (FR-022) is best-effort by content.
- Reports are kept as long as the import record; no automatic purge is part of this
  feature.
- The import queue is the application's existing background queue; dedicated queues and
  Redis belong to Phase 14 (F9).
- Activity bookings are attached only when the activity already exists at the hotel;
  activities are never created by the import.
- The frontend slice (upload, progress, history, report) is delivered in
  `ecosystem-frontend` per D13 and described in this feature's frontend-changes document.
- Out of scope: importing from other PMS systems through live connections or APIs,
  transactions import (behind the finance flag), editing rows inside the app, undoing an
  import, and per-hotel custom column mappings.
