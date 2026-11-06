# API Contract: Super Admin Administration

All responses use `{ message, code, body }`. Every `/api/superadmin` route has the stack `api.key → auth:sanctum → throttle:api → tenant → super_admin`.

Common refusals on those routes: `401` with no valid API key or token, `403` for anyone but a super admin, `404` for an unknown or soft-deleted id in the path, `422` for invalid fields. Field rules are in [data-model.md](../data-model.md).

## Accounts

`AccountResource`: `id`, `name`, `slug`, `country_code`, `default_currency`, `default_timezone`, `hotels_count`, `users_count`, `created_at`, `updated_at`.

| Method and path | Success | Other outcomes |
| --- | --- | --- |
| `GET /api/superadmin/accounts` | `200`, paginated | `422` on a filter or sort outside the [allowed columns](../research.md#r5-list-endpoints) |
| `POST /api/superadmin/accounts` | `201` | `422` when the slug is held by any account, including a deleted one |
| `GET /api/superadmin/accounts/{account}` | `200` | |
| `PUT /api/superadmin/accounts/{account}` | `200` | `422` on a slug held by another account |
| `DELETE /api/superadmin/accounts/{account}` | `200`, soft-deleted | `422` while it has a live hotel |

Contract fields in a request are ignored, and never appear in a response.

## Hotels

Responses use the shared `HotelResource`.

| Method and path | Success | Other outcomes |
| --- | --- | --- |
| `GET /api/superadmin/hotels` | `200`, paginated; `filter[hotel_group_id]` | `422` on a malformed id |
| `POST /api/superadmin/accounts/{account}/hotels` | `201`, new or restored | `422` "The slug has already been taken." for a live slug, or a deleted hotel in another account |
| `GET /api/superadmin/hotels/{hotel}` | `200` | |
| `PUT /api/superadmin/hotels/{hotel}` | `200`; `name`, `slug`, `hotel_group_id` | `422` on a slug held by any hotel, or a [refused move](../research.md#r7-moving-a-hotel-v6) |
| `DELETE /api/superadmin/hotels/{hotel}` | `200`, soft-deleted | |

## Users

Responses use `UserResource` plus `hotel_ids`, the user's memberships. Tenant responses don't include `hotel_ids`. A super admin target returns `404` on every route with `{user}`.

| Method and path | Success | Other outcomes |
| --- | --- | --- |
| `GET /api/superadmin/users` | `200`, paginated; `filter[hotel_group_id]`, `filter[hotel_id]`, `filter[role]` | `422` on a malformed id, or a filter or sort on a secret column |
| `POST /api/superadmin/users` | `201`, with `reset_email_sent` `true` or `false` | `403` for `role=super_admin`; `422` when `hotel_id` is missing, the hotel and account differ, or `group_role` is sent for an employee |
| `GET /api/superadmin/users/{user}` | `200` | |
| `PUT /api/superadmin/users/{user}` | `200`; `name`, `email`, `phone_number` | `422` on a taken email or phone |
| `PUT /api/superadmin/users/{user}/access` | `200`; `role`, `group_role`, `hotel_ids`, `staff_role_id` | `403` for `role=super_admin`, or a staff role from another hotel; `422` for a [refused change](../data-model.md#access-change-rules) |
| `POST /api/superadmin/users/{user}/password-reset` | `200`, email sent, any pending link replaced | `429` within a minute of the last link; `503` when the send fails, with no event |
| `POST /api/superadmin/users/{user}/revoke-tokens` | `200`, also with nothing to revoke | |

**Refused role message**: "The super admin role cannot be assigned through the API." A request that is also invalid in another way may get `422` first. Nothing is written either way.

## Public reset

`POST /api/reset-password`, with `api.key` and `throttle:login`, and no login.

| Field | Rules |
| --- | --- |
| `token` | required, string |
| `email` | required, email |
| `password` | required, confirmed, `Password::defaults()` |

| Outcome | Status |
| --- | --- |
| Password set, link used up, tokens and device cleared, no platform event | `200` |
| Invalid or expired token, or unknown email, all with the same message | `422` |
| Too many attempts | `429` |

## Reset email link

`{FRONTEND_URL}/reset-password?token=…&email=…`, for both kinds of link. The frontend posts to `POST /api/reset-password`.

## Changes to tenant endpoints

| Endpoint | Change |
| --- | --- |
| `POST /api/hotel`, `DELETE /api/hotel/{hotel}` | Removed, now `405` |
| `POST /api/users`, `PUT` or `PATCH /api/users/{user}` | `role=super_admin` returns `403` for every caller, with the message above |
| `PUT` or `PATCH /api/users/{user}` | `403` when a super admin target is sent `hotel_group_id` or `group_role` |
| Any hotel endpoint | A soft-deleted hotel can't be reached, by default or with `X-Hotel-Id` |
