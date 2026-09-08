<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventLogResource;
use App\Models\AiInsights;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    /**
     * The record types whose audit trail can be read, keyed by the URL slug.
     */
    private const SUBJECTS = [
        'reservation' => Reservation::class,
        'transaction' => Transaction::class,
        'stay' => Stay::class,
        'guest' => Guest::class,
        'recommendation' => Recommendation::class,
        'task' => Task::class,
        'ai-insights' => AiInsights::class,
    ];

    /**
     * Read the change history of one record: GET /api/history/{type}/{id}.
     */
    public function show(Request $request, string $type, string $id)
    {
        $this->authorize('viewAny', EventLog::class);

        $modelClass = self::SUBJECTS[$type] ?? abort(404, 'Unknown record type.');

        // Resolve the subject through its own tenant scope (so another hotel's
        // id 404s) but include soft-deleted rows — the history must outlive
        // the record it describes.
        $query = $modelClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        abort_if($query->find($id) === null, 404, 'Record not found.');

        $events = EventLog::query()
            ->where('subject_type', (new $modelClass)->getMorphClass())
            ->where('subject_id', $id)
            ->with('actor')
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 15));

        return apiResponse('Record history fetched successfully.', 200, EventLogResource::collection($events));
    }
}
