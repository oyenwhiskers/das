{{-- Soft BIMBSEC form-fit hint. Never blocks save; warns when over the cell capacity. --}}
@props([
    'max' => null,
    'for' => null,
    'ids' => null,
    'together' => false,
])

@php
    $max = is_numeric($max) ? (int) $max : null;
    $ids = array_values(array_filter($ids ?? ($for ? [$for] : [])));
    $togetherNote = ($together || count($ids) > 1) ? ' for these lines together' : '';
@endphp

@if ($max && $ids !== [])
    <div
        x-data="{
            max: {{ $max }},
            len: 0,
            ids: {{ \Illuminate\Support\Js::from($ids) }},
            sync() {
                const parts = this.ids
                    .map((id) => (document.getElementById(id)?.value ?? '').trim())
                    .filter((value) => value !== '');
                this.len = Array.from(parts.join(' ')).length;
            },
            init() {
                this.sync();
                this.ids.forEach((id) => document.getElementById(id)?.addEventListener('input', () => this.sync()));
            },
        }"
        {{ $attributes }}
    >
        <p x-cloak x-show="len > max" class="mt-1 text-xs text-amber-700">
            Too long for the BIMBSEC form (<span x-text="len"></span>/{{ $max }}). You can still save — shorten before printing.
        </p>
        <p x-show="len <= max" class="mt-1 text-xs text-slate-500">
            BIMBSEC form fits {{ $max }} characters{{ $togetherNote }}.
        </p>
    </div>
@endif
