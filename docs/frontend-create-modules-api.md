# Frontend Create Modules API Guide

This document is a frontend-focused guide for the resource create endpoints defined in [routes/api.php](/D:/Hospitality%20Ecosystem/routes/api.php:25).

It is meant for building the create modules/forms for these resources:

- `hotel`
- `activity`
- `reservation`
- `room`
- `guest`

This guide focuses on `POST` create behavior, payloads, validation expectations, and the ownership/scoping rules the frontend needs to respect.

## Base URL

All endpoints below are served under:

`/api`

Examples:

- `POST /api/hotel`
- `POST /api/activity`
- `POST /api/reservation`
- `POST /api/room`
- `POST /api/guest`

## Required Headers

Every create request below should send:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

Notes:

- `X-API-KEY` is checked by the `api.key` middleware.
- `Authorization: Bearer {login_token}` is required because all resource routes are inside `auth:sanctum`.
- Get the login token from `POST /api/login`.

## Standard Success Response

Successful responses use this wrapper:

```json
{
  "message": "Resource created successfully.",
  "code": 201,
  "body": {}
}
```

- `message` is safe to show in a toast.
- `code` repeats the HTTP status.
- `body` contains the created resource.

## Validation Error Response

Field validation failures return Laravel's default `422` shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Human-readable error message."]
  }
}
```

## Business Rule Errors

Some requests pass field validation but still fail ownership checks. Those use the API wrapper instead of the Laravel `errors` shape:

```json
{
  "message": "The selected hotel does not belong to you.",
  "code": 403,
  "body": null
}
```

The frontend should treat these as form-level errors.

## Frontend Rules That Apply To Most Create Forms

- Always send `Accept: application/json`.
- Treat all ids as UUID strings, not integers.
- For `hotel_id`, always use the logged-in admin's own hotel id unless the resource is `hotel` itself.
- Do not expose arbitrary hotel selection for `activity`, `reservation`, `room`, or `guest`.
- For enum-like fields that the backend does not fully restrict, enforce safe options in the frontend.
- For JSON fields, send real JSON objects/arrays, not stringified JSON blobs.

## 1. Create Hotel

### Endpoint

`POST /api/hotel`

### Purpose

Creates a hotel on behalf of another user. **This is a super-admin-only endpoint**, not a self-service "create my hotel" flow — see [Hotel API Documentation](/D:/Hospitality%20Ecosystem/docs/hotel-api-documentation.md) for the full authorization model. A regular admin gets their hotel by registering via `POST /api/register` (nested `hotel` object — see [Register API Update](/D:/Hospitality%20Ecosystem/docs/register-api-update.md)), not through this endpoint.

### Important Backend Behavior

- Caller must have `role: "super_admin"` — any other role gets `403`.
- `owner_id` must be sent explicitly (it identifies who the hotel is being created for) — it is **not** auto-filled from the caller, since the caller (a super admin) is generally not the intended owner.
- Recreating a soft-deleted hotel's `slug` with the same `owner_id` restores that hotel instead of erroring or creating a duplicate; with a different `owner_id` it's rejected. See the full doc for details.

### Request Body

```json
{
  "owner_id": "019f9b37-c260-7abc-9000-1234567890ab",
  "name": "Grand Harbor Hotel",
  "slug": "grand-harbor-hotel",
  "timezone": "Africa/Cairo",
  "currency": "USD",
  "country_code": "EG",
  "city": "Cairo",
  "address": "Nile Corniche",
  "whatsapp_number": "+201234567890",
  "email": "frontdesk@example.com",
  "phone": "+20212345678",
  "branding": {
    "primary_color": "#0f766e"
  },
  "ai_preferences": {
    "tone": "friendly"
  },
  "is_active": true
}
```

### Field Notes

- `name` is required.
- `owner_id` is required in practice (see above), must exist in `users.id`.
- `slug` is required and must be unique among active hotels.
- `timezone` defaults to `UTC` if omitted.
- `currency` defaults to `USD` and must be a 3-character string.
- `country_code` should be a 2-character string.
- `branding` and `ai_preferences` are JSON objects/arrays.
- `is_active` is boolean and defaults to `true`.

### Frontend Recommendations

- Build this as a super-admin-only "provision a hotel" form, not part of the regular admin onboarding flow.
- Include an owner picker (search/select an existing user) since `owner_id` must be sent explicitly.
- Validate `slug` format client-side before submit.
- Validate `email` format client-side even if the generic backend validation is permissive.
- Use a timezone dropdown instead of free text if possible.
- Use a 3-letter uppercase currency code input.

## 2. Create Activity

### Endpoint

`POST /api/activity`

### Purpose

Creates a hotel activity for the authenticated user's hotel.

### Important Backend Behavior

- `hotel_id` is always forced to the logged-in user's hotel, regardless of what's sent in the request body.
- `category_id`, if sent, must belong to the caller's own hotel or the API returns `403`.
- Authorization is enforced via `ActivityPolicy` (admin-only for view/create/update/delete).

### Request Body

```json
{
  "category_id": "019fabcd-1234-7000-9000-123456789abc",
  "name": "Airport Pickup",
  "description": "Private airport transfer for guests.",
  "price": 35.5,
  "currency": "USD",
  "is_active": true
}
```

### Field Notes

- `category_id` is optional, but if sent it must belong to the caller's hotel.
- `name` is required.
- `description` is optional text.
- `price` is numeric and defaults to `0`.
- `currency` is a 3-character string and defaults to `USD`.
- `is_active` is boolean and defaults to `true`.

### Frontend Recommendations

- Auto-fill `hotel_id` from the logged-in user's hotel and hide it from the form.
- If the UI has no activity-category picker yet, omit `category_id`.
- Treat `price` as numeric input, but expect decimal values to come back as strings in many Laravel JSON responses.

## 3. Create Reservation

### Endpoint

`POST /api/reservation`

### Purpose

Creates a reservation for the authenticated user's hotel.

### Important Backend Behavior

- `hotel_id` must belong to the logged-in user.
- `guest_id` must belong to the same hotel as `hotel_id`.
- `room_id` is optional, but if sent it must also belong to the same hotel.
- `reservation_id` is not auto-generated on this endpoint; the frontend must send it.

### Request Body

```json
{
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1",
  "room_id": "019f9b37-c268-738c-bc46-53281c1763cf",
  "reservation_id": "RES-ABC12345",
  "arrival_date": "2026-09-01",
  "departure_date": "2026-09-04",
  "status": "confirmed",
  "adults": 2,
  "children": 1,
  "source": "walk_in",
  "special_requests": "Late check-in",
  "reservation_value": 450.5,
  "currency": "USD"
}
```

### Field Notes

- `hotel_id`, `guest_id`, `reservation_id`, `arrival_date`, and `departure_date` are required.
- `room_id` is optional.
- `status` must be one of the backend enum values:
  - `pending`
  - `confirmed`
  - `checked_in`
  - `checked_out`
  - `cancelled`
- `adults` and `children` are integers.
- `reservation_value` is numeric.
- `currency` must be a 3-character string.

### Frontend Recommendations

- Auto-fill and hide `hotel_id`.
- Build guest and room pickers from the current hotel's own data only.
- Enforce `departure_date >= arrival_date` client-side because the backend does not currently enforce that relationship.
- Generate a reservation code client-side if the product expects a consistent format.

## 4. Create Room

### Endpoint

`POST /api/room`

### Purpose

Creates a room for the authenticated user's hotel.

### Important Backend Behavior

- `hotel_id` must match the logged-in user's hotel id.
- A different hotel id returns `403`.
- Room authorization is also protected by `RoomPolicy`.

### Request Body

```json
{
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "room_number": "101",
  "room_type": "double",
  "floor": "1",
  "status": "available"
}
```

### Field Notes

- `hotel_id` is required.
- `room_number`, `room_type`, `floor`, and `status` are optional strings.
- `status` defaults to `available` at the database level if omitted.
- `status` is not restricted to a fixed enum server-side.

### Frontend Recommendations

- Auto-fill and hide `hotel_id`.
- Use a select for `status` instead of free text.
- If your product has a fixed room type list, enforce it client-side because the backend currently accepts any string.

## 5. Create Guest

### Endpoint

`POST /api/guest`

### Purpose

Creates a guest for the authenticated user's hotel.

### Important Backend Behavior

- `hotel_id` must match the logged-in user's hotel id.
- A different or missing `hotel_id` returns `403`.
- Guest authorization is also protected by `GuestPolicy`.
- **This endpoint may return an existing guest instead of creating a new one.** If the submitted `email` or `phone_number` matches a guest already at this hotel — even under a totally different `channel`/`external_id` — that existing guest is reused (still `201`), so the same real person doesn't end up as two rows just because they booked through a different channel. The response reflects the *existing* record's data, not the just-submitted fields — render whatever comes back in `body`, don't assume it echoes the form. There's currently no field indicating "this was reused, not newly created."

### Request Body

```json
{
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "first_name": "Youssef",
  "last_name": "Kamal",
  "email": "youssef@example.com",
  "phone_number": "+201234567890",
  "preferred_language": "en",
  "nationality": "EG",
  "preferences": {
    "bed_type": "king"
  },
  "loyalty_status": "gold",
  "marketing_consent": true,
  "external_id": "OTA-9981",
  "channel": "booking_com"
}
```

### Field Notes

- `hotel_id` is effectively required by controller behavior.
- `first_name`, `last_name`, `email`, `phone_number`, `nationality`, `loyalty_status`, and `external_id` are optional strings.
- `preferred_language` defaults to `en`.
- `preferences` is a JSON object/array.
- `marketing_consent` is boolean.
- `channel` must be one of the allowed reservation-channel enum values.

### Allowed `channel` Values

- `booking_com`
- `expedia`
- `airbnb`
- `agoda`
- `tripadvisor`
- `vrbo`

You can also fetch these from:

`GET /api/available-channels`

### Frontend Recommendations

- Auto-fill and hide `hotel_id`.
- Validate `email` format client-side.
- Use a select for `channel`, ideally populated from `GET /api/available-channels`.
- Use switches/checkboxes for `marketing_consent`.

## Suggested Create-Form Strategy

For the frontend create modules, this setup will map well to the backend:

- `hotel`: standalone form, no `hotel_id`, no `owner_id`.
- `activity`: hidden `hotel_id`, optional `category_id`, JSON-capable advanced settings.
- `reservation`: hidden `hotel_id`, guest picker required, room picker optional.
- `room`: hidden `hotel_id`, simple text/select fields.
- `guest`: hidden `hotel_id`, channel select, preferences JSON or structured sub-fields.

## Most Important Gotchas

- Never let the frontend submit another hotel's `hotel_id` for `activity`, `reservation`, `room`, or `guest`.
- `POST /api/hotel` is super-admin-only and requires an explicit `owner_id` — it is not the regular hotel-onboarding flow (that's `POST /api/register`).
- Do not expect the reservation create endpoint to generate `reservation_id`.
- Do not rely on the backend to validate all product rules like room-status options or reservation date ordering.
- Use form-level handling for wrapped business-rule errors like `"The selected hotel does not belong to you."`

## Related Docs

- [Auth API Documentation](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md)
- [Register API Update](/D:/Hospitality%20Ecosystem/docs/register-api-update.md)
- [Hotel API Documentation](/D:/Hospitality%20Ecosystem/docs/hotel-api-documentation.md)
- [Hotel Policy API Documentation](/D:/Hospitality%20Ecosystem/docs/hotel-policy-api-documentation.md)
- [Guest API Documentation](/D:/Hospitality%20Ecosystem/docs/guest-api-documentation.md)
- [Reservations API Documentation](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md)
- [Room API Documentation](/D:/Hospitality%20Ecosystem/docs/room-api-documentation.md)
- [Activity API Documentation](/D:/Hospitality%20Ecosystem/docs/activity-api-documentation.md)
