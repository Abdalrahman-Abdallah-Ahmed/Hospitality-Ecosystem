# Research: Reservation Import v2 and Reconciliation

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md)

These findings come from reading the code on branch `015-reservation-lifecycle` on
2026-10-23, after SPEC-011 and SPEC-012 were built. Each decision lists what was chosen,
why, and what was rejected. R1–R9 replace the notes the blocked plan kept on 2026-10-12.

## Starting point

`POST /api/reservation/import` runs `App\Imports\ReservationsImport` (maatwebsite/excel
`ToCollection` + `WithHeadingRow`) synchronously and returns `200 { imported, skipped[] }`.
For each row it:

- requires `guest_phone`, `arrival_date` and `departure_date`, and skips the row if any is
  missing;
- skips the row when its `reservation_id` is already in use;
- creates a missing room or room type;
- matches the guest through `ReservationCreator::findOrCreateGuest()`;
- accepts a JSON `guests` column and `children_ages` (both added by SPEC-011);
- writes one reservation with one line through `ReservationCreator::create(...,
  fromImport: true)`, inside a transaction.

`fromImport` skips the capacity check, the availability guard and the lifecycle check on
create. `syncStays(recordStatus: true)` then records stays for `checked_in`,
`checked_out` and `no_show` (SPEC-012 R9), and `syncRoomOccupancy()` marks in-house rooms
occupied. The run is wrapped in `EventLogger::withoutRecording()` and writes one
`reservations_imported` event.

The parts to keep are the write path, the `fromImport` flag, auto-creating rooms and
types, stay recording, audit suppression and the `reservations.import` permission. The
rest is replaced.

## R1. The pipeline is "plan, then write"

**Decision**: An import runs in two phases.

1. **Prepare**: one job, which only reads the database. It runs parse → normalize →
   validate → map, then **resolves** every mapped reservation against the hotel's
   existing data:
   - a duplicate reference;
   - a duplicate by content;
   - guest matches and conflicts;
   - room clashes;
   - rooms and room types that would need creating.

   Each reservation that survives is stored as a *draft*, and each row gets its outcome or
   a pending state.
2. **Write**: runs the drafts in file order, in chunks. Each draft is its own transaction
   (FR-024). A draft that fails while writing is rolled back and recorded as invalid with
   the error.

A **dry run is the prepare phase alone** (FR-030). The report is built from the same row
results, so a dry run followed by a real run with no data changed in between gives the
same outcome for every row (US5-2).

**Rationale**:
- Interactions *inside* the file (shared guests, in-file room clashes, in-file content
  duplicates) are settled at the map stage over the whole file. Interactions with
  existing data are settled at the resolve stage against a fixed snapshot. Nothing in the
  write phase depends on earlier drafts having been written, so the dry run needs no
  simulation layer.
- The write phase stays short per job (R3).

