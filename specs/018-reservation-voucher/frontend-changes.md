# Frontend changes: Reservation Voucher with Uploadable Templates

For `ecosystem-frontend`. API details: [voucher templates API](../../docs/voucher-templates-api-documentation.md)
and [reservations API § 7](../../docs/reservations-api-documentation.md#7-reservation-voucher-pdf).
Nothing existing changes; everything below is new.

## 1. Settings → Voucher templates

- **List** `GET /api/voucher-templates`: name, language, a "Default" badge, and a thumbnail from
  `image.url`. Load the image with the auth headers (it is not public); for example fetch it as a
  blob and use an object URL.
- **Upload** `POST /api/voucher-templates` (multipart: `name`, `language`, `is_default`, `image`).
  Show the image rules before upload (PNG/JPG/WebP, ≤ 5 MB, short side ≥ 1000 px, long side ≤
  6000 px, proportions between 1:3 and 3:1) and map `422` keys (`image.dimensions_short_side`,
  `image.dimensions_long_side`, `image.ratio`, `image.type`, `name`) to messages.
- **Edit** `PUT /api/voucher-templates/{id}`: rename, change the language, "Make default".
- **Replace image** `POST /api/voucher-templates/{id}/image`: the layout is kept.
- **Delete** `DELETE /api/voucher-templates/{id}`.
- Gate the page and its actions on `voucher_templates.view|create|update|delete` from the user's
  permissions.

## 2. Layout editor

- The template image is the canvas. Load the field list once from
  `GET /api/voucher-templates/fields` (`kind: list` fields show a list icon).
- The admin drags a field onto the image and resizes it. Store `x, y, w, h` as **fractions of the
  displayed image** (left/top divided by the displayed width/height), clamped so the box stays
  inside.
- A side panel per box: field, font size (6–72), colour (`#RRGGBB`), bold, alignment
  (start/centre/end, with a hint that "start" is the right edge on Arabic templates), overflow
  (shrink/truncate; lists always shrink). A `free_text` box needs its text (≤ 500).
- Give each box a stable `id` (≤ 40 characters). Errors come back keyed `layout.{index}.{key}`
  and every message ends with `(box {id})`; highlight that box.
- **Preview** `POST /api/voucher-templates/{id}/preview` with the unsaved `layout`, either with
  sample data or with a reservation picked by the user (`reservation_id`, which also needs
  `reservations.voucher`). Show the returned PDF in a viewer.
- **Save** `PUT /api/voucher-templates/{id}/layout` sends the whole layout (max 60 boxes).

## 3. Reservation page → "Voucher"

Show the action when the user has `reservations.voucher` and the reservation is not `cancelled`
or `no_show` (the API answers `409` otherwise).

Dialog:
- **Template**: default preselected ("Default for the guest's language"), or any template from
  the list; the voucher's language follows the chosen template.
- **Show total** toggle (off by default).
- **Overrides** (collapsed by default): primary guest name, other guest names, arrival and
  departure dates, room lines, adults, children, children's ages, special requests, hotel name,
  address, phone, email. Send only the fields the user changed. Explain that these change the PDF
  only.
- **Staff note** (≤ 500).
- **Preview / Download**: `POST /api/reservation/{id}/voucher` (`?download=1` to download). The
  response is a PDF. If the `X-Voucher-Unplaced` header is present, warn that those items (for
  example the staff note) have no place on the chosen template.
- **Email to guest** (with `reservations.voucher_email`): optional "send to another address".
  Generate one `request_key` (UUID) when the user clicks and reuse it for any retry of that click.
  `202` means queued; `200` means that click was already sent; `429` means a limit was reached
  (5 voucher emails per reservation per day, or 20 per user per hour; show the message); `422`
  on `email` means the guest has no email address. Hide the hotel name/address/phone/email
  overrides when emailing: they are `422` there and only allowed on download.
- **Preview** PDFs carry a "PREVIEW" stamp; a cancelled or no-show reservation is `409`.
- A `failed` request with `status_reason: uncertain_delivery` may or may not have reached the
  guest; offer "Send again" (a new `request_key`).
- **Send history** `GET /api/reservation/{id}/voucher/emails`: status (`queued`, `sending`,
  `sent`, `skipped`, `failed`), recipient, sent time, and `unplaced`. Poll after a `202` until the
  status is final.

## 4. Roles

The six new permissions appear in the role editor automatically through `GET /api/permissions`.
None is an employee default.
