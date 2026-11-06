# Quickstart: Validating Super Admin Administration

## Prerequisites

- Postgres running. Don't recreate or drop its volume, because another project shares it.
- `php artisan migrate` run, which adds `user_invitation_tokens`.
- `FRONTEND_URL` set in `.env`, with `.env.example` showing the key.
- For manual checks: `MAIL_MAILER=log`, so emails, and their links, appear in `storage/logs/laravel.log`.

## Automated checks

```bash
php artisan test --parallel --filter=AccountAdministrationTest
php artisan test --parallel --filter=HotelAdministrationTest
php artisan test --parallel --filter=UserAdministrationTest
php artisan test --parallel --filter=PasswordResetTest
php artisan test --parallel --filter=DeletedHotelAccessTest
php artisan test --parallel --filter=EntryPointInventoryTest
php artisan test --parallel --filter=CostVisibilityTest
php artisan test --parallel
./vendor/bin/pint
```

## Scenarios the tests must prove

| Scenario | Expected outcome | Spec |
| --- | --- | --- |
| Create an account, hotel and admin, follow the emailed link, log in, open the hotel | Each step succeeds with no seeder or SQL, and three platform events exist | SC-001 |
| Send `role=super_admin` to all four endpoints, as a tenant admin and as a super admin | `403` every time, and the role is unchanged | SC-002 |
| Give a user a token, pairing code and device, then revoke, and message from that number | Token and code refused, and the reply is the not-paired message | SC-003 |
| Inspect responses, events and mail payloads from create and reset | No password or token, except the link in the email itself | SC-004 |
| Record a platform event on a guest, then read `GET /api/history/guest/{id}` as that hotel's admin | Event absent, and `hotel_id` is null even with a current hotel set | SC-005 |
| Call every new route as a hotel admin and an employee, and without a token or key | Refused, and nothing changes | SC-006 |
| Reuse a deleted slug: hotel in the same account, hotel in another account, hotel edit, account create | Restored with seats recounted, `422`, `422`, `422`. No `500` | SC-007 |
| First link at 71 hours, and at 73 hours. Normal link at 59 and 61 minutes | Works, refused, works, refused. Use `$this->travel()` | Clarification |
| Force a mail failure on create, then resend straight away | `201` with `reset_email_sent: false`, then the resend isn't throttled | FR-032 |
| Force a mail failure on resend | `503` with no event, and the error is reported | FR-032 |
| Move a hotel in each of the four refused cases, then with only home-hotel users | `422` four times, then success with users and seats following | FR-014 |
| Filter, sort and search accounts on contract fields | `422`, `422`, and no match | FR-005 |
| Delete a hotel, then call an endpoint as its staff, with and without `X-Hotel-Id`. Then restore it | None of its data, nothing created in it, and the same token works after restore | FR-015a |
| Demote a hotel's owner, then create a new admin there | The hotel loses its owner, and the new admin owns it | FR-026 |

## Manual end-to-end check

Follow the requests in [the contract](contracts/superadmin-administration.md) in the CUJ 1 order, using the seeded super admin. Read the link from the log, post its token to `POST /api/reset-password`, then log in as the new admin and call `GET /api/hotel/{id}`.
