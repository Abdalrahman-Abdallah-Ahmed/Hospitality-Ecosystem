<?php

namespace App\Http\Requests\Vouchers\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

/**
 * Template image rules (FR-011): PNG, JPG or WebP up to 5 MB, checked on the
 * file's real content, with a short side of at least 1000 px, a long side of
 * at most 6000 px and an aspect ratio within 1:3 to 3:1. Each failure is
 * reported under its own key so the client can tell which rule failed.
 */
trait ValidatesVoucherImage
{
    /**
     * @return array<string, mixed>
     */
    protected function imageRules(): array
    {
        return [
            'image' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:'.config('vouchers.max_upload_kb')],
        ];
    }

    protected function checkImage(Validator $validator): void
    {
        $file = $this->file('image');

        if (! $file instanceof UploadedFile || $validator->errors()->has('image')) {
            return;
        }

        $info = @getimagesize($file->getRealPath());
        $types = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];

        if ($info === false || ! isset($types[$info[2]]) || $types[$info[2]] !== $file->getMimeType()) {
            $validator->errors()->add('image.type', 'The image must be a real PNG, JPG or WebP file.');

            return;
        }

        [$width, $height] = $info;
        $short = min($width, $height);
        $long = max($width, $height);

        if ($short < (int) config('vouchers.min_short_side_px')) {
            $validator->errors()->add('image.dimensions_short_side', 'The shorter side of the image must be at least '.config('vouchers.min_short_side_px').' pixels.');
        }

        if ($long > (int) config('vouchers.max_long_side_px')) {
            $validator->errors()->add('image.dimensions_long_side', 'The longer side of the image must be at most '.config('vouchers.max_long_side_px').' pixels.');
        }

        if ($short > 0 && $long / $short > (float) config('vouchers.max_aspect_ratio')) {
            $validator->errors()->add('image.ratio', 'The image proportions must be between 1:3 and 3:1.');
        }
    }
}
