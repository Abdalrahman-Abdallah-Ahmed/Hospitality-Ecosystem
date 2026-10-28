# Feature Specification: Reservation Voucher with Uploadable Templates

**Feature Branch**: `018-reservation-voucher`

**Created**: 2026-10-27

**Status**: Draft

**Input**: User description: "SPEC-013 Reservation Voucher (PDF) with uploadable templates".
This is SPEC-013 from Phase 2 of `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md`
(item 10), under decision D18 as amended on 2026-10-27. It consumes the reservation
restructure (`specs/002-reservation-rooms`), guests and party
(`specs/014-reservation-guests-party`) and the lifecycle (`specs/015-reservation-lifecycle`).

## Overview

Guests and partners ask hotels for a document proving a reservation exists. Today staff
retype reservation details into their own templates by hand, which is slow and error-prone.

This feature:

- **Generates a voucher.** A one-page PDF proof of reservation, built from the saved
  reservation at the moment it is requested. It is not an invoice and is never stored.
- **Uses the hotel's own design.** A hotel uploads one or more voucher template images
  (its letterhead, logo, labels and artwork) and marks where each piece of reservation data
  goes. The voucher prints the data into those places.
- **Works without a design.** A hotel that has not uploaded a template gets a clean
  built-in layout with its name and contact details.
- **Speaks the guest's language.** English or Arabic, chosen from the guest's preferred
  language, with right-to-left layout and correctly joined Arabic letters.
- **Can be emailed.** Staff download the voucher or send it to the guest's email with the
  PDF attached. A retry never sends it twice.

**In scope**: template upload and management, field placement and styling, the built-in
default layout, preview, voucher generation with per-request overrides, voucher email,
permissions, audit, docs.

**Out of scope**: QR or verification codes; multi-page templates; custom font upload;
storing generated PDFs or keeping a voucher history beyond the audit log; the drag-and-drop
layout editor itself (a frontend slice in `ecosystem-frontend`; the backend stores and
validates the layout); sending the voucher through the Guest Concierge or the Admin AI;
prices per room line, taxes or payment details (vouchers are not invoices); sending the
voucher by WhatsApp (post-MVP); sending it automatically on any event.

## Clarifications

### Session 2026-10-27

- Q: Can a voucher be generated for a `pending` or `no_show` reservation? → A: `pending`
  yes (its status is printed on the voucher); `no_show` and `cancelled` no.
- Q: May staff email the voucher while the guest's WhatsApp window is open, given the
  constitution's WhatsApp-first contact rule? → A: Yes. Email is optional and sent only when
  a staff member triggers it; nothing emails a voucher automatically. Sending the voucher by
  WhatsApp is post-MVP. The constitution gets a note allowing staff-initiated document sends
  by email.
- Q: Should overriding dates, guest names and party counts need its own permission or
  approval? → A: No. Anyone allowed to generate (or email) a voucher may use every allowed
  override; the audit entry with the overrides is the control.
- Q: Should a voucher show the physical room number when a room is assigned? → A: No.
  Room lines never show room numbers, only the room type.
- Q: Should there be a cap on voucher emails per reservation? → A: Yes. At most 5 voucher
  emails per reservation in any rolling 24 hours; more are rejected with a clear reason and
  nothing is queued.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Download a voucher for a reservation (Priority: P1)

A front-desk agent opens a confirmed reservation and clicks "Voucher". They receive a PDF
showing the hotel's details, the reservation reference and status, the primary guest and
other guests, arrival and departure dates with the number of nights, each room-type line,
the adults, children and children's ages, and the special requests. The total is not shown.
For an Arabic-speaking guest the voucher is in Arabic and reads right to left.

**Why this priority**: It is the core value and works on its own with the built-in layout,
before any hotel uploads a template.

**Independent Test**: For a hotel with no templates, create a confirmed reservation with two
room lines, a primary guest, one other guest and a child; request the voucher; the PDF
contains every listed field and no price. Repeat with a guest whose preferred language is
Arabic; the text is Arabic and right-aligned.

**Acceptance Scenarios**:

1. **Given** a confirmed reservation in a hotel with no templates, **When** a permitted user
   requests its voucher, **Then** a one-page PDF in the built-in layout is returned with all
   fields listed above and no total.
