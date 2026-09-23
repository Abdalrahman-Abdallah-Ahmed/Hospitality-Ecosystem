# Quickstart: validating Reservation Rooms

**Feature**: [spec.md](spec.md) · **Contract**: [contracts/reservations-api.md](contracts/reservations-api.md) · **Model**: [data-model.md](data-model.md)

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d     # Postgres + pgvector
php artisan migrate                                # runs the 3 new migrations
```

Tests use the `Hospitality_Ecosystem_testing` database from `phpunit.xml`, never the dev
database.

## Automated checks

```bash
php artisan test --filter=ReservationRoom           # new domain + API tests
php artisan test --filter=ReservationController     # updated contract tests
php artisan test --filter=ReservationRoomOccupancy  # occupancy on the new shape
php artisan test --filter=ReservationImport
php artisan test --filter=CreateReservationTool
php artisan test --filter=RoomTypeController        # deletion guard
php artisan test --filter=TenantIsolation
php artisan test                                    # full suite must stay green
./vendor/bin/pint
```

## Scenarios → expected outcome

| # | Scenario (spec ref) | Expected |
| --- | --- | --- |
| 1 | Create with `2 × Deluxe, 1 × Suite`, no rooms (US1-1) | 201; `rooms` has 3 `reserved` lines, all `room: null`; `room_summary` = Deluxe 2, Suite 1 |
| 2 | Create with `rooms: []` (US1-3) | 422 `errors.rooms` |
| 3 | Create with an inactive type / another hotel's type (US1-4) | 422 on `rooms.0.room_type_id`; no reservation row |
| 4 | 5 adults into types with total `max_occupancy` 4 (US1-5) | 422 capacity message |
| 5 | Same with `capacity_override: true` as staff (US1-6) | 201; EventLog has `reservation.capacity_overridden` with actor kind `user` |
| 6 | AI tool, over capacity (US1-7) | tool returns an error; no reservation |
| 7 | Run migrations on a DB with legacy reservations (US2) | every reservation has 1 line with its old room and type; a reservation with no room has the "Unspecified (migrated)" type; room statuses are unchanged |
| 8 | Re-run the backfill migration's `up()` (US2-4) | 0 new lines |
| 9 | PUT with desired list adding a Suite and dropping an unassigned Deluxe (US3) | assigned Deluxe line unchanged (same id and room); new Suite line; dropped line `cancelled` |
| 10 | PUT removing the last line (US3-3) | 422 |
| 11 | PUT `rooms` on a `checked_out` reservation (US3-4) | 422 |
| 12 | Create with `room_id` of the matching type (US4-1) | line holds that room |
| 13 | Checked-in guest: change line's room 101 → 105 (US4-4) | 101 `available`, 105 `occupied`, `reservation_room.updated` audited |
| 14 | Checked-in: room of another type, or add a line (US4-5, US3-5) | 422 |
| 15 | Same room on two lines (US4-6) | 422; DB partial unique index also refuses a direct insert |
| 16 | Status → `cancelled` (FR-012) | all lines `cancelled`; any occupied room released |
| 17 | 3-room reservation checked in | all 3 rooms `occupied`; overnight job dirties all 3; `occupiedRoomsOn` counts 3 |
| 18 | `GET /api/reservation?filter[room_type_id]=…` (FR-018) | only reservations with an active line of that type |
| 19 | Hotel B user reads/filters Hotel A reservation rooms (FR-022) | 404/empty; no lines leak via filters or tools |
| 20 | `DELETE /api/room-types/{id}` used by an upcoming reservation (FR-028) | 422 `deletion_blocked_by_reservations`; allowed once only past/cancelled lines remain |
| 21 | Import the same file twice (US5-4) | second run skips every row; no duplicate lines |
| 22 | Guest asks the Concierge about their booking (US5-2) | `GetOwnReservationTool` returns own `rooms` and `room_summary` only |

## Manual smoke test (optional)

1. `composer dev`, then log in as a hotel admin.
2. Create two room types and three rooms, then POST scenario 1. Check the response shape
   against the contract.
3. PUT the reservation to `checked_in` with a room on one line, then `GET /api/room` shows
   it `occupied`.
