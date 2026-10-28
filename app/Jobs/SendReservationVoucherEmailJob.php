<?php

namespace App\Jobs;

use App\Enums\QueueLane;
use App\Enums\VoucherEmailStatus;
use App\Mail\ReservationVoucherMail;
use App\Models\Reservation;
use App\Models\VoucherEmailRequest;
use App\Models\VoucherTemplate;
use App\Services\Vouchers\ReservationVoucherEligibility;
use App\Services\Vouchers\VoucherDataBuilder;
use App\Services\Vouchers\VoucherMailSender;
use App\Services\Vouchers\VoucherRenderer;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Emails a reservation voucher a staff member asked for (SPEC-013, research
 * R9/R10). Only ever dispatched by the voucher email endpoint (FR-031).
 *
 * - Claims the request (queued → sending) before doing anything, so two
 *   workers never both send. A claim left by a killed worker is taken over
 *   after a few minutes.
 * - Renders at send time from the options snapshot on the request: its
 *   template (even if since deleted), language, total choice, overrides and
 *   note, with the reservation's current data.
 * - Skips the send if the reservation was cancelled, marked no-show or
 *   deleted in the meantime (FR-029).
 * - Any failure after the claim hands it back for the retry; an attempt
 *   that finds another attempt's recent claim comes back once it is stale.
 * - With Resend, sends with the idempotency key `voucher-email:{id}`, fixed
 *   per request, so a retry after an uncertain send is dropped by the
 *   provider. With any other mailer it sends at most once: a retry after
 *   the mail reached the mailer ends `failed` (`uncertain_delivery`) and
 *   staff resend with a new key.
 */
class SendReservationVoucherEmailJob implements ShouldQueue
{
    use Queueable;

    /** Rendering plus one provider call fits well inside this, under the queue's retry_after (360 s). */
    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public string $emailRequestId)
    {
        // Staff are not waiting on it in real time and no guest is waiting on a reply.
        $this->onQueue(QueueLane::DEFAULT->value);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(
        VoucherDataBuilder $builder,
        VoucherRenderer $renderer,
        VoucherMailSender $sender,
        ReservationVoucherEligibility $eligibility,
    ): void {
        $stale = now()->subMinutes((int) config('vouchers.email_claim_stale_minutes'));

        $claimed = VoucherEmailRequest::withoutGlobalScope('hotel')
            ->whereKey($this->emailRequestId)
            ->where(fn ($query) => $query->where('status', VoucherEmailStatus::QUEUED->value)
                // A claim abandoned by a dead worker.
                ->orWhere(fn ($query) => $query
                    ->where('status', VoucherEmailStatus::SENDING->value)
                    ->where('claimed_at', '<', $stale)))
            ->update(['status' => VoucherEmailStatus::SENDING->value, 'claimed_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            $this->waitForLiveClaim();

            return;
        }

        try {
            // The only unscoped read: the hotel is not known until the request is.
            $request = VoucherEmailRequest::withoutGlobalScope('hotel')->findOrFail($this->emailRequestId);

            TenantContext::runForHotel($request->hotel_id, function () use ($request, $builder, $renderer, $sender, $eligibility): void {
                // A reservation deleted since the request is no longer eligible.
                $reservation = Reservation::find($request->reservation_id);
                $reason = $reservation ? $eligibility->reasonFor($reservation) : 'reservation_deleted';

                if ($reason) {
                    $this->finish($request, VoucherEmailStatus::SKIPPED, $reason);

                    return;
                }

                $template = $request->voucher_template_id
                    ? VoucherTemplate::withTrashed()->find($request->voucher_template_id)
                    : null;

                // A mailer that cannot drop duplicates sends at most once: an
                // earlier attempt that reached the mailer may have delivered.
                if (! $sender->isIdempotent() && $request->send_started_at !== null) {
                    $this->finish($request, VoucherEmailStatus::FAILED, 'uncertain_delivery');

                    return;
                }

                $data = $builder->fromReservation($reservation, $request->language, $request->show_total, $request->staff_note, $request->overrides ?? []);
                $result = $template ? $renderer->renderTemplate($data, $template) : $renderer->renderDefault($data);

                if (! $sender->isIdempotent()) {
                    $request->forceFill(['send_started_at' => now()])->save();
                }

                $sender->send(
                    new ReservationVoucherMail($result->pdf, $data->reference, $request->language, (string) $reservation->hotel?->name),
                    $request->recipient,
                    'voucher-email:'.$request->id,
                );

                $request->forceFill(['unplaced' => $result->unplaced ?: null, 'sent_at' => now()]);
                $this->finish($request, VoucherEmailStatus::SENT);
            });
        } catch (Throwable $e) {
            // Whatever failed after the claim, even recording a send that went
            // out, hand the claim back so the retry takes it at once. With
            // Resend the idempotency key stops a second delivery; with another
            // mailer, send_started_at makes the retry give up instead.
            VoucherEmailRequest::withoutGlobalScope('hotel')
                ->whereKey($this->emailRequestId)
                ->where('status', VoucherEmailStatus::SENDING->value)
                ->update(['status' => VoucherEmailStatus::QUEUED->value, 'claimed_at' => null, 'updated_at' => now()]);

            throw $e;
        }
    }

    /**
     * Not claimed: either the request is finished, or another attempt holds a
     * recent claim. In the second case come back once that claim would be
     * stale, instead of ending as a success with the request still `sending`.
     */
    private function waitForLiveClaim(): void
    {
        $status = VoucherEmailRequest::withoutGlobalScope('hotel')->whereKey($this->emailRequestId)->value('status');

        // value() applies the model's cast, so this is the enum.
        if ($status === VoucherEmailStatus::SENDING) {
            $this->release(((int) config('vouchers.email_claim_stale_minutes') + 1) * 60);
        }
    }

    /**
     * The last try failed: staff see the voucher was not sent instead of a
     * request stuck at queued.
     */
    public function failed(?Throwable $exception): void
    {
        $request = VoucherEmailRequest::withoutGlobalScope('hotel')
            ->whereKey($this->emailRequestId)
            ->whereIn('status', [VoucherEmailStatus::QUEUED->value, VoucherEmailStatus::SENDING->value])
            ->first();

        if ($request) {
            TenantContext::runForHotel($request->hotel_id, fn () => $this->finish(
                $request,
                VoucherEmailStatus::FAILED,
                $exception ? class_basename($exception) : 'unknown',
            ));
        }
    }

    private function finish(VoucherEmailRequest $request, VoucherEmailStatus $status, ?string $reason = null): void
    {
        $request->forceFill(['status' => $status, 'status_reason' => $reason])->save();

        EventLogger::record($request, $status->value, context: array_filter([
            'reservation_id' => $request->reservation_id,
            'reason' => $reason,
        ]));
    }
}
