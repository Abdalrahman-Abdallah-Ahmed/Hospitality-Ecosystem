<?php

namespace App\Ai\Tools;

use App\Enums\Permission;
use App\Models\Hotel;
use App\Models\Stay;
use App\Models\User;
use App\Support\Stays\FrontDeskLists;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The front desk's lists for the Admin AI — arrivals, departures, in-house —
 * with the same rules as the stays endpoints (FrontDeskLists), so it can find
 * the stay it is asked to check in or out. Checks stays.view for the admin it
 * acts for.
 */
class GetStaysTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'List guest stays for a day: arrivals (expected guests arriving that day or late), departures (guests in '
            .'the house due out that day or overdue) or in_house (everyone in the house). Each row gives the '
            .'reservation code, guest, room type, room number (or "unassigned"), planned dates and flags. Use it '
            .'to find who is arriving, leaving or staying; never guess occupancy.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->hasPermission(Permission::STAYS_VIEW)) {
            return 'You do not have permission to view stays.';
        }

        $list = $request->string('list')->toString();

        if (! in_array($list, FrontDeskLists::LISTS, true)) {
            return 'Ask for one of: '.implode(', ', FrontDeskLists::LISTS).'.';
        }

        $day = $request->string('date')->toString() ?: FrontDeskLists::today($this->hotel);

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $day)->toDateString();
        } catch (Throwable) {
            return 'The date must be YYYY-MM-DD.';
        }

        $rows = FrontDeskLists::get($this->hotel, $list, $day)->map(fn (Stay $stay) => [
            'reservation' => $stay->reservation?->reservation_id,
            'guest' => trim(($stay->guest?->first_name ?? '').' '.($stay->guest?->last_name ?? '')) ?: $stay->guest?->phone_number,
            'room_type' => $stay->reservationRoom?->roomType?->name,
            'room_number' => $stay->room?->room_number ?? 'unassigned',
            'arrival' => $stay->planned_arrival_date->toDateString(),
            'departure' => $stay->planned_departure_date->toDateString(),
            'status' => $stay->status->value,
            'late' => $list === 'arrivals' && $stay->planned_arrival_date->toDateString() < $day,
            'overdue' => $list !== 'arrivals' && $stay->planned_departure_date->toDateString() < $day,
        ]);

        return json_encode(['list' => $list, 'date' => $day, 'stays' => $rows->values()->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()->description('arrivals, departures or in_house.')->required(),
            'date' => $schema->string()->description('The day, YYYY-MM-DD. Defaults to today at the hotel.'),
        ];
    }
}
