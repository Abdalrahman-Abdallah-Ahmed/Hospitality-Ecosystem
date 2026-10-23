# Feature Specification: Reservation Import Column Mapping

**Feature Branch**: `016-import-column-mapping`

**Created**: 2026-10-23

**Status**: Draft

**Input**: User description: "the frontend should map the uploaded excel sheet to the needed
columns: the user uploads a sheet with its own column names, and the frontend makes the user
map them to the columns the system needs." It extends Phase 13
(`specs/013-reservation-import-v2`, SPEC-100/101) and came out of the T059 rehearsal on a
synthetic PMS export (2026-10-23), where the import failed outright on headers like
`Arr. Date` and rejected amounts like `EGP 25,200`.

## Overview

Today the import works out what each column means only from a fixed list of known header
names. When a hotel's export uses a name that isn't on the list, the column is silently
ignored. If that column was the arrival or departure date, the whole import fails. The only
fix is a code change, so the hotel can't migrate on its own.

This feature puts the user in charge of that step:

- **Preview.** The admin uploads the file and sees its columns, a few sample values from
  each, and the system field each column seems to be.
- **Map.** The admin confirms or corrects each column's field, and leaves columns the
  system doesn't need unmapped.
- **Run.** The confirmed mapping drives the import (dry run or real), which then runs and
  reports exactly as it does today.
- **Remember.** The hotel's mapping is saved, so the next export from the same system maps
  itself.

Two smaller gaps found in the rehearsal are closed at the same time: a single "guest name"
column can be mapped, and the admin says how the file writes amounts, so `25,200` is read
instead of rejected.

Values inside columns (status labels, room type names, activity names) are not mapped
here. They keep going through the existing translation tables.

## Clarifications

### Session 2026-10-23

Decided with the product owner before the spec was written:

- Q: Where are the sheet's columns read for the mapping screen? → A: By the server, in a
  preview step. The browser never parses the file, and the file is uploaded once.
- Q: Is the mapping pre-filled? → A: Yes. The hotel's saved mapping comes first, then the
  known header names. The user confirms or corrects it.
- Q: Is a mapping saved? → A: The hotel's last confirmed mapping is remembered and suggested
  for the next file. There are no named templates.
- Q: Are values inside columns mapped too? → A: No, only columns.
- Q: How is a single full-name column handled? → A: A "guest full name" field, split into
  first and last name.
- Q: Can two sheet columns fill one field? → A: No. Each field takes at most one column.
- Q: How are amounts like `25,200` read? → A: The user picks the file's decimal mark when
  confirming. The default keeps today's behaviour.
