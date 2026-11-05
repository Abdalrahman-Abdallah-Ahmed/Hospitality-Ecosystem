# Quickstart: Module Data Export

How to prove the feature works. Contract: [contracts/export-endpoints.md](contracts/export-endpoints.md).
Columns and audit shape: [data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
php artisan migrate --seed
```

## 1. Automated suite

```bash
php artisan test --parallel --filter=Export
php artisan test --parallel --filter="PermissionAuthorizationTest|CrossTenantRouteTest|EntryPointInventoryTest"
```

Expected: all green. `ExportControllerTest` covers, per module and format: filtered rows and
order equal the list's; zero rows → header only; cap and cap+1; 11th request → 429;
employee with/without `<module>.export`; Hotel B rows never in Hotel A's file; super admin
without `hotel_id` → 403; formula neutralisation; audit entry written.

## 2. Manual: CSV matches the list

```bash
TOKEN=...   # admin of a seeded hotel
curl -s -H "X-API-KEY: $API_KEY" -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/reservation?filter[status]=confirmed&per_page=100" | jq '.body.meta.total'
curl -s -OJ -H "X-API-KEY: $API_KEY" -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/reservation/export?filter[status]=confirmed&format=csv"
```

Expected: the CSV's data row count equals `total`; the file name is
`reservations-<hotel>-<timestamp>.csv`; opening it in Excel shows Arabic names correctly.

## 3. Manual: Excel types

Export `format=xlsx` for bookings. Expected: "Scheduled For" sorts as dates, "Expected Value"
sums, and a guest named `=1+1` shows as text, not `2`.

## 4. Manual: SQL round-trip (SC-003, SC-008)

```bash
curl -s -OJ ... "http://localhost:8000/api/guest/export?format=sql"
curl -s -OJ ... "http://localhost:8000/api/booking/export?format=sql"
createdb export_check
pg_dump --schema-only -t guests -t bookings Hospitality_Ecosystem | psql export_check
# drop the FK constraints to tables not exported, then:
psql export_check -f guests-*.sql && psql export_check -f bookings-*.sql
```

Expected: both files load without error; row counts match; every `bookings.guest_id`
resolves to a loaded guest; a name with an apostrophe is unchanged.

## 5. Manual: refusals

- Employee whose role has `reservations.view` only → `GET /api/reservation/export` → 403.
- `config(['exports.max_rows' => 5])` (or `EXPORT_MAX_ROWS=5`) with 6 matching → 422 with `matching: 6, max_rows: 5`, and an `hotel.reservations_export_refused` audit row.
- 11 exports within a minute → the 11th is 429.

## 6. Performance (SC-001)

Seed 10,000 reservations for one hotel, then time `format=xlsx`, `csv` and `sql`.
Expected: each completes in under 30 s on the production-sized host (2 GB RAM).

Measured 2026-11-03 (dev machine, 10,000 reservations each with a room line, test DB): CSV 6.2 s, XLSX 8.3 s, SQL 1.7 s; process peak ~200 MB including the seeding arrays. No cell caching needed.

## 7. Audit

`GET /api/history` (or `event_logs`) shows `hotel.reservations_exported` with
`format`, filters and `row_count` for each successful export above.
