# Feature Specification: Super Admin Route Move

**Feature Branch**: `022-superadmin-route-move`

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "Stage 1 of docs/designs/super-admin-platform.md: move every api/admin route to api/superadmin as a hard cut, with no alias and no behaviour change. See Goal 1 (line 66), Technical Plan §1 (lines 282–296) and the Stage 1 plan (lines 792–870)."

## Problem and Need

Platform-operator endpoints live under `/api/admin`, but "admin" also means a hotel admin, who is a different kind of user. The address suggests hotel admins can use these endpoints, and they cannot. The super admin platform work adds accounts, users, suspension and impersonation to this area, so it needs an unambiguous address first.

This feature renames the address and changes nothing else. Later stages can then add endpoints without carrying the old name.

**Actors**:

- **Super admin**: the platform operator, and the only user allowed to call these endpoints.
- **Hotel admin and employee**: must stay refused, exactly as today.
- **Frontend application**: the API client that must switch to the new addresses.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Super admin keeps every capability at the new address (Priority: P1)

A super admin uses usage and cost reports, global knowledge management, knowledge index rebuilds and guest memory extraction re-runs. After the move they do all of this under `/api/superadmin`, and each endpoint behaves as it did before.

**Why this priority**: This is the feature. If any endpoint is missing or behaves differently, an operator loses a capability or the "no behaviour change" promise is broken.

**Independent Test**: Run the existing super admin test suite with only the addresses changed and its assertions untouched. It must pass.

**Acceptance Scenarios**:

