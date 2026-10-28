<?php

return [

    'title' => 'قسيمة الحجز',

    'fields' => [
        'hotel.name' => 'اسم الفندق',
        'hotel.address' => 'عنوان الفندق',
        'hotel.phone' => 'هاتف الفندق',
        'hotel.email' => 'بريد الفندق',
        'reservation.reference' => 'رقم الحجز',
        'reservation.status' => 'حالة الحجز',
        'guest.primary_name' => 'الضيف الرئيسي',
        'guests.others' => 'الضيوف الآخرون',
        'guests.all' => 'جميع الضيوف',
        'stay.arrival_date' => 'تاريخ الوصول',
        'stay.departure_date' => 'تاريخ المغادرة',
        'stay.nights' => 'عدد الليالي',
        'rooms.lines' => 'الغرف',
        'party.adults' => 'البالغون',
        'party.children' => 'الأطفال',
        'party.children_ages' => 'أعمار الأطفال',
        'reservation.special_requests' => 'الطلبات الخاصة',
        'reservation.total' => 'الإجمالي',
        'voucher.staff_note' => 'ملاحظة الموظف',
        'voucher.issue_date' => 'تاريخ الإصدار',
        'free_text' => 'نص حر',
    ],

    'labels' => [
        'reservation' => 'الحجز',
        'status' => 'الحالة',
        'guest' => 'الضيف',
        'other_guests' => 'الضيوف الآخرون',
        'arrival' => 'الوصول',
        'departure' => 'المغادرة',
        'nights' => 'الليالي',
        'rooms' => 'الغرف',
        'adults' => 'البالغون',
        'children' => 'الأطفال',
        'children_ages' => 'أعمار الأطفال',
        'special_requests' => 'الطلبات الخاصة',
        'total' => 'الإجمالي',
        'note' => 'ملاحظة',
        'issued' => 'تاريخ الإصدار',
    ],

    'statuses' => [
        'pending' => 'قيد الانتظار',
        'confirmed' => 'مؤكد',
        'checked_in' => 'تم تسجيل الوصول',
        'checked_out' => 'تم تسجيل المغادرة',
        'cancelled' => 'ملغى',
        'no_show' => 'لم يحضر',
    ],

    'list_separator' => '، ',

    'more' => '+:count أخرى',

    'mail' => [
        'subject' => 'قسيمة حجزك – :reference',
        'body' => "عزيزي الضيف،\n\nتجد مرفقًا قسيمة حجزك رقم :reference في :hotel.\n\nنتطلع إلى استقبالك.",
    ],

    'errors' => [
        'ineligible' => 'لا يمكن إصدار قسيمة لحجز ملغى أو لم يحضر صاحبه.',
        'no_recipient' => 'لا يوجد بريد إلكتروني للضيف. أدخل بريدًا لإرسال القسيمة.',
        'daily_cap' => 'تم إرسال عدد كبير من القسائم لهذا الحجز. حاول لاحقًا.',
        'user_hourly_cap' => 'أرسلت عددًا كبيرًا من القسائم خلال الساعة الأخيرة. حاول لاحقًا.',
        'hotel_contact_on_email' => 'لا يمكن تعديل بيانات الفندق في قسيمة مرسلة بالبريد. نزّلها بدلًا من ذلك.',
        'not_overridable' => 'لا يمكن تعديل هذا الحقل.',
        'departure_before_arrival' => 'يجب أن يكون تاريخ المغادرة المعروض بعد تاريخ الوصول المعروض.',
    ],

];
