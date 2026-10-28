# Tasks: Reservation Voucher with Uploadable Templates

**Input**: Design documents from `specs/018-reservation-voucher/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/voucher-api.md](contracts/voucher-api.md), [quickstart.md](quickstart.md)

**Tests**: Required by the constitution (Principle VIII) and CLAUDE.md: permission and tenant-isolation tests for every new permission and tenant-scoped surface. Write each story's tests first and see them fail before implementing.

**Organization**: Tasks are grouped by user story after a shared foundation, so each story can be built and tested on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: the user story the task belongs to (US1–US6, see spec.md)

---

## Phase 1: Setup

**Purpose**: Package, fonts, config and shared test fixtures. No behaviour change.

- [X] T001 Run `composer require mpdf/mpdf:^8.2` and commit `composer.json` and `composer.lock`. Confirm `php -m` lists `gd`, `mbstring` and `zlib` (research R1).
- [X] T002 [P] Add `resources/fonts/voucher/` with `NotoSans-Regular.ttf`, `NotoSans-Bold.ttf`, `NotoNaskhArabic-Regular.ttf`, `NotoNaskhArabic-Bold.ttf`, plus `OFL.txt` (the licence) and a `README.md` naming the source and version (research R2).
  - *Done differently:* mPDF can't read Noto Naskh Arabic's GPOS tables, so only Noto Sans is bundled and Arabic uses mPDF's own XB Riyaz (research R2, revised). The same change applies to T017, T018 and T031.
- [X] T003 [P] Create `config/vouchers.php` with these keys:
  - `disk` → `env('VOUCHERS_DISK', 'local')`
  - `max_upload_kb` → `5120`
  - `min_short_side_px` → `1000`
  - `max_long_side_px` → `6000`
  - `max_aspect_ratio` → `3`
  - `render_long_side_px` → `3508`
  - `render_jpeg_quality` → `90`
  - `page_long_side_mm` → `297`
  - `min_font_pt` → `6`
  - `max_layout_boxes` → `60`
  - `email_claim_stale_minutes` → `5`
  - `email_daily_cap` → `5`
  - `mpdf_temp_dir` → `storage_path('app/mpdf-tmp')`

  Add `VOUCHERS_DISK=local` to `.env.example`. Make sure `storage/app/mpdf-tmp` is ignored by git; `storage/app/.gitignore` may already cover it.
- [X] T004 [P] Add shared voucher fixtures to `tests/Pest.php`:
  - `vcReservation(Hotel $hotel, array $attributes = [], int $rooms = 2)`: a confirmed reservation with room-type lines, built with `createReservationWithRooms()`. It has a primary guest (with email and `preferred_language`), one other guest, `children = 1` and `children_ages = [7]`, `special_requests`, and `reservation_value`/`currency`.
  - `vcImage(int $w = 2480, int $h = 3508, string $type = 'png')`: an `UploadedFile` made with GD (`UploadedFile::fake()->image()` does not produce WebP, so write the file with `imagewebp`/`imagepng`/`imagejpeg` to a temp path).
  - `vcEmployee(Hotel $hotel, array $permissions)`: reuses `fdEmployee()`.
  - `vcPost($test, User $user, string $uri, array $payload = [])` and `vcGet(...)`: API headers plus `actingAs`, like `fdPost`/`fdGet`.
  - `vcPdfText(string $pdfBinary): string`: text extracted with `Smalot\PdfParser\Parser`.

---

## Phase 2: Foundational

**Purpose**: Enums, permissions, the template table and model, the policy and the language files that every story needs.

**⚠️ CRITICAL**: Finish this phase before any user story.

- [X] T005 [P] Create `app/Enums/VoucherLanguage.php`:
  - backed string enum with `EN = 'en'` and `AR = 'ar'`;
  - `fromGuestLanguage(?string $code): self` returns `AR` when `$code` lowercased starts with `ar`, and `EN` otherwise (research R7);
  - `isRtl(): bool`.
- [X] T006 [P] Create `app/Enums/VoucherField.php` with the catalogue from data-model.md § Field catalogue:
  - **Cases**: `hotel.name`, `hotel.address`, `hotel.phone`, `hotel.email`, `reservation.reference`, `reservation.status`, `guest.primary_name`, `guests.others`, `guests.all`, `stay.arrival_date`, `stay.departure_date`, `stay.nights`, `rooms.lines`, `party.adults`, `party.children`, `party.children_ages`, `reservation.special_requests`, `reservation.total`, `voucher.staff_note`, `voucher.issue_date`, `free_text`.
  - `kind(): string` returns `list` for `guests.others`, `guests.all` and `rooms.lines`, and `text` otherwise.
  - `label(VoucherLanguage $l): string` reads `lang/{en,ar}/voucher.php` `fields.*`.
- [X] T007 [P] Create `app/Enums/VoucherEmailStatus.php` with `QUEUED='queued'`, `SENDING='sending'`, `SENT='sent'`, `SKIPPED='skipped'`, `FAILED='failed'`, and `isTerminal()` for sent, skipped and failed.
- [X] T008 [P] Add six cases to `app/Enums/Permission.php`, following the neighbouring cases for labels and descriptions:
  - next to the `RESERVATIONS_*` cases: `RESERVATIONS_VOUCHER = 'reservations.voucher'` and `RESERVATIONS_VOUCHER_EMAIL = 'reservations.voucher_email'`;
  - a new group: `VOUCHER_TEMPLATES_VIEW|CREATE|UPDATE|DELETE = 'voucher_templates.view|create|update|delete'`.

  Do **not** add any of them to `employeeDefaults()` (FR-032).
- [X] T009 [P] Create `database/migrations/2026_10_27_000001_create_voucher_templates_table.php` with the columns in data-model.md § voucher_templates:
  - `id` uuid PK;
  - `hotel_id` uuid FK → `hotels`, cascade on delete;
  - `name` string(120); `language` string(2); `is_default` boolean default false;
  - `disk`, `original_path`, `render_path`, `mime_type` strings;
  - `size_bytes`, `width_px`, `height_px` integers;
  - `layout` jsonb default `'[]'`;
  - `created_by` uuid FK → `users`, nullable, null on delete;
  - timestamps and softDeletes.

  Add two partial unique indexes with raw `DB::statement`:
  - `(hotel_id, lower(name)) WHERE deleted_at IS NULL`;
  - `(hotel_id, language) WHERE is_default AND deleted_at IS NULL`.

  Add an index on `hotel_id`.
- [X] T010 Create `app/Models/VoucherTemplate.php`:
  - traits: `HasUuids`, `SoftDeletes`, `BelongsToHotel`, `Filterable`; `$keyType = 'string'`, `$incrementing = false`;
  - `$fillable = ['name','language','is_default','layout']`;
  - casts: `language` → `VoucherLanguage`, `is_default` → bool, `layout` → array, `size_bytes`/`width_px`/`height_px` → integer;
  - filterable on `language` and `is_default`; searchable on `name`; sortable on `name` and `created_at`;
  - `creator()` belongsTo `User` (`created_by`);
  - a scope `defaultFor(VoucherLanguage)`.

  Depends on T005 and T009.
- [X] T011 [P] Create `lang/en/voucher.php` and `lang/ar/voucher.php`:
  - `fields.*`: a label for every `VoucherField`;
  - `labels.*` for the built-in layout: Reservation, Status, Guest, Other guests, Arrival, Departure, Nights, Rooms, Adults, Children, Children's ages, Special requests, Total, Note, Issued;
  - `statuses.*` for each `ReservationStatus`;
  - `more` → `'+:count more'` / `'+:count أخرى'`;
  - `mail.subject` → `'Your reservation voucher – :reference'`, with an Arabic equivalent;
  - `mail.body`;
  - `errors.ineligible`.
- [X] T012 Create `app/Policies/VoucherTemplatePolicy.php` using `ChecksPermissions`:
  - `viewAny`/`view` → `VOUCHER_TEMPLATES_VIEW`;
  - `create` → `VOUCHER_TEMPLATES_CREATE`;
  - `update` → `VOUCHER_TEMPLATES_UPDATE`;
  - `delete` → `VOUCHER_TEMPLATES_DELETE`.

  Each goes through `$this->allows($user, Permission::X, $record)`. Register it the way the other policies are registered (check `AppServiceProvider` / auto-discovery). Add `voucher(User, Reservation)` → `RESERVATIONS_VOUCHER` and `voucherEmail(User, Reservation)` → `RESERVATIONS_VOUCHER_EMAIL` to `app/Policies/ReservationPolicy.php`. Depends on T008 and T010.

**Checkpoint**: migrations run (`php artisan migrate`), and `GET /api/permissions` lists the six new cases.

---

## Phase 3: User Story 1 - Download a voucher for a reservation (Priority: P1) 🎯 MVP

**Goal**: `POST /api/reservation/{id}/voucher` returns a one-page PDF in the built-in layout, in English or Arabic, with every FR-002 field and the total hidden by default. It refuses cancelled and no-show reservations, and is audited.

**Independent Test**: For a hotel with no templates, generate a voucher for `vcReservation()`. The PDF text contains every field and no total; with `show_total` it contains the total; an Arabic guest gets Arabic labels; a cancelled reservation gets 409.

### Tests for User Story 1

- [X] T013 [P] [US1] Write `tests/Feature/ReservationVoucherTest.php` (`uses(RefreshDatabase::class)`, API key in `beforeEach`). It asserts:
  - (a) 200, `Content-Type: application/pdf`, a single page; `vcPdfText()` contains the hotel name, reference, status label, primary and other guest names, both dates, the nights count, each room-type name, adults, children, the age `7` and the special requests; no `reservation_value`;
  - (b) `show_total: true` makes the total and currency appear;
  - (c) a guest with `preferred_language = 'ar'` gets Arabic labels from `lang/ar/voucher.php` in the text. Assert on the rendered HTML through a `VoucherRenderer::html()` seam if pdfparser can't extract Arabic reliably;
  - (d) a guest with `preferred_language = 'fr'` or null gets English;
  - (e) `cancelled` and `no_show` give 409 and write no `reservation.voucher_generated` event;
  - (f) `pending` gives 200, and the text contains the pending label;
  - (g) one `event_logs` row with `event_type = 'reservation.voucher_generated'`, the actor, and `context.language`/`context.show_total`/`context.voucher_template_id = null`;
  - (h) `?download=1` sets `Content-Disposition: attachment`;
  - (i) both an unassigned line and an assigned line print only the room-type name; the assigned room's number never appears in the PDF text;
  - (j) the reservation row is unchanged after generation.
- [X] T014 [P] [US1] Add US1 isolation and permission cases to `tests/Feature/ReservationVoucherTest.php`:
  - hotel B admin → 404 on hotel A's reservation voucher;
  - an employee with `reservations.voucher` → 200, and without it → 403;
  - an employee with no role → 403.

### Implementation for User Story 1

- [X] T015 [P] [US1] Create `app/Services/Vouchers/VoucherData.php`. It's a `final readonly` value object holding:
  - `VoucherLanguage $language`;
  - `array<string, string|null> $text`, keyed by text `VoucherField` values;
  - `array<string, list<string>> $lists`, keyed by list fields;
  - `bool $showTotal`, `?string $staffNote`, `string $reference`, `string $statusLabel`.

  Add `value(VoucherField $f): string|array|null`.
- [X] T016 [US1] Create `app/Services/Vouchers/VoucherDataBuilder.php` with `fromReservation(Reservation $r, VoucherLanguage $lang, bool $showTotal, ?string $staffNote, array $overrides = []): VoucherData`.
  - **Loading**: eager-load `guest`, `guests`, `reservationRooms.roomType` and `hotel` (not `reservationRooms.room`).
  - **Values**: fill every field per data-model.md § Field catalogue.
  - **Reference**: `reservation_id`, falling back to the UUID.
  - **Room lines**: "{type name}" only; never a room number.
  - **Nights**: departure minus arrival, in days.
  - **Dates**: Carbon `locale($lang->value)->translatedFormat('j F Y')` with Western digits. Convert any Eastern Arabic digits back if Carbon's `ar` locale produces them.
  - **Issue date**: `now($hotel->timezone)`.
  - **Total**: only when `$showTotal` and `reservation_value` is not null.

  The `$overrides` parameter is accepted but applied in US4 (T040). Depends on T015.
- [X] T017 [US1] Create `resources/views/vouchers/default.blade.php`: an A4 portrait built-in layout (research R8).
  - **Root**: `<html dir="rtl|ltr" lang>`.
  - **Header**: hotel name in large bold, then the address, phone and email line.
  - **Body**: a title, then a two-column label/value table over FR-002.
  - **Lists**: rendered as `<br>`-separated rows.
  - **Empty values**: rows with an empty value are omitted.
  - **Footer**: the staff note when present, then "Issued {date}".
  - **Fonts**: `font-family` is `notonaskharabic` for Arabic and `notosans` for English.
- [X] T018 [US1] Create `app/Services/Vouchers/VoucherRenderer.php`, which wraps mPDF.
  - **Constructor**: builds `fontDir` from mPDF defaults plus `resource_path('fonts/voucher')` and `fontdata` for `notosans` (R, B) and `notonaskharabic` (R, B, `useOTL => 0xFF`, `useKashida => 75`). It sets `tempDir` from `config('vouchers.mpdf_temp_dir')`, creating it if missing, and turns off remote fetching.
  - **`renderDefault(VoucherData $d): string`**: A4 with standard margins, built from `default.blade.php`; `SetDirectionality('rtl')` for Arabic; `Output('', 'S')`.
  - **`html()` seam**: a public `html()` method used by tests.

  Depends on T002, T011 and T017.
- [X] T019 [US1] Create `app/Services/Vouchers/VoucherTemplateResolver.php` with `resolve(Reservation $r, ?string $templateId): array{0: ?VoucherTemplate, 1: VoucherLanguage}`, per research R7:
  - an explicit id is found through the tenant-scoped `VoucherTemplate` query (not trashed), else `ModelNotFoundException` → 404, and its language is used;
  - otherwise the language comes from `VoucherLanguage::fromGuestLanguage($r->guest?->preferred_language)`, the template is the `defaultFor()` that language, or null.

  US1 only exercises the null-template path.
- [X] T020 [P] [US1] Create `app/Http/Requests/Vouchers/VoucherOptionsRequest.php` with these rules:
  - `template_id` → `nullable|uuid`;
  - `show_total` → `sometimes|boolean`;
  - `staff_note` → `nullable|string|max:500`;
  - `overrides` → `nullable|array`. The keys are checked in US4 (T039); until then, reject any non-empty `overrides` with 422 so no unvalidated key is silently ignored.
- [X] T021 [US1] Create `app/Http/Controllers/ReservationVoucherController.php` with `generate(VoucherOptionsRequest $request, Reservation $reservation)`:
  1. `$this->authorize('voucher', $reservation)`.
  2. Return 409 via `apiResponse(__('voucher.errors.ineligible'), 409, null)` when the status is `cancelled` or `no_show` (FR-004).
  3. Resolve the template and language (T019), build the data (T016), and render (`renderDefault` when there's no template).
  4. Call `EventLogger::record($reservation, 'voucher_generated', changes: array_filter(['overrides' => …, 'staff_note' => …]) ?: null, context: ['voucher_template_id' => …, 'language' => …, 'show_total' => …])` (research R11).
  5. Return `response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => (download ? 'attachment' : 'inline').'; filename="voucher-{reference}.pdf"'])`.

  Keep the controller thin. Put the eligibility check in `app/Services/Vouchers/ReservationVoucherEligibility.php`: `isEligible(Reservation): bool` is true for `pending`, `confirmed`, `checked_in` and `checked_out`, and `reasonFor(Reservation): ?string` returns `reservation_cancelled` or `reservation_no_show`. The email job (T047) reuses it.
- [X] T022 [US1] Register `Route::post('/reservation/{reservation}/voucher', [ReservationVoucherController::class, 'generate'])` in `routes/api.php`, next to the other `/reservation/{reservation}/…` routes and **before** `Route::resource('/reservation', …)`.

**Checkpoint**: T013 and T014 pass. US1 is shippable on its own with the built-in layout.

---

## Phase 4: User Story 2 - Upload a template and place the fields (Priority: P1)

**Goal**: Admins upload a template image, save a layout of boxes, and mark it default. English or Arabic vouchers then print on that artwork with each value inside its box.

**Independent Test**: Upload a valid image, save a 3-box layout, set it default for English, and generate a voucher for an English guest. The PDF page has the image's proportions, and each value is inside its box.

### Tests for User Story 2

- [X] T023 [P] [US2] Write `tests/Feature/VoucherTemplateControllerTest.php` for upload. It asserts:
  - (a) PNG, JPG and WebP at 2480×3508 → 201, `layout: []`, both files exist on `Storage::fake(config('vouchers.disk'))`, `render_path` ends in `render.jpg`, `width_px`/`height_px`/`mime_type`/`size_bytes` are stored, and `hotel_id` is stamped;
  - (b) the 422 rules each fail on their own: a 900 px short side, a 7000 px long side, a 1000×3500 ratio, a 6 MB file, a `.png` that is really text, and a GIF. Each response names its rule, and no file or row remains;
  - (c) a duplicate name in the same hotel → 422, while the same name in another hotel is fine;
  - (d) a transparent PNG gives a render copy with a white background (sample a corner pixel with GD).
- [X] T024 [P] [US2] Add layout, default and fields cases to `tests/Feature/VoucherTemplateControllerTest.php`:
  - (a) `PUT …/layout` with valid boxes → 200, stored as sent;
  - (b) each invalid box is rejected with 422 keyed `layout.{i}.{key}` and the message contains the box `id`, while the previous layout is unchanged. The invalid cases are: `x + w > 1`, `h = 0`, an unknown `field`, `color = 'red'`, `font_size = 80`, `align = 'left'`, `free_text` without `text`, `text` on a non-free-text box, a duplicate `id`, and 61 boxes;
  - (c) setting template B default for `en` clears A's default, and a direct second default insert violates the partial index;
  - (d) `POST …/image` with a new 1240×1754 image keeps `layout` byte-identical;
  - (e) `GET /api/voucher-templates/fields` lists every `VoucherField` with its `kind` and both labels.
- [X] T025 [P] [US2] Write `tests/Feature/VoucherRendererTest.php`. It asserts:
  - (a) **Page size**: a 2480×3508 template → 210×297 mm, and a 3508×2480 one → 297×210 mm. Read the size from mPDF or the PDF MediaBox.
  - (b) **Shrink**: `VoucherTextFitter` shrinks a long single value down to `min_font_pt` and then truncates with "…".
  - (c) **Truncate**: `truncate` keeps the font size and cuts with "…".
  - (d) **Long list**: a 10-guest list in a small box ends with the localized "+N more", and the counts add up.
  - (e) **Fitting list**: a list that fits is unchanged.
  - (f) **Alignment**: `align: start` maps to `text-align: right` on an Arabic template and `left` on English; `end` is the reverse.
  - (g) **Box style**: each rendered box element has `position:absolute`, mm coordinates equal to fraction × page size, and `overflow:hidden`.
  - (h) **Generation**: `POST /reservation/{id}/voucher` uses the hotel's English default template for an English guest, and the built-in layout when the default is deleted.
  - (i) **Performance and memory** (SC-009, plan Performance Goals): uploading a 6000×2000 PNG through `VoucherTemplateService::create()` and rendering a voucher on its render copy, with a 10-guest, 10-line reservation, each take under 5 s wall time (`hrtime`). `memory_get_peak_usage(true)` measured around each, after `memory_reset_peak_usage()`, stays under 256 MB. Mark the test `->group('slow')` so it can be filtered; the staging timing in T060 remains the sign-off.

### Implementation for User Story 2

- [X] T026 [P] [US2] Create `app/Http/Requests/Vouchers/VoucherLayoutRequest.php` with the shared layout rules from data-model.md § Layout field box:
  - `layout` → `present|array|max:{max_layout_boxes}`;
  - `layout.*.id` → `required|string|max:40|distinct`;
  - `layout.*.field` → `required|Rule::enum(VoucherField::class)`;
  - `layout.*.x|y` → `required|numeric|min:0|lt:1`;
  - `layout.*.w|h` → `required|numeric|gt:0|lte:1`;
  - `layout.*.font_size` → `required|numeric|between:6,72`;
  - `layout.*.color` → `required|regex:/^#[0-9A-Fa-f]{6}$/`;
  - `layout.*.bold` → `sometimes|boolean`;
  - `layout.*.align` → `sometimes|in:start,center,end`;
  - `layout.*.overflow` → `sometimes|in:shrink,truncate`;
  - `layout.*.text` → `required_if:layout.*.field,free_text|prohibited_unless:layout.*.field,free_text|nullable|string|max:500`.

  In `after()`, reject any box with `x + w > 1` or `y + h > 1`, and append `(box {id})` to every per-box error message. Expose a static `rules()` helper so the preview request (T035) reuses it. Add `normalized(): array`, which fills the defaults (`bold=false`, `align='start'`, `overflow='shrink'`, `text=null`).
