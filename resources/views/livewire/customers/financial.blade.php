<section aria-label="Financial Declaration" class="rounded-lg border border-slate-200 bg-white">
    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 sm:px-5">
        <div><h2 class="text-base font-semibold text-navy-900">Financial Declaration</h2><p class="text-xs text-slate-500">Monthly income selects a suggested personal annual range. Other ranges are declared separately.</p></div>
        @unless ($editing)<button type="button" wire:click="edit" class="text-sm font-medium text-navy-700">{{ $current ? 'Edit' : 'Add' }}</button>@endunless
    </div>
    @if ($editing)
        <form wire:submit="save" class="space-y-4 p-4 sm:p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><x-input-label for="monthly-income" value="Monthly Income (exact RM)" /><x-text-input id="monthly-income" type="number" min="0" step="0.01" wire:model.live.debounce.400ms="financial.monthly_income_rm" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('financial.monthly_income_rm')" class="mt-1" /></div>
                @foreach (['personal_annual_income_bracket' => ['Personal Gross Annual Income', \App\Livewire\Customers\Financial::PERSONAL_INCOME], 'joint_annual_income_bracket' => ['Joint Annual Income with Spouse', \App\Livewire\Customers\Financial::JOINT_INCOME], 'net_asset_bracket' => ['Net Assets', \App\Livewire\Customers\Financial::NET_ASSETS], 'portfolio_bracket' => ['Net Investment Portfolio', \App\Livewire\Customers\Financial::PORTFOLIO]] as $field => [$label, $options])
                    <div><x-input-label :for="$field" :value="$label" /><select id="{{ $field }}" wire:model="financial.{{ $field }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">Not Declared</option>@foreach ($options as $value => $option)<option value="{{ $value }}">{{ $option }}</option>@endforeach</select><x-input-error :messages="$errors->get('financial.'.$field)" class="mt-1" /></div>
                @endforeach
                <div><x-input-label for="portfolio-ownership" value="Portfolio Ownership" /><select id="portfolio-ownership" wire:model="financial.portfolio_ownership" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">Not Declared</option><option value="personal">Personal</option><option value="joint_with_spouse">Joint with Spouse</option></select><x-input-error :messages="$errors->get('financial.portfolio_ownership')" class="mt-1" /></div>
            </div>
            <p class="text-xs text-amber-800">The fifth portfolio range is preserved as printed. Its upper bound appears to contain a typo; confirm the intended value with the form owner.</p>
            <fieldset><legend class="mb-2 text-sm font-medium text-slate-800">Source of Wealth / Funds (select all that apply)</legend><div class="grid gap-2 sm:grid-cols-3">@foreach (\App\Livewire\Customers\Financial::WEALTH_SOURCES as $value => $label)<label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $value }}" wire:model.live="financial.wealth_sources" class="rounded border-slate-300 text-navy-700">{{ $label }}</label>@endforeach</div><x-input-error :messages="$errors->get('financial.wealth_sources')" class="mt-1" /></fieldset>
            @if (in_array('other', $financial['wealth_sources'] ?? [], true))<div><x-input-label for="wealth-other" value="Other source (specify)" /><x-text-input id="wealth-other" wire:model="financial.other_wealth_source" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('financial.other_wealth_source')" class="mt-1" /></div>@endif
            <div class="grid gap-4 sm:grid-cols-2"><div><x-input-label for="financial-date" value="Declaration Date" /><x-text-input id="financial-date" type="date" wire:model="financial.declared_on" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('financial.declared_on')" class="mt-1" /></div><div><x-input-label for="financial-source" value="Source" /><select id="financial-source" wire:model="financial.source" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="staff_entry">Staff data entry</option><option value="customer_attestation">Customer attestation</option></select><x-input-error :messages="$errors->get('financial.source')" class="mt-1" /></div></div>
            <div class="flex justify-end gap-3"><button type="button" wire:click="cancel" class="text-sm text-slate-600">Cancel</button><x-primary-button>Save Declaration</x-primary-button></div>
        </form>
    @else
        @if ($current)
            <dl class="grid gap-3 p-4 text-sm sm:grid-cols-2 sm:p-5">
                <div><dt class="text-slate-500">Monthly Income</dt><dd>{{ $current->monthly_income_rm !== null ? 'RM '.number_format((float) $current->monthly_income_rm, 2) : 'Not declared' }}</dd></div>
                <div><dt class="text-slate-500">Personal Annual Income</dt><dd>{{ \App\Livewire\Customers\Financial::PERSONAL_INCOME[$current->personal_annual_income_bracket] ?? 'Not declared' }}</dd></div>
                <div><dt class="text-slate-500">Joint Annual Income</dt><dd>{{ \App\Livewire\Customers\Financial::JOINT_INCOME[$current->joint_annual_income_bracket] ?? 'Not declared' }}</dd></div>
                <div><dt class="text-slate-500">Net Assets</dt><dd>{{ \App\Livewire\Customers\Financial::NET_ASSETS[$current->net_asset_bracket] ?? 'Not declared' }}</dd></div>
                <div><dt class="text-slate-500">Net Investment Portfolio</dt><dd>{{ \App\Livewire\Customers\Financial::PORTFOLIO[$current->portfolio_bracket] ?? 'Not declared' }}</dd></div>
                <div><dt class="text-slate-500">Portfolio Ownership</dt><dd>{{ $current->portfolio_ownership ? ucwords(str_replace('_', ' ', $current->portfolio_ownership)) : 'Not declared' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Source of Wealth / Funds</dt><dd>{{ collect($current->wealth_sources)->map(fn ($value) => $value === 'other' ? 'Other: '.$current->other_wealth_source : (\App\Livewire\Customers\Financial::WEALTH_SOURCES[$value] ?? $value))->implode(', ') ?: 'Not declared' }}</dd></div>
                <div class="sm:col-span-2 text-slate-500">Declared {{ $current->declared_on->format('d M Y') }} · {{ $current->source === 'customer_attestation' ? 'Customer attestation' : 'Staff data entry' }}</div>
            </dl>
        @else
            <p class="p-4 text-sm text-slate-600 sm:p-5">No financial declaration recorded.</p>
        @endif
        @if ($history->isNotEmpty())
            <details class="border-t border-slate-100 p-4 text-sm sm:p-5">
                <summary class="cursor-pointer text-navy-700">Previous declarations ({{ $history->count() }})</summary>
                <ul class="mt-3 space-y-4">
                    @foreach ($history as $previous)
                        <li class="rounded-md border border-slate-200 p-3">
                            <p class="font-medium">{{ $previous->declared_on->format('d M Y') }} · {{ $previous->source === 'customer_attestation' ? 'Customer attestation' : 'Staff data entry' }}</p>
                            <dl class="mt-2 grid gap-1 text-slate-600 sm:grid-cols-2">
                                <div>Monthly: {{ $previous->monthly_income_rm !== null ? 'RM '.number_format((float) $previous->monthly_income_rm, 2) : 'Not declared' }}</div>
                                <div>Personal annual: {{ \App\Livewire\Customers\Financial::PERSONAL_INCOME[$previous->personal_annual_income_bracket] ?? 'Not declared' }}</div>
                                <div>Joint annual: {{ \App\Livewire\Customers\Financial::JOINT_INCOME[$previous->joint_annual_income_bracket] ?? 'Not declared' }}</div>
                                <div>Net assets: {{ \App\Livewire\Customers\Financial::NET_ASSETS[$previous->net_asset_bracket] ?? 'Not declared' }}</div>
                                <div>Portfolio: {{ \App\Livewire\Customers\Financial::PORTFOLIO[$previous->portfolio_bracket] ?? 'Not declared' }}</div>
                                <div>Ownership: {{ $previous->portfolio_ownership ? ucwords(str_replace('_', ' ', $previous->portfolio_ownership)) : 'Not declared' }}</div>
                                <div class="sm:col-span-2">Wealth sources: {{ collect($previous->wealth_sources)->map(fn ($value) => $value === 'other' ? 'Other: '.$previous->other_wealth_source : (\App\Livewire\Customers\Financial::WEALTH_SOURCES[$value] ?? $value))->implode(', ') ?: 'Not declared' }}</div>
                            </dl>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endif
</section>
