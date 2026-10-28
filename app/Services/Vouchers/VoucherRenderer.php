<?php

namespace App\Services\Vouchers;

use App\Enums\VoucherField;
use App\Enums\VoucherLanguage;
use App\Models\VoucherTemplate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use RuntimeException;

/**
 * Renders reservation vouchers to PDF with mPDF (SPEC-013, research R1–R8).
 *
 * - The built-in layout is an A4 page with a label/value table.
 * - A template voucher uses the template's normalized image as the full-page
 *   background, the page taking the image's proportions, and places each
 *   layout box at its fractional position with the text fitted beforehand.
 *
 * Arabic is shaped and laid out right to left with mPDF's own XB Riyaz font
 * (OpenType layout on). Noto Naskh Arabic was tried first, but mPDF cannot
 * read its GPOS mark-to-ligature tables (lookup type 5, format 3). Latin
 * runs fall back to Noto Sans.
 * Nothing is fetched over the network and nothing is stored.
 */
class VoucherRenderer
{
    /** Fields that may be sent with a request but have no box of their own on every template. */
    private const OPTIONAL_PLACEMENT = [VoucherField::STAFF_NOTE, VoucherField::TOTAL];

    public function renderDefault(VoucherData $data): VoucherRenderResult
    {
        $mpdf = $this->newMpdf('A4', $data->language, margins: 15);
        $mpdf->WriteHTML($this->defaultHtml($data));

        return new VoucherRenderResult($mpdf->Output('', 'S'));
    }

    /**
     * @param  list<array<string, mixed>>|null  $layout  an unsaved layout (preview), else the template's own
     * @param  bool  $preview  stamps "PREVIEW" across the page: a preview is never audited, so it must not pass for a real voucher
     */
    public function renderTemplate(VoucherData $data, VoucherTemplate $template, ?array $layout = null, bool $preview = false): VoucherRenderResult
    {
        // The template may come back re-read if its image was just replaced.
        [$background, $template] = $this->renderCopy($template);
        $layout ??= $template->layout ?? [];
        [$width, $height] = $this->pageSize($template->width_px, $template->height_px);

        try {
            $mpdf = $this->newMpdf([$width, $height], $data->language, margins: 0);

            if ($preview) {
                $mpdf->SetWatermarkText('PREVIEW', 0.15);
                $mpdf->showWatermarkText = true;
            }

            $mpdf->WriteHTML($this->templateHtml($data, $layout, $width, $height, $background, new VoucherTextFitter($mpdf)));
            $pdf = $mpdf->Output('', 'S');
        } finally {
            @unlink($background);
        }

        return new VoucherRenderResult($pdf, $this->unplaced($data, $layout));
    }

    public function defaultHtml(VoucherData $data): string
    {
        return view('vouchers.default', [
            'data' => $data,
            'rtl' => $data->language->isRtl(),
            'locale' => $data->language->value,
            'font' => $this->fontFor($data->language),
        ])->render();
    }

    /**
     * @param  list<array<string, mixed>>  $layout
     */
    public function templateHtml(VoucherData $data, array $layout, float $width, float $height, string $background, VoucherTextFitter $fitter): string
    {
        $rtl = $data->language->isRtl();
        $font = $this->fontFor($data->language);
        $boxes = [];

        foreach ($layout as $box) {
            $field = VoucherField::tryFrom((string) ($box['field'] ?? ''));

            if (! $field) {
                continue;
            }

            $boxWidth = (float) $box['w'] * $width;
            $boxHeight = (float) $box['h'] * $height;
            $bold = (bool) ($box['bold'] ?? false);
            $size = (float) $box['font_size'];

            if ($field->isList()) {
                $items = $data->value($field);
                $fitted = $items === [] ? ['size' => $size, 'lines' => []]
                    : $fitter->fitList($font, $bold, $size, $boxWidth, $boxHeight, $items, $data->language);
            } else {
                $text = $field === VoucherField::FREE_TEXT ? ($box['text'] ?? null) : $data->value($field);
                $fitted = blank($text) ? ['size' => $size, 'lines' => []]
                    : $fitter->fitText($font, $bold, $size, $boxWidth, $boxHeight, (string) $text, $box['overflow'] ?? 'shrink');
            }

            $boxes[] = [
                'left' => round((float) $box['x'] * $width, 3),
                'top' => round((float) $box['y'] * $height, 3),
                'width' => round($boxWidth, 3),
                'height' => round($boxHeight, 3),
                'size' => $fitted['size'],
                'lines' => $fitted['lines'],
                'color' => $box['color'] ?? '#000000',
                'bold' => $bold,
                'align' => $this->cssAlign($box['align'] ?? 'start', $rtl),
                'direction' => $rtl && ! $field->isLtrValue() ? 'rtl' : 'ltr',
            ];
        }

        return view('vouchers.template', [
            'boxes' => $boxes,
            'rtl' => $rtl,
            'locale' => $data->language->value,
            'font' => $font,
            'width' => $width,
            'height' => $height,
            'background' => str_replace('\\', '/', $background),
            'lineHeight' => VoucherTextFitter::LINE_HEIGHT,
        ])->render();
    }

