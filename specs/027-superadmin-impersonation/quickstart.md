# Quickstart: Validating Impersonation

## Prerequisites

- Postgres running. Don't recreate or drop its volume, because another project shares it.
- One new migration. `php artisan test` applies it to the test database. Check `DB_DATABASE` before migrating by hand, because `--env=testing` reaches the dev database.

## Automated checks

```bash
php artisan test --parallel --filter=ImpersonationTest
php artisan test --parallel --filter=AccessGuardTest
php artisan test --parallel --filter=EntryPointInventoryTest
php artisan test --parallel --filter=CrossTenantRouteTest
php artisan test --parallel --filter=SuspensionTest
php artisan test --parallel --filter=EventLogTest
php artisan test --parallel --filter=PlatformAuditTest
php artisan test --parallel
./vendor/bin/pint
```

`SuspensionTest`, `EventLogTest` and `PlatformAuditTest` must pass with no edits: a request without an impersonation token behaves as it did. The one allowed exception is a test that reads a super admin actor as a hotel user, which T025 must explain.

## Scenarios the tests must prove

Each maps to acceptance scenarios in [spec.md](spec.md). Shapes are in [contracts/impersonation.md](contracts/impersonation.md).

| Scenario | Expected |
| --- | --- |
| Start for an admin | `token`, `token_id`, `expires_at` 30 minutes ahead, and a start event with `hotel_id = null` |
| Start for a super admin | `422`, no token, no event |
| Employee's session on allowed and denied endpoints | `200` and `403` by their staff role |
| Token at 29:59, then at 30:01 | Works, then `401` with no event |
| End by id | Token `401`, one event naming the caller |
| End again, a login token's id, an expired id, a deleted user's id | `404`, no event |
| `end-impersonation` with two sessions | `ended: 2`, two events. The login token works and the device stays paired |
| Logout with the token | Event with `hotel_id = null`, super admin actor, `impersonated_user_id` set |
| Create a guest with the token | Actor is the super admin, with `impersonated_user_id` set |
| Advisor tool write during a session | Same, with `actor_kind = ai_agent` |
| Same write with the user's own token | Actor is the user, with no new context key |
| Update a team, hotel settings or a policy with the token | One event with `changed_fields` and no values |
| Same with the user's own token | No event |
| Every refused write, including `PUT /users/{id}` with `password` | `403`, nothing changed, and the old password still logs in. Unknown ids get `403` too |
| Token on any `api/superadmin` route | `403` |
| History row with a super admin actor, read by hotel staff | `actor` is id and name, with no `ip` in `context` |
| The same row read by a super admin | Full row |
| Suspended only hotel | `GET` returns data, `POST` gets the suspended `403` |
| Suspended default A, active B | `GET` reads A. `POST` with no header creates in B. `PUT` on A's record with `X-Hotel-Id: B` is `404`. `PUT /hotel/{A}` with that header is the suspended `403`. `POST` in B succeeds |
| Sentry with a DSN | Both tags on a session's request, and neither on the next normal request |
| Prune | Removes tokens expired over an hour ago, and keeps running sessions |

## By hand, once

With the app running, as a super admin:

1. `POST /api/superadmin/users/{id}/impersonate` for a hotel admin, and copy the token.
2. `GET /api/user` with that token. The `impersonation` object should name you.
3. Create a guest with that token, then read the guest's `GET /api/history/guest/{id}`. The actor should show only your id and name.
4. `PUT /api/users/{id}` with a `password`. It should return `403`.
5. `POST /api/logout`, then repeat step 2. It should return `401`.
