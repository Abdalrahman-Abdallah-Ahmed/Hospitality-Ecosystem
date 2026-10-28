{{-- Built-in voucher layout (SPEC-013, research R8): rendering input for mPDF, not a UI. --}}
@php
    use App\Enums\VoucherField;
    $t = fn (string $key) => __('voucher.'.$key, [], $locale);
    // Phone, email, reference and total keep left-to-right order inside Arabic text.
    $show = fn (VoucherField $field) => $field->isLtrValue() && $data->has($field)
        ? '<bdo dir="ltr">'.e($data->value($field)).'</bdo>'
        : (is_array($data->value($field)) ? implode('<br>', array_map('e', $data->value($field))) : nl2br(e((string) $data->value($field))));
    $rows = [
        ['labels.reservation', VoucherField::RESERVATION_REFERENCE],
        ['labels.status', VoucherField::RESERVATION_STATUS],
        ['labels.guest', VoucherField::GUEST_PRIMARY_NAME],
        ['labels.other_guests', VoucherField::GUESTS_OTHERS],
        ['labels.arrival', VoucherField::STAY_ARRIVAL_DATE],
        ['labels.departure', VoucherField::STAY_DEPARTURE_DATE],
        ['labels.nights', VoucherField::STAY_NIGHTS],
        ['labels.rooms', VoucherField::ROOMS_LINES],
        ['labels.adults', VoucherField::PARTY_ADULTS],
        ['labels.children', VoucherField::PARTY_CHILDREN],
        ['labels.children_ages', VoucherField::PARTY_CHILDREN_AGES],
        ['labels.special_requests', VoucherField::SPECIAL_REQUESTS],
        ['labels.total', VoucherField::TOTAL],
    ];
    $contact = array_map($show, array_values(array_filter(
        [VoucherField::HOTEL_ADDRESS, VoucherField::HOTEL_PHONE, VoucherField::HOTEL_EMAIL],
        fn (VoucherField $field) => $data->has($field),
    )));
    $start = $rtl ? 'right' : 'left';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: {{ $font }}; font-size: 10.5pt; color: #1f2937; direction: {{ $rtl ? 'rtl' : 'ltr' }}; }
        .hotel { font-size: 20pt; font-weight: bold; color: #111827; }
        .contact { font-size: 9pt; color: #4b5563; margin-top: 2mm; }
        .title { font-size: 14pt; font-weight: bold; margin-top: 10mm; padding-bottom: 2mm; border-bottom: 0.4mm solid #111827; }
        table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        td { padding: 2.2mm 0; vertical-align: top; border-bottom: 0.2mm solid #e5e7eb; text-align: {{ $start }}; }
        td.label { width: 38%; color: #6b7280; }
        td.value { font-weight: bold; }
        .note { margin-top: 8mm; padding: 3mm; border: 0.3mm solid #d1d5db; }
        .issued { margin-top: 8mm; font-size: 8.5pt; color: #6b7280; }
    </style>
</head>
<body>
    <div class="hotel">{{ $data->value(VoucherField::HOTEL_NAME) }}</div>
    @if ($contact !== [])
        <div class="contact">{!! implode('  ·  ', $contact) !!}</div>
    @endif

    <div class="title">{{ $t('title') }}</div>

    <table>
        @foreach ($rows as [$label, $field])
            @continue(! $data->has($field))
            <tr>
                <td class="label">{{ $t($label) }}</td>
                <td class="value">{!! $show($field) !!}</td>
            </tr>
        @endforeach
    </table>

    @if ($data->has(VoucherField::STAFF_NOTE))
        <div class="note"><b>{{ $t('labels.note') }}:</b> {!! nl2br(e($data->value(VoucherField::STAFF_NOTE))) !!}</div>
    @endif

    <div class="issued">{{ $t('labels.issued') }}: {{ $data->value(VoucherField::ISSUE_DATE) }}</div>
</body>
</html>