- [X] T027 [P] [US2] Create `app/Http/Requests/Vouchers/StoreVoucherTemplateRequest.php`:
  - **Rules**: `name` → `required|string|max:120` plus a unique-per-hotel check (case-insensitive, ignoring soft-deleted rows); `language` → `required|Rule::enum(VoucherLanguage::class)`; `is_default` → `sometimes|boolean`; `image` → `required|file|mimetypes:image/png,image/jpeg,image/webp|max:5120`.
  - **`after()`**: run `getimagesize()` on the real file. Fail `image.type` if it's unreadable or its type doesn't match the mimetype. Fail `image.dimensions_short_side` if `min(w,h) < 1000`, `image.dimensions_long_side` if `max(w,h) > 6000`, and `image.ratio` if `max/min > 3`. Error keys and messages follow contracts/voucher-api.md.
  - **Also create** `VoucherTemplateImageRequest.php` (image only, the same image rules through a shared trait in `app/Http/Requests/Vouchers/Concerns/ValidatesVoucherImage.php`), and `UpdateVoucherTemplateRequest.php` (`name` sometimes, unique ignoring itself; `language` sometimes; `is_default` sometimes).
  - Call `unsetAttributes()` to strip `hotel_id`, as the other requests do.
- [X] T028 [US2] Create `app/Services/Vouchers/VoucherTemplateService.php`.
  - **`create(Hotel $hotel, array $data, UploadedFile $image, ?User $by): VoucherTemplate`**: in `DB::transaction`, create the row and write `original.{ext}` with the extension from the detected type, never the client name. Write `render.jpg` through `normalize()`, then set the file columns. If anything throws, delete the files written so far and rethrow; nothing is stored on reject. If `is_default`, call `setDefault`.
  - **`normalize(string $sourcePath, string $mime): string`** (JPEG bytes): load with `imagecreatefrompng|jpeg|webp`, create a truecolor canvas filled white, `imagecopy` the source onto it (this flattens alpha), downscale with `imagescale` so the long side is ≤ `render_long_side_px`, then `imagejpeg` at quality `render_jpeg_quality`. Free GD images in `finally`.
  - **`replaceImage(VoucherTemplate $t, UploadedFile $image)`**: write the new files under new names, update the columns and keep `layout` (FR-014). Delete the old files with `DB::afterCommit`.
  - **`setDefault(VoucherTemplate $t)`**: in one transaction, `lockForUpdate` the hotel's templates of that language, unset any other default, and set this one (FR-013).
  - **`update(VoucherTemplate $t, array $data)`**: handles a language change on a default per contracts (`PUT`).
  - **`saveLayout(VoucherTemplate $t, array $layout)`**.

  Depends on T010 and T003.
