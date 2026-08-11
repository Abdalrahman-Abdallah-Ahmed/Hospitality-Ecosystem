<?php

namespace App\Observers;

use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\HotelPolicy;

class HotelPolicyObserver
{
    /**
     * Handle the HotelPolicy "saved" event.
     */
    public function saved(HotelPolicy $policy): void
    {
        if (! $policy->is_active) {
            $policy->chunks()->delete();

            return;
        }

        // isDirty(), not wasChanged(): wasChanged() is stale on a no-op save (Eloquent only
        // refreshes it when an UPDATE actually runs), which would wrongly look like a change.
        if (! $policy->isDirty(['content', 'title', 'category', 'keywords', 'is_active'])) {
            return;
        }

        SyncKnowledgeChunksJob::dispatch($policy);
    }

    /**
     * Handle the HotelPolicy "deleted" event.
     */
    public function deleted(HotelPolicy $policy): void
    {
        $policy->chunks()->delete();
    }
}
