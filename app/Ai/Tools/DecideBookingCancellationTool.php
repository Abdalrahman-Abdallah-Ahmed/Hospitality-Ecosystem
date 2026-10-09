<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Models\Hotel;
use App\Services\BookingCancellationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Answers a guest's request to cancel an activity booking, exactly as the
 * cancellation-request screen does (BookingCancellationService). Approving
 * cancels the booking, so the admin confirms it first (FR-017); declining
 * needs a note for the guest and runs straight away.
 */
class DecideBookingCancellationTool implements ConfirmsBeforeRunning, Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Approve or decline a guest\'s pending request to cancel an activity booking, by booking id or reference. '
            .'Approving cancels the booking (the system asks the admin to confirm first). Declining needs a note '
            .'explaining why, which the guest is told.';
    }

    public function needsConfirmation(Request $request): bool
    {
        return $request->string('decision')->toString() === 'approve';
    }

    public function confirmationSummary(Request $request, string $locale): string
    {
        $booking = $this->findBooking($request->string('booking')->toString());
        $reference = $booking?->reference ?? $request->string('booking')->toString();
        $activity = $booking?->activity?->name ?? $booking?->item_name ?? '?';
        $date = $booking?->scheduled_date?->toDateString() ?? '?';

        return $locale === 'ar'
            ? "الموافقة على طلب الضيف إلغاء الحجز {$reference}: {$activity} بتاريخ {$date}."
            : "Approve the guest's request to cancel booking {$reference}: {$activity} on {$date}.";
    }

    public function handle(Request $request): Stringable|string
    {
        $booking = $this->findBooking($request->string('booking')->toString());

        if (! $booking) {
            return 'This hotel has no booking with that id or reference.';
        }

        $cancellations = app(BookingCancellationService::class);

        if (! $cancellations->openRequest($booking)) {
            return $this->notDone('There is no open cancellation request for this booking.');
        }

        $decision = $request->string('decision')->toString();

        if ($decision === 'approve') {
            $reason = $request->string('reason')->toString() ?: null;

            $result = $this->attempt(function () use ($cancellations, $booking, $reason) {
                Validator::make(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:1000']])->validate();

                return $cancellations->approve($booking, $reason);
            });

            return is_string($result) ? $result : $this->done(['booking_id' => $booking->id, 'reference' => $booking->reference], ['status' => 'cancelled', 'request' => 'approved']);
        }

        if ($decision === 'decline') {
            $note = $request->string('note')->toString();

            $result = $this->attempt(function () use ($cancellations, $booking, $note) {
                Validator::make(['note' => $note], ['note' => ['required', 'string', 'max:1000']])->validate();

                return $cancellations->decline($booking, $note);
            });

            return is_string($result) ? $result : $this->done(['booking_id' => $booking->id, 'reference' => $booking->reference, 'request_task_id' => $result->id], ['request' => 'declined']);
        }

        return 'Say whether to approve or decline the request.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking' => $schema->string()->description("The booking's id or reference.")->required(),
            'decision' => $schema->string()->enum(['approve', 'decline'])->required(),
            'reason' => $schema->string()->description('For an approval: a reason, if the admin gave one.'),
            'note' => $schema->string()->description('Required to decline: why, in words the guest can be told.'),
        ];
    }
}
