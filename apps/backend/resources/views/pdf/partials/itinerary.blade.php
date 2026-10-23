@php
    $cityOf = fn (string $code) => isset($airports[$code]) ? ($airports[$code]->city_ar ?: $airports[$code]->city_en) : $code;
    $fmt = fn (string $iso) => \Illuminate\Support\Carbon::parse($iso)->format('Y-m-d H:i');
    $dur = fn (int $m) => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
@endphp

<div class="section-title">خط الرحلة &nbsp;<span class="small muted ltr">Itinerary</span></div>

@foreach ($booking->itinerary as $index => $slice)
    <div class="slice-head">
        {{ $cityOf($slice['origin']) }} ({{ $slice['origin'] }}) ← {{ $cityOf($slice['destination']) }} ({{ $slice['destination'] }})
        &nbsp;·&nbsp; {{ $slice['stops'] === 0 ? 'مباشرة / Direct' : $slice['stops'].' توقف / stop(s)' }}
        &nbsp;·&nbsp; <span class="ltr">{{ $dur($slice['duration_minutes']) }}</span>
    </div>
    <table class="grid">
        <thead>
            <tr>
                <th>الرحلة<br><span class="small">Flight</span></th>
                <th>المغادرة<br><span class="small">Departure</span></th>
                <th>الوصول<br><span class="small">Arrival</span></th>
                <th>المدة<br><span class="small">Duration</span></th>
                <th>الطائرة / الدرجة<br><span class="small">Aircraft / Class</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($slice['segments'] as $segment)
                <tr>
                    <td>
                        <span class="bold ltr">{{ $segment['flight_number'] }}</span><br>
                        <span class="small muted">{{ $airlineNames[$segment['marketing_carrier']] ?? $segment['marketing_carrier'] }}</span>
                    </td>
                    <td>
                        <span class="bold">{{ $segment['origin'] }}</span> {{ $cityOf($segment['origin']) }}<br>
                        <span class="ltr small">{{ $fmt($segment['departure_at']) }}</span>
                    </td>
                    <td>
                        <span class="bold">{{ $segment['destination'] }}</span> {{ $cityOf($segment['destination']) }}<br>
                        <span class="ltr small">{{ $fmt($segment['arrival_at']) }}</span>
                    </td>
                    <td class="ltr">{{ $dur($segment['duration_minutes']) }}</td>
                    <td class="small">{{ $segment['aircraft'] ?? '-' }} / {{ __('cabins.'.$booking->cabin) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach

<table class="kv" style="width: 100%; margin-top: 6px;">
    <tr>
        <td class="k">الأمتعة المسجلة / Checked baggage</td>
        <td class="ltr">{{ $booking->baggage['checked'] ?? '-' }}</td>
        <td class="k">حقيبة اليد / Cabin baggage</td>
        <td class="ltr">{{ $booking->baggage['cabin'] ?? '-' }}</td>
    </tr>
    <tr>
        <td class="k">قابلية الاسترداد / Refundable</td>
        <td>{{ $booking->refundable ? 'نعم / Yes' : 'لا / No' }}</td>
        <td class="k">التوقيت / Times</td>
        <td class="small">بالتوقيت المحلي للمطار / local airport time</td>
    </tr>
</table>
