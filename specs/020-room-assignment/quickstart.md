# Quickstart: Validating Room Assignment

**Feature**: `specs/020-room-assignment` · **Date**: 2026-10-30

Endpoint shapes: [contracts/room-assignment-api.md](contracts/room-assignment-api.md).
Columns and rules: [data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d      # pgvector:pg17 (ships btree_gist)
php artisan rooms:overlaps                           # must print none, exit 0, before migrating
php artisan migrate
```

If `rooms:overlaps` lists anything, resolve those reservations first (unassign or move one
side); the migration refuses to run while overlaps exist.

## Automated validation

```bash
php artisan test --parallel --filter='RoomAssignment|RoomOverlap|RoomMove|RoomUpgrade|UnassignedArrivals|PermissionAuthorization|TenantIsolation|AdminTool|AiToolInventory|EntryPointInventory'
php artisan test --parallel      # full suite before commit
./vendor/bin/pint
```

## Manual scenarios (dev DB, one hotel, admin token)

Seed: room types Deluxe (rooms 204, 206, 208) and Suite (501); reservations A (Deluxe ×2,
Nov 2 → Nov 5) and B (Deluxe ×1, Nov 4 → Nov 6), both confirmed.

| # | Action | Expected |
| --- | --- | --- |
| 1 | `PUT /reservation/A/rooms` → lines = 204, 206 | `200`; both lines hold rooms; two audit entries |
| 2 | `PUT /reservation/B/rooms` → 204 | `422` "Room 204 is booked for A on overlapping nights." |
| 3 | Same as 2 but B arrives Nov 5 | `200` (back-to-back allowed) |
| 4 | `PUT /reservation/A/rooms` swap 204 ↔ 206 in one call | `200` |
| 5 | Two terminals send step 2's clash at once against an unassigned 208 for A and B | one `200`, one `422` |
| 6 | `PUT /reservation/B` moving dates so B overlaps A in 204 | `422` naming 204 and A |
| 7 | `PUT /reservation/B/rooms` → 501 without `upgrade` | `422` type message |
| 8 | Same with `upgrade: true` | `200`; `is_upgrade: true`; `GET /availability` shows Suite −1, Deluxe +1 on Nov 4–5 |
| 9 | `GET /reservation/unassigned-arrivals?from=2026-11-01&to=2026-11-07` before step 1 | A's two lines and B's line, `is_late: false` |
| 10 | Check A's 204 line in, then `POST /stays/{stay}/move-room` → 208, reason "AC broken" | `200`; stay in 208; 208 occupied; 204 dirty with cleaning task; `room_moved` audit |
| 11 | `PUT /reservation/A` changing the in-house line's room | `422` "…move the guest with the room-move action." |
| 12 | Employee without a role calls 1, 9, 10 | `403` each |
| 13 | Admin of another hotel calls 1 on A | `404` |
| 14 | Admin AI: "put RES-A's second room in 206" | tool goes through the same rules; a clash comes back as the clash message |
| 15 | `INSERT` a clashing `reservation_rooms` row directly in `psql` | rejected by `reservation_rooms_no_overlap` |

## Done when

- All of the above hold, the full suite is green on Postgres, Pint is clean.
- `docs/reservations-api-documentation.md`, `docs/stays-api-documentation.md`,
  `docs/staff-roles-api-documentation.md` and `docs/latest-changes-2026-10-30.md` describe
  the changes; `frontend-changes.md` hands the slice to `ecosystem-frontend`.
