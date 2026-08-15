<?php

namespace App\Ai\Tools;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateReservationTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Create a reservation for this hotel. Matches the guest by phone number, creating a new guest record if none exists yet.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $room = null;

        if ($roomNumber = $request->string('room_number')->toString()) {
            $room = Room::where('hotel_id', $this->hotel->id)
                ->where('room_number', $roomNumber)
                ->first();

            if (! $room) {
                return 'The selected room does not belong to this hotel.';
            }
        }

        $guest = Guest::withTrashed()->firstOrCreate(
            [
                'hotel_id' => $this->hotel->id,
                'phone_number' => $request->string('guest_phone')->toString(),
            ],
            [
                'first_name' => $request->string('guest_first_name')->toString() ?: null,
                'last_name' => $request->string('guest_last_name')->toString() ?: null,
                'email' => $request->string('guest_email')->toString() ?: null,
            ]
        );

        if ($guest->trashed()) {
            $guest->restore();
        }

        $reservation = ReservationCreator::create([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $room?->id,
            'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
            'arrival_date' => $request->string('arrival_date')->toString(),
            'departure_date' => $request->string('departure_date')->toString(),
            'status' => $request->string('status')->toString() ?: ReservationStatus::PENDING->value,
            'adults' => $request->integer('adults') ?: 1,
            'children' => $request->integer('children') ?: 0,
            'source' => 'whatsapp',
            'special_requests' => $request->string('special_requests')->toString() ?: null,
            'reservation_value' => $request->float('reservation_value', 0),
            'currency' => $request->string('currency')->toString() ?: $this->hotel->currency,
        ]);

        ReservationCreator::syncRoomOccupancy($reservation);

        return json_encode($reservation->load(['guest', 'room'])->toArray());
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'guest_phone' => $schema->string()
                ->description("The guest's phone number, used to find or create their guest record.")
                ->required(),
            'guest_first_name' => $schema->string()->description("The guest's first name, if known."),
            'guest_last_name' => $schema->string()->description("The guest's last name, if known."),
            'guest_email' => $schema->string()->description("The guest's email address, if known."),
            'room_number' => $schema->string()->description('A specific room number to assign, if known.'),
            'arrival_date' => $schema->string()->description('Arrival date, YYYY-MM-DD.')->required(),
            'departure_date' => $schema->string()->description('Departure date, YYYY-MM-DD.')->required(),
            'status' => $schema->string()
                ->description('One of: '.implode(', ', array_column(ReservationStatus::cases(), 'value')).'. Defaults to pending.'),
            'adults' => $schema->integer()->description('Number of adults. Defaults to 1.'),
            'children' => $schema->integer()->description('Number of children. Defaults to 0.'),
            'special_requests' => $schema->string()->description('Any special requests the guest mentioned.'),
            'reservation_value' => $schema->number()->description('The total value of the reservation, if known.'),
            'currency' => $schema->string()->description("3-letter currency code. Defaults to the hotel's own currency."),
        ];
    }
}
