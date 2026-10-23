<?php

namespace App\Http\Controllers;

use App\Enums\ImportDateOrder;
use App\Enums\ImportDecimalMark;
use App\Enums\ImportStatus;
use App\Http\Requests\ConfirmReservationImportPreviewRequest;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\ShowReservationImportPreviewRequest;
use App\Http\Requests\StoreReservationImportPreviewRequest;
use App\Http\Resources\ReservationImportPreviewResource;
use App\Http\Resources\ReservationImportResource;
use App\Jobs\PrepareReservationImportJob;
use App\Models\ReservationImport;
use App\Models\ReservationImportMapping;
use App\Models\ReservationImportPreview;
use App\Support\Import\Reservations\ImportColumnSuggester;
use App\Support\Import\Reservations\ImportFailed;
use App\Support\Import\Reservations\ImportFileReader;
use App\Support\Import\Reservations\ImportPreviewBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Column mapping for reservation imports (016): upload a file once, map its
 * columns to the import's fields, and confirm to queue a normal import
 * (SPEC-100), as often as needed until the upload expires.
 */
class ReservationImportPreviewController extends Controller
{
    public function __construct(
        private ImportPreviewBuilder $builder,
        private ImportColumnSuggester $suggester,
    ) {}

    public function store(StoreReservationImportPreviewRequest $request, ImportFileReader $reader): JsonResponse
    {
        $this->authorize('create', ReservationImportPreview::class);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            throw ValidationException::withMessages(['hotel_id' => 'Name a valid hotel to import into.']);
        }

        // Read before storing, so an unreadable file leaves nothing behind.
        $file = $request->file('file');
        try {
            $head = $reader->head($file->getRealPath());
        } catch (ImportFailed $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $id = (string) Str::uuid7();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'csv');
        $path = $file->storeAs("reservation-import-previews/{$hotel->id}", "{$id}.{$extension}", config('reservation_import.disk'));

        $preview = (new ReservationImportPreview)->forceFill(['id' => $id])->fill([
            'hotel_id' => $hotel->id,
            'uploaded_by' => $request->user()->id,
            'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'file_path' => $path,
            'head_rows' => $head['rows'],
            'last_row' => $head['last_row'],
            'expires_at' => now()->addHours((int) config('reservation_import.preview_ttl_hours', 24)),
        ]);
        $preview->save();

        return apiResponse('Upload ready to map.', 201, $this->screen($preview->load('uploader', 'imports')));
    }

    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', ReservationImportPreview::class);

        $previews = ReservationImportPreview::query()
            ->unexpired()
            ->with(['uploader', 'imports'])
            ->latest()
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return apiResponse('Uploads waiting to be mapped fetched successfully.', 200, ReservationImportPreviewResource::collection($previews));
    }

    public function show(ShowReservationImportPreviewRequest $request, ReservationImportPreview $reservationImportPreview): JsonResponse
    {
        $this->authorize('view', $reservationImportPreview);

        $headerRow = $request->validated('header_row');

        return apiResponse('Upload fetched successfully.', 200, $this->screen($reservationImportPreview->load('uploader', 'imports'), $headerRow === null ? null : (int) $headerRow));
    }

    public function confirm(ConfirmReservationImportPreviewRequest $request, ReservationImportPreview $reservationImportPreview): JsonResponse
    {
        $preview = $reservationImportPreview;
        $this->authorize('view', $preview);

        $disk = Storage::disk(config('reservation_import.disk'));
        $id = (string) Str::uuid7();
        $path = "reservation-imports/{$preview->hotel_id}/{$id}.".pathinfo($preview->file_path, PATHINFO_EXTENSION);

        $import = DB::transaction(function () use ($request, $preview, $disk, $id, $path) {
            $import = (new ReservationImport)->forceFill(['id' => $id])->fill([
                'hotel_id' => $preview->hotel_id,
                'uploaded_by' => $request->user()->id,
                'reservation_import_preview_id' => $preview->id,
                'file_name' => $preview->file_name,
                'file_path' => $path,
                'dry_run' => $request->boolean('dry_run'),
                'date_order' => $request->validated('date_order') ?? ImportDateOrder::DAY_FIRST->value,
                'header_row' => (int) $request->validated('header_row'),
                'column_map' => $request->columnMap(),
                'decimal_mark' => $request->validated('decimal_mark') ?? ImportDecimalMark::AUTO->value,
                'status' => ImportStatus::QUEUED,
            ]);
            $import->save();

            // Each import owns a copy: its job deletes it when prepare ends,
            // while the preview stays confirmable (research R2).
            $disk->copy($preview->file_path, $path);

            $this->remember($preview, $import->header_row, $import->column_map, $request->user()->id);

            return $import;
        });

        PrepareReservationImportJob::dispatch($import->id)->afterCommit();

        return apiResponse('Reservation import queued.', 202, new ReservationImportResource($import->refresh()->load('uploader')->setAttribute('warned_rows_count', 0)));
    }

    public function destroy(ReservationImportPreview $reservationImportPreview): JsonResponse
    {
        $this->authorize('delete', $reservationImportPreview);

        Storage::disk(config('reservation_import.disk'))->delete($reservationImportPreview->file_path);
        $reservationImportPreview->delete();

        return apiResponse('Upload discarded.', 200);
    }

    /**
     * The preview with its mapping screen built for a header row, or for
     * the suggested one.
     */
    private function screen(ReservationImportPreview $preview, ?int $headerRow = null): ReservationImportPreviewResource
    {
        $saved = ReservationImportMapping::where('hotel_id', $preview->hotel_id)->first();
        $suggested = $this->suggester->suggestedHeaderRow($saved, $preview->last_row);

        return (new ReservationImportPreviewResource($preview))->withScreen($this->builder->build($preview, $headerRow ?? $suggested, $saved), $suggested);
    }

    /**
     * The confirmed mapping becomes the hotel's suggestion for its next
     * file (FR-014): by header text, so the next export may order its
     * columns differently, and with the columns left unmapped on purpose.
     *
     * @param  array<int, ?string>  $columnMap
     */
    private function remember(ReservationImportPreview $preview, int $headerRow, array $columnMap, string $userId): void
    {
        $header = $preview->head_rows[$headerRow - 1] ?? [];
        $columns = [];
        foreach ($columnMap as $position => $field) {
            $key = ImportFileReader::normalizeHeader($header[$position] ?? '');
            if ($key !== '' && ! array_key_exists($key, $columns)) {
                $columns[$key] = $field;
            }
        }

        ReservationImportMapping::updateOrCreate(
            ['hotel_id' => $preview->hotel_id],
            ['header_row' => $headerRow, 'columns' => $columns, 'updated_by' => $userId],
        );
    }
}
