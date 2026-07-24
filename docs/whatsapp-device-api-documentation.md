# WhatsApp Device API Documentation

This document describes the WhatsApp device APIs that Claude or another external client can use to connect and pair a WhatsApp device with a hotel owner account.

## Purpose

This flow has two steps:

1. An authenticated user calls `POST /api/connect` to generate a temporary pairing token.
2. Claude or the external device service calls `POST /api/pair` with that token and the WhatsApp device data.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `POST /api/connect`
- `POST /api/pair`

## Required Headers

### 1. API Key

Both endpoints are inside the `api.key` middleware group.

Send this header on every request:

```http
X-API-KEY: {your_api_key}
```

Notes:

- The backend checks the `API_KEY` environment variable.
- If `API_KEY` is empty on the server, requests are allowed without this header.
- If the key is configured and missing or wrong, the API returns HTTP `401`:

```json
{
  "message": "Invalid API key."
}
```

### 2. JSON Headers

Send:

```http
Content-Type: application/json
Accept: application/json
```

### 3. Bearer Token for `/connect`

`/api/connect` is protected by `auth:sanctum`, so it requires a logged-in user token:

```http
Authorization: Bearer {login_token}
```

`/api/pair` does not use `auth:sanctum`, but it still requires `X-API-KEY` and a valid pairing token in the request body.

## Response Format

Successful custom API responses use this structure:

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

## 1. Generate Pairing Token

Creates a token that Claude or the external device service can use to pair a WhatsApp device to the current authenticated user.

### Endpoint

`POST /api/connect`

### Authentication

Required:

- `X-API-KEY: {your_api_key}`
- `Authorization: Bearer {login_token}`

### Request Body

No body is required.

### Success Response

HTTP `200 OK`

```json
{
  "message": "WhatsApp device token created successfully.",
  "code": 200,
  "body": {
    "token": "2|exampleWhatsAppDeviceToken"
  }
}
```

### Frontend / Claude Note

- This token is created from Laravel Sanctum.
- It should be passed to the pairing endpoint as the `token` field.
- This is different from the user's normal login token, even though both are Sanctum tokens.

## 2. Pair WhatsApp Device

Pairs a WhatsApp device to the user who owns the pairing token.

### Endpoint

`POST /api/pair`

### Authentication

Required:

- `X-API-KEY: {your_api_key}`

This endpoint does not require a bearer token in the header. Instead, it requires a pairing token in the JSON body.

### Request Body

```json
{
  "phone_number": "+201000000000",
  "token": "2|exampleWhatsAppDeviceToken",
  "wa_user_id": "whatsapp-user-123"
}
```

### Validation Rules

- `phone_number`: required, string
- `token`: required, string
- `wa_user_id`: required, string

### Success Response

HTTP `201 Created`

```json
{
  "message": "WhatsApp device paired successfully.",
  "code": 201,
  "body": {
    "id": 1,
    "user_id": 12,
    "phone_number": "+201000000000",
    "hotel_id": "550e8400-e29b-41d4-a716-446655440000",
    "wa_user_id": "whatsapp-user-123",
    "status": "active",
    "created_at": "2026-07-23T12:00:00.000000Z",
    "updated_at": "2026-07-23T12:00:00.000000Z"
  }
}
```

Notes:

- The API stores the new device with `status = "active"`.
- `wa_user_id` must be unique in the database.
- The created record belongs to the user identified by the pairing token.

## Business Rules in Pairing

The pairing endpoint applies these checks:

1. The provided `token` must match an existing Sanctum token.
2. The token must belong to a valid user.
3. That user must be associated with a hotel.
4. That user must not already have a paired WhatsApp device.

If any of these checks fail, the API returns an error instead of creating a device.

## Error Cases

### Invalid Pairing Token

HTTP `401 Unauthorized`

```json
{
  "message": "Invalid token.",
  "code": 401,
  "body": null
}
```

### User Has No Hotel

HTTP `400 Bad Request`

```json
{
  "message": "User is not associated with any hotel.",
  "code": 400,
  "body": null
}
```

### User Already Has a Paired Device

HTTP `400 Bad Request`

```json
{
  "message": "User already has a paired WhatsApp device.",
  "code": 400,
  "body": null
}
```

### Validation Error

For missing or invalid input, Laravel returns HTTP `422 Unprocessable Entity`.

Typical example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "token": [
      "The token field is required."
    ],
    "phone_number": [
      "The phone number field is required."
    ]
  }
}
```

Important note:

- Validation errors are not wrapped in the custom `message/code/body` format.
- Field errors should be read from the `errors` object.

## Suggested Integration Flow for Claude

### If Claude is helping an authenticated user inside your app

1. User logs in and gets a normal auth token from `/api/login`
2. App calls `POST /api/connect` with that bearer token
3. App receives a WhatsApp pairing token
4. Claude or the external service calls `POST /api/pair`
5. Device becomes linked to the user's hotel account

### If Claude is acting as the pairing client

Claude needs:

- the server base URL
- the `X-API-KEY`
- the pairing `token` returned by `/api/connect`
- the device `phone_number`
- the device `wa_user_id`

Claude should then send a single `POST /api/pair` request.

## Example cURL Requests

### Generate Pairing Token

```bash
curl -X POST http://your-domain.com/api/connect \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Pair Device

```bash
curl -X POST http://your-domain.com/api/pair \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -d '{
    "phone_number": "+201000000000",
    "token": "2|exampleWhatsAppDeviceToken",
    "wa_user_id": "whatsapp-user-123"
  }'
```

## Summary for Claude

- Call `POST /api/connect` as an authenticated user to generate a WhatsApp pairing token
- Call `POST /api/pair` with `phone_number`, `token`, and `wa_user_id`
- Always send `X-API-KEY` if the backend server uses `API_KEY`
- Expect `401` for an invalid token and `400` for business-rule failures
- On success, the device is created with `status = active`
