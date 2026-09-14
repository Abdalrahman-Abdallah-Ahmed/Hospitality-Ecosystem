# Latest Backend Changes — 2026-09-14

Fixes for issues found by the frontend Playwright QA run.

## 1. Breaking: Login and Register Are Rate Limited

`POST /api/login` allows 5 attempts per minute per email + IP, and 20 per minute per IP across all emails. `POST /api/register` allows 5 per minute per IP. Over the limit you get `429 Too Many Attempts.` with a `Retry-After` header.

**Full doc:** [Auth API § Rate Limiting](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md#rate-limiting)

## 2. Breaking: CORS Only Allows the Configured Frontend

`Access-Control-Allow-Origin` was `*`. The API now answers browsers only from origins in `CORS_ALLOWED_ORIGINS` (comma-separated, default `http://localhost:3000`). A frontend on any other origin fails the preflight.

## 3. New: Employees Can Read Guests and Activities

Employees could create bookings but got `403` on the guest and activity lists the booking form needs. They can now call `GET /api/guest`, `GET /api/guest/{id}`, `GET /api/activity` and `GET /api/activity/{id}` for their own hotel. Create, update and delete stay admin-only.

**Full docs:** [Guest API](/D:/Hospitality%20Ecosystem/docs/guest-api-documentation.md#who-can-call-these-endpoints), [Activity API](/D:/Hospitality%20Ecosystem/docs/activity-api-documentation.md#who-can-call-these-endpoints)

## 4. Fix: `GET /api/task-category/{id}` Works

It returned `500` because the route existed without a controller method. It now returns the task category (admins of its hotel, and super admins), with `hotel` and `team` loaded.

**Full doc:** [Task Management API § 2.2](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md#22-get-a-task-category--get-apitask-categoryid)

## 5. Ops: Debug Output Is Never Served Outside Local

A `500` included a stack trace with server paths because `APP_DEBUG=true`. Debug mode is now forced off unless `APP_ENV` is `local` or `testing`, whatever `APP_DEBUG` says. Still set `APP_DEBUG=false` in deployed `.env` files.

## Unchanged, Confirmed

- `GET /api/hotel-policy/{id}` returns `405`: there is deliberately no single-policy endpoint. The URI exists for `PUT` and `DELETE`, so `405 Method Not Allowed` is the correct status. Build the detail view from the list row.
- `X-API-KEY` is a client identifier, not a secret. Access control is the bearer token plus per-role policies.

## Checklist

- [ ] Set `CORS_ALLOWED_ORIGINS` on each server to its frontend URL(s) before this deploys.
- [ ] Confirm `APP_ENV=production` and `APP_DEBUG=false` on deployed servers.
- [ ] Login and register pages: handle `429` using `Retry-After`.
- [ ] Booking form: load the guest and activity pickers for employees too.