- [X] T029 [P] [US2] Create `app/Http/Resources/VoucherTemplateResource.php` with the shape in contracts/voucher-api.md: `id`, `name`, `language`, `is_default`, `image{mime_type,width_px,height_px,size_bytes,url}`, `layout`, `created_at` and `updated_at`. Paths and disk are never exposed.
- [X] T030 [US2] Create `app/Services/Vouchers/VoucherTextFitter.php`, per research R5.
  - **`fitText(Mpdf $m, string $font, bool $bold, float $sizePt, float $wMm, float $hMm, string $text, string $overflow): array{size: float, lines: list<string>}`**: wrap by measuring words with `GetStringWidth`. Line height is `size × 1.25 × 0.3528` mm. With `shrink`, step down 0.5 pt until the lines fit or the size reaches `min_font_pt`, then truncate the last fitting line with "…". With `truncate`, keep the size and truncate.
  - **`fitList(..., list<string> $items, VoucherLanguage $lang)`**: one item per line, shrinking the same way. At the minimum size, keep `k` items plus a final `__('voucher.more', ['count' => n-k], $lang->value)` line, choosing `k` so everything fits.

  It must be deterministic and work without rendering. Depends on T018 (shared mPDF font setup: extract a `VoucherRenderer::newMpdf(array $format, bool $rtl)` factory and use it here).
