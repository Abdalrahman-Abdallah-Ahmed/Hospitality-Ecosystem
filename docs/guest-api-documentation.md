# Guest API Documentation

This document describes the guest management APIs the frontend team should use to build the guests list/detail/create/edit UI.

It covers the **admin-facing CRUD endpoints** only (`index`, `store`, `show`, `update`, `destroy`), defined by `App\Http\Controllers\GuestController`.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `GET /api/guest`
- `POST /api/guest`
- `GET /api/guest/{id}`
- `PUT /api/guest/{id}`
- `DELETE /api/guest/{id}`

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

Notes:

- `X-API-KEY` is checked by the `api.key` middleware. If `API_KEY` is unset on the server, this header is not enforced; when it is set, a missing/wrong key returns HTTP `401`.
- `Authorization: Bearer {login_token}` is required because every guest route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login` (see `docs/auth-api-documentation.md`).
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before controller/policy logic runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\GuestPolicy`, on top of the bearer-token check above:

| Action | Rule |
| --- | --- |
| `index` (list) | The logged-in user's `role` must be `admin`. |
| `store` (create) | The logged-in user's `role` must be `admin`. |
| `show` / `update` / `destroy` | The user must be `admin`, **and** the guest's `hotel_id` must equal the hotel the user owns. |

Practical implications for the UI:

- A non-admin user should never reach this screen; treat any `403` here as "this user should not be on this page," not an in-page recoverable state.
- A `403` on `show`/`update`/`destroy` for a specific guest id most likely means the id belongs to a different hotel. Treat it the same as "not found or not yours."
- `index` is additionally query-scoped to `where('hotel_id', <the user's hotel>)`, so the list only ever contains guests from the current admin's hotel.

## Response Format

Successful custom API responses use this structure:

```json
{
  "message": "Some message",
  "code": 200,
  "body": {}
}
```

- `message`: human-readable message, safe to show in a toast.
- `code`: repeats the HTTP status code.
- `body`: the actual payload (a guest object, a paginated list, or `null`).

**Validation errors (`422`) are the main exception**: they use Laravel's default shape, not the `message/code/body` wrapper.

## The Guest Object

Every endpoint that returns a guest returns it with `hotel`, `reservations`, `conversations`, and **`stays`** eager-loaded:

```json
{
  "id": "019fabcd-1234-7000-9000-123456789abc",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "first_name": "Youssef",
  "last_name": "Kamal",
  "email": "youssef@example.com",
  "phone_number": "+201234567890",
  "preferred_language": "en",
  "nationality": "EG",
  "preferences": {
    "bed_type": "king",
    "high_floor": true
  },
  "loyalty_status": "gold",
  "marketing_consent": true,
  "external_id": "OTA-9981",
  "channel": "booking",
  "identity_hash": "phone:9f2c1a...",
  "identity_resolved_at": "2026-07-31T09:15:00.000000Z",
  "created_at": "2026-07-31T09:15:00.000000Z",
  "updated_at": "2026-07-31T09:15:00.000000Z",
  "deleted_at": null,
  "hotel": { "id": "...", "name": "Hotel Name", "...": "..." },
  "reservations": [
    { "id": "...", "reservation_id": "RES-ABC123", "...": "..." }
  ],
  "conversations": [
    { "id": "...", "...": "..." }
  ],
  "stays": [
    {
      "id": "019fabcd-...",
      "hotel_id": "019f9b37-...",
      "guest_id": "019fabcd-1234-7000-9000-123456789abc",
      "reservation_id": "019fabcd-...",
      "room_id": "019fabcd-...",
      "planned_arrival_date": "2026-09-01",
      "planned_departure_date": "2026-09-04",
      "checked_in_at": "2026-09-01T14:32:00.000000Z",
      "checked_out_at": null,
      "status": "in_house",
      "adults": 2,
      "children": 0,
      "nights": null,
      "room_revenue": "450.00",
      "currency": "USD",
      "market_segment": null,
      "source_channel": "whatsapp",
      "created_at": "2026-09-01T09:00:00.000000Z",
      "updated_at": "2026-09-01T14:32:00.000000Z",
      "deleted_at": null
    }
  ]
}
```

