# Latest Backend Changes — 2026-09-19

Follow-up to the [system design assessment](system-design-assessment-2026-09-19.md). Three changes need frontend work. The rest changes server behaviour only.

## 1. Breaking: Login Tokens Expire After 7 Days

Tokens from `POST /api/login` and `POST /api/register` used to be valid forever. They now expire 7 days after they are issued. An expired token gets `401` on every protected route.

**What to change in the frontend:**

- Treat any `401` from a protected route as "session ended": clear the stored token and redirect to the login page.
- There is no refresh endpoint. The user logs in again.

**Full docs:** [Auth API § Token Lifetime](auth-api-documentation.md#token-lifetime)

## 2. Breaking: API Key Accepted From the Header Only

`?api_key=` in the URL is no longer accepted and gets `401`. Send `X-API-KEY` as a header, which all documented examples already do.

**What to change in the frontend:** only something that builds URLs with `api_key=` (for example a download link) needs changing. Everything else is unaffected.

## 3. New: Rate Limit on Every Authenticated Route

Authenticated routes now allow 240 requests per minute per user, and `POST /api/pair` allows 10 per minute per IP. Over the limit the API returns `429` with a `Retry-After` header.

**What to change in the frontend:** a normal dashboard stays far below this. If you poll, make sure a `429` backs off for `Retry-After` seconds instead of retrying immediately.

**Full docs:** [Auth API § Rate Limiting](auth-api-documentation.md#rate-limiting)

## 4. WhatsApp Concierge Behaviour (No Client Change)

- Meta redeliveries no longer produce duplicate replies, bookings or tasks.
- Every message in a batched webhook is answered, not only the first.
- A guest known at several hotels reaches the hotel where they are staying, not an arbitrary one.
- Admins whose phone number was saved with `+`, spaces or dashes are now recognised.
- Each number is limited to 10 messages per minute (`WHATSAPP_INBOUND_PER_MINUTE`).
- A failed agent turn gets an apology reply instead of silence.
- The concierge runs inside the guest's hotel scope, and the shared global knowledge base is visible to it (and to the in-app advisor chat, where global articles were previously dropped).

**Full docs:** [WhatsApp Device API § Inbound Messages](whatsapp-device-api-documentation.md#inbound-messages-meta-webhook)

## 5. Server Configuration

New or changed environment variables. All have defaults, so none is required.

| Variable | Default | Purpose |
| --- | --- | --- |
| `SANCTUM_TOKEN_EXPIRATION` | `10080` (7 days) | Login token lifetime in minutes. Empty turns expiry off. |
| `WHATSAPP_INBOUND_PER_MINUTE` | `10` | Messages one phone number may send per minute. |
| `AI_COST_GUEST_CEILING_SHARE` | `0.60` | Share of the daily AI ceiling that guest traffic may use. |
| `DB_QUEUE_RETRY_AFTER` | `360` (was `90`) | Must stay above the WhatsApp job's 300 s timeout. If the server's `.env` sets it lower, raise it. |

Two new migrations: `whatsapp_inbound_messages`, and expression indexes on phone digits for `guests`, `users` and `whats_app_devices`.

## 6. Deployment

- Pushing to `main` now runs `pint --test` and the full test suite against Postgres + pgvector first. A failure stops the deploy.
- The deploy script runs `migrate --force` and `composer install --no-dev`.
