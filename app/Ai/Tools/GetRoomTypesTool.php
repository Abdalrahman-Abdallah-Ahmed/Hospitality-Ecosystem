<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The hotel's room types for the Admin AI: what each sleeps and how many
 * physical rooms it has. How many can still be sold comes from availability,
 * never from these counts.
 */
class GetRoomTypesTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'List this hotel\'s room types: name, description, maximum occupancy, adult and child capacity, bed '
            .'configuration, whether it is active, and how many physical rooms it has. This is not availability.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = RoomType::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->withCount('rooms')
            ->orderBy('name');

        return ListResult::fromQuery($query, ListResult::limit($request), fn (RoomType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'description' => $type->description,
            'max_occupancy' => $type->max_occupancy,
            'adult_capacity' => $type->adult_capacity,
            'child_capacity' => $type->child_capacity,
            'bed_configuration' => $type->bed_configuration,
            'is_active' => (bool) $type->is_active,
            'rooms' => $type->rooms_count,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('At most this many, 1-50. Default 50.'),
        ];
    }
}
