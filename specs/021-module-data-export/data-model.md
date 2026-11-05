# Data Model: Module Data Export

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

No new tables and no migrations. The feature reads four existing tables and writes only
audit rows to the existing `event_logs` table.

## Enums

### `App\Enums\ExportFormat` (new)

| Case | Value | File | Content type |
| --- | --- | --- | --- |
| `XLSX` | `xlsx` | `.xlsx` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| `CSV` | `csv` | `.csv` | `text/csv; charset=UTF-8` |
| `SQL` | `sql` | `.sql` | `application/sql; charset=UTF-8` |

### `App\Enums\Permission` (extended)

| Case | Value | Grouped after | In `employeeDefaults()` |
| --- | --- | --- | --- |
| `ACTIVITIES_EXPORT` | `activities.export` | `ACTIVITIES_DELETE` | No |
| `BOOKINGS_EXPORT` | `bookings.export` | `BOOKINGS_OVERRIDE_CAPACITY` | No |
| `GUESTS_EXPORT` | `guests.export` | `GUESTS_DELETE` | No |
| `RESERVATIONS_EXPORT` | `reservations.export` | `RESERVATIONS_MOVE_ROOM` | No |

## Export request (not stored)

| Field | Rule | Notes |
| --- | --- | --- |
| `format` | optional, one of `ExportFormat` | Default `csv` |
| `hotel_id` | optional uuid | Required in effect for super admins (otherwise 403); ignored for scoped users |
| `search`, `sort`, `filter[...]` | as the module's list | Unknown filter/sort columns → 422 |
| Bookings only: `activity_id`, `reservation_id`, `scheduled_from`, `scheduled_to`, `cancellation_requested` | as `BookingIndexRequest` | |
| Reservations only: `filter[room_type_id]`, `filter[room_id]` | as `ReservationIndexRequest` | Match any live room line |
| `page`, `per_page` | accepted, ignored | Export covers every page |

**Selection rule**: the module's list builder (R1) + `where hotel_id = resolved hotel` +
tie-breaker `orderBy(<table>.id)`, soft-deleted rows excluded by the model's `SoftDeletes`.

**Limits**: matching count > `config('exports.max_rows')` (default 10,000) → refused, 422.
More than 10 export requests per user per minute → 429.

## Export audit entry (`event_logs` row, existing table)

Written by `EventLogger::record($hotel, ...)`.

| Field | Value |
| --- | --- |
| `hotel_id` | resolved hotel |
| `event_type` | `hotel.reservations_exported` · `hotel.activities_exported` · `hotel.bookings_exported` · `hotel.guests_exported`; refusals over the cap: `hotel.<module>_export_refused` |
| `subject_type` / `subject_id` | the hotel |
| `actor_*` | the signed-in user (existing behaviour) |
| `changes` | `{ format, search, sort, filter, extra_filters, row_count }` (refusal: `matching`, `max_rows` instead of `row_count`) |

No exported record content is written to the entry.

## Column sets — Excel and CSV

Strings pass through formula neutralisation (R3). "Label" = readable enum label. Dates are
real Excel dates in XLSX and `YYYY-MM-DD` / `YYYY-MM-DD HH:MM` text in CSV.

### Reservations — one row per reservation

| Header | Source | Type |
| --- | --- | --- |
| Reservation Number | `reservations.reservation_id` | text |
| Guest Name | `guest.first_name + last_name` | text |
| Guest Email | `guest.email` | text |
| Guest Phone | `guest.phone_number` | text |
| Arrival Date | `arrival_date` | date |
| Departure Date | `departure_date` | date |
| Nights | departure − arrival | integer |
| Adults | `adults` | integer |
| Children | `children` | integer |
| Room Types | distinct `reservationRooms(active).roomType.name`, comma-joined | text |
| Rooms | `reservationRooms(active).room.room_number` where assigned, comma-joined; empty if none | text |
| Party Guests | `reservationGuests.guest` full names, comma-joined | text |
| Status | `status` (label) | text |
| Source | `source` (label) | text |
| Reservation Value | `reservation_value` | number |
| Currency | `currency` | text |
| Special Requests | `special_requests` | text |
| Created At | `created_at` | date-time |

### Activities

| Header | Source | Type |
| --- | --- | --- |
| Name | `name` | text |
| Category | `category.name` | text |
| Description | `description` | text |
| Price | `price` | number |
| Currency | `currency` | text |
| Active | `is_active` → Yes/No | text |
| Available From | `available_from` | date |
| Available Until | `available_until` | date |
| Audience | `audience` (label) | text |
| Duration (Days) | `duration_days` | integer |
| Daily Capacity | `daily_capacity` | integer |
| Created At | `created_at` | date-time |

### Bookings

| Header | Source | Type |
| --- | --- | --- |
| Reference | `reference` | text |
| Item Name | `item_name` | text |
| Activity | `activity.name` (empty if removed) | text |
| Guest Name | `guest` full name | text |
| Reservation Number | `reservation.reservation_id` | text |
| Status | `status` (label) | text |
| Scheduled For | `scheduled_for` | date-time |
| Guests (Pax) | `pax` | integer |
| Charge Model | `charge_model` (label) | text |
| Expected Value | `expected_value` | number |
| Currency | `currency` | text |
| Origin | `origin` (label) | text |
| Channel | `channel` (label) | text |
| Created By | `createdBy.name` | text |
| Confirmed At | `confirmed_at` | date-time |
| Realised At | `realised_at` | date-time |
| Cancelled At | `cancelled_at` | date-time |
| Cancellation Reason | `cancellation_reason` | text |
| Notes | `notes` | text |

### Guests

| Header | Source | Type |
| --- | --- | --- |
| First Name | `first_name` | text |
| Last Name | `last_name` | text |
| Email | `email` | text |
| Phone | `phone_number` | text |
| Preferred Language | `preferred_language` | text |
| Nationality | `nationality` | text |
| Loyalty Status | `loyalty_status` | text |
| VIP | `is_vip` → Yes/No | text |
| Channel | `channel` (label) | text |
| External ID | `external_id` | text |
| Created At | `created_at` | date-time |

## Column sets — SQL

All columns of the module's table as stored (`Schema::getColumnListing`), including `id`,
`hotel_id` and every foreign-key id, minus:

| Table | Excluded | Why |
| --- | --- | --- |
| `bookings` | `context` | Internal AI/recommendation processing context |
| `guests` | `identity_hash`, `identity_resolved_at` | Internal identity-matching values (code review, 2026-11-03) |
| `reservations`, `activities` | — | No internal-only columns today |

Value encoding: `NULL`, `TRUE`/`FALSE`, unquoted numbers, everything else quoted by the
connection's PDO quoting. One `INSERT INTO "<table>" (...) VALUES (...);` per record inside
`BEGIN; … COMMIT;`, preceded by a comment header.
