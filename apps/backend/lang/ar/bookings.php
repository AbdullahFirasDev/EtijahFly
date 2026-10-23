<?php

return [
    'events' => [
        'created' => 'تم إنشاء الحجز للمسار :route.',
        'awaiting_ticketing' => 'المزود لا يدعم الحجز المبدئي؛ سيتم الشراء عند طلب الإصدار.',
        'hold_created' => 'تم تثبيت الحجز المبدئي لدى المزود (PNR :pnr) حتى :expires.',
        'hold_failed' => 'رفض المزود الحجز: :reason',
        'unexpected_provider_status' => 'أعاد المزود حالة غير متوقعة (:status) بدلاً من الحجز المبدئي.',
        'provider_timeout' => 'لم يستجب المزود في الوقت المحدد. جارٍ التحقق من حالة الحجز لديه.',
        'reconciliation_failed' => 'لم يُعثر على الحجز لدى المزود بعد عدة محاولات تحقق.',
        'charged' => 'تم خصم :amount من حساب الشركة.',
        'ticketed' => 'أصدر المزود :count تذكرة إلكترونية.',
        'ticketing_failed' => 'فشل إصدار التذاكر لدى المزود وتم عكس الخصم: :reason',
        'ticketing_pending_verification' => 'نتيجة الإصدار غير مؤكدة من المزود؛ جارٍ التحقق. لا تعتبر التذاكر صادرة حتى التأكيد.',
        'ticketing_needs_attention' => 'تعذر التحقق من نتيجة الإصدار تلقائياً؛ يتطلب متابعة يدوية مع المزود.',
        'ticketing_not_completed' => 'أكد المزود أن التذاكر لم تصدر (الحالة :status).',
        'provider_no_record' => 'لا يوجد سجل للحجز لدى المزود.',
        'cancelled' => 'تم إلغاء الحجز: :reason',
        'cancel_failed' => 'فشل الإلغاء لدى المزود: :reason',
        'hold_expired' => 'انتهت مهلة الحجز المبدئي.',
        'hold_extended' => 'مدّد المزود مهلة الحجز المبدئي.',
        'refund_requested' => 'تم طلب الاسترداد: :reason',
        'refund_pending' => 'طلب الاسترداد قيد المعالجة لدى المزود.',
        'refunded' => 'اكتمل الاسترداد وأُعيد :amount إلى حساب الشركة.',
        'refund_rejected' => 'رُفض طلب الاسترداد: :reason',
    ],
];
