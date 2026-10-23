<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    @include('pdf.partials.styles')
</head>
<body>
@php($isCredit = $invoice->type === 'credit_note')
@include('pdf.partials.header', [
    'titleAr' => $isCredit ? 'إشعار دائن' : 'فاتورة',
    'titleEn' => $isCredit ? 'Credit Note' : 'Invoice',
    'badge' => $invoice->number,
])

<table class="kv" style="width: 100%;">
    <tr>
        <td class="k">إلى / Bill to</td>
        <td class="bold">{{ $tenant->legal_name ?? $tenant->name }}</td>
        <td class="k">التاريخ / Date</td>
        <td class="ltr">{{ $invoice->issued_at->timezone($tenant->timezone)->format('Y-m-d') }}</td>
    </tr>
    <tr>
        <td class="k">الصادر عن / Issued by</td>
        <td>{{ $platformName }}</td>
        <td class="k">العملة / Currency</td>
        <td>{{ $invoice->currency }}</td>
    </tr>
    @if ($booking)
        <tr>
            <td class="k">رقم الحجز / Booking</td>
            <td class="code ltr">{{ $booking->reference }}</td>
            <td class="k">المسار / Route</td>
            <td class="ltr">{{ $booking->route() }}</td>
        </tr>
    @endif
</table>

<table class="grid" style="margin-top: 12px;">
    <thead>
        <tr>
            <th>البيان<br><span class="small">Description</span></th>
            <th style="width: 28%;">المبلغ<br><span class="small">Amount ({{ $invoice->currency }})</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="ltr">{{ number_format((float) $line['amount'], 2) }}</td>
            </tr>
        @endforeach
        <tr class="total-row">
            <td>{{ $isCredit ? 'صافي المبلغ المُعاد إلى الحساب / Net credited to account' : 'صافي المستحق / Net payable' }}</td>
            <td class="ltr">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency }}</td>
        </tr>
    </tbody>
</table>

@if ($booking?->is_test)
    <div class="notice-test">مستند تجريبي — TEST DOCUMENT, no real transaction.</div>
@endif

@include('pdf.partials.footer')
</body>
</html>
