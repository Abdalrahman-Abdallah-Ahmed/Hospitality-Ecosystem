# Latest changes — 2026-11-07

## Account and hotel suspension (spec 024)

A super admin can now stop an account or one hotel completely, for a customer who hasn't paid, and restore it later with its data kept. Spec: `specs/024-superadmin-suspension`. API: [superadmin-accounts-api-documentation.md](superadmin-accounts-api-documentation.md#suspending-a-customer).

### Breaking

- **Any hotel API route can now return a new `403`**, as can `POST /api/login`. Only `POST /api/logout` is exempt.

  ```json
  { "message": "This account has been suspended.", "code": 403, "body": { "reason": "suspended", "scope": "account" } }
  ```

  `scope` is `account` or `hotel`. Check `body.reason`: other `403` responses don't have it.
- **`user.hotel_id` can differ from `user.hotel.id`** on login and `GET /api/user`, when the user's default hotel is suspended and they have another. Read `user.hotel`.
- **Superadmin hotel responses gain four fields** (`suspended_at`, `suspended_by`, `suspended_reason`, `is_suspended`), and account responses three.

### Changed

- **`GET /api/hotel` opens to admins and to employees holding the new `hotels.view` permission.** It was super admin only. Each gets only the hotels they can reach and that aren't suspended. Employees get a short record (`id`, `name`, `slug`, `timezone`, `currency`, `city`), and can search, filter and sort only on those. A super admin's list is unchanged.
- **WhatsApp**: a suspended hotel's senders get a fixed reply at most once per 24 hours per hotel and phone number, with no AI run. STOP still works.
- **Background jobs** for a suspended hotel don't run, and leave no `failed_jobs` row. Imports, voucher emails and guest notices in progress end failed. Skipped work isn't replayed.

### New endpoints

- `POST /api/superadmin/accounts/{account}/suspend` and `/reactivate`
- `POST /api/superadmin/hotels/{hotel}/suspend` and `/reactivate`
- `filter[suspended]` on `GET /api/superadmin/accounts` and `/hotels`

### Deployment

- **Run the migration**, which adds three columns to `hotel_groups` and `hotels`.

### What the frontend needs to do

- Show a suspended screen on the new `403`, on login and on every other request. The messages are English only, so translate from `reason` and `scope`.
- Read the user's hotel from `user.hotel`, not `user.hotel_id`.
- Offer multi-hotel users their hotels from `GET /api/hotel`, and handle the short record for employees.
- Add `hotels.view` to the role editor. `GET /api/permissions` already lists it.
- Add suspend and reactivate actions, a reason field, the suspended filter, and suspension details to the super admin account and hotel screens.
