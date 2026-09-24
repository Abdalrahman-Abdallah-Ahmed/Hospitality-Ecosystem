<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\FindsReservationStays;
use App\Enums\Permission;
use App\Enums\StayStatus;
use App\Models\Hotel;
use App\Models\Stay;
use App\Models\User;
use App\Services\StayLifecycleService;
use App\Support\Audit\EventLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Checks guests out for the Admin AI through StayLifecycleService, with the
 * same rules and messages as the front desk (FR-023): each room checked out
 * is left dirty with a cleaning task. Checks stays.check_out for the admin it
 * acts for; writes are audited as the AI agent.
 */
class CheckOutTool implements Tool
{
    use FindsReservationStays;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Check guests out of a reservation: every room in the house, or only the rooms with the given room '
            .'numbers. Each room checked out is marked dirty and gets a cleaning task for housekeeping. If rooms of the '
            .'reservation never arrived, the last room cannot check out until those are cancelled; the reason comes back.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->hasPermission(Permission::STAYS_CHECK_OUT)) {
            return 'You do not have permission to check guests out.';
        }

        $reservation = $this->findReservation($this->hotel, trim($request->string('reservation_id')->toString()));

        if (! $reservation) {
            return 'This hotel has no reservation with that code.';
        }

        $stays = $this->staysOf($reservation);
        $numbers = $this->roomNumbers((array) ($request['room_numbers'] ?? []));
        $at = $request->string('checked_out_at')->toString() ?: null;
        $lifecycle = app(StayLifecycleService::class);

        try {
            $result = EventLogger::asAiAgent(function () use ($lifecycle, $reservation, $stays, $numbers, $at) {
                if ($numbers === []) {
                    return $lifecycle->checkOutReservation($reservation, $at);
                }

                $chosen = $stays->filter(fn (Stay $stay) => in_array($stay->room?->room_number, $numbers, true));

                if ($chosen->isEmpty()) {
                    throw ValidationException::withMessages(['room_numbers' => 'None of those rooms is on this reservation.']);
                }

                return DB::transaction(function () use ($lifecycle, $chosen, $at) {
                    $tasks = collect();

                    foreach ($chosen as $stay) {
                        $result = $lifecycle->checkOut($stay, $at);
                        $tasks = $tasks->merge($result['cleaning_tasks']);
                    }

                    return [...$result, 'cleaning_tasks' => $tasks];
                });
            });
        } catch (ValidationException $e) {
            return $this->refusal($e);
        }

        $out = $this->staysOf($reservation)
            ->where('status', StayStatus::DEPARTED)
            ->map(fn (Stay $stay) => $stay->room?->room_number)
            ->filter()
            ->implode(', ');
        $tasks = collect($result['cleaning_tasks'])->pluck('id')->implode(', ');

        return "Checked out {$reservation->reservation_id}. Rooms checked out: {$out}. Reservation status: {$result['reservation']->status->value}."
            .($tasks !== '' ? " Cleaning tasks created: {$tasks}." : '');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reservation_id' => $schema->string()->description("The reservation's code, e.g. RES-1001.")->required(),
            'room_numbers' => $schema->array()->items($schema->string())
                ->description('Only check out these rooms (by room number). Leave out to check out every room in the house.'),
            'checked_out_at' => $schema->string()
                ->description('The actual check-out time if it was earlier today (ISO 8601). Only when the admin states it.'),
        ];
    }
}