    /**
     * The page in millimetres with the image's proportions, long side fixed
     * (research R4).
     *
     * @return array{0: float, 1: float}
     */
    public function pageSize(int $widthPx, int $heightPx): array
    {
        $long = (float) config('vouchers.page_long_side_mm');
        $ratio = min($widthPx, $heightPx) / max($widthPx, $heightPx);
        $short = round($long * $ratio, 3);

        return $widthPx >= $heightPx ? [$long, $short] : [$short, $long];
    }

    /**
     * `start` and `end` follow the reading direction (FR-022).
     */
    public function cssAlign(string $align, bool $rtl): string
    {
        return match ($align) {
            'center' => 'center',
            'end' => $rtl ? 'left' : 'right',
            default => $rtl ? 'right' : 'left',
        };
    }

    /**
     * A PDF engine with the bundled fonts, no remote fetching, and a page of
     * the given format. Public so the text fitter can share its font setup.
     *
     * @param  string|array{0: float, 1: float}  $format
     */
    public function newMpdf(string|array $format, VoucherLanguage $language, float $margins = 0): Mpdf
    {
        $tempDir = (string) config('vouchers.mpdf_temp_dir');
        File::ensureDirectoryExists($tempDir);

        $orientation = 'P';
        if (is_array($format)) {
            $orientation = $format[0] > $format[1] ? 'L' : 'P';
            $format = [min($format), max($format)];
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'orientation' => $orientation,
            'margin_left' => $margins,
            'margin_right' => $margins,
            'margin_top' => $margins,
            'margin_bottom' => $margins,
            'margin_header' => 0,
            'margin_footer' => 0,
            'tempDir' => $tempDir,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], resource_path('fonts/voucher')],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'notosans' => [
                    'R' => 'NotoSans-Regular.ttf',
                    'B' => 'NotoSans-Bold.ttf',
                ],
            ],
            'default_font' => $this->fontFor($language),
            // Latin names, emails and digits inside an Arabic voucher.
            'useSubstitutions' => true,
            'backupSubsFont' => ['notosans', 'dejavusans'],
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'curlAllowUnsafeSslRequests' => false,
            'whitelistStreamWrappers' => [],
        ]);

        if ($language->isRtl()) {
            $mpdf->SetDirectionality('rtl');
        }

        return $mpdf;
    }

    public function fontFor(VoucherLanguage $language): string
    {
        return $language->isRtl() ? 'xbriyaz' : 'notosans';
    }

    /**
     * Keys that carry a value for this voucher but have no box on the
     * layout: the staff note and the shown total, when sent but not placed.
     *
     * @param  list<array<string, mixed>>  $layout
     * @return list<string>
     */
    private function unplaced(VoucherData $data, array $layout): array
    {
        $placed = array_column($layout, 'field');

        return collect(self::OPTIONAL_PLACEMENT)
            ->filter(fn (VoucherField $field) => $data->has($field) && ! in_array($field->value, $placed, true))
            ->map(fn (VoucherField $field) => $field->value)
            ->values()
            ->all();
    }

    /**
     * A private temporary copy of the template's render image, which the
     * caller deletes. Copying first means an image replaced mid-render (its
     * old file deleted once the replacement commits) cannot pull the file
     * from under the PDF engine; if the file is already gone, the template is
     * re-read and its new image used.
     *
     * @return array{0: string, 1: VoucherTemplate}
     */
    private function renderCopy(VoucherTemplate $template): array
    {
        $disk = Storage::disk($template->disk);

        if (! $disk->exists($template->render_path)) {
            $template = VoucherTemplate::withoutGlobalScope('hotel')->withTrashed()->findOrFail($template->getKey());
        }

        // tempnam() creates the file; rename that same file to a .jpg name so
        // the one path the caller deletes is the only file left behind.
        $placeholder = tempnam(sys_get_temp_dir(), 'voucher');
        $path = $placeholder.'.jpg';
        rename($placeholder, $path);
        file_put_contents($path, $disk->get($template->render_path) ?? throw new RuntimeException('The template image is missing.'));

        return [$path, $template];
    }
}
