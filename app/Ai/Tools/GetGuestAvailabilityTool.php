<?php

namespace App\Ai\Tools;

use App\Models\Hotel;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Whether each of the guest's hotel's room types can be booked for their
 * dates, for the Concierge. Deliberately yes/no only: room counts would tell
 * anyone on WhatsApp how full the hotel is. Always lists every active type,
 * the one the guest asked about first, so the Concierge can offer
 * alternatives in one call.
 */
class GetGuestAvailabilityTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Check whether this hotel's room types can be booked for the guest's dates. Returns every room type with "
            .'its description, capacity and available (true or false for the whole stay), the requested type first. '
            .'At most '.AvailabilityService::AI_MAX_NIGHTS.' nights per call. Use it for every availability question; '
            .'never guess, and never tell the guest how many rooms are left.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $availability = app(AvailabilityService::class);
        $arrival = $request->string('arrival_date')->toString() ?: null;
        $departure = $request->string('departure_date')->toString() ?: null;
        $askedFor = trim($request->string('room_type')->toString());
        $asked = mb_strtolower($askedFor);

        try {
            $availability->assertRange($this->hotel, $arrival, $departure, AvailabilityService::AI_MAX_NIGHTS);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        $rows = $this->yesOrNoPerType($availability->forHotel($this->hotel, $arrival, $departure), $asked);

        $result = [
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'room_types' => $rows->all(),
        ];

        if ($asked !== '' && ! $rows->contains(fn (array $row) => mb_strtolower($row['name']) === $asked)) {
            $result['note'] = "The hotel has no room type called \"{$askedFor}\".";
        }

        return json_encode($result);
    }

    /**
     * The grid reduced to what a guest may know, the asked-for type first.
     *
     * @param  array{room_types: list<array{room_type: RoomType, bookable_for_stay: int}>}  $grid
     * @return Collection<int, array{name: string, description: ?string, max_occupancy: int, adult_capacity: int, child_capacity: int, available: bool}>
     */
    private function yesOrNoPerType(array $grid, string $asked): Collection
    {
        return collect($grid['room_types'])
            ->map(fn (array $row) => [
                'name' => $row['room_type']->name,
                'description' => $row['room_type']->description,
                'max_occupancy' => $row['room_type']->max_occupancy,
                'adult_capacity' => $row['room_type']->adult_capacity,
                'child_capacity' => $row['room_type']->child_capacity,
                'available' => $row['bookable_for_stay'] > 0,
            ])
            ->sortBy(fn (array $row) => mb_strtolower($row['name']) === $asked ? 0 : 1)
            ->values();
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'arrival_date' => $schema->string()->description("The guest's arrival date, YYYY-MM-DD.")->required(),
            'departure_date' => $schema->string()->description("The guest's departure date, YYYY-MM-DD.")->required(),
            'room_type' => $schema->string()->description('The room type the guest asked about, by name, if any.'),
        ];
    }
}
