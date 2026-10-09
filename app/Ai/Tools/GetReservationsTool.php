<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Reservations for the AI agents.
 *
 * The Insights agent uses it unpaged: today's arrivals as a plain list, as it
 * always has. The Admin AI uses it paged (SPEC-055 R6): filters by date
 * range, status, guest or code, dates in the hotel's own timezone, at most
 * 50 rows with the total.
 */
class GetReservationsTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
        private readonly bool $paged = false,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        if (! $this->paged) {
            return "Retrieve today's reservations (arriving today) for the current hotel, including guest name, the rooms booked (room type and room number, if assigned), and status.";
        }

        return 'List this hotel\'s reservations with guest, rooms booked (room type and the room number once assigned), '
            .'party and status. Filter by arrival, departure or stay date (YYYY-MM-DD, hotel time), status, guest '
            .'name or phone, or reservation code. With no filter it lists today\'s arrivals. Returns at most 50 with '
            .'the total; when "partial" is true, say how many there are and offer to narrow it.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->paged) {
            return json_encode($this->query()
                ->whereDate('arrival_date', now()->toDateString())
                ->get()
                ->map(fn (Reservation $reservation) => $this->row($reservation))
                ->values());
        }

        $query = $this->query();
        $filtered = false;

        foreach (['arrival_from' => ['arrival_date', '>='], 'arrival_to' => ['arrival_date', '<='], 'departure_from' => ['departure_date', '>='], 'departure_to' => ['departure_date', '<=']] as $field => [$column, $operator]) {
            if ($request->filled($field)) {
                $query->whereDate($column, $operator, $request->string($field)->toString());
                $filtered = true;
            }
        }

        if ($request->filled('staying_on')) {
            $night = $request->string('staying_on')->toString();
            $query->whereDate('arrival_date', '<=', $night)->whereDate('departure_date', '>', $night);
            $filtered = true;
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
            $filtered = true;
        }

        if ($request->filled('code')) {
            $query->where('reservation_id', trim($request->string('code')->toString()));
            $filtered = true;
        }

        if ($request->filled('guest')) {
            $guest = trim($request->string('guest')->toString());
            $digits = (string) PhoneNumber::digits($guest);

            $query->whereHas('guest', fn (Builder $query) => $query
                ->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", ['%'.addcslashes($guest, '%_\\').'%'])
                ->when(strlen($digits) >= PhoneNumber::MIN_DIGITS, fn (Builder $query) => $query->orWhereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$digits])));
            $filtered = true;
        }

        if (! $filtered) {
            $query->whereDate('arrival_date', now($this->hotel->timezone ?: config('app.timezone'))->toDateString());
        }

        return ListResult::fromQuery(
            $query->orderBy('arrival_date')->orderBy('reservation_id'),
            ListResult::limit($request),
            fn (Reservation $reservation) => [
                ...$this->row($reservation),
                'arrival_date' => $reservation->arrival_date?->toDateString(),
                'departure_date' => $reservation->departure_date?->toDateString(),
            ],
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        if (! $this->paged) {
            return [];
        }

        return [
            'arrival_from' => $schema->string()->description('Arriving on or after this date, YYYY-MM-DD.'),
            'arrival_to' => $schema->string()->description('Arriving on or before this date, YYYY-MM-DD.'),
            'departure_from' => $schema->string()->description('Departing on or after this date, YYYY-MM-DD.'),
            'departure_to' => $schema->string()->description('Departing on or before this date, YYYY-MM-DD.'),
            'staying_on' => $schema->string()->description('In the hotel on this night, YYYY-MM-DD.'),
            'status' => $schema->string()->enum(['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled']),
            'guest' => $schema->string()->description("Part of the guest's name, or their phone number."),
            'code' => $schema->string()->description('The reservation code, e.g. RES-1001.'),
            'limit' => $schema->integer()->description('At most this many, 1-50. Default 50.'),
        ];
    }

    private function query(): Builder
    {
        return Reservation::with(['guest', 'reservationRooms.roomType', 'reservationRooms.room'])
            ->where('hotel_id', $this->hotel->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Reservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'reservation_id' => $reservation->reservation_id,
            'guest_name' => trim($reservation->guest?->first_name.' '.$reservation->guest?->last_name),
            ...$reservation->roomsForAi(),
            'status' => $reservation->status->value,
            'adults' => $reservation->adults,
            'children' => $reservation->children,
        ];
    }
}
