# Quickstart: validating Housekeeping and Maintenance

**Feature**: [spec.md](spec.md) · **API**: [contracts/housekeeping-maintenance-api.md](contracts/housekeeping-maintenance-api.md) ·
**Model**: [data-model.md](data-model.md)

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d   # Postgres + pgvector (the tests need it)
php artisan migrate                              # runs the five 2026_10_04_00000{1..5} migrations
```

The suite runs against `Hospitality_Ecosystem_testing` (see `phpunit.xml`). There is no
`.env.testing`, so never run `artisan --env=testing` against the dev DB.

Before deploying, run migrations 2 and 5 on a production snapshot and read the release
report (R19). It lists rooms left occupied instead of out of order, and older duplicate
cleaning tasks.

## Automated validation

```bash
php artisan test --filter=RoomStatusSplitTest              # FR-037–039, US9: mapping, check constraints, 422 on old values, conflict report
php artisan test --filter=OperationalDefaultsTest          # FR-040–042, US9: new hotel, backfill, keeps admin choices, idempotent, reuse by name
php artisan test --filter=HousekeepingLifecycleTest        # US1, FR-001–003, FR-007/008: start/complete/cancel/reopen, stale task, occupied room
php artisan test --filter=HousekeepingInspectionTest       # US2, FR-004–006: pass/fail, re-clean, readiness, toggling the setting
php artisan test --filter=HousekeepingStartOfDayTest       # US3, FR-011–014: stay-over tasks, departing today, out of order, rerun, time zones
php artisan test --filter=OutOfOrderTest                   # US5, FR-016–021: take/patch/return, occupied refusal, affected lines, availability
php artisan test --filter=RoomIssueReportTest              # US4, FR-022–025: maintenance task, out of order with/without permission, idempotent
php artisan test --filter=TaskNotificationTest             # US6, FR-026–029: team fan-out, reassign, no duplicates, routing alert once a day
php artisan test --filter=HousekeepingBoardTest            # US7, FR-030/031, SC-008: grouping, filters, 500-room timing
php artisan test --filter=HousekeepingManualStatusTest     # US8, FR-009/010: reason, audit, tasks untouched
php artisan test --filter=HousekeepingConcurrencyTest      # FR-035, SC-003: double start, check-out vs out of order, double start-of-day
php artisan test --filter=CheckOutTest                     # reuse of a stay-over task, now through HousekeepingService
php artisan test --filter=AvailabilityServiceTest          # out_of_order counted at once
php artisan test --filter=TenantIsolationTest              # FR-034, SC-009
php artisan test --filter=PermissionAuthorizationTest      # rooms.update_housekeeping_status, rooms.set_out_of_order rows
php artisan test                                           # full suite green
./vendor/bin/pint
```

## Manual walk-through (API)

Headers: `X-API-KEY`, `Authorization: Bearer <admin token>`.

1. **Defaults**: `GET /api/hotel/{id}`. All five default ids are filled.
   `GET /api/team` shows *Housekeeping* and *Maintenance*. Add a housekeeper user to the
   Housekeeping team.
2. **Check-out → clean**: Check a guest out of room 204 (`POST /api/stays/{id}/check-out`).
   - Room 204 is `dirty`.
   - One cleaning task exists, with `cleaning_reason: check_out`.
   - The housekeeper got one email.

   Then:
   - `PUT /api/task/{id}` with `status=in_progress` → the room is `cleaning`.
   - `status=completed` → the room is `clean`, with `ready: true`.
3. **Inspection**: `PUT /api/hotel/{id}` with `inspection_required=true`, then repeat
   step 2.
   - The room is `clean`, with `ready: false`, and an inspection task exists.
   - `POST /api/task/{inspection}/inspection` with `result=fail` and a note → the room is
     `dirty`, and a `re_clean` task exists.
   - Clean it again, then pass the inspection → the room is `inspected`.
4. **Issue → out of order**: On the open cleaning task, `POST /api/task/{id}/issues` with
   `room_unsellable=true`.
   - A maintenance task exists, assigned to the Maintenance team.
   - The room is `out_of_order`.
   - `GET /api/availability` for that room type shows one fewer room.
5. **Repair → return**: Complete the maintenance task. The response has
   `room_ready_to_return: true`, and the room is still out of order. Then
   `POST /api/room/{id}/return-to-service` → the room is `available` + `dirty`, with a new
   cleaning task.
6. **Start of day**: With a guest in-house for 3 nights, run
   `php artisan schedule:test` (pick the start-of-day job) or dispatch
   `StartHousekeepingDayJob`.
   - The room is `dirty`, with one `stay_over` task.
   - Run it again → nothing changes.
7. **Board**: `GET /api/housekeeping/board`. The counts match the steps above.
   `GET /api/maintenance/tasks` lists the repair task.
8. **Old values**: `PUT /api/room/{id}` with `status=maintenance` → `422`. The message
   names `POST /room/{id}/out-of-order`.

## Expected outcomes

Every step leaves one audit entry per change in `event_log`, with the actor and cause
(SC-007). There is no second cleaning or inspection task for any room (SC-003), and no one
receives a duplicate email (SC-006).
