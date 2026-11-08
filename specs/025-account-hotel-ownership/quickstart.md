# Quickstart: Validating Account Ownership

## Prerequisites

- Postgres running. Don't recreate or drop its volume, because another project shares it.
- The dev database freshly migrated and seeded with this app's own commands. The index and the cast can't run over old `group_admin` values or two owners in one account. See [data-model.md](data-model.md).

## Automated checks

```bash
php artisan test --parallel --filter=AccountOwnershipTest
php artisan test --parallel --filter=MultiHotelAccessTest
php artisan test --parallel --filter=SuspensionRouteInventoryTest
php artisan test --parallel --filter=CrossTenantRouteTest
php artisan test --parallel --filter=EntryPointInventoryTest
php artisan test --parallel
./vendor/bin/pint
```

## Scenarios the tests must prove

Expected responses are in [contracts/account-ownership.md](contracts/account-ownership.md).

### Finding 3: the list and the record agree

Account with hotels A and B, and an owner based at A. `GET /api/hotel` lists both, and `GET`, `PUT` and `PATCH /api/hotel/{B}` are `200`. Suspend B: those requests become the suspended `403`, and B leaves the list. As an admin of another account, the same requests are a plain `403` with no `body.reason`.

### Working in a second hotel

Add an admin based at A and a member of B, and a hotel C.

1. As the owner with `X-Hotel-Id: C`, open a room in C: `200`. Without the header, the same room is `404`.
2. As the owner with `X-Hotel-Id: C`, create a team, list rooms, then read the dashboard. The team belongs to C, and the list and dashboard show only C. With no header, all of them use A.
3. As the owner with `X-Hotel-Id: A`, list hotels: all three. Update hotel C's own record with C's housekeeping team: `200`.
4. As the admin, do the same in B, then open a room in C: refused. Send any request with `X-Hotel-Id: C`: plain `403`, and nothing is created.
5. As the admin with B chosen, update a room in B, linking a room type from A: refused.
6. As the admin with B chosen, email a voucher for a B reservation: the request row belongs to B.
7. As an employee based at A and a member of B, open a room in B: `403`.
8. As an admin with `X-Hotel-Id: B`, a paired WhatsApp device based at A still shows as paired.

### One owner

1. Assign an owner, then assign another admin: `422`, and nothing changes.
2. Send `owner` for the current owner: `200`, with no event.
3. Create a user as a second owner: `422`, no user, no email.
4. Create a user with an already-used email while the account has no owner, by forcing past validation: the duplicate is not reported as a second owner.
5. Delete the owner, and assign another: `200`.
6. Send `group_admin` to either super admin endpoint: `422`.

### Registration and moves

1. Register: the response shows `group_role: owner` and the new account. Add a hotel to the account, and open it as the new user.
2. Move the first hotel: `422`.
3. Send `group_role: null` for the customer: `hotel_group_id` is null, and they still open their hotel.
4. Move it: `200`, and `account()` is the destination. They aren't its owner.
5. Assign them as owner where the destination already has one: `422`.

### Only the owner manages other admins

As an admin who isn't the owner: `PUT` a new password or role for another admin or the owner, and `DELETE` them, are each `403`. `GET` is allowed, and editing themselves or an employee works. Creating a user with `role: admin`, or promoting an employee, is `403`, and creating an employee works. As the owner, creating, promoting, editing or deleting another admin works, changing their own name works, and changing their own role or deleting themselves is `403`. As a super admin, changing the owner's role clears `group_role` and `hotel_group_id`, and the employee then reaches only their home hotel.

### Advisor and WhatsApp

1. Start an advisor chat with hotel A selected. Continue it with `X-Hotel-Id: B`: `404`.
2. A sender on WhatsApp who owns several hotels still acts in their default hotel, whatever their last web request chose.

## Manual check

With the API running, log in as a registered customer who has a second hotel, and repeat "Working in a second hotel" with an API client. The frontend can't do this until its hotel switcher exists, because it never sends `X-Hotel-Id`.
