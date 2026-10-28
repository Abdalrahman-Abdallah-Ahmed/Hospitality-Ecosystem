<?php

namespace App\Http\Requests\Vouchers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Models\VoucherEmailRequest;

/**
 * The generic list parameters for a reservation's voucher email history.
 * The controller is named after the reservation voucher, not the model, so
 * the model is given here instead of inferred from the route.
 */
class VoucherEmailRequestIndexRequest extends GenericIndexRequest
{
    protected function modelClass(): string
    {
        return VoucherEmailRequest::class;
    }
}
