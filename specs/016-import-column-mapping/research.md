# Research: Reservation Import Column Mapping

Decisions for `016-import-column-mapping`. Each one names what was chosen, why, and what
was rejected. The code they build on is the SPEC-100 import in
`app/Support/Import/Reservations` and `app/Jobs/PrepareReservationImportJob.php`.

## R1. One read per upload: a preview record holds the head of the sheet

**Decision**: the preview upload reads the file's first sheet once, with formatted values
(`Maatwebsite\Excel\Concerns\WithFormatData`, so date cells show as dates, not serial
numbers). It stores on the preview record:

- `head_rows`: the first 30 rows, as display strings, trimmed to the last non-empty column;
- `last_row`: the number of the last non-blank row in the sheet.

Everything the mapping screen needs is computed from those two values, for any header row
from 1 to 10:

- the columns and their header text, from row `header_row`;
- up to 5 non-empty samples per column, from the rows after it (still inside the 30);
- the data row count, `last_row - header_row`.

Changing the header row (`GET …/{preview}?header_row=4`) rebuilds the screen from the
record and never re-reads the file. The file is read again only by the import itself,
exactly as today.

**Rationale**: FR-002a asks for an instant header-row switch. Reading a 5 MB spreadsheet
takes about 1–2 s per pass, so one read at upload (well inside SC-005's 10 s) and none
afterwards keeps the screen responsive. The head holds 30 rows: 10 candidate header rows
plus room for samples below the lowest one.

**Alternatives considered**:
- Re-read the file on every header-row change: simpler storage, but it repeats a seconds-long
  read for each click.
- Parse in the browser: rejected by the product owner (Clarifications). The browser and
  the server could read the same file differently.
- Store every row: the import reads the file anyway, and this would duplicate guest data in
  the database.

## R2. The import runs from a copy of the preview's file

**Decision**: confirming copies the preview file to the path a direct upload uses
(`reservation-imports/{hotel_id}/{import_id}.{ext}`) and creates a normal
`reservation_imports` record. `PrepareReservationImportJob` still deletes the import's own
copy when prepare ends. The preview keeps its file until it is discarded or expires.

**Rationale**: FR-012 lets one preview be confirmed several times, such as a dry run and
then a real run. The SPEC-100 job already owns and deletes its file, so giving each import
a private copy changes nothing in the job's lifecycle or failure handling. A file is at most
5 MB, so copying it costs little.

**Alternatives considered**: having the import read the preview's file in place. Every run
would then depend on the preview outliving it, and either the job or the prune job would
have to skip the delete. That is fragile, and expiry could pull the file out from under a
queued import.

## R3. A "layout" tells the reader how to read the sheet

**Decision**: `ImportFileReader::read()` gains an optional layout argument with
`header_row` (default 1) and `columns` (sheet position → canonical field, or null):

- **No `columns`** (a direct upload): headers go through the synonym table, as today.
- **With `columns`** (a confirmed preview): only the mapped positions are read, each as its
  field. Every unmapped non-blank header goes into `unrecognized_columns`.

In both cases, rows above `header_row` are skipped, and `row_number` stays the file's own
row number (`index + header_row + 1`). The required-column check still runs on the result.
Confirmation already guarantees it passes, so here it only guards against a corrupt record.

The import stores the layout as `header_row` and `column_map` (jsonb, null for direct
uploads). The prepare job passes it through. No other stage changes, because the reader
already outputs canonical columns.

**Rationale**: the normalizer, mapper and resolver work on canonical columns, so the
mapping only has to change how columns are named, in the one class that names them
(Preserve and Evolve).

**Alternatives considered**: rewriting the file with canonical headers at confirm time.
This means writing an xlsx, which loses cell types (dates), and still cannot express rows
above the header.

## R4. Columns are identified by position; the saved mapping by header text

**Decision**:
- **Confirm:** the confirm request maps sheet positions (`"columns": {"0": "…", "4": null}`),
  so two columns that share a header text can be mapped independently. Positions it leaves
  out are unmapped.
- **Saved mapping:** stored by normalized header text: lower-case, with punctuation and
  spacing folded to `_` by the same `ImportFileReader::normalizeHeader()` the synonym table
  uses. This makes `Arr. Date` and `ARR DATE` the same header. Blank headers aren't saved,
  because they have no text to match.
- **Suggestions:** per column, the saved mapping wins (including an explicit null), then
  the synonym table, then a direct canonical-name match, then none. A field already
  suggested to an earlier column isn't suggested again (FR-007).

**Rationale**: a position is unambiguous within one file, which is what confirming needs.
The next export's columns may be in another order, so reuse has to go by header text
(spec US3).

## R5. Full name is a field, split in the normalizer

**Decision**: add `guest_full_name` to `canonical_columns`, and add the synonyms
`guest_name`, `name`, `full_name` and `guest_full_name` to the header table, so a direct
upload with `Guest Name` gets it too. `RowNormalizer::guests()` splits it:

- a value with a comma gives last name, then first name (`Smith, John`);
- otherwise the first word is the first name and the rest is the last name;
- spaces are collapsed.

If a row also has `guest_first_name` or `guest_last_name`, those win and the full name is
ignored. Confirmation forbids mapping both (FR-011), so this only matters for direct
uploads that have both columns.

A row with only a full name and no contact still fails the existing "no phone, email or
guest id" check. Today's rule for when a guest exists is extended to count
`guest_full_name` among the guest columns.

**Rationale**: splitting at normalization means the mapper, guest matching and party rules
need no change. Arabic names such as `Mohamed Abdel Rahman` keep the multi-word family name
together in the last name.

## R6. Decimal mark: three modes, parsed strictly in the two explicit ones

**Decision**: a new enum, `App\Enums\ImportDecimalMark`, with three values:

- **`auto`** (the default): today's `RowNormalizer::decimalAmount()`, unchanged.
- **`dot`**: valid amounts are `^-?\d{1,3}(,\d{3})+(\.\d+)?$` or `^-?\d+(\.\d+)?$`, read with
  "," removed.
- **`comma`**: the same pattern with "." and "," swapped, read with "." removed and "," made
  the decimal point.

Currency text is stripped first, as today. A value that doesn't fit the chosen mode is an
error naming the amount (FR-018), so `99,50` is invalid under `dot`. Numeric cells skip
the parsing, as today.

The setting is stored as `reservation_imports.decimal_mark` and accepted by both the
confirm and the direct upload (FR-017). It is passed to the normalizer the same way
`date_order` is.

**Rationale**: it mirrors the `date_order` decision. The admin states the convention once
per file and nothing is guessed. The patterns reject mixed spellings instead of reading
them some other way.

## R7. Preview lifecycle: hard-deleted at expiry or discard, pruned hourly

**Decision**: a `reservation_import_previews` record expires 24 hours after upload
(`expires_at`). An hourly `PruneReservationImportPreviewsJob` deletes expired previews and
their files, running under `TenantContext::withoutScope()` because it runs without a
request.

- **Discard** (`DELETE`) deletes the record and the file at once.
- **Lists** show only unexpired previews.
- **An expired preview not yet pruned** answers `410 Gone` ("upload the file again").
- **After pruning**, it answers `404`.
- **Imports** link to their preview with `nullOnDelete`, so they outlive it (FR-005a).

Previews aren't soft-deletable. Like `ReservationImport`, a preview is never edited by
clients, and it holds guest data that must actually go when it expires.

**Rationale**: the spec requires the file deleted at expiry and the samples not kept after
it (Assumptions). Soft-deleting would keep the samples in `head_rows`.

## R8. Saved mapping: one row per hotel

**Decision**: a `reservation_import_mappings` table (BelongsToHotel, unique `hotel_id`)
holds `header_row` and `columns` (jsonb, normalized header → field or null). It is upserted
in the same transaction that creates the import on a successful confirm (FR-014), and never
on a refused one (SC-006).

**Rationale**: one mapping per hotel is the agreed scope (no named templates). A separate
table keeps `hotels` free of import concerns and gets tenant scoping from the existing
trait.

## R9. Confirm validation lives in a form request against the preview

**Decision**: `ConfirmReservationImportPreviewRequest` validates:

- **Settings:** `header_row` (1–10, and not past the file's last row), `date_order`,
  `dry_run`, `decimal_mark`;
- **Shape of `columns`:** an object of position → field or null.

An `after()` hook then checks the mapping against the bound preview, adding one error per
problem under `columns` (FR-011):

- a position not in the file;
- an unknown field;
- a field mapped twice;
- a required field missing (arrival, departure, any guest identifier);
- full name combined with first or last name.

**Rationale**: every problem is reported in one `422`, in the project's request-validation
layer. Nothing is written before the request passes (SC-006).

## R10. Authorization and routes

**Decision**:
- **Policy:** a new `ReservationImportPreviewPolicy` (`viewAny`, `view`, `create`,
  `delete`) checks the existing `Permission::RESERVATIONS_IMPORT` through `allows()`, with
  the same super-admin `before()` as `ReservationImportPolicy`. No new permission
  (spec Assumptions).
- **Routes:** five under `/api/reservation/import-previews`, registered before the
  `/reservation` resource like the existing `/reservation/imports` routes.
- **Isolation:** another hotel's preview is a `404` through the `hotel` global scope on
  route-model binding (FR-019).

## R11. Field list comes from config, with labels

**Decision**: `config/reservation_import.php` gains `field_labels` (canonical field →
English label). The preview's `fields` list is built from `canonical_columns` plus those
labels and two flags: `required` (from `required_columns`) and `guest_identifier` (from
`guest_identifier_columns`). The frontend translates the labels into Arabic by key.

**Rationale**: the config is already the single source of the canonical columns. A second
list in an enum would drift from it. The keys are stable, so the frontend can localize
them (constitution: English and Arabic).

## R12. Audit

**Decision**: no new audit events. The import a confirm creates already logs its single
`reservations_imported` event with the uploader as the actor (SPEC-100). Uploading and
discarding a preview change no hotel data.

**Rationale**: constitution VI asks for audit on actions that change records. A preview is
a temporary staging object.
