# Data Model: Super Admin Account, Hotel and User Administration

One new table. Existing tables keep their columns. No backfill: the project has test users only.

## New table: `user_invitation_tokens`

Same shape as `password_reset_tokens`, used only by the `invitations` broker. Migration: `<date>_000001_create_user_invitation_tokens_table.php`.

| Column | Type | Notes |
| --- | --- | --- |
| `email` | string, primary key | One pending invitation per email |
| `token` | string | Hashed by the broker |
| `created_at` | timestamp, nullable | Expires after 4320 minutes |

## New enum: `App\Enums\GroupRole`

`owner`, `group_admin`. Used for validation only. `users.group_role` stays an uncast string ([research R9](research.md#r9-group_role)).

## Account (`hotel_groups`, model `HotelGroup`)

| Field | Create | Edit | Rules |
| --- | --- | --- | --- |
| `name` | required | optional | string, max 255 |
| `slug` | required | optional | string, max 255, unique including deleted |
| `country_code` | optional | optional | 2 characters, nullable |
| `default_currency` | optional | optional | 3 characters. Database default `EUR` |
| `default_timezone` | optional | optional | valid timezone. Database default `UTC` |

**Never accepted or returned**: `contract_value_monthly`, `contract_currency`, `settings`, `is_active`.

**Derived**: `hotels_count` (live hotels) and `users_count` ([research R5](research.md#r5-list-endpoints)).

**Lifecycle**: live → soft-deleted, refused while it has a live hotel. No restore in v1, and a deleted account keeps its slug.

## Hotel (`hotels`, model `Hotel`)

Create takes `{account}` from the path. The response is the shared `HotelResource`.

| Field | Create | Edit | Rules |
| --- | --- | --- | --- |
| `name` | required | optional | string, max 255 |
| `slug` | required | optional | See [research R6](research.md#r6-slug-rules-v4) |
| `timezone` | optional | no | Valid timezone. New hotel falls back to the account's |
| `currency` | optional | no | 3 characters. New hotel falls back to the account's |
| `country_code`, `city`, `address`, `email`, `phone`, `whatsapp_number` | optional | no | Nullable, by column type |
| `hotel_group_id` | from path | optional | Live account. See moves below |

**Not accepted**: `owner_id` and every operational setting. The hotel's own admin keeps those.

**Lifecycle**:

- **Create**: gets operational defaults through the existing `created` hook, and has no owner until a first admin is created.
- **Delete**: soft-deleted. Staff lose API reach ([research R4](research.md#r4-soft-deleted-hotels-leave-reach-fr-015a)), and links and tokens stay.
- **Restore**: only when a create in the same account uses its slug. A new `restored` hook on `Hotel` recounts seats.
- **Move**: refused in the [four cases in research R7](research.md#r7-moving-a-hotel-v6).

## User (`users`, model `User`)

Super admins are outside all of this: `404` as a target, and excluded from lists.

| Field | Create | Edit | Access | Rules |
| --- | --- | --- | --- | --- |
| `name` | required | optional | no | string, max 255 |
| `email` | required | optional | no | email, unique |
| `phone_number` | optional | optional | no | Unique, nullable |
| `role` | required | no | optional | `Rule::enum(UserRole)`, with `super_admin` then refused with `403` |
| `hotel_id` | required | no | no | Live hotel. Fixed after creation |
| `hotel_group_id` | optional | no | no | Live account, and must be the hotel's account |
| `group_role` | optional | no | optional | `GroupRole` or null. Admins only |
| `hotel_ids` | no | no | optional | Array of live hotels in the user's account |
| `staff_role_id` | no | no | optional | Employees only, from the home hotel |
| `team_id` | no | no | optional | A team in the home hotel, or null |

`password` is never accepted. A new user gets `Str::password(32)`, which the `hashed` cast stores.

### Access change rules

Applied in one transaction, working out the user's final state first:

1. Final `role` is the sent value, or the current one.
2. Employee: a sent non-null `group_role` returns `422`, and an existing one is cleared.
3. Admin with a final `group_role`: `hotel_group_id` is set from `User::account()`. No account returns `422`.
4. `hotel_ids` sent: every hotel must be in `User::account()`, or `422`. The list replaces `hotel_user` rows for live hotels, with `is_primary` false and `role` from the final role. Rows for soft-deleted hotels stay, so a restore returns access (FR-015a).
5. `role` changed: existing `hotel_user.role` values are updated to match.
6. Not an employee: `staff_role_id` is cleared. A staff role sent for a non-employee returns `422`, and one from another hotel returns `403`.
7. Ownership ([research R8](research.md#r8-ownership-v10)).

### Attachment scopes

| Scope | Matches |
| --- | --- |
| `attachedToAccount($ids)` | `hotel_group_id` in the list, `hotel_id` in a live hotel of those accounts, or a `hotel_user` row in one |
| `attachedToHotel($ids)` | `hotel_id` in the list, a `hotel_user` row, or `hotel_group_id` of those hotels' accounts with a `group_role` |

`attachedToHotel` is the same three routes as the existing `Hotel::admins()`.

## Tenant reach (`User::accessibleHotelIds()`)

| Source | Before | After |
| --- | --- | --- |
| Group role + account | Live hotels only | No change |
| `hotel_user` | Live hotels only | No change |
| `hotel_id` | Included even when deleted | Included only when live |

## Platform event (`event_log`)

Existing table. `hotel_id` is always null.

| `event_type` | Subject | Written by |
| --- | --- | --- |
| `platform.account.created`, `.updated`, `.deleted` | `HotelGroup` | `AccountAdministration` |
| `platform.hotel.created`, `.updated`, `.deleted` | `Hotel` | `HotelAdministration` |
| `platform.user.created`, `.updated`, `.access_changed` | `User` | `UserAdministration` |
| `platform.user.password_reset_sent`, `.access_revoked` | `User` | `UserAdministration` |

Never contains a password, hash or token. A completed public reset writes no event.
