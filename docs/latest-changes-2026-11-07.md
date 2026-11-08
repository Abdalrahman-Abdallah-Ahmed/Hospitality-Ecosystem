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

## Account-level hotel ownership (spec 025)

Ownership moves from the hotel to the account. An account has at most one owner, who works in every hotel of it, and admins work in one hotel per request across every module. Spec: `specs/025-account-hotel-ownership`. API: [hotel-api-documentation.md](hotel-api-documentation.md), [user-management-api-documentation.md](user-management-api-documentation.md), [auth-api-documentation.md](auth-api-documentation.md#choosing-a-hotel-x-hotel-id).

### Breaking

1. **`owner_id` and `owner` are gone from every hotel**, in `/api/hotel`, `/api/superadmin/hotels`, and the `hotel` nested in login, `GET /api/user`, `/api/users` and registration. `filter[owner_id]` and `sort=owner_id` return `422`.
2. **`group_admin` is gone.** `group_role` is `owner` or `null`, and an account can have one owner. A second returns `422` with `This account already has an owner.`
3. **Nobody becomes an owner automatically.** Creating or promoting an admin, even an account's first, no longer gives them anything beyond their own hotels. Send `group_role: owner` through the super admin user endpoints.
4. **`X-Hotel-Id` naming a hotel the user can't reach is a plain `403`**, where it used to be ignored. It applies to admins and employees, and to every route except logout, `GET /api/hotel`, `POST /api/connect` and `GET /api/check-paired`.
5. **An admin's request works in one hotel**: the one in `X-Hotel-Id`, or their default. Lists, the dashboard, reports and new records follow it, and a record in another hotel returns `404`. **Admins with several hotels no longer get lists or dashboards combining them.**
6. **Admins can work in every hotel they reach** by choosing it, including hotels that used to refuse them, and any admin of a hotel can open and edit its record. `GET /api/hotel` keeps listing all of them.
7. **`/api/users` ignores `hotel_group_id` and `group_role`** for every caller, super admins included. **Only the account owner can create or promote an admin, or edit or delete another admin**, otherwise `403`. The same goes for an employee who is a member of a hotel the acting admin can't reach. The owner can't change their own role or delete themselves. A super admin changing an owner's role ends the ownership.
8. **Registration makes the new user their account's owner**, so `user.group_role` is `owner`. Moving that customer's hotel to another account needs `group_role: null` for them first.
9. **An advisor conversation can't be continued from another hotel.** A `conversation_id` started in another hotel returns `404` with `Conversation not found.`

### Changed

- `PUT /api/superadmin/users/{user}/access` with `group_role: null`, or demoting an owner, also clears `hotel_group_id`.
- Moving a hotel no longer checks a per-hotel owner. The account's owner stops reaching a hotel that moves out, and the destination's owner reaches it at once.

### Deployment

- **Run the migration**, which drops `hotels.owner_id`, adds a unique index for one owner per account, and adds `agent_conversations.hotel_id`.
- **Freshly migrate dev databases.** An old `group_admin` value would fail to read, and an account with two owners would stop the index being created. There is no backfill.

### What the frontend needs to do

- Remove `owner_id` from the hotel types, the hotel details view and `HOTEL_SORTABLE_COLUMNS`, and `group_admin` from the role list and its label.
- Add a hotel switcher that sends `X-Hotel-Id`, built from `GET /api/hotel`, and clear the choice on a plain `403`. Without it, admins can't work in a second hotel.
- Correct the wording about hotel ownership in the access and create-user forms: nobody becomes an owner automatically.
- Hide edit and delete on other admins' rows, and the admin choice in the role field, unless the signed-in user has `group_role: owner`.
- Start a new advisor chat when the hotel changes.
