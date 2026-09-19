<?php

namespace App\Console\Commands;

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Stay;
use App\Services\GuestContactService;
use App\Services\SenderRecognitionService;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Stamps first/last contact on guests and stays from the conversation history
 * that existed before live stamping did.
 *
 * Each guest message is mapped to a stay with the same rule live recognition
 * uses, evaluated at the message's date. The result is a floor, not an exact
 * history: a turn that failed before the agent ran persisted no message.
 * Safe to re-run — the service only ever widens the recorded span.
 */
class BackfillGuestContactTimestamps extends Command
{
    protected $signature = 'guests:backfill-contact-timestamps
        {--hotel= : Only this hotel id}
        {--dry-run : Report what would be stamped without writing anything}';

    protected $description = 'Stamp guest and stay contact timestamps from existing WhatsApp conversations';

    public function handle(GuestContactService $contacts, SenderRecognitionService $recognition): int
    {
        $hotels = Hotel::query()
            ->when($this->option('hotel'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        foreach ($hotels as $hotel) {
            $summary = TenantContext::runForHotel(
                $hotel->id,
                fn () => $this->backfillHotel($hotel, $contacts, $recognition),
            );

            // One summary per hotel rather than one per guest, the way the
            // nightly outcome matcher records its runs.
            if (! $this->option('dry-run')) {
                EventLogger::record($hotel, 'guest_contact_backfilled', changes: $summary);
            }

            $this->line(sprintf(
                '%s: %d guests, %d stays, %d messages (%d not mapped to a stay)%s',
                $hotel->name,
                $summary['guests'],
                $summary['stays'],
                $summary['messages'],
                $summary['unmapped_messages'],
                $this->option('dry-run') ? ' — dry run, nothing written' : '',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{guests: int, stays: int, messages: int, unmapped_messages: int}
     */
    private function backfillHotel(Hotel $hotel, GuestContactService $contacts, SenderRecognitionService $recognition): array
    {
        $summary = ['guests' => 0, 'stays' => 0, 'messages' => 0, 'unmapped_messages' => 0];
        $morphClass = (new Guest)->getMorphClass();

        $guests = Guest::where('hotel_id', $hotel->id)->whereHas('conversations')->lazyById(200);

        foreach ($guests as $guest) {
            $sentAt = ConversationMessage::query()
                ->where('role', 'user')
                ->where('participant_type', $morphClass)
                ->whereIn('conversation_id', $guest->conversations()->select('id'))
                ->orderBy('created_at')
                ->pluck('created_at')
                ->map(fn ($at) => Carbon::parse($at));

            if ($sentAt->isEmpty()) {
                continue;
            }

            [$byStay, $unmapped] = $this->spansByStay($guest, $sentAt, $recognition);
            $summary['unmapped_messages'] += $unmapped;

            if (! $this->option('dry-run')) {
                $contacts->recordRange($guest, null, $sentAt->first(), $sentAt->last());

                foreach ($byStay as $span) {
                    $contacts->recordRange($guest, $span['stay'], $span['first'], $span['last']);
                }
            }

            $summary['guests']++;
            $summary['stays'] += count($byStay);
            $summary['messages'] += $sentAt->count();
        }

        return $summary;
    }

    /**
     * Each stay's earliest and latest message, choosing the stay for each
     * message with the live recognition rule at the message's own date.
     *
     * @param  Collection<int, Carbon>  $sentAt  in ascending order
     * @return array{0: array<string, array{stay: Stay, first: Carbon, last: Carbon}>, 1: int}
     */
    private function spansByStay(Guest $guest, Collection $sentAt, SenderRecognitionService $recognition): array
    {
        $reservations = $guest->reservations()->with('stay')->get();
        $byStay = [];
        $unmapped = 0;

        foreach ($sentAt as $at) {
            $stay = $recognition->relevantReservation($reservations, $at)?->stay;

            if (! $stay) {
                $unmapped++;

                continue;
            }

            $byStay[$stay->id] ??= ['stay' => $stay, 'first' => $at, 'last' => $at];
            $byStay[$stay->id]['last'] = $at;
        }

        return [$byStay, $unmapped];
    }
}
