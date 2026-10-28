{{-- Template voucher (SPEC-013, research R4–R6): the template image fills the page and each
     box is placed at its fractional position. Text is fitted before this view runs; overflow
     is hidden so nothing can draw outside a box (FR-021). Rendering input for mPDF, not a UI. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 0;
            background-image: url("{{ $background }}");
            background-image-resize: 6;
        }
        body { font-family: {{ $font }}; margin: 0; }
        .box { position: absolute; overflow: hidden; line-height: {{ $lineHeight }}; }
    </style>
</head>
<body>
@foreach ($boxes as $box)
    <div class="box" style="left: {{ $box['left'] }}mm; top: {{ $box['top'] }}mm; width: {{ $box['width'] }}mm; height: {{ $box['height'] }}mm; font-size: {{ $box['size'] }}pt; color: {{ $box['color'] }}; font-weight: {{ $box['bold'] ? 'bold' : 'normal' }}; text-align: {{ $box['align'] }}; direction: {{ $box['direction'] }};">{!! implode('<br>', array_map('e', $box['lines'])) !!}</div>
@endforeach
</body>
</html>
