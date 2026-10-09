<?php

namespace App\Ai\Tools\Admin;

use Laravel\Ai\Tools\Request;

/**
 * A tool whose call the admin must confirm before it runs (FR-017): the
 * hard-to-reverse actions. GuardedTool turns this into a framework approval,
 * so the call pauses before handle() and runs only on the admin's next
 * message, never on the model's say-so.
 */
interface ConfirmsBeforeRunning
{
    /**
     * Whether this particular call needs confirmation (a booking status
     * change does only when it cancels).
     */
    public function needsConfirmation(Request $request): bool;

    /**
     * What will happen, read from the stored records rather than the model's
     * wording, so the admin confirms the real target. $locale is `en` or `ar`.
     */
    public function confirmationSummary(Request $request, string $locale): string;
}
