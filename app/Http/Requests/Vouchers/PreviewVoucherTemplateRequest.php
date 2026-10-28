<?php

namespace App\Http\Requests\Vouchers;

use App\Models\Reservation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Previewing a template (FR-025) with sample data or a reservation, and
 * optionally an unsaved layout. Nothing is stored.
 */
class PreviewVoucherTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $options = VoucherOptionsRequest::optionRules();
        unset($options['template_id']);

        return [
            'reservation_id' => ['nullable', 'uuid'],
            ...$options,
            ...VoucherLayoutRequest::boxRules('layout'),
            'layout' => ['sometimes', 'array', 'max:'.config('vouchers.max_layout_boxes')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            VoucherOptionsRequest::checkOverrides($validator, $this->input('overrides'), $this->previewReservation());

            if ($this->has('layout')) {
                VoucherLayoutRequest::checkBoxes($validator, $this->input('layout'), 'layout');
            }
        });
    }

    /**
     * The reservation being previewed, for checking date overrides against
     * its stored dates. Tenant-scoped: another hotel's id finds nothing here
     * and is answered 404 by the controller.
     */
    private function previewReservation(): ?Reservation
    {
        $id = $this->input('reservation_id');

        return is_string($id) && Str::isUuid($id) ? Reservation::find($id) : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function layout(): ?array
    {
        return $this->has('layout') ? VoucherLayoutRequest::normalize($this->validated('layout')) : null;
    }
}
