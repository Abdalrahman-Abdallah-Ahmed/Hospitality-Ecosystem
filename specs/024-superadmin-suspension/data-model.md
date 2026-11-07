# Data Model: Account and Hotel Suspension

Three new columns on two existing tables. No new tables. No backfill: the project has test users only.

## New columns on `hotel_groups` and `hotels`

Migration: `2026_11_07_000001_add_suspension_to_hotel_groups_and_hotels.php`.

| Column | Type | Notes |
| --- | --- | --- |
| `suspended_at` | timestampTz, nullable, indexed | Set means suspended in its own right |
| `suspended_by` | uuid, nullable, foreign key to `users`, null on delete | The super admin who suspended it |
| `suspended_reason` | text, nullable | Required when suspending, max 1000 characters. Shown only to super admins |

**Model rules**: `suspended_at` is cast to a datetime. None of the three is in `$fillable`, so `GenericUpdateRequest` can't derive a rule for them. Only `App\Services\Superadmin\Suspension` writes them, with `forceFill`. `is_active` is not read or written by this feature.

## Suspension state

Each level holds its own state, and neither changes the other.

| Hotel's own `suspended_at` | Account's `suspended_at` | Counts as suspended | Response `scope` |
| --- | --- | --- | --- |
| empty | empty | No | none |
| set | empty | Yes | `hotel` |
| empty | set | Yes | `account` |
| set | set | Yes | `account` |

### Transitions

| From | Action | To | Otherwise |
| --- | --- | --- | --- |
| Not suspended | Suspend, with a reason | Columns set, event written | `422` if already suspended in its own right, keeping the recorded values |
| Suspended | Reactivate | Columns cleared, event written | `422` if not suspended in its own right, with no event |

- A hotel can be suspended while its account is, and stays suspended after the account returns.
- Reactivating a hotel inside a suspended account clears the hotel's columns, and it still counts as suspended.
- A hotel moved between accounts keeps its own state and takes the new account's.
- A restored hotel keeps its own state.

## Audit events

Written through `EventLogger::recordPlatform`, so `hotel_id` is null and hotel staff can't see them.

| `event_type` | Subject | `context` |
| --- | --- | --- |
| `platform.account.suspended` | `HotelGroup` | `reason` |
| `platform.account.reactivated` | `HotelGroup` | |
| `platform.hotel.suspended` | `Hotel` | `reason` |
| `platform.hotel.reactivated` | `Hotel` | |

## Permission

`Permission::HOTELS_VIEW = 'hotels.view'`, grouped with the others. `group()` and `action()` derive from the value, and `GET /api/permissions` reads the enum, so the role editor gets it with no other change. It isn't in `employeeDefaults()`.

## Fixed-reply limit

A cache entry, not a table: `suspended-reply:{hotel id}:{phone}`, for 86400 seconds, written with `Cache::put` after the reply has gone out. When the sender has only an account, the account id takes the hotel id's place. Losing it, or a crash just after the send, costs at most one extra reply.

## Inbound message status

No new status. A repeat message within the limit gets the existing `THROTTLED`, with `reply_text` null. Its enum comment changes from "per-sender rate limit" to cover this as well.

## New code types

| Type | Purpose |
| --- | --- |
| `App\Contracts\HasHotel` | `hotelId(): ?string` on each job that acts on one hotel |
| `App\Enums\SuspensionScope` | `account` or `hotel`, the suspended response's `body.scope` |
| `App\Exceptions\HotelSuspended` | `RuntimeException` passed to a blocked job's `failed()` |
| `App\Support\Suspension\HotelAccess` | The shared calculation for login and the middleware |
| `App\Support\Suspension\SuspendedResponse` | Builds the `403`, using `lang/en/suspension.php` |
| `App\Support\Suspension\ImpersonatedRead` | Stays false until stage 5 |
