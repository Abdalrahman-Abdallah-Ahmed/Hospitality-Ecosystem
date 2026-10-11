<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventLogResource;
use App\Models\EventLog;
use App\Support\Audit\HistorySubjects;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    /**
     * Read the change history of one record: GET /api/history/{type}/{id}.
     * The record types are listed in HistorySubjects.
     */
    public function show(Request $request, string $type, string $id)
    {
        $this->authorize('viewAny', EventLog::class);

        abort_unless(HistorySubjects::hasHistory($type), 404, 'Unknown record type.');

        $modelClass = HistorySubjects::classFor($type);
        $morphType = (new $modelClass)->getMorphClass();

        // Resolve the subject through its own tenant scope (so another hotel's
        // id 404s) but include soft-deleted rows — the history must outlive
        // the record it describes.
        $query = $modelClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        // A record deleted for good (a guest memory) has no row left to
        // resolve; its history is still the hotel's if the tenant-scoped log
        // holds entries for it (SPEC-083 FR-034).
        $exists = $query->find($id) !== null
            || EventLog::query()->where('subject_type', $morphType)->where('subject_id', $id)->exists();

        abort_unless($exists, 404, 'Record not found.');

        $events = EventLog::query()
            ->where('subject_type', $morphType)
            ->where('subject_id', $id)
            ->with('actor')
            ->orderByDesc('occurred_at')
            // Two events in the same instant keep the order they were written
            // in: ids are UUIDv7, ordered by creation time.
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return apiResponse('Record history fetched successfully.', 200, EventLogResource::collection($events));
    }
}
