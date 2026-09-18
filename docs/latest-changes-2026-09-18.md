# Latest Backend Changes — 2026-09-18

## 1. Breaking: `marketing_consent` Removed from Guests

Guests give consent when they make the reservation, so the per-guest flag is gone.

- Guest objects no longer include `marketing_consent`. This covers `GET /api/guest`, `GET /api/guest/{id}`, the create and update responses, and anywhere a guest is embedded (e.g. `reservation.guest`).
- `POST /api/guest` and `PUT`/`PATCH /api/guest/{id}` no longer accept it. Sending it does not fail: the field is ignored and the request succeeds.
- **Filtering `GET /api/guest` by `marketing_consent` no longer filters.** Filters on unknown columns are skipped silently, so the list comes back unfiltered with no error. Remove any such filter rather than relying on it.

**What to change in the frontend:**

- Remove the marketing-consent switch from the guest create and edit forms.
- Stop reading `guest.marketing_consent` anywhere: tables, detail views, list filters, TypeScript types.
- Drop it from request payloads. Not required, since it is ignored, but it keeps payloads honest.

**Full docs:** [Guest API](/D:/Hospitality%20Ecosystem/docs/guest-api-documentation.md), [Frontend Create Modules § Guests](/D:/Hospitality%20Ecosystem/docs/frontend-create-modules-api.md)
