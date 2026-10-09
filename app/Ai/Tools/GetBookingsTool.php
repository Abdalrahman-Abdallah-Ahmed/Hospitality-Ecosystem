<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Hotel;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Activity bookings for the Admin AI, with the same filters as the booking
 * list, including the ones a guest has asked to cancel.
 */
class GetBookingsTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'List this hotel\'s activity bookings: reference, activity, guest, date and time, party size, status, '
            .'and whether the guest has asked to cancel. Filter by guest (name or phone), activity name, date range '
            .'(YYYY-MM-DD), status, or only those with a pending cancellation request. Returns at most 50 with the total.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = Booking::with(['guest', 'activity'])
            ->withExists('openCancellationRequest as cancellation_requested')
            ->where('hotel_id', $this->hotel->id)
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('date_from'), fn (Builder $query) => $query->where('scheduled_date', '>=', $request->string('date_from')->toString()))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->where('scheduled_date', '<=', $request->string('date_to')->toString()))
            ->when($request->filled('activity'), fn (Builder $query) => $query->whereHas('activity', fn (Builder $activity) => $activity->where('name', 'ilike', '%'.addcslashes($request->string('activity')->toString(), '%_\\').'%')))
            ->when($request->has('cancellation_requested'), fn (Builder $query) => $request->boolean('cancellation_requested')
                ? $query->whereHas('openCancellationRequest')
                : $query->whereDoesntHave('openCancellationRequest'));

        if ($request->filled('guest')) {
            $guest = trim($request->string('guest')->toString());
            $digits = (string) PhoneNumber::digits($guest);

            $query->whereHas('guest', fn (Builder $query) => $query
                ->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", ['%'.addcslashes($guest, '%_\\').'%'])
                ->when(strlen($digits) >= PhoneNumber::MIN_DIGITS, fn (Builder $query) => $query->orWhereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$digits])));
        }

        $query->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('scheduled_for');

        return ListResult::fromQuery($query, ListResult::limit($request), fn (Booking $booking) => [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'activity' => $booking->activity?->name ?? $booking->item_name,
            'guest' => $booking->guest ? trim($booking->guest->first_name.' '.$booking->guest->last_name) : null,
            'scheduled_for' => $booking->scheduled_for?->copy()->setTimezone($this->hotel->timezone ?: config('app.timezone'))->format('Y-m-d H:i') ?? $booking->scheduled_date?->toDateString(),
            'pax' => $booking->pax,
            'status' => $booking->status->value,
            'cancellation_requested' => (bool) $booking->cancellation_requested,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guest' => $schema->string()->description("Part of the guest's name, or their phone number."),
            'activity' => $schema->string()->description('Part of the activity name.'),
            'date_from' => $schema->string()->description('On or after this date, YYYY-MM-DD.'),
            'date_to' => $schema->string()->description('On or before this date, YYYY-MM-DD.'),
            'status' => $schema->string()->enum(BookingStatus::class),
            'cancellation_requested' => $schema->boolean()->description('Only bookings the guest has asked to cancel (true), or only those they have not (false).'),
            'limit' => $schema->integer()->description('At most this many, 1-50. Default 50.'),
        ];
    }
}
