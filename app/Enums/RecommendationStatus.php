<?php

namespace App\Enums;

/**
 * A recommendation's lifecycle (SPEC-071).
 *
 * Every recommendation starts PENDING_APPROVAL. Only APPROVED ones may be
 * offered to a guest; offering one moves it to SENT, and the guest's
 * reaction moves it on from there.
 */
enum RecommendationStatus: string
{
    /**
     * Retired: "waiting to be offered" before approval existed. Migrated to
     * PENDING_APPROVAL and never written or read again. Kept only because a
     * shipped migration names it as the old column default.
     */
    case PENDING = 'pending';

    case PENDING_APPROVAL = 'pending_approval';     // generated, awaiting an approver
    case APPROVED = 'approved';                     // may be offered to the guest
    case REJECTED_BY_ADMIN = 'rejected_by_admin';   // an approver refused it; never offered
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';                     // the guest declined it
    case PURCHASED = 'purchased';
    case IGNORED = 'ignored';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    /**
     * Nothing moves a recommendation out of a final status.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::ACCEPTED,
            self::REJECTED,
            self::PURCHASED,
            self::IGNORED,
            self::EXPIRED,
            self::CANCELLED,
            self::REJECTED_BY_ADMIN,
        ], true);
    }
}
