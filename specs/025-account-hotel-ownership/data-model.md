# Data Model: Account-Level Hotel Ownership

One migration, `2026_11_07_000002_move_hotel_ownership_to_account.php`, or the next number on the day. No new tables and no backfill. An existing dev database is freshly migrated: an old `group_admin` value would throw on read once the column is cast, and two owners in an account would fail the index.

## `hotels`

| Change | Detail |
| --- | --- |
| Drop `owner_id` | With its foreign key, using `dropConstrainedForeignId`. A hotel belongs to its account through the existing `hotel_group_id` |

**Model**: remove `owner_id` from `$fillable`, and remove `owner()`. `HotelFactory` and `HospitalitySeeder` drop the attribute.

## `users`

No new columns.

| Change | Detail |
| --- | --- |
| Add unique index `users_one_owner_per_account` | On `(hotel_group_id, group_role)`. Rows with either value null never conflict |

**Model**: cast `group_role` to `App\Enums\GroupRole`, which now has one case, `OWNER = 'owner'`. Add `isOwner()`, true when both `group_role` and `hotel_group_id` are set. Users have no soft deletes, so a deleted owner frees the account at once.

**Rule the column can't express**: an owner must be an admin who belongs to the account. `UserAdministration` applies this, and it is the only writer apart from registration.

## `agent_conversations`

| Change | Detail |
| --- | --- |
| Add `hotel_id` | Nullable uuid, foreign key to `hotels`, null on delete. Set on web advisor conversations. Null on WhatsApp and guest conversations |

## Who reaches what

`User::accessibleHotelIds()` doesn't change. `TenantContext` narrows it per request: for suspension, as spec 024 does, and then, for admins, to the one hotel the request works in.

| User | Reachable hotels | What they can do there |
| --- | --- | --- |
| Account owner | Every live hotel in the account, one per request | Everything, in every tenant module |
| Other admin | Home hotel and member hotels, one per request | Everything, except create, promote, update or delete another admin. They can update themselves |
| Employee | Home hotel and member hotels together, for lists | Their permissions, on home hotel records only |
| Super admin | Unrestricted | Unchanged |

## Ownership states

| From | Action | To | Account link |
| --- | --- | --- | --- |
| New customer | `POST /api/register` | Owner | Set to the new account |
| Admin | `group_role: owner`, on create or access | Owner, or `422` if the account has one | Set from `User::account()` |
| Owner | `group_role: null`, through access | Admin | Cleared |
| Owner | `role: employee`, through access | Employee | Cleared |
| Owner | Super admin changes `role` through `/api/users/{id}` | Employee | Cleared |
| Owner | Super admin deletes them through `/api/users/{id}` | Row gone | Gone |
| Owner | Anyone else through `/api/users/{id}` | Unchanged, `403` | Unchanged |
| Employee | Promoted to admin by an owner or super admin | Admin, never owner | Unchanged |
| Employee | Promotion by another admin | Unchanged, `403` | Unchanged |

A user with an account link and no role reaches only their home and member hotels. The link alone gives nothing extra, and blocks moving their home hotel until `group_role: null` clears it.

## Hotel moves

A move writes only `hotels.hotel_group_id`, never a user. So it can't create a second owner, and the source owner's reach ends with no further step, because `accessibleHotelIds()` reads the hotel's current account.
