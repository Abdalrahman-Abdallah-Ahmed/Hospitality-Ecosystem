<?php

namespace App\Mail;

use App\Enums\VoucherLanguage;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The reservation voucher sent to a guest at a staff member's request
 * (SPEC-013, FR-026). Not queued: SendReservationVoucherEmailJob already runs
 * on the queue and records the outcome, and sends it through
 * VoucherMailSender so the provider can drop a duplicate.
 */
class ReservationVoucherMail extends Mailable
{
    public function __construct(
        public string $pdf,
        public string $reference,
        public VoucherLanguage $language,
        public string $hotelName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(text: 'mail.voucher', with: ['body' => $this->textBody()]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename())->withMime('application/pdf')];
    }

    public function filename(): string
    {
        return 'voucher-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->reference).'.pdf';
    }

    public function subjectLine(): string
    {
        return __('voucher.mail.subject', ['reference' => $this->reference], $this->language->value);
    }

    public function textBody(): string
    {
        return __('voucher.mail.body', ['reference' => $this->reference, 'hotel' => $this->hotelName], $this->language->value);
    }
}
