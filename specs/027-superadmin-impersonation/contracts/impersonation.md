# API Contract: Super Admin Impersonation

All responses use `{ message, code, body }`. Super admin routes run on `api.key → auth:sanctum → throttle:api → tenant → super_admin`, so anyone else gets `401` or `403` before any of the rules below.

## POST /api/superadmin/users/{user}/impersonate

No body.

| Status | When |
| --- | --- |
| `200` | Session started |
| `404` | Unknown user |
| `422` | The target is a super admin. No token or event is created |

```json
{
  "message": "Impersonation started successfully.",
  "code": 200,
  "body": {
    "token": "<plain token, returned only here>",
    "token_id": 123,
    "expires_at": "2026-11-08T12:30:00+00:00"
  }
}
```

There's no way to extend a session. Starting another gives a separate token, and the first runs on.

## POST /api/superadmin/impersonation/end

```json
{ "token_id": 123 }
```

| Status | When |
| --- | --- |
| `200` | Session ended, `body: null` |
| `404` | Not a running session: unknown id, not an impersonation token, expired, already ended, or the user no longer exists |
| `422` | `token_id` missing or not an integer, as `{ message, errors }` |

Any super admin may call it. The caller is the actor on the end event.

## POST /api/superadmin/users/{user}/end-impersonation

No body. Ends every running session of that user. Login tokens and the WhatsApp device are untouched.

| Status | When |
| --- | --- |
| `200` | `body: { "ended": 2 }`, which may be `0` |
| `404` | Unknown user, or a super admin, as on the other user administration routes |

## POST /api/logout (changed)

Unchanged response. An impersonation token also gets `platform.impersonation.ended`. An expired one gets `401` and no event.

## GET /api/user (changed)

With an impersonation token, the user's usual body gains one key:

```json
{
  "impersonation": {
    "impersonator": { "id": "<uuid>", "name": "Support Person" },
    "expires_at": "2026-11-08T12:30:00+00:00"
  }
}
```

With any other token the key is absent. No other response has it, and `UserResource` doesn't change.

## Refused with an impersonation token

`403`, `{ "message": "This action is not available while impersonating.", "code": 403, "body": null }`, before the record is looked up.

| Method | Path |
| --- | --- |
| POST | `/api/connect` |
| POST | `/api/users` |
| PUT, PATCH, DELETE | `/api/users/{user}` |
| POST | `/api/staff-roles` |
| PUT, PATCH, DELETE | `/api/staff-roles/{staff_role}` |
| POST | `/api/team/{team}/members` |
| POST | `/api/ai-advisor/chat` |
| DELETE | `/api/whatsapp-device` |

A request that an earlier check refuses gets that check's `403`: suspended (`body.scope`), or an `X-Hotel-Id` the user can't reach. Reads of these resources work under the user's permissions. Every other token is unaffected.

## Expired tokens

`401` on every endpoint. No event is written.

## Suspended hotels and accounts, with an impersonation token

| Request | Result |
| --- | --- |
| `GET`, `HEAD` | Allowed, with the user's whole scope, suspended hotels included |
| Any other method, naming a suspended hotel with `X-Hotel-Id` | Suspended `403`, `body.scope = "hotel"` or `"account"` |
| Any other method, with every hotel suspended | Suspended `403` |
| Any other method, with some hotels active | Narrows to the active hotels, as for a normal user. No header works in an active one |
| `PUT` on a hotel-owned record of a suspended hotel, working in an active one | `404` |
| `PUT /api/hotel/{id}` for a suspended hotel | Suspended `403` |
| `POST /api/logout` | Allowed |

With a suspended default hotel A and active hotel B, and no header, a `GET` reads A and a `POST` writes to B. Send `X-Hotel-Id` throughout a session.

## GET /api/history/{type}/{id} (changed)

When the reader isn't a super admin and a row's actor is a super admin, or an actor user who no longer exists:

- `actor` is `{ "id": "<uuid>", "name": "Support Person" }`, or null if the user no longer exists.
- `context` has no `ip`. `context.impersonated_user_id` stays.

A super admin's own token reads the full row. Rows with a hotel user as actor don't change.

## Events and Sentry

See [data-model.md](../data-model.md) for event rows. Requests with an impersonation token tag Sentry with `impersonating = "true"` and `impersonator_id`. All others clear both.
