<div class="footer">
    @if ($brand['footer'])
        <div>{{ $brand['footer'] }}</div>
    @endif
    <div>
        {{ $brand['name'] }} @if ($brand['address']) — {{ $brand['address'] }} @endif
    </div>
    <div class="ltr">
        Generated {{ $generatedAt->format('Y-m-d H:i T') }} · Powered by {{ $platformName }}
    </div>
</div>
