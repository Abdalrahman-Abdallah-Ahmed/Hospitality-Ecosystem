<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use App\Services\Reports\ConversionReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * Did the recommendations work? GET /api/analytics/conversion.
     *
     * Acceptance, booking, realisation and settlement rates for the period,
     * with the fulfilment gap and the caveats that keep them honest. The
     * figures live in ConversionReport, shared with the Admin AI.
     */
    public function conversion(Request $request, ConversionReport $report): JsonResponse
    {
        $this->authorize('viewAny', Recommendation::class);

        [$from, $to] = $report->period(
            $request->filled('from') ? $request->string('from')->toString() : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        );

        return apiResponse('Conversion analytics fetched successfully.', 200, $report->for($from, $to));
    }
}