Field notes for the UI:

- `id` and `hotel_id` are UUID strings, not integers.
- `preferences` is a JSON object/array field and comes back as parsed JSON, not a string.
- `marketing_consent` is a boolean.
- `preferred_language` defaults to `"en"` at the database level when omitted on create.
- `channel` is an enum-like field server-side. It must be one of the reservation channel values defined by the backend; do not send arbitrary strings.
- `email` is currently only validated as a plain string by the generic validator, not with an email-format rule. The frontend should still validate email format client-side.
- Guests use `SoftDeletes`, so `DELETE` does **not** permanently erase the row.
- **`identity_hash`** and **`identity_resolved_at`** are internal fields (Phase 1 WP-2) used to detect the same person arriving through a different channel — see [Create a Guest](#2-create-a-guest). Not meant for display; `identity_hash` is a one-way hash of the guest's normalised email or phone, not the raw value.
- **`stays`** (Phase 1 WP-2) is every stay this guest has had — one per reservation, in booking order, not just the current/latest one. A reservation without a room assigned still produces a stay (`room_id: null`). `status` is one of `expected` (booked, not arrived), `in_house`, `departed`, `no_show`, or `cancelled`. `checked_in_at`/`checked_out_at`/`nights` are `null` until they actually happen — don't assume they mirror the reservation's `arrival_date`/`departure_date` (those are the *planned* dates; a guest can check out early or late). There is no separate `GET /api/stay` endpoint — stays only ever arrive nested here or on the reservation.
- Because `reservations`, `conversations`, and `stays` are all eager-loaded, a guest detail response can be noticeably larger than a room response. The list endpoint also includes these nested relations in each row — for a hotel with long-tenured repeat guests, `stays` can grow to cover their entire history.

## 1. List Guests

### Endpoint

`GET /api/guest`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[channel]=booking` | Exact match on any real `guests` column. |
| `search` | string | `search=Youssef` | Partial (`LIKE %term%`) match across the guest's string/text columns such as `first_name`, `last_name`, `email`, `phone_number`, `preferred_language`, `nationality`, `loyalty_status`, `external_id`, `channel`. |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1-100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the guests table's real columns, so an unknown key returns a `422`.

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object, and each row in `body.data` is a full [guest object](#the-guest-object) with nested relations.

For the UI: read the list from `body.data`, and drive pagination controls from `body.current_page`, `body.last_page`, and `body.total`.

### Error: Unknown Filter/Sort Column

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "filter.not_a_column": ["Unknown filter column [not_a_column]."]
  }
}
```

## 2. Create a Guest

### Endpoint

`POST /api/guest`

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
  "channel": "booking"
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | Effectively required by controller behavior. Must exist in `hotels.id`, and must belong to the logged-in admin. |
| `first_name` | optional, string, max 255. |
| `last_name` | optional, string, max 255. |
| `email` | optional, string, max 255. Frontend should validate format client-side. |
| `phone_number` | optional, string, max 255. |
| `preferred_language` | optional, string, max 10. Defaults to `"en"` if omitted. |
| `nationality` | optional, string, max 255. |
| `preferences` | optional, array/object. |
| `loyalty_status` | optional, string, max 255. |
| `marketing_consent` | optional, boolean. Defaults to `false` if omitted. |
| `external_id` | optional, string, max 255. |
| `channel` | optional, must be one of the backend enum values. |

**Important - hotel scoping:** even though the underlying DB column is nullable, this endpoint will reject a missing or wrong `hotel_id` with a business-rule `403`, because the controller checks that the payload's `hotel_id` matches the logged-in admin's hotel. The frontend should always send the current admin's hotel id and should not expose a hotel picker for this screen.

### Success Response

HTTP `201 Created`. `body` is a full [guest object](#the-guest-object).

**Note:** this may return an *existing* guest instead of creating a new row. The only uniqueness this endpoint enforces at the database level is `(hotel_id, channel, external_id)`, so the same real person arriving through a different channel (e.g. once direct, once via Booking.com) would otherwise create a second row for them. Before creating, the backend also checks whether any existing guest at this hotel already has the same `email` or `phone_number`; if so, that guest is reused (and restored, if it was soft-deleted) instead of creating a duplicate — still a `201`, since from the caller's perspective a guest now exists matching what was requested. Check whether `body.id` matches a guest you already knew about if this distinction matters to your UI.

### Error: Missing / Invalid Fields

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "channel": ["The selected channel is invalid."]
  }
}
```

### Error: Guest For a Different Hotel

HTTP `403`:

```json
{
  "message": "The selected hotel does not belong to you.",
  "code": 403,
  "body": null
}
```

Treat this as a form-level error, not a field-level validation error.

## 3. Get a Single Guest

### Endpoint

`GET /api/guest/{id}`

### Success Response

HTTP `200 OK`. `body` is a full [guest object](#the-guest-object).

### Error: Not Found

HTTP `404` if the id does not exist:

```json
{ "message": "No query results for model [App\\Models\\Guest] {id}" }
```

### Error: Belongs to a Different Hotel

HTTP `403`:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a 404 in the UI.

## 4. Update a Guest

### Endpoint

`PUT /api/guest/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change:

