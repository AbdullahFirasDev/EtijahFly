<table class="header">
    <tr>
        <td style="width: 60%;">
            @if ($brand['logo'])
                <img src="{{ $brand['logo'] }}" style="height: 46px;" alt="">
                <br>
            @endif
            <span class="brand-name">{{ $brand['name'] }}</span><br>
            <span class="small muted">
                @if ($brand['phone']) <span class="ltr">{{ $brand['phone'] }}</span> @endif
                @if ($brand['email']) &nbsp;|&nbsp; <span class="ltr">{{ $brand['email'] }}</span> @endif
                @if ($brand['website']) &nbsp;|&nbsp; <span class="ltr">{{ $brand['website'] }}</span> @endif
            </span>
        </td>
        <td style="width: 40%; text-align: left;">
            <div class="doc-title">{{ $titleAr }}</div>
            <div class="doc-subtitle ltr">{{ $titleEn }}</div>
            @isset($badge)
                <div style="margin-top: 5px;"><span class="{{ $badgeClass ?? 'pill' }}">{{ $badge }}</span></div>
            @endisset
        </td>
    </tr>
</table>
