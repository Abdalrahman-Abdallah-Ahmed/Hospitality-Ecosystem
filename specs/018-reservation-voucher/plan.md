# Implementation Plan: Reservation Voucher with Uploadable Templates

**Branch**: `018-reservation-voucher` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/018-reservation-voucher/spec.md`. This is
SPEC-013 from Phase 2 of the master plan, under D18 as amended on 2026-10-27.

**Depends on** (all on `main`): SPEC-010 reservation rooms (`002`), SPEC-011 guests and
party (`014`), SPEC-012 lifecycle and `no_show` (`015`). Constitution v2.2.1: v2.2.0 allows
staff-initiated document email, and v2.2.1 allows binary file responses.

## Summary

- **PDF engine (R1, R2)**: `mpdf/mpdf ^8.2` with bundled Noto Sans for English and mPDF's own XB Riyaz for Arabic (Noto Naskh Arabic was dropped during implementation: mPDF can't read its GPOS tables; see R2).
  It shapes Arabic and lays text right to left without a headless browser, so it fits the
  2 GB host.
- **Templates (R3, R4, R7)**: a new `VoucherTemplate` model. Each holds a private original
  image plus a normalized `render.jpg`, a `language`, an `is_default` flag (a partial unique
  index per hotel and language) and a JSONB `layout` of boxes. Box positions are fractions
  of the image, and the page takes the image's proportions with the long side at 297 mm.
- **Rendering (R5, R6, R8)**: a `VoucherRenderer` builds a `VoucherData` value object from
  the reservation plus the overrides.
  - It picks the template, or the built-in Blade layout.
  - It fits each box's text with `VoucherTextFitter`, which handles the 6 pt minimum, "…"
    and "+N more".
  - It hands the HTML to mPDF. Boxes are absolutely positioned with `overflow: hidden` and
    mirror their alignment on RTL templates.
- **Generation (R12, R13)**: `POST /reservation/{id}/voucher` returns a PDF. Cancelled and
  no-show reservations get `409`, and each generation is audited (R11).
- **Email (R9, R10)**: `POST …/voucher/email` with a client `request_key`.
  - A `voucher_email_requests` row is unique per hotel, reservation and key, and stores the
    options as a snapshot.
  - `SendReservationVoucherEmailJob` runs on the `default` lane. It claims the row, renders,
    and sends `ReservationVoucherMail` with the PDF attached through `VoucherMailSender`.
    With the Resend mailer, the request id is passed as Resend's idempotency key.
  - The job skips the send if the reservation became ineligible. A retry never sends twice,
    even when the worker dies between sending and recording the send.
- **Preview**: `POST /voucher-templates/{id}/preview` takes sample data or a real
  reservation, plus an optional unsaved layout. It stores, sends and audits nothing.
- **Permissions (R14)**: `reservations.voucher`, `reservations.voucher_email` and
  `voucher_templates.view|create|update|delete`. None of them is in the employee defaults.

## Technical Context

**Language/Version**: PHP 8.3+ (8.4 locally), Laravel 13.

**Primary Dependencies**: `mpdf/mpdf ^8.2` (new). GD for image normalization (already
installed, with WebP support). Resend through the existing mailer. `smalot/pdfparser`
(already installed) is used in tests to read PDF text.

**Storage**: PostgreSQL, with two new tables (`voucher_templates` and
`voucher_email_requests`; see [data-model.md](data-model.md)). Template images go on the
private disk `config('vouchers.disk')`, which defaults to `local`, the same pattern as
`config('knowledge.disk')`.

**Testing**: Pest feature tests on real Postgres:
- endpoint tests for templates, voucher and email;
- `VoucherTextFitter` unit-style tests;
- PDF text assertions with `smalot/pdfparser`;
- `Mail::fake`/`Queue::fake` for email, with the job run synchronously for the idempotency
  cases;
- permission dataset rows and isolation cases.

**Target Platform**: Linux server: JSON API, queue worker (`default` lane), scheduler
untouched.

**Project Type**: Multi-tenant Laravel web service (backend only; the frontend slice runs in
`ecosystem-frontend`).

**Performance Goals**: a voucher renders in ≤ 5 s with the largest render copy (3508 px long
side) on the production host (SC-009). A template upload, including GD normalization of a
6000 px image, finishes in ≤ 5 s.

**Constraints**:
- **Memory**: peak memory per render ≤ 256 MB, achieved by capping the original at 6000 px
  and the render copy at 3508 px.
- **No stored PDFs**: no PDF is stored (FR-001), and none goes in queue payloads.
- **Tenant scope**: on every lookup, including `template_id` and `reservation_id` inside
  request bodies.
- **Email**: only from the staff endpoint (FR-031; constitution v2.2.0).

**Scale/Scope**:
- 2 migrations and 3 new enums, plus 6 `Permission` cases.
- New classes:
  - models: `VoucherTemplate`, `VoucherEmailRequest`;
  - services: `VoucherTemplateService`, `VoucherRenderer`, `VoucherTextFitter`,
    `VoucherTemplateResolver`, `VoucherDataBuilder`, `ReservationVoucherEligibility`,
    `VoucherMailSender`;
  - `ReservationVoucherMail` and `SendReservationVoucherEmailJob`;
  - a policy, 2 controllers, 7 form requests (plus a shared image-rules trait) and 2
    resources;
  - 2 Blade views and 2 language files.
- 13 endpoints.

## Constitution Check

*Gate before Phase 0, re-checked after Phase 1. Constitution v2.2.1.*

| Principle | How this plan complies | Status |
| --- | --- | --- |
| I. PMS is the system of record | The voucher is a read-only view of reservation data. Overrides change only the PDF, never stored records (FR-007, SC-005). | ✅ |
| II. Preserve and evolve | Reuses `EventLogger`, `BelongsToHotel`, `GenericIndexRequest`/`GenericQuery`, `resolveHotel()`, the private-disk pattern of knowledge documents, the claim-before-send pattern of `SendGuestRequestNoticeJob`, and the existing mailer. Adds no new architecture beyond the PDF engine, which nothing existing can replace. | ✅ |
| III. Tenant isolation | Both models use `BelongsToHotel`. Route-model binding is tenant-scoped, so another hotel's record is `404`. `template_id` and `reservation_id` in bodies are resolved through the scoped models. The job runs in `TenantContext::runForHotel()`. Isolation tests cover every route. | ✅ |
| IV. Permission-based authorization | 6 new cases checked through `ChecksPermissions::allows()` in `VoucherTemplatePolicy` and `ReservationPolicy` (`voucher`, `voucherEmail`). None is in `employeeDefaults()`. They're added to the permission docs and `PermissionAuthorizationTest`. | ✅ |
| V. AI acts through tools | No AI tool in this feature. The Concierge and the Admin AI can't generate or email vouchers (spec out of scope). | ✅ |
| VI. Auditability and observability | `reservation.voucher_generated` and `reservation.voucher_email_requested` events record the actor, options and overrides. The job's outcome is recorded on the request row and in the event log. Final job failures are reported as usual. | ✅ |
| VII. Integrity and idempotency | The email is idempotent through a unique `(hotel_id, reservation_id, request_key)` plus claim-before-send (R9). Default uniqueness is enforced by a partial unique index. Layout saves are all-or-nothing. | ✅ |
| VIII. Tested at the domain boundary | Endpoint, renderer, fitter, job idempotency, permission and isolation tests. | ✅ |
| IX. Spec-driven | Spec → 2 clarifications → this plan. Plan D18 and the constitution were amended first. | ✅ |
| Guest Identity and WhatsApp (v2.2.0) | Email only on an explicit staff request, audited; no automatic sends; WhatsApp document delivery post-MVP. | ✅ |
| Financial scope (no ledger in MVP) | Reads `reservation_value` for the optional total and writes nothing to transactions. | ✅ |

| API Design (v2.2.1) | The voucher, preview and template image endpoints deliver files, so they return binary on success; every error goes through `apiResponse()` (R12). | ✅ |
| Privacy (Internationalization, Security, and Privacy) | Audit entries carry the overrides and the recipient only because they are the agreed control for overrides (clarification Q1) and the send record. No other guest contact data is copied, and no PDF is stored (R11). | ✅ |

**Post-design re-check**: passes. The binary file responses are allowed by the v2.2.1
clarification of API Design. `/speckit-analyze` finding D1 led to that amendment.

## Project Structure

### Documentation (this feature)

```text
specs/018-reservation-voucher/
├── spec.md
├── plan.md               # this file
├── research.md           # R1–R14
├── data-model.md
├── quickstart.md
├── contracts/
│   └── voucher-api.md
├── checklists/
│   └── requirements.md
└── tasks.md              # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── Permission.php                         # +6 cases (modify)
│   ├── VoucherLanguage.php                    # new
│   ├── VoucherField.php                       # new: catalogue + kind()
│   └── VoucherEmailStatus.php                 # new
├── Models/
│   ├── VoucherTemplate.php                    # new
│   └── VoucherEmailRequest.php                # new
├── Policies/
│   ├── VoucherTemplatePolicy.php              # new
│   └── ReservationPolicy.php                  # + voucher(), voucherEmail() (modify)
├── Http/
│   ├── Controllers/
│   │   ├── VoucherTemplateController.php      # index/store/show/update/destroy/layout/image/replaceImage/preview/fields
│   │   └── ReservationVoucherController.php   # generate/email/emails
│   ├── Requests/Vouchers/
│   │   ├── StoreVoucherTemplateRequest.php
│   │   ├── UpdateVoucherTemplateRequest.php
│   │   ├── VoucherTemplateImageRequest.php
│   │   ├── VoucherLayoutRequest.php           # shared box rules (also used by preview)
│   │   ├── VoucherOptionsRequest.php          # template_id, show_total, staff_note, overrides
│   │   └── EmailVoucherRequest.php            # + request_key, email
│   └── Resources/
│       ├── VoucherTemplateResource.php
│       └── VoucherEmailRequestResource.php
├── Services/Vouchers/
│   ├── VoucherTemplateService.php             # upload, normalize (GD), replace, set default
│   ├── VoucherTemplateResolver.php            # R7
│   ├── VoucherDataBuilder.php                 # reservation + overrides → VoucherData
│   ├── VoucherData.php                        # readonly value object
│   ├── VoucherTextFitter.php                  # R5
│   ├── VoucherRenderer.php                    # mPDF wrapper; render(VoucherData, ?template, ?layout)
│   ├── ReservationVoucherEligibility.php      # status check shared by controller and job
│   └── VoucherMailSender.php                  # Resend SDK with idempotency key, else Mail (R10)
├── Mail/
│   └── ReservationVoucherMail.php
└── Jobs/
    └── SendReservationVoucherEmailJob.php     # default lane, claim-before-send