2. **Given** the same request with "show total" turned on, **When** the voucher is generated,
   **Then** the reservation's total value and currency appear.
3. **Given** a guest whose preferred language is Arabic, **When** the voucher is generated,
   **Then** labels and generated text are in Arabic, laid out right to left, with letters
   joined correctly; names and free text entered in Latin script still print legibly.
4. **Given** a guest whose preferred language is neither English nor Arabic (or none),
   **When** the voucher is generated, **Then** it is in English.
5. **Given** a cancelled or no-show reservation, **When** a voucher is requested, **Then**
   the request is rejected with a clear reason and nothing is generated or audited as
   generated.
6. **Given** a pending reservation, **When** a voucher is requested, **Then** it is generated
   and shows the status "pending".
7. **Given** any successful generation, **Then** an audit entry records the actor, the
   reservation, the template used (or "default"), the language, whether the total was
   shown, and any overrides.

---

### User Story 2 - Upload a template and place the fields (Priority: P1)

A hotel admin uploads their voucher artwork as an image, names it "Standard EN", marks it
English and the default for English. In the layout editor they draw a box where the guest
name goes, another for the dates, a list box for the room lines, and so on, choosing font
size, colour, bold and alignment for each. They save the layout. From then on English
vouchers print on that artwork.

**Why this priority**: It is the flexibility the hotel asked for; without it every voucher
looks the same.

**Independent Test**: Upload a valid image, save a layout with three boxes, set it default
for English, generate a voucher for an English-speaking guest; the PDF has the image as its
page background and the three values inside their boxes.

**Acceptance Scenarios**:

1. **Given** a PNG, JPG or WebP image of at most 5 MB whose shorter side is at least 1000
   pixels, **When** an admin uploads it with a name and a language, **Then** a template is
   created for their hotel with an empty layout.
2. **Given** an image that is too large, too small, of another type, or not really an image,
   **When** it is uploaded, **Then** the upload is rejected with a reason naming the failed
   rule and nothing is stored.
3. **Given** a template, **When** the admin saves a layout of field boxes, **Then** each box
   is stored with its field, position and size as fractions of the image (each between 0 and
   1, the box lying fully inside the image), and its styling.
4. **Given** a layout with a box outside the image, an unknown field, a negative or zero
   size, an invalid colour or an unsupported option, **When** it is saved, **Then** the
   whole layout is rejected with the failing box identified and the previous layout is kept.
5. **Given** a template set as default for English, **When** another English template is set
   as default, **Then** the first one stops being the default; there is never more than one
   default per language per hotel.
6. **Given** a template whose image is replaced by a new upload of a different resolution
   but the same proportions, **Then** the saved boxes still land in the same places.
7. **Given** an English default template with a layout, **When** a voucher is generated for
   an English-speaking guest, **Then** the template image fills the page, the page has the
   image's proportions, and each field's text appears inside its box with its styling.

---

### User Story 3 - Preview a template before using it (Priority: P2)

While placing fields, the admin previews the template filled with sample data, and then
with a real reservation, to check that long names and lists fit.

**Why this priority**: Without a preview, mistakes are found only when a guest receives the
voucher.

**Independent Test**: Request a preview of a template with sample data and with a chosen
reservation; both return a PDF and neither writes a voucher audit entry or sends anything.

**Acceptance Scenarios**:

1. **Given** a template, **When** a permitted user previews it with sample data, **Then** a
   PDF is returned with plausible sample values in every placed box, in the template's
   language.
2. **Given** a template and a reservation of the same hotel, **When** the user previews with
   that reservation, **Then** the PDF shows the real values exactly as a voucher would.
3. **Given** a preview with an unsaved layout sent in the request, **Then** the PDF uses that
   layout and the saved layout is not changed.
4. **Given** a reservation or template of another hotel, **When** it is used in a preview,
   **Then** the response is not found.

---

### User Story 4 - Choose a template and override displayed values (Priority: P2)

A guest asks for the voucher to show their company's name instead of their own, and the
agent wants to add a note about airport pickup. The agent picks the "Corporate" template
instead of the default, overrides the guest name, adds a staff note and downloads the PDF.
The reservation itself is unchanged.

