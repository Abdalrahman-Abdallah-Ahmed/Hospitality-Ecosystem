# Research: Super Admin Route Move

The Technical Context has no open unknowns. These are the decisions that shape the work.

## R1: How the new prefix is registered

- **Decision**: Keep one registration in the `then:` block of `bootstrap/app.php`, with the same middleware array, `prefix('api/superadmin')` and `routes/superadmin.php`.
- **Rationale**: This pattern guards the whole file by construction, and FR-010 requires that. The array order also has to stay the same, because `gatherMiddleware()` is asserted in order.
- **Alternatives considered**: `Route::redirect` or a second registration for `/api/admin` (rejected by the spec's hard cut); a `Route::group()` inside the file (a route could then be left outside the guard).

## R2: How the controllers move

- **Decision**: `git mv` the folder and change the namespace line and the imports in the route file. Leave no `class_alias` behind.
- **Rationale**: Only `routes/admin.php` imports these classes, and only one path string refers to the folder (`CostVisibilityTest.php:314`). Nothing resolves them dynamically. `HandlesKnowledgeDocuments` and the tenant `KnowledgeDocumentController` / `KnowledgeBaseArticleController` stay where they are.
- **Alternatives considered**: Leaving the namespace as `Admin` would be a smaller diff, but it leaves the name in the code at odds with the URL, which FR-007 rules out.

## R3: How the new test stays valid in later stages

- **Decision**: `RouteMoveTest` makes three lasting assertions:
  1. Each of the 25 mapped method and path pairs exists under `api/superadmin`.
  2. **Every** route under `api/superadmin` has the exact middleware stack, so routes added later are covered too.
  3. No registered route starts with `api/admin`, and the 25 old addresses return `404` for a super admin and for a caller with no credentials.
- **Rationale**: Stage 2 adds routes to this area, so asserting "exactly 25" would fail then. FR-002's exact count applies only to this stage, so it is a one-off quickstart check.
- **Alternatives considered**: Asserting the exact route list (breaks at Stage 2); relying only on existing feature tests (they don't prove the old address is gone or check the stack on all routes).

## R4: How "no behaviour change" is proved

- **Decision**: Use two independent checks:
  1. The existing tests change only in URLs and names, with no expected result altered. Review the diff for this.
  2. A before-and-after `php artisan route:list --json` comparison. After normalising the prefix and namespace, the two lists must be identical in method, path, action and middleware, for all routes.
- **Rationale**: The comparison also covers the hotel-facing routes, including the set scanned by the cost-leak check (SC-005), without needing a database. It needs a snapshot taken before any change, so that is the first implementation step.
- **Alternatives considered**: Comparing recorded HTTP responses (much more setup, and the controllers are untouched, so it adds little).

## R5: Which documents change

- **Decision**:
  - **Update (living)**: the eight API docs named in the design, the roadmap, and `CLAUDE.md`. Notes inside those docs that point readers to these URLs change too, such as the dated notice at the top of `knowledge-base-article-api-documentation.md`, so nobody follows a dead address.
  - **Keep the file name**: `global-knowledge-admin-api-documentation.md`. The name describes the audience, not the URL, and renaming it would only create churn.
  - **Leave alone (history)**: `specs/001–021`, dated `latest-changes-*`, `docs/system-design-assessment-2026-09-19.md`, the superseded PGRIP plan, the design document, and the design review reports at the repo root.
- **Rationale**: FR-013 and SC-006. A dated document records what was true then.

## R6: How the frontend finds out

- **Decision**: The breaking-change note is the only deliverable for the frontend in this stage, and it has to be complete on its own (FR-015). `/frontend-apply-changes` can hand it over once the backend is built.
- **Rationale**: This is the agreed handoff: the frontend adapts after the backend, and the backend doesn't wait. Environments people actually use still need both shipped together.
