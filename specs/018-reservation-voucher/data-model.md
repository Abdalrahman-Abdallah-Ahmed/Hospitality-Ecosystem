# Data Model: Reservation Voucher with Uploadable Templates

Two new tables, both hotel-scoped through `BelongsToHotel`, with UUID keys. No change to
existing tables. Migrations follow the `YYYY_MM_DD_NNNNNN_description.php` sequence
(`2026_10_27_000001…`).

## voucher_templates (`App\Models\VoucherTemplate`)

`HasUuids`, `SoftDeletes`, `BelongsToHotel`, `Filterable`.

| Column | Type | Rules |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK → hotels, cascade on delete | stamped by `BelongsToHotel` |
| `name` | string(120) | required; unique per hotel among non-deleted rows (partial unique index on `(hotel_id, lower(name)) WHERE deleted_at IS NULL`) |
| `language` | string(2) | `VoucherLanguage` enum: `en`, `ar` |
| `is_default` | boolean, default false | at most one per `(hotel_id, language)`: partial unique index `WHERE is_default AND deleted_at IS NULL` |
| `disk` | string | the disk the files live on (`config('vouchers.disk')` at upload) |
| `original_path` | string | `voucher-templates/{hotel_id}/{id}/original.{ext}` |
| `render_path` | string | `…/render.jpg`, normalized copy (research R3) |
| `mime_type` | string | of the original: `image/png`, `image/jpeg`, `image/webp` |
| `size_bytes` | integer | of the original |
| `width_px`, `height_px` | integer | of the original |
| `layout` | jsonb, default `[]` | list of field boxes (below) |
| `created_by` | uuid FK → users, nullable, null on delete | |
| timestamps, `deleted_at` | | |

**Fillable**: `name`, `language`, `is_default`, `layout`. The file columns are written only
by `VoucherTemplateService`.

**Casts**: `language` → `VoucherLanguage`, `is_default` → bool, `layout` → array.

**Filterable**: `language`, `is_default`; search on `name`; sort on `name`, `created_at`.

### Validation (from FR-010, FR-011)

- Image: `mimetypes:image/png,image/jpeg,image/webp`, ≤ 5120 KB, `getimagesize()` must
  succeed and its type must match the mimetype. Short side ≥ 1000, long side ≤ 6000, and
  `max/min` ≤ 3.
- Replacing the image (`POST …/image`) runs the same validation, overwrites both files,
  updates the file columns and **keeps `layout`** (FR-014).

### Layout field box (an element of `layout`)

```json
{
  "id": "b1",
  "field": "guest.primary_name",
  "x": 0.12, "y": 0.31, "w": 0.40, "h": 0.04,
  "font_size": 14,
  "color": "#1A1A1A",
  "bold": false,
  "align": "start",
  "overflow": "shrink",
  "text": null
}
```