- [X] T031 [US2] Create `resources/views/vouchers/template.blade.php`.
  - **Background**: `@page { size: {w}mm {h}mm; margin: 0; background-image: url("{local render path}"); background-image-resize: 6; }`.
  - **Boxes**: one `<div>` per box with `position:absolute; left:{x·W}mm; top:{y·H}mm; width:{w·W}mm; height:{h·H}mm; overflow:hidden; font-size:{fitted}pt; color; font-weight; text-align:{mapped}; direction`, holding the fitted lines joined with `<br>` and HTML-escaped.
  - **Fonts**: as T017.
- [X] T032 [US2] Extend `app/Services/Vouchers/VoucherRenderer.php` with `renderTemplate(VoucherData $d, VoucherTemplate $t, ?array $layoutOverride = null): VoucherRenderResult`.
  - **Page size**: `pageSize(int $wPx, int $hPx): array{0: float, 1: float}` fixes the long side at `page_long_side_mm`, with `format = [w, h]` and margins 0.
  - **Render copy**: read from `Storage::disk($t->disk)->path($t->render_path)`. For a non-local disk, copy it to a temp file first and delete it in `finally`.
  - **Boxes**: map each box's alignment (`start`/`end` mirrored on RTL, R6) and fit it with `VoucherTextFitter`. Use the box's own `text` for `free_text` and `VoucherData::value()` for the others.
  - **Result**: `VoucherRenderResult` (readonly: `string $pdf`, `list<string> $unplaced`). `unplaced` lists `voucher.staff_note`, and `reservation.total` when shown, when no box uses that field.
  - **Signature change**: `renderDefault` now also returns `VoucherRenderResult` with empty `unplaced`; update T021.
  - **Logo**: no remote fetch. The `branding` logo is ignored unless it resolves to a local path.
