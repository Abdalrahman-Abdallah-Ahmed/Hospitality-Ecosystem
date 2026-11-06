# Quickstart: Validating the Route Move

Expected results refer to the [contract](contracts/superadmin-routes.md) and the spec's success criteria.

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
```

## 1. Before changing anything: take the route snapshot

```bash
php artisan route:list --json > <scratchpad>/routes-before.json
```

This reads the route table only and doesn't touch a database. It can't be recreated after the move without checking out the old code.

## 2. After the move: refresh local caches

```bash
composer dump-autoload
php artisan optimize:clear
```

## 3. Compare the routes

```bash
php artisan route:list --json > <scratchpad>/routes-after.json
```

Parse both files as JSON first. The raw text escapes its paths (`api\/admin\/usage`, `Controllers\\Admin\\`), so a text replacement on the file won't match.

From each parsed route, take `method`, `uri`, `action` and `middleware`. In the "before" routes, replace `api/admin` with `api/superadmin` in `uri` and `Controllers\Admin\` with `Controllers\Superadmin\` in `action`. Then sort both lists by method and URI, because the new prefix changes the sort order, and compare.

**Expected**: no differences in method, URI, action or middleware, for any route. This covers FR-002, FR-003, FR-008 and SC-005.

## 4. Count the routes (this stage only)

```bash
php artisan route:list --path=api/superadmin --json
php artisan route:list --path=api/admin --json
```

**Expected**: 25 routes under the new prefix (SC-001) and none under the old one. Later stages add routes, so the 25 stops being true after this stage.

## 5. Run the automated checks

```bash
php artisan test --filter=RouteMoveTest
php artisan test --parallel
./vendor/bin/pint --test
```

**Expected**: all pass. `RouteMoveTest` covers SC-001, SC-002 and SC-004. The full run includes `CrossTenantRouteTest`, `EntryPointInventoryTest` and `CostVisibilityTest` (FR-012).

## 6. Confirm the test changes are address-only

Review the diff of `tests/`, with renames detected. Apart from the new test, the changes should only be URLs, the moved paths, comments and the `CostVisibilityTest` filter and allowlist.

**Expected**: no expected status code or response assertion changes (SC-003).

## 7. Search for leftovers

```bash
git grep -nE "api/admin|routes/admin|Controllers.Admin|Feature/Admin" -- app bootstrap database routes tests CLAUDE.md "docs/*api-documentation.md" docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md
```

**Expected**: matches only in `RouteMoveTest`, which proves the old address is gone (SC-006).

## 8. Try it by hand (optional)

With the app running and a super admin token issued before the move, which also confirms existing tokens keep working:

| Request | Expected |
| --- | --- |
| `GET /api/superadmin/usage` | `200` |
| `GET /api/superadmin/knowledge-documents/deleted` | `200` with a list, not read as an id |
| `GET /api/admin/usage` | `404` |
| `GET /api/superadmin/usage` as a hotel admin | `403` |

## 9. Check the breaking-change note

Using only `docs/latest-changes-<date>.md`, find the new address for each of the 25 old ones, and confirm it says both applications must deploy together (SC-007).
