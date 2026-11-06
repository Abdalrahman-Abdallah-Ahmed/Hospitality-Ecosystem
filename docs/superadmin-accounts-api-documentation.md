# Super Admin Accounts API Documentation

For the frontend's super admin area: how a super admin onboards a customer and administers accounts, hotels and users without SQL.

All endpoints below sit under `/api/superadmin`, in `routes/superadmin.php`, and only a super admin can call them.

## Required Headers

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {super_admin_login_token}
Accept: application/json
Content-Type: application/json
```

## Response Format

Responses use the usual wrapper:

```json
{
  "message": "Account created successfully.",
  "code": 201,
  "body": {}
}
```

Field validation failures return Laravel's `{ "message", "errors" }` shape with `422`. Other refusals use the wrapper with `body: null`.

| Status | Meaning |
| --- | --- |
| `401` | No valid API key or login token |
| `403` | The caller isn't a super admin, or the request was refused for a reason given below |
| `404` | Unknown or soft-deleted id in the path, or a super admin as the target user |
| `422` | Invalid fields, or a rule below refused the change |

List endpoints take the usual `search`, `sort` (`-field` for descending), `page`, `per_page` and `filter[column]=value`.

## Onboarding a Customer

1. `POST /accounts` creates the account.
2. `POST /accounts/{account}/hotels` creates each hotel.
3. `POST /users` with `hotel_id` and `role=admin` creates the first admin, who becomes the hotel's owner if it has none.
4. The admin receives an email, sets a password through [`POST /api/reset-password`](auth-api-documentation.md#4-set-a-new-password), and logs in.

The super admin never knows the password. Every write is recorded in the audit trail with the super admin as actor, where no hotel user can read it.

## Accounts

An account is the paying customer (a hotel group). Contract fields are neither returned nor accepted, and can't be filtered, sorted or searched on.

```json
{
  "id": "019f9b37-c260-7abc-9000-1234567890ab",
  "name": "Acme Hotels",
  "slug": "acme",
  "country_code": "EG",
  "default_currency": "EGP",
  "default_timezone": "Africa/Cairo",
  "hotels_count": 2,
  "users_count": 14,
  "created_at": "2026-11-06T10:00:00.000000Z",
  "updated_at": "2026-11-06T10:00:00.000000Z"
}
```

`hotels_count` counts live hotels. `users_count` counts each non-super-admin user once, whether attached by account, a live home hotel or a live membership.

| Method and path | Purpose | Notes |
| --- | --- | --- |
| `GET /accounts` | List | Filter and sort on the fields above except the counts. `search` reads `name` and `slug` |
| `POST /accounts` | Create | `201` |
| `GET /accounts/{account}` | View | |
| `PUT /accounts/{account}` | Edit | Partial |
| `DELETE /accounts/{account}` | Soft-delete | `422` while it has a live hotel |

| Field | Create | Rules |
| --- | --- | --- |
| `name` | required | string, max 255 |
| `slug` | required | string, max 255. Unique, including soft-deleted accounts |
| `country_code` | optional | 2 characters |
| `default_currency` | optional | 3 characters. Defaults to `EUR` |
| `default_timezone` | optional | Valid timezone. Defaults to `UTC` |

## Hotels

Responses use the same hotel object as [the hotel API](hotel-api-documentation.md). Everything else about a hotel stays with its own admin.

| Method and path | Purpose | Notes |
| --- | --- | --- |
| `GET /hotels` | List across accounts | `filter[hotel_group_id]={account}` |
| `POST /accounts/{account}/hotels` | Create or restore | `201` |
| `GET /hotels/{hotel}` | View | |
| `PUT /hotels/{hotel}` | Edit or move | `name`, `slug`, `hotel_group_id` only |
| `DELETE /hotels/{hotel}` | Soft-delete | Its staff can no longer reach it. Links and tokens stay |

### Create fields

`name` and `slug` are required. `timezone`, `currency`, `country_code`, `city`, `address`, `email`, `phone` and `whatsapp_number` are optional. A new hotel without a timezone or currency takes the account's defaults.

A new hotel gets its default teams and categories, and has no owner until its first admin is created.

### Slugs and restoring

| Slug is held by | Result |
| --- | --- |
| A live hotel | `422` |
| A soft-deleted hotel in this account | That hotel is restored with its data, and the fields you sent. The same `id` comes back with `201` |
| A soft-deleted hotel in another account | `422` "The slug has already been taken." The deleted hotel isn't touched |

Editing a hotel's slug to one any hotel holds, including a soft-deleted one, returns `422`.

### Moving a hotel to another account

Users attached only through their home hotel follow it. A move returns `422` while any of these is true, because someone would end up linked across accounts:

- the hotel has memberships;
- a user based at the hotel is attached directly to its account;
- a user based at the hotel has memberships in other hotels;
- the hotel's owner is based at another hotel.

The destination must be a live account.

## Users

Every user who isn't a super admin. Super admins never appear in a list, and return `404` as a target.

Responses use the same user object as [the user API](user-management-api-documentation.md), plus `hotel_ids`, the user's memberships.

| Method and path | Purpose | Notes |
| --- | --- | --- |
| `GET /users` | List | `filter[hotel_group_id]`, `filter[hotel_id]`, `filter[role]`. Account and hotel filters find users attached in any way |
| `POST /users` | Create | `201` |
| `GET /users/{user}` | View | |
| `PUT /users/{user}` | Edit details | `name`, `email`, `phone_number` |
| `PUT /users/{user}/access` | Change access | See below |
| `POST /users/{user}/password-reset` | Send a reset email | See below |
| `POST /users/{user}/revoke-tokens` | Cut off access | See below |

### Creating a user

| Field | Rules |
| --- | --- |
| `name` | required |
| `email` | required, unique |
| `phone_number` | optional, unique |
| `role` | required, `admin` or `employee` |
| `hotel_id` | required, a live hotel. It can't be changed later |
| `hotel_group_id` | optional. The hotel must belong to this account |
| `group_role` | optional, `owner` or `group_admin`, for admins only. Also attaches the user to the hotel's account |

The user gets a random password nobody knows, and an email with a link that lasts 72 hours. A `password` in the request is ignored.

The response includes `reset_email_sent`. When it is `false`, the email failed but the user exists: don't create them again, use `POST /users/{user}/password-reset`.

### The super admin role

`role=super_admin` returns `403` "The super admin role cannot be assigned through the API." on every endpoint, for every caller. A request that is also invalid in another way may get `422` first. Nothing is written either way.

### Changing access

`PUT /users/{user}/access` takes any of the following, and is all or nothing.

| Field | Rules |
| --- | --- |
| `role` | `admin` or `employee` |
| `group_role` | `owner`, `group_admin` or `null`. Admins only. Attaches the user to their account, and `422` if they have none |
| `hotel_ids` | Replaces the user's memberships. A hotel being added must be live and in the user's account, or `422` |
| `staff_role_id` | Employees only, or `422`. From the user's home hotel, or `403` |

- The home hotel stays reachable whether or not it is in `hotel_ids`.
- A membership in a soft-deleted hotel isn't touched, so restoring the hotel returns access.
- Becoming an admin removes the staff role, and makes the user the owner of their home hotel if it has none.
- Becoming an employee removes the account-wide role, and ends ownership of every hotel they own.

### Password email

`POST /users/{user}/password-reset` sends a link that lasts 60 minutes, and cancels any link still pending once it is sent.

| Status | Meaning |
| --- | --- |
| `200` | Sent |
| `429` | A link went out under a minute ago |
| `503` | The email failed. Nothing is recorded, and any link the user already holds still works |

### Revoking access

`POST /users/{user}/revoke-tokens` removes all the user's login tokens and pairing codes and unpairs their WhatsApp device, so the Admin Advisor stops answering them. It succeeds even with nothing to revoke. To lock out a compromised user, call this, then send a reset email.

## Audit

Each successful write records one event in `event_log`, with no hotel attached, so it never appears on a hotel's event log:

| Event | When |
| --- | --- |
| `platform.account.created`, `.updated`, `.deleted` | Account writes |
| `platform.hotel.created`, `.updated`, `.deleted` | Hotel writes. A restore is `.created` with `context.restored = true` |
| `platform.user.created`, `.updated`, `.access_changed` | User writes. An email sent on create isn't recorded separately |
| `platform.user.password_reset_sent`, `.access_revoked` | Reset access |

An edit that changes nothing records no event. A user completing a reset records none, because no super admin acted. Events never contain a password, hash or token.
