<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ExportFormat;
use App\Services\Exports\DataExporter;
use App\Support\Export\ModuleExport;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * The export action the reservation, activity, booking and guest lists share
 * (spec 021): the module's export permission, one hotel (a super admin names
 * it), then the file. The query is the list's own, so the file holds exactly
 * what the list shows.
 */
trait ExportsLists
{
    /**
     * The list inputs recorded in the audit entry. Never record content.
     */
    private const EXPORT_AUDITED_INPUTS = [
        'search', 'sort', 'filter',
        'activity_id', 'reservation_id', 'scheduled_from', 'scheduled_to', 'cancellation_requested',
    ];

    /**
     * @param  class-string  $modelClass
     * @param  FormRequest  $request  an export request (uses ValidatesExportFormat)
     * @param  Closure(Builder, ExportFormat): ModuleExport  $spreadsheet
     */
    private function exportList(string $module, string $modelClass, Builder $query, FormRequest $request, Closure $spreadsheet): Response|JsonResponse
    {
        $this->authorize('export', $modelClass);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        // The export class decides what it eager loads.
        $query->setEagerLoads([])->where($query->getModel()->qualifyColumn('hotel_id'), $hotel->id);

        return app(DataExporter::class)->download(
            $module,
            $query,
            $request->exportFormat(),
            $hotel,
            Arr::only($request->validated(), self::EXPORT_AUDITED_INPUTS),
            $spreadsheet,
        );
    }
}
