# Research: Reservation Voucher with Uploadable Templates

Decisions behind [plan.md](plan.md). Each entry: decision, rationale, alternatives.

## R1. PDF engine

**Decision**: `mpdf/mpdf` `^8.2`, pure PHP, with OpenType layout on (`useOTL`) for the
Arabic font, so letters join and runs are reordered right to left.

**Rationale**:
- It shapes Arabic and handles bidirectional text (FR-006); dompdf does not join Arabic
  letters.
- It needs only `gd`, `mbstring` and `zlib`, all present locally (PHP 8.4, GD with WebP).
  No headless browser, which fits the 2 GB production host (memory
  `mvp-queue-on-postgres`).
- It supports custom page sizes, a full-page background image (`@page` with
  `background-image-resize: 6`) and absolutely positioned blocks in millimetres, which is
  what a box layout needs (FR-017, FR-024).

**Alternatives**:
- dompdf: no Arabic shaping, so it fails FR-006.
- Browsershot or Gotenberg (Chromium): best fidelity, but needs a browser process or an
  extra service and several hundred MB of RAM per render.
- TCPDF: shapes Arabic, but its HTML support is weaker and its API is older; mPDF descends
  from it and is easier to drive from HTML.

## R2. Fonts

**Decision** (revised during implementation, 2026-10-27):
- **English**: Noto Sans (SIL OFL), regular and bold, bundled in `resources/fonts/voucher/`
  and registered through mPDF's `fontdata`.
- **Arabic**: mPDF's own **XB Riyaz** (regular and bold, OpenType layout on), with Noto Sans
  as the substitution font for any glyph it lacks.

No font upload (FR-023).

**Why the change**: the plan chose Noto Naskh Arabic, but mPDF 8.3 cannot read its GPOS
mark-to-ligature tables ("GPOS Lookup Type 5, Format 3 not supported") and refuses to
render it. XB Riyaz is the Arabic font mPDF's own documentation uses. It ships with mPDF,
joins letters correctly, and includes Latin glyphs. A rendered sample was checked visually:
joined letters, right-to-left layout, final ي/ى present.

**Alternatives**:
- Noto Naskh Arabic: not supported by mPDF, see above.
- DejaVu Sans: covers Arabic, but its shaping is weak.
- Amiri: not tried; its GPOS tables are as complex as Naskh's.

## R3. Template image storage and normalization

**Decision**:
- **Validation** (`StoreVoucherTemplateRequest`): `mimetypes:image/png,image/jpeg,image/webp`,
  `max:5120`, then `getimagesize()` on the real file. Short side ≥ 1000 px, long side ≤
  6000 px, ratio between 1:3 and 3:1 (FR-011).
- **Original**: stored at `voucher-templates/{hotel_id}/{template_id}/original.{ext}` on a
  private disk, `config('vouchers.disk')` (default `local`), like `config('knowledge.disk')`.
- **Render copy**: decoded with GD, flattened onto white (transparent PNG and WebP edge
  case), downscaled so the long side is at most 3508 px (A4 at 300 dpi), and saved as
  `render.jpg` (quality 90) next to the original. mPDF only ever reads the render copy.

**Rationale**:
- Re-encoding strips metadata and any payload hidden in the upload.
- Every PDF gets one image format, so WebP support in mPDF doesn't matter.
- The PDF stays small enough for email attachments.
- The 6000 px cap bounds GD memory: the largest decodable image is 6000 × 6000 × 4 bytes
  ≈ 144 MB. This guards against decompression bombs on the 2 GB host, since a 5 MB WebP can
  hold far more pixels than that.

**Alternatives**:
- Pass the original straight to mPDF: WebP and alpha handling depend on the mPDF version,
  and PDFs get large.
- Store in the database: images would bloat backups and queries.

## R4. Coordinate system and page size

**Decision**:
- Boxes are stored as fractions `x, y, w, h` in `[0, 1]` of the image's width and height,
  with the origin at the top-left. The origin is the same for Arabic templates; only text
  alignment mirrors (FR-022).
- The page is sized in millimetres from the image's ratio, with the long side fixed at 297
  mm. Portrait A4-ratio art gives 210 × 297 mm.
- A box's mm position is `x × page_width`, and so on.