**Why this priority**: Real requests often need small per-document adjustments, and hotels
keep several designs.

**Independent Test**: Generate a voucher with a named template, an override of the primary
guest name and a staff note; the PDF shows the overrides; reloading the reservation shows the
original values; the audit entry lists the overrides.

**Acceptance Scenarios**:

1. **Given** several templates, **When** staff name one of them in the request, **Then** that
   template is used whatever its language default status, and the text language follows the
   template's language.
2. **Given** no template named, **When** a voucher is generated, **Then** the hotel's default
   template for the guest's language is used; if there is none, the built-in layout is used.
3. **Given** overrides for allowed fields and a staff note, **When** the voucher is
   generated, **Then** the PDF shows the overridden values and the note, and the reservation
   and its guests are unchanged.
4. **Given** an override for a field that cannot be overridden (such as the reservation
   reference or status), **When** it is sent, **Then** the request is rejected with the
   field named.
5. **Given** a template that has no box for the staff note, **When** a note is sent,
   **Then** the voucher is still generated and the response says the note was not placed.
   For an emailed voucher, the fields that were not placed are recorded on the email request
   when it is sent and shown in its send history.

---

### User Story 5 - Email the voucher to the guest (Priority: P2)

After downloading, the agent clicks "Email to guest". The guest receives an email in their
language with the voucher attached. The agent's network drops and the browser retries; the
guest still receives only one email.

**Why this priority**: Most vouchers are sent to the guest; doing it from the system saves
a manual step and leaves a record.

**Independent Test**: Request a voucher email twice with the same request key; one email is
queued and sent, the second request returns the first result; both are visible in the audit.

**Acceptance Scenarios**:

1. **Given** a reservation whose primary guest has an email address, **When** a permitted
   user requests the voucher email, **Then** the email is queued with the voucher (with the
   same template, total and override choices allowed as for download) attached, and the
   response confirms it was queued.
2. **Given** the same request repeated with the same request key within 24 hours, **Then** no
   second email is queued, and the response reports the original one.
3. **Given** the staff choose a different address for this send, **When** it is valid, **Then**
   the email goes to that address; the guest record is not changed.
4. **Given** no guest email and no address in the request, **When** an email is requested,
   **Then** it is rejected with a clear reason.
4a. **Given** 5 voucher emails already requested for a reservation in the last 24 hours,
   **When** a sixth is requested with a new request key, **Then** it is rejected as too many
   requests, nothing is queued, and a repeat of an earlier request key still returns that
   earlier request.
5. **Given** the email fails to send, **Then** the failure is retried by the queue, reported
   as a job failure if it keeps failing, and never sends a second copy once one has gone out.
6. **Given** the email is queued, **Then** an audit entry records the actor, reservation,
   recipient address, template, language, total choice and overrides.

---

### User Story 6 - Manage templates (Priority: P3)

Admins list their hotel's templates with name, language, default flag and thumbnail; rename
them; change the language; replace the image; and delete templates they no longer use.

**Why this priority**: Housekeeping of the template list; needed but not on the critical
path.

**Independent Test**: Create two templates, rename one, delete the other; the list shows one
renamed template; another hotel's admin sees none of them.

**Acceptance Scenarios**:

1. **Given** templates in two hotels, **When** an admin lists templates, **Then** only their
   hotel's templates are returned, with filtering, search, sort and pagination like other
   lists.
2. **Given** a default template, **When** it is deleted, **Then** that language has no
   default and the built-in layout is used until another is chosen.
3. **Given** a template's image, **When** a permitted user requests it, **Then** it is served
   only to users of that hotel; there is no public link.
4. **Given** a template of another hotel, **When** it is read, updated or deleted, **Then**
   the response is not found.

---

### Edge Cases

- **Long content**: a list box with more guests or room lines than fit shrinks the text down
  to 6 pt, then ends with "+N more". A single-value box set to "shrink"
  shrinks to the minimum size, then truncates with an ellipsis; one set to "truncate" cuts at
  the box edge with an ellipsis. Text never spills outside its box.