```json
{
  "phone_number": "+201111111111",
  "marketing_consent": false,
  "preferences": {
    "bed_type": "twin"
  }
}
```

### Validation Rules

Same field-level rules as [create](#validation-rules), except every field is optional on update.

Important frontend rules:

- Do **not** include `hotel_id` in the update payload.
- If `hotel_id` is present and different from the guest's current hotel, the API rejects the whole request with `422`.
- If `hotel_id` is present as `null`, that still counts as changing it and will also be rejected.

### Success Response

HTTP `200 OK`. `body` is the updated [guest object](#the-guest-object).

### Error: Reassigning to a Different Hotel

HTTP `422`:

```json
{
  "message": "Reassigning a guest to a different hotel is not allowed.",
  "code": 422,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-guest): `404` if the id does not exist, `403` if it belongs to a different hotel.

## 5. Delete a Guest

### Endpoint

`DELETE /api/guest/{id}`

This is a **soft delete**. The row is marked deleted via `deleted_at`; it is not permanently removed.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Guest deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-guest): `404` if the id does not exist, `403` if it belongs to a different hotel.

## Validation Errors

For field-shape validation failures, Laravel returns its default shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Human-readable error message."]
  }
}
```

The UI should distinguish this from custom business-rule errors like:

- `"The selected hotel does not belong to you."` (`403`, wrapped as `message/code/body`)
- `"Reassigning a guest to a different hotel is not allowed."` (`422`, wrapped as `message/code/body`)

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- List with `GET /api/guest`, filter with `filter[column]=value`, free-text search with `search=`, sort with `sort=column` or `sort=-column`, paginate with `page` and `per_page`.
- Read guest rows from `body.data`.
- Guest responses include nested `hotel`, `reservations`, `conversations`, and `stays` (every stay this guest has had, not just the current one).
- Always send the current admin's own `hotel_id` on create.
- Never send `hotel_id` on update.
- `email` should be validated client-side even though the backend currently treats it as a generic string.
- `DELETE` is soft-delete, not hard-delete.
- **New:** `POST /api/guest` no longer creates a duplicate row for a guest who already exists at this hotel with the same `email` or `phone_number`, even if `channel`/`external_id` differ — it reuses (and restores, if soft-deleted) the existing guest instead. Check `body.id` against a guest you already knew about if your UI needs to tell "reused" apart from "newly created."
