<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * One guest's profile and history for the Admin AI: who they are, their
 * last stays, upcoming reservations and recent activity bookings. Unlike the
 * guest list, it includes contact details: an admin asking about one named
 * guest is the case those are needed for. The identity fingerprint is never
 * returned.
 */
class GetGuestTool implements Tool
{
    use AdminToolSupport;

    private const HISTORY = 10;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Look up one guest by guest id or phone number: profile (name, contact, language, nationality, '
            .'preferences, VIP), their last stays, upcoming reservations and recent activity bookings.';
    }

    public function handle(Request $request): Stringable|string
    {
        $guest = $this->findGuest($request->string('guest')->toString());

        if ($guest === null) {
            return 'This hotel has no guest with that id or phone number.';
        }

        if (! $guest instanceof Guest) {
            return $this->ambiguous('guest', $guest->map(fn (Guest $match) => ['id' => $match->id, 'name' => $this->guestName($match)]));
        }

        $today = $this->hotelToday();

        $stays = Stay::withoutGlobalScope('hotel')
            ->where('guest_id', $guest->id)
            ->with('room')
            ->orderByDesc('planned_arrival_date')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn (Stay $stay) => [
                'status' => $stay->status->value,
                'arrival' => $stay->planned_arrival_date?->toDateString(),
                'departure' => $stay->planned_departure_date?->toDateString(),
                'room_number' => $stay->room?->room_number,
            ]);

        $upcoming = Reservation::withoutGlobalScope('hotel')
            ->where('guest_id', $guest->id)
            ->whereDate('departure_date', '>=', $today)
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->orderBy('arrival_date')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn (Reservation $reservation) => [
                'code' => $reservation->reservation_id,
                'status' => $reservation->status->value,
                'arrival' => $reservation->arrival_date?->toDateString(),
                'departure' => $reservation->departure_date?->toDateString(),
            ]);

        $bookings = Booking::withoutGlobalScope('hotel')
            ->where('guest_id', $guest->id)
            ->orderByDesc('scheduled_for')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn (Booking $booking) => [
                'reference' => $booking->reference,
                'item' => $booking->item_name,
                'status' => $booking->status->value,
                'scheduled_for' => $booking->scheduled_for?->copy()->setTimezone($this->hotel->timezone ?: config('app.timezone'))->format('Y-m-d H:i'),
                'pax' => $booking->pax,
            ]);

        return json_encode([
            'id' => $guest->id,
            'name' => $this->guestName($guest),
            'email' => $guest->email,
            'phone_number' => $guest->phone_number,
            'preferred_language' => $guest->preferred_language,
            'nationality' => $guest->nationality,
            'preferences' => $guest->preferences,
            'is_vip' => (bool) $guest->is_vip,
            'loyalty_status' => $guest->loyalty_status,
            'stays' => $stays->values()->all(),
            'upcoming_reservations' => $upcoming->values()->all(),
            'bookings' => $bookings->values()->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guest' => $schema->string()->description("The guest's id, or their phone number.")->required(),
        ];
    }
}
