<?php

namespace App\Ai\Tools\Admin;

use App\Enums\Permission;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Wraps every Admin AI tool so the rules that keep the AI inside the acting
 * user's rights are enforced in one place, not remembered per tool (R2):
 *
 * - the permission the matching staff endpoint checks, re-read at call time
 *   so a role edited mid-conversation applies to the next call;
 * - the acting user's hotel as the tenant scope, so a tool that forgets a
 *   hotel filter still cannot reach another property;
 * - for writes, one transaction and an audit trail naming the AI, the admin
 *   it acted for, the tool and the conversation;
 * - for hard-to-reverse calls, a framework approval, so the call pauses
 *   before it runs until the admin confirms.
 *
 * The model sees the inner tool's own name, description and schema.
 */
class GuardedTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    /** @var array<string, ?string> The access check's answer per tool call id. */
    private array $refusals = [];

    public const READ = 'read';

    public const WRITE = 'write';

    /**
     * Exception to the four-argument limit: only AdminToolset builds these,
     * with named arguments, one per registry field. Revisit if a second
     * place starts constructing guarded tools: give the entry its own class.
     *
     * @param  list<Permission>  $permissions  all required
     * @param  list<class-string>  $writes  the models a write changes
     */
    public function __construct(
        private readonly Tool $inner,
        private readonly Hotel $hotel,
        private readonly User $user,
        private readonly string $kind,
        private readonly array $permissions = [],
        private readonly bool $adminOnly = false,
        private readonly bool $selfChecked = false,
        private readonly array $writes = [],
        private readonly ?string $conversationId = null,
        private readonly string $locale = 'en',
    ) {}

    public function name(): string
    {
        return class_basename($this->inner);
    }

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        return TenantContext::runForHotel($this->hotel->id, function () use ($request) {
            if ($refusal = $this->refusal($request)) {
                return $refusal;
            }

            if ($this->kind === self::READ) {
                return $this->inner->handle($request);
            }

            return EventLogger::asAiAgent(
                fn () => DB::transaction(fn () => $this->inner->handle($request)),
                onBehalfOf: $this->user,
                ai: [
                    'agent' => 'admin_advisor',
                    'tool' => $this->name(),
                    'tool_call_id' => $request->toolCallId(),
                    'conversation_id' => $this->conversationId,
                ],
            );
        });
    }

    /**
     * Ask the admin first only when the call would actually run: a call the
     * user has no permission for fails straight away instead.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        if (! $this->inner instanceof ConfirmsBeforeRunning) {
            return false;
        }

        return TenantContext::runForHotel($this->hotel->id, function () use ($request) {
            if ($this->refusal($request) !== null || ! $this->inner->needsConfirmation($request)) {
                return false;
            }

            return Approval::required($this->inner->confirmationSummary($request, $this->locale));
        });
    }

    /**
     * Why the acting user may not use this tool, or null when they may.
     *
     * Read fresh from the database, so a role changed mid-conversation
     * applies to the next call. Within one call (its approval check, then
     * its run) the answer is reused rather than queried again.
     */
    private function refusal(Request $request): ?string
    {
        if ($this->selfChecked) {
            return null;
        }

        $callId = $request->toolCallId();

        if ($callId !== null && array_key_exists($callId, $this->refusals)) {
            return $this->refusals[$callId];
        }

        $refusal = $this->checkAccess();

        if ($callId !== null) {
            $this->refusals[$callId] = $refusal;
        }

        return $refusal;
    }

    private function checkAccess(): ?string
    {
        $user = User::with('staffRole')->find($this->user->id);

        $allowed = $user !== null && ($this->adminOnly
            ? $user->isAdmin() || $user->isSuperAdmin()
            : collect($this->permissions)->every(fn (Permission $permission) => $user->hasPermission($permission)));

        if ($allowed) {
            return null;
        }

        $needs = $this->adminOnly
            ? 'an administrator'
            : implode(', ', array_map(fn (Permission $permission) => $permission->value, $this->permissions));

        return "You do not have permission to use {$this->name()} (requires {$needs}). Nothing was changed.";
    }

    public function inner(): Tool
    {
        return $this->inner;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function adminOnly(): bool
    {
        return $this->adminOnly;
    }

    public function selfChecked(): bool
    {
        return $this->selfChecked;
    }

    /**
     * @return list<class-string>
     */
    public function writes(): array
    {
        return $this->writes;
    }
}
