<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\VoucherEmailStatus;
use App\Enums\VoucherLanguage;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff request to email a reservation's voucher (SPEC-013, research R9),
 * with the options chosen at request time and the outcome.
 *
 * No SoftDeletes, on purpose: this is the send history and the idempotency
 * record, and a soft-deleted row would let a reused request key send again.
 * Status columns are written only by the controller and the job.
 */
class VoucherEmailRequest extends Model
{
    use BelongsToHotel, Filterable, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'reservation_id',
        'request_key',
        'recipient',
        'voucher_template_id',
        'language',
        'show_total',
        'overrides',
        'staff_note',
        'requested_by',
    ];

    public array $searchable = ['recipient'];

    protected $casts = [
        'language' => VoucherLanguage::class,
        'status' => VoucherEmailStatus::class,
        'overrides' => 'array',
        'unplaced' => 'array',
        'show_total' => 'boolean',
        'claimed_at' => 'datetime',
        'send_started_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(VoucherTemplate::class, 'voucher_template_id')->withTrashed();
    }
}
