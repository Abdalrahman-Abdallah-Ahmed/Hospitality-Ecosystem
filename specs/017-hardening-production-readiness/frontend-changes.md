# Frontend Changes (`ecosystem-frontend`): 017 Hardening and Production Readiness

This is for the frontend team. The full text is in `docs/frontend-changes-2026-10-25.md`,
which is the copy to sync.

Summary:

- **Breaking: another hotel's record is `404`, not `403`.** Detail, edit, delete and
  action calls on a record of another hotel now answer "not found". Handle it like any
  missing record. `403` still means "your role lacks this permission".
- **Dashboard** figures are reused for up to 60 seconds per hotel. Polling faster
  returns the same numbers.
- **Every screen** needs error, empty and loading states, and `next build` must be clean
  (FR-034). The handoff has a checklist per screen.
- **Not changed:** list page sizes and pagination; the new `GET /api/health` is not for
  the frontend.