| Key | Rule |
| --- | --- |
| `id` | required string ≤ 40, unique within the layout (the editor's handle, echoed in errors) |
| `field` | required, one of `VoucherField` cases (catalogue below) |
| `x`, `y` | required numeric, 0 ≤ v < 1 |
| `w`, `h` | required numeric, > 0, with `x + w ≤ 1` and `y + h ≤ 1` |
| `font_size` | required numeric, 6–72 (pt) |
| `color` | required, `#RRGGBB` |
| `bold` | boolean, default false |
| `align` | `start` \| `center` \| `end`, default `start` |
| `overflow` | `shrink` \| `truncate`, default `shrink`; list fields always shrink and then add "+N more" |
| `text` | required when `field = free_text` (≤ 500 chars), otherwise must be null or absent |

The layout is at most **60 boxes**. A field may appear more than once (for example, the
reference in the header and in the footer). The whole layout is validated in one request;
errors are keyed `layout.{index}.{key}` and include the box `id` (FR-019).

### Field catalogue (`App\Enums\VoucherField`)

| Key | Kind | Source |
| --- | --- | --- |
| `hotel.name` / `hotel.address` / `hotel.phone` / `hotel.email` | text | `hotels` |
| `reservation.reference` | text | `reservations.reservation_id` (falls back to the UUID when empty) |
| `reservation.status` | text | localized `ReservationStatus` label |
| `guest.primary_name` | text | primary guest |
| `guests.others` | list | non-primary guests' names |
| `guests.all` | list | primary first, then the others |
| `stay.arrival_date` / `stay.departure_date` | text | reservation dates, localized format |
| `stay.nights` | text | departure − arrival in days |
| `rooms.lines` | list | one line per `reservation_room`: the room-type name only, never a room number (spec clarification 2026-10-27) |
| `party.adults` / `party.children` | text | reservation |
| `party.children_ages` | text | ages joined with the language's list separator |
| `reservation.special_requests` | text | reservation |
| `reservation.total` | text | `reservation_value` + `currency`, empty unless `show_total` |
| `voucher.staff_note` | text | the request |
| `voucher.issue_date` | text | today in the hotel's timezone |
| `free_text` | text | the box's own `text` |

`GET /voucher-templates/fields` returns this catalogue with the kind and localized labels
for the editor (FR-018).

## voucher_email_requests (`App\Models\VoucherEmailRequest`)

`HasUuids`, `BelongsToHotel`. No soft deletes, a deliberate exception to the model
convention (plan, Complexity Tracking). This is an append-only send history and the
idempotency record, so a soft-deleted row would let a reused key send again.

| Column | Type | Rules |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK → hotels | |
| `reservation_id` | uuid FK → reservations, cascade on delete | |
| `request_key` | string(100) | client-supplied; unique with `(hotel_id, reservation_id)` |
| `recipient` | string | email validated with `email:rfc` |
| `voucher_template_id` | uuid FK → voucher_templates, nullable, null on delete | null = built-in layout |
| `language` | string(2) | `VoucherLanguage` |
| `show_total` | boolean | |
| `overrides` | jsonb, nullable | |
| `staff_note` | text, nullable | |
| `status` | string | `VoucherEmailStatus`: `queued`, `sending`, `sent`, `skipped`, `failed` |
| `status_reason` | string, nullable | for example `reservation_cancelled`, or the exception class |
| `unplaced` | jsonb, nullable | data keys present but with no box on the template, set when sent (spec US4 #5) |
| `claimed_at`, `sent_at` | timestampTz, nullable | |
| `requested_by` | uuid FK → users, nullable | |
| timestamps | | |

**Daily cap (FR-027a)**: before inserting a new row, count this reservation's rows with
`created_at > now() - 24h` and `status NOT IN ('skipped', 'failed')`. If there are
`config('vouchers.email_daily_cap')` (5) or more, reject with `429`. The count and the insert
run in one transaction after `lockForUpdate` on the reservation row, so two parallel clicks
can't both pass at 4. The `(reservation_id, created_at)` index serves the count.

### State transitions

```text
queued ──claim──▶ sending ──ok──▶ sent          (terminal)
   │                 │
   │                 ├─ reservation cancelled / no_show ─▶ skipped (terminal)
   │                 └─ exception ─▶ (retry: stale claim > 5 min re-claimable) ─▶ failed (terminal, after tries)
   └─ (claim of a row already sent/skipped/failed is a no-op)
```

## Request-only shapes (not stored)

**Generation options**, shared by download, email and preview:

| Key | Rule |
| --- | --- |
| `template_id` | nullable uuid, a template of the same hotel (else `404`) |
| `show_total` | boolean, default false |
| `staff_note` | nullable string ≤ 500 |
| `overrides` | nullable object; only these keys are allowed (FR-007), and any other key gives `422` naming it |

The allowed override keys:

| Override key | Rule |
| --- | --- |
| `primary_guest_name` | string ≤ 200 |
| `other_guest_names` | array of string ≤ 200, max 50 |
| `arrival_date`, `departure_date` | date; departure after arrival, using the overridden arrival or the stored one |
| `room_lines` | array of string ≤ 200, max 50 |
| `adults`, `children` | integer 0–99 |
| `children_ages` | array of integer 0–17 |
| `special_requests` | string ≤ 2000 |
| `hotel_name`, `hotel_address`, `hotel_phone`, `hotel_email` | string ≤ 255 |

`stay.nights` is computed from the displayed dates, so date overrides change it.

**Email-only**: `request_key` (required string ≤ 100) and `email` (optional; it defaults to
the primary guest's email, and when neither exists the response is `422`).

## Enums (new)

- `App\Enums\VoucherLanguage`: `EN = 'en'`, `AR = 'ar'`, with `fromGuestLanguage(?string)`.
- `App\Enums\VoucherField`: the catalogue above, with `kind()` (`text`|`list`).
- `App\Enums\VoucherEmailStatus`: as above.
- `App\Enums\Permission`: `RESERVATIONS_VOUCHER`, `RESERVATIONS_VOUCHER_EMAIL`,
  `VOUCHER_TEMPLATES_VIEW|CREATE|UPDATE|DELETE`.
