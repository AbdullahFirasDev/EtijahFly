<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>Booking {{ $booking->reference }}</title>
    @include('pdf.partials.styles')
</head>
<body>
@include('pdf.partials.header', [
    'titleAr' => 'تأكيد حجز',
    'titleEn' => 'Booking Confirmation',
    'badge' => __('statuses.booking.'.$booking->status->value, [], 'ar').' / '.__('statuses.booking.'.$booking->status->value, [], 'en'),
    'badgeClass' => in_array($booking->status->value, ['on_hold', 'confirmed', 'ticketed']) ? 'pill' : 'pill-muted',
])

@if ($booking->is_test)
    <div class="notice-test">
        حجز تجريبي من مزود محاكاة — الرحلات والأسعار والمراجع غير حقيقية ولا يصلح للسفر.<br>
        <span class="ltr">TEST BOOKING from a simulated provider — flights, fares and references are not real. Not valid for travel.</span>
    </div>
@endif

<div class="notice">
    <span class="bold">هذا المستند تأكيد حجز صادر عن {{ $brand['name'] }} عبر منصة {{ $platformName }} وليس تذكرة سفر إلكترونية.</span>
    @if ($isTicketed)
        رقم التذكرة الرسمي يظهر في مستند التذكرة الإلكترونية الصادر بعد تأكيد شركة الطيران.
    @else
        لم تصدر التذكرة الإلكترونية بعد؛ لا يمكن السفر بهذا المستند.
    @endif
    <br>
    <span class="ltr small">This is a booking confirmation issued by {{ $brand['name'] }} via {{ $platformName }}. It is NOT an electronic ticket.
        {{ $isTicketed ? 'Official ticket numbers appear on the e-ticket document issued after airline confirmation.' : 'No e-ticket has been issued yet; this document is not valid for travel.' }}</span>
</div>

<table class="kv" style="width: 100%;">
    <tr>
        <td class="k">رقم الحجز / Booking reference</td>
        <td class="code ltr">{{ $booking->reference }}</td>
        <td class="k">مرجع شركة الطيران / Airline PNR</td>
        <td class="code ltr">{{ $booking->pnr ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">تاريخ الحجز / Booked on</td>
        <td class="ltr">{{ $booking->created_at->timezone($tenant->timezone)->format('Y-m-d H:i') }}</td>
        <td class="k">نوع الرحلة / Trip</td>
        <td>{{ __('trip_types.'.$booking->trip_type) }}</td>
    </tr>
    @if ($booking->status->value === 'on_hold' && $booking->hold_expires_at)
        <tr>
            <td class="k">الحجز المبدئي ساري حتى / Hold valid until</td>
            <td class="ltr bold" colspan="3">{{ $booking->hold_expires_at->timezone($tenant->timezone)->format('Y-m-d H:i T') }}</td>
        </tr>
    @endif
</table>

<div class="section-title">المسافرون &nbsp;<span class="small muted ltr">Passengers</span></div>
<table class="grid">
    <thead>
        <tr>
            <th>#</th>
            <th>الاسم<br><span class="small">Name</span></th>
            <th>النوع<br><span class="small">Type</span></th>
            <th>وثيقة السفر<br><span class="small">Travel document</span></th>
            <th>الجنسية<br><span class="small">Nationality</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($booking->passengers as $i => $passenger)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="ltr bold">{{ $passenger->last_name }}/{{ $passenger->first_name }} {{ $passenger->title }}</td>
                <td>{{ __('passenger_types.'.$passenger->type->value) }}</td>
                <td class="ltr">{{ $passenger->maskedDocument() }}</td>
                <td>{{ $passenger->nationality }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@include('pdf.partials.itinerary')

<div class="section-title">السعر &nbsp;<span class="small muted ltr">Fare</span></div>
<table class="grid" style="width: 55%;">
    <tr class="total-row">
        <td>الإجمالي لجميع المسافرين / Total, all passengers</td>
        <td class="ltr">{{ number_format((float) $booking->sale_total, 2) }} {{ $booking->currency }}</td>
    </tr>
</table>

<div class="section-title">جهة الاتصال &nbsp;<span class="small muted ltr">Contact</span></div>
<table class="kv">
    <tr><td class="k">البريد / Email</td><td class="ltr">{{ $booking->contact_email }}</td></tr>
    @if ($booking->contact_phone)
        <tr><td class="k">الهاتف / Phone</td><td class="ltr">{{ $booking->contact_phone }}</td></tr>
    @endif
</table>

@include('pdf.partials.footer')
</body>
</html>
