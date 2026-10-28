# Contract: Voucher Templates and Reservation Voucher API

All routes are in `routes/api.php` under the standard stack (`api.key → auth:sanctum →
throttle:api → tenant`).
- **Headers**: every request sends `X-API-KEY` and `Authorization: Bearer`.
- **JSON responses** use `{ message, code, body }` via `apiResponse()`.
- **PDF responses** are `application/pdf` (research R12).
- **Another hotel's template or reservation** is answered with `404`.
- **Missing permission** is answered with `403`.

Shapes are defined in [data-model.md](../data-model.md).

---

## Templates

### `GET /api/voucher-templates/fields`
Permission: `voucher_templates.view`. Returns the field catalogue.

```json
{ "body": [
  { "key": "guest.primary_name", "kind": "text", "label": { "en": "Primary guest", "ar": "الضيف الرئيسي" } },
  { "key": "rooms.lines", "kind": "list", "label": { "en": "Room lines", "ar": "الغرف" } },
  { "key": "free_text", "kind": "text", "label": { "en": "Free text", "ar": "نص حر" } }
] }
```

### `GET /api/voucher-templates`
Permission: `voucher_templates.view`. Uses `GenericIndexRequest`: `filter[language]`,
`filter[is_default]`, `search` (name), `sort=name|-created_at`, `per_page`, `page`.
The response body is a paginated list of `VoucherTemplateResource`.

```json
{
  "id": "uuid", "name": "Standard EN", "language": "en", "is_default": true,
  "image": { "mime_type": "image/png", "width_px": 2480, "height_px": 3508, "size_bytes": 812345,
             "url": "/api/voucher-templates/{id}/image" },
  "layout": [ /* field boxes */ ],
  "created_at": "…", "updated_at": "…"
}
```

### `POST /api/voucher-templates` (multipart)
Permission: `voucher_templates.create`.

| Field | Rule |
| --- | --- |
| `name` | required, ≤ 120, unique in the hotel |
| `language` | required, `en`\|`ar` |
| `is_default` | boolean, default false |
| `image` | required file (FR-011) |
| `hotel_id` | super admin only, resolved by `resolveHotel()` |

**Responses**:
- `201`: the template, with `layout: []`.
- `422`: an image error names the failed rule (`image.dimensions_short_side`,
  `image.dimensions_long_side`, `image.ratio`, `image.type`, `image.size`). Nothing is
  stored.

### `GET /api/voucher-templates/{voucherTemplate}`
Permission: `voucher_templates.view`. Returns the template, or `404`.

### `PUT /api/voucher-templates/{voucherTemplate}`
Permission: `voucher_templates.update`. Body: `name?`, `language?`, `is_default?`.

**Behaviour**:
- Setting `is_default: true` clears the hotel's other default for that language in the same
  transaction (FR-013).
- Changing `language` on a default template moves its default flag to the new language. That
  clears the other template's default for the new language, and leaves the old language
  with no default.

**Responses**: `200` with the template.

### `PUT /api/voucher-templates/{voucherTemplate}/layout`
Permission: `voucher_templates.update`. Body: `{ "layout": [ box, … ] }`. This replaces the
whole layout (FR-019).

**Responses**:
- `200`: the template.
- `422`: errors keyed `layout.{i}.{key}`, with the box `id` in the message. The saved layout
  is unchanged.

### `POST /api/voucher-templates/{voucherTemplate}/image` (multipart)
Permission: `voucher_templates.update`. Body: `image` (same rules as upload). The layout is
kept.

**Responses**: `200` with the template, or `422` as on upload.

### `GET /api/voucher-templates/{voucherTemplate}/image`
Permission: `voucher_templates.view`. Streams the original image with its mimetype. There
is no public URL (FR-012).

### `DELETE /api/voucher-templates/{voucherTemplate}`
Permission: `voucher_templates.delete`. Soft-deletes the template; files are kept so a queued
email can still render. If the template was a default, that language falls back to the
built-in layout (FR-015).

**Responses**: `200`.

### `POST /api/voucher-templates/{voucherTemplate}/preview`
Permission: `voucher_templates.view`. A real reservation also needs `reservations.voucher`.

| Field | Rule |
| --- | --- |
| `reservation_id` | nullable uuid; a reservation of the same hotel (else `404`); null = sample data in the template's language |
| `layout` | nullable; an unsaved layout, validated like `PUT …/layout`; not saved |
| `show_total`, `staff_note`, `overrides` | as for generation |

**Responses**: `200` `application/pdf`. Nothing is stored, sent or audited (FR-025).

---

## Reservation voucher

### `POST /api/reservation/{reservation}/voucher`
Permission: `reservations.voucher`. Query: `download=1` sets `Content-Disposition:
attachment`; the default is `inline`.

```json
{
  "template_id": "uuid|null",
  "show_total": false,
  "staff_note": "Airport pickup 14:00",
  "overrides": { "primary_guest_name": "ACME Travel LLC" }
}
```

| Status | When |
| --- | --- |
| `200` `application/pdf` | generated; header `X-Voucher-Unplaced: voucher.staff_note` when a sent note or override has no box on the template (US4 #5) |
| `404` | reservation or `template_id` not in the user's hotel, or a deleted template |
| `409` | reservation `cancelled` or `no_show`: `{ message: "A voucher cannot be issued for a cancelled reservation." }` |
| `422` | invalid options or an override key not allowed (named in the error) |

**Side effects**: one `reservation.voucher_generated` event log entry (FR-009).

### `POST /api/reservation/{reservation}/voucher/email`
Permission: `reservations.voucher_email`. Body: the generation options plus the following.

| Field | Rule |
| --- | --- |
| `request_key` | required string ≤ 100, generated by the client per click |
| `email` | optional; defaults to the primary guest's email |

**Responses**:

| Status | When |
| --- | --- |
| `202` | queued: `body` = `VoucherEmailRequestResource` `{ id, status: "queued", status_reason, recipient, language, voucher_template_id, show_total, unplaced, created_at, sent_at }`. `unplaced` is filled once sent. |
| `200` | same `request_key` already used for this reservation: the existing request with its current status, nothing queued (FR-027) |
| `404` / `409` | as for generation |
| `429` | 5 new requests for this reservation in the last rolling 24 hours, not counting `skipped`/`failed` ones (FR-027a). The same-key check runs first, so a repeat still gets `200`. Nothing is queued or audited. |
| `422` | no recipient (`email` missing and the guest has none), invalid email, invalid options |

**Side effects**: one `reservation.voucher_email_requested` event; a
`SendReservationVoucherEmailJob` on the `default` lane; the job's outcome is recorded on the
request (`sent`/`skipped`/`failed`) and in the event log.

### `GET /api/reservation/{reservation}/voucher/emails`
Permission: `reservations.voucher_email`. Lists the reservation's email requests, newest
first and paginated, so the UI can show "sent at…" after a `202`.

---

## Permissions (`GET /api/permissions` picks these up automatically)

| Case | Value |
| --- | --- |
| `RESERVATIONS_VOUCHER` | `reservations.voucher` |
| `RESERVATIONS_VOUCHER_EMAIL` | `reservations.voucher_email` |
| `VOUCHER_TEMPLATES_VIEW` | `voucher_templates.view` |
| `VOUCHER_TEMPLATES_CREATE` | `voucher_templates.create` |
| `VOUCHER_TEMPLATES_UPDATE` | `voucher_templates.update` |
| `VOUCHER_TEMPLATES_DELETE` | `voucher_templates.delete` |

None is in `employeeDefaults()`.
