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

- The backend checks the configured `API_KEY` (read through config, so it keeps working after `php artisan optimize`).
- If no key is configured, requests are allowed without this header **only** in a `local` or `testing` environment. In any other environment an unset key rejects every request.
- If the key is missing or wrong, the API returns HTTP `401`:

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

- This token is a **pairing code**, not a login token. It should be passed to the pairing endpoint as the `token` field, or sent as a WhatsApp message to the hotel number.
- It **expires 15 minutes** after it is issued. Call `/api/connect` again for a fresh one.
- It is **single use**: it is deleted as soon as a device is paired with it.
- It is **not an API credential**. Sending it as `Authorization: Bearer …` returns `401` on every authenticated route.
- Conversely, a normal login token is rejected by `/api/pair` (`Invalid token.`).

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

- `phone_number`: required. Send it in any common format — `+20 115 179 3758`, `20-115-179-3758` and `201151793758` are all accepted. The server strips everything but digits, and the result must be **7–15 digits including the country code**. It is stored digits-only, which is also the format Meta's webhook uses.
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
    "phone_number": "201000000000",
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

1. The provided `token` must be an unexpired pairing code issued by `/api/connect` (a login token, an expired code, or an already-redeemed code counts as invalid).
2. The token must belong to a valid user.
3. That user must be associated with a hotel.
4. That user must not already have a paired WhatsApp device.

If any of these checks fail, the API returns an error instead of creating a device. On success the pairing code is revoked.

`POST /api/pair` is rate limited to 10 requests per minute per IP. Over the limit it returns HTTP `429` with a `Retry-After` header.

## Inbound Messages (Meta Webhook)

`POST /api/whatsapp` receives Meta's webhook. Clients never call it, but its behaviour affects what users see in WhatsApp:

- **Every message in a delivery is answered.** Meta can batch several messages (across entries and changes) into one call. Each one is handled separately.
- **Redeliveries are ignored.** Each message is stored by Meta's message id (`wamid`) in `whatsapp_inbound_messages`. When Meta resends a message it has already delivered, the resend is acknowledged and dropped, so the agent never runs twice for one message.
- **Per-sender rate limit.** One phone number may send `WHATSAPP_INBOUND_PER_MINUTE` messages per minute (default 10). The first message over the limit gets one "please wait a minute" reply. The rest of that minute is dropped unanswered and stored with status `throttled`.
- **Sender recognition compares digits.** A user or guest phone number saved as `+20 115 179 3758` matches Meta's `201151793758`. Admin recognition and pairing now agree on this.
- **A number that is a guest at several hotels** is routed to the stay the guest is most likely writing about: the hotel where they are in-house now, then their next arrival, then their most recent past stay. A number with no reservations goes to the most recently created guest.
- **One message per sender at a time.** A second message from the same number waits until the first has been answered, so replies arrive in order and the conversation history stays consistent.
- **Replies are generated once.** If sending the reply fails, the stored reply is resent with backoff instead of asking the model again. If the agent itself fails, the sender gets an apology instead of silence. The turn is not retried, because its tools may already have created bookings or tasks.
- **Spend ceiling.** Guest-driven AI spend stops at `AI_COST_GUEST_CEILING_SHARE` (default 60%) of the account's daily ceiling, and the guest is told the concierge is unavailable. Staff advisor and scheduled insights continue up to the full ceiling.

## 3. Check Pairing Status

Reports whether a phone number has a paired WhatsApp device. The dashboard polls this after showing a pairing code.

### Endpoint

`GET /api/check-paired?phone_number={phone_number}`

### Authentication

Required (changed 2026-09-13 — this endpoint used to need only the API key):

- `X-API-KEY: {your_api_key}`
- `Authorization: Bearer {login_token}`

### Phone Number Format

Send the number however the person typed it — `+`, spaces, dashes and brackets are fine, so `+20 115 179 3758` works as-is (URL-encode it in the query string). The server keeps only the digits, which must be **7–15 digits including the country code**. A number typed without its country code (e.g. `01151793758`) is a different number and will not match. Anything else returns `422` on `phone_number`.

### Scope

Only devices and users belonging to the caller's own hotel(s) are considered. A phone number that belongs to another hotel reports as not paired, with `user_name` and `user_role` both `null`.

### Responses

| HTTP | `message` | `body` |
| --- | --- | --- |
| `200` | `User has a paired WhatsApp device.` | `{ paired: true, device }` |
| `202` | `User has a paired WhatsApp device, but it is not active.` | `{ paired: true, device }` |
| `201` | `User not paired.` | `{ paired: false, user_role, user_name }` — the user fields are `null` when no user in your hotel(s) has that number |
| `401` | `Unauthenticated.` | Missing or invalid bearer token |
| `422` | Validation error | `phone_number` missing, or not 7–15 digits once formatting is stripped |

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

- Call `POST /api/connect` as an authenticated user to generate a WhatsApp pairing token — it expires in 15 minutes and works once
- Call `POST /api/pair` with `phone_number`, `token`, and `wa_user_id`
- Call `GET /api/check-paired` with a bearer token to poll pairing status
- Always send `X-API-KEY` if the backend server uses `API_KEY`
- Expect `401` for an invalid token and `400` for business-rule failures
- On success, the device is created with `status = active`
