# Quickstart: validating Stay Lifecycle and Check-in/out

**Feature**: [spec.md](spec.md) · **API**: [contracts/stays-api.md](contracts/stays-api.md) ·
**Model**: [data-model.md](data-model.md)

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d   # Postgres + pgvector (tests need it)
php artisan migrate                              # runs the five 2026_09_24_00000{1..5} migrations
```

The test suite uses `Hospitality_Ecosystem_testing` (see `phpunit.xml`). There is no
`.env.testing`: never run `artisan --env=testing` against the dev DB.

## Automated validation

```bash
php artisan test --filter=StayMigrationTest          # FR-004, SC-005: backfill + uniqueness
php artisan test --filter=StaySyncTest               # FR-001–003: one stay per line, follow/cancel/reinstate
php artisan test --filter=CheckInTest                # US1, US3: rules, assignment at check-in, warnings, idempotency
php artisan test --filter=CheckOutTest               # US2, US3: room dirty, cleaning task, FR-012a, early/late
php artisan test --filter=CheckInOutConcurrencyTest  # FR-010, SC-006: two desks, one effect
php artisan test --filter=StayListTest               # US4: arrivals/departures/in-house, late/overdue
php artisan test --filter=ReservationStatusDeprecationTest   # FR-020/021: PUT path, cancel/delete guards
php artisan test --filter=StayAiToolsTest            # US5: Admin AI tools, permission refusal, AI audit actor
php artisan test --filter=TaskStayLinkTest           # US6: stay_id rules, Concierge linking
php artisan test --filter=AvailabilityServiceTest    # FR-015: departed lines free inventory
php artisan test --filter=TenantIsolationTest        # FR-027, SC-009
php artisan test --filter=PermissionAuthorizationTest  # stays.* dataset rows
php artisan test                                     # full suite green
./vendor/bin/pint
```

## Manual walk-through (API)

Headers: `X-API-KEY`, `Authorization: Bearer <admin token>`.

1. **Setup**: `PUT /api/hotel/{id}` with `housekeeping_team_id` and `cleaning_task_category_id`; a room type *Deluxe* with rooms 204 and 205; a confirmed reservation
   arriving today with 2 Deluxe lines, room 204 assigned to the first line.
   `GET /api/reservation/{id}` → two stays, both `expected`.
2. **Arrivals**: `GET /api/stays/arrivals` → both stays; the second shows `room: null`.
3. **Blocked check-in**: `POST /api/reservation/{id}/check-in` with no body → `422`,
   one entry for the unassigned stay ("Assign or name a room first."); nothing changed.
4. **Check-in with assignment**: same call with
   `{"rooms":[{"stay_id":"<second>","room_id":"<205>"}]}` → `200`, both `in_house`,
   rooms 204/205 `occupied`, reservation `checked_in`. If 205 was dirty, `warnings` names it.
5. **Repeat**: the same call again → `200`, unchanged `checked_in_at`, no new audit rows
   (history endpoint, subject type `stay`).
6. **Late-entered time**: `POST /api/stays/{first}/check-out` with
   `{"checked_out_at":"<today 07:00>"}` → `200`, stay `departed` at 07:00, room 204
   `available` + `dirty`, one cleaning task (`GET /api/task?filter[stay_id]=<first>`),
   reservation still `checked_in`. The audit row shows 07:00 and `entered_at`.
7. **Last room out**: `POST /api/stays/{second}/check-out` → reservation `checked_out`,
   second cleaning task.
8. **Availability freed**: repeat 6 on a fresh reservation leaving two days early;
   `GET /api/availability` for the freed nights shows one more sellable Deluxe.
9. **Deprecated path**: on a new confirmed, fully assigned reservation,
   `PUT /api/reservation/{id}` with `{"status":"checked_in"}` → `200`, `Deprecation: true`
   header, stays `in_house`. As an employee without `stays.check_in` → `403`.
10. **Guards**: `PUT` `{"status":"cancelled"}` on a reservation with an in-house room →
    `422`; `DELETE` it → `422`.
11. **Isolation**: as another hotel's admin, `GET /api/stays/{id}` and
    `POST /api/stays/{id}/check-in` → `403`.

## Expected outcomes checklist

- [ ] Stays = live lines for every multi-room reservation (SC-004)
- [ ] No check-in without a confirmed reservation and a free, in-order room (SC-002)
- [ ] One cleaning task per check-out, never two (SC-003, SC-006)
- [ ] Every check-in/out has an audit row with its actor; AI rows show `ai_agent` (SC-007)
- [ ] Arrivals/departures for a 500-room seeded hotel respond in < 2 s (SC-008)
- [ ] Nothing written to `transactions` (FR-029)
