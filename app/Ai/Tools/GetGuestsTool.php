<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetGuestsTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Find this hotel\'s guests by name, phone number or email ("search"). With no search it lists guests with a '
            .'confirmed reservation that has not ended. Returns at most 50 with the total. For one guest\'s full '
            .'history use the single-guest lookup.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $today = now($this->hotel->timezone ?: config('app.timezone'))->toDateString();
        $currentConfirmed = function ($query) use ($today) {
            $query->where('status', ReservationStatus::CONFIRMED)
                ->whereDate('departure_date', '>=', $today);
        };

        $query = Guest::where('hotel_id', $this->hotel->id)
            ->with(['reservations' => $currentConfirmed])
            ->orderBy('first_name')
            ->orderBy('last_name');

        $search = trim($request->string('search')->toString());

        if ($search === '') {
            $query->whereHas('reservations', $currentConfirmed);
        } else {
            $digits = (string) PhoneNumber::digits($search);

            $query->where(fn (Builder $query) => $query
                ->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", ['%'.addcslashes($search, '%_\\').'%'])
                ->orWhere('email', 'ilike', addcslashes($search, '%_\\'))
                ->when(strlen($digits) >= PhoneNumber::MIN_DIGITS, fn (Builder $query) => $query->orWhereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$digits])));
        }

        // Only what the advisor needs to talk about a guest. Serialising the
        // whole model would also send the identity fingerprint and free-text
        // preferences to the model provider.
        return ListResult::fromQuery($query, ListResult::limit($request), fn (Guest $guest) => [
            'id' => $guest->id,
            'name' => trim($guest->first_name.' '.$guest->last_name),
            'is_vip' => (bool) $guest->is_vip,
            'loyalty_status' => $guest->loyalty_status,
            'preferred_language' => $guest->preferred_language,
            'reservations' => $guest->reservations->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'reservation_id' => $reservation->reservation_id,
                'arrival_date' => $reservation->arrival_date?->toDateString(),
                'departure_date' => $reservation->departure_date?->toDateString(),
                'adults' => $reservation->adults,
                'children' => $reservation->children,
            ])->values(),
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description("Part of the guest's name, their phone number, or their email."),
            'limit' => $schema->integer()->description('At most this many, 1-50. Default 50.'),
        ];
    }
}
