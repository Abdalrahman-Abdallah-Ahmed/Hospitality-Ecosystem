# Feature Specification: Module Data Export

**Feature Branch**: `021-module-data-export`

**Created**: 2026-11-03

**Status**: Draft

**Input**: User description: "need to create an export feature for the following modules "reservations, activity, bookings, guests""

## Clarified Decisions

Settled with the requester before drafting:

| Topic | Decision |
| --- | --- |
| Modules | Reservations, Activities (the hotel's activity catalogue, not the AI activity log), Bookings, Guests |
| Formats | Excel spreadsheet, CSV, SQL (INSERT statements) |
| Which records | Exactly what the module's list shows under the current search, filters and sort — every page, not just the visible one |
| Delivery | Direct download in the same request; no background jobs or export history |
| Who may export | A new, separate export permission per module, grantable through staff roles |
| Columns (Excel/CSV) | A fixed, readable column set per module, with related names filled in instead of internal identifiers |
| Columns (SQL) | The module's own stored record fields, as stored, one INSERT per record |
| Safeguards | Every export is written to the audit log; exports above a 10,000-row cap are refused; at most 10 exports per user per minute |

## Clarifications

### Session 2026-11-03

- Q: When an export would contain more than the row cap, what should happen, and what should the cap be? → A: Refuse above 10,000 rows with the cap and matching count; never return a partial file.
- Q: How should the reservation spreadsheet show a reservation's several rooms/room types and party guests? → A: One row per reservation, with room types, rooms and party guests as comma-joined lists.
- Q: Should exports have their own per-user rate limit on top of the normal API throttle? → A: Yes, at most 10 exports per minute per user across all modules.
- Q: Should SQL exports keep the records' own IDs and hotel ID? → A: Keep them: record id, hotel id and foreign-key ids as stored.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Export filtered reservations to a spreadsheet (Priority: P1)

A front-office manager filters the reservation list (for example, arrivals next week with status "confirmed") and downloads the result as an Excel or CSV file to share with housekeeping, the owner, or an accountant.

**Why this priority**: Reservations are the core operational record and the most common thing staff need outside the system. This story alone proves the whole pattern: permission, filtered selection, readable columns, file download, audit entry and row cap.

**Independent Test**: Seed reservations for one hotel, apply a filter, request an Excel and a CSV export, and check the files contain exactly the filtered reservations in list order with the agreed columns.

**Acceptance Scenarios**:

1. **Given** a user holding the reservation export permission and 40 reservations of which 12 match the filter, **When** they export with that filter as CSV, **Then** they receive a CSV file with a header row and exactly 12 data rows in the same order the list would show.
2. **Given** the same filter, **When** they export as Excel, **Then** they receive an Excel file with the same 12 rows and columns, where dates are real dates and amounts are real numbers.
3. **Given** a list that would show 3 pages of results, **When** the user exports, **Then** the file contains the records from all pages, not just the current one.
4. **Given** a successful export, **When** an administrator reviews the audit log, **Then** they see who exported, which module, which format, the filters used, the row count and when.

---

### User Story 2 - Export guests, bookings and activities (Priority: P2)

Staff export the guest list (for example for a loyalty mailing reviewed by management), the bookings list (for example to reconcile realised activity revenue), and the activity catalogue (for example to send to a partner or print a price list), using the same filters as each module's list.

**Why this priority**: Same value as Story 1 for the other three modules; it reuses the pattern Story 1 establishes.

**Independent Test**: For each of the three modules, seed records, export with and without a filter in Excel and CSV, and check the rows and columns.

**Acceptance Scenarios**:

1. **Given** a user with the guest export permission, **When** they export guests filtered to VIP guests, **Then** the file contains only VIP guests with name, contact and loyalty columns.
2. **Given** a user with the booking export permission, **When** they export bookings filtered by status "realised", **Then** each row shows the booking reference, the guest's name, the activity name and the reservation number instead of internal identifiers.
3. **Given** a user with the activity export permission, **When** they export the catalogue, **Then** each row shows the activity's category name, price, currency, availability and capacity.

---

### User Story 3 - Export records as SQL for loading into another database (Priority: P3)

A technical administrator exports a module as SQL so the records can be loaded into a reporting database, a data warehouse, or a migration target.

**Why this priority**: Useful for integrations and migrations, but a smaller audience than the spreadsheet exports.

**Independent Test**: Export a filtered set as SQL, load the file into an empty copy of the module's table, and check every exported record arrives with identical values.

**Acceptance Scenarios**:

1. **Given** 5 filtered reservations, **When** the user exports as SQL, **Then** the file contains 5 INSERT statements for the reservations table, one per record, carrying the stored field values.
2. **Given** a guest whose name contains an apostrophe or other special characters, **When** guests are exported as SQL, **Then** the statement loads correctly and the value round-trips unchanged.
3. **Given** a record with empty optional fields, **When** it is exported as SQL, **Then** those fields are written as NULL, not as empty text.

---

### User Story 4 - Control who may export through staff roles (Priority: P2)

A hotel admin decides which staff roles may export which modules, separately from who may view them, because an export takes personal data out of the system.

**Why this priority**: Required before guest data can be exported safely; it ships with Story 1.

**Independent Test**: Give an employee a role with "view reservations" but not "export reservations" and check the export is refused; add the export permission and check it succeeds.

**Acceptance Scenarios**:

1. **Given** an employee whose role grants viewing reservations but not exporting them, **When** they request a reservation export, **Then** the request is refused as forbidden and no file or audit entry is produced.
2. **Given** an employee whose role grants the export permission, **When** they request the export, **Then** it succeeds.
3. **Given** an employee with no staff role, **When** they request any export, **Then** it is refused (export is not part of the default employee permissions).
4. **Given** a hotel admin, **When** they open the role editor, **Then** the four export permissions are listed alongside the existing permissions.

---

### Edge Cases

- **No matching records**: the export still succeeds and returns a file with only the header row (Excel/CSV) or no INSERT statements (SQL), and the audit entry records zero rows.
- **Above the row cap**: the export is refused with a clear message stating the cap and the number of matching records, asking the user to narrow the filters. No partial file is produced; silently truncating would hand out an incomplete file that looks complete.
- **Another hotel's data**: a scoped user's export contains only their own hotel's records, whatever filters they send. A filter that names another hotel's record matches nothing.
- **Super admin**: must name the hotel to export; exports cover one hotel at a time.
- **Soft-deleted records**: excluded, as they are from the list.
- **Spreadsheet formula injection**: a text value that begins with `=`, `+`, `-` or `@` (for example a guest name or special request typed by a guest) must not be run as a formula when the file is opened in a spreadsheet program.
- **Non-Latin text** (Arabic, Cyrillic, accented names): appears correctly when the CSV or Excel file is opened in common spreadsheet programs.
- **Values with commas, quotes or line breaks** (special requests, notes): stay in one cell in CSV.
- **Too many exports in a short time**: an 11th export by the same user within a minute is refused as "too many requests", produces no file, and the user can retry after the window passes.
- **Reservation with several rooms or party guests**: still one row; the room types, rooms and party guests columns list each value separated by commas. A reservation with no room assigned yet leaves the rooms column empty.
- **Missing related record** (for example a booking whose activity was later removed): the related-name column is left empty and the export does not fail.
- **Unknown format, invalid filter or sort field**: refused with a validation error naming the problem, the same way the list endpoint refuses it.

## Requirements *(mandatory)*

### Functional Requirements

**Selection**

- **FR-001**: Users MUST be able to export the Reservations, Activities, Bookings and Guests modules, each independently.
- **FR-002**: An export MUST select exactly the records the module's list would return for the same search, filters and sort, across all pages.
- **FR-003**: An export MUST contain only records of the hotel(s) the user may access, under the same tenant rules as the list. A super admin MUST name a single hotel.
- **FR-004**: An export MUST exclude soft-deleted records.

**Formats and content**

- **FR-005**: Users MUST be able to choose Excel, CSV or SQL as the format of each export.
- **FR-006**: Excel and CSV exports MUST start with a header row of readable column names and contain one row per record, using the fixed column set for that module (see Key Entities).
- **FR-007**: Excel and CSV exports MUST show related records by readable value (guest name, activity name, category name, reservation number, staff name) rather than internal identifiers.
- **FR-008**: Status, source, channel and similar coded values MUST appear as their readable label in Excel and CSV.
- **FR-009**: In Excel exports, dates MUST be stored as dates and money and counts as numbers, so they can be sorted and summed.
- **FR-010**: CSV exports MUST be readable with non-Latin characters intact when opened in common spreadsheet programs, and MUST keep values containing commas, quotes or line breaks in one field.
- **FR-011**: Excel and CSV exports MUST neutralise text values that would otherwise be run as spreadsheet formulas.
- **FR-012**: SQL exports MUST contain one INSERT statement per record for the module's own table, carrying the record's stored field values — including its own id, its hotel id and the ids of related records, as stored — with empty values written as NULL and text values escaped so every statement loads without error.
- **FR-013**: SQL exports MUST leave out internal-only fields that have no meaning outside the system (for example machine-learning vectors or internal processing state).
- **FR-014**: The downloaded file MUST be named after the module, hotel and date/time of the export, with the extension of its format (for example `reservations-<hotel>-20261103-1430.xlsx`).

**Delivery and limits**

- **FR-015**: The file MUST be returned directly in response to the export request, with no separate step to collect it.
- **FR-016**: The system MUST refuse an export whose matching record count is above a configurable row cap (default 10,000 rows), with a message stating the cap and the matching count. It MUST NOT return a truncated file. An export of exactly the cap MUST succeed.
- **FR-016a**: The system MUST limit each user to 10 exports per minute across all four modules, refusing further requests in that minute as "too many requests" without producing a file.

**Access control**

- **FR-017**: The system MUST introduce one export permission per module: reservations export, activities export, bookings export, guests export.
- **FR-018**: An export MUST be allowed only to users holding that module's export permission for the hotel being exported. Admins and super admins hold it automatically; employees hold it only through their staff role.
- **FR-019**: The export permissions MUST NOT be part of the default permissions of an employee without a staff role.
- **FR-020**: The export permissions MUST appear in the permission list used by the role editor.
- **FR-021**: Holding the view permission alone MUST NOT allow exporting.

**Audit**

- **FR-022**: Every successful export MUST be written to the audit log with the user, hotel, module, format, filters/search/sort applied, row count and time.
- **FR-023**: A refused export (no permission, validation failure or row cap) MUST NOT produce a file. A refusal for exceeding the row cap MUST also be recorded in the audit log, so attempts to pull large amounts of data are visible.

**Documentation**

- **FR-024**: Each module's API documentation MUST describe its export, and the staff roles permission reference MUST list the four new permissions.

### Key Entities

- **Export request**: one user's request to download one module in one format, made up of the module, the format, and the same search/filter/sort inputs the module's list accepts. Not stored, apart from its audit entry.
- **Export audit entry**: the record that an export happened: who, which hotel, which module, format, the filters applied, row count, and when.
- **Export permission**: one per module, granted to staff roles like any other permission.
- **Column set**: the fixed list of readable columns per module for Excel and CSV. Summary below; the authoritative list with order and types is in [data-model.md](data-model.md):
  - **Reservations** (one row per reservation): reservation number, guest name, guest email, guest phone, arrival date, departure date, nights, adults, children, room types, rooms, party guests, status, source, reservation value, currency, special requests, created at. Room types, rooms and party guests are comma-joined lists.
  - **Activities**: name, category, description, price, currency, active, available from, available until, audience, duration (days), daily capacity, created at.
  - **Bookings**: reference, item name, activity, guest name, reservation number, status, scheduled for, guests (pax), charge model, expected value, currency, origin, channel, created by, confirmed at, realised at, cancelled at, cancellation reason, notes.
  - **Guests**: first name, last name, email, phone, preferred language, nationality, loyalty status, VIP, channel, external ID, created at.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can go from a filtered list to a downloaded file in one action, and the download for a cap-sized export (10,000 rows) completes in under 30 seconds.
- **SC-002**: For every module and every format, the exported records match the list's results for the same filters with 100% agreement in count, order and identity.
- **SC-003**: Every exported SQL file loads into an empty copy of its table with zero errors, and every loaded value equals the original.
- **SC-004**: Exported Excel and CSV files open in common spreadsheet programs with correct characters and no formula execution, verified with names in at least three scripts (Latin with accents, Arabic, Cyrillic).
- **SC-005**: 0 records from another hotel appear in any export by a hotel-scoped user.
- **SC-006**: 100% of successful exports have an audit entry; 0 exports succeed for a user without the module's export permission.
- **SC-007**: For every module, the number of data rows in an Excel or CSV export equals the number of matching records (no row multiplication from rooms or party guests).
- **SC-008**: An exported booking set and guest set loaded together from SQL keep every booking linked to its guest (100% of foreign-key ids resolve).

## Assumptions

- The export reuses each module's existing list search, filter and sort behaviour; no new filter options are added by this feature.
- Column labels are in English; per-user language of column headers is out of scope. This matches the rest of the staff API and the existing import report, which are English-only; Arabic content in the data is fully supported (SC-004), which is how the export meets the constitution's English/Arabic requirement.
- Dates and times are written in the same form the list returns them; time zone handling follows the existing list behaviour.
- The row cap of 10,000 is a default chosen to keep direct downloads within normal request time limits on the production host; it is configurable per deployment, not per hotel.
- Guest contact data (email, phone) is included in full for users holding the export permission; masking is out of scope because the permission itself is the control.
- SQL exports cover only the module's own table; related tables (for example a reservation's rooms or party members) are not included, although their ids are kept on the exported records so separately exported tables can be re-linked.
- Background exports, scheduled/emailed exports, export history and PDF output are out of scope.
- The AI activity log is not part of this feature.
- Every new export endpoint is a tenant entry point and is covered by the project's existing cross-tenant and entry-point inventory checks.
