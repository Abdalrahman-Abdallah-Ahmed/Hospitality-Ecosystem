<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Models\Hotel;
use App\Models\User;
use App\Services\MaintenanceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Takes a room out of order, exactly as the maintenance screen does
 * (MaintenanceService::takeOutOfOrder): the room stops being sellable and
 * the reservations that held it are listed. Hard to reverse while guests are
 * affected, so the admin confirms it first (FR-017).
 */
class SetRoomOutOfOrderTool implements ConfirmsBeforeRunning, Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Take a room out of order, by room number, with the reason and, if the admin gave one, the expected end '
            .'date (YYYY-MM-DD). The system asks the admin to confirm before it runs. A room with a guest in it cannot be '
            .'taken out of order; the reason comes back.';
    }

    public function needsConfirmation(Request $request): bool
    {
        return true;
    }

    public function confirmationSummary(Request $request, string $locale): string
    {
        $number = trim($request->string('room_number')->toString());
        $room = $this->findRoomByNumber($number);
        $type = $room?->roomType?->name ?? '?';
        $reason = $request->string('reason')->toString();
        $until = $request->string('expected_end_date')->toString() ?: ($locale === 'ar' ? 'غير محدد' : 'unknown');

        return $locale === 'ar'
            ? "إيقاف الغرفة {$number} ({$type}) عن الخدمة: {$reason}، حتى {$until}."
            : "Put room {$number} ({$type}) out of order: {$reason}, until {$until}.";
    }

    public function handle(Request $request): Stringable|string
    {
        $room = $this->findRoomByNumber($request->string('room_number')->toString());

        if (! $room) {
            return 'This hotel has no room with that number.';
        }

        $data = [
            'reason' => $request->string('reason')->toString(),
            'expected_end_date' => $request->string('expected_end_date')->toString() ?: null,
        ];

        $result = $this->attempt(function () use ($room, $data) {
            Validator::make($data, [
                'reason' => ['required', 'string', 'max:500'],
                'expected_end_date' => ['nullable', 'date_format:Y-m-d', $this->notPastInHotel()],
            ])->validate();

            return app(MaintenanceService::class)->takeOutOfOrder($room, $this->user, $data['reason'], $data['expected_end_date']);
        });

        if (is_string($result)) {
            return $result;
        }

        return $this->done(
            ['room_id' => $room->id, 'room_number' => $room->room_number],
            ['status' => 'out_of_order', 'changed' => $result['changed'], 'reason' => $data['reason'], 'expected_end_date' => $data['expected_end_date']],
            $result['affected_lines']->isEmpty() ? null : 'Reservations holding this room: '.$result['affected_lines']->count().'. They need another room.',
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_number' => $schema->string()->required(),
            'reason' => $schema->string()->description('What is wrong, in the admin\'s words.')->required(),
            'expected_end_date' => $schema->string()->description('When it should be back, YYYY-MM-DD. Only if the admin said.'),
        ];
    }
}
