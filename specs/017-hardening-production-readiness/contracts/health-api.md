# Contract: Health Checks

Two checks (spec Clarifications, FR-026, R11). Neither returns versions, hostnames,
exception messages or credentials.

**Breaking**: none. `/up` is unchanged; `/api/health` is new.

## `GET /up` — public, shallow

The Laravel built-in route, unchanged. No API key, no token. Used by the load balancer and
the Sentry Uptime monitor.

| Result | Response |
| --- | --- |
| App boots | `200`, Laravel's default body |
| App cannot boot | `500` |

## `GET /api/health` — full, token-protected

- **Middleware**: `api` and `throttle:30,1` only. It sits outside `api.key`,
  `auth:sanctum` and `tenant`, so a monitor needs nothing but the token.
- **Request header**: `X-Health-Token: <HEALTH_CHECK_TOKEN>`. Compared with `hash_equals`.
- **Caller**: the deploy workflow's final step, and the Sentry Uptime monitor every minute
  (it sends the `X-Health-Token` header). The token lives in the server
  `.env` and GitHub secrets only.

| Case | Status | Body (`apiResponse` envelope) |
| --- | --- | --- |
| Header missing or wrong, or no token configured on the server | `401` | `{ "message": "Unauthorized.", "code": 401, "body": null }` |
| All checks pass | `200` | `{ "message": "Healthy.", "code": 200, "body": { "status": "healthy", "checks": { "database": "ok", "queue": "ok", "cache": "ok" } } }` |
| Any check fails or times out (2 s each) | `503` | `{ "message": "Unhealthy.", "code": 503, "body": { "status": "unhealthy", "checks": { "database": "ok", "queue": "fail", "cache": "ok" } } }` |
| More than 30 calls/min from one IP | `429` | standard throttle response |

What each check does:

| Check | Passes when |
| --- | --- |
| `database` | `select 1` on the default connection succeeds |
| `queue` | a read of the `jobs` table on the queue connection succeeds (on the MVP's `database` queue; after the Redis upgrade this becomes `PING` on its connection) |
| `cache` | put, get and forget of a random `health:{uuid}` key round-trips |

The order of keys in `checks` is fixed. A failure never adds an error message or exception
class to the body; the failure is reported to Sentry instead (R13).

## Tests (`HealthCheckTest`)

- `401` without the header, with a wrong token, and when `health.token` is empty.
- `200` with the token when all three checks pass.
- `503` with the failing check marked `fail`, when the cache or the queue connection is faked to throw.
- The body contains no `APP_VERSION`, host name, connection name or exception text.
- `/up` stays `200` with no token.
