# API Contract: Account and Hotel Suspension

All responses use `{ message, code, body }`.

## The suspended response

Returned by login, by every tenant route except `POST /api/logout`, and by `GET` and `PUT /api/hotel/{id}`.

```json
{
  "message": "This account has been suspended.",
  "code": 403,
  "body": { "reason": "suspended", "scope": "account" }
}
```

| `scope` | Message |
| --- | --- |
| `account` | This account has been suspended. |
| `hotel` | This hotel has been suspended. |

Clients should branch on `body.reason`, not the message. Other `403` responses have no `reason`.

## Super admin endpoints

The stack is `api.key → auth:sanctum → throttle:api → tenant → super_admin`, so anyone but a super admin gets `403`. An unknown or soft-deleted id gets `404`.

| Method and path | Body | Success | `422` when |
| --- | --- | --- | --- |
| `POST /api/superadmin/accounts/{account}/suspend` | `reason` (required string, max 1000) | `200` with the account | No reason, or already suspended |
| `POST /api/superadmin/accounts/{account}/reactivate` | none | `200` with the account | Not suspended |
| `POST /api/superadmin/hotels/{hotel}/suspend` | `reason` (required string, max 1000) | `200` with the hotel | No reason, or already suspended in its own right |
| `POST /api/superadmin/hotels/{hotel}/reactivate` | none | `200` with the hotel | Not suspended in its own right |

### Fields added to super admin responses

| Field | On | Notes |
| --- | --- | --- |
| `suspended_at` | Account, hotel | Its own suspension only |
| `suspended_by` | Account, hotel | User id |
| `suspended_reason` | Account, hotel | |
| `is_suspended` | Hotel | True through its own suspension or its account's |

The account and hotel endpoints on `/api/superadmin`, including create and edit, return these fields. Hotel responses move to a new resource with all the existing fields.

### Filter

`GET /api/superadmin/accounts` and `GET /api/superadmin/hotels` accept `filter[suspended]` of `true`, `false`, `1` or `0`. Empty means no filter, and anything else gets `422`. For hotels, it follows `is_suspended`. It can't be used for sort.

## Login

`POST /api/login`, in order:

| Case | Response |
| --- | --- |
| Wrong password | `401`, whatever the suspension state |
| Super admin | `200`, never checked |
| Account suspended | Suspended `403`, `scope: account`, no token |
| Has hotels, all suspended | Suspended `403`, `scope: hotel`, no token |
| Default hotel suspended, another active | `200`, and `user.hotel` is the first active hotel |
| No hotels, account not suspended | `200`, as today |

`user.hotel_id` stays the stored default, so it can differ from `user.hotel.id`. Clients should read `user.hotel`. `GET /api/user` behaves the same way.

## Tenant routes

Every route in the tenant group except `POST /api/logout`:

| Case | Response |
| --- | --- |
| Super admin | As today |
| Nothing suspended | As today |
| Account suspended | Suspended `403`, `scope: account` |
| `X-Hotel-Id` names a suspended hotel the caller can reach | Suspended `403` with that hotel's scope |
| `X-Hotel-Id` names a hotel the caller can't reach | As today |
| All the caller's hotels suspended | Suspended `403` |
| Some suspended, no header or an active one named | Works in an active hotel. A suspended hotel's record ids get `404` |
| No hotels, account not suspended | As today |

## Hotel list

`GET /api/hotel` is gated by `hotels.view`, which admins hold automatically and employees only through a staff role. It was super admin only.

| Caller | Hotels | Record | Search, filter and sort |
| --- | --- | --- | --- |
| Super admin | All | Full, with no suspension fields | All columns, as today |
| Admin | Reachable and not suspended | Full | All columns |
| Employee with `hotels.view` | Reachable and not suspended | `id`, `name`, `slug`, `timezone`, `currency`, `city` | Short record's fields. Search covers `name`, `slug` and `city` |
| Employee without it | | `403` | |

## A hotel's own routes

`GET` and `PUT /api/hotel/{id}` run authorization first. Who passes is unchanged, and `hotels.view` doesn't help.

| Case | Response |
| --- | --- |
| Fails authorization | Normal `403`, with no `reason`, whatever the hotel's state |
| Passes, active hotel | As today |
| Passes, suspended hotel | Suspended `403` |
| Super admin, suspended hotel | As today |

A `PUT` with an invalid body gets its `422` first.

## WhatsApp

No API change. For an inbound message to a suspended hotel, in order:

| Check | Result |
| --- | --- |
| Unpaired admin | The not-paired reply |
| Guest's message is an opt-out or opt-in keyword | Applied and confirmed |
| First message in 24 hours for this hotel and phone | "Sorry, I can't answer messages right now. Please contact the hotel directly for assistance.", in Arabic for a guest whose language is Arabic. Status `replied`, with no AI run |
| Later message | Stored, no reply. Status `throttled` |
