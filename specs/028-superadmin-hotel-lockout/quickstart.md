# Quickstart: Validating Stage 6

**Feature**: [spec.md](spec.md) · **Contract**: [contracts/api.md](contracts/api.md)

## Prerequisites

- The Postgres container is up: `docker compose -f .postgres/compose.yaml up -d`. It shares a volume with another project, so never drop it.
- No `.env.testing` exists; `phpunit.xml` forces the test database and clears the Sentry DSN. Run tests with no env prefix.

## Automated checks

```bash
php artisan test --parallel --filter=SuperAdminDeniedOnTenantRoutesTest   # the generic walk (FR-016)
php artisan test --parallel --filter=UserAdministrationTest               # delete endpoint (FR-014, FR-018)
php artisan test --parallel --filter=HotelAccessOptionsTest               # staff role and team lists (FR-012, FR-018)
php artisan test --parallel                                               # everything, incl. rewritten tests (FR-017)
./vendor/bin/pint --test
```

Expected: all green. `SuperAdminDeniedOnTenantRoutesTest` checks more than 100 route/method pairs and names any route that answers anything but 403.

## Manual walk-through (dev database, seeded test accounts)

`php artisan migrate --seed` creates test accounts at every access level (see `AccessLevelSeeder`).

1. Log in as the seeded super admin: `POST /api/login` → 200, a token.
2. `GET /api/user` → 200, role `super_admin`.
3. `GET /api/guest` → **403** `Super admins use /api/superadmin.`
4. `GET /api/guest/00000000-0000-0000-0000-000000000000` → **403**, not 404.
5. `GET /api/superadmin/hotels` → 200; pick a hotel id `H`.
6. `GET /api/superadmin/hotels/H/staff-roles` and `/teams` → 200, only `H`'s records.
7. `POST /api/superadmin/users/{hotelAdmin}/impersonate` → a session token; `GET /api/guest` with it → 200, that admin's view.
8. `DELETE /api/superadmin/users/{owner}` → **409**. `PUT /api/superadmin/users/{owner}/access` with `{ "group_role": null }`, then delete again → 200.
9. `POST /api/logout` with the super admin token → 200.

## Scans that must stay clean

```bash
# Every remaining reference must be a "keep" in plan.md's inventory.
grep -rn "isSuperAdmin\|SUPER_ADMIN" app
# No hotel endpoint doc still tells a super admin to send hotel_id.
grep -rln "super admin must send\|super admin sees all\|super admin names" docs
```
