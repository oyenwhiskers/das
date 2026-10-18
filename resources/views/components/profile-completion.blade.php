@props(['percent', 'wide' => false])
@php
    $percent = max(0, min(100, (int) $percent));
@endphp
<div {{ $attributes->merge(['class' => $wide ? 'flex w-full max-w-xs items-center gap-2.5' : 'inline-flex items-center gap-2']) }}>
    <div @class([
        'h-2 overflow-hidden rounded-full bg-slate-100 ring-1 ring-inset ring-slate-200',
        'min-w-0 flex-1' => $wide,
        'w-16' => ! $wide,
    ]) role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Profile {{ $percent }}% complete">
        <div @class([
            'h-full rounded-full',
            'bg-emerald-500' => $percent >= 100,
            'bg-amber-400' => $percent >= 50 && $percent < 100,
            'bg-rose-400' => $percent < 50,
        ]) style="width: {{ $percent }}%"></div>
    </div>
    <span @class([
        'shrink-0 tabular-nums text-xs font-semibold',
        'text-emerald-700' => $percent >= 100,
        'text-amber-700' => $percent >= 50 && $percent < 100,
        'text-rose-700' => $percent < 50,
    ])>{{ $percent }}%</span>
</div>
