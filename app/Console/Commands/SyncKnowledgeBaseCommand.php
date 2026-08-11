<?php

namespace App\Console\Commands;

use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use Illuminate\Console\Command;

class SyncKnowledgeBaseCommand extends Command
{
    protected $signature = 'knowledge:sync';

    protected $description = 'Queue an embedding sync for every published article and active hotel policy';

    public function handle(): int
    {
        $chunkables = KnowledgeBaseArticle::where('status', 'published')->get()
            ->concat(HotelPolicy::where('is_active', true)->get());

        if ($chunkables->isEmpty()) {
            $this->info('Nothing to sync.');

            return self::SUCCESS;
        }

        $this->withProgressBar($chunkables, fn ($chunkable) => SyncKnowledgeChunksJob::dispatch($chunkable));

        $this->newLine(2);
        $this->info("Queued {$chunkables->count()} knowledge base record(s) for embedding sync.");

        return self::SUCCESS;
    }
}
