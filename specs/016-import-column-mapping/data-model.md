# Data Model: Reservation Import Column Mapping

Two new tables and four new columns on `reservation_imports`. All of them are scoped by
hotel through `BelongsToHotel`. Decisions are in [research.md](research.md).

## `reservation_import_previews` (new)

An uploaded file waiting to be mapped (R1, R7). Hard-deleted on discard or expiry.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | `HasUuids` |
| `hotel_id` | uuid FK → hotels, cascade | `BelongsToHotel` |
| `uploaded_by` | uuid FK → users, null on delete | |
| `file_name` | string(255) | Client file name, as on imports |
| `file_path` | string | Private disk: `reservation-import-previews/{hotel_id}/{id}.{ext}` |
| `head_rows` | jsonb | First 30 rows as display strings (list of lists) |
| `last_row` | unsigned int | Number of the last non-blank row (1-based); 0 for an empty sheet |
| `expires_at` | timestampTz | `created_at + 24h`; indexed for the prune job |
| `created_at`, `updated_at` | timestampsTz | |

Index: `(hotel_id, expires_at)` for the list.

**Derived (not stored), for a given `header_row` (1–10, ≤ `last_row`):**

- `columns`: one per position in `head_rows[header_row - 1]`, plus any wider positions
  present in the rows below. Each column is `{ position, letter, header, samples[≤5], suggested_field }`.
  The header is blank → `"Column {letter}"`.
- `data_rows`: `max(0, last_row - header_row)`.
- `suggested_header_row`: the hotel mapping's `header_row` if it is ≤ `min(10, last_row)`,
  otherwise 1.

**Rules**
- **Expiry:** a preview with `expires_at <= now()` isn't listed. Reading or confirming it
  answers `410`. The hourly prune deletes the record and its file.
- **Discard:** deletes the record and its file at once.

## `reservation_import_mappings` (new)

The hotel's last confirmed mapping (R8). One per hotel.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK → hotels, cascade, **unique** | `BelongsToHotel` |
| `header_row` | unsigned small int | 1–10 |
| `columns` | jsonb | `{ normalized_header: field \| null }`; blank headers aren't stored |
| `updated_by` | uuid FK → users, null on delete | Who confirmed it last |
| `created_at`, `updated_at` | timestampsTz | |

**Rules**
- **Upsert:** the mapping is written in the same transaction as the import a successful
  confirm creates (FR-014).
- **Refused confirms:** they never touch it (SC-006).
- **Two columns with the same normalized header:** the first in sheet order is stored.

## `reservation_imports` (existing, SPEC-100): new columns

| Column | Type | Notes |
| --- | --- | --- |
| `reservation_import_preview_id` | uuid FK → previews, **null on delete** | Set when started from a preview |
| `header_row` | unsigned small int, default 1 | Direct uploads stay 1 |
| `column_map` | jsonb, nullable | `{ position: field \| null }`; null means the synonym table is used (direct upload) |
| `decimal_mark` | string(8), default `auto` | Cast to `ImportDecimalMark` |

Existing rows migrate with the defaults: `header_row = 1`, `column_map = null`,
`decimal_mark = auto`. Their behaviour is unchanged.

## Enum: `App\Enums\ImportDecimalMark` (new)

| Case | Value | Reads |
| --- | --- | --- |
| `AUTO` | `auto` | Today's rule: `1,200` / `1.200` alone is ambiguous → invalid |
| `DOT` | `dot` | `25,200` → 25200; `1,260.50` → 1260.50; `99,50` → invalid |
| `COMMA` | `comma` | `25.200` → 25200; `1.260,50` → 1260.50; `1,260.50` → invalid |

## Mappable fields

The fields are `canonical_columns` from `config/reservation_import.php`, plus the new
`guest_full_name` (R5). Each one is exposed as:

```json
{ "key": "arrival_date", "label": "Arrival date", "required": true, "guest_identifier": false }
```

- `required`: arrival and departure dates.
- `guest_identifier`: phone, email and guest id; at least one of these is required.

### Mapping validity (FR-011)

A confirm's `columns` is valid only when:

1. every key is a column position in the preview at the chosen `header_row`;
2. every value is a mappable field key or null;
3. no field is used twice;
4. `arrival_date` and `departure_date` are both mapped;
5. at least one of `guest_phone`, `guest_email` or `guest_external_id` is mapped;
6. `guest_full_name` isn't mapped together with `guest_first_name` or `guest_last_name`.

### Full name split (FR-016)

| Value | First | Last |
| --- | --- | --- |
| `Mohamed Abdel Rahman` | Mohamed | Abdel Rahman |
| `Smith, John` | John | Smith |
| `  Sara  ` | Sara | (empty) |
| (empty) | (empty) | (empty) |

When a row also has `guest_first_name` or `guest_last_name` (direct uploads only), those
win and the full name is ignored.
