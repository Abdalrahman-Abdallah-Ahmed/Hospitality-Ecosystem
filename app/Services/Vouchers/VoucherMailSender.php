<?php

namespace App\Services\Vouchers;

use App\Mail\ReservationVoucherMail;
use Illuminate\Support\Facades\Mail;
use Resend\Client;
use Symfony\Component\Mime\Address;

/**
 * Sends a voucher email so a retry never delivers it twice (research R9,
 * R10, FR-028).
 *
 * The job claims a request before sending, but a mail that went out while
 * the worker died, or that the provider accepted just as the client timed
 * out, would be sent again on retry.
 *
 * - When the default mailer's transport is Resend, this calls the Resend
 *   client directly with an idempotency key, which Laravel's Resend
 *   transport cannot pass; Resend drops a repeated send with the same key,
 *   so the job may retry freely.
 * - Any other mailer (SMTP, failover, log…) cannot drop duplicates. The job
 *   then sends at most once: see isIdempotent().
 */
class VoucherMailSender
{
    /**
     * Whether a repeated send of the same request is dropped by the provider.
     * Decided by the default mailer's transport, not its name, so a mailer
     * called anything that uses Resend counts; a failover or round-robin
     * mailer does not, since it may hand the mail to another transport.
     */
    public function isIdempotent(): bool
    {
        $mailer = (string) config('mail.default');

        return config("mail.mailers.{$mailer}.transport", $mailer) === 'resend';
    }

    public function send(ReservationVoucherMail $mail, string $recipient, string $idempotencyKey): void
    {
        if (! $this->isIdempotent()) {
            Mail::to($recipient)->send($mail);

            return;
        }

        // Address quotes and encodes the display name, so a name with a comma,
        // quotes or angle brackets still makes one valid address.
        $from = new Address((string) config('mail.from.address'), (string) config('mail.from.name', ''));

        app(Client::class)->emails()->send([
            'from' => $from->toString(),
            'to' => [$recipient],
            'subject' => $mail->subjectLine(),
            'text' => $mail->textBody(),
            'attachments' => [[
                'filename' => $mail->filename(),
                'content' => base64_encode($mail->pdf),
                'content_type' => 'application/pdf',
            ]],
        ], ['idempotency_key' => $idempotencyKey]);
    }
}
