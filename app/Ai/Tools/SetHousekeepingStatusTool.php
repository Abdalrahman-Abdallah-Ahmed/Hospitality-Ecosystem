<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Enums\HousekeepingStatusesEnum;
use App\Models\Hotel;
use App\Services\HousekeepingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Corrects a room's housekeeping status by hand, exactly as the housekeeping
 * board does (HousekeepingService::setManually). Only the housekeeping
 * status changes; the room status (available, occupied, out of order) is a
 * separate field and is left alone. Open cleaning tasks are listed, not
 * closed.
 */
class SetHousekeepingStatusTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Set a room\'s housekeeping status (dirty, cleaning, clean or inspected), by room number. This does not '
            .'change whether the room is available, occupied or out of order. Open cleaning tasks for the room are listed '
            .'in the result, not closed.';
    }

    public function handle(Request $request): Stringable|string
    {
        $room = $this->findRoomByNumber($request->string('room_number')->toString());

        if (! $room) {
            return 'This hotel has no room with that number.';
        }

        $data = [
            'housekeeping_status' => $request->string('housekeeping_status')->toString(),
            'reason' => $request->string('reason')->toString() ?: 'Set by the admin through the AI advisor.',
        ];

        $result = $this->attempt(function () use ($room, $data) {
            Validator::make($data, [
                'housekeeping_status' => ['required', Rule::enum(HousekeepingStatusesEnum::class)],
                'reason' => ['required', 'string', 'max:500'],
            ])->validate();

            return app(HousekeepingService::class)->setManually($room, HousekeepingStatusesEnum::from($data['housekeeping_status']), $data['reason']);
        });

        if (is_string($result)) {
            return $result;
        }

        return $this->done(
            ['room_id' => $room->id, 'room_number' => $room->room_number],
            ['housekeeping_status' => $data['housekeeping_status'], 'changed' => $result['changed']],
            $result['open_tasks']->isEmpty() ? null : 'Open housekeeping tasks for this room: '.$result['open_tasks']->pluck('id')->implode(', ').'. They were not closed.',
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_number' => $schema->string()->required(),
            'housekeeping_status' => $schema->string()->enum(HousekeepingStatusesEnum::class)->required(),
            'reason' => $schema->string()->description('Why, if the admin said.'),
        ];
    }
}
