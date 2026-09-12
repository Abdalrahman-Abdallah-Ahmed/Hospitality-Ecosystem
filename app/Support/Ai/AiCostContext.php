<?php

namespace App\Support\Ai;

use App\Enums\AiTriggerKind;
use App\Exceptions\AiSpendCeilingExceededException;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Services\AiCost\AiSpendCeiling;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a provider call is being made for, and what set it off.
 *
 * The laravel/ai events carry the model, the tokens, and the latency, but
 * nothing about the hotel or the guest — the package has no idea our accounts
 * exist. This supplies that half, the same way EventLogger::asAiAgent()
 * supplies actor kind to the audit trail: a call site declares the context
 * once, and everything that happens inside it is attributed correctly without
 * being told again.
 *
 * That is what keeps capture central. A new agent written next year is logged
 * with full cost attribution as long as its caller opens a context, and is
 * still logged — as `unattributed`, visibly — if it forgets.
 *
 * Contexts nest, and the innermost wins. This matters: a knowledge-base
 * search inside a guest conversation embeds a query, and that embedding is
 * guest-driven cost. Because the embedding call happens inside the
 * conversation's context, it is attributed to the guest rather than filed
 * as an anonymous system cost.
 */
class AiCostContext
{
    /** @var array<int, array{account: ?HotelGroup, hotel: ?Hotel, trigger: ?Model, kind: AiTriggerKind}> */
    private static array $stack = [];

    /**
     * Attempts within the current context, keyed by nesting depth. A failover
     * to a second provider is the same logical call being retried, and the
     * retry costs real money — so it is logged as attempt 2, not as a
     * separate first attempt.
     *
     * @var array<int, int>
     */
    private static array $attempts = [];

    /**
     * Run a callback with every AI call inside it attributed to this account
     * and trigger, restoring the previous context afterward even if it throws.
     *
     * This is also where the hard daily ceiling is enforced, because it is
     * the one point every AI call passes through — which is exactly what an
     * abuse stop needs. The check happens before the context is pushed, so a
     * stopped account throws without leaving anything behind on the stack.
     *
     * @throws AiSpendCeilingExceededException
     */
    public static function for(
        AiTriggerKind $kind,
        ?Hotel $hotel = null,
        ?Model $trigger = null,
        ?HotelGroup $account = null,
        ?callable $callback = null,
    ): mixed {
        $account ??= $hotel?->hotelGroup;

        // Nested contexts are part of a call already admitted — re-checking
        // would stop an in-flight conversation halfway through.
        if (self::$stack === []) {
            app(AiSpendCeiling::class)->assertNotExceeded($account);
        }

        self::$stack[] = [
            'account' => $account,
            'hotel' => $hotel,
            'trigger' => $trigger,
            'kind' => $kind,
        ];

        self::$attempts[count(self::$stack)] = 0;

        try {
            return $callback();
        } finally {
            unset(self::$attempts[count(self::$stack)]);
            array_pop(self::$stack);
        }
    }

    /**
     * The context a call being made right now belongs to.
     *
     * Falls back to the tenant scope for the hotel, so a job that set up its
     * tenancy but not its cost context still attributes to the right account.
     * The trigger kind does NOT fall back: an undeclared call is recorded as
     * `unattributed` rather than being filed under whichever kind seemed
     * likely. Guessing would put an invented fact in the table that exists to
     * hold measured ones.
     *
     * @return array{account: ?HotelGroup, hotel: ?Hotel, trigger: ?Model, kind: AiTriggerKind}
     */
    public static function current(): array
    {
        if (self::$stack !== []) {
            return self::$stack[count(self::$stack) - 1];
        }

        $hotel = ($hotelId = TenantContext::currentHotelId())
            ? Hotel::query()->find($hotelId)
            : null;

        return [
            'account' => $hotel?->hotelGroup,
            'hotel' => $hotel,
            'trigger' => null,
            'kind' => AiTriggerKind::UNATTRIBUTED,
        ];
    }

    /**
     * Count this call against the current context and return which attempt it
     * is. The first call in a context is attempt 1; a failover retry is 2.
     */
    public static function nextAttempt(): int
    {
        $depth = count(self::$stack);

        if ($depth === 0) {
            return 1;
        }

        return self::$attempts[$depth] = (self::$attempts[$depth] ?? 0) + 1;
    }

    /**
     * The attempt number the current context is on, without advancing it.
     */
    public static function currentAttempt(): int
    {
        return self::$attempts[count(self::$stack)] ?? 1;
    }

    public static function reset(): void
    {
        self::$stack = [];
        self::$attempts = [];
    }
}