**Rationale**:
- Fractions survive replacing the image with another resolution (FR-014, US2 #6).
- The frontend editor can work in the displayed image's fractions with no knowledge of
  pixels.
- Fixing the long side gives printable sizes close to A4 whatever the upload resolution.

**Alternatives**:
- Pixels: these break on re-upload.
- A fixed A4 page with letterboxing: this distorts or pads non-A4 art.

## R5. Fitting text into boxes

**Decision**: a `VoucherTextFitter` measures text with mPDF's `GetStringWidth()` in the box's
font before rendering.
- **shrink**: wrap to the box width, and reduce the size in 0.5 pt steps until the lines fit
  the box height or the size reaches **6 pt**. If it still doesn't fit, truncate the last
  line with "…".
- **truncate**: keep the set size, wrap, cut at the last full line, and end it with "…".
- **List boxes**: one item per line, with the shrink rule. At 6 pt, keep the items that fit
  minus one line and add "+N more", using a localized label (FR-020).
- Each box is rendered as an absolutely positioned block with `overflow: hidden`, so nothing
  can draw outside it even if a measurement is off (FR-021).

**Rationale**: mPDF's own `overflow: auto` shrink-to-fit has no minimum size and no "+N
more". Measuring first makes the result deterministic and testable without parsing the PDF.

**Alternatives**: rely on mPDF auto-shrink (fails the list rule and is untestable); estimate
widths by character count (wrong for proportional and Arabic fonts).

## R6. RTL and alignment

**Decision**:
- **Direction**: an Arabic voucher sets `dir="rtl"` on the document and on each box.
- **Alignment**: `start` maps to right and `end` to left in Arabic, and the reverse in
  English; `center` stays centred.
- **Values**: guest names, emails and references are kept as entered. The bidi algorithm
  keeps Latin runs and digits left to right inside RTL boxes (spec edge case "Mixed
  scripts").
- **Formatting**: dates use Western digits in both languages (spec Assumptions), formatted
  `d M Y` in English and `d MMMM Y` with Arabic month names in Arabic, through Carbon's
  `ar` locale.

## R7. Template selection and language

**Decision**: `VoucherTemplateResolver::resolve(Reservation, ?templateId)` chooses as
follows:
1. If a template id is given, use that template (same hotel, not deleted). Its language
   wins (FR-005).
2. Otherwise the language is `ar` when `guest.preferred_language` starts with `ar` (`ar`,
   `ar-SA`, …), and `en` otherwise. Use the hotel's default template for that language.
3. Otherwise use the built-in layout in that language (FR-016).

A template with an empty layout is still usable; it prints only the image.

**Default uniqueness** (FR-013) is enforced by a partial unique index on `(hotel_id,
language) WHERE is_default AND deleted_at IS NULL`. The service unsets the previous default
in the same transaction before setting the new one.

## R8. Built-in default layout

**Decision**: a Blade view, `resources/views/vouchers/default.blade.php`, rendered on an A4
portrait page.
- **Header**: hotel name and contact block (and `branding.logo_url` when present and
  reachable on the same storage; otherwise none).
- **Body**: a two-column label/value table for the FR-002 content, with lists as rows.
- **Labels**: from `lang/{en,ar}/voucher.php`.
- **Empty values**: rows with no value are omitted.

Template vouchers use `resources/views/vouchers/template.blade.php`: an `@page` background
plus the positioned boxes. These are the first Blade views in the repo; they are rendering
input only, not a UI.

## R9. Email idempotency

**Decision**:
- **Table**: a `voucher_email_requests` row per send request, with a unique index on
  `(hotel_id, reservation_id, request_key)`. `request_key` is a client-generated string (a
  UUID recommended) that the client must send.
- **Repeat key**: the controller tries an insert. On a unique conflict it returns `200` with
  the existing row; on a new row it dispatches `SendReservationVoucherEmailJob` and returns
  `202`.
- **Claim before sending**: the job claims the row by setting `status` from `queued` to
  `sending`, the way `SendGuestRequestNoticeJob` claims tasks. It sends, then sets `sent` with
  `sent_at`. A retry that finds `sent` does nothing (FR-028).
- **Stale claims**: a claim older than 5 minutes can be taken over, so a crashed worker
  doesn't strand the row.
- **The gap between sending and recording**: the claim alone can't stop a duplicate when
  the mail was delivered but the row never became `sent`. That happens when the worker is
  killed after the send, or when the mailer times out after the provider accepted the mail.
  The send therefore carries the provider's idempotency key `voucher-email:{request id}`
  (R10). Resend keeps keys for 24 hours, far longer than the retry and stale-claim window
  (minutes), so a retried send of the same request is dropped by Resend and reported as
  success. Mailers without idempotency (`log`, `array`) are development and test only.
- **No longer eligible**: if the reservation is `cancelled` or `no_show` at send time, the row
  becomes `skipped` with a reason (FR-029).
- **Final failure**: `failed()` sets `failed` with the error class, and the job failure is
  reported as usual.

The key stays unique for as long as the row exists, so a same-key repeat after 24 hours
also returns the original. That is stricter than FR-027 requires, and correct, since a
deliberate resend uses a new key. There's no pruning in MVP; the rows are small and double
as send history.

**Snapshot**: the row stores the options chosen at request time (`template_id`, `language`,
`show_total`, `overrides`, `staff_note`, `recipient`). The job renders the PDF at send time
from those options and the current reservation (spec edge case). The PDF never goes in the
queue payload, which keeps the Postgres `jobs` table small.

**Alternatives**:
- An `Idempotency-Key` header with a cache store: no cache store is shared reliably in the
  MVP setup, and it leaves no send history.
- Rendering into the queue payload: a multi-MB `jobs` row and a stale PDF.

## R10. Mail delivery

**Decision**: a Mailable, `ReservationVoucherMail`, that attaches the PDF with
`attachData(..., 'voucher-{reference}.pdf', ['mime' => 'application/pdf'])`. Its subject and
short body come from `lang/{en,ar}/voucher.php` in the voucher language. It uses the
configured mailer (Resend in production, `log` locally).

**Queue lane**: `default`. Staff aren't waiting in real time and no guest is waiting on a
reply. `$timeout = 120`, `$tries = 3`, with backoff.

**Idempotent delivery**: Laravel's `ResendTransport` calls `$resend->emails->send($params)`
without the SDK's `$options` argument, so an idempotency key can't travel through
`Mail::send()`. A small `VoucherMailSender::send(ReservationVoucherMail $mail, string
$recipient, string $idempotencyKey)` therefore has two paths:
- **When `config('mail.default') === 'resend'`**: it calls the Resend SDK client
  (`Resend::client(config('services.resend.key'))`, bound in the container so tests can
  mock it). The call is `emails->send([...], ['idempotency_key' => $key])` with `from`
  (`config('mail.from')`), `to`, `subject`, `text` (the mailable's rendered text body) and
  `attachments: [['filename' => …, 'content' => base64_encode($pdf), 'content_type' =>
  'application/pdf']]`.
- **Otherwise**: `Mail::to($recipient)->send($mail)`.

The Mailable stays the single source of the subject, body and attachment, and the SDK path
reads from it. Rejected alternatives:
- A subclass of `ResendTransport` that copies `doSend`: it breaks on framework upgrades.
- At-most-once delivery (never retry an uncertain send): it breaks FR-028.

## R11. Audit

**Decision**:
- **Generation**: after a successful render, `EventLogger::record($reservation,
  'voucher_generated', changes: ['overrides' => …, 'staff_note' => …], context:
  ['voucher_template_id' => …|null, 'language' => …, 'show_total' => bool])`. `context` only
  takes scalars, so overrides go in `changes`.
- **Email request**: `EventLogger::record($reservation, 'voucher_email_requested', …)` with
  the same fields plus `voucher_email_request_id` and the recipient.
- **Outcome**: the job's result is recorded with `EventLogger::record($emailRequest,
  'sent'|'skipped'|'failed')` inside `TenantContext::runForHotel()`.
- **Previews**: these write nothing (FR-025).
- **Why guest data is in these entries** (constitution: audit records must not
  unnecessarily expose sensitive information): clarification Q1 made the audit entry the
  only control on overrides, so the overridden values must be kept to show what a voucher
  claimed. The recipient address is the record of where a document was sent. Nothing else
  is copied in (no guest phone, no other contact data, no PDF), and the entries are only
  visible to users with access to the hotel's history.
- **Template changes**: model create/update/delete events are logged automatically like
  other hotel models.

## R12. HTTP responses for PDFs

**Decision**: the voucher and preview endpoints return `application/pdf`
(`Content-Disposition: inline` by default, or `attachment` when `?download=1`). Every error
still goes through `apiResponse()` as JSON. The template image endpoint streams the
original file through `Storage::response()`.

Constitution v2.2.1 (API Design) allows this: an endpoint whose purpose is to deliver a
file may return the binary on success, with errors through `apiResponse()`.
`GET /knowledge-documents/{id}/download` already works this way.

## R13. Request method for generation

**Decision**: `POST /reservation/{reservation}/voucher` takes the options in a JSON body. A
`GET` isn't offered, because overrides and the staff note don't fit cleanly in a query
string and shouldn't land in access logs.

## R14. Permissions

**Decision**: six new cases.
- `reservations.voucher`: generate a voucher, and preview a template with a real
  reservation.
- `reservations.voucher_email`: email a voucher.
- `voucher_templates.view`, `.create`, `.update` and `.delete`: template management.

None of them is in `employeeDefaults()` (FR-032).

Previewing with sample data needs `voucher_templates.view`. Previewing with a real
reservation also needs `reservations.voucher` on that reservation, because it reads guest
data.