- [X] T033 [US2] Create `app/Http/Controllers/VoucherTemplateController.php`.
  - **Actions**: `store`, `update`, `layout` (`PUT …/layout` through `VoucherLayoutRequest`), `replaceImage` and `fields`.
  - **Authorization**: `$this->authorize()` with the policy abilities (`fields` → `viewAny`).
  - **Hotel**: `resolveHotel()` decides the hotel on store.
  - **Responses**: `apiResponse()` with `VoucherTemplateResource`; 201 on store.

  Register the routes in `routes/api.php`, putting the literal `fields` route before `{voucherTemplate}`:
  - `GET /voucher-templates/fields`;
  - `POST /voucher-templates`;
  - `PUT /voucher-templates/{voucherTemplate}`;
  - `PUT /voucher-templates/{voucherTemplate}/layout`;
  - `POST /voucher-templates/{voucherTemplate}/image`.

  Route-model binding is tenant-scoped, so another hotel's id gives 404.
- [X] T034 [US2] Wire templates into generation: in `ReservationVoucherController::generate` (T021), call `renderTemplate` when the resolver returns a template, and set the event context `voucher_template_id`.

**Checkpoint**: T023, T024 and T025 pass. US1 tests still pass.

---

## Phase 5: User Story 3 - Preview a template (Priority: P2)

**Goal**: `POST /voucher-templates/{id}/preview` renders a template with sample data or a real reservation, optionally with an unsaved layout. It never stores, sends or audits anything.

**Independent Test**: Preview with sample data and with a reservation. Both return a PDF; the event log and mail are untouched; the saved layout is unchanged when an unsaved layout is sent.

- [X] T035 [P] [US3] Add preview cases to `tests/Feature/VoucherTemplateControllerTest.php`:
  - (a) sample data → 200 PDF in the template's language, and the text contains the sample primary name;
  - (b) `reservation_id` of the same hotel → its values are present;
  - (c) an unsaved `layout` in the body is used, and the stored `layout` is unchanged;
  - (d) a hotel B reservation id → 404;
  - (e) `event_logs` count is unchanged and `Mail::assertNothingQueued()`;
  - (f) `voucher_templates.view` alone with `reservation_id` → 403, and with `reservations.voucher` too → 200;
  - (g) without `voucher_templates.view` → 403.
- [X] T036 [US3] Add `VoucherDataBuilder::sample(VoucherLanguage $lang): VoucherData` with fixed, plausible values per language:
  - a hotel block;
  - reference `SAMPLE-0001`;
  - one long primary name (≥ 40 chars) and 3 others;
  - 3 room lines (room-type names only);
  - 2 adults, 2 children aged 4 and 9;
  - dates 3 nights apart;
  - a two-sentence special request.

  Arabic sample names are in Arabic script, with one Latin name to exercise mixed runs.
- [X] T037 [US3] Create `app/Http/Requests/Vouchers/PreviewVoucherTemplateRequest.php`: `reservation_id` → `nullable|uuid`; `layout` → `sometimes|array` plus `VoucherLayoutRequest::rules()` and its `after()` box checks; plus the `VoucherOptionsRequest` option rules. Add `preview()` to `VoucherTemplateController`:
  1. `authorize('view', $template)`.
  2. When `reservation_id` is set, find it through the tenant-scoped `Reservation` query (404 otherwise), then `authorize('voucher', $reservation)` and build from it in the template's language. Otherwise use `sample()`.
  3. Call `renderTemplate` with the unsaved layout.
  4. Return the PDF inline. Write no event and send nothing.

  Register `POST /voucher-templates/{voucherTemplate}/preview`.

**Checkpoint**: T035 passes.

---

## Phase 6: User Story 4 - Choose a template and override displayed values (Priority: P2)

**Goal**: Staff pick a named template and override allowed display values plus a staff note. The PDF reflects them, the reservation never changes, and the audit lists them.

**Independent Test**: Generate with `template_id`, a `primary_guest_name` override and a staff note. The PDF shows them, `GET /reservation/{id}` is unchanged, the event's `changes.overrides` lists them, and `X-Voucher-Unplaced` names the note when there is no box for it.

- [X] T038 [P] [US4] Add US4 cases to `tests/Feature/ReservationVoucherTest.php`:
  - (a) `template_id` of an Arabic template for an English guest → the Arabic template and Arabic language are used;
  - (b) a hotel B `template_id` → 404, and a soft-deleted template → 404;
  - (c) each allowed override shows in the PDF text: `primary_guest_name`, `other_guest_names`, `arrival_date`, `departure_date` (nights recomputed), `room_lines`, `adults`, `children`, `children_ages`, `special_requests`, `hotel_name`, `hotel_address`, `hotel_phone`, `hotel_email`;
  - (d) a disallowed key (`reference`, `status`, `foo`) → 422 naming the key;
  - (e) `departure_date` not after the displayed arrival → 422;
  - (f) reservation, guests and reservation rooms are byte-identical before and after (SC-005);
  - (g) a template without a `voucher.staff_note` box plus a sent note → 200 and the header `X-Voucher-Unplaced: voucher.staff_note`;
  - (h) the event `changes.overrides` equals the validated overrides, and `changes.staff_note` is set.
