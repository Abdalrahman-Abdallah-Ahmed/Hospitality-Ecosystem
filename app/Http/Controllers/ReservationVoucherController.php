<?php

namespace App\Http\Controllers;

use App\Enums\VoucherEmailStatus;
use App\Http\Requests\Vouchers\EmailVoucherRequest;
use App\Http\Requests\Vouchers\VoucherEmailRequestIndexRequest;
use App\Http\Requests\Vouchers\VoucherOptionsRequest;
use App\Http\Resources\VoucherEmailRequestResource;
use App\Jobs\SendReservationVoucherEmailJob;
use App\Models\Reservation;
use App\Models\VoucherEmailRequest;
use App\Services\Vouchers\ReservationVoucherEligibility;
use App\Services\Vouchers\VoucherDataBuilder;
use App\Services\Vouchers\VoucherRenderer;
use App\Services\Vouchers\VoucherTemplateResolver;
use App\Support\Audit\EventLogger;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The reservation voucher (SPEC-013): a PDF proof of reservation rendered on
 * the fly, never stored, and emailed to the guest only when staff ask.
 */
class ReservationVoucherController extends Controller
{
    public function __construct(
        private ReservationVoucherEligibility $eligibility,
        private VoucherTemplateResolver $resolver,
        private VoucherDataBuilder $builder,
        private VoucherRenderer $renderer,
    ) {}

    public function generate(VoucherOptionsRequest $request, Reservation $reservation): Response|JsonResponse
    {
        $this->authorize('voucher', $reservation);

        if (! $this->eligibility->isEligible($reservation)) {
            return apiResponse(__('voucher.errors.ineligible'), 409);
        }

        $options = $request->options();
        [$template, $language] = $this->resolver->resolve($reservation, $options['template_id']);

        $data = $this->builder->fromReservation($reservation, $language, $options['show_total'], $options['staff_note'], $options['overrides']);
        $result = $template ? $this->renderer->renderTemplate($data, $template) : $this->renderer->renderDefault($data);

        EventLogger::record($reservation, 'voucher_generated', $this->auditedOptions($options), context: [
            'voucher_template_id' => $template?->id,
            'language' => $language->value,
            'show_total' => $options['show_total'],
        ]);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="voucher-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $data->reference).'.pdf"',
        ];

        if ($result->unplaced !== []) {
            $headers['X-Voucher-Unplaced'] = implode(',', $result->unplaced);
        }

        return response($result->pdf, 200, $headers);
    }

    public function email(EmailVoucherRequest $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('voucherEmail', $reservation);

        if (! $this->eligibility->isEligible($reservation)) {
            return apiResponse(__('voucher.errors.ineligible'), 409);
        }

        $options = $request->options();
        // Fixed now, so the email keeps the choices made at request time.
        [$template, $language] = $this->resolver->resolve($reservation, $options['template_id']);

        // The guest the voucher is addressed to, not necessarily the booker.
        $recipient = $request->validated('email') ?: $reservation->primaryGuest()?->email;

        if (! $recipient) {
            throw ValidationException::withMessages(['email' => [__('voucher.errors.no_recipient')]]);
        }

        $key = (string) $request->validated('request_key');

        [$emailRequest, $created, $limit] = DB::transaction(function () use ($reservation, $key, $recipient, $template, $language, $options, $request) {
            // Serializes requests for one reservation so the daily cap holds.
            Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            $existing = $this->findByKey($reservation, $key);
            if ($existing) {
                return [$existing, false, null];
            }

            $counted = fn () => VoucherEmailRequest::whereNotIn('status', [VoucherEmailStatus::SKIPPED->value, VoucherEmailStatus::FAILED->value]);

            if ($counted()->where('reservation_id', $reservation->id)->where('created_at', '>', now()->subDay())->count()
                >= (int) config('vouchers.email_daily_cap')) {
                return [null, false, 'voucher.errors.daily_cap'];
            }

            // Per staff user across reservations, so the per-reservation cap
            // cannot be multiplied by looping over reservations.
            if ($counted()->where('requested_by', $request->user()?->id)->where('created_at', '>', now()->subHour())->count()
                >= (int) config('vouchers.email_user_hourly_cap')) {
                return [null, false, 'voucher.errors.user_hourly_cap'];
            }

            try {
                // In a savepoint: on Postgres a failed INSERT aborts the
                // enclosing transaction, and the lookup below must still run.
                $emailRequest = DB::transaction(fn () => VoucherEmailRequest::create([
                    'reservation_id' => $reservation->id,
                    'request_key' => $key,
                    'recipient' => $recipient,
                    'voucher_template_id' => $template?->id,
                    'language' => $language,
                    'show_total' => $options['show_total'],
                    'overrides' => $options['overrides'] ?: null,
                    'staff_note' => $options['staff_note'],
                    'requested_by' => $request->user()?->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                return [$this->findByKey($reservation, $key), false, null];
            }

            EventLogger::record($reservation, 'voucher_email_requested', $this->auditedOptions($options), context: [
                'voucher_email_request_id' => $emailRequest->id,
                'recipient' => $recipient,
                'voucher_template_id' => $template?->id,
                'language' => $language->value,
                'show_total' => $options['show_total'],
            ]);

            SendReservationVoucherEmailJob::dispatch($emailRequest->id)->afterCommit();

            return [$emailRequest, true, null];
        });

        // No Retry-After on purpose: the frontend reads a 429 with that header as
        // the global throttle:api limit and pauses every call.
        if ($limit) {
            return apiResponse(__($limit), 429);
        }

        return $created
            ? apiResponse('Voucher email queued', 202, VoucherEmailRequestResource::make($emailRequest->refresh()))
            : apiResponse('Voucher email already requested with this key', 200, VoucherEmailRequestResource::make($emailRequest));
    }

    public function emails(VoucherEmailRequestIndexRequest $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('voucherEmail', $reservation);

        $query = VoucherEmailRequest::where('reservation_id', $reservation->id)
            // Newest first unless the caller sorts.
            ->when(! $request->filled('sort'), fn ($query) => $query->latest()->orderByDesc('id'));

        $emails = GenericQuery::apply($query, $request);

        return apiResponse('Voucher emails retrieved successfully', 200, VoucherEmailRequestResource::collection($emails));
    }

    private function findByKey(Reservation $reservation, string $key): ?VoucherEmailRequest
    {
        return VoucherEmailRequest::where('reservation_id', $reservation->id)->where('request_key', $key)->first();
    }

    /**
     * What a voucher claimed beyond the stored reservation: the audit entry is
     * the control on overrides (spec clarification, research R11).
     *
     * @param  array{overrides: array<string, mixed>, staff_note: ?string}  $options
     * @return array<string, mixed>|null
     */
    private function auditedOptions(array $options): ?array
    {
        return array_filter([
            'overrides' => $options['overrides'] ?: null,
            'staff_note' => $options['staff_note'],
        ], fn ($value) => $value !== null) ?: null;
    }
}
