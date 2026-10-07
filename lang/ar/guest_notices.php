<?php

/*
 * Fixed messages telling a guest how their request ended (SPEC-007). Never
 * AI-written, and never carrying task titles, descriptions or staff names.
 */
return [

    'completed' => 'مرحباً :first_name، تم الانتهاء من طلب :kind الخاص بك في :hotel. يمكنك الرد هنا إذا احتجت أي شيء آخر.',

    'cancellation_approved' => 'مرحباً :first_name، تم إلغاء حجزك لـ :item بتاريخ :date (رقم المرجع :reference) في :hotel بناءً على طلبك.',

    'cancellation_declined' => 'مرحباً :first_name، حجزك لـ :item بتاريخ :date (رقم المرجع :reference) في :hotel لا يزال قائماً. :note',

    'email_subject_completed' => ':hotel: تم الانتهاء من طلب :kind الخاص بك',

    'email_subject_cancellation_approved' => ':hotel: تم إلغاء حجزك :reference',

    'email_subject_cancellation_declined' => ':hotel: حجزك :reference لا يزال قائماً',

    'kinds' => [
        'housekeeping' => 'التدبير المنزلي',
        'service' => 'الخدمة',
        'maintenance' => 'الصيانة',
        'room_change' => 'تغيير الغرفة',
    ],

];
