{{-- Soft BIMBSEC form-fit hint. Never blocks save; warns when over the cell capacity. --}}
@props([
    'max' => null,
    'for' => null,
    'ids' => null,
    'together' => false,
    // Matches CorporateResolver::address — ids order: line1, line2, postcode, city, state, country.
    'corporateAddress' => false,
    // number_format(value, 2, '.', ',') length (corporate capital boxes).
    'money' => false,
    // Printed as dd/mm/yyyy (10 cells).
    'dateDmy' => false,
    // Override join between multiple plain fields (e.g. " — " for industry + activity).
    'join' => null,
    // Always show current length as len/max.
    'liveCount' => false,
    // Server-rendered starting length (avoids 0 before Livewire fills the inputs).
    'initial' => null,
])

@php
    $max = is_numeric($max) ? (int) $max : null;
    $ids = array_values(array_filter($ids ?? ($for ? [$for] : [])));
    $togetherNote = ($together || $corporateAddress || count($ids) > 1) ? ' for these lines together' : '';
    $initial = is_numeric($initial) ? (int) $initial : 0;
    $join = is_string($join) ? $join : ' ';
@endphp

@if ($max && $ids !== [])
    <div
        x-data="{
            max: {{ $max }},
            len: {{ $initial }},
            ids: {{ \Illuminate\Support\Js::from($ids) }},
            corporateAddress: {{ $corporateAddress ? 'true' : 'false' }},
            money: {{ $money ? 'true' : 'false' }},
            dateDmy: {{ $dateDmy ? 'true' : 'false' }},
            join: {{ \Illuminate\Support\Js::from($join) }},
            read(id) {
                const el = document.getElementById(id);
                const domVal = el ? el.value.trim() : '';
                let wireVal = '';
                if (this.$wire) {
                    const raw = this.$wire.get(id);
                    if (raw !== undefined && raw !== null) {
                        wireVal = String(raw).trim();
                    }
                }
                if (el && document.activeElement === el) {
                    return domVal;
                }
                return domVal !== '' ? domVal : wireVal;
            },
            moneyLen(raw) {
                if (raw === '') return 0;
                const n = Number(raw);
                if (Number.isNaN(n)) return Array.from(raw).length;
                return Array.from(n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })).length;
            },
            measure(values) {
                if (this.money) {
                    return this.moneyLen(values[0] ?? '');
                }
                if (this.dateDmy) {
                    return (values[0] ?? '') === '' ? 0 : 10;
                }
                if (this.corporateAddress) {
                    const [line1, line2, postcode, city, state, country] = values;
                    const parts = [line1, line2, [postcode, city].filter(Boolean).join(' '), state, country].filter(Boolean);
                    return Array.from(parts.join(', ')).length;
                }
                return Array.from(values.filter((value) => value !== '').join(this.join)).length;
            },
            sync(fromUser = false) {
                const next = this.measure(this.ids.map((id) => this.read(id)));
                // shortcut: empty DOM before Livewire hydration must not wipe server initial
                if (! fromUser && next === 0 && this.len > 0) {
                    return;
                }
                this.len = next;
            },
            init() {
                const run = () => this.sync(false);
                const fromUser = () => this.sync(true);
                this.$nextTick(run);
                setTimeout(run, 0);
                setTimeout(run, 100);
                this.ids.forEach((id) => {
                    document.getElementById(id)?.addEventListener('input', fromUser);
                    this.$wire?.$watch?.(id, fromUser);
                });
            },
        }"
        {{ $attributes }}
    >
        @if ($liveCount)
            <div class="mt-1 text-xs" :class="len > max ? 'text-amber-700' : 'text-slate-500'">
                <p class="tabular-nums">
                    <span x-text="len"></span>/{{ $max }} characters
                </p>
                <p x-cloak x-show="len > max">Too long for the form — shorten before printing (save still works).</p>
            </div>
        @else
            <p x-cloak x-show="len > max" class="mt-1 text-xs text-amber-700">
                Too long for the BIMBSEC form (<span x-text="len"></span>/{{ $max }}). You can still save — shorten before printing.
            </p>
            <p x-show="len <= max" class="mt-1 text-xs text-slate-500">
                BIMBSEC form fits {{ $max }} characters{{ $togetherNote }}.
            </p>
        @endif
    </div>
@endif
