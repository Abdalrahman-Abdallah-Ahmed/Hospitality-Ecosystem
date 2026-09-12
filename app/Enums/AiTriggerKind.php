<?php

namespace App\Enums;

/**
 * What caused an AI call — the field on ai_usage_logs that earns its keep.
 *
 * It separates cost we control from cost guests drive. A scheduled job runs
 * because we decided it should; an inbound WhatsApp message runs because
 * someone we have never met sent one. Only one of those is bounded by
 * anything we choose, and a cost report that cannot tell them apart cannot
 * say whether a rising bill is a growing business or an open tap.
 */
enum AiTriggerKind: string
{
    /** Externally driven: anyone who can message the hotel's number. */
    case GUEST_MESSAGE = 'guest_message';

    /** A logged-in human asked for it — the advisor chat, on-demand recommendations. */
    case STAFF_REQUEST = 'staff_request';

    /** Our own cron or queue work: insights, knowledge-base sync. */
    case SCHEDULED_JOB = 'scheduled_job';

    /**
     * A call that reached the provider without declaring where it came from.
     *
     * This exists so that an undeclared call is still logged and still
     * visible, rather than being quietly filed under whichever real kind
     * looked most likely. Guessing here would put invented facts in the one
     * table that exists to hold measured ones.
     *
     * In practice it should always read zero. A non-zero count means a new
     * call site skipped AiCostContext, and the cost endpoint reports it
     * explicitly for that reason.
     */
    case UNATTRIBUTED = 'unattributed';
}
