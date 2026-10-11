<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Documents stopped by the daily AI ceiling stored the exception message,
     * which names what the account spent and its ceiling — cost of goods —
     * in a field admins can read. Replace it with the neutral text the job
     * stores from now on (SPEC-091). Idempotent.
     *
     * Matched on the message as well as the failure code: a re-index clears
     * the code when it starts but leaves the old error in place, so a
     * document queued again after a ceiling stop still carries the amounts.
     */
    public function up(): void
    {
        DB::table('knowledge_documents')
            ->where(fn ($query) => $query
                ->where('failure_code', 'usage_limit_reached')
                ->orWhere('error', 'like', '%on AI today, at or past its daily ceiling%'))
            ->update(['error' => 'The daily AI usage limit was reached; try again tomorrow.']);
    }

    /**
     * The original messages are not restored: they should never have been
     * stored where an admin could read them.
     */
    public function down(): void
    {
        //
    }
};
