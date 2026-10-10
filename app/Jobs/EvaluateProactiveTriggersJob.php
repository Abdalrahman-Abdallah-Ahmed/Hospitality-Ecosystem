<?php

namespace App\Jobs;

use App\Enums\ProactiveMessageStatus;
use App\Enums\ProactiveTrigger;
use App\Models\Hotel;
use App\Models\ProactiveMessage;
use App\Services\Proactive\ProactiveTriggerFinder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds the proactive messages each hotel's triggers call for, records each
 * once, and hands the ones now due to SendProactiveMessageJob (SPEC-073).
 *
 * Inserting is idempotent — the unique (hotel, guest, trigger, event) key
 * ignores a row that already exists — so the sweep, a retry and an approval
 * dispatching it at the same moment never create a second message.
 *
 * Only hotels that switched proactive messaging on are looked at.
 */
class EvaluateProactiveTriggersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        // Limit to the approved-recommendation trigger of one reservation:
        // an approval does not wait for the next sweep.
        public ?string $reservationId = null,
        public ?string $hotelId = null,
    ) {}

    /**
     * Evaluate a reservation right after one of its recommendations was
     * approved.
     */
    public static function forReservation(string $hotelId, string $reservationId): void
    {
        dispatch(new self($reservationId, $hotelId));
    }

    public function handle(ProactiveTriggerFinder $finder): void
    {
        $hotels = Hotel::query()
            ->where('is_active', true)
            ->when($this->hotelId, fn ($query) => $query->whereKey($this->hotelId))
            ->get()
            ->filter(fn (Hotel $hotel) => $hotel->proactiveSettings()->enabled);

        foreach ($hotels as $hotel) {
            TenantContext::runForHotel($hotel->id, fn () => $this->evaluate($hotel, $finder));
        }
    }

    private function evaluate(Hotel $hotel, ProactiveTriggerFinder $finder): void
    {
        $settings = $hotel->proactiveSettings();
        $triggers = $this->reservationId
            ? [ProactiveTrigger::RECOMMENDATION_APPROVED]
            : ProactiveTrigger::cases();
        $now = now();

        foreach ($triggers as $trigger) {
            if (! $settings->triggerEnabled($trigger)) {
                continue;
            }

            $rows = array_map(fn (array $row) => [
                ...$row,
                'id' => (string) Str::uuid7(),
                'status' => ProactiveMessageStatus::SCHEDULED->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $finder->find($hotel, $trigger, $now, $this->reservationId));

            if ($rows !== []) {
                DB::table('proactive_messages')->insertOrIgnore($rows);
            }
        }

        ProactiveMessage::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', ProactiveMessageStatus::SCHEDULED->value)
            ->where('due_at', '<=', $now)
            ->pluck('id')
            ->each(fn (string $id) => SendProactiveMessageJob::dispatch($id));
    }
}
