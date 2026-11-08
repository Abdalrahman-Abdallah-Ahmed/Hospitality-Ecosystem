<?php

namespace App\Services\Superadmin;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Audit\EventLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Starting and ending impersonation sessions (spec 027). A session is a token of
 * the target user with an impersonator_id, so that user's permissions and hotels
 * apply unchanged.
 *
 * Both events name the target user as subject, because a token's bigint id does
 * not fit the uuid subject_id. Both set impersonated_user_id explicitly, because
 * the super admin endpoints run on the super admin's own token, where the swap
 * in EventLogger does not apply.
 */
class Impersonation
{
    private const TOKEN_NAME = 'impersonation';

    private const MINUTES = 30;

    /**
     * In one transaction: a token left without its impersonator_id would be an
     * ordinary login token, free of every limit a session has.
     *
     * @throws DomainRuleException when the target is a super admin
     */
    public function start(User $superAdmin, User $target): NewAccessToken
    {
        if ($target->isSuperAdmin()) {
            throw new DomainRuleException('A super admin cannot be impersonated.');
        }

        return DB::transaction(function () use ($superAdmin, $target) {
            $token = $target->createToken(self::TOKEN_NAME, ['*'], now()->addMinutes(self::MINUTES));

            // Not fillable on Sanctum's model.
            $token->accessToken->forceFill(['impersonator_id' => $superAdmin->getKey()])->save();

            EventLogger::recordPlatform('platform.impersonation.started', $target, context: [
                'impersonated_user_id' => $target->getKey(),
                'token_id' => $token->accessToken->getKey(),
                'expires_at' => $token->accessToken->expires_at->toIso8601String(),
            ]);

            return $token;
        });
    }

    /**
     * False when the id is not a running session: not an impersonation token,
     * already expired (which writes no event), or for a user who is gone, whose
     * token already returns 401 and leaves no subject to record against.
     */
    public function end(int $tokenId): bool
    {
        $token = $this->running()->whereKey($tokenId)->first();

        return $token?->tokenable !== null && $this->finish($token, $token->tokenable);
    }

    /**
     * Every running session of this user, and nothing else of theirs: login
     * tokens and the WhatsApp device stay. Returns how many ended.
     */
    public function endAllFor(User $user): int
    {
        return DB::transaction(function () use ($user) {
            $tokens = $this->running()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->get();

            $ended = 0;

            foreach ($tokens as $token) {
                if ($this->finish($token, $user)) {
                    $ended++;
                }
            }

            return $ended;
        });
    }

    /**
     * Logout with an impersonation token. The auth guard already refused it if
     * it had expired.
     */
    public function endCurrent(PersonalAccessToken $token): void
    {
        $this->finish($token, $token->tokenable);
    }

    /**
     * @return Builder<PersonalAccessToken>
     */
    private function running(): Builder
    {
        return PersonalAccessToken::query()
            ->whereNotNull('impersonator_id')
            ->where('expires_at', '>', now());
    }

    /**
     * Records the event only if this call removed the row, so a session has at
     * most one end event, however many requests try to end it at once.
     */
    private function finish(PersonalAccessToken $token, User $subject): bool
    {
        return DB::transaction(function () use ($token, $subject) {
            if (PersonalAccessToken::query()->whereKey($token->getKey())->delete() === 0) {
                return false;
            }

            EventLogger::recordPlatform('platform.impersonation.ended', $subject, context: [
                'impersonated_user_id' => $subject->getKey(),
                'token_id' => $token->getKey(),
            ]);

            return true;
        });
    }
}
