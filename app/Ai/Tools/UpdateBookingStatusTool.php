<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Enums\BookingStatus;
use App\Models\Hotel;
use App\Services\BookingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Moves an activity booking through its lifecycle exactly as the booking
 * screen does (confirm, attended, no-show, cancel), with the same forward-only
 * rules. Cancelling is hard to reverse, so that one call is confirmed by the
 * admin first (FR-017); the others run straight away.
 */
class UpdateBookingStatusTool implements ConfirmsBeforeRunning, Tool
{
    use AdminToolSupport;

    private const STATUSES = ['confirmed', 'realised', 'no_show', 'cancelled'];

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Change an activity booking\'s status, by booking id or reference: confirmed, realised (the guest attended), '
            .'no_show, or cancelled (needs a reason; the system asks the admin to confirm first). A booking only moves '
            .'forward; a refused change comes back with the reason.';
    }

    public function needsConfirmation(Request $request): bool
    {
        return $request->string('status')->toString() === BookingStatus::CANCELLED->value;
    }

    public function confirmationSummary(Request $request, string $locale): string
    {
        $booking = $this->findBooking($request->string('booking')->toString());
        $reference = $booking?->reference ?? $request->string('booking')->toString();
        $activity = $booking?->activity?->name ?? $booking?->item_name ?? '?';
        $guest = $this->guestName($booking?->guest);
        $date = $booking?->scheduled_date?->toDateString() ?? '?';
        $pax = $booking?->pax ?? '?';

        return $locale === 'ar'
            ? "إلغاء الحجز {$reference}: {$activity} للضيف {$guest} بتاريخ {$date}، عدد الأشخاص {$pax}."
            : "Cancel booking {$reference}: {$activity} for {$guest} on {$date}, {$pax} pax.";
    }

    public function handle(Request $request): Stringable|string
    {
        $booking = $this->findBooking($request->string('booking')->toString());

        if (! $booking) {
            return 'This hotel has no booking with that id or reference.';
        }

        $data = [
            'status' => $request->string('status')->toString(),
            'reason' => $request->string('reason')->toString() ?: null,
            'realised_at' => $request->string('realised_at')->toString() ?: null,
        ];

        $result = $this->attempt(function () use ($booking, $data) {
            Validator::make($data, [
                'status' => ['required', Rule::in(self::STATUSES)],
                'reason' => ['nullable', 'string', 'max:1000', 'required_if:status,'.BookingStatus::CANCELLED->value],
                'realised_at' => ['nullable', 'date'],
            ])->validate();

            $service = app(BookingService::class);

            return match (BookingStatus::from($data['status'])) {
                BookingStatus::CONFIRMED => $service->confirm($booking),
                BookingStatus::REALISED => $service->realise($booking, $data['realised_at'] ? Carbon::parse($data['realised_at']) : null),
                BookingStatus::NO_SHOW => $service->markNoShow($booking),
                BookingStatus::CANCELLED => $service->cancel($booking, $data['reason']),
                default => $booking,
            };
        });

        if (is_string($result)) {
            return $result;
        }

        return $this->done(['booking_id' => $booking->id, 'reference' => $booking->reference], ['status' => $result->fresh()->status->value]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking' => $schema->string()->description("The booking's id or reference.")->required(),
            'status' => $schema->string()->enum(self::STATUSES)->required(),
            'reason' => $schema->string()->description('Required to cancel: why.'),
            'realised_at' => $schema->string()->description('When the guest attended, if not now (ISO 8601).'),
        ];
    }
}
