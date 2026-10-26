# Health API

Two health checks (spec 017, FR-026). Neither returns versions, host names,
error messages or credentials.

## `GET /up`: public

Laravel's built-in check. No API key, no token. `200` when the application
boots, `500` when it cannot. Use it for a simple "is it up" probe.

## `GET /api/health`: full, token-protected

Proves the database, the queue and the cache answer. Used by the deploy
workflow's last step and by the uptime monitor.

- **Auth:** header `X-Health-Token: <HEALTH_CHECK_TOKEN>`. No API key, no login.
- **Throttle:** 30 calls per minute per IP.

| Case | Status | Body |
| --- | --- | --- |
| Header missing or wrong, or no token configured on the server | `401` | `{ "message": "Unauthorized.", "code": 401, "body": null }` |
| All checks pass | `200` | `{ "message": "Healthy.", "code": 200, "body": { "status": "healthy", "checks": { "database": "ok", "queue": "ok", "cache": "ok" } } }` |
| A check fails | `503` | `{ "message": "Unhealthy.", "code": 503, "body": { "status": "unhealthy", "checks": { "database": "ok", "queue": "fail", "cache": "ok" } } }` |
| Over the throttle | `429` | standard throttle response |

| Check | Passes when |
| --- | --- |
| `database` | `select 1` succeeds |
| `queue` | the `jobs` table can be read through the queue's connection |
| `cache` | a random key can be written, read back and removed |

A failure never adds its reason to the body. It is reported to error tracking
(Sentry) instead.

**Setup:** set `HEALTH_CHECK_TOKEN` (32+ random characters) in the server
`.env`, and the same value as the `HEALTH_CHECK_TOKEN` GitHub secret.