- [X] T039 [US4] Extend `app/Http/Requests/Vouchers/VoucherOptionsRequest.php` and replace the T020 blanket reject. Allow exactly these keys and add a rule for each (data-model.md § Request-only shapes):
  - `primary_guest_name` → string ≤ 200;
  - `other_guest_names` → array ≤ 50 of string ≤ 200;
  - `arrival_date`, `departure_date` → date;
  - `room_lines` → array ≤ 50 of string ≤ 200;
  - `adults`, `children` → integer 0–99;
  - `children_ages` → array of integer 0–17;
  - `special_requests` → string ≤ 2000;
  - `hotel_name`, `hotel_address`, `hotel_phone`, `hotel_email` → string ≤ 255.

  In `after()`, reject any other key in `overrides` with `overrides.{key}` → "This field cannot be overridden." Check that the displayed departure is after the displayed arrival, using the stored reservation dates when one side isn't overridden (the reservation comes from the route).
- [X] T040 [US4] Apply overrides in `VoucherDataBuilder::fromReservation()`. Each override replaces its displayed value; `stay.nights` is recomputed from the displayed dates; date overrides are formatted like stored dates. The builder never writes to models; assert in code review that no `save()` or `update()` is reachable.
- [X] T041 [US4] In `ReservationVoucherController::generate`, set the `X-Voucher-Unplaced` header (comma-separated) from `VoucherRenderResult::$unplaced` when it isn't empty, and record the validated overrides and note in the event `changes`.

**Checkpoint**: T038 passes.

---

## Phase 7: User Story 5 - Email the voucher to the guest (Priority: P2)

**Goal**: `POST /reservation/{id}/voucher/email` queues one email per `request_key`. The job claims the request, renders, attaches and sends exactly once; it skips the send if the reservation became ineligible, and records the outcome.

**Independent Test**: Send the same `request_key` 3 times: one 202 and two 200s with the same id, one job, one mail with a PDF attachment, and the request ends `sent`.

### Tests for User Story 5

- [X] T042 [P] [US5] Write `tests/Feature/VoucherEmailTest.php`. It asserts:
  - (a) the first call → 202, `status: queued`, and `Queue::assertPushedOn('default', SendReservationVoucherEmailJob::class)` once;
  - (b) the same `request_key` twice more → 200 with the same id, still one job (SC-004);
  - (c) the same key on a different reservation → a new request;
  - (d) `email` in the body overrides the recipient, and the guest's email column is unchanged;
  - (e) no guest email and no `email` → 422;
  - (f) cancelled or no_show → 409 and no row;
  - (g) running the job with `Mail::fake()` sends one `ReservationVoucherMail` to the recipient with one `application/pdf` attachment named `voucher-{reference}.pdf` and a subject in the voucher language; the row becomes `sent` with `sent_at`; and an `event_logs` row `voucher_email_request.sent` is written;
  - (h) running the job a second time on a `sent` row sends nothing;
  - (i) cancelling the reservation before the job runs → `skipped` with `status_reason = 'reservation_cancelled'`, and no mail;
  - (j) a `sending` row claimed less than 5 minutes ago is left alone, and one older than 5 minutes is taken over;
  - (k) a mailer exception on the final try → `failed()` sets `failed` with the exception class;
  - (l) the job reads the snapshot: changing the guest's `preferred_language` after queuing doesn't change the language;
  - (m) a `reservation.voucher_email_requested` event with the recipient and options;
  - (n) `GET /reservation/{id}/voucher/emails` lists the requests newest first;
  - (o) isolation: hotel B → 404 on both routes;
  - (p) permissions: `reservations.voucher_email` → allowed, and without it → 403;
  - (r) idempotent delivery (research R10): with `config(['mail.default' => 'resend'])` and a mocked Resend client bound in the container, the job calls `emails->send()` once with `['idempotency_key' => 'voucher-email:{request id}']`, one base64 PDF attachment and the localized subject. Simulate a worker killed after the send (the row is left `sending` with an old `claimed_at`); the takeover run calls `emails->send()` with the **same** key, so Resend drops the duplicate. With the default test mailer, `Mail::fake()` sees exactly one `ReservationVoucherMail`;
  - (s) `unplaced` is stored on the request when the template has no box for a sent staff note;
  - (q) daily cap (FR-027a): 5 requests with new keys → 202 each, and a sixth → 429 with no row and no job. Repeating the first key still → 200. Marking one earlier row `failed` makes room for one more (202). After `travel(25)->hours()`, new requests are accepted again.

### Implementation for User Story 5

- [X] T043 [P] [US5] Create `database/migrations/2026_10_27_000002_create_voucher_email_requests_table.php` with the columns in data-model.md § voucher_email_requests:
  - `id` uuid PK;
  - `hotel_id` uuid FK → `hotels`, cascade;
  - `reservation_id` uuid FK → `reservations`, cascade;
  - `request_key` string(100);
  - `recipient` string;
  - `voucher_template_id` uuid FK → `voucher_templates`, nullable, null on delete;
  - `language` string(2); `show_total` boolean; `overrides` jsonb nullable; `staff_note` text nullable;
  - `status` string default `'queued'`; `status_reason` string nullable; `unplaced` jsonb nullable;
  - `claimed_at`, `sent_at` timestampTz nullable;
  - `requested_by` uuid FK → `users`, nullable, null on delete;
  - timestamps.

  Add a unique index on `(hotel_id, reservation_id, request_key)` and an index on `(reservation_id, created_at)`.
- [X] T044 [P] [US5] Create `app/Models/VoucherEmailRequest.php`:
  - traits: `HasUuids`, `BelongsToHotel`; UUID key settings;
  - `$fillable`: `reservation_id`, `request_key`, `recipient`, `voucher_template_id`, `language`, `show_total`, `overrides`, `staff_note`, `requested_by`. `status`, `status_reason`, `unplaced`, `claimed_at` and `sent_at` are written only by the job and controller through `forceFill`;
  - casts: `language` → `VoucherLanguage`, `status` → `VoucherEmailStatus`, `overrides` → array, `unplaced` → array, `show_total` → bool, `claimed_at`/`sent_at` → datetime;
  - no `SoftDeletes`, on purpose (data-model.md, plan Complexity Tracking); say so in a one-line class docblock;
  - relations: `reservation()` and `template()` (`withTrashed`).
