<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\ReservationImportRowsRequest;
use App\Http\Resources\ReservationImportResource;
use App\Http\Resources\ReservationImportRowResource;
use App\Models\ReservationImport;
use App\Support\Import\Reservations\ImportReportExport;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reservation import history, progress and reconciliation reports
 * (SPEC-100/101). Imports are started by ReservationController::import();
 * everything here only reads.
 */
class ReservationImportController extends Controller
{
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', ReservationImport::class);

        if (! $request->filled('sort')) {
            $request->merge(['sort' => '-created_at']);
        }

        $imports = GenericQuery::apply($this->withCounts(ReservationImport::query()), $request);

        return apiResponse('Reservation imports fetched successfully.', 200, ReservationImportResource::collection($imports));
    }

    public function show(ReservationImport $reservationImport)
    {
        $this->authorize('view', $reservationImport);

        $import = $this->withCounts(ReservationImport::query())->findOrFail($reservationImport->id);

        return apiResponse('Reservation import fetched successfully.', 200, new ReservationImportResource($import));
    }

    public function rows(ReservationImportRowsRequest $request, ReservationImport $reservationImport)
    {
        $this->authorize('view', $reservationImport);

        $rows = $reservationImport->rows()
            ->when($request->validated('outcome'), fn (Builder $query, string $outcome) => $query->where('outcome', $outcome))
            ->when($request->has('has_warnings'), fn (Builder $query) => $request->boolean('has_warnings')
                ? $query->whereRaw("warnings <> '[]'::jsonb")
                : $query->whereRaw("warnings = '[]'::jsonb"))
            ->orderBy('row_number')
            ->paginate($request->integer('per_page', 50));

        return apiResponse('Reservation import rows fetched successfully.', 200, ReservationImportRowResource::collection($rows));
    }

    /**
     * The non-imported rows and warnings as a spreadsheet (FR-028). A file,
     * so it is the one response here that is not an apiResponse() envelope.
     */
    public function report(ReservationImport $reservationImport)
    {
        $this->authorize('view', $reservationImport);

        if (! $reservationImport->isFinished()) {
            return apiResponse('The import has not finished yet.', 409);
        }

        return Excel::download(new ImportReportExport($reservationImport), "import-{$reservationImport->id}-report.xlsx");
    }

    private function withCounts(Builder $query): Builder
    {
        return $query->with('uploader')
            ->withCount(['rows as warned_rows_count' => fn (Builder $rows) => $rows->whereRaw("warnings <> '[]'::jsonb")]);
    }
}
