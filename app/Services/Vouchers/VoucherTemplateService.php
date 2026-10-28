<?php

namespace App\Services\Vouchers;

use App\Enums\VoucherLanguage;
use App\Models\Hotel;
use App\Models\User;
use App\Models\VoucherTemplate;
use GdImage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Creates and changes voucher templates (SPEC-013, research R3).
 *
 * Each template keeps its original upload (served back to the editor) and a
 * normalized JPEG the PDF engine reads: decoded with GD, flattened onto
 * white, scaled down to print size and re-encoded, which also drops any
 * metadata or payload hidden in the upload. The layout survives an image
 * replacement because boxes are stored as fractions of the image.
 */
class VoucherTemplateService
{
    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * @param  array{name: string, language: string, is_default?: bool}  $data
     */
    public function create(Hotel $hotel, array $data, UploadedFile $image, ?User $by): VoucherTemplate
    {
        $disk = (string) config('vouchers.disk');
        $written = [];

        try {
            return DB::transaction(function () use ($hotel, $data, $image, $by, $disk, &$written) {
                $this->lockHotel($hotel->id);

                $template = new VoucherTemplate([
                    'name' => $data['name'],
                    'language' => $data['language'],
                    'is_default' => ! empty($data['is_default']),
                ]);
                $template->id = (string) Str::uuid();
                $template->forceFill(['hotel_id' => $hotel->id, 'created_by' => $by?->id, 'disk' => $disk]);
                $template->forceFill($this->storeFiles($template, $image, $written));

                if ($template->is_default) {
                    $this->clearOtherDefaults($template);
                }

                // One insert, already default when asked: no extra audit entry.
                $this->saveCheckingName($template);

                return $template->refresh();
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($written);

            throw $e;
        }
    }

    /**
     * New files under new names, then the old ones are deleted once the
     * change is committed. The layout is untouched (FR-014).
     */
    public function replaceImage(VoucherTemplate $template, UploadedFile $image): VoucherTemplate
    {
        $old = [$template->original_path, $template->render_path];
        $written = [];

        try {
            DB::transaction(function () use ($template, $image, $old, &$written) {
                $template->forceFill($this->storeFiles($template, $image, $written))->save();

                DB::afterCommit(fn () => Storage::disk($template->disk)->delete($old));
            });
        } catch (Throwable $e) {
            Storage::disk($template->disk)->delete($written);

            throw $e;
        }

        return $template->refresh();
    }

    /**
     * Renames, changes the language, or makes the template a default. A
     * default that changes language stays the default, now of its new
     * language (contracts: PUT).
     *
     * @param  array{name?: string, language?: string, is_default?: bool}  $data
     */
    public function update(VoucherTemplate $template, array $data): VoucherTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $this->lockHotel($template->hotel_id);

            $template->fill(array_intersect_key($data, array_flip(['name', 'language'])));
            $template->is_default = (bool) ($data['is_default'] ?? $template->is_default);

            // Only a template that is (or stays) a default can collide with
            // another one, e.g. after moving to a language that has a default.
            if ($template->is_default) {
                $this->clearOtherDefaults($template);
            }

            // One save, so the audit trail records only what really changed.
            $this->saveCheckingName($template);

            return $template->refresh();
        });
    }

    /**
     * Serializes default and name changes within one hotel: a second
     * "make default" waits for the first instead of colliding with it.
     */
    private function lockHotel(string $hotelId): void
    {
        DB::table('hotels')->where('id', $hotelId)->lockForUpdate()->first();
    }

    /**
     * Saves in a savepoint; a name taken by a concurrent request (it passed
     * validation at the same moment) becomes a 422 on `name`, not a 500.
     */
    private function saveCheckingName(VoucherTemplate $template): void
    {
        try {
            DB::transaction(fn () => $template->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A voucher template with this name already exists in this hotel.']]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $layout
     */
    public function saveLayout(VoucherTemplate $template, array $layout): VoucherTemplate
    {
        $template->layout = $layout;
        $template->save();

        return $template;
    }

    /**
     * Unsets any other default of the template's (possibly new) language
     * (FR-013). Call inside a transaction that already holds the hotel lock
     * (lockHotel), so two concurrent "make default" requests run one after
     * the other; the partial unique index backs this up.
     */
    private function clearOtherDefaults(VoucherTemplate $template): void
    {
        $language = $template->language instanceof VoucherLanguage ? $template->language->value : $template->language;

        $others = VoucherTemplate::withoutGlobalScope('hotel')
            ->where('hotel_id', $template->hotel_id)
            ->where('language', $language)
            ->whereKeyNot($template->id)
            ->lockForUpdate()
            ->get();

        foreach ($others as $other) {
            if ($other->is_default) {
                $other->is_default = false;
                $other->save();
            }
        }
    }

    /**
     * JPEG bytes for the PDF engine: flattened onto white and no longer than
     * the print size on its long side.
     */
    public function normalize(string $sourcePath, string $mime): string
    {
        $source = match ($mime) {
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => false,
        };

        if (! $source instanceof GdImage) {
            throw new RuntimeException('The template image could not be decoded.');
        }

        $canvas = null;
        $scaled = null;

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            $canvas = imagecreatetruecolor($width, $height);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

            $limit = (int) config('vouchers.render_long_side_px');
            $long = max($width, $height);

            if ($long > $limit) {
                $scale = $limit / $long;
                $scaled = imagescale($canvas, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
            }

            ob_start();
            imagejpeg($scaled ?: $canvas, null, (int) config('vouchers.render_jpeg_quality'));

            return (string) ob_get_clean();
        } finally {
            imagedestroy($source);
            if ($canvas instanceof GdImage) {
                imagedestroy($canvas);
            }
            if ($scaled instanceof GdImage) {
                imagedestroy($scaled);
            }
        }
    }

    /**
     * Writes the original and the render copy under fresh names and returns
     * the file columns. Paths written are appended to `$written` so a caller
     * can clean up after a failure.
     *
     * @param  list<string>  $written
     * @return array<string, mixed>
     */
    private function storeFiles(VoucherTemplate $template, UploadedFile $image, array &$written): array
    {
        $disk = Storage::disk($template->disk);
        $mime = (string) $image->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? throw new RuntimeException('Unsupported template image type.');
        [$width, $height] = getimagesize($image->getRealPath()) ?: throw new RuntimeException('The template image could not be read.');

        $directory = "voucher-templates/{$template->hotel_id}/{$template->id}";
        $version = Str::lower(Str::random(8));
        $original = "{$directory}/original-{$version}.{$extension}";
        $render = "{$directory}/render-{$version}.jpg";

        $render_bytes = $this->normalize($image->getRealPath(), $mime);

        $disk->putFileAs($directory, $image, basename($original));
        $written[] = $original;
        $disk->put($render, $render_bytes);
        $written[] = $render;

        return [
            'original_path' => $original,
            'render_path' => $render,
            'mime_type' => $mime,
            'size_bytes' => (int) $image->getSize(),
            'width_px' => (int) $width,
            'height_px' => (int) $height,
        ];
    }
}
