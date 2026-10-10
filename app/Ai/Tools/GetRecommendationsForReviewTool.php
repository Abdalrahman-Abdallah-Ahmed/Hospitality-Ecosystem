<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Ai\Tools\Admin\ListResult;
use App\Enums\RecommendationStatus;
use App\Models\Hotel;
use App\Models\Recommendation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The approval queue, for the Admin AI (SPEC-071 FR-012): recommendations
 * awaiting a decision by default, with the guest, room and activity, so the
 * admin can name the one to approve.
 */
class GetRecommendationsForReviewTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'List AI-generated activity recommendations awaiting approval (or another status), with the guest, room, '
            .'activity, reason and confidence. Filter by guest name, room number, reservation, activity or arrival dates.';
    }

    public function handle(Request $request): Stringable|string
    {
        $status = RecommendationStatus::tryFrom($request->string('status')->toString() ?: RecommendationStatus::PENDING_APPROVAL->value)
            ?? RecommendationStatus::PENDING_APPROVAL;
        $guest = trim($request->string('guest')->toString());
        $room = trim($request->string('room_number')->toString());
        $activity = trim($request->string('activity')->toString());

        $query = Recommendation::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('status', $status->value)
            ->with(['reservation.guest', 'reservation.reservationRooms.room', 'activity'])
            ->when($request->filled('reservation_id'), fn ($query) => $query->where('reservation_id', $request->string('reservation_id')->toString()))
            ->when($guest !== '', fn ($query) => $query->whereHas('reservation.guest', fn ($q) => $q
                ->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", ['%'.$guest.'%'])))
            ->when($room !== '', fn ($query) => $query->whereHas('reservation.reservationRooms.room', fn ($q) => $q->where('room_number', $room)))
            ->when($activity !== '', fn ($query) => $query->whereHas('activity', fn ($q) => $q->where('name', 'ilike', '%'.$activity.'%')))
            ->when($request->filled('arrival_from'), fn ($query) => $query->whereHas('reservation', fn ($q) => $q->whereDate('arrival_date', '>=', $request->string('arrival_from')->toString())))
            ->when($request->filled('arrival_to'), fn ($query) => $query->whereHas('reservation', fn ($q) => $q->whereDate('arrival_date', '<=', $request->string('arrival_to')->toString())))
            ->orderBy('recommended_at');

        return ListResult::fromQuery($query, ListResult::MAX, fn (Recommendation $recommendation) => [
            'id' => $recommendation->id,
            'guest' => $this->guestName($recommendation->reservation?->guest),
            'reservation' => $recommendation->reservation?->reservation_id,
            'rooms' => $recommendation->reservation?->reservationRooms->map(fn ($line) => $line->room?->room_number)->filter()->values()->all(),
            'arrival' => $recommendation->reservation?->arrival_date?->toDateString(),
            'activity' => $recommendation->activity?->name,
            'reason' => $recommendation->reason,
            'predicted_confidence' => $recommendation->predicted_confidence,
            'status' => $recommendation->status->value,
            'source' => $recommendation->source?->value,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_map(fn (RecommendationStatus $s) => $s->value, RecommendationStatus::cases()))
                ->description('Default pending_approval.'),
            'guest' => $schema->string()->description('Part of the guest\'s name.'),
            'room_number' => $schema->string(),
            'reservation_id' => $schema->string()->description('The reservation\'s id (uuid).'),
            'activity' => $schema->string()->description('Part of the activity\'s name.'),
            'arrival_from' => $schema->string()->description('YYYY-MM-DD'),
            'arrival_to' => $schema->string()->description('YYYY-MM-DD'),
        ];
    }
}
