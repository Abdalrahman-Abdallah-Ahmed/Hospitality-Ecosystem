# Quickstart: Reservation Import v2 and Reconciliation

Validation guide for `013-reservation-import-v2`. Contract details:
[contracts/reservation-import-api.md](contracts/reservation-import-api.md). Data, outcomes
and validation rules: [data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
composer install            # pulls giggsey/libphonenumber-for-php-lite
php artisan migrate
```

For manual runs, start a worker (`composer dev` runs `queue:listen`).

## Run

```bash
php artisan test --parallel --filter=ReservationImport
php artisan test --parallel --filter='TenantIsolation|PermissionAuthorization'
php artisan test --parallel
./vendor/bin/pint
```

## Timing (T058, 2026-10-23)

Measured on the development machine (Windows, Postgres in Docker, about 1.6 ms per query),
with generated rows, inside one test transaction with statistics refreshed before each
chunk:

| Rows | Prepare | Write, per 100 reservations | Total |
| --- | --- | --- | --- |
| 1,000 | 2 s | about 6.5 s, flat | 67 s |
| 3,000 | 6 s | 6.5 s, rising to 12 s | 264 s |

Each reservation costs about 27 queries. The later slowdown at 3,000 rows comes from
running everything in one open test transaction, which can't clean up dead row versions.
In production each reservation commits on its own. Extrapolated, 5,000 rows take about 5.5
minutes, within SC-003's 10. This hasn't been measured on a real queue worker yet; repeat
it on staging with the reference export (T059).

## Acceptance scenarios

| # | Scenario | Expected outcome |
| --- | --- | --- |
| 1 | Upload a mixed CSV (good rows, missing dates, a bad date). | `202` with `status: queued`; it moves to `running` then `completed`; the totals add up to the data rows; every non-imported row has a reason that names the field. |
| 2 | Upload a file with no `arrival_date` column. | `failed`; `failure_reason` names the column; nothing written. |
| 3 | One reference over 3 rows: 2 × "Dbl Deluxe" (rooms 214, 215), 1 × "Suite" without a number, 2 named guests, the second flagged primary. | One reservation, 3 lines (2 assigned), 2 guests with the second primary. |
| 4 | "12/09/2025" with the default order, then with `month_first`. | 12 Sep 2025, then 9 Dec 2025. "31/02/2025" is invalid. |
| 5 | Phone "0020 100 123 4567" with an existing guest "+201001234567"; also "01001234567" at a hotel with `country_code = EG`. | The existing guest is reused both times. |
| 6 | A row whose phone matches guest A and whose email matches guest B. | `unresolved`, naming both; nothing from that reservation written. |
| 7 | Import a file, then import the identical file again. | The second run is all `duplicate`; reservation, guest and booking counts unchanged. |
| 8 | Re-import with one reference's departure changed. | `duplicate` with a `data_differs` warning; the stored reservation is unchanged. |
| 9 | Two uploads for the same hotel in a row. | The second stays `queued` until the first ends, then reports the first's reservations as `duplicate`. Another hotel's upload runs in parallel. |
| 10 | Dry run, then a real run of the same file. | The dry run writes nothing (no reservation, guest, room, type or booking). Both reports give each row the same outcome. |
| 11 | "In House" row for room 301 departing tomorrow; a second reservation in the file holds room 301 on the same nights. | The first is checked in and room 301 is occupied; the second is imported with the line unassigned and a `room_clash` warning naming the first. |
| 12 | "No Show" with an arrival last week; "No Show" arriving next week; "Checked Out" departing tomorrow. | The first is `no_show` (stays no-show, holds no room, no task); the other two are `invalid`, naming status and date. |
| 12a | "In House" row whose departure was last week. | Imported `checked_out` with a `status_corrected` warning; no room occupied. |
| 12b | One reference over 3 unnumbered "Deluxe" rows, each with a different guest; then the same with `room_count` 2. | One Deluxe line and 3 guests; then two Deluxe lines. |
| 13 | 1 adult, 0 children, 3 named guests; then the same without party columns. | `invalid` (party vs guest count); then imported with 3 adults. |
| 14 | 2 children with ages "5"; 2 children with no ages. | `invalid`; imported with an empty ages list. |
| 15 | A row with `activity_name` "Sunset Cruise" (exists) and one with "Camel Ride" (doesn't). | Booking attached (`origin: import`, status following the reservation: realised for a past date on a checked-out stay, confirmed for a future one); the second reservation is imported with a `booking_unresolved` warning. |
| 16 | Force a failure in the middle of the write phase, then re-upload the same file. | The first is `failed` with its progress; the end state equals one clean run (SC-007). |
| 17 | After any scenario above. | No room is on two live lines on the same night (SC-008). |
| 18 | An employee without `reservations.import` calls each endpoint; another hotel's admin opens this import. | `403`; `404`. |
| 19 | A super admin uploads without `hotel_id`, then with it. | `422`, then `202` for that hotel. |
| 20 | Download the report of a finished import; download it while running. | `.xlsx` of the non-imported rows and warnings; `409` while running. |
| 21 | Any import. | One `reservations_imported` audit event with the uploader as actor; no notifications, ledger rows, cleaning tasks or memory extraction. |

## Reference dataset (SC-001, manual)

The September 2025 export holds guest PII and is not committed. With the file supplied by
the project owner:

1. Dry-run it against a staging hotel and check:
   - `total_rows` equals the data rows;
   - no row's reason is a raw exception;
   - `unrecognized_columns` holds nothing that should have been mapped.
2. Run it for real, then run it again. The second run must be all `duplicate`.