- **Mixed scripts**: an Arabic voucher printing a Latin guest name, a reference with digits,
  or an email address keeps each run in its natural direction.
- **Alignment in Arabic**: "start" means right and "end" means left on an Arabic template;
  "centre" is unchanged.
- **Empty values**: a box whose field has no value (no special requests, no children) prints
  nothing; the built-in layout omits the whole row.
- **Assigned or unassigned rooms**: room lines show the room type only; a voucher never
  prints a physical room number, so a lost or forwarded voucher does not reveal where the
  guest sleeps and a later reassignment does not make it wrong.
- **Very wide or very tall images**: the page takes the image's proportions within sensible
  limits (an aspect ratio between 1:3 and 3:1); outside that the upload is rejected.
- **Transparent PNG**: transparent areas print as white.
- **Template deleted between preview and generate**: the request names a missing template
  and is rejected as not found; staff can retry with the default.
- **Template language differs from guest language**: when staff pick a template explicitly,
  the template's language wins.
- **Guest language changed after an email was queued**: the email already queued keeps the
  language, template and options chosen when it was requested.
- **Status at send time**: if the reservation is cancelled after an email is queued but
  before it is sent, the email is not sent and the job records why.
- **No-show undone**: a reservation whose no-show is undone returns to its earlier status
  and can get a voucher again.

## Requirements *(mandatory)*

### Functional Requirements

**Voucher content and generation**

- **FR-001**: The system MUST generate a one-page voucher PDF for a reservation from its
  current saved data at the time of the request, and MUST NOT store the PDF.
- **FR-002**: The voucher MUST be able to show: hotel name, address, phone and email;
  reservation reference and status; primary guest name; other guests' names; arrival and
  departure dates; number of nights; each room-type line (room type only, never a room
  number); adults, children and children's ages; special requests; the total value and
  currency (only when requested); a staff note (only when given); and the issue date.
- **FR-003**: The total MUST be hidden unless the request asks to show it.
- **FR-004**: The system MUST generate vouchers for `pending`, `confirmed`, `checked_in` and
  `checked_out` reservations, and MUST refuse `cancelled` and `no_show` ones with a clear
  reason. The status is printed on the voucher.
- **FR-005**: The voucher language MUST be Arabic when the guest's preferred language is
  Arabic and English otherwise, unless a template is named explicitly, in which case the
  template's language is used.
- **FR-006**: Arabic vouchers MUST lay text out right to left with correctly joined letters,
  and MUST keep Latin names, digits and email addresses legible within Arabic text.
- **FR-007**: The request MAY override these displayed values: primary guest name, other
  guests' names, arrival and departure dates, room-line labels, adults, children, children's
  ages, special requests and the hotel contact block. Overrides MUST affect only that PDF
  and MUST NOT change any stored record. Any other field named as an override MUST be
  rejected. Overrides need no permission beyond the one for generating (or emailing) the
  voucher; every override is recorded in the audit entry (FR-009, FR-030).
- **FR-008**: The request MAY include a staff note of up to 500 characters.
- **FR-009**: Each voucher generation MUST write an audit entry recording the actor, hotel,
  reservation, template (or default layout), language, whether the total was shown, and the
  overrides and note.

**Templates**

- **FR-010**: A hotel MUST be able to hold any number of named voucher templates. Each
  template has a name (unique within the hotel), a language (English or Arabic), a default
  flag, one image and a layout.
- **FR-011**: Template images MUST be PNG, JPG or WebP, at most 5 MB, with a shorter side of
  at least 1000 pixels, a longer side of at most 6000 pixels and an aspect ratio between 1:3
  and 3:1. The file's real content, not only its name, MUST be checked.
- **FR-012**: Template images MUST be stored privately per hotel and served only to
  authenticated users of that hotel with permission to view templates.
- **FR-013**: There MUST be at most one default template per language per hotel; making a
  template default MUST clear the previous default of that language in the same operation.
- **FR-014**: Replacing a template's image MUST keep its layout.
- **FR-015**: Deleting a template MUST NOT affect reservations or past audit entries; if it
  was a default, that language falls back to the built-in layout.
