# Quickstart: validating Room-Type Availability

**Contract**: [contracts/availability-api.md](contracts/availability-api.md) ·
**Model**: [data-model.md](data-model.md)

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d   # Postgres + pgvector
php artisan migrate                              # includes the reservation date index
```

SPEC-010 (reservation rooms) must already be migrated.

## Automated checks

```bash
php artisan test --filter=AvailabilityServiceTest          # calculation rules (US1, US3)
php artisan test --filter=AvailabilityControllerTest       # endpoint, validation, 403, super admin
php artisan test --filter=ReservationAvailabilityGuardTest # overbooking guard, override, AI, import (US2)
php artisan test --filter=ReservationAvailabilityConcurrencyTest # same-type bookings serialized (SC-003)
php artisan test --filter=AvailabilityToolsTest            # Admin AI + Concierge tools (US4)
php artisan test --filter=TenantIsolationTest
php artisan test --filter=PermissionAuthorizationTest
php artisan test                                           # full suite green
./vendor/bin/pint --test
```

## What each scenario must show

| # | Setup | Action | Expected |
| --- | --- | --- | --- |
| 1 | 5 Deluxe rooms, 1 `maintenance`, confirmed reservation with 2 Deluxe lines for 13–14 Mar | `GET /api/availability?arrival_date=…12&departure_date=…15` | Deluxe: sellable 4 / 2 / 4, `bookable_for_stay` 2 |
| 2 | Reservations departing 13 Mar and arriving 13 Mar | Look up the night of 13 Mar | Only the arriving one is counted |
| 3 | Cancelled and checked-out reservations on the dates | Look up | They count as 0 booked |
| 4 | Checked-in guest whose departure date was yesterday | Look up tonight | Still booked 1 |
| 5 | 2 Deluxe sellable on 13 Mar | Create 3 Deluxe for 12–15 Mar | 422, `shortfalls` = Deluxe / 13 Mar / 1; nothing saved |
| 6 | Same | Resend with `overbook_override: true` as an employee without `reservations.overbook` | 403; nothing saved |
| 7 | Same | Resend as an admin | 201; `reservation.overbooking_overridden` audit entry with the actor and shortfall; grid shows `overbooked: 1` |
| 8 | Same | AI `CreateReservationTool` | Tool returns the shortfall message; nothing saved |
| 9 | 1 Deluxe sellable, committed data (`DatabaseTruncation`) | Connection B holds the Deluxe lock and books it; connection A tries with `lock_timeout = 1s`, then again after B commits | A first times out on the lock, then gets the shortfall; exactly one reservation is saved |
| 10 | Reservation on a legacy overbooked night | Change only `special_requests` | Saved; no check fails |
| 11 | Import file beyond availability | Import | Rows are saved; the grid shows them as overbooked |
| 12 | A 2nd hotel | Look up with the 1st hotel's `room_type_ids` | 422, same message as for an unknown id |
| 13 | Employee with no staff role | `GET /api/availability` | 200 (default permission) |
| 14 | Concierge tool for a sold-out type | Ask | `available: false`; the response has no counts |

## Manual performance check (SC-005)

Seed one hotel with 500 rooms across 6 types and about 15k lines over 90 days (for
example, a throwaway seeder or tinker loop). Then time
`GET /api/availability?arrival_date=<today>&departure_date=<today+90>`. It should finish in
under 2 s on a developer machine. `AvailabilityServiceTest` also checks that the number of
queries is the same for 1 and 90 nights.
