<?php

namespace App\Http\Requests\Vouchers;

use App\Enums\VoucherField;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A whole template layout, validated as a whole (FR-017, FR-019): boxes are
 * fractions of the image, lie fully inside it, and carry basic styling. Each
 * box error names the box id so the editor can point at it.
 */
class VoucherLayoutRequest extends FormRequest
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
        $rules = static::boxRules('layout');
        $rules['layout'] = ['present', ...$rules['layout']];

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public static function boxRules(string $prefix): array
    {
        return [
            $prefix => ['array', 'max:'.config('vouchers.max_layout_boxes')],
            "{$prefix}.*" => ['array'],
            "{$prefix}.*.id" => ['required', 'string', 'max:40', 'distinct'],
            "{$prefix}.*.field" => ['required', Rule::enum(VoucherField::class)],
            "{$prefix}.*.x" => ['required', 'numeric', 'min:0', 'lt:1'],
            "{$prefix}.*.y" => ['required', 'numeric', 'min:0', 'lt:1'],
            "{$prefix}.*.w" => ['required', 'numeric', 'gt:0', 'lte:1'],
            "{$prefix}.*.h" => ['required', 'numeric', 'gt:0', 'lte:1'],
            "{$prefix}.*.font_size" => ['required', 'numeric', 'between:6,72'],
            "{$prefix}.*.color" => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            "{$prefix}.*.bold" => ['sometimes', 'boolean'],
            "{$prefix}.*.align" => ['sometimes', 'in:start,center,end'],
            "{$prefix}.*.overflow" => ['sometimes', 'in:shrink,truncate'],
            "{$prefix}.*.text" => [
                'nullable', 'string', 'max:500',
                "required_if:{$prefix}.*.field,".VoucherField::FREE_TEXT->value,
                "prohibited_unless:{$prefix}.*.field,".VoucherField::FREE_TEXT->value,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => static::checkBoxes($validator, $this->input('layout'), 'layout'));
    }

    /**
     * Boxes must lie inside the image, and every per-box error message names
     * the box id.
     */
    public static function checkBoxes(Validator $validator, mixed $layout, string $prefix): void
    {
        if (! is_array($layout)) {
            return;
        }

        foreach ($layout as $index => $box) {
            if (! is_array($box)) {
                continue;
            }

            foreach (['x' => 'w', 'y' => 'h'] as $origin => $size) {
                if (is_numeric($box[$origin] ?? null) && is_numeric($box[$size] ?? null) && 1 + 1e-9 < $box[$origin] + $box[$size]) {
                    $validator->errors()->add("{$prefix}.{$index}.{$size}", "The box must lie inside the image ({$origin} + {$size} ≤ 1).");
                }
            }
        }

        $messages = $validator->errors()->getMessages();

        foreach ($messages as $key => $list) {
            if (! preg_match('/^'.preg_quote($prefix, '/').'\.(\d+)\./', $key, $match)) {
                continue;
            }

            $id = $layout[(int) $match[1]]['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $messages[$key] = array_map(fn (string $message) => str_contains($message, "(box {$id})") ? $message : "{$message} (box {$id})", $list);
            }
        }

        $errors = $validator->errors();
        foreach (array_keys($errors->getMessages()) as $key) {
            $errors->forget($key);
        }
        foreach ($messages as $key => $list) {
            foreach ($list as $message) {
                $errors->add($key, $message);
            }
        }
    }

    /**
     * The layout with defaults filled, in the stored shape.
     *
     * @param  list<array<string, mixed>>  $layout
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $layout): array
    {
        return array_values(array_map(fn (array $box) => [
            'id' => (string) $box['id'],
            'field' => (string) $box['field'],
            'x' => (float) $box['x'],
            'y' => (float) $box['y'],
            'w' => (float) $box['w'],
            'h' => (float) $box['h'],
            'font_size' => (float) $box['font_size'],
            'color' => strtoupper((string) $box['color']),
            'bold' => (bool) ($box['bold'] ?? false),
            'align' => $box['align'] ?? 'start',
            'overflow' => $box['overflow'] ?? 'shrink',
            'text' => ($box['field'] ?? null) === VoucherField::FREE_TEXT->value ? ($box['text'] ?? null) : null,
        ], $layout));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function layout(): array
    {
        return static::normalize($this->validated('layout'));
    }
}
