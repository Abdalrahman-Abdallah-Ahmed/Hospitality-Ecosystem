<?php

namespace App\Console\Commands;

use App\Enums\KnowledgeRebuildScope;
use App\Models\Hotel;
use App\Services\Knowledge\KnowledgeRebuildService;
use Illuminate\Console\Command;

/**
 * The CLI way into an index rebuild. Same service, same tracking row, as the
 * super-admin endpoint, so a rebuild started from a shell shows up in the
 * admin area with its progress and failures.
 */
class SyncKnowledgeBaseCommand extends Command
{
    protected $signature = 'knowledge:sync
        {--hotel= : limit to one hotel}
        {--global : global knowledge only}
        {--documents : include knowledge documents (re-indexed from their stored text)}';

    protected $description = 'Queue a re-index of published articles and active hotel policies, and optionally knowledge documents';

    public function handle(KnowledgeRebuildService $rebuilds): int
    {
        if ($this->option('hotel') && $this->option('global')) {
            $this->error('Use --hotel or --global, not both.');

            return self::INVALID;
        }

        $hotel = null;
        $scope = KnowledgeRebuildScope::ALL;

        if ($hotelId = $this->option('hotel')) {
            $hotel = Hotel::find($hotelId);

            if ($hotel === null) {
                $this->error("Hotel {$hotelId} not found.");

                return self::FAILURE;
            }

            $scope = KnowledgeRebuildScope::HOTEL;
        } elseif ($this->option('global')) {
            $scope = KnowledgeRebuildScope::GLOBAL;
        }

        $rebuild = $rebuilds->start($scope, $hotel, null, includeDocuments: (bool) $this->option('documents'));

        if ($rebuild->total === 0) {
            $this->info('Nothing to sync.');

            return self::SUCCESS;
        }

        $this->info("Queued {$rebuild->total} knowledge source(s) for re-indexing (rebuild {$rebuild->id}).");

        return self::SUCCESS;
    }
}
