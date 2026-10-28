# Voucher Templates API Documentation

**Feature**: Reservation Voucher with Uploadable Templates (SPEC-013, `specs/018-reservation-voucher`)

**Date**: 2026-10-27

---

## Overview

A voucher template is a hotel's own voucher design: one image (the letterhead, logo, labels and
artwork) plus a **layout** of boxes saying where each piece of reservation data is printed. When
staff generate a voucher ([reservations API § 7](reservations-api-documentation.md#7-reservation-voucher-pdf)),
the template image fills the PDF page and the data is printed inside the boxes.

- A hotel can have any number of named templates.
- Each template is **English** (`en`) or **Arabic** (`ar`). At most one template per language is
  the hotel's **default**; it is used when staff don't pick a template.
- With no template for the voucher's language, a built-in layout is used.
- Templates are single-page. There are no QR codes and no custom fonts.

All requests send `X-API-KEY` and `Authorization: Bearer <token>`. JSON responses use
`{ message, code, body }`. Another hotel's template is `404`; a missing permission is `403`.

---

## Image rules

| Rule | Error key on failure |
| --- | --- |
| PNG, JPG or WebP, checked on the file's content, not its name | `image.type` (or `image` for a non-image upload) |
| At most 5 MB | `image` |
| Shorter side at least 1000 px | `image.dimensions_short_side` |
| Longer side at most 6000 px | `image.dimensions_long_side` |
| Proportions between 1:3 and 3:1 | `image.ratio` |

A rejected upload stores nothing. Images are private: they are only served by
`GET /api/voucher-templates/{id}/image` to users of the same hotel.

The voucher page takes the image's proportions, with its long side at 297 mm (A4 art gives an A4
page). Transparent areas print white.

---

## Layout

A layout is a list of up to **60 boxes**, saved and validated as a whole. Positions and sizes
are **fractions of the image** (0 to 1, from the top-left corner), so a layout keeps working when
the image is replaced by one of another resolution with the same proportions. The editor should
work in fractions of the displayed image.

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
| `id` | Required string ≤ 40, unique within the layout. Every error about a box names it: `… (box b1)`. |
| `field` | Required, one of the [fields](#fields). A field may appear more than once. |
| `x`, `y` | Required, `0 ≤ value < 1` |
| `w`, `h` | Required, `0 < value ≤ 1`, and `x + w ≤ 1`, `y + h ≤ 1` (the box lies inside the image) |
| `font_size` | Required, 6–72 (points) |
| `color` | Required, `#RRGGBB` |
| `bold` | Boolean, default `false` |
| `align` | `start` (default), `center` or `end`. On Arabic templates `start` is the right edge. |
| `overflow` | `shrink` (default) or `truncate` |
| `text` | Required for `free_text` boxes (≤ 500 characters); must be empty for every other field |

**How text fits:** text never prints outside its box.
- `shrink` wraps the text and lowers the size step by step, down to 6 pt; if it still doesn't
  fit, the last line ends with "…".
- `truncate` keeps the size and ends the last line that fits with "…".
- List fields print one item per line and always shrink; at 6 pt they show as many items as fit
  and end with "+N more" ("+N أخرى" in Arabic).

Phone, email, reference and total print left to right even on Arabic templates.

### Fields

| Key | Kind | Value |
| --- | --- | --- |
| `hotel.name`, `hotel.address`, `hotel.phone`, `hotel.email` | text | The hotel's details |
| `reservation.reference` | text | The reservation reference |
| `reservation.status` | text | Localized status, e.g. "Confirmed" |
| `guest.primary_name` | text | Primary guest |
| `guests.others` | list | Other guests |
| `guests.all` | list | Primary guest first, then the others |
| `stay.arrival_date`, `stay.departure_date` | text | e.g. "10 December 2026" / "10 ديسمبر 2026" |
| `stay.nights` | text | Number of nights |
| `rooms.lines` | list | One line per booked room: the room type name (never a room number) |
| `party.adults`, `party.children` | text | Counts |
| `party.children_ages` | text | e.g. "4, 9" |
| `reservation.special_requests` | text | Special requests |
| `reservation.total` | text | Total and currency, only when staff choose to show it |
| `voucher.staff_note` | text | The note staff add when generating |
| `voucher.issue_date` | text | Today, in the hotel's time zone |
| `free_text` | text | The box's own `text` (labels are usually drawn into the image instead) |

---

## Endpoints

| Method and path | Permission | Purpose |
| --- | --- | --- |
| `GET /api/voucher-templates/fields` | `voucher_templates.view` | Field catalogue for the editor |
| `GET /api/voucher-templates` | `voucher_templates.view` | List |
| `POST /api/voucher-templates` | `voucher_templates.create` | Upload |
| `GET /api/voucher-templates/{id}` | `voucher_templates.view` | Show |
| `PUT /api/voucher-templates/{id}` | `voucher_templates.update` | Rename, language, default |
| `PUT /api/voucher-templates/{id}/layout` | `voucher_templates.update` | Save the layout |
| `POST /api/voucher-templates/{id}/image` | `voucher_templates.update` | Replace the image |
| `GET /api/voucher-templates/{id}/image` | `voucher_templates.view` | Original image |
| `DELETE /api/voucher-templates/{id}` | `voucher_templates.delete` | Delete |
| `POST /api/voucher-templates/{id}/preview` | `voucher_templates.view` (+ `reservations.voucher` with a real reservation) | Preview as PDF |

None of these permissions is an employee default.

### The template object

```json
{
  "id": "uuid",
  "hotel_id": "uuid",
  "name": "Standard EN",
  "language": "en",
  "is_default": true,
  "image": {
    "mime_type": "image/png",
    "width_px": 2480,
    "height_px": 3508,
    "size_bytes": 812345,
    "url": "/api/voucher-templates/{id}/image"
  },
  "layout": [],
  "created_at": "2026-10-27T10:00:00.000000Z",
  "updated_at": "2026-10-27T10:00:00.000000Z"
}
```

### 1. Field catalogue

`GET /api/voucher-templates/fields`

```json
{ "message": "Voucher fields retrieved successfully", "code": 200, "body": [
  { "key": "guest.primary_name", "kind": "text", "label": { "en": "Primary guest", "ar": "الضيف الرئيسي" } },
  { "key": "rooms.lines", "kind": "list", "label": { "en": "Room lines", "ar": "الغرف" } }
] }
```

### 2. List

`GET /api/voucher-templates?filter[language]=ar&filter[is_default]=1&search=Stan&sort=-created_at&per_page=15`

The usual list parameters. `search` matches the name. Deleted templates are not listed.
A super admin sees every hotel's templates; `filter[hotel_id]=<uuid>` narrows the list to one
hotel (for a scoped user it can only narrow within their own hotels).

### 3. Upload

`POST /api/voucher-templates` (multipart/form-data)

| Field | Rule |
| --- | --- |
| `name` | Required, ≤ 120, unique in the hotel (ignoring case; a deleted template's name is free) |
| `language` | Required, `en` or `ar` |
| `is_default` | Optional boolean. `true` makes it the default for its language and clears the previous one. |
| `image` | Required file, see [image rules](#image-rules) |
| `hotel_id` | Super admins only, who must name the hotel; a hotel admin always gets their own hotel |

`201` with the template and `layout: []`. `422` names the failed rule.

### 4. Show

`GET /api/voucher-templates/{id}` → `200` with the template.

### 5. Update

`PUT /api/voucher-templates/{id}` with any of `name`, `language`, `is_default`.

- `is_default: true` clears the hotel's other default for that language.
- Changing the language of a default template keeps it the default, now of the new language,
  and clears that language's previous default. The old language is left with no default.

### 6. Save the layout

`PUT /api/voucher-templates/{id}/layout` with `{ "layout": [ box, … ] }`

Replaces the whole layout. `422` with errors keyed `layout.{index}.{key}` (each message names the
box id) keeps the previous layout. Defaults are filled in the stored layout.

### 7. Replace the image

`POST /api/voucher-templates/{id}/image` (multipart, field `image`, same rules as upload)

The layout is kept. `200` with the template.

### 8. Image

`GET /api/voucher-templates/{id}/image` streams the original upload with its content type.

### 9. Delete

`DELETE /api/voucher-templates/{id}` → `200`. A soft delete: the template leaves the list, its
name becomes free, and a voucher email already queued with it still renders. If it was a default,
that language uses the built-in layout until another default is chosen.

### 10. Preview

`POST /api/voucher-templates/{id}/preview` returns `application/pdf`.

| Field | Rule |
| --- | --- |
| `reservation_id` | Optional. A reservation of the same hotel (else `404`); needs `reservations.voucher` too. Without it, sample data in the template's language is used. |
| `layout` | Optional unsaved layout, validated like § 6. It is used for this preview only and not saved. |
| `show_total`, `staff_note`, `overrides` | As for [generating a voucher](reservations-api-documentation.md#generation-options) |

A preview stores, sends and audits nothing, so it is stamped **PREVIEW** across the page and
can't pass for a voucher. With sample data, overrides change the sample values. A cancelled or
no-show `reservation_id` is `409`, as for generating a voucher.

---

## Audit

Template create, update and delete are recorded in the event log like other hotel records.
Voucher generation and email requests are recorded on the reservation
(`reservation.voucher_generated`, `reservation.voucher_email_requested`), and the email outcome on
the email request (`voucher_email_request.sent` / `skipped` / `failed`).
