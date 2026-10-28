<?php

namespace App\Services\Vouchers;

/**
 * A rendered voucher PDF, plus the data keys that had a value but no box on
 * the template (spec US4 #5), for example a staff note the template has no
 * place for.
 */
final readonly class VoucherRenderResult
{
    /**
     * @param  list<string>  $unplaced
     */
    public function __construct(
        public string $pdf,
        public array $unplaced = [],
    ) {}
}