- Q: Should the mapping step support exports whose header row isn't the first row (title
  lines above it)? → A: Yes. The preview shows the first 10 rows, and the admin picks the
  header row (suggested: the hotel's last choice, otherwise row 1).
- Q: Can an admin who leaves the mapping screen find the upload again? → A: Yes. The
  hotel's unexpired previews are listed, newest first, and can be reopened or discarded.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Map an unfamiliar export and import it (Priority: P1)

A hotel admin has an export from their old system with column names the system has never
seen (`Arr. Date`, `Guest`, `Mob.`). They upload it and see a list of the file's columns,
each with a few sample values and a suggested system field, or none. They set `Arr. Date` to
arrival date, `Mob.` to guest phone, and leave `Nationality` unmapped, then start the import.
The import runs in the background and reports as usual.

**Why this priority**: Without it, any export whose headers aren't already known can't be
imported without a developer. This is the reason for the feature.

**Independent Test**: Upload a file whose arrival, departure and phone columns all have
unknown names. Map them in the preview and confirm. Check that the import completes with
the same outcomes as the same file using known header names.

**Acceptance Scenarios**:

1. **Given** an uploaded file, **When** the preview is returned, **Then** it lists every
   column in file order with its header text (or a placeholder when the header is blank), up
   to 5 sample values, and a suggested field or none, plus the total number of data rows.
2. **Given** a preview, **When** the admin confirms a mapping, **Then** an import is queued
   exactly as a direct upload is today (queued status, progress, rows, report), recording
   the mapping it used.
3. **Given** a confirmed mapping that leaves some columns unmapped, **When** the import
   runs, **Then** those columns are ignored and listed as unrecognized in the report.
4. **Given** a mapping without an arrival date, departure date, or any guest identifier
   (phone, email or guest id), **When** the admin confirms, **Then** the request is refused
   at once with a message naming what's missing, and no import is created.
5. **Given** a mapping that assigns the same field to two columns, or names a column the file
   doesn't have, or a field that doesn't exist, **When** the admin confirms, **Then** the
   request is refused, naming the problem, and no import is created.
6. **Given** a preview, **When** the admin confirms it as a dry run and then confirms the
   same preview for real, **Then** both runs read the same file and the admin doesn't
   upload it again.
7. **Given** a file with a hotel name, report title and date range on rows 1–3 and the
   header on row 4, **When** the admin picks row 4 as the header row, **Then** the columns,
   samples and suggestions are rebuilt from row 4 without uploading again, rows 1–3 are
   ignored, and data rows start at row 5. Row numbers in the import report stay the file's
   own row numbers.
8. **Given** an admin who uploaded a file and closed the mapping screen, **When** they open
   the import page again within 24 hours, **Then** the upload is listed with its file name,
   uploader, upload time, expiry and the imports already started from it, and they can
   reopen it to map and confirm, or discard it.

---

### User Story 2 - The mapping is suggested, not typed (Priority: P1)

The admin shouldn't have to map columns the system already understands. Headers the
system knows (`Check-In`, `Email`, `Res No.`) arrive pre-selected; the admin only deals with
the rest.

**Why this priority**: Most columns in most exports are already known. Pre-filling keeps
the mapping step to a glance for the common case and keeps the flow as fast as today's
direct upload.

**Independent Test**: Upload a file whose headers are all in the known list, and check that
every column arrives with the right field selected and that confirming without changes
gives the same result as today's direct upload.

**Acceptance Scenarios**:

1. **Given** a column whose header is a known name, **When** the preview is returned,
   **Then** that column's suggested field is the one the known name stands for.
2. **Given** a column whose header is unknown, **When** the preview is returned, **Then**
   it has no suggested field.
3. **Given** two columns that would both be suggested the same field (e.g. `Mobile` and
   `Phone`), **When** the preview is returned, **Then** only the first gets the suggestion
   and the other is left unmapped for the admin to decide.

---

### User Story 3 - The hotel's mapping is remembered (Priority: P2)

The hotel exports from the same system every time. After mapping it once, the next upload
arrives already mapped the way the admin left it, including columns the known list doesn't
cover, and columns the admin deliberately left unmapped stay unmapped.

**Why this priority**: Makes repeat imports one click. It isn't needed for a first
migration, which is why it comes after stories 1 and 2.

**Independent Test**: Confirm a mapping that sets an unknown header to a field and leaves a
known one unmapped. Upload a file with the same headers and check the preview suggests
exactly that mapping.

**Acceptance Scenarios**:

1. **Given** a hotel that has confirmed a mapping, **When** a file is previewed whose
   column headers include ones from that mapping, **Then** each of those columns is
   suggested the field (or "unmapped") the hotel chose last time, ahead of the known
   header names.
2. **Given** a column the saved mapping doesn't mention, **When** the preview is returned,
   **Then** its suggestion falls back to the known header names.
3. **Given** two hotels, **When** each confirms a different mapping, **Then** neither sees
   the other's mapping in its suggestions.
4. **Given** a confirmed mapping, **When** the confirm request is refused (story 1,
   scenarios 4–5), **Then** the saved mapping is not changed.

---

### User Story 4 - A single "guest name" column (Priority: P2)

Many exports print the guest's whole name in one column (`Guest Name: Ahmed Saleh`, or
`Smith, John`). The admin maps that column to "guest full name", and the system splits it.

**Why this priority**: Without it, guests from those exports are created with no name,
which staff then have to fix by hand.

**Independent Test**: Import a file whose only name column is mapped to full name and check
the guests' first and last names.

**Acceptance Scenarios**:

1. **Given** a full-name value of several words, **When** the row is imported, **Then** the
   first word is the first name and the rest is the last name (`Mohamed Abdel Rahman` →
   `Mohamed` / `Abdel Rahman`).
2. **Given** a full-name value with a comma, **When** the row is imported, **Then** the part
   before the comma is the last name and the part after it the first name (`Smith, John` →
   `John` / `Smith`).
3. **Given** a full-name value of one word, **When** the row is imported, **Then** it is the
   first name and the last name is empty.
4. **Given** a mapping that sets a column to full name and another to first name or last
   name, **When** the admin confirms, **Then** it is refused: a file uses either the full
   name field or the separate name fields.

---

### User Story 5 - Amounts written with thousands separators (Priority: P3)

The admin's export writes amounts as `25,200` or `EGP 1,260`. When confirming, the admin
says the file uses "." for decimals and "," for thousands, and these amounts are read
correctly instead of making the reservation invalid.

