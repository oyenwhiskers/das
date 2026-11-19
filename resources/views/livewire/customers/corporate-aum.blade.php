<section aria-label="Estimated Net Assets / AUM" class="rounded-lg border border-slate-200 bg-white">
    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 sm:px-5">
        <div>
            <h2 class="text-base font-semibold text-navy-900">Estimated Net Assets / AUM</h2>
            <p class="text-xs text-slate-500">Corporate asset under management bracket from the application form.</p>
        </div>
        @unless ($editing)
            <button type="button" wire:click="edit" class="text-sm font-medium text-navy-700">{{ $customer->corporateDetails?->aum_bracket ? 'Edit' : 'Add' }}</button>
        @endunless
    </div>
    @if ($editing)
        <form wire:submit="save" class="space-y-4 p-4 sm:p-5">
            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                <x-input-label for="aum_bracket" class="sm:pt-2" value="Estimated Net Asset / AUM" />
                <div>
                    <select id="aum_bracket" wire:model="aum_bracket" class="block w-full rounded-md border-slate-300 text-sm">
                        <option value="">Not Declared</option>
                        @foreach (\App\Livewire\Customers\CorporateAum::AUM_BRACKETS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('aum_bracket')" class="mt-1" />
                </div>
            </div>
            <div class="flex gap-3">
                <x-secondary-button type="button" wire:click="cancel" class="flex-1 justify-center">Cancel</x-secondary-button>
                <x-primary-button class="flex-1 justify-center">Save</x-primary-button>
            </div>
        </form>
    @else
        <p class="p-4 text-sm text-slate-800 sm:p-5">
            {{ \App\Livewire\Customers\CorporateAum::AUM_BRACKETS[$customer->corporateDetails?->aum_bracket] ?? 'Not declared' }}
        </p>
    @endif
</section>
