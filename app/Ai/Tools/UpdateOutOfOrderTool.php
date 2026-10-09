<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Models\Hotel;
use App\Services\MaintenanceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Corrects an out-of-order room's reason or expected end date, as the
 * maintenance screen does (MaintenanceService::updateOutOfOrder).
 */
class UpdateOutOfOrderTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Change why an out-of-order room is out of order, or when it is expected back (YYYY-MM-DD), by room number. '
            .'The room stays out of order; use the return-to-service tool to bring it back.';
    }

    public function handle(Request $request): Stringable|string
    {
        $room = $this->findRoomByNumber($request->string('room_number')->toString());

        if (! $room) {
            return 'This hotel has no room with that number.';
        }

        $data = array_filter([
            'reason' => $request->string('reason')->toString() ?: null,
            'expected_end_date' => $request->string('expected_end_date')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $this->attempt(function () use ($room, $data) {
            Validator::make($data, [
                'reason' => ['sometimes', 'string', 'max:500', 'required_without:expected_end_date'],
                'expected_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', $this->notPastInHotel(), 'required_without:reason'],
            ])->validate();

            return app(MaintenanceService::class)->updateOutOfOrder($room, $data);
        });

        if (is_string($result)) {
            return $result;
        }

        return $this->done(['room_id' => $room->id, 'room_number' => $room->room_number], $data);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_number' => $schema->string()->required(),
            'reason' => $schema->string(),
            'expected_end_date' => $schema->string()->description('YYYY-MM-DD.'),
        ];
    }
}
