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

---

The sections below come from the first three packages of the [conversational pitching plan](PGRIP_Ecosystem_WP_Conversational_Pitching_v1_0.md) (WP-13 to WP-15). None of them turns pitching on. They make guest contact and recommendation delivery measurable.

## 7. New: Guest Contact Timestamps (Read-Only)

Guests and stays now carry `first_contacted_at` and `last_contacted_at`: when the guest first and last messaged the hotel on WhatsApp. On a stay, only messages sent during that stay count. `null` means the guest never messaged.

- Returned on the guest object and on each nested stay. They cannot be set: create and update ignore them.
- Use the stay's value to answer "did this guest talk to us during this visit?". The guest's value covers every visit.
- History before today was rebuilt from stored conversations (`php artisan guests:backfill-contact-timestamps`). It is a minimum, because messages whose turn failed early were never stored.

**What to change in the frontend:** nothing is required. A "last contacted" column or a "never contacted" badge is now possible.

**Full docs:** [Guest API § The Guest Object](guest-api-documentation.md#the-guest-object)

## 8. New: Activity Audience, Duration and Daily Capacity

Activities accept three new optional fields: `audience` (`all`, `family`, `adults_only`), `duration_days` (1–30) and `daily_capacity` (≥ 1). `null` means "unknown" and never excludes an activity. `daily_capacity: 0` is rejected.

**What to change in the frontend:** add the three fields to the activity form. Leave them empty unless someone at the hotel sets them. Don't pre-fill `audience` from the category name.

**Full docs:** [Activity API § Pitching Attributes](activity-api-documentation.md#pitching-attributes)

## 9. Changed: Recommendation Delivery Is Measured

Recommendations now record `delivered_at` and `delivery_channel` (read-only): when and how the guest was actually offered one. They are stamped when the guest reacts in chat, when staff record any outcome other than `not_delivered`, or when a booking carries the recommendation id. When delivery is stamped, `status` moves from `pending` to `sent`. The AI only reading a recommendation does not count.

- **Staff recording an outcome now also records delivery.** Send the real `channel` (`face_to_face`, `phone`, `email`, `whatsapp`). It defaults to `face_to_face`.
- `minutes_to_outcome` is measured from delivery when a delivery was recorded earlier. Otherwise it is still measured from `recommended_at`.
- When a stronger outcome replaces an earlier one, the earlier evidence (the guest's quote, confidence, reason) is kept in the outcome's `context.superseded` instead of being lost.
- Usage: `recommendations_delivered` is now a real count (`measured: true`) and has left `not_measured`. `recommendations.delivered` in the usage report is a number, and `delivered_basis` is `"measured"`.

**Full docs:** [Recommendation Outcome API § Delivery](recommendation-outcome-api-documentation.md#5-delivery), [Usage Metering API](usage-metering-api-documentation.md#not-measured-and-why)

## 10. Changed: Conversion Analytics Counts Measured Delivery

`GET /api/analytics/conversion` now counts `delivered` as the recommendations that actually reached the guest, instead of everything not marked `not_delivered`. `acceptance_rate` and `booking_rate` divide by it, so a recommendation that was only generated no longer lowers them. The response shape is unchanged.

The nightly job now writes `not_delivered` (instead of `expired`) for a recommendation that never reached a guest who has left. So staff should record face-to-face offers, or those will count as not delivered.

**Full docs:** [Conversion Analytics § delivered is measured](conversion-analytics-api-documentation.md#delivered-is-measured)

## 11. Rollout

Three new migrations add nullable columns to `guests`, `stays`, `activities` and `recommendations`. After migrating, run `php artisan guests:backfill-contact-timestamps` once. It is safe to run again. Add `--dry-run` to preview it.

## 12. New: Task `guest_signal` (Read-Only), and Pitching Rules (Off)

Tasks now return `guest_signal`: why a guest-related task exists (`escalation`, `service_request`, `booking_follow_up`, or `null`). Only the WhatsApp concierge sets it, and existing concierge tasks were labelled where their origin was certain. Escalations now also carry the guest's `reservation_id`.

Behind the scenes, every guest WhatsApp message now records whether the concierge would be allowed to suggest an activity, and why or why not (a new `pitch_decisions` table with no API yet). What it *could* offer is whatever `RecommendationAgent` already generated for that reservation — a reservation nobody has ever generated for gets one generated inline, on its first eligible turn, so staff no longer have to trigger it by hand. Pitching itself is **off** (`PITCHING_ENABLED=false`), and nothing is suggested to guests yet.

**What to change in the frontend:** nothing is required. A `guest_signal` badge on task rows is possible. Note that while a guest has an escalation this stay, or an open service request from the last 24 hours, the concierge will not suggest activities to them.

**Full docs:** [Task Management API § The Task Object](task-management-api-documentation.md#the-task-object)

| Variable | Default | Purpose |
| --- | --- | --- |
| `PITCHING_ENABLED` | `false` | Master switch for conversational pitching. Leave off until the owner decisions in the pitching plan (§15) are recorded. |
| `PITCHING_MAX_PER_STAY` | `1` | Unsolicited suggestions per stay. A guest asking for one doesn't count. |

Two more migrations: `tasks.guest_signal` (with the backfill), and the `pitch_decisions` table plus `recommendations.pitch_decision_id`.