1. **Given** a super admin, **When** they call any of the 25 endpoints in the [Endpoint Map](#endpoint-map) at its new address with the same input as before, **Then** they get the same status code and the same response body shape as before the move.
2. **Given** a global knowledge document created before the move, **When** a super admin fetches it at the new address, **Then** it is returned unchanged.
3. **Given** a super admin, **When** they request `/api/superadmin/knowledge-documents/deleted`, **Then** they get the list of deleted documents, and "deleted" is not read as a document id.
4. **Given** a super admin with a token issued before the move, **When** they call a new address, **Then** the token works and they do not have to log in again.

---

### User Story 2 - Access stays exactly as restricted (Priority: P1)

Only a super admin can reach this area. A hotel admin or employee must not gain access through the move, and every protection the old address had must apply to the new one.

**Why this priority**: These endpoints expose every customer's data and the platform's own costs. A route that answers without checking who is asking would be a cross-tenant data leak, so this ranks with Story 1.

**Independent Test**: Call every new address as a hotel admin, as an employee, without a token and without an API key, and compare each endpoint's protections with the old stack.

**Acceptance Scenarios**:

1. **Given** a hotel admin or an employee, **When** they call any new address, **Then** they are refused with `403`.
2. **Given** a request with no valid login token, **When** it calls any new address, **Then** it is refused with `401`.
3. **Given** a request with no valid API key, **When** it calls any new address, **Then** it is refused with `401`, as on the old address.
4. **Given** every endpoint under `/api/superadmin`, **When** its protections are inspected, **Then** it has the full stack, in the same order as before: API key, login, rate limit, tenant resolution, super admin check.
5. **Given** a new endpoint added to this area later, **When** it is defined, **Then** it inherits the super admin guard automatically, without its author having to apply it.

---

### User Story 3 - The old address is gone (Priority: P2)

Nothing answers under `/api/admin` any more. There is no alias, redirect or deprecation period, so only one address exists and later stages have a single surface to secure.

**Why this priority**: The design rejects an alias on purpose. It matters less than Stories 1 and 2 because a leftover route would be untidy rather than dangerous, as long as it stayed guarded.

**Independent Test**: Request each old address, with and without a super admin login, and confirm each returns "not found".

**Acceptance Scenarios**:

1. **Given** a super admin, **When** they call `/api/admin/usage`, **Then** they get `404`.
2. **Given** any of the 25 old addresses and any caller, **When** the address is called with its original method, **Then** the response is `404`, not a redirect and not a `401` or `403`.

---

### User Story 4 - Frontend and readers can follow the change (Priority: P2)

Whoever updates the frontend gets one note that maps every old address to its new one and says the two applications must ship together. The API documentation shows only the new addresses.

**Why this priority**: The move breaks the frontend's super admin screens until they are updated. The note prevents that, but it does not change how the API behaves.

**Independent Test**: Using only the breaking-change note, find the new address for each of the 25 old ones. Then search the current documentation for the old prefix.

**Acceptance Scenarios**:

1. **Given** the breaking-change note, **When** a developer looks up any old address, **Then** they find exactly one new address for it, and the note says both applications must deploy together.
2. **Given** the current API documentation and project guidance, **When** they are searched for the old prefix, **Then** the only matches are in the breaking-change note and in historical records.
3. **Given** the historical specs and dated change notes written before this feature, **When** this feature ships, **Then** they are unchanged.

### Edge Cases

- **Frontend not yet updated**: its super admin screens get `404` from the old addresses. This is the accepted risk of a hard cut, and it is handled by deploying both applications together, not by a fallback.
- **Old address with no credentials**: it returns `404` rather than `401`, because no route exists there.
- **Bare prefix**: `/api/superadmin` on its own has no endpoint and returns `404`, as `/api/admin` does today.
- **Hotel-facing endpoints with similar paths**: `/api/knowledge-documents`, `/api/knowledge-base-articles` and the account's own usage report keep their addresses and behaviour.
- **Super admin using hotel-facing endpoints**: this still works as today. Restricting it belongs to a later stage.
- **Work already running during the release**: knowledge rebuilds and memory extractions do not depend on the address, so they finish normally and stay visible at the new address.
- **Automated cost-leak check**: the check that scans hotel-facing routes for cost data skips the super admin area by its address. It must follow the new prefix, so that it neither scans super admin routes as hotel-facing nor loses coverage of any hotel route.

## Requirements *(mandatory)*

### Functional Requirements

**The move**

- **FR-001**: Every endpoint in the [Endpoint Map](#endpoint-map) MUST be available at its new address under `/api/superadmin`, with the same method and the same path after the prefix.
- **FR-002**: No endpoint may be added, removed, merged or split by this feature. The area MUST contain exactly the 25 endpoints in the map.
- **FR-003**: For the same input and state, each endpoint MUST return the same status code and response body as before the move. Validation, side effects, audit records and queued work MUST also stay the same.
- **FR-004**: Data, schema and login tokens MUST NOT change. Tokens issued before the move MUST keep working.

**The hard cut**

- **FR-005**: Every endpoint MUST be gone from `/api/admin`, with no alias, redirect or deprecation period.
- **FR-006**: Every old address MUST return `404` for every caller.
- **FR-007**: The old route definitions MUST be removed rather than disabled. File paths, folders, namespaces and address references for this area, including those in tests and code comments, MUST use "superadmin", so the name in the code matches the address. Test helper names and test titles MAY keep their existing wording.
- **FR-008**: Hotel-facing endpoints MUST keep their addresses and behaviour.

**Authorization**

- **FR-009**: Every new address MUST carry the same protections in the same order as today: API key, login, rate limit, tenant resolution, super admin check.
- **FR-010**: The super admin guard MUST apply to the whole area, so that an endpoint added later cannot be left unguarded. Individual endpoints MUST NOT have to apply it themselves.
- **FR-011**: A hotel admin or employee MUST be refused with `403` on every new address. No permissions are added or changed.
- **FR-012**: Automated checks that classify routes as tenant or super admin, including the inventory and cross-tenant checks and the hotel-facing cost-leak check, MUST recognise the new area with no loss of coverage.

**Documentation**

- **FR-013**: The API documentation and project guidance MUST show only the new addresses. Historical specs and dated change notes MUST stay unchanged.
- **FR-014**: A breaking-change note MUST map all 25 old addresses to their new ones and state that the frontend and backend must deploy together.
- **FR-015**: The note MUST be enough for the frontend work to be done from it alone, in line with the agreed handoff, where the frontend adapts after the backend is built.

### Endpoint Map

The prefix changes from `/api/admin` to `/api/superadmin`. The method and the rest of each path stay the same.

| Area | Method | Path after the prefix |
| --- | --- | --- |
| Reporting | GET | `/usage` |
| Reporting | GET | `/ai-cost` |
| Global knowledge documents | GET | `/knowledge-documents/deleted` |
| Global knowledge documents | GET | `/knowledge-documents` |
| Global knowledge documents | POST | `/knowledge-documents` |
| Global knowledge documents | GET | `/knowledge-documents/{id}` |
| Global knowledge documents | PUT | `/knowledge-documents/{id}` |
| Global knowledge documents | DELETE | `/knowledge-documents/{id}` |
| Global knowledge documents | POST | `/knowledge-documents/{id}/file` |
| Global knowledge documents | GET | `/knowledge-documents/{id}/download` |
| Global knowledge documents | GET | `/knowledge-documents/{id}/text` |
| Global knowledge documents | PUT | `/knowledge-documents/{id}/text` |
| Global knowledge documents | DELETE | `/knowledge-documents/{id}/text` |
| Global knowledge documents | POST | `/knowledge-documents/{id}/reindex` |
| Global knowledge documents | POST | `/knowledge-documents/{id}/restore` |
| Global knowledge articles | GET | `/knowledge-base-articles` |
| Global knowledge articles | POST | `/knowledge-base-articles` |
| Global knowledge articles | GET | `/knowledge-base-articles/{id}` |
| Global knowledge articles | PUT | `/knowledge-base-articles/{id}` |
| Global knowledge articles | DELETE | `/knowledge-base-articles/{id}` |
| Knowledge index rebuilds | POST | `/knowledge/rebuilds` |
| Knowledge index rebuilds | GET | `/knowledge/rebuilds` |
| Knowledge index rebuilds | GET | `/knowledge/rebuilds/{id}` |
| Guest memory extractions | GET | `/guest-memory-extractions` |
| Guest memory extractions | POST | `/guest-memory-extractions/{extraction}/rerun` |

### Out of Scope

- Any new super admin capability: accounts, hotels, users, access reset, suspension, impersonation, dashboards or event log changes. These are later stages of the design.
- Restricting super admins from hotel-facing endpoints.
- Changes to the frontend itself. It follows from the breaking-change note.
- Rewriting historical specs or dated change notes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 25 of 25 endpoints respond at their new addresses, and the area contains no others.
- **SC-002**: 25 of 25 old addresses return "not found".
- **SC-003**: All existing super admin tests pass with changes limited to addresses and names, and no expected result is altered.
- **SC-004**: Hotel admins and employees are refused on 25 of 25 new addresses, and all 25 show the full protection stack.
- **SC-005**: The full automated test suite passes with no reduction in the number of hotel-facing routes checked for cost leaks.
- **SC-006**: A search of current documentation and project guidance finds the old prefix only in the breaking-change note and historical records. A search of the code finds it only in the check that proves the old address is gone.
- **SC-007**: A developer can resolve 25 of 25 old addresses to their new ones using only the breaking-change note.

## Assumptions

- **No alias, by decision.** The project constitution prefers a controlled deprecation path for conflicting functionality. The design rejects one here: the system is at development stage with test users only, this is an address rename rather than a removal of capability, and the change is documented. The implementation plan's Constitution Check should record this justification.
- **Deployment.** The backend can be built and merged before the frontend changes. Any environment people actually use must receive both together, because the frontend's super admin screens fail in the meantime.
- **Scope of the design's file lists.** The Stage 1 plan in `docs/designs/super-admin-platform.md` is the starting list of affected files. The plan must re-check it against the code. It does not call out the address-based filter in the hotel-facing cost-leak check, which FR-012 covers.
- **Comments only.** Every mention of the old address in application code and seeders is a comment, so updating them does not change behaviour.
- **Dependencies.** No other stage has to ship first. Later stages of the design depend on this one.
