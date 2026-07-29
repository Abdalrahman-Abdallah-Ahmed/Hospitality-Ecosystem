# Register API Update — Hotel Onboarding

This document describes changes to `POST /api/register`. The register form must be
updated to collect hotel details, since registering now creates the user **and**
their hotel in one call.

This supersedes the "1. Register" section in `docs/auth-api-documentation.md`.
Login, logout, headers, and response envelope are unchanged — see that doc for those.

## Endpoint

`POST /api/register`

Headers are unchanged: send `X-API-KEY` (if configured), `Content-Type: application/json`,
`Accept: application/json`.

## Request Body

The payload now nests hotel fields under a `hotel` object:

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "hotel": {
    "name": "Grand Palace Hotel",
    "city": "Cairo",
    "country_code": "EG",
    "address": "123 Nile Street",
    "timezone": "Africa/Cairo",
    "currency": "EGP",
    "email": "info@grandpalace.com",
    "phone": "+201000000000",
    "whatsapp_number": "+201000000001"
  }
}
```

### Field Requirements

| Field | Required | Notes |
|---|---|---|
| `name` | yes | user's full name, max 255 |
| `email` | yes | unique in `users`, valid email |
| `password` | yes | min 8, must be confirmed |
| `password_confirmation` | yes | must match `password` |
| `hotel` | yes | object |
| `hotel.name` | yes | max 255, unique in `hotels` |
| `hotel.city` | yes | max 255 |
| `hotel.country_code` | no | 2-letter ISO code |
| `hotel.address` | no | max 255 |
| `hotel.timezone` | no | defaults to `UTC` server-side if omitted |
| `hotel.currency` | no | 3-letter ISO code, defaults to `USD` if omitted |
| `hotel.email` | no | valid email, unique in `hotels` |
| `hotel.phone` | no | max 255 |
| `hotel.whatsapp_number` | no | max 255 |

Only `hotel.name` and `hotel.city` are required on the hotel side — the rest can be
left out of the initial register form and filled in later from hotel settings if you
want a shorter signup flow.

## Success Response

HTTP `201 Created`

```json
{
  "message": "User registered successfully.",
  "code": 201,
  "body": {
    "user": {
      "id": "0f2b...uuid",
      "name": "John Doe",
      "email": "john@example.com",
      "role": "admin"
    },
    "hotel": {
      "id": "8a1c...uuid",
      "name": "Grand Palace Hotel",
      "slug": "grand-palace-hotel"
    }
  }
}
```

Notes:

- The registering user is automatically made the hotel's owner/admin (`role: "admin"`).
- `hotel.slug` is generated from `hotel.name` server-side — don't send a slug.
- `user.id` / `hotel.id` are UUID strings, not integers.
- As before, this endpoint does **not** log the user in or return a token — call
  `/api/login` next to get a bearer token.

## Validation Errors

Same `422` shape as other endpoints (see `docs/auth-api-documentation.md`), but errors
for hotel fields are nested, e.g.:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "hotel.name": ["The hotel.name field is required."],
    "hotel.city": ["The hotel.city field is required."]
  }
}
```

When rendering field errors, map `hotel.name` / `hotel.city` / etc. back to the
corresponding hotel form fields (not the top-level `name` field, which is the user's name).

## Frontend Checklist

- [ ] Add a "Hotel" section to the register form with `name` and `city` as required
      fields, plus optional `country_code`, `address`, `timezone`, `currency`,
      `email`, `phone`, `whatsapp_number`.
- [ ] Submit the payload with hotel fields nested under `hotel`, not flattened.
- [ ] Read `body.hotel` from the success response and store `hotel.id` / `hotel.slug`
      alongside the user if the app needs it before the next `/api/login` call.
- [ ] Update error handling to read `errors["hotel.name"]`, `errors["hotel.city"]`, etc.