- [X] T045 [P] [US5] Create `app/Http/Requests/Vouchers/EmailVoucherRequest.php`, which extends `VoucherOptionsRequest` and adds `request_key` → `required|string|max:100` and `email` → `nullable|email:rfc|max:255`. Create `app/Http/Resources/VoucherEmailRequestResource.php` with `id`, `status`, `status_reason`, `recipient`, `language`, `voucher_template_id`, `show_total`, `unplaced`, `created_at` and `sent_at`.
- [X] T046 [P] [US5] Create `app/Mail/ReservationVoucherMail.php`.
  - **Constructor**: `string $pdf`, `string $reference`, `VoucherLanguage $language`, `string $hotelName`.
  - **Envelope**: the subject is `__('voucher.mail.subject', ['reference' => …], $language->value)`.
  - **Content**: a plain-text view `resources/views/mail/voucher.blade.php` with `mail.body` in the language.
  - **Attachments**: `Attachment::fromData(fn () => $this->pdf, "voucher-{$reference}.pdf")->withMime('application/pdf')`.
  - **Queueing**: not queued itself; the job sends it synchronously through `VoucherMailSender`.
  - **Accessors** for the SDK path: `pdf()`, `filename()`, `subjectLine()` and `textBody()` (renders the text view in the voucher language).

  In the same task, create `app/Services/Vouchers/VoucherMailSender.php` with `send(ReservationVoucherMail $mail, string $recipient, string $idempotencyKey): void` (research R10).
  - **Resend path**: when `config('mail.default') === 'resend'`, call `app(\Resend\Client::class)->emails->send(['from' => config('mail.from.name').' <'.config('mail.from.address').'>', 'to' => [$recipient], 'subject' => …, 'text' => …, 'attachments' => [['filename' => …, 'content' => base64_encode($pdf), 'content_type' => 'application/pdf']]], ['idempotency_key' => $idempotencyKey])`.
  - **Other mailers**: `Mail::to($recipient)->send($mail)`.
  - **Container binding**: bind `\Resend\Client` in `AppServiceProvider` as a singleton built with `\Resend::client(config('services.resend.key'))`, only if no binding exists yet, so tests can swap in a mock.
  - **Resend errors**: any exception from the SDK is rethrown as-is so the job retries.
- [X] T047 [US5] Create `app/Jobs/SendReservationVoucherEmailJob.php`.
  - **Job settings**: `use Queueable`; constructor `(public string $emailRequestId)` with `$this->onQueue(QueueLane::DEFAULT->value)`; `$timeout = 120`, `$tries = 3`, `backoff() = [10, 60]`.
  - **Claiming**: `handle()` loads the row with `withoutGlobalScope('hotel')`, then runs everything inside `TenantContext::runForHotel($row->hotel_id, …)`. It claims atomically with an `UPDATE … SET status='sending', claimed_at=now() WHERE id=? AND (status='queued' OR (status='sending' AND claimed_at < now()-stale))`, and returns if no row was affected.
  - **Ineligible**: if the reservation is `cancelled` or `no_show` (reusing the T021 eligibility helper), set `skipped` with `reservation_<status>`, record `EventLogger::record($row, 'skipped')` and return.
  - **Render**: resolve the template from the snapshot (`withTrashed`, falling back to the built-in layout if the template was force-deleted), build `VoucherData` with the snapshot language, `show_total`, overrides and note, and render.
  - **Send**: `app(VoucherMailSender::class)->send(new ReservationVoucherMail(...), $row->recipient, 'voucher-email:'.$row->id)`. The key is fixed per request, so every retry and takeover reuses it. Then set `sent`, `sent_at` and `unplaced` (from `VoucherRenderResult::$unplaced`), and record `EventLogger::record($row, 'sent')`.
  - **Failure**: `failed(Throwable $e)` sets `failed` with `status_reason = class_basename($e)` and records `'failed'` inside `runForHotel`.
- [X] T048 [US5] Add `email(EmailVoucherRequest $request, Reservation $reservation)` and `emails(GenericIndexRequest $request, Reservation $reservation)` to `ReservationVoucherController`.
  - **`email`, steps 1–3**:
    1. `authorize('voucherEmail', $reservation)`.
    2. Check eligibility (409).
    3. Resolve the template and language now, so the snapshot is fixed. Resolve the recipient as `email` ?? the primary guest's `email`, else 422 `email` "The guest has no email address."
  - **`email`, step 4**: inside `DB::transaction`, `lockForUpdate` the reservation row. If a row with this `(hotel_id, reservation_id, request_key)` exists, go to step 5 (existing row). Otherwise count the reservation's rows with `created_at > now()-24h` and `status NOT IN (skipped, failed)`. If the count is ≥ `config('vouchers.email_daily_cap')`, return `apiResponse(__('voucher.errors.daily_cap'), 429, null)`, with no row, no event and no job (FR-027a). Otherwise create the row, still catching `UniqueConstraintViolationException` to re-read the existing row. Add `errors.daily_cap` to both `lang/*/voucher.php` files (T011).
  - **`email`, step 5, existing row**: return 200 with the resource.
  - **`email`, step 5, new row**: `EventLogger::record($reservation, 'voucher_email_requested', changes: overrides/note, context: ['voucher_email_request_id', 'recipient', 'voucher_template_id', 'language', 'show_total'])`, then `SendReservationVoucherEmailJob::dispatch($row->id)->afterCommit()`, and return 202.
  - **`emails`**: authorize `voucherEmail`, then return the reservation's requests newest first, paginated, through `GenericQuery::apply()`. If `VoucherEmailRequest` needs `Filterable` for that, add it.

  Register `POST /reservation/{reservation}/voucher/email` and `GET /reservation/{reservation}/voucher/emails` before the resource route.
- [X] T049 [US5] Add `SendReservationVoucherEmailJob` to `tests/Inventory/entry_points.php` with `['scope' => 'hotel']`, unless `EntryPointInventoryTest` already discovers it through the generic checks. Run `QueueLaneTest`, `JobTenantIsolationTest` and `EntryPointInventoryTest`.

**Checkpoint**: T042 passes, and the inventory tests are green.

---

## Phase 8: User Story 6 - Manage templates (Priority: P3)

**Goal**: List, view, stream the image of, and delete templates, all hotel-scoped.

**Independent Test**: Create two templates, then rename one and delete the other. The list shows one renamed template, and hotel B sees nothing (404 on every id route).

- [X] T050 [P] [US6] Add management cases to `tests/Feature/VoucherTemplateControllerTest.php`:
  - (a) `GET /voucher-templates` lists only the hotel's templates and supports `filter[language]`, `filter[is_default]`, `search`, `sort=-created_at` and `per_page`;
  - (b) `GET …/{id}` → 200, and hotel B → 404;
  - (c) `GET …/{id}/image` streams the original with its mimetype to an allowed user; hotel B gets 404; a user without `voucher_templates.view` gets 403;
  - (d) `DELETE` soft-deletes the template and keeps its files; a deleted default makes generation fall back to the built-in layout; the deleted template is absent from the list; its name can be reused;
  - (e) permission matrix: an employee with each of `view`, `create`, `update`, `delete` is allowed only on the matching endpoints and gets 403 otherwise; an employee with no role gets 403 everywhere;
  - (f) a super admin must pass `hotel_id` on store (`resolveHotel()` behaviour), and the template is created in that hotel.
