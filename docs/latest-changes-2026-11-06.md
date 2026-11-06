# Latest changes — 2026-11-06

## Super admin account, hotel and user administration (spec 023)

A super admin can now onboard a customer through the API, without SQL, and lock out a compromised user. Spec: `specs/023-superadmin-administration`. API: [superadmin-accounts-api-documentation.md](superadmin-accounts-api-documentation.md).

### Breaking

- **`POST /api/hotel` and `DELETE /api/hotel/{id}` now return `405` for everyone.** Use `POST /api/superadmin/accounts/{account}/hotels` and `DELETE /api/superadmin/hotels/{id}`. The new create takes the account from the path, and doesn't accept `owner_id`.
- **Nobody can grant `super_admin` through the API.** `role: "super_admin"` on `POST` or `PUT /api/users` returns `403` for every caller, super admins included. The message is now `The super admin role cannot be assigned through the API.`
- **A super admin can't be linked to an account.** `hotel_group_id` or `group_role` on a super admin target in `PUT /api/users/{id}` returns `403`.
- **A super admin can't be demoted through the API.** `role` on a super admin target in `PUT /api/users/{id}` returns `403`, because nothing in the API can grant the role back.
- **`PUT /api/hotel/{id}` ignores `owner_id` and `hotel_group_id`**, for every caller. Use `PUT /api/superadmin/hotels/{hotel}` to move a hotel.
- **A slug a soft-deleted record still holds returns `422`** on the tenant hotel update, and on any other generic create or update whose unique index covers deleted rows. These used to return `500`.
- **List filters check the column's type.** A value the column can't hold, such as `filter[created_at]=abc` or a malformed id, returns `422` on every list endpoint. These used to return `500`. Filtering on a JSON column is refused the same way, and dates must be ISO 8601.
- **Staff can't reach a soft-deleted hotel through the API**, by default or with `X-Hotel-Id`. Their links and tokens are kept, so access returns if the hotel is restored. WhatsApp and scheduled work for that hotel aren't changed.

### Deployment

- **Set `FRONTEND_URL`** to the frontend's address. Password emails link to `{FRONTEND_URL}/reset-password`, and without it they point at `http://localhost:3000`.
- **Run the migration**, which adds `user_invitation_tokens`.

### What the frontend needs to do

- Add a reset page at `/reset-password?token=…&email=…` that posts to `POST /api/reset-password`. Until it exists, new users can't set a password. See [auth-api-documentation.md](auth-api-documentation.md#4-set-a-new-password).
- Move hotel creation and deletion screens to the new addresses.
- Remove "Super admin" from role pickers.
- Build the new account and user administration screens.

### New endpoints

| Method | Address |
| --- | --- |
| GET, POST | `/api/superadmin/accounts` |
| GET, PUT, DELETE | `/api/superadmin/accounts/{account}` |
| POST | `/api/superadmin/accounts/{account}/hotels` |
| GET | `/api/superadmin/hotels` |
| GET, PUT, DELETE | `/api/superadmin/hotels/{hotel}` |
| GET, POST | `/api/superadmin/users` |
| GET, PUT | `/api/superadmin/users/{user}` |
| PUT | `/api/superadmin/users/{user}/access` |
| POST | `/api/superadmin/users/{user}/password-reset` |
| POST | `/api/superadmin/users/{user}/revoke-tokens` |
| POST | `/api/reset-password` (public, API key only) |

### Known limits

- A user's home hotel can't be changed after creation, so moving someone off it still needs SQL.
- An account owner can open the settings of only the hotels they own, not every hotel in the account.
- Password emails are English only, as the welcome email is, because users have no language setting.
- A tenant admin demoting a colleague through `PUT /api/users/{id}` doesn't clear that colleague's account-wide role or hotel ownership. The new `PUT /api/superadmin/users/{user}/access` does.
