<?php

namespace App\Ai\Tools;

use App\Enums\Permission;
use App\Http\Resources\AvailabilityResource;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Live room availability for the Admin AI: the same per-type, per-night grid
 * as GET /api/availability, for at most AvailabilityService::AI_MAX_NIGHTS
 * nights per call. Checks availability.view for the admin it acts for, like
 * the endpoint does.
 */
class GetAvailabilityTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Check live room availability. For each room type and each night from arrival_date up to (not including) '
            .'departure_date, returns how many rooms the type has, how many are out of order, how many are booked, '
            .'how many can still be sold, and how many it is overbooked by, plus bookable_for_stay: how many rooms of '
            .'the type can be booked for the whole stay. At most '.AvailabilityService::AI_MAX_NIGHTS.' nights per call. '
            .'Use it for every question about free rooms or whether a booking fits; never guess availability.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->hasPermission(Permission::AVAILABILITY_VIEW)) {
            return 'You do not have permission to view room availability.';
        }

        $availability = app(AvailabilityService::class);
        $arrival = $request->string('arrival_date')->toString() ?: null;
        $departure = $request->string('departure_date')->toString() ?: null;
        $roomTypeIds = null;

        if (($name = trim($request->string('room_type')->toString())) !== '') {
            $type = RoomType::withoutGlobalScope('hotel')
                ->where('hotel_id', $this->hotel->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->first();

            if (! $type) {
                return "This hotel has no room type called \"{$name}\".";
            }

            $roomTypeIds = [$type->id];
        }

        try {
            $availability->assertRange($this->hotel, $arrival, $departure, AvailabilityService::AI_MAX_NIGHTS);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        return json_encode(AvailabilityResource::make(
            $availability->forHotel($this->hotel, $arrival, $departure, $roomTypeIds)
        )->resolve());
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'arrival_date' => $schema->string()->description('The first night, YYYY-MM-DD. Not in the past.')->required(),
            'departure_date' => $schema->string()->description('The departure date, YYYY-MM-DD. It is not a night.')->required(),
            'room_type' => $schema->string()->description('Only this room type, by name, e.g. Deluxe. Leave out for every active room type.'),
        ];
    }
}
