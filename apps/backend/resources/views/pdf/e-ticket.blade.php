<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>E-ticket {{ $booking->reference }}</title>
    @include('pdf.partials.styles')
</head>
<body>
@include('pdf.partials.header', [
    'titleAr' => 'إيصال التذكرة الإلكترونية',
    'titleEn' => 'Electronic Ticket Itinerary / Receipt',
    'badge' => $booking->status->value === 'ticketed' ? 'صادرة / Issued' : __('statuses.booking.'.$booking->status->value, [], 'ar').' / '.__('statuses.booking.'.$booking->status->value, [], 'en'),
    'badgeClass' => $booking->status->value === 'ticketed' ? 'pill' : 'pill-muted',
])

@if ($booking->is_test)
    <div class="notice-test">
        تذكرة تجريبية من مزود محاكاة — أرقام التذاكر غير صادرة عن شركة طيران ولا تصلح للسفر.<br>
        <span class="ltr">TEST TICKET from a simulated provider — ticket numbers were not issued by an airline. Not valid for travel.</span>
    </div>
@else
    <div class="notice-ok">
        أرقام التذاكر أدناه صادرة ومؤكدة من مزود الحجز بتاريخ {{ $booking->ticketed_at?->timezone($tenant->timezone)->format('Y-m-d H:i') }}.
        <br><span class="ltr small">Ticket numbers below were issued and confirmed by the booking provider.</span>
    </div>
@endif

@if (in_array($booking->status->value, ['refund_pending', 'refunded']))
    <div class="notice">
        {{ $booking->status->value === 'refunded' ? 'تم استرداد هذه التذاكر ولم تعد صالحة.' : 'طلب استرداد قيد المعالجة لهذه التذاكر.' }}
        <span class="ltr small">{{ $booking->status->value === 'refunded' ? 'These tickets have been refunded and are no longer valid.' : 'A refund is being processed for these tickets.' }}</span>
    </div>
@endif

<table class="kv" style="width: 100%;">
    <tr>
        <td class="k">مرجع شركة الطيران / Airline PNR</td>
        <td class="code ltr">{{ $booking->pnr }}</td>
        <td class="k">رقم الحجز / Booking reference</td>
        <td class="code ltr">{{ $booking->reference }}</td>
    </tr>
    <tr>
        <td class="k">تاريخ الإصدار / Issued</td>
        <td class="ltr">{{ $booking->ticketed_at?->timezone($tenant->timezone)->format('Y-m-d H:i') }}</td>
        <td class="k">الناقل المُصدِر / Validating carrier</td>
        <td>{{ $airlineNames[$tickets->first()?->airline_code] ?? $tickets->first()?->airline_code }}</td>
    </tr>
</table>

<div class="section-title">التذاكر &nbsp;<span class="small muted ltr">Tickets</span></div>
<table class="grid">
    <thead>
        <tr>
            <th>المسافر<br><span class="small">Passenger</span></th>
            <th>النوع<br><span class="small">Type</span></th>
            <th>رقم التذكرة<br><span class="small">E-ticket number</span></th>
            <th>الحالة<br><span class="small">Status</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($tickets as $ticket)
            <tr>
                <td class="ltr bold">{{ $ticket->passenger->last_name }}/{{ $ticket->passenger->first_name }} {{ $ticket->passenger->title }}</td>
                <td>{{ __('passenger_types.'.$ticket->passenger->type->value) }}</td>
                <td class="code ltr">{{ substr($ticket->ticket_number, 0, 3) }}-{{ substr($ticket->ticket_number, 3) }}</td>
                <td>{{ __('statuses.ticket.'.$ticket->status) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@include('pdf.partials.itinerary')

<div class="section-title">تنبيهات السفر &nbsp;<span class="small muted ltr">Travel notes</span></div>
<div class="small">
    يرجى الحضور إلى المطار قبل موعد الإقلاع بثلاث ساعات على الأقل للرحلات الدولية، والتأكد من صلاحية جواز السفر والتأشيرات المطلوبة.
    تخضع التذكرة لشروط شركة الطيران الناقلة.
    <br><span class="ltr">Please arrive at least 3 hours before international departures with a valid passport and any required visas. Tickets are subject to the operating carrier's conditions of carriage.</span>
</div>

@include('pdf.partials.footer')
</body>
</html>
