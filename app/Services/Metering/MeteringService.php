<?php

namespace App\Services\Metering;

use App\Enums\ActorKind;
use App\Enums\MeterFeature;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\MeterEvent;
use App\Models\Stay;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one writer for usage. Records what happened as an append-only event
 * and keeps a derived counter alongside it.
 *
 * Callers must never let a failure here reach the user — see record(). Losing
 * a meter event costs cents; failing to answer a guest costs a customer.
 */
class MeteringService
{
    /**
     * Run a metering call so that it cannot break what it is measuring.
     *
     * Every call site goes through this. If metering fails, the guest's
     * WhatsApp message must still be answered and the booking must still
     * save: a lost meter event costs cents, an unanswered guest costs a
     * customer. Under-billing is recoverable; a broken product is not.
     *
     * Keeping the try/catch here rather than at each call site means no
     * future call site can forget it.
     */
    public function safely(callable $callback): void
    {
        try {
            $callback($this);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Record one countable thing.
     *
     * Returns the event, or null when nothing was recorded: a non-positive
     * quantity, or an idempotency key this account has already used. A null
     * return is a normal outcome, not an error.
     *
     * Wrap every call site in try/catch and report — never rethrow.
     */
    public function record(
        HotelGroup $account,
        MeterFeature $feature,
        int $quantity = 1,
        ?Model $source = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
        ?Hotel $hotel = null,
        ?CarbonInterface $occurredAt = null,
        ?ActorKind $actorKind = null,
    ): ?MeterEvent {
        if ($feature->isSeat()) {
            throw new RuntimeException(
                "[{$feature->value}] is a seat: recount it with recountSeats(), do not record events for it."
            );
        }

        if ($quantity <= 0) {
            return null;
        }

        $occurredAt = $occurredAt ? Carbon::instance($occurredAt->toDateTime()) : now();
        $periodStart = self::periodStart($occurredAt);
        $actor = Auth::user();

        try {
            return DB::transaction(function () use (
                $account, $feature, $quantity, $source, $idempotencyKey,
                $metadata, $hotel, $occurredAt, $periodStart, $actor, $actorKind
            ) {
                $event = MeterEvent::create([
                    'hotel_group_id' => $account->getKey(),
                    'hotel_id' => $hotel?->getKey(),
                    'feature_code' => $feature->value,
                    'quantity' => $quantity,
                    'unit' => $feature->unit(),
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'actor_type' => $actor?->getMorphClass(),
                    'actor_id' => $actor?->getKey(),
                    'actor_kind' => ($actorKind ?? ($actor ? ActorKind::USER : ActorKind::SYSTEM))->value,
                    'period_start' => $periodStart,
                    'occurred_at' => $occurredAt,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata ?: null,
                ]);

                $this->bumpCounter($account->getKey(), $feature->value, $periodStart, $quantity);

                return $event;
            });
        } catch (QueryException $e) {
            // 23505 = unique violation. The only unique key on the table is
            // (hotel_group_id, idempotency_key), so this is a replay: the
            // event is already recorded and must not be counted twice.
            if ($e->getCode() === '23505') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Same as record(), for the many call sites that hold a hotel rather than
     * an account. Resolves the account from the hotel so no caller has to
     * remember that a hotel's group is the thing usage belongs to.
     */
    public function recordForHotel(
        Hotel $hotel,
        MeterFeature $feature,
        int $quantity = 1,
        ?Model $source = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
        ?ActorKind $actorKind = null,
    ): ?MeterEvent {
        $account = $hotel->hotelGroup;

        if (! $account) {
            // Since the hotel-group prerequisite this cannot happen through
            // any normal path, but a meter event with no account is worse
            // than a missing one: it is a row nobody can ever attribute.
            Log::warning('Metering skipped: hotel has no group.', ['hotel_id' => $hotel->getKey()]);

            return null;
        }

        return $this->record(
            account: $account,
            feature: $feature,
            quantity: $quantity,
            source: $source,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
            hotel: $hotel,
            occurredAt: $occurredAt,
            actorKind: $actorKind,
        );
    }

    /**
     * Seats are "how many exist right now", so they are read back from the
     * tables that own them and the counter is overwritten. Nothing is summed
     * and no meter event is written: summing snapshots would be meaningless,
     * and incrementing would produce a property count that only ever rises,
     * surviving every hotel anyone deletes.
     *
     * @return array<string, int> feature code => count
     */
    public function recountSeats(HotelGroup $account, ?CarbonInterface $at = null): array
    {
        $periodStart = self::periodStart($at ?? now());
        $hotelIds = Hotel::query()->where('hotel_group_id', $account->getKey())->pluck('id');

        $counts = [
            MeterFeature::PROPERTIES->value => $hotelIds->count(),
            MeterFeature::USERS->value => User::query()
                ->where(function ($query) use ($account, $hotelIds) {
                    $query->where('hotel_group_id', $account->getKey())
                        ->orWhereIn('hotel_id', $hotelIds);
                })
                ->count(),
            // Guest and Stay are tenant-scoped; a recount is an account-wide
            // question, so the per-request hotel scope must not narrow it.
            MeterFeature::GUESTS->value => Guest::withoutGlobalScope('hotel')
                ->whereIn('hotel_id', $hotelIds)->count(),
            MeterFeature::STAYS->value => Stay::withoutGlobalScope('hotel')
                ->whereIn('hotel_id', $hotelIds)->count(),
        ];

        foreach ($counts as $featureCode => $count) {
            $this->setCounter($account->getKey(), $featureCode, $periodStart, $count);
        }

        return $counts;
    }

    /**
     * The safety net. Recomputes every counter for a period from the events
     * that back it and overwrites what was there, then recounts seats.
     *
     * Drift between the incremental counter and the events is a bug, so it is
     * logged rather than quietly corrected — a counter that keeps needing
     * repair is telling you something.
     *
     * @return array<int, array{hotel_group_id: string, feature_code: string, was: int, now: int}>
     */
    public function rebuild(?CarbonInterface $at = null): array
    {
        $periodStart = self::periodStart($at ?? now());
        $discrepancies = [];
        $seen = [];

        $summed = MeterEvent::query()
            ->where('period_start', $periodStart)
            ->groupBy('hotel_group_id', 'feature_code')
            ->select('hotel_group_id', 'feature_code', DB::raw('sum(quantity) as total'))
            ->get();

        foreach ($summed as $row) {
            $seen[] = $row->hotel_group_id.'|'.$row->feature_code->value;

            $was = $this->setCounter(
                $row->hotel_group_id,
                $row->feature_code->value,
                $periodStart,
                (int) $row->total,
            );

            if ($was !== null && $was !== (int) $row->total) {
                $discrepancies[] = [
                    'hotel_group_id' => $row->hotel_group_id,
                    'feature_code' => $row->feature_code->value,
                    'was' => $was,
                    'now' => (int) $row->total,
                ];
            }
        }

        // A counter with no events behind it should read zero, not keep its
        // last value. Seats are excluded: they are not event-derived.
        $seatCodes = array_map(fn (MeterFeature $feature) => $feature->value, MeterFeature::seats());

        $orphaned = DB::table('usage_counters')
            ->where('period_start', $periodStart)
            ->whereNotIn('feature_code', $seatCodes)
            ->get(['hotel_group_id', 'feature_code', 'used']);

        foreach ($orphaned as $counter) {
            if (in_array($counter->hotel_group_id.'|'.$counter->feature_code, $seen, true)) {
                continue;
            }

            if ((int) $counter->used !== 0) {
                $discrepancies[] = [
                    'hotel_group_id' => $counter->hotel_group_id,
                    'feature_code' => $counter->feature_code,
                    'was' => (int) $counter->used,
                    'now' => 0,
                ];
            }

            $this->setCounter($counter->hotel_group_id, $counter->feature_code, $periodStart, 0);
        }

        HotelGroup::query()->each(function (HotelGroup $account) use ($at) {
            $this->recountSeats($account, $at);
        });

        foreach ($discrepancies as $discrepancy) {
            Log::warning('Usage counter drifted from its events.', $discrepancy);
        }

        return $discrepancies;
    }

    /**
     * The billing period an event belongs to. The calendar month for now —
     * when subscriptions exist this comes from the subscription's own dates
     * instead, and stops being aligned to the calendar.
     */
    public static function periodStart(CarbonInterface $at): string
    {
        return Carbon::instance($at->toDateTime())->startOfMonth()->toDateString();
    }

    /**
     * Add to a counter atomically. Two concurrent events on the same counter
     * both land: the database does the addition, not PHP.
     */
    protected function bumpCounter(string $accountId, string $featureCode, string $periodStart, int $quantity): void
    {
        DB::statement(
            'insert into usage_counters
                (id, hotel_group_id, feature_code, period_start, used, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, ?)
             on conflict (hotel_group_id, feature_code, period_start)
             do update set used = usage_counters.used + excluded.used,
                           updated_at = excluded.updated_at',
            [(string) Str::uuid(), $accountId, $featureCode, $periodStart, $quantity, now(), now()]
        );
    }

    /**
     * Overwrite a counter with a known total. Returns what it held before, or
     * null if it did not exist, so a rebuild can report the drift.
     */
    protected function setCounter(string $accountId, string $featureCode, string $periodStart, int $used): ?int
    {
        $existing = DB::table('usage_counters')
            ->where('hotel_group_id', $accountId)
            ->where('feature_code', $featureCode)
            ->where('period_start', $periodStart)
            ->value('used');

        DB::statement(
            'insert into usage_counters
                (id, hotel_group_id, feature_code, period_start, used, recomputed_at, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, ?, ?)
             on conflict (hotel_group_id, feature_code, period_start)
             do update set used = excluded.used,
                           recomputed_at = excluded.recomputed_at,
                           updated_at = excluded.updated_at',
            [(string) Str::uuid(), $accountId, $featureCode, $periodStart, $used, now(), now(), now()]
        );

        return $existing === null ? null : (int) $existing;
    }
}
