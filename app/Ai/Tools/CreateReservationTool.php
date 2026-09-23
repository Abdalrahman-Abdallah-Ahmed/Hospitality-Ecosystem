<?php

namespace App\Ai\Tools;

use App\Enums\ReservationStatus;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\CreationNotificationService;
use App\Support\Audit\EventLogger;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Books a reservation by room type and quantity for the Admin AI, through the
 * same ReservationCreator as the staff API, with its writes audited as the AI
 * agent. It cannot override the party-capacity check or the availability
 * guard: a shortfall comes back to the model as the readable message.
 *
 * It has no permission check of its own: it relies on AdminAdvisorAgent being
 * admin-only. Any future agent that is not admin-only must check
 * reservations.create before offering it.
 */
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
        return 'Create a reservation for this hotel by room type and quantity (e.g. 2 × Deluxe), optionally '
            .'with a specific room number per room. Matches the guest by phone number, creating a new guest '
            .'record if none exists yet.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $lines = $this->lines($request);

        if (is_string($lines)) {
            return $lines;
        }

        try {
            $reservation = EventLogger::asAiAgent(function () use ($request, $lines) {
                $guest = ReservationCreator::findOrCreateGuest($this->hotel->id, [
                    'phone_number' => $request->string('guest_phone')->toString(),
                    'first_name' => $request->string('guest_first_name')->toString() ?: null,
                    'last_name' => $request->string('guest_last_name')->toString() ?: null,
                    'email' => $request->string('guest_email')->toString() ?: null,
                ]);

                return ReservationCreator::create([
                    'hotel_id' => $this->hotel->id,
                    'guest_id' => $guest->id,
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
                ], $lines);
            });
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        app(CreationNotificationService::class)->whatsAppReservationCreated($reservation);

        return json_encode($reservation->load(['guest', 'reservationRooms.roomType', 'reservationRooms.room'])->toArray());
    }

    /**
     * The model's room request, as ReservationCreator lines: room types by
     * name (active ones only, never created on the fly), room numbers within
     * this hotel. A message string when it cannot be resolved.
     *
     * @return array<int, array<string, mixed>>|string
     */
    private function lines(Request $request): array|string
    {
        $rooms = (array) ($request['rooms'] ?? []);
        $legacyRoomNumber = $request->string('room_number')->toString();

        if ($rooms === [] && $legacyRoomNumber !== '') {
            $rooms = [['room_number' => $legacyRoomNumber]];
        }

        if ($rooms === []) {
            return 'Which room type should I book?';
        }

        $lines = [];

        foreach ($rooms as $item) {
            $quantity = (int) ($item['quantity'] ?? 1);
            $roomNumber = trim((string) ($item['room_number'] ?? ''));
            $typeName = trim((string) ($item['room_type'] ?? ''));
            $room = null;

            if ($roomNumber !== '') {
                if ($quantity > 1) {
                    return 'A room number can only be given for a single room, not for a quantity above 1.';
                }

                $room = Room::withoutGlobalScope('hotel')
                    ->where('hotel_id', $this->hotel->id)
                    ->where('room_number', $roomNumber)
                    ->first();

                if (! $room) {
                    return "Room {$roomNumber} does not exist in this hotel.";
                }
            }

            if ($typeName === '' && ! $room) {
                return 'Which room type should I book?';
            }

            $type = $typeName !== '' ? $this->activeType($typeName) : null;

            if ($typeName !== '' && ! $type) {
                return "Unknown room type {$typeName}. Available: ".$this->activeTypeNames().'.';
            }

            $lines[] = [
                'room_type_id' => $type?->id ?? $room->room_type_id,
                'quantity' => $quantity,
                'room_id' => $room?->id,
            ];
        }

        return $lines;
    }

    private function activeType(string $name): ?RoomType
    {
        return RoomType::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('is_active', true)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function activeTypeNames(): string
    {
        return RoomType::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ') ?: 'none';
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
            'rooms' => $schema->array()
                ->items($schema->object([
                    'room_type' => $schema->string()->description("The room type's name, e.g. Deluxe.")->required(),
                    'quantity' => $schema->integer()->description('How many rooms of this type. Defaults to 1.'),
                    'room_number' => $schema->string()->description('A specific room number, only when quantity is 1.'),
                ]))
                ->description('The rooms to book, by room type and quantity. Ask for the room type if the user did not give one.'),
            'room_number' => $schema->string()->description('Deprecated: a single specific room number. Prefer rooms.'),
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
