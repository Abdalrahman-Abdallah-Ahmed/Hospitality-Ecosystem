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

- The backend checks the `API_KEY` environment variable.
- If `API_KEY` is empty on the server, requests are allowed without this header.
- If the key is configured and missing or wrong, the API returns:

```json
{
  "message": "Invalid API key."
}
```

with HTTP status `401`.

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
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    }
  }
}
```

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
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    }
  }
}
```

### Frontend Handling

After a successful login:

1. Read `body.token`
2. Store it securely for authenticated API calls
3. Send it on protected requests as:

```http
Authorization: Bearer {token}
```

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
