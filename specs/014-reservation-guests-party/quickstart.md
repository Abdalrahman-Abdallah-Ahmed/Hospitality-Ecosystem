# Quickstart: Validate Reservation Guests and Party Composition

**Feature**: [spec.md](spec.md) · **Contract**: [contracts/reservation-guests-api.md](contracts/reservation-guests-api.md) · **Model**: [data-model.md](data-model.md)

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
php artisan migrate
```

Use the PostgreSQL test database configured in `phpunit.xml`; do not use SQLite.

## Automated checks

```bash
php artisan test --filter=ReservationGuests
php artisan test --filter=ReservationController
php artisan test --filter=CreateReservationTool
php artisan test --filter=ReservationRoomsAiTools
php artisan test --filter=ReservationImport
php artisan test --filter=SenderRecognitionService
php artisan test --filter=TenantIsolation
php artisan test
./vendor/bin/pint
```

## Acceptance scenarios

| # | Scenario | Expected outcome |
| --- | --- | --- |
| 1 | Create a reservation with a primary guest, an additional guest, 2 children and ages 5 and 8. | Created reservation returns two marked guests and `[5, 8]`. |
| 2 | Create with one guest and no primary flag. | That guest becomes primary; existing single-guest clients continue to work. |
| 3 | Submit no primary, two primaries or the same guest twice. | 422 with no partial reservation, guest links or audit state. |
| 4 | Submit two children with one age, age 18, or ages for zero children. | 422 on `children_ages`. |
| 5 | Switch the primary guest on a current reservation. | `guest_id`, the primary relation and not-yet-departed stays change together; audit history identifies the actor. |
| 6 | Update only dates on a migrated reservation with children and no ages. | Update succeeds and preserves the empty ages list. |
| 7 | Change that reservation's children total without a complete age list. | 422; no party change is saved. |
| 8 | Attempt to attach a guest from another hotel. | 422 without exposing the foreign guest. |
| 9 | Create or update through the Admin AI tools. | The same invariants and permissions apply; the actor is audited as AI. |
| 10 | Message from an additional guest's WhatsApp number. | They are treated as their own guest profile and do not receive reservation details or authority. |
| 11 | Migrate a database with existing and soft-deleted reservations. | Each existing reservation gets exactly one primary link to its former `guest_id`; no ages are invented. |
| 12 | Re-run the migration fixture/backfill. | No duplicate guest links are created. |
