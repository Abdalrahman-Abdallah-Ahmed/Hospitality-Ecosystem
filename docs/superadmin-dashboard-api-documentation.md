# Super Admin Dashboard API Documentation

For the frontend's super admin area: three read-only sections that show the platform at a glance. They cover how many customers there are and who is suspended, what each account uses and costs, and which hotels are in use.

All endpoints below sit under `/api/superadmin`, in `routes/superadmin.php`, and only a super admin can call them.

Load each section with its own request, so that one slow section doesn't hold up the others.

## Required Headers

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {super_admin_login_token}
Accept: application/json
```

## Response Format

Responses use the usual wrapper:

```json
{
  "message": "Dashboard counts fetched successfully.",
  "code": 200,
  "body": {}
}
```

| Status | Meaning |
| --- | --- |
| `200` | The section's figures, which may be up to 15 minutes old |
| `401` | No valid API key or login token |
| `403` | The caller isn't a super admin. This comes before any check of the window, and `body` is `null` |
| `422` | Invalid window, in Laravel's `{ "message", "errors" }` shape, under `from` or `to`. A window over 12 months is reported under `to`, or under `from` when no `to` was sent |

## The Window

All three sections take the same optional query parameters.

| Parameter | Rule |
| --- | --- |
| `from` | `Y-m-d`, a UTC date, not after today |
| `to` | `Y-m-d`, a UTC date, not after today, on or after `from`, and at most 12 months after `from` |

| Sent | Window used |
| --- | --- |
| Neither | The 30 days ending today, counting today |
| `from` only | `from` to today. A `from` over 12 months ago gets `422` |
| `to` only | 29 days before `to`, to `to` |
| Both | `from` to `to` |

The window runs from the start of `from` to the end of `to`, in UTC. A hotel's own time zone doesn't affect it.

A parameter sent empty (`from=`) gets `422`, so leave it out instead. Cap the date picker at today, because a later date gets `422`.

Every `200` body has these fields:

| Field | Meaning |
| --- | --- |
| `from`, `to` | The window actually used, as `Y-m-d` |
| `cached_at` | When these figures were computed, in ISO 8601 UTC |

## Caching

Each section is cached for 15 minutes, per section and per window. The one exception is the current figures in counts (`accounts`, `hotels` and `users`), which have a single entry, so every date range shows the same ones. A counts response gives the older time as its `cached_at`.

Show `cached_at`, so that nobody takes the figures for live ones.

Nothing clears the cache early. After a super admin suspends an account, the counts can stay old for up to 15 minutes, and there's no way to force a recount.

## Get Counts

`GET /api/superadmin/dashboard/counts`

```json
{
  "message": "Dashboard counts fetched successfully.",
  "code": 200,
  "body": {
    "accounts": { "active": 12, "suspended": 1 },
    "hotels": { "active": 20, "suspended": 3 },
    "users": { "active": 140, "suspended": 9 },
    "signups": [
      { "date": "2026-10-10", "accounts": 0, "users": 2 },
      { "date": "2026-10-11", "accounts": 1, "users": 1 }
    ],
    "from": "2026-10-10",
    "to": "2026-11-08",
    "cached_at": "2026-11-08T10:15:00Z"
  }
}
```

**`accounts`, `hotels` and `users` show the state now.** The window doesn't change them, and only affects `signups`. Deleted accounts and hotels aren't counted, and super admins never are.

| Term | Meaning |
| --- | --- |
| Active | Not suspended. `is_active` plays no part: a hotel with it off still counts as active |
| Suspended account | The account itself is suspended |
| Suspended hotel | The hotel or its account is suspended |
| Suspended user | Suspension would refuse them at login, as described below |

**A user has no suspension of their own.** Their state comes from the hotels they reach:

- An account owner reaches every hotel of the account.
- Anyone else reaches their home hotel and the hotels they're a member of.

| The user reaches | They count as |
| --- | --- |
| At least one active hotel | Active |
| Only suspended hotels | Suspended |
| No hotels, and their account is suspended | Suspended |
| No hotels, and no suspended account | Active |

So a user can count as active while one of their hotels is suspended, or even while they're linked to a suspended account, as long as they can still work somewhere. Revoking a user's tokens isn't a suspension, and doesn't change the count.

**`signups`** has one row per day of the window, in date order, with zeros on days with none. Days are UTC.

- `accounts` is every account created that day, including ones a super admin made and ones deleted since.
- `users` is every user created that day except super admins, including staff a hotel added.

Nothing records where an account or user came from, so `users` is not the number of new customers. `accounts` is the closer measure.

## Get Usage and AI Cost

`GET /api/superadmin/dashboard/usage`

```json
{
  "message": "Dashboard usage fetched successfully.",
  "code": 200,
  "body": {
    "accounts": [
      {
        "hotel_group_id": "019f9b37-c260-7abc-9000-1234567890ab",
        "name": "Acme Hotels",
        "usage": {
          "seats": {},
          "features": {},
          "recommendations": {}
        },
        "ai": {},
        "ai_cost_usd": 12.345678,
        "cost_basis": "mixed",
        "estimated_rows_pct": 4.2
      }
    ],
    "unattributed": { "calls": 3, "ai_cost_usd": 0.0021, "cost_basis": "measured", "estimated_rows_pct": 0 },
    "deleted_accounts": { "calls": 0, "ai_cost_usd": 0, "cost_basis": "no data", "estimated_rows_pct": 0 },
    "from": "2026-10-10",
    "to": "2026-11-08",
    "cached_at": "2026-11-08T10:15:00Z"
  }
}
```

`accounts` lists every account that isn't deleted, by name.

| Field | Meaning |
| --- | --- |
| `usage.seats`, `usage.features`, `usage.recommendations` | The same as the matching fields of [`GET /api/superadmin/usage`](usage-metering-api-documentation.md) for the same window. A seat is read for the month of `to`, and reads `null` with `measured: false` if that month has no count |
| `ai` | The same as `ai` in that report: requests and tokens, with no cost |
| `ai_cost_usd` | What the account's AI cost in the window, in USD to six decimals. It matches [`GET /api/superadmin/ai-cost`](ai-cost-attribution-api-documentation.md) for the same window |
| `cost_basis` | `measured`, `estimated`, `mixed` or `no data` |
| `estimated_rows_pct` | The share of the account's AI calls with an estimated cost, to one decimal |
| `unattributed` | AI calls logged with no account: their number, their cost in USD, and that cost's basis |
| `deleted_accounts` | One total, in the same shape, for AI calls of accounts that have since been deleted. `/ai-cost` doesn't report these |

**Read a low cost with `cost_basis`.** A model with no price on file is logged at an estimated cost of `0`, which means "not priced", not "free". EUR, margin and the breakdowns stay on `/ai-cost`.

Those two reports use a different default window (the start of the month), so send explicit dates when comparing them with this one.

## Get Activity

`GET /api/superadmin/dashboard/activity`

```json
{
  "message": "Dashboard activity fetched successfully.",
  "code": 200,
  "body": {
    "hotels": [
      {
        "hotel_id": "019f9b37-d111-7abc-9000-1234567890ab",
        "hotel_group_id": "019f9b37-c260-7abc-9000-1234567890ab",
        "name": "Acme Downtown",
        "reservations": 41,
        "bookings": 7
      }
    ],
    "from": "2026-10-10",
    "to": "2026-11-08",
    "cached_at": "2026-11-08T10:15:00Z"
  }
}
```

`hotels` lists every hotel that isn't deleted, by name, including suspended hotels and hotels with zero activity. A hotel moved to another account shows under its current one.

`reservations` and `bookings` count the records created in the window, of any status. A cancelled reservation counts, and a soft-deleted one doesn't. Imported reservations count on the day of the import.

No revenue, price or currency appears on this dashboard.

## Known Limits

- **Two booking figures.** `bookings_created` in the usage section counts meter events per account, and `bookings` here counts live records per hotel. They can differ: soft-deleting a booking lowers only the second.
- **User totals don't add up across accounts.** Platform `users` counts each person once, and includes people with no account. It can differ from the sum of `users_count` in [`GET /api/superadmin/accounts`](superadmin-accounts-api-documentation.md), and from metered seats.
- **Deleted users.** Users are hard-deleted, so they disappear from past `signups`.
- **An account with no hotels** counts as an account, and appears in usage at zero. Moving a hotel can leave one behind.
