<div class="space-y-4">
    <section aria-label="Investment Profile" class="rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
            <div><h2 class="text-base font-semibold text-navy-900">Investment Profile</h2><p class="text-xs text-slate-500">Customer-declared information; no independent assessment recorded.</p></div>
            @unless ($editingProfile)<button type="button" wire:click="editProfile" class="text-sm font-medium text-navy-700 hover:text-navy-900">Edit</button>@endunless
        </div>
        @if ($editingProfile)
            <form wire:submit="saveProfile" class="space-y-4 px-4 py-4 sm:px-5">
                <fieldset><legend class="mb-2 text-sm font-medium text-slate-800">Investment Objectives</legend><div class="flex flex-wrap gap-x-5 gap-y-2">
                    @foreach (\App\Livewire\Customers\Investment::OBJECTIVES as $value => $label)
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" value="{{ $value }}" wire:model="profile.objectives" class="rounded border-slate-300 text-navy-700">{{ $label }}</label>
                    @endforeach
                </div><x-input-error :messages="$errors->get('profile.objectives')" class="mt-1" /></fieldset>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><x-input-label for="investment-risk" value="Risk Tolerance" /><select id="investment-risk" wire:model="profile.risk_tolerance" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">Not provided</option><option value="conservative">Conservative</option><option value="moderate">Moderate</option><option value="aggressive">Aggressive</option></select><x-input-error :messages="$errors->get('profile.risk_tolerance')" class="mt-1" /></div>
                    <div><x-input-label for="investment-knowledge" value="Trading Knowledge" /><select id="investment-knowledge" wire:model="profile.trading_knowledge" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">Not provided</option><option value="beginner">Beginner</option><option value="intermediate">Intermediate</option><option value="advanced">Advanced</option></select><x-input-error :messages="$errors->get('profile.trading_knowledge')" class="mt-1" /></div>
                </div>
                <fieldset><legend class="mb-2 text-sm font-medium text-slate-800">Investment Products</legend><div class="flex flex-wrap gap-x-5 gap-y-2">
                    @foreach (\App\Livewire\Customers\Investment::PRODUCTS as $value => $label)
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" value="{{ $value }}" wire:model.live="profile.products" class="rounded border-slate-300 text-navy-700">{{ $label }}</label>
                    @endforeach
                </div><x-input-error :messages="$errors->get('profile.products')" class="mt-1" /></fieldset>
                @if (in_array('other', $profile['products'] ?? [], true))
                    <div><x-input-label for="investment-other" value="Other product details" /><x-text-input id="investment-other" wire:model="profile.other_product_details" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('profile.other_product_details')" class="mt-1" /></div>
                @endif
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-3"><button type="button" wire:click="cancelProfile" class="text-sm text-slate-600">Cancel</button><x-primary-button>Save Profile</x-primary-button></div>
            </form>
        @else
            <dl class="grid gap-x-6 gap-y-3 px-4 py-4 text-sm sm:grid-cols-2 sm:px-5">
                <div><dt class="text-slate-500">Investment Objectives</dt><dd class="mt-1 font-medium text-slate-800">{{ $currentProfile && $currentProfile->objectives ? collect($currentProfile->objectives)->map(fn ($value) => \App\Livewire\Customers\Investment::OBJECTIVES[$value] ?? $value)->implode(' · ') : 'Not provided' }}</dd></div>
                <div><dt class="text-slate-500">Risk Tolerance</dt><dd class="mt-1 font-medium text-slate-800">{{ $currentProfile?->risk_tolerance ? ucfirst($currentProfile->risk_tolerance) : 'Not provided' }}</dd></div>
                <div><dt class="text-slate-500">Trading Knowledge</dt><dd class="mt-1 font-medium text-slate-800">{{ $currentProfile?->trading_knowledge ? ucfirst($currentProfile->trading_knowledge) : 'Not provided' }}</dd></div>
                <div><dt class="text-slate-500">Investment Products</dt><dd class="mt-1 font-medium text-slate-800">{{ $currentProfile && $currentProfile->products ? collect($currentProfile->products)->map(fn ($value) => $value === 'other' ? 'Other: '.$currentProfile->other_product_details : (\App\Livewire\Customers\Investment::PRODUCTS[$value] ?? $value))->implode(' · ') : 'Not provided' }}</dd></div>
                <div><dt class="text-slate-500">Last Updated</dt><dd class="mt-1 font-medium text-slate-800">{{ $currentProfile?->declared_at?->format('d M Y, h:i A') ?? 'Not provided' }}</dd></div>
            </dl>
        @endif
    </section>

    <section aria-label="Other Brokerage Declaration" class="rounded-lg border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 sm:px-5"><div><h2 class="text-base font-semibold text-navy-900">Other Broker Accounts Declaration</h2><p class="text-xs text-slate-500">An empty account list does not mean the customer declared No.</p></div>@unless ($editingBrokerageDeclaration)<button type="button" wire:click="editBrokerageDeclaration" class="text-sm font-medium text-navy-700">{{ $currentBrokerageDeclaration ? 'Edit' : 'Add' }}</button>@endunless</div>
        @if ($editingBrokerageDeclaration)
            <form wire:submit="saveBrokerageDeclaration" class="grid gap-4 p-4 sm:grid-cols-3 sm:p-5">
                <div><x-input-label for="broker-answer" value="Has Trading/Margin Account with Other Brokers?" /><select id="broker-answer" wire:model="brokerageDeclaration.answer" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="not_declared">Not Declared</option><option value="yes">Yes</option><option value="no">No</option></select><x-input-error :messages="$errors->get('brokerageDeclaration.answer')" class="mt-1" /></div>
                <div><x-input-label for="broker-declared-on" value="Declaration Date" /><x-text-input id="broker-declared-on" type="date" wire:model="brokerageDeclaration.declared_on" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('brokerageDeclaration.declared_on')" class="mt-1" /></div>
                <div><x-input-label for="broker-source" value="Source" /><select id="broker-source" wire:model="brokerageDeclaration.source" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="staff_entry">Staff data entry</option><option value="customer_attestation">Customer attestation</option></select><x-input-error :messages="$errors->get('brokerageDeclaration.source')" class="mt-1" /></div>
                <div class="flex justify-end gap-3 sm:col-span-3"><button type="button" wire:click="cancelBrokerageDeclaration" class="text-sm text-slate-600">Cancel</button><x-primary-button>Save Declaration</x-primary-button></div>
            </form>
        @else
            <p class="p-4 text-sm text-slate-700 sm:p-5">{{ $currentBrokerageDeclaration ? ucfirst(str_replace('_', ' ', $currentBrokerageDeclaration->answer)) : 'Not Declared' }}@if ($currentBrokerageDeclaration) · {{ $currentBrokerageDeclaration->declared_on->format('d M Y') }}@endif</p>
            @if ($brokerageDeclarationHistory->isNotEmpty())<details class="border-t border-slate-100 p-4 text-sm sm:p-5"><summary class="cursor-pointer text-navy-700">Previous answers ({{ $brokerageDeclarationHistory->count() }})</summary><ul class="mt-2 space-y-1">@foreach ($brokerageDeclarationHistory as $previous)<li>{{ ucfirst(str_replace('_', ' ', $previous->answer)) }} · {{ $previous->declared_on->format('d M Y') }}</li>@endforeach</ul></details>@endif
        @endif
    </section>
    <section aria-label="Brokerage Accounts" class="rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5"><h2 class="text-base font-semibold text-navy-900">Brokerage Accounts</h2><button type="button" wire:click="addBrokerage" class="rounded-md bg-navy-700 px-3 py-2 text-sm font-medium text-white hover:bg-navy-800">+ Add Brokerage Account</button></div>
        <div class="divide-y divide-slate-100">
            @forelse ($accounts as $account)
                <article wire:key="brokerage-{{ $account->id }}" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h3 class="font-medium text-slate-900">{{ $account->broker_name }}</h3><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $account->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span></div><p class="mt-1 text-sm text-slate-600">{{ ucfirst($account->account_type) }} · {{ $account->branch_address ?: 'Branch / address not provided' }}</p></div>
                    <div class="flex flex-wrap gap-3 text-sm"><button type="button" wire:click="viewBrokerage({{ $account->id }})" class="font-medium text-navy-700">View</button><button type="button" wire:click="editBrokerage({{ $account->id }})" class="font-medium text-navy-700">Edit</button>@if ($account->is_active)<button type="button" x-on:click="if (await confirmAction({ title: 'Deactivate brokerage account?', text: 'The account will remain in history.', confirmButtonText: 'Deactivate' })) $wire.setBrokerageActive({{ $account->id }}, false)" class="font-medium text-slate-600">Deactivate</button>@else<button type="button" wire:click="setBrokerageActive({{ $account->id }}, true)" class="font-medium text-navy-700">Reactivate</button>@endif</div>
                </article>
            @empty
                <p class="px-4 py-5 text-sm text-slate-600 sm:px-5">No brokerage accounts recorded yet. Other accounts may exist; add any the customer declares.</p>
            @endforelse
        </div>
        @if ($brokerageMode === 'edit')
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" x-on:keydown.escape.window="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancelBrokerage() }">
                <div class="absolute inset-0 bg-slate-900/40" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancelBrokerage() }" aria-hidden="true"></div>
                <div role="dialog" aria-modal="true" aria-labelledby="brokerage-form-title" x-trap.noscroll="true" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
                        <h3 id="brokerage-form-title" class="font-semibold text-navy-900">{{ $selectedBrokerageId ? 'Edit Brokerage Account' : 'Add Brokerage Account' }}</h3>
                        <button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancelBrokerage() }" aria-label="Close brokerage form" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">&times;</button>
                    </div>
                    <form wire:submit="saveBrokerage" class="flex min-h-0 flex-col">
                        <div class="grid min-h-0 gap-4 overflow-y-auto px-4 py-5 sm:grid-cols-2 sm:px-6">
                            <div><x-input-label for="broker-name" value="Broker Name" /><x-text-input id="broker-name" wire:model="brokerage.broker_name" class="mt-1 block w-full" required /><x-input-error :messages="$errors->get('brokerage.broker_name')" class="mt-1" /></div>
                            <div><x-input-label for="broker-type" value="Account Type" /><select id="broker-type" wire:model="brokerage.account_type" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="trading">Trading</option><option value="margin">Margin</option></select><x-input-error :messages="$errors->get('brokerage.account_type')" class="mt-1" /></div>
                            <div class="sm:col-span-2"><x-input-label for="broker-branch" value="Branch / Address (optional)" /><x-text-input id="broker-branch" wire:model="brokerage.branch_address" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('brokerage.branch_address')" class="mt-1" /></div>
                            <div class="sm:col-span-2"><label class="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="confirmDuplicate" class="mt-0.5 rounded border-slate-300 text-navy-700">This is a separate account if the broker, type and branch match an existing record.</label><x-input-error :messages="$errors->get('confirmDuplicate')" class="mt-1" /></div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-slate-200 px-4 py-3 sm:px-6"><button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancelBrokerage() }" class="text-sm text-slate-600">Cancel</button><x-primary-button wire:loading.attr="disabled" wire:target="saveBrokerage">Save Account</x-primary-button></div>
                    </form>
                </div>
            </div>
        @endif
        @if ($brokerageMode === 'view' && $selectedBrokerage)
            <div class="border-t border-slate-100 bg-slate-50 px-4 py-4 text-sm sm:px-5"><h3 class="font-semibold text-slate-900">{{ $selectedBrokerage->broker_name }}</h3><p class="mt-1 text-slate-600">{{ ucfirst($selectedBrokerage->account_type) }} account · {{ $selectedBrokerage->is_active ? 'Active' : 'Inactive' }}</p><p class="mt-1 text-slate-600">Branch / Address: {{ $selectedBrokerage->branch_address ?: 'Not provided' }}</p><div class="mt-3 flex gap-3"><button type="button" wire:click="cancelBrokerage" class="text-navy-700">Close</button><button type="button" wire:click="editBrokerage({{ $selectedBrokerage->id }})" class="text-navy-700">Edit</button></div></div>
        @endif
    </section>
</div>