- **FR-016**: When a hotel has no usable template for the voucher's language and none is
  named, the system MUST use a built-in default layout showing the content of FR-002 with
  the hotel's name and contact details.

**Layout**

- **FR-017**: A layout MUST be a list of field boxes. Each box has: a field, a position and
  size expressed as fractions of the image width and height (x, y, width, height, each from
  0 to 1, with the box fully inside the image and a non-zero size), a font size, a colour, a
  bold flag, an alignment (start, centre or end) and an overflow rule (shrink or truncate).
- **FR-018**: Box fields MUST come from a fixed catalogue: the reservation and hotel values
  of FR-002, plus "free text" boxes carrying text the admin enters (up to 500 characters),
  plus list boxes for "guest names" and "room lines". The catalogue MUST be readable through
  the API so the editor can offer it.
- **FR-019**: A layout MUST be saved as a whole and validated as a whole; an invalid layout
  MUST be rejected with the failing box identified, keeping the previous layout.
- **FR-020**: List boxes MUST print one item per line, shrink down to a minimum of 6 pt to
  fit, and end with "+N more" when items still do not fit. Single-value boxes set to shrink
  use the same 6 pt minimum.
- **FR-021**: Text MUST never be drawn outside its box.
- **FR-022**: "Start" and "end" alignment MUST mirror on Arabic templates.
- **FR-023**: Vouchers MUST use fonts bundled with the system that cover Latin and Arabic;
  admins cannot upload fonts.
- **FR-024**: The rendered page MUST have the template image's proportions with the image
  filling the page, and each box MUST appear at the same relative place as in the layout.

**Preview**

- **FR-025**: A permitted user MUST be able to preview a template as a PDF with sample data
  or with a reservation of the same hotel, optionally with an unsaved layout in the request.
  A preview MUST NOT change stored data, send anything, or write a voucher audit entry.
  Because it is not audited, a preview MUST be visibly marked "PREVIEW", and a preview with
  a reservation that cannot get a voucher (FR-004) MUST be refused like generation.
  Overrides apply to sample data as they do to a reservation.

**Email**

- **FR-026**: A permitted user MUST be able to email the voucher to the primary guest's email
  address, or to an address given in the request, with the PDF attached and a short message
  in the voucher's language. The guest record MUST NOT change.
- **FR-027**: The email MUST be sent in the background. The request MUST carry a request key;
  a repeat with the same key for the same reservation within 24 hours MUST NOT queue or send
  a second email and MUST return the original result.
- **FR-027a**: The system MUST accept at most 5 new voucher email requests per reservation
  in any rolling 24 hours, whatever the recipient. Requests that ended `skipped` or `failed`
  do not count; repeats of an existing request key are not new requests. A request over the
  cap MUST be rejected with a clear reason and MUST NOT queue anything. The same applies to
  more than 20 new voucher email requests by one staff user in any rolling hour, across all
  reservations.
- **FR-027b**: An emailed voucher MUST NOT override the hotel's own contact block (name,
  address, phone, email): it is sent from the hotel's sending domain, and changed contact
  details would make it a convincing phishing message. Those overrides stay available for
  downloads.
- **FR-028**: A failing send MUST be retried and, when retries are exhausted, reported as a
  job failure; a send that already succeeded MUST NOT be repeated by a retry. With a mailer
  that cannot drop a duplicate, a retry after the mail may already have gone out MUST give
  up (`uncertain_delivery`) rather than risk a second email.
- **FR-029**: If the reservation becomes ineligible before the email is sent, the email MUST
  NOT be sent and the reason MUST be recorded.
- **FR-030**: Each email request MUST write an audit entry recording the actor, hotel,
  reservation, recipient, template, language, total choice, overrides and note, and its
  outcome MUST be traceable (sent or failed).
- **FR-031**: Emailing a voucher MUST happen only when a staff member explicitly requests it,
  whatever the state of the guest's WhatsApp window. No reservation event, job or AI agent
  may email a voucher on its own. Sending the voucher by WhatsApp is out of scope (post-MVP).

**Access and isolation**

- **FR-032**: Generating and previewing a voucher for a reservation MUST require a voucher
  permission; emailing it MUST require a separate voucher-email permission; template view,
  create, update and delete MUST each require their own permission. Hotel admins and super
  admins hold all of them; employees hold them only through a staff role. None are in the
  employee defaults.
