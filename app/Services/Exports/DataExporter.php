<?php

namespace App\Services\Exports;

use App\Enums\ExportFormat;
use App\Models\Hotel;
use App\Support\Audit\EventLogger;
use App\Support\Export\ModuleExport;
use App\Support\Export\SqlDump;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Maatwebsite\Excel\DefaultValueBinder;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns one hotel's filtered list into a downloadable file (spec 021): checks
 * the row cap, records the export in the audit log, names the file and hands
 * it to the spreadsheet or SQL writer. Shared by the four list exports.
 */
class DataExporter
{
    /**
     * Columns with no meaning outside the system, left out of SQL (FR-013):
     * the AI/recommendation processing context on bookings, and the guest
     * identity-matching hash and when it was resolved.
     *
     * @var array<string, list<string>>
     */
    private const SQL_EXCLUDED = [
        'bookings' => ['context'],
        'guests' => ['identity_hash', 'identity_resolved_at'],
    ];

    /**
     * @param  array<string, mixed>  $filters  the list inputs the export used, for the audit entry
     * @param  Closure(Builder, ExportFormat): ModuleExport  $spreadsheet
     */
    public function download(
        string $module,
        Builder $query,
        ExportFormat $format,
        Hotel $hotel,
        array $filters,
        Closure $spreadsheet,
    ): Response|JsonResponse {
        $matching = (clone $query)->toBase()->getCountForPagination();
        $cap = (int) config('exports.max_rows');

        // Refused, never truncated (FR-016), and recorded so attempts to pull
        // large amounts of data are visible (FR-023).
        if ($matching > $cap) {
            EventLogger::record($hotel, "{$module}_export_refused", changes: [
                'format' => $format->value,
                ...$filters,
                'matching' => $matching,
                'max_rows' => $cap,
            ]);

            return apiResponse(
                "This export matches {$matching} records; the limit is {$cap}. Narrow the filters and try again.",
                422,
                ['matching' => $matching, 'max_rows' => $cap],
            );
        }

        // Written as the download starts: it records an export that was
        // started, with the records matching at that moment. CSV and SQL are
        // read while streaming, so a record written in between can make the
        // file differ by that record; the SQL file states what it holds.
        EventLogger::record($hotel, "{$module}_exported", changes: [
            'format' => $format->value,
            ...$filters,
            'row_count' => $matching,
        ]);

        $filename = $module.'-'.Str::slug($hotel->name).'-'.now()->format('Ymd-Hi').'.'.$format->extension();

        if ($format === ExportFormat::SQL) {
            return (new SqlDump($query, $module, $hotel, $matching, $filters, self::SQL_EXCLUDED[$module] ?? []))
                ->stream($filename);
        }

        $export = $spreadsheet($query, $format)->inTimezone($hotel->timezone);

        // CSV streams a chunk at a time, so memory stays flat. The body is
        // written after the tenant middleware has cleared the hotel context,
        // so the hotel is set again for the read.
        if ($format === ExportFormat::CSV) {
            return response()->streamDownload(
                fn () => TenantContext::runForHotel($hotel->id, fn () => $export->streamCsv()),
                $filename,
                ['Content-Type' => $format->contentType()],
            );
        }

        try {
            return Excel::download($export, $filename, ExcelWriter::XLSX, ['Content-Type' => $format->contentType()]);
        } finally {
            // The export installs itself as PhpSpreadsheet's process-wide value
            // binder and nothing puts the default back; left in place, it
            // would store every value read later in this process (an import,
            // say) as text.
            Cell::setValueBinder(new DefaultValueBinder);
        }
    }
}
