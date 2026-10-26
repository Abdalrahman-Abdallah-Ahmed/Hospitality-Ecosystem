# Lane load check

Proves on staging that slow work never holds up urgent work (spec 017, SC-003
and SC-004). Run it once the lanes are live, before go-live, and after any
change to lanes or worker counts. Record the results in
`docs/production-readiness-checklist.md`.

**Needs:** staging with the five lane workers running, a fake AI provider (no
real model calls, no cost) and a WhatsApp sender that does not reach real
phones.

## 1. Idle baseline

Send 20 signed test messages to `POST /api/whatsapp` from a test guest. For
each, take the time from receipt (`whatsapp_inbound_messages.created_at`) to the
reply being sent (the `SendWhatsAppMessageJob` finishing; see the worker log).
Note the 95th percentile.

## 2. Guest replies under a batch backlog (SC-003)

Fill the `ai-batch` lane with 500 jobs from `php artisan tinker`:

```php
$admin = App\Models\User::where('role', 'admin')->first();
foreach (range(1, 500) as $i) {
    App\Jobs\CreateAiInsightsJob::dispatch(['insight_type' => 'general', 'user' => $admin]);
}
```

Confirm the backlog (`select queue, count(*) from jobs group by queue`), then
repeat step 1.

**Pass:** the loaded 95th percentile is no more than 10% above the idle one.

## 3. Staff mail during an import (SC-004)

Start a 10,000-row reservation import, then assign a task to a staff member
while it runs. Take the time until `TaskAssignedNotification` is sent (mail
log).

**Pass:** under 1 minute.

## Results

| Date | Idle p95 | Loaded p95 | Difference | Task mail during import | Pass? |
| --- | --- | --- | --- | --- | --- |
| _to record_ | | | | | |