- [X] T051 [US6] Add `index(GenericIndexRequest)`, `show`, `image` and `destroy` to `VoucherTemplateController`.
  - **`index`**: `GenericQuery::apply()` over `VoucherTemplate`, returning a paginated `VoucherTemplateResource`.
  - **`image`**: `authorize('view')`, then `Storage::disk($t->disk)->response($t->original_path, null, ['Content-Type' => $t->mime_type])`.
  - **`destroy`**: soft-deletes and leaves files.

  Register these routes:
  - `GET /voucher-templates`;
  - `GET /voucher-templates/{voucherTemplate}`;
  - `GET /voucher-templates/{voucherTemplate}/image`;
  - `DELETE /voucher-templates/{voucherTemplate}`.

**Checkpoint**: T050 passes. All 13 endpoints from contracts/voucher-api.md are routed.

---

## Phase 9: Polish & Cross-Cutting

- [X] T052 [P] Add rows to the permission dataset in `tests/Feature/PermissionAuthorizationTest.php`:
  - `'voucher templates' => ['/api/voucher-templates', Permission::VOUCHER_TEMPLATES_VIEW]`;
  - write and action endpoints for `reservations.voucher`, `reservations.voucher_email` and `voucher_templates.create|update|delete`, in whatever datasets the file uses for actions.

  Add an `it('never gives employees without a role the voucher permissions')` case, like the no-show one. Also assert that `GET /api/permissions` lists all six new values (FR-034).

  Add an architecture check in `tests/Feature/VoucherEmailTest.php` (FR-031): `arch()->expect(SendReservationVoucherEmailJob::class)->toOnlyBeUsedIn([ReservationVoucherController::class])`, and the same for `ReservationVoucherMail` (allowing the job and `VoucherMailSender`). This proves no event, job or AI tool can send a voucher on its own. If `toOnlyBeUsedIn` isn't available in this Pest version, grep `app/` in a plain test.
- [X] T053 [P] Run `tests/Feature/CrossTenantRouteTest.php` and confirm every new route is classified by `crossTenantRuleFor()`. If any lands in `none`, add the rule (for `{voucherTemplate}`/`{reservation}` parameters and the `reservation_id`/`template_id` body references), following the existing route-rule entries.
- [X] T054 [P] Write `docs/voucher-templates-api-documentation.md` covering all template endpoints, the field catalogue, layout box rules, image rules, preview and error keys. Use the style of `docs/room-types-api-documentation.md`, built from contracts/voucher-api.md.
- [X] T055 [P] Update `docs/reservations-api-documentation.md` with the `POST …/voucher`, `POST …/voucher/email` and `GET …/voucher/emails` sections, covering the options, overrides table, status codes, the `X-Voucher-Unplaced` header and idempotency.
- [X] T056 [P] Update the permission reference in `docs/staff-roles-api-documentation.md` with the six permissions, their endpoints, and a note that none is an employee default.
- [X] T057 [P] Write `specs/018-reservation-voucher/frontend-changes.md` for `ecosystem-frontend`, using the plan's "Frontend slice":
  - the template list and upload;
  - the layout editor, which stores fractions of the displayed image, handles per-box errors by `id`, and previews;
  - the reservation "Voucher" dialog, which generates a `request_key` per submit and polls `…/voucher/emails`;
  - the new permissions.
- [X] T058 Update `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md` Phase 2 with a "**Built (date):** `specs/018-reservation-voucher`" note, like the Phase 13 and 14 notes.
- [X] T059 Run `./vendor/bin/pint` and then `php artisan test --parallel` until both are green (CLAUDE.md; memory: run tests with `--parallel`).
  - *Result 2026-10-27:* Pint clean; `php artisan test --parallel` 2357/2357 passed, run with `SENTRY_LARAVEL_DSN=` cleared. With the DSN from `.env`, tests run out of memory in `SentryScrubber::keys()` (it recurses into objects with no cycle guard). That happens on the last commit too, so it is not caused by this feature.
- [ ] T060 Walk through [quickstart.md](quickstart.md) manual steps 1–10 on the dev DB, verifying the DB name explicitly (memory: no `.env.testing`), and tick the outcomes checklist. SC-003 needs the project owner's sign-off as a native Arabic reader on steps 3 and 5; record their name and the date under this task. SC-009 is covered automatically by T025 (i) and also timed on staging before go-live.

---

## Dependencies & Execution Order

- **Setup (T001–T004)** → **Foundational (T005–T012)** → stories.
- **US1 (T013–T022)**: needs Foundational only. This is the MVP.
- **US2 (T023–T034)**: needs Foundational and T018 (the mPDF factory). T034 touches the US1 controller.
- **US3 (T035–T037)**: needs US2 (`renderTemplate` and the layout rules).
- **US4 (T038–T041)**: needs US1. Test (a) and the "unplaced" header need US2.
- **US5 (T042–T049)**: needs US1 (the builder, renderer and eligibility helper). It's independent of US2–US4 apart from the snapshot fields.
- **US6 (T050–T051)**: needs T010, T012 and T029. It can run alongside US3–US5.
- **Polish (T052–T060)**: after all the stories.

Within a story, write the tests first, then models and requests, then services, then controllers and routes.

## Parallel Examples

- **Setup**: T002, T003 and T004 together after T001.
- **Foundational**: T005, T006, T007, T008, T009 and T011 together; then T010; then T012.
- **US1**: T013, T014, T015 and T020 together; then T016 → T017 → T018 → T019 → T021 → T022.
- **US2**: T023, T024, T025, T026, T027 and T029 together; then T028, then T030 → T031 → T032 → T033 → T034.
- **US5**: T042, T043, T044, T045 and T046 together; then T047 → T048 → T049.
- **Polish**: T052–T057 together.

## Implementation Strategy

1. **MVP**: Setup, Foundational, then US1. Staff can download correct English and Arabic vouchers in the built-in layout. Stop and validate with quickstart steps 1–3.
2. **Hotel branding**: US2 and US3 deliver uploaded templates, layout and preview, the flexibility the hotel asked for.
3. **Day-to-day use**: US4 (overrides and template choice) and US5 (email).
4. **Housekeeping**: US6, then Polish.

Each checkpoint leaves `main`-mergeable code with green tests.
