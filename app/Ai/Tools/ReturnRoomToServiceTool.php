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
 * Brings an out-of-order room back into service, as the maintenance screen
 * does (MaintenanceService::returnToService): it becomes sellable again and
 * gets a cleaning task before the next guest.
 */
class ReturnRoomToServiceTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Return an out-of-order room to service, by room number, with an optional note. It becomes sellable again '
            .'and gets a cleaning task for housekeeping.';
    }

    public function handle(Request $request): Stringable|string
    {
        $room = $this->findRoomByNumber($request->string('room_number')->toString());

        if (! $room) {
            return 'This hotel has no room with that number.';
        }

        $note = $request->string('note')->toString() ?: null;

        $result = $this->attempt(function () use ($room, $note) {
            Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:500']])->validate();

            return app(MaintenanceService::class)->returnToService($room, $note);
        });

        if (is_string($result)) {
            return $result;
        }

        return $this->done(
            ['room_id' => $room->id, 'room_number' => $room->room_number, 'cleaning_task_id' => $result['cleaning_task']?->id],
            ['status' => $result['room']->status?->value ?? 'available'],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_number' => $schema->string()->required(),
            'note' => $schema->string()->description('What was fixed, if the admin said.'),
        ];
    }
}