**Why this priority**: In the rehearsal this rejected about a fifth of reservations, but
only for exports that write amounts as text. Many exports store them as numbers, which are
read correctly already.

**Independent Test**: Import the same file with the default setting and with "dot decimal"
and compare: the first rejects `25,200`; the second reads it as 25200.

**Acceptance Scenarios**:

1. **Given** the default decimal setting, **When** a file has `25,200`, **Then** it is
   rejected as ambiguous exactly as today.
2. **Given** "dot decimal", **When** a file has `25,200`, `1,260.50` or `EGP 3,600`,
   **Then** they are read as 25200, 1260.50 and 3600.
3. **Given** "comma decimal", **When** a file has `25.200` or `1.260,50`, **Then** they are
   read as 25200 and 1260.50.
4. **Given** "dot decimal", **When** a file has `99,50`, **Then** the reservation is invalid
   with the amount named, because under that setting the value doesn't make sense.
5. **Given** any import, **When** its record is viewed, **Then** it shows the decimal setting
   used.

---

### Edge Cases

- **Duplicate header names** (two columns both called `Phone`): columns are identified by
  their position, so each can be mapped on its own.
- **Blank header cells** with data under them: shown as a column with a placeholder name
  (for example "Column F") and sample values, and mappable like any other.
- **A header row beyond the end of a short file**: refused, naming the last row the file
  has.
- **A file with a header row and no data rows**: the preview is returned with zero data
  rows; confirming it creates an import that completes with nothing imported.
- **A file the server can't read** (wrong type, corrupt, over the size limit): the preview is
  refused with the same messages a direct upload gives today. Nothing is stored.
- **A discarded preview**: it disappears from the list, and confirming or reopening it is
  treated as not found.
- **An expired preview**: confirming it is refused with a message telling the admin to
  upload the file again.
- **A preview from another hotel**: it is treated as not found.
- **A sheet whose headers change between exports** (a column added or renamed): the saved
  mapping still applies to the headers that match. The new or renamed ones fall back to the
  known names, or none.
- **A column mapped to full name whose cell is empty on a row**: that guest has no name, the
  same as an empty first and last name today.
- **The direct upload** stays as it is today (known header names only). It also
  accepts the decimal setting.

## Requirements *(mandatory)*

### Functional Requirements

**Preview**

- **FR-001**: Staff with the import permission MUST be able to upload a reservation file
  for a preview without starting an import. The same file types, size limit and hotel rules
  as a direct upload apply, and a super admin MUST name the hotel.
- **FR-002**: The preview MUST return, for the file's first sheet: the first 10 rows as
  they appear in the file, the header row in use, every column in file order with its
  position, its header text from that row (or a placeholder when blank), up to 5 non-empty
  sample values from the rows below it, and a suggested field or none; plus the number of
  data rows and the list of fields that can be mapped.
- **FR-002a**: The admin MUST be able to choose any of the first 10 rows as the header row.
  Choosing one rebuilds the columns, samples, suggestions and data row count from that row
  without a new upload. Rows above the header row are ignored and not counted as data rows.
  The suggested header row is the one the hotel used last, if the file has at least that
  many rows, otherwise row 1.
- **FR-003**: Each mappable field MUST be listed with its key, a human-readable label, and
  whether it is required or one of the guest-identifier fields (at least one of which is
  required).
- **FR-004**: The uploaded file MUST be kept on the server for the preview's lifetime so it
  can be imported without uploading it again. Only the server reads the file.
- **FR-005**: A preview MUST expire 24 hours after upload. An expired preview's file MUST be
  deleted, and confirming it MUST be refused.
- **FR-005a**: Staff MUST be able to list the hotel's unexpired previews, newest first,
  each with its file name, uploader, upload time, expiry, and the imports started from it;
  reopen any of them; and discard one, which deletes its file at once. Imports already
  started from a discarded or expired preview are unaffected.

**Suggestions**

- **FR-006**: Each column's suggestion MUST come first from the hotel's saved mapping when
  that mapping names the same header (compared ignoring case, spacing and punctuation), then
  from the known header names, then none.
- **FR-007**: A suggestion MUST NOT give the same field to two columns; the first column in
  file order keeps it and the rest get none.
- **FR-008**: A column the hotel last chose to leave unmapped MUST be suggested as unmapped,
  even if its header is a known name.

**Confirming**

- **FR-009**: Staff MUST be able to confirm a preview with the header row, a mapping (sheet
  column → field or unmapped), the date order (as today), dry run or not (as today), and the decimal
  setting. This queues an import that runs, reports and is listed exactly as one from a
  direct upload.
