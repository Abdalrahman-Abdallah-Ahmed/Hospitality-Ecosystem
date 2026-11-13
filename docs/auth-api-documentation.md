# Authentication API Documentation

This document describes the authentication APIs the next frontend/cloud team should use to build the register, login, and logout pages.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `POST /api/register`
- `POST /api/login`
- `POST /api/logout`

## Required Headers

### 1. API Key

All auth API endpoints are inside the `api.key` middleware group.

Send this header on every request:

```http
X-API-KEY: {your_api_key}
```

Notes:

- The backend checks the configured `API_KEY` (read through config, so it keeps working after `php artisan optimize`).
- If no key is configured, requests are allowed without this header **only** in a `local` or `testing` environment. In any other environment an unset key rejects every request.
- The key is read from the `X-API-KEY` header **only**. An `?api_key=` query parameter is ignored and the request gets `401`, because keys in URLs end up in access logs and browser history.
- If the key is missing or wrong, the API returns:

```json
{
  "message": "Invalid API key."
}
```

with HTTP status `401`.

**The API key is not a secret.** A browser frontend ships it in its bundle, so anyone can read it. It identifies the client app; it is not access control. Every protected route is guarded by the bearer token and the per-role policies, and `register` / `login` are rate limited (see [Rate Limiting](#rate-limiting)).

### 2. JSON Content Type

For request bodies, send:

```http
Content-Type: application/json
Accept: application/json
```

### 3. Bearer Token

`/api/logout` requires a Laravel Sanctum bearer token returned from `/api/login`.

```http
Authorization: Bearer {token}
```

## Response Format

Successful custom API responses use this shape:

```json
{
  "message": "Some message",
  "code": 200,
  "body": {}
}
```

Notes:

- `message`: human-readable message
- `code`: repeated HTTP status code
- `body`: actual payload, or `null`

## 1. Register

Creates a new user account.

### Endpoint

`POST /api/register`

### Request Body

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

### Validation Rules

- `name`: required, string, max 255 characters
- `email`: required, valid email, max 255 characters, must be unique in `users`
- `password`: required, minimum 8 characters
- `password_confirmation`: required because password must be confirmed

### Success Response

HTTP `201 Created`

```json
{
  "message": "User registered successfully.",
  "code": 201,
  "body": {
    "user": {
      "id": "019facde-1111-7000-9000-abcdef123456",
      "name": "John Doe",
      "email": "john@example.com",
      "role": "admin",
      "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
      "staff_role_id": null,
      "staff_role": null,
      "permissions": ["activities.view", "activities.create", "...every permission"]
    },
    "hotel": { "...": "HotelResource" }
  }
}
```

The registering user is always the new hotel's `admin`, so `permissions` lists every permission and `staff_role` is `null`. See [Staff Roles API](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md).

### Welcome Email

After a successful registration the new user is sent a welcome email naming their hotel. It is queued, so it is delivered by the queue worker shortly after the `201` response, and a mail failure never fails the registration. Nothing is sent when validation fails.

### Important Frontend Note

This endpoint does **not** return an auth token and does **not** log the API user in.

Recommended frontend flow after successful registration:

1. Submit `/api/register`
2. If registration succeeds, immediately call `/api/login`
3. Store the returned bearer token
4. Redirect the user to the authenticated area

## 2. Login

Authenticates a user and returns a Sanctum personal access token.

### Endpoint

`POST /api/login`

### Request Body

```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

### Validation Rules

- `email`: required, valid email
- `password`: required, string

### Success Response

HTTP `200 OK`

```json
{
  "message": "Authenticated successfully.",
  "code": 200,
  "body": {
    "token": "1|exampleSanctumTokenHere",
    "token_type": "Bearer",
    "user": {
      "id": "019facde-1111-7000-9000-abcdef123456",
      "name": "John Doe",
      "email": "john@example.com",
      "role": "employee",
      "staff_role_id": "019fb2a0-1111-7000-9000-abcdef123456",
      "staff_role": {
        "id": "019fb2a0-1111-7000-9000-abcdef123456",
        "name": "Housekeeping",
        "permissions": ["rooms.view", "tasks.view", "tasks.update"]
      },
      "permissions": ["rooms.view", "tasks.view", "tasks.update"],
      "hotel": { "...": "HotelResource" },
      "team": null
    }
  }
}
```

`body.user` is the full user object (see [User Management API § The User Object](/D:/Hospitality%20Ecosystem/docs/user-management-api-documentation.md#the-user-object)), with `hotel`, `team` and `staff_role` loaded. `staff_role` above is shortened.

`permissions` is the user's effective permission list: every permission for an admin, and the role's list or the defaults for an employee. See [Staff Roles API](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md).

### Frontend Handling

After a successful login:

1. Read `body.token`
2. Store it securely for authenticated API calls
3. Send it on protected requests as:

```http
Authorization: Bearer {token}
```

4. Use `body.user.permissions` to build navigation and actions right away. There's no need to call `GET /api/user` first.
5. Read the user's hotel from `body.user.hotel`, not `body.user.hotel_id`. They differ when the user's default hotel is suspended and they have another: `hotel_id` stays the stored default, and `hotel` is the active hotel they work in. `GET /api/user` behaves the same way, and for an admin it also follows `X-Hotel-Id`.

### Choosing a hotel (`X-Hotel-Id`)

*2026-11-07.* An admin's request works in **one hotel**: the one named by the `X-Hotel-Id` header, or their default hotel without it. Lists, the dashboard and reports show only that hotel, new records are created there, and a record in any other hotel returns `404`, even one the admin can reach. An account owner can choose any hotel of the account. Any other admin can choose their home hotel and the hotels they are a member of. Employees are unchanged: their permissions apply to their home hotel's records only.

A header naming a hotel the user can't reach, including an unknown, deleted or malformed id, returns a plain `403` on every request:

```json
{ "message": "This action is unauthorized.", "code": 403, "body": null }
```

Clear the stored choice when you get it. A suspended hotel of the user's still gets the suspended `403` below. `POST /api/logout`, `GET /api/hotel`, `POST /api/connect` and `GET /api/check-paired` ignore the header, so a client can always recover. A super admin's own token reaches only `POST /api/logout` and `GET /api/user` here; every other hotel route answers `403` (spec 028).

### Suspended Account or Hotel

HTTP `403 Forbidden`, with no token, once the password has been checked. A wrong password still gets the `401` below.

```json
{
  "message": "This account has been suspended.",
  "code": 403,
  "body": { "reason": "suspended", "scope": "account" }
}
```

`scope` is `account` when the account is suspended, and `hotel` when all of the user's hotels are (the message is then "This hotel has been suspended."). A user with another active hotel logs in normally.

Every hotel API route except `POST /api/logout` can return the same response to an existing token, when the account is suspended, all the user's hotels are, or `X-Hotel-Id` names a suspended hotel of theirs. Check `body.reason`, not the message, and show the suspended screen. Other `403` responses have no `reason`. The messages are English only, so translate from `reason` and `scope` if needed.

### Invalid Credentials Response

HTTP `401 Unauthorized`

```json
{
  "message": "Invalid credentials.",
  "code": 401,
  "body": null
}
```

## 3. Logout

Logs the user out by deleting the current access token only.

### Endpoint

`POST /api/logout`

### Required Headers

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {token}
Accept: application/json
```

### Request Body

No body is required.

### Success Response

HTTP `200 OK`

```json
{
  "message": "Logged out successfully.",
  "code": 200,
  "body": null
}
```

### Important Behavior

- Logout removes the **current** access token only.
- If the same user is logged in on multiple devices or sessions, other tokens remain active.

### Impersonation Sessions

With a super admin's [impersonation token](superadmin-impersonation-api-documentation.md), logging out ends the session and records `platform.impersonation.ended`. An expired one returns `401`, and no event is written. A normal token's logout doesn't change.

`GET /api/user` with an impersonation token adds this key, on that response only. With any other token the key is absent:

```json
{
  "impersonation": {
    "impersonator": { "id": "01a0...", "name": "Support Person" },
    "expires_at": "2026-11-08T12:30:00+00:00"
  }
}
```

---

## 4. Set a New Password

A user sets a password from an emailed link, either a new user's first link or a reset link a super admin sent. There is no self-service "forgot password" request. See [superadmin-accounts-api-documentation.md](superadmin-accounts-api-documentation.md).

The link opens the frontend at `{FRONTEND_URL}/reset-password?token=…&email=…`. The page asks for a new password and posts here. Public: API key only, no login.

### Endpoint

`POST /api/reset-password`

### Request Body

```json
{
  "token": "the-token-from-the-link",
  "email": "nadia@example.com",
  "password": "A-new-Password1!",
  "password_confirmation": "A-new-Password1!"
}
```

### Success Response

HTTP `200`. The link stops working, and **all the user's login tokens are revoked and their WhatsApp device is unpaired**, so they log in again with the new password.

```json
{
  "message": "Password set successfully.",
  "code": 200,
  "body": null
}
```

### Link Lifetime

| Link | Valid for |
| --- | --- |
| A new user's first link | 72 hours |
| Any other, including a resend for a new user | 60 minutes |

Sending a new link cancels any link still pending for that user.

### Invalid Link

HTTP `422` for an invalid or expired token or an unknown email, always with the same message so the response never reveals whether an email exists. Show a page telling the user to ask for a new link.

```json
{
  "message": "This password reset link is invalid or has expired.",
  "code": 422,
  "body": null
}
```

## Token Lifetime

Login and register tokens **expire 7 days after they are issued** (server setting `SANCTUM_TOKEN_EXPIRATION`, in minutes). An expired token gets HTTP `401` on any protected route, the same as a missing one. There is no refresh endpoint: on `401`, clear the stored token and send the user to the login page.

## Rate Limiting

| Endpoint | Limit |
| --- | --- |
| `POST /api/login` | 5 attempts per minute per email + IP, and 20 per minute per IP across all emails. |
| `POST /api/reset-password` | Same limiter as `POST /api/login`, sharing its counts: 5 attempts per minute per email + IP, and 20 per minute per IP. |
| `POST /api/register` | 5 attempts per minute per IP. |
| `POST /api/pair` | 10 requests per minute per IP. |
| Every authenticated route (`auth:sanctum`), including `/api/superadmin/*` | 240 requests per minute per user. |

Every attempt counts, successful or not. Over the limit the API returns HTTP `429` with a `Retry-After` header (seconds):

```json
{
  "message": "Too Many Attempts."
}
```

On `429`, show "Too many attempts, try again in N seconds" using `Retry-After`, and disable the submit button until then. The header is listed in `Access-Control-Expose-Headers`, so a browser frontend on an allowed origin can read it.

## Browser Origins (CORS)

Only the origins in the server's `CORS_ALLOWED_ORIGINS` (comma-separated; defaults to `http://localhost:3000`) may call the API from a browser. A frontend served from any other origin fails the CORS preflight. Add each deployed frontend URL to that variable.

## Validation Error Format

For invalid input, Laravel returns HTTP `422 Unprocessable Entity`.

Typical example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email field is required."
    ],
    "password": [
      "The password field is required."
    ]
  }
}
```

Important note:

- Validation errors are not wrapped in the custom `message/code/body` format.
- The frontend should read field-level messages from the `errors` object.

## Suggested Frontend Page Behavior

### Register Page

- Fields: `name`, `email`, `password`, `password_confirmation`
- On success:
  - either show a success message and move to login
  - or automatically call `/api/login` and continue to the app
- On `422`, show field errors under each input

### Login Page

- Fields: `email`, `password`
- On success:
  - save bearer token
  - save user info if needed
  - redirect to authenticated pages
- On `401`, show "Invalid credentials"

### Logout Action

- Send `POST /api/logout` with the stored bearer token
- If successful:
  - clear stored token
  - clear any cached user data
  - redirect to login page

## Example cURL Requests

### Register

```bash
curl -X POST http://your-domain.com/api/register \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
  }'
```

### Login

```bash
curl -X POST http://your-domain.com/api/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -d '{
    "email": "john@example.com",
    "password": "password123"
  }'
```

### Logout

```bash
curl -X POST http://your-domain.com/api/logout \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Summary for the Cloud Team

- Use `/api/register` to create the account
- Then use `/api/login` to get the auth token
- Use `Authorization: Bearer {token}` for protected calls
- Use `/api/logout` to invalidate the current token
- Send `X-API-KEY` on requests when the backend environment has `API_KEY` configured
