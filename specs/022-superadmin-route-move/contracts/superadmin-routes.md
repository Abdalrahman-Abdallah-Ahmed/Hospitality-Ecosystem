# Contract: Super Admin Routes

The 25 endpoints are listed in the spec's [Endpoint Map](../spec.md#endpoint-map). Each keeps its method, the path after the prefix, its request and its response. Existing request and response details stay in the `docs/*-api-documentation.md` files, with only the prefix changed.

## Addresses

| | Before | After |
| --- | --- | --- |
| Prefix | `/api/admin` | `/api/superadmin` |
| Old prefix | n/a | No routes |

## Middleware

Every route under `/api/superadmin` has exactly this stack, in this order, applied to the whole file at registration:

```text
api → api.key → auth:sanctum → throttle:api → tenant → super_admin
```

## Status codes

| Request | Response |
| --- | --- |
| Any caller, any old `/api/admin/...` address | `404` |
| New address, super admin | Same as the old address returned |
| New address, hotel admin or employee | `403` |
| New address, no valid login token | `401` |
| New address, no valid API key | `401` |
| `/api/superadmin` with nothing after it | `404` |

`404` on an old address uses the standard JSON not-found response for `api/*` paths. No route answers there, so it comes before any middleware runs.