- **FR-033**: Templates and vouchers MUST be hotel-scoped. Another hotel's reservation or
  template MUST respond as not found, including inside preview requests.
- **FR-034**: Permission discovery for the role editor MUST list the new permissions.

### Key Entities

- **Voucher Template**: a hotel's voucher design. Name, language (English/Arabic), default
  flag, the image (type, pixel width and height, size), the layout, who created it and when.
  Belongs to one hotel.
- **Layout Field Box**: one placed field on a template: field key, position and size as
  fractions of the image, font size, colour, bold, alignment, overflow rule, and the text
  for free-text boxes. Part of a template's layout.
- **Voucher** (not stored): the PDF built from a reservation, a template or the built-in
  layout, a language, the show-total choice, overrides and a staff note.
- **Voucher Email Request**: a request to send a voucher, identified by its request key and
  reservation, with the recipient and generation options, and its state (queued, sending,
  sent, skipped, failed) plus any fields that had no box on the template. Kept so retries are
  recognised.
- **Audit Entry** (existing): records each generation and each email request.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Staff can get a voucher for a reservation in under 10 seconds from opening it,
  with no retyping.
- **SC-002**: 100% of fields placed on a template appear within their box on the generated
  PDF, for any reservation, including one with 10 guests and 10 room lines.
- **SC-003**: A voucher for an Arabic-speaking guest is fully Arabic, right to left, with
  correctly joined letters, as signed off by the project owner (a native Arabic reader) on
  the quickstart reservations.
- **SC-004**: Repeating an email request with the same key never produces a second email,
  across at least 3 retries in tests.
- **SC-005**: No stored reservation or guest value changes after any voucher generation,
  preview or email, whatever overrides were sent.
- **SC-006**: An admin can upload a template and place the core fields (guest, dates, room
  lines, reference) in under 15 minutes using the frontend editor.
- **SC-007**: Every voucher generation and email appears in the audit log with its actor and
  options; previews do not.
- **SC-008**: A user of one hotel can never obtain another hotel's template, template image,
  preview or voucher; each attempt is answered as not found.
- **SC-009**: A voucher is produced within 5 seconds for a template image at the maximum
  allowed size on the production host.

## Assumptions

- The voucher is for staff use; guests do not request it themselves through the Concierge in
  this feature.
- Template names and labels are entered by the hotel; the built-in layout ships its own
  English and Arabic labels. Labels printed by the system in a template (such as "+N more")
  follow the voucher language.
- "Nights" is the number of nights between arrival and departure dates.
- The issue date printed on the voucher is the hotel's local date at generation.
- Dates are formatted for the voucher language; Arabic vouchers use Western digits for
  dates, references and numbers, as is common in Gulf hotel documents.
- Room-line labels use the room-type name; prices per line are never printed.
- The total is the reservation's stored total value and currency; when there is none, "show
  total" prints nothing.
- The 24-hour window for email request keys is long enough for client retries and short
  enough that a deliberate resend the next day is a new email.
- The email's sender and delivery use the system's existing mail setup.
- The frontend layout editor works in fractions of the displayed image, so the backend never
  needs screen sizes.
- Existing audit logging (actor kinds, event log) is reused; no new audit store is created.
- The PDF engine is chosen in the plan; it must shape Arabic correctly and fit the 2 GB
  production host without a headless browser.
- Template management uses the same list behaviour (filter, search, sort, pagination) as the
  other index endpoints.

## Dependencies

- `specs/002-reservation-rooms` (room-type lines), `specs/014-reservation-guests-party`
  (primary and other guests, children's ages), `specs/015-reservation-lifecycle` (statuses
  including `no_show`).
- Existing mail setup and queue lanes; existing event log.
- Constitution v2.2.0 (Guest Identity and WhatsApp), amended 2026-10-27, allows
  staff-initiated document sends such as the voucher by email.
- Frontend slice in `ecosystem-frontend`: template list and upload, drag-and-drop layout
  editor with preview, "Voucher" action on the reservation page (template picker, show-total
  toggle, overrides, staff note, download, email).
