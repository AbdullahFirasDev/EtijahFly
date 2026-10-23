<?php

return [
    'open' => 'فتح',
    'booking_held' => ['title' => 'حجز مبدئي :reference', 'body' => 'تم تثبيت الحجز :reference (:route) حتى :expires. أصدر التذاكر قبل انتهاء المهلة.'],
    'hold_expiring' => ['title' => 'مهلة الحجز :reference تقترب من الانتهاء', 'body' => 'تنتهي مهلة الحجز :reference (:route) في :expires.'],
    'booking_expired' => ['title' => 'انتهت مهلة الحجز :reference', 'body' => 'انتهت مهلة الحجز المبدئي :reference (:route) دون إصدار.'],
    'booking_ticketed' => ['title' => 'صدرت تذاكر الحجز :reference', 'body' => 'أصدر المزود التذاكر الإلكترونية للحجز :reference (:route).'],
    'ticketing_failed' => ['title' => 'فشل إصدار تذاكر :reference', 'body' => 'تعذر إصدار تذاكر الحجز :reference وتم إرجاع المبلغ إلى حسابك. السبب: :reason'],
    'booking_failed' => ['title' => 'تعذر إنشاء الحجز :reference', 'body' => 'رفض المزود الحجز :reference. السبب: :reason'],
    'booking_cancelled' => ['title' => 'تم إلغاء الحجز :reference', 'body' => 'تم إلغاء الحجز :reference (:route).'],
    'booking_refunded' => ['title' => 'اكتمل استرداد :reference', 'body' => 'اكتمل استرداد الحجز :reference وأُضيف المبلغ إلى حسابك.'],
    'payment_approved' => ['title' => 'تم اعتماد الدفعة :reference', 'body' => 'تمت إضافة :amount إلى رصيد شركتك.'],
    'payment_rejected' => ['title' => 'رُفضت الدفعة :reference', 'body' => 'لم يتم اعتماد الدفعة :reference بمبلغ :amount. راجع ملاحظات المراجعة.'],
    'low_balance' => ['title' => 'رصيد منخفض', 'body' => 'الرصيد المتاح لشركتك :available.'],
    'invitation' => [
        'title' => 'دعوة للانضمام إلى :organisation',
        'body' => 'تمت دعوتك لإنشاء حساب في :organisation.',
        'action' => 'قبول الدعوة',
        'expires' => 'تنتهي صلاحية الدعوة في :date.',
    ],
];
