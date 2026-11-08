# Data Model: Super Admin Platform Dashboard

No new tables or columns, and no migration. This feature reads existing data and caches the result.

## What each figure reads

| Figure | Source | Records included | Date used |
| --- | --- | --- | --- |
| `accounts` | `hotel_groups` | Not soft-deleted, including those with no hotels | None: current state |
| `hotels` | `hotels` | Not soft-deleted | None: current state |
| `users` | `users`, `hotels`, `hotel_user`, `hotel_groups` | `role` isn't `super_admin` | None: current state |
| `signups.accounts` | `hotel_groups` | All, including soft-deleted | `created_at` |
| `signups.users` | `users` | `role` isn't `super_admin` | `created_at` |
| `usage`, `ai` | `meter_events`, `usage_counters`, `ai_usage_logs` (counts and tokens only) | Accounts not soft-deleted | `occurred_at`. Seats read the counter for the month of `to` |
| `ai_cost_usd`, `cost_basis`, `estimated_rows_pct`, `unattributed`, `deleted_accounts` | `ai_usage_logs` | Grouped by `hotel_group_id`, with null as `unattributed` and soft-deleted accounts as `deleted_accounts` | `occurred_at` |
| `reservations`, `bookings` | `reservations`, `bookings` | Not soft-deleted, any status, per live hotel | `created_at` |

The counts in `accounts`, `hotels` and `users` show the state now, not the state during the window. Only `signups`, usage, cost and activity use the window, so those three counts have one cache entry of their own.

## Suspension rules

| Record | Suspended when | Written by |
| --- | --- | --- |
| Account | `suspended_at` is set | Existing `Suspension` service |
| Hotel | Its `suspended_at` is set, or its account's is, including a soft-deleted account | Existing `Suspension` service |
| User | Derived, with no column: see below | Nothing |

`is_active` plays no part (V9).

### Deriving a user's state

The user's **reachable hotels** are live hotels only:

| User | Reachable hotels |
| --- | --- |
| Owner: `group_role` and `hotel_group_id` both set | Every hotel of that account |
| Anyone else | Their home hotel (`hotel_id`) and memberships (`hotel_user`). A direct `hotel_group_id` adds nothing |

The user's **account** is `hotel_group_id`, or else the account of their home hotel, reading soft-deleted hotels and accounts.

| Reachable hotels | Account | User counts as |
| --- | --- | --- |
| At least one active | Anything | Active |
| At least one, all suspended | Anything | Suspended |
| None | Suspended | Suspended |
| None | Active, or none | Active |

This is the same result as `App\Support\Suspension\HotelAccess`, written in SQL as `User::scopeRefusedBySuspension`. A test holds the two together by comparing against real logins.

## Cached section

One entry per section and window, in the default cache store, for 900 seconds, plus one entry for the current counts.

| Part | Value |
| --- | --- |
| Key | `superadmin:dashboard:v1:{section}:{from}:{to}`, where `section` is `counts`, `usage` or `activity`, and the dates are `Y-m-d`. In `counts`, this holds `signups` |
| Current counts key | `superadmin:dashboard:v1:counts:current`, holding `accounts`, `hotels` and `users` |
| Value | Plain arrays and scalars |
| `cached_at` | ISO 8601 UTC, set when the figures were computed. A counts response reports the older of its two entries |

Entries aren't cleared on writes. They expire on their own.

## Window

| Field | Rule |
| --- | --- |
| `from`, `to` | Optional, `Y-m-d`, UTC, not after today |
| Default | `from` is today − 29 days, and `to` is today |
| `from` alone | `to` is today |
| `to` alone | `from` is `to` − 29 days |
| Range | `to` is on or after `from`, and no later than `from` plus 12 months |
| Reads | From the start of `from` to the end of `to` |
