# API Contract: Super Admin Platform Dashboard

All responses use `{ message, code, body }`. The stack is `api.key → auth:sanctum → throttle:api → tenant → super_admin`.

## Common to all three

**Query parameters**

| Parameter | Rule |
| --- | --- |
| `from` | Optional, `Y-m-d`, UTC, not after today |
| `to` | Optional, `Y-m-d`, UTC, not after today, on or after `from`, at most 12 months after `from` |

With neither, the window is the 30 days ending today, counting today. `from` alone runs to today. `to` alone starts 29 days earlier. A parameter sent empty (`from=`) gets `422`, so leave it out instead.

**Responses**

| Status | When |
| --- | --- |
| `200` | The section's figures, which may be up to 15 minutes old |
| `401` | No valid API key or login token |
| `403` | The caller isn't a super admin, whatever the query says |
| `422` | Invalid window, as Laravel's `{ message, errors }`, on the `from` or `to` key |

**Every `200` body has**

| Field | Meaning |
| --- | --- |
| `from`, `to` | The window actually used, as `Y-m-d` |
| `cached_at` | When these figures were computed, in ISO 8601 UTC. Up to 15 minutes old |

`X-Hotel-Id` has no effect.

## `GET /api/superadmin/dashboard/counts`

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

- `accounts`, `hotels` and `users` show the current state, not the state during the window, and are the same in every window. Deleted records and super admins aren't included.
- `cached_at` is the older of the current counts and the signups.
- "Active" means not suspended, and `is_active` plays no part.
- A user is `suspended` when suspension would refuse them at login.
- `signups` has one row per day of the window, in date order, with zeros. It counts every account and every non-super-admin user created that UTC day, including staff and accounts a super admin made.

## `GET /api/superadmin/dashboard/usage`

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

- `accounts` lists every account that isn't deleted, by name.
- `usage.seats`, `usage.features`, `usage.recommendations` and `ai` have the same shape and values as the matching fields in `GET /api/superadmin/usage`.
- `ai_cost_usd` is in USD to six decimals, and matches `GET /api/superadmin/ai-cost` for the same window.
- `cost_basis` is `measured`, `estimated`, `mixed` or `no data`. An estimated cost can read `0` for a model with no price on file, so treat it as "not priced", not "free".
- `unattributed` is AI calls logged with no account. `deleted_accounts` is one total for AI calls of soft-deleted accounts, which aren't listed.

## `GET /api/superadmin/dashboard/activity`

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

- `hotels` lists every hotel that isn't deleted, by name, including suspended hotels and those with zero activity.
- Counts are records created in the window, of any status, that aren't soft-deleted. Imported reservations count on the day of the import.
- No revenue, price or currency appears.
- `bookings` can differ from `bookings_created` in the usage section. That one counts meter events per account, and this one counts live records per hotel, so soft-deleting a booking lowers only this one.

## Frontend handoff notes

- Load each section with its own request, so one slow section doesn't hold up the others.
- Show `cached_at`, because a super admin's own change can take up to 15 minutes to appear.
- Cap the date picker at today, because a later date gets `422`.
