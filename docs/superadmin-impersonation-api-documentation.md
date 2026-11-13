# Super Admin Impersonation API Documentation

For the frontend's super admin area: acting as a hotel user for up to 30 minutes to support them. A session is a token that belongs to that user, so hotel endpoints apply their permissions and hotels, apart from the limits below. The audit trail names the real super admin.

The three endpoints sit under `/api/superadmin`, in `routes/superadmin.php`, and only a super admin can call them. An impersonation token gets `403` on every route in that file.

> **The only way into a hotel (spec 028, 2026-11-10):** a super admin's own token gets `403` `Super admins use /api/superadmin.` on every hotel route except `POST /api/logout` and `GET /api/user`. To see or change what a hotel's user sees, start a session here.

## Required Headers

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {super_admin_login_token}
Accept: application/json
```

## 1. Start a Session

`POST /api/superadmin/users/{user}/impersonate`, with no body.

```json
{
  "message": "Impersonation started successfully.",
  "code": 200,
  "body": {
    "token": "123|plain-token",
    "token_id": 123,
    "expires_at": "2026-11-08T12:30:00+00:00"
  }
}
```

| Status | When |
| --- | --- |
| `200` | Session started |
| `404` | Unknown user |
| `422` | The target is a super admin. No token or event is created |

Use `token` as the Bearer token on hotel endpoints. It's returned only here, and isn't stored anywhere readable.

A session can't be extended. Starting another gives a separate token with its own 30 minutes, and the first keeps running. Several sessions can run at once, for one user and from different super admins.

## 2. End a Session by Id

`POST /api/superadmin/impersonation/end`

```json
{ "token_id": 123 }
```

| Status | When |
| --- | --- |
| `200` | Session ended, `body: null` |
| `404` | Not a running session: unknown id, not an impersonation token, expired, already ended, or the user no longer exists |
| `422` | `token_id` missing or not an integer |

Any super admin may call it, and is named as the actor on the end event.

## 3. End All of a User's Sessions

`POST /api/superadmin/users/{user}/end-impersonation`, with no body. This is for a super admin who doesn't have the `token_id`, because no endpoint lists running sessions.

```json
{ "message": "Impersonation ended successfully.", "code": 200, "body": { "ended": 2 } }
```

| Status | When |
| --- | --- |
| `200` | `ended` may be `0` |
| `404` | Unknown user, or a super admin |

It ends only impersonation tokens. The user's login tokens and WhatsApp device stay. `POST /api/superadmin/users/{user}/revoke-tokens` also signs the user out and unpairs the device, so use that one to lock out a compromised user.

## Using a Session

### Showing a banner

`GET /api/user` with the token adds one key, on that response only:

```json
{
  "impersonation": {
    "impersonator": { "id": "01a0...", "name": "Support Person" },
    "expires_at": "2026-11-08T12:30:00+00:00"
  }
}
```

With any other token the key is absent.

### Ending

- `POST /api/logout` with the token ends the session and records the end event.
- After 30 minutes every endpoint returns `401`, and no event is written.

### What a session can't do

These return `403` before the addressed record is looked up, whatever the user's own rights:

```json
{ "message": "This action is not available while impersonating.", "code": 403, "body": null }
```

| Method | Path |
| --- | --- |
| POST | `/api/connect` |
| POST | `/api/users` |
| PUT, PATCH, DELETE | `/api/users/{id}` |
| POST | `/api/staff-roles` |
| PUT, PATCH, DELETE | `/api/staff-roles/{id}` |
| POST | `/api/team/{id}/members` |
| POST | `/api/ai-advisor/chat` |
| DELETE | `/api/whatsapp-device` |

So a session can't set a password, change an email, role or membership, or pair a device. It also can't use the AI advisor, because the conversation and any write waiting for confirmation belong to the user. The same refusal applies to any attempt to change a user or issue a token during a session, on any route. Reads of `/api/users` and `/api/staff-roles` work under the user's permissions, and `GET /api/whatsapp-device` shows the user's device. A request that an earlier check refuses gets that check's `403`: suspended, or an `X-Hotel-Id` the user can't reach.

To change users or assign a staff role, use the `api/superadmin` user endpoints. Staff role definitions are changed only by the hotel's own admins.

### Suspended hotels and accounts

| Request | Result |
| --- | --- |
| `GET`, `HEAD` | Allowed, with the user's whole scope, suspended hotels included |
| Any other method, naming a suspended hotel with `X-Hotel-Id` | Suspended `403`, with `body.scope` |
| Any other method, with every hotel suspended | Suspended `403` |
| Any other method, with some hotels active | Works in the active hotels, as for a normal user |
| A write to a hotel-owned record of a suspended hotel, working in an active one | `404` |
| `PUT /api/hotel/{id}` for a suspended hotel | Suspended `403` |
| `POST /api/logout` | Allowed |

**Send `X-Hotel-Id` throughout a session.** With a suspended default hotel A, an active hotel B and no header, a `GET` reads A and a `POST` writes to B, and `GET /api/user` shows `hotel` A. A normal user never sees this, because their reads move to B too.

## What the Audit Trail Records

Rows are in `event_log`.

| Event | `hotel_id` | Subject | Actor | `context` |
| --- | --- | --- | --- | --- |
| `platform.impersonation.started` | null | Target user | The super admin who started it | `impersonated_user_id`, `token_id`, `expires_at` |
| `platform.impersonation.ended` | null | Target user | The super admin who ended it. On logout, the session's super admin | `impersonated_user_id`, `token_id` |

These two never appear on a hotel's history.

During a session's request:

- **Audited writes** have the super admin as actor, and `context.impersonated_user_id` set to the user. `actor_kind` doesn't change, so an AI write stays `ai_agent`.
- **Writes to hotel settings, teams, task categories, activity categories and hotel policies**, which aren't audited otherwise, record one event each (for example `team.updated`), with `context.changed_fields` holding field names and never values. A guest memories toggle also keeps its own event. Deleting a team or task category that a hotel setting points at also records `hotel.updated`, naming the setting the database cleared. Other callers record nothing new.

Sentry events for these requests are tagged `impersonating = "true"` and `impersonator_id`.

## Known Limits

- **Attribution covers event rows only.** Fields such as a task's creator or an import's uploader keep the target user, and AI usage from jobs a session starts counts against the hotel.
- **Not every write is recorded.** Creating or deleting a reservation import preview, saving the import column mapping, and starting an import record no event in the request, as for any other caller.
- **A session shares the user's limits.** The API and export rate limits and the voucher email cap are counted per user.
- **Jobs a session starts name the hotel user.** A write made later by a queued job, such as a reservation import, has no token to read. The start event shows who held the session at that time.
- **`expires_at` is the latest a session could have ended, not always when it did.** `revoke-tokens`, a password reset and deleting either user all end it with no end event.
- **Running sessions can't be listed.** `token_id` is returned at start and stored on the events, but no endpoint shows it.
- **Revoking a super admin's own tokens leaves their sessions running**, for up to 30 minutes. Deleting that super admin ends them.
- **Stored event rows for the five record types above can't be read through the API yet**, because none of them is a history record type.
- **Hotel staff can see who acted.** A history row shows a super admin's id and name, but not their email, phone or IP. See [history-api-documentation.md](history-api-documentation.md).
