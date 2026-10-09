<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Services\Reservations\ReservationCommands;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Changes a reservation's dates, party, room types or requests through
 * ReservationCommands, with the same rules and messages as the reservation
 * screen: it never oversells a room type and never overrides capacity
 * (FR-011). It has no status field at all: cancelling has its own tool, and
 * checking in or out only happens through the check-in/out tools (FR-012).
 *
 * A room list that leaves out rooms the reservation holds cancels them, which
 * is as hard to undo as cancelling the reservation: that call waits for the
 * admin's confirmation (FR-017). Adding rooms or changing dates does not.
 */
class UpdateReservationTool implements ConfirmsBeforeRunning, Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Change a reservation, found by its code: arrival or departure date, adults, children, special requests, '
            .'or the room types it holds. For rooms, send the complete list the reservation should have afterwards '
            .'(e.g. [{room_type: Deluxe, quantity: 2}]); rooms already assigned are kept where possible. Send only what '
            .'the admin asked to change. Removing rooms waits for the admin\'s confirmation, which the system asks '
            .'for. If the change would oversell a room type, nothing changes and the short nights come back. It '
            .'cannot cancel, check in or check out.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reservation = $this->findReservationByCode($request->string('code')->toString());

        if (! $reservation) {
            return 'This hotel has no reservation with that code.';
        }

        $attributes = [];

        foreach (['arrival_date', 'departure_date', 'special_requests'] as $field) {
            if ($request->filled($field)) {
                $attributes[$field] = $request->string($field)->toString();
            }
        }

        foreach (['adults', 'children'] as $field) {
            if ($request->has($field) && $request[$field] !== null) {
                $attributes[$field] = $request->integer($field);
            }
        }

        $rooms = null;

        if ($request->filled('rooms')) {
            $plan = $this->roomPlan($reservation->reservationRooms()->active()->get(), (array) $request['rooms']);

            if (is_string($plan)) {
                return $plan;
            }

            $rooms = $plan['lines'];
        }

        if ($attributes === [] && $rooms === null) {
            return 'Nothing to change: say what to update.';
        }

        $result = $this->attempt(fn () => app(ReservationCommands::class)->update($reservation, $attributes, $rooms));

        if (is_string($result)) {
            return $result;
        }

        $result->load(['reservationRooms.roomType', 'reservationRooms.room']);

        return $this->done(
            ['reservation_id' => $result->id, 'code' => $result->reservation_id],
            [...$attributes, ...($rooms !== null ? ['rooms' => $result->roomsForAi()['room_summary']] : [])],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->description('The reservation code, e.g. RES-1001.')->required(),
            'arrival_date' => $schema->string()->description('New arrival date, YYYY-MM-DD.'),
            'departure_date' => $schema->string()->description('New departure date, YYYY-MM-DD.'),
            'adults' => $schema->integer()->description('Total adults across all rooms.'),
            'children' => $schema->integer()->description('Total children across all rooms.'),
            'special_requests' => $schema->string(),
            'rooms' => $schema->array()->items($schema->object([
                'room_type' => $schema->string()->description('Room type name, e.g. Deluxe.')->required(),
                'quantity' => $schema->integer()->description('How many rooms of this type. Default 1.'),
            ]))->description('The complete list of rooms the reservation should hold afterwards. Leave out to keep the rooms as they are.'),
        ];
    }

    public function needsConfirmation(Request $request): bool
    {
        return $this->droppedLines($request)->isNotEmpty();
    }

    public function confirmationSummary(Request $request, string $locale): string
    {
        $code = trim($request->string('code')->toString());
        $dropped = $this->droppedLines($request)
            ->map(fn (ReservationRoom $line) => trim(($line->roomType?->name ?? '?').' '.($line->room?->room_number ?? ($locale === 'ar' ? 'غير محددة' : 'unassigned'))))
            ->implode(', ');

        return $locale === 'ar'
            ? "تعديل الحجز {$code} وإلغاء هذه الغرف منه: {$dropped}."
            : "Change reservation {$code}, removing these rooms from it: {$dropped}.";
    }

    /**
     * The live lines a call's room list would cancel; none when it sends no
     * room list or the reservation is not this hotel's.
     *
     * @return Collection<int, ReservationRoom>
     */
    private function droppedLines(Request $request): Collection
    {
        $reservation = $this->findReservationByCode($request->string('code')->toString());

        if (! $reservation || ! $request->filled('rooms')) {
            return collect();
        }

        $plan = $this->roomPlan($reservation->reservationRooms()->active()->with(['roomType', 'room'])->get(), (array) $request['rooms']);

        return is_string($plan) ? collect() : $plan['dropped'];
    }

    /**
     * The desired room types as ReservationCreator lines: for each type, keep
     * that many of its live lines (assigned ones first, so an assigned room
     * is not dropped for an unassigned one) and add new lines for the rest.
     * Quantities of a type named twice are added together. Live lines not
     * kept are left out, which cancels them: they are returned as `dropped`.
     *
     * @param  Collection<int, ReservationRoom>  $live
     * @param  array<int, mixed>  $wanted
     * @return array{lines: array<int, array<string, mixed>>, dropped: Collection<int, ReservationRoom>}|string
     */
    private function roomPlan(Collection $live, array $wanted): array|string
    {
        $quantities = $this->quantitiesByType($wanted);

        if (is_string($quantities)) {
            return $quantities;
        }

        $lines = [];
        $keptIds = [];

        foreach ($quantities as $typeId => $quantity) {
            $kept = $live->where('room_type_id', $typeId)
                ->sortBy(fn (ReservationRoom $line) => $line->room_id === null ? 1 : 0)
                ->take($quantity);

            foreach ($kept as $line) {
                $lines[] = ['id' => $line->id];
                $keptIds[] = $line->id;
            }

            if ($kept->count() < $quantity) {
                $lines[] = ['room_type_id' => $typeId, 'quantity' => $quantity - $kept->count()];
            }
        }

        return [
            'lines' => $lines,
            'dropped' => $live->reject(fn (ReservationRoom $line) => in_array($line->id, $keptIds, true))->values(),
        ];
    }

    /**
     * @param  array<int, mixed>  $wanted
     * @return array<string, int>|string room type id => total quantity, or why the list is unusable
     */
    private function quantitiesByType(array $wanted): array|string
    {
        $quantities = [];

        foreach ($wanted as $item) {
            $name = trim((string) ($item['room_type'] ?? ''));

            $type = RoomType::withoutGlobalScope('hotel')
                ->where('hotel_id', $this->hotel->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->first();

            if (! $type) {
                return "This hotel has no room type called \"{$name}\".";
            }

            $quantities[$type->id] = ($quantities[$type->id] ?? 0) + max(1, (int) ($item['quantity'] ?? 1));
        }

        return $quantities;
    }
}
