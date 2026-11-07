# Quickstart: Validating Suspension

## Prerequisites

- Postgres running. Don't recreate or drop its volume, because another project shares it.
- `php artisan migrate` run, which adds the suspension columns.

## Automated checks

```bash
php artisan test --parallel --filter=SuspensionModelTest
php artisan test --parallel --filter=SuspensionAdministrationTest
php artisan test --parallel --filter=SuspensionTest
php artisan test --parallel --filter=SuspensionJobTest
php artisan test --parallel --filter=SuspensionWhatsAppTest
php artisan test --parallel --filter=SuspensionRouteInventoryTest
php artisan test --parallel --filter=JobSuspensionInventoryTest
php artisan test --parallel --filter=PermissionAuthorizationTest
php artisan test --parallel --filter=EntryPointInventoryTest
php artisan test --parallel
./vendor/bin/pint
```

Tests that make several token requests call `forgetGuards()` between them, as `AuthTest` does, so a cached guard can't hide a refused token.

## Scenarios the tests must prove

Expected responses are in [contracts/suspension.md](contracts/suspension.md).

### CUJ 2: Suspend a non-paying account

1. Build an account with two hotels, a signed-in employee and a queued job for each hotel.
2. Suspend it with a reason. Expect the audit event with `hotel_id` null.
3. Check that the old token and a new login both get the `403` with `scope: account`, and that logout still works.
4. Run the queue. Expect no work done and no `failed_jobs` row.
5. Reactivate. Check that the old token works, login works, and skipped jobs weren't replayed.

### CUJ 3: One hotel of a multi-hotel user

With hotels A (default, suspended) and B:

1. Login succeeds, and `user.hotel.id` is B. `GET /api/user` agrees, and the stored `hotel_id` is still A.
2. `GET /api/hotel` lists only B.
3. A dashboard read, a policy-guarded `GET` and a create all act on B, with no header and with `X-Hotel-Id: B`.
4. `X-Hotel-Id: A` gets the `403` with `scope: hotel`.
5. A record of A, requested by id with no header, gets `404`.

### Employees (V3)

1. An employee of A assigned to B, with a role in A, keeps that role's permissions in B.
2. An employee of A with no assignment gets the `403`, even with other active hotels in the account.

### Nothing leaks

1. A stranger requesting a suspended hotel, or naming it in `X-Hotel-Id`, gets no `reason = suspended`.
2. A suspended hotel's owner gets the suspended `403` on `GET /api/hotel/{id}`. An active hotel's owner with no membership gets `200`.
3. No tenant response contains a `suspended_*` field, and tenant `PUT /api/hotel/{id}` can't write one.
4. A super admin reads a suspended hotel through both `api/superadmin` and `GET /api/hotel/{id}`.
5. A user with no hotels isn't told anything. An owner with no hotels in a suspended account is refused.

### Hotel list (V16)

1. An employee with `hotels.view` gets the short record, and `403` without it. `filter[email]`, `sort=email` and a search on an email value reveal nothing.
2. An admin gets the full record, for their own hotels only.

### Jobs

1. Each of the 10 hotel jobs is skipped when its hotel or account is suspended, and runs after reactivation.
2. An import and a voucher email end failed. A proactive message stays `SCHEDULED`. After reactivation, the next `EvaluateProactiveTriggersJob` run sends it if still valid, and doesn't if it is past `valid_until`.
3. A `failed()` handler that throws writes no `failed_jobs` row, and the error is reported.
4. A global document and article still index. A knowledge rebuild reaching a suspended hotel's items still finishes, counting them as failed.
5. Each looping job skips a suspended hotel. Purge keeps its documents, and still removes global ones and those of soft-deleted hotels.

### WhatsApp

Using a mocked sender and an AI fake:

1. A guest gets the fixed reply, with no AI run and no AI usage. It is in Arabic for a guest whose `preferred_language` is `ar`, and otherwise the English text, which equals `UNAVAILABLE_REPLY`.
2. A repeat within 24 hours sends nothing, and its status is `throttled`. After 24 hours, the reply is sent again.
3. A retried send of a decided reply still goes out.
4. STOP is applied and confirmed. An unpaired admin gets the not-paired reply.
5. A paired admin gets the fixed reply, including one with other active hotels whose default is suspended.
6. An admin whose default hotel is soft-deleted, in a suspended account, gets the fixed reply.

### Monitoring

With a DSN set, the scope has `suspended = 'true'` on a blocked request and a blocked job, and `hotel_id` names B on a request moved from A to B.

## Manual check

Against a local server, with a seeded super admin:

```bash
curl -X POST "$APP_URL/api/superadmin/accounts/$ACCOUNT/suspend" \
  -H "X-API-KEY: $API_KEY" -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"reason":"Unpaid invoice"}'

curl "$APP_URL/api/dashboard" \
  -H "X-API-KEY: $API_KEY" -H "Authorization: Bearer $STAFF_TOKEN" -H "Accept: application/json"
```

The second call should return the `403` with `body.reason = "suspended"`. Reactivate with `POST .../reactivate`, and the same call should succeed.