- **FR-010**: The confirmed mapping MUST be the only source of column meaning for that
  import: no column may be read as a field it wasn't mapped to, and every unmapped column
  MUST be listed as unrecognized in the import's report.
- **FR-011**: Confirming MUST be refused, creating nothing, when the mapping: gives one field
  to more than one column; names a column the file doesn't have or a field that doesn't
  exist; lacks arrival date or departure date; lacks every guest identifier field; or maps
  both full name and first or last name. The refusal MUST name each problem.
- **FR-012**: A preview MUST be confirmable more than once until it expires, so a dry run can
  be followed by a real run of the same file. Each confirm creates its own import.
- **FR-013**: Each import MUST record the header row, mapping and decimal setting it ran
  with, and all three MUST be visible when the import is viewed.

**Saved mapping**

- **FR-014**: Each successful confirm (dry run or real) MUST save the confirmed mapping as
  the hotel's mapping, by header text, including the columns left unmapped, together with
  the header row used. It replaces the previous one.
- **FR-015**: A hotel's saved mapping MUST be visible only to that hotel (and a super admin
  acting for it).

**Full name**

- **FR-016**: "Guest full name" MUST be a mappable field. A value with a comma is split
  into last name (before the comma) and first name (after); otherwise the first word is the
  first name and the remaining words the last name. Surrounding and repeated spaces are
  ignored.

**Amounts**

- **FR-017**: The decimal setting MUST have three values: automatic (default, today's
  behaviour: `1,200` and `1.200` are ambiguous and rejected), dot decimal ("." marks
  decimals, "," separates thousands) and comma decimal (the reverse). It MUST be accepted on
  both the preview confirm and the direct upload.
- **FR-018**: Under dot decimal or comma decimal, an amount that doesn't fit the chosen
  convention MUST make the reservation invalid with the amount named, never be guessed.

**Access and audit**

- **FR-019**: Every preview, confirm and saved-mapping action MUST require the existing
  reservation import permission and the same-hotel check. Another hotel's preview MUST be
  treated as not found.
- **FR-020**: The existing direct upload, import status, row results and report MUST keep their
  current behaviour. This feature adds to them and breaks nothing.

### Key Entities *(include if feature involves data)*

- **Import preview**: one uploaded file waiting to be mapped. It belongs to a hotel and
  the user who uploaded it, and holds the stored file, the file's columns (position, header,
  samples), the data row count, the suggested mapping and when it expires.
- **Column mapping**: a set of sheet column → field (or unmapped) pairs. The confirmed
  mapping is recorded on the import it created.
- **Hotel saved mapping**: one per hotel. Header text → field (or unmapped) and the header
  row, from the hotel's last confirmed mapping.
- **Reservation import** (existing, from SPEC-100): gains the header row, mapping and
  decimal setting it ran with, and a link to the preview it came from.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An admin can import a file whose required columns all have unknown header
  names without any change to the system's configuration or code.
- **SC-002**: For a file whose headers are all known names, the admin goes from upload to a
  queued import with one confirm and no edits, and the result is identical to today's direct
  upload of the same file.
- **SC-003**: A second file from the same export system arrives fully mapped: 100% of the
  columns the admin mapped or left unmapped last time are suggested the same way.
- **SC-004**: Re-running the T059 synthetic export with dot decimal makes zero reservations
  invalid for amounts (74 rows were rejected for amounts on 2026-10-23).
- **SC-005**: The preview of a 5,000-row file is shown to the admin within 10 seconds of the
  upload finishing.
- **SC-006**: No refused confirm creates an import or changes the hotel's saved mapping.

## Assumptions

- Only the first sheet is read, as today; files with several sheets are out of scope. The
  header row must be within the first 10 rows. The direct upload keeps reading row 1 as
  the header.
- "Known header names" means the existing translation table in the import configuration. It
  stays as the source of suggestions and as the only mapping for direct uploads.
- Sample values are shown to staff who already have the import permission and are not
  stored beyond the preview's lifetime. The preview file holds guest data and is deleted
  when it expires, like an import file after it runs.
- A preview is visible to anyone in the hotel with the import permission, not just the
  uploader, matching how imports are visible today.
- The frontend builds the mapping screen from the preview response. A handoff describing
  the screen is delivered alongside the API documentation (constitution: every phase
  delivers its frontend slice).
- No new permission is added. Mapping is part of importing, and anyone allowed to import
  may map.
