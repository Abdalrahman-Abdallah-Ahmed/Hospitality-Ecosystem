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

## Rate Limiting

| Endpoint | Limit |
| --- | --- |
| `POST /api/login` | 5 attempts per minute per email + IP, and 20 per minute per IP across all emails. |
| `POST /api/register` | 5 attempts per minute per IP. |

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