config/vouchers.php                            # disk, limits, page long side, min font
database/migrations/
├── 2026_10_27_000001_create_voucher_templates_table.php
└── 2026_10_27_000002_create_voucher_email_requests_table.php
resources/
├── fonts/voucher/                             # Noto Sans (+ OFL.txt); Arabic uses mPDF's XB Riyaz
└── views/vouchers/
    ├── default.blade.php                      # built-in layout
    └── template.blade.php                     # background + positioned boxes
lang/{en,ar}/voucher.php                       # labels, statuses, "+:count more", mail subject/body
routes/api.php                                 # 13 routes (modify)

tests/Feature/
├── VoucherTemplateControllerTest.php
├── ReservationVoucherTest.php
├── VoucherEmailTest.php
├── VoucherRendererTest.php                    # fitter + RTL + page size
├── PermissionAuthorizationTest.php            # dataset rows (modify)
└── TenantIsolationTest.php                    # or covered by CrossTenantRouteTest (verify)
tests/Inventory/entry_points.php               # SendReservationVoucherEmailJob (modify)

docs/
├── voucher-templates-api-documentation.md     # new
├── reservations-api-documentation.md          # voucher + email endpoints (modify)
└── staff-roles-api-documentation.md           # permission reference (modify)
```

**Structure Decision**: the existing single Laravel project layout. Voucher services are
grouped under `app/Services/Vouchers/`, the way `Services/Reservations/` and
`Services/Knowledge/` group theirs.

## Implementation notes

- **Upload order**: create the row inside a transaction, write the files, and on any failure
  delete the files written so far (FR-011: nothing stored on reject). Use
  `DB::afterCommit` for deleting old files on replace, as `IndexKnowledgeDocumentJob` does.
- **Paths**: never trust the client filename. The extension comes from the detected type.
- **mPDF temp dir**: `storage/app/mpdf-tmp` (config). Create it lazily and keep it out of
  git.
- **Page size**: `format` is `[w_mm, h_mm]`, with all margins `0` for templates and the
  standard A4 margins for the built-in layout.
- **Background**: `@page { background-image: url(render path); background-image-resize: 6; }`,
  passed as a local filesystem path, never a URL, so mPDF makes no HTTP fetch.
- **No remote fetches**: disable remote fetches in mPDF, `curlAllowUnsafeSslRequests` false.
  The logo from `branding.logo_url` is used only when it resolves to a local disk path.
- **Sample data for previews**: `VoucherDataBuilder::sample(VoucherLanguage)` with fixed,
  plausible names, including one long name and 4 guests, to exercise fitting.
- **"Unplaced" header**: `VoucherRenderer` returns the list of data keys that were present
  but had no box. The controller sets `X-Voucher-Unplaced`.
- **Job hygiene**: `$timeout = 120`, `$tries = 3`, `backoff [10, 60]`, the
  `QueueLane::DEFAULT` constructor lane, and `TenantContext::runForHotel()` in `handle()`.
  It's listed in `tests/Inventory/entry_points.php` if not driven by the generic checks.

## Frontend slice (`ecosystem-frontend`)

- **Settings → Voucher templates**: a list with a thumbnail through the image endpoint, plus
  upload, rename, language and default.
- **Layout editor**: the image is the canvas. The admin drags and resizes boxes, which are
  stored as fractions of the displayed image. A side panel sets the field, font size,
  colour, bold, alignment and overflow. A preview button uses sample data or a picked
  reservation. Save is all-or-nothing, and the editor shows errors per box `id`.
- **Reservation page → "Voucher" action**: a dialog with a template picker (default
  preselected), a show-total toggle, override fields, a staff note, Preview/Download, and
  "Email to guest". The client generates `request_key` once per dialog submit and shows the
  send status from `GET …/voucher/emails`.
- The new permissions appear automatically in the role editor.

## Complexity Tracking

| Deviation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Binary responses on 3 endpoints (voucher, preview, template image) | The deliverable is a file. Allowed by constitution v2.2.1 (API Design); errors stay `apiResponse()` JSON. | Base64 inside `apiResponse()` adds about 33% to the size, can't be opened inline by the browser, and differs from the existing knowledge-document download. |
| `VoucherMailSender` calls the Resend SDK directly when the mailer is `resend` | Laravel's `ResendTransport` can't pass Resend's idempotency key, and without it a send that succeeded but wasn't marked `sent` (worker killed, or a timeout after delivery) is sent again on retry, breaking FR-028 (R9, R10). | Copying `ResendTransport::doSend` into a subclass breaks on framework upgrades. At-most-once delivery (no retries) breaks FR-028's "MUST be retried". |
| `voucher_email_requests` has no `SoftDeletes` | It's an append-only send history and the idempotency record; a soft-deleted row would let a reused key send again. | Soft deletes add nothing: rows are never deleted by users. |
