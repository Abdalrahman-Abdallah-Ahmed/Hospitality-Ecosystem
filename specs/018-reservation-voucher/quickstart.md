# Quickstart: Reservation Voucher with Uploadable Templates

Validation guide. Endpoints and shapes: [contracts/voucher-api.md](contracts/voucher-api.md),
[data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
composer install                     # pulls mpdf/mpdf
php artisan migrate --seed
composer dev                         # serve + queue listener (MAIL_MAILER=log locally)
```

- `php -m` lists `gd`, `mbstring` and `zlib`, and GD reports WebP support.
- Noto Sans (regular and bold) is present in `resources/fonts/voucher/`. Arabic uses
  mPDF's own XB Riyaz, which ships with `mpdf/mpdf`.

## Automated checks

```bash
php artisan test --parallel --filter=Voucher
php artisan test --parallel --filter=PermissionAuthorizationTest
php artisan test --parallel --filter="CrossTenantRouteTest|EntryPointInventoryTest|QueueLaneTest|JobTenantIsolationTest"
./vendor/bin/pint --test
```

Expected: all green. The voucher tests cover:
- **Content**: PDF text extracted with `smalot/pdfparser` contains each FR-002 value; the
  total is absent unless `show_total` is set.
- **Status rules**: 409 for `cancelled` and `no_show`; 200 for `pending`.
- **Layout validation**: each bad-box case gives a 422, and the previous layout survives.
- **Default uniqueness**: setting a second default clears the first, and the partial index
  holds under a direct insert.
- **Image rules**: each failed rule gives a 422 and leaves no stored file.
- **Re-upload**: replacing the image keeps the layout.
- **Text fitting** (`VoucherTextFitter`): shrink to 6 pt, then "…"; truncate; a 10-guest
  list ends with "+N more"; RTL alignment mirroring.
- **Email**: the same `request_key` three times gives one queued job and one mail (`Mail::fake`),
  and a cancelled-before-send request ends `skipped`.
- **Previews**: no event log rows and no mail.
- **Isolation**: hotel B gets 404 on every hotel A template route, voucher route and
  preview with a hotel A reservation.
- **Permissions**: an employee with each permission is allowed, and gets 403 without it.

## Manual walk-through (dev DB)

1. **Built-in layout, English.** Create a confirmed reservation with 2 room lines, a primary
   guest plus 1 other guest, and 1 child aged 7. Call `POST /api/reservation/{id}/voucher`.
   The PDF shows every FR-002 field and no total.
2. **Show total.** Repeat with `"show_total": true`. The total and currency appear.
3. **Arabic.** Set the guest's `preferred_language` to `ar` and repeat. The labels are
   Arabic, text is right-aligned, the letters join, and the Latin name and email read
   correctly.
4. **Upload a template.** Upload a 2480×3508 PNG as "Standard EN" (`en`, default). Upload a
   900 px-wide image: you get 422 `image.dimensions_short_side`.
5. **Lay it out.** Save a layout with `guest.primary_name`, `stay.arrival_date`,
   `rooms.lines` and a `free_text` box. Preview with sample data, then with the reservation
   from step 1. The values sit inside their boxes on the artwork.
6. **Overflow.** Add 10 guests to the reservation and preview. The guest list box shrinks,
   then ends with "+N more", and nothing leaves its box.
7. **Overrides.** Generate with `template_id`, a `primary_guest_name` override and a staff
   note on a template with no note box. The PDF shows the override, the
   `X-Voucher-Unplaced: voucher.staff_note` header is set, `GET /reservation/{id}` is
   unchanged, and `event_logs` has `reservation.voucher_generated` with the overrides.
8. **Email.** Send `POST …/voucher/email` with `request_key: "k1"` twice. The first gets 202
   and the second 200 with the same id. `storage/logs/laravel.log` shows one mail with a PDF
   attachment, and the request reaches `sent`.
9. **Not eligible.** Cancel the reservation. Generating gives 409; emailing with a new key
   gives 409.
10. **Isolation.** As a hotel B admin, `GET /api/voucher-templates/{hotel A id}` gives 404.

## Outcomes checklist

- [ ] SC-002: every placed box's text lies inside its box (steps 5–6)
- [ ] SC-003: an Arabic reader confirms step 3 is correct
- [ ] SC-004: one email per `request_key` (step 8)
- [ ] SC-005: the reservation is unchanged after step 7
- [ ] SC-007: event log rows for steps 1–3, 7 and 8; none for the previews
- [ ] SC-008: step 10 and the automated isolation tests
- [ ] SC-009: on the staging host, generation with a 6000 px template takes ≤ 5 s (time it
      with `curl -w '%{time_total}'`)