**Alternatives**:
- Run every stage per row while writing (today's shape). Rejected: a dry run would then
  need an in-memory overlay that mimics every write so later rows see earlier ones.
- Run the dry run as the real write inside a transaction that is rolled back. Rejected:
  it cannot span the chunked jobs of R3, and one long transaction over 5,000 reservations
  holds the type locks of `AvailabilityService::lockTypes()` for minutes.

**Concurrency between phases**: staff can book a room between prepare and write. The
writer re-runs `RoomAssignmentRules::reason()` for every assigned line just before it
writes. A new clash downgrades that line to unassigned with a clash warning, the same
outcome FR-012a gives, so the result stays deterministic. The check runs inside the
draft's transaction, after `ReservationCreator::create()` has taken its room-type locks
(`AvailabilityService::lockTypes()`). This is the only guard: no database exclusion
constraint on room nights exists yet.

## R2. Storage: imports, row results and drafts

**Decision**: three new tenant-owned tables (details in [data-model.md](data-model.md)):

- `reservation_imports`: one per upload. Holds status, stage, progress, the date order,
  the dry-run flag, totals, and report-level lists (created rooms and types, unrecognized
  columns, failure reason).
- `reservation_import_rows`: one per data row, holding its outcome, stage, fields, reason,
  warnings and the reservation it produced or matched.
- `reservation_import_drafts`: one per mapped reservation awaiting the write phase. It
  holds the normalized payload as `jsonb` and its position in file order.

Drafts are deleted when the import finishes. The uploaded file is deleted when the
prepare phase ends (or the import fails). Row results are kept with the import (FR-029).

**Rationale**:
- The report and its download (FR-026, FR-028) need only row number, reference, outcome,
  fields and reason. Guest phone numbers and emails are not needed after the run, so
  none is kept (constitution: privacy). The one exception is deliberate: an `unresolved`
  reason names the candidate guests by first and last name (FR-019), because staff need
  that to fix the file. Those guests already exist at the same hotel, and the reason never
  includes their phone or email.
- Drafts let the write phase continue across jobs and survive a worker restart.
- `knowledge_index_rebuilds` already tracks a background run with status and counts this
  way.

**Alternatives**:
- Keep the raw row payload on `reservation_import_rows`. Rejected: it keeps guest PII
  forever for no reporting need.
- Re-parse the file in every write chunk. Rejected: the file would have to stay on disk,
  and every chunk would repeat the normalization.

## R3. Background run, per-hotel serialization, timeouts

**Decision**: Two jobs on the existing `database` queue:

- `PrepareReservationImportJob`: runs prepare, then dispatches the first write chunk, or
  completes the import for a dry run.
- `WriteReservationImportJob`: writes up to 100 drafts, persists progress, and dispatches
  itself for the next chunk until no drafts remain. It then completes the import (totals,
  audit event, cleanup).

Both jobs:
- set `timeout` 300 and `failOnTimeout`. That is under the database queue's
  `retry_after` of 360 (`config/queue.php`), so a job is never handed to a second worker
  while still running;
- set `tries = 0` and `maxExceptions = 3`. A release for ordering doesn't use up a retry,
  as in `IndexKnowledgeDocumentJob`;
- run inside `TenantContext::runForHotel($import->hotel_id)`;
- use `WithoutOverlapping('reservation-import:'.$hotelId)` so a hotel runs one import at
  a time (FR-023).

**Order**: before starting, the prepare job checks for an older import of the same hotel
that is still `queued` or `running`. If one exists, the job releases itself (15 s), so
uploads run in upload order. Imports for different hotels run in parallel.

**Failure**: `failed()` marks the import `failed`:
- with the stage and progress reached;
- with a staff-readable reason (the raw exception goes to the log, not to the API);
- deleting its drafts and file.

Reservations already written stay. Re-uploading the same file finishes the rest as
duplicates plus new imports (SC-007).

**Sizing**: prepare for 5,000 rows is one parse, plus batched `whereIn` lookups for
references, phone digits, emails, external ids and rooms. That is well under 300 s. A
write chunk of 100 reservations at about 40 queries each stays far below 300 s. The SC-003
target (5,000 rows in 10 minutes) holds with headroom.

**Alternatives**:
- One long job with a raised `retry_after` on a dedicated queue connection. Rejected:
  queue topology belongs to Phase 14 (F9), and the dev `queue:listen` would need changing.
- `Bus::batch()`. Rejected: it needs the `job_batches` table, and the drafts must still be
  written in order, which a batch does not guarantee.

## R4. Parse and header normalization

**Decision**:
- Keep maatwebsite/excel ^3.1. Read the first sheet into a collection with heading-row
  formatting turned off, so the raw headers reach our normalizer.
- Normalize each header: trim, lower-case, turn spaces, dashes and dots into `_`, collapse
  repeats. Then map it through a platform-wide synonym table in
  `config/reservation_import.php`, for example `res_no` / `booking_ref` / `confirmation` →
  `reservation_reference`, and `mobile` / `phone` → `guest_phone`.
- Unknown headers are listed once in `unrecognized_columns`.
- Required columns: `arrival_date`, `departure_date`, and at least one guest identifier
  column (`guest_phone`, `guest_email`, `guest_external_id`). If any is missing, the import
  fails before any row is processed, naming them.
- `reservation_reference` is optional (FR-022).
- File types and the 5 MB limit are unchanged.

**Rationale**: the synonym table is data (Assumption: platform-wide for MVP, documented),
so adding a PMS's wording is a config change with a test, not a code change.

**Reference dataset**: the September 2025 export is not in the repo (spec Assumptions).
The synonym and status tables start from the columns the import accepts today plus common
PMS wording. A task obtains the dataset and extends the tables, and SC-001 is verified
against it. This is a delivery dependency, not an open design question.

## R5. Value normalization: phone, email, dates, statuses, party

**Phone. Decision**: add `giggsey/libphonenumber-for-php-lite`. A new
`App\Support\Import\PhoneNormalizer` parses each raw value with the hotel's `country_code`
as the default region:

- If it parses as a valid number, it is stored as E.164 digits without `+` (the
  `PhoneNumber` storage form).
- If it doesn't parse, or the hotel has no country code and the number is national, it
  keeps `PhoneNumber::digits()` and the row gets a "phone may lack its country code"
  warning.

`PhoneNumber::digits()` and every other caller are unchanged.

*Rationale*: the blocked plan found that `"0020 100 123 4567"` does not match
`"+201001234567"` today (US4-1). Trunk prefixes differ by country (Italy keeps its leading
0), so "replace a leading 0 with the calling code" is wrong in general. The lite build
parses and formats only, with no geocoding or carrier data.

*Alternatives*: a hand-kept calling-code map plus a leading-0 rule (rejected: wrong for
several countries); changing `PhoneNumber::digits()` globally (rejected: it would change
WhatsApp matching and the expression indexes).

**Email**: trim and lower-case; an invalid address makes the guest invalid with the field
named.

**Dates. Decision**: `App\Support\Import\DateParser` accepts these formats, using the
import's `date_order` for numeric day/month formats:

- Excel serial numbers (PhpSpreadsheet `Date::excelToDateTimeObject`);
- `Y-m-d`;
- `d/m/Y` or `m/d/Y`, with `/`, `-` or `.` as the separator and 2- or 4-digit years
  (2-digit means 20yy);
- `d M Y` / `d-M-Y` / `M d, Y` with English month names or abbreviations.

A time part is ignored. Anything else is invalid (FR-008). An ISO date or a named month is
never ambiguous, so the order doesn't apply to it.

**Statuses. Decision**: a label map in `config/reservation_import.php`, matched
case- and space-insensitively:

| Status | Labels |
| --- | --- |
| `confirmed` | confirmed, conf, guaranteed, definite, booked, reserved |
| `pending` | pending, tentative, provisional, option |
| `cancelled` | cancelled, canceled, cxl, cancel |
| `checked_in` | checked in, in house, inhouse, arrived, ci |
| `checked_out` | checked out, departed, co |
| `no_show` | no show, noshow, no-show, ns |

An empty status keeps today's default, `pending`. An unknown label makes the row invalid.

**Status against dates (FR-017b)**, using the hotel's local date:

| Status | Rule |
| --- | --- |
| `checked_out` | departure ≤ today |
| `no_show` | arrival ≤ today |
| `checked_in` | arrival ≤ today; a future arrival is invalid |

`checked_in` with a departure already passed is imported as **`checked_out`**, with a
`status_corrected` warning (clarification 2026-10-23, FR-017b). Old systems often leave
departed guests "in house", and importing them as checked-in would mark rooms occupied by
guests who have left. A departure of today stays checked-in.

**Party**: `adults` and `children` must be whole numbers ≥ 0 (adults ≥ 1). A missing party
defaults to adults = named guests (at least 1) and children = 0 (FR-013a). Ages come in as
a comma list, 0–17 (FR-009), and are passed to `ReservationCreator`, which already applies
SPEC-011's import exception.

## R6. Mapping rows to reservations

**Decision**:

- **Grouping**: rows are grouped by normalized `reservation_reference` (trimmed,
  case-insensitive). Rows without one form a group of one.
- **Reservation-level fields**: arrival, departure, status, source, value, currency, party
  and special requests must agree across a group. An empty cell is not a disagreement. A
  conflict makes the whole group invalid, naming the fields (FR-011).
- **Room units (FR-012, clarification 2026-10-23)**:
  - A row with a `room_number` is one unit, and rows repeating the same number in a group
    are the same unit.
  - Unnumbered rows add one unit of their `room_type` the first time that type appears
    among the group's unnumbered rows; later unnumbered rows of the same type add guests
    only. This stops one-row-per-guest exports from inflating room counts.
  - A `room_count` for a type sets the number of unnumbered units of that type instead.
    Rows of the group that disagree on it make the group invalid.
  - A row with neither a number nor a type is a guest-only row and adds no unit.
  - A group with no units gets one unit of the hotel's default type, as today.
  - `ReservationRoomSync::MAX_UNITS` still applies; more units makes the group invalid.
- **Guests (FR-013)**:
  - Each row may name one guest through `guest_first_name`, `guest_last_name`,
    `guest_phone`, `guest_email`, `guest_external_id` and `is_primary`.
  - The existing JSON `guests` column stays supported, so files from today's format keep
    working.
  - The same person named on several rows of a group is linked once.
  - The primary is the one flagged, otherwise the first listed. More than one flagged
    primary makes the group invalid.
- **Bookings (FR-015)**: `activity_name`, `activity_date`, `activity_time` and
  `activity_pax` per row. The activity is matched by case-insensitive trimmed name among
  the hotel's activities, including inactive ones. If the name is unknown or matches two
  activities, the booking is reported as unresolved and the reservation is still imported.

**In-file guest identity**: the map stage keeps one identity index for the whole file,
keyed by phone digits, email and external id, filled in file order. A guest mention whose
identifiers point to two different in-file people makes its reservation **unresolved**.
That is the in-file form of FR-019, so the same person spread over two reservations
becomes one guest.

**In-file room clashes (FR-012a)**: in file order, a line whose room is already held on
an overlapping night by an earlier live draft is set unassigned, with a clash warning
naming that reservation. A held night is one whose status holds inventory and that isn't
a checked-out or no-show stay, matching `RoomAssignmentRules`.

**In-file content duplicates (FR-022)**: a reference-less group with the same primary
identity, dates and room types as an earlier group in the file is a duplicate of it.

## R7. Resolve against the hotel's data (read-only)

**Decision**: a `ReservationImportResolver` runs batched lookups, all filtered on the
import's `hotel_id`:

- **Reference duplicates (FR-021)**: `reservations` live rows whose `reservation_id` is in
  the file's references. A match is reported as duplicate, with a "file data differs"
  note when any of these differ: arrival, departure, status, number of live lines, or
  primary guest identity. A soft-deleted match is not a duplicate. The writer restores it
  through the existing path (`ReservationCreator::create()` restores trashed codes), and
  the row is reported "imported (restored)".
- **Guest matching (FR-018–FR-020)**: for each in-file person, look up the hotel's guests
  separately by phone digits (the existing `PhoneNumber::DIGITS_SQL` expression index), by
  normalized email (`identity_hash` `email:` prefix) and by external id (see below).
  - If the results name more than one distinct guest, the reservation is **unresolved**,
    listing the candidate ids and names.
  - One distinct guest means it is reused, and empty fields are filled.
  - None means a guest is created.

  This replaces `GuestIdentityService::findForReservation()` for the import only. That
  method returns the phone match and throws only when the email points elsewhere, and it
  ignores external ids.
- **External guest id**: stored on the existing `guests.external_id` with
  `guests.channel = null` under the existing unique `(hotel_id, channel, external_id)`
  index. Postgres treats NULLs as distinct, so uniqueness for import ids is enforced in the
  resolver, not by the index. A new `guests.external_source` column (`import`) keeps import
  ids apart from WhatsApp/OTA ids, and a partial unique index on
  `(hotel_id, external_id) WHERE external_source = 'import'` makes it exact.
  - *Alternative*: add `import` to `ReservationChannels`. Rejected: that enum lists
    booking channels and feeds the frontend's channel picker.
- **Content duplicates**: for reference-less groups whose primary guest matched an existing
  guest, check for a live reservation with the same guest, dates and room-type multiset.
- **Room clashes with existing data**: one query over live, inventory-holding lines whose
  room is in the file's room set and whose dates overlap the file's date range. Each
  draft line is then checked in memory, using the same predicate as
  `RoomAssignmentRules::reason()`.
- **Rooms and types to create**: names missing at the hotel are listed, and a dry run
  reports them as "would be created".

## R8. Writing

**Decision**: `ReservationImportWriter::write(Draft)` runs in one `DB::transaction` per
draft:

1. Create missing room types and rooms (`RoomType::resolveFor()`, the existing room
   lookup).
2. Create or reuse guests and fill empty fields, never overwriting (FR-020). Set
   `external_id` / `external_source`.
3. Lock the draft's room types (`AvailabilityService::lockTypes()`; the lock is taken
   again inside `create()`, which is harmless within one transaction), then re-check room
   assignments (R1).
