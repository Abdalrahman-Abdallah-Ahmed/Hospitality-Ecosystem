<?php

namespace App\Http\Controllers;

use App\Enums\VoucherField;
use App\Enums\VoucherLanguage;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Vouchers\PreviewVoucherTemplateRequest;
use App\Http\Requests\Vouchers\StoreVoucherTemplateRequest;
use App\Http\Requests\Vouchers\UpdateVoucherTemplateRequest;
use App\Http\Requests\Vouchers\VoucherLayoutRequest;
use App\Http\Requests\Vouchers\VoucherTemplateImageRequest;
use App\Http\Resources\VoucherTemplateResource;
use App\Models\Reservation;
use App\Models\VoucherTemplate;
use App\Services\Vouchers\ReservationVoucherEligibility;
use App\Services\Vouchers\VoucherDataBuilder;
use App\Services\Vouchers\VoucherRenderer;
use App\Services\Vouchers\VoucherTemplateService;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Voucher templates (SPEC-013): a hotel's voucher designs, their layouts of
 * field boxes, and previews. Images are private and served only here.
 */
class VoucherTemplateController extends Controller
{
    public function __construct(private VoucherTemplateService $templates) {}

    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', VoucherTemplate::class);

        $templates = GenericQuery::apply(VoucherTemplate::query(), $request);

        return apiResponse('Voucher templates retrieved successfully', 200, VoucherTemplateResource::collection($templates));
    }

    /**
     * The fields an admin can place, for the layout editor (FR-018).
     */
    public function fields(): JsonResponse
    {
        $this->authorize('viewAny', VoucherTemplate::class);

        $fields = array_map(fn (VoucherField $field) => [
            'key' => $field->value,
            'kind' => $field->kind(),
            'label' => [
                VoucherLanguage::EN->value => $field->label(VoucherLanguage::EN),
                VoucherLanguage::AR->value => $field->label(VoucherLanguage::AR),
            ],
        ], VoucherField::cases());

        return apiResponse('Voucher fields retrieved successfully', 200, $fields);
    }

    public function store(StoreVoucherTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', VoucherTemplate::class);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $template = $this->templates->create(
            $hotel,
            unsetAttributes($request->safe()->only(['name', 'language', 'is_default']), ['hotel_id']),
            $request->file('image'),
            $request->user(),
        );

        return apiResponse('Voucher template created successfully', 201, VoucherTemplateResource::make($template));
    }

    public function show(VoucherTemplate $voucherTemplate): JsonResponse
    {
        $this->authorize('view', $voucherTemplate);

        return apiResponse('Voucher template retrieved successfully', 200, VoucherTemplateResource::make($voucherTemplate));
    }

    public function update(UpdateVoucherTemplateRequest $request, VoucherTemplate $voucherTemplate): JsonResponse
    {
        $this->authorize('update', $voucherTemplate);

        $template = $this->templates->update($voucherTemplate, $request->validated());

        return apiResponse('Voucher template updated successfully', 200, VoucherTemplateResource::make($template));
    }

    public function layout(VoucherLayoutRequest $request, VoucherTemplate $voucherTemplate): JsonResponse
    {
        $this->authorize('update', $voucherTemplate);

        $template = $this->templates->saveLayout($voucherTemplate, $request->layout());

        return apiResponse('Voucher template layout saved successfully', 200, VoucherTemplateResource::make($template));
    }

    public function replaceImage(VoucherTemplateImageRequest $request, VoucherTemplate $voucherTemplate): JsonResponse
    {
        $this->authorize('update', $voucherTemplate);

        $template = $this->templates->replaceImage($voucherTemplate, $request->file('image'));

        return apiResponse('Voucher template image replaced successfully', 200, VoucherTemplateResource::make($template));
    }

    /**
     * The original upload, for the layout editor. There is no public URL
     * (FR-012).
     */
    public function image(VoucherTemplate $voucherTemplate): StreamedResponse
    {
        $this->authorize('view', $voucherTemplate);

        return Storage::disk($voucherTemplate->disk)->response(
            $voucherTemplate->original_path,
            null,
            ['Content-Type' => $voucherTemplate->mime_type],
        );
    }

    /**
     * Soft delete; the files stay so a queued email can still render.
     */
    public function destroy(VoucherTemplate $voucherTemplate): JsonResponse
    {
        $this->authorize('delete', $voucherTemplate);

        $voucherTemplate->delete();

        return apiResponse('Voucher template deleted successfully', 200, []);
    }

    /**
     * The template filled with sample data or a reservation, with an
     * optional unsaved layout (FR-025). Stores, sends and audits nothing, so
     * the PDF is stamped "PREVIEW" and a reservation that cannot get a
     * voucher cannot be previewed either: a preview never stands in for one.
     */
    public function preview(
        PreviewVoucherTemplateRequest $request,
        VoucherTemplate $voucherTemplate,
        VoucherDataBuilder $builder,
        VoucherRenderer $renderer,
        ReservationVoucherEligibility $eligibility,
    ): Response|JsonResponse {
        $this->authorize('view', $voucherTemplate);

        $showTotal = $request->boolean('show_total');
        $note = $request->validated('staff_note');
        $overrides = $request->validated('overrides') ?? [];

        if ($reservationId = $request->validated('reservation_id')) {
            $reservation = Reservation::query()->where('hotel_id', $voucherTemplate->hotel_id)->findOrFail($reservationId);
            $this->authorize('voucher', $reservation);

            if (! $eligibility->isEligible($reservation)) {
                return apiResponse(__('voucher.errors.ineligible'), 409);
            }

            $data = $builder->fromReservation($reservation, $voucherTemplate->language, $showTotal, $note, $overrides);
        } else {
            $data = $builder->sample($voucherTemplate->language, $showTotal, $note, $overrides);
        }

        $result = $renderer->renderTemplate($data, $voucherTemplate, $request->layout(), preview: true);

        return response($result->pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="voucher-preview.pdf"',
        ]);
    }
}
