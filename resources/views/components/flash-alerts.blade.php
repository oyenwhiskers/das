@if (session('status') || session('error'))
    <div
        id="flash-alerts"
        class="hidden"
        @if (session('status')) data-status="{{ session('status') }}" @endif
        @if (session('error')) data-error="{{ session('error') }}" @endif
    ></div>
@endif