4. Call `ReservationCreator::create($attributes, $rooms, fromImport: true)` with a
   `guests` list of `guest_id` + `is_primary`. Party and ages validation therefore run in
   `ReservationGuestSync` and `validateChildrenAges()`, the same rules as every other
   entry point (SPEC-011 FR-014).
5. Create resolved bookings through `BookingService::create()` with:
   - `origin = import` (`BookingOrigin::IMPORT` already exists);
   - a new `fromImport` option that skips `requireDate()`, availability, the
     recommendation credit and metering (legacy data is recorded as it is; it isn't
     demand);
   - a status that follows the reservation (FR-015, clarification 2026-10-23):
     `cancelled` when the reservation is cancelled or a no-show; `realised` (with
     `realised_at` at the activity's scheduled time) when the activity date has passed in
     hotel local time and the reservation is checked in or checked out; otherwise
     `confirmed`. The status is set directly on create, not through the booking
     transitions, as the reservation's own status is.

The whole loop runs inside `EventLogger::withoutRecording()`, and `CreationNotificationService`
is not triggered (FR-033), as today.

**Side effects to verify in tests**:
- an imported `checked_out` must not dirty rooms, create cleaning tasks or queue memory
  extraction;
- an imported `no_show` must create no task and no extraction (FR-017c).

`syncStays(recordStatus: true)` uses `StayService::checkOut()` / `markNoShow()`, and
`StayService::checkOut()` has no task or extraction calls. Those live in
`StayLifecycleService`, which the import doesn't use. Tests pin this.

**Ledger**: nothing writes `transactions` (FR-033). `reservation_value` and `currency` stay
on the reservation.

## R9. API, authorization and audit

**Decision**:

- **Upload**: `POST /api/reservation/import` now returns `202` with the import resource
  (FR-005, breaking). It accepts `file`, `date_order` (`day_first` default /
  `month_first`), `dry_run` (bool) and `hotel_id` (required for super admins, through
  `resolveHotel()`; FR-031). This fixes today's "super admin cannot target a hotel"
  exception.
- **New reads**:
  - `GET /api/reservation/imports` (`GenericIndexRequest`, newest first);
  - `GET /api/reservation/imports/{import}` (status and report summary);
  - `GET /api/reservation/imports/{import}/rows` (paginated, filter by outcome);
  - `GET /api/reservation/imports/{import}/report` (xlsx download of non-imported rows
    and warnings; FR-028).

  The routes sit next to the existing import route. Contract:
  [contracts/reservation-import-api.md](contracts/reservation-import-api.md).
- **Policy**: a new `ReservationImportPolicy` with `viewAny` / `view` that checks
  `allows($user, Permission::RESERVATIONS_IMPORT, $import)`, so the same-hotel check is
  included. Upload keeps `ReservationPolicy::import`. No new permission case (FR-031;
  CLAUDE.md step 4 doesn't apply because the case exists).
- **Audit (FR-032)**: one `reservations_imported` event when the import finishes, recorded
  on the `ReservationImport` with totals, file name and dry-run flag. The job has no
  authenticated user, so a small `EventLogger::actingAs(User, callable)` sets the uploader
  as the actor through the existing `$onBehalfOf` slot, with actor kind `user`. A dry run
  writes the event too (it's a staff action), flagged `dry_run: true`.
- **Download**: binary, so it can't go through `apiResponse()`. It uses
  `Excel::download()`, as the knowledge documents stream files today
  (`HandlesKnowledgeDocuments`). Errors still use `apiResponse()`.

## R10. Tests

**Decision**: new Pest feature files.

- `ReservationImportTest.php`: rewritten. It covers the queued contract, statuses and
  progress, and the report totals against data rows. Jobs run synchronously
  (`Queue::fake()` for the contract assertions; the sync connection for end-to-end runs).
- `ReservationImportMappingTest.php`: grouping, units, guests, party defaults, statuses
  against dates, bookings.
- `ReservationImportNormalizationTest.php`: unit-level datasets for headers, phones (with
  hotel country codes), dates in both orders, statuses.
- `ReservationImportIdempotencyTest.php`:
  - re-import;
  - corrected re-run;
  - reference-less duplicates;
  - the same reference at another hotel;
  - differing data;
  - two queued uploads run in order;
  - a crash mid-write followed by a re-run equals one clean run (SC-007).
- `ReservationImportGuestMatchingTest.php`: US4 1–5, in-file conflicts.
- `ReservationImportDryRunTest.php`: nothing written; the report equals the later real run.
- Room clash, in-house and no-show rows, plus SC-008 (no room on two live lines a night):
  covered in the mapping and idempotency files.
- `TenantIsolationTest.php`: another hotel's admin gets `403`/`404` on every import read;
  an import never matches another hotel's guest, reference or room.
- `PermissionAuthorizationTest.php`: the five import endpoints added to the dataset (there
  is no import row today).
- Fixtures: small CSVs built in the tests. The reference dataset is not committed (PII),
  so SC-001 is checked manually with the quickstart.
