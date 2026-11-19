<section aria-label="Banking Information" class="rounded-lg border border-slate-200 bg-white">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
        <div><h2 class="text-base font-semibold text-navy-900">Banking Information</h2><p class="text-xs text-slate-500">Primary does not confirm ownership or payment authorization.</p></div>
        <button type="button" wire:click="add" class="text-sm font-medium text-navy-700">Add</button>
    </div>
    <div class="grid gap-3 px-4 py-4 sm:px-5 lg:grid-cols-2">
        @forelse ($accounts as $account)
            <article wire:key="bank-account-{{ $account->id }}" class="rounded-lg border border-slate-200 p-4 {{ $account->is_active ? 'bg-white' : 'bg-slate-50' }}">
                <div class="flex flex-wrap items-start justify-between gap-2"><h3 class="font-semibold text-slate-900">{{ $account->bank_name }}</h3><div class="flex gap-2">@if ($account->is_primary)<span class="rounded-full bg-navy-100 px-2 py-0.5 text-xs font-medium text-navy-800">Primary</span>@endif @unless ($account->is_active)<span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700">Inactive</span>@endunless</div></div>
                <p class="mt-1 text-sm text-slate-600">{{ trim(ucfirst((string) $account->account_type).($account->ownership ? ' · '.ucfirst($account->ownership).' account' : '')) }}</p>
                <dl class="mt-3 space-y-1 text-sm"><div class="flex flex-wrap gap-1"><dt class="text-slate-500">Account No:</dt><dd class="font-medium text-slate-800">{{ $account->maskedAccountNumber() }}</dd></div><div class="flex flex-wrap gap-1"><dt class="text-slate-500">Account Holder:</dt><dd class="font-medium text-slate-800">{{ $account->account_holder_name }}</dd></div><div><dt class="sr-only">Country</dt><dd class="text-slate-600">{{ $account->bank_country }}</dd></div></dl>
                <div class="mt-4 flex flex-wrap gap-3 border-t border-slate-200 pt-3 text-sm"><button type="button" wire:click="view({{ $account->id }})" class="font-medium text-navy-700">View</button><button type="button" wire:click="edit({{ $account->id }})" class="font-medium text-navy-700">Edit</button>@if ($account->is_active)<button type="button" x-on:click="if (await confirmAction({ title: 'Deactivate bank account?', text: 'The account will remain in history.', confirmButtonText: 'Deactivate' })) $wire.setActive({{ $account->id }}, false)" class="font-medium text-slate-600">Deactivate</button>@else<button type="button" wire:click="setActive({{ $account->id }}, true)" class="font-medium text-navy-700">Reactivate</button>@endif</div>
            </article>
        @empty
            <div class="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600 lg:col-span-2">No bank accounts recorded yet. Add an account for future documents.</div>
        @endforelse
    </div>
    @if ($mode === 'edit')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.cancel()"><div class="absolute inset-0 bg-slate-900/40" wire:click="cancel"></div><div role="dialog" aria-modal="true" aria-labelledby="bank-form-title" x-trap.noscroll="true" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
            <div class="border-b border-slate-200 px-4 py-3 sm:px-6"><h3 id="bank-form-title" class="font-semibold text-navy-900">{{ $selectedId ? 'Edit Bank Account' : 'Add Bank Account' }}</h3></div>
            <form wire:submit="save" class="flex min-h-0 flex-col"><div class="min-h-0 space-y-5 overflow-y-auto px-4 py-5 sm:px-6">
                <fieldset><legend class="mb-3 text-sm font-semibold text-slate-800">Bank details</legend><div class="grid gap-4">
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="bank-name" class="sm:pt-2" value="Bank Name" />
                        <div>
                            <x-text-input id="bank-name" wire:model="form.bank_name" class="block w-full" required />
                            <x-input-error :messages="$errors->get('form.bank_name')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="bank-country" class="sm:pt-2" value="Bank Country" />
                        <div>
                            <x-text-input id="bank-country" wire:model="form.bank_country" class="block w-full" required />
                            <x-input-error :messages="$errors->get('form.bank_country')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="bank-branch" class="sm:pt-2" value="Branch Name / Address" />
                        <div>
                            <x-text-input id="bank-branch" wire:model="form.branch_address" class="block w-full" />
                            <x-input-error :messages="$errors->get('form.branch_address')" class="mt-1" />
                        </div>
                    </div>
                </div></fieldset>
                <fieldset><legend class="mb-3 text-sm font-semibold text-slate-800">Account details</legend><div class="grid gap-4">
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="bank-type" class="sm:pt-2" value="Account Type" />
                        <div>
                            <select id="bank-type" wire:model="form.account_type" class="block w-full rounded-md border-slate-300 text-sm"><option value="">Not provided</option><option value="SAVINGS">Savings</option><option value="CURRENT">Current</option><option value="OTHER">Other</option></select>
                            <x-input-error :messages="$errors->get('form.account_type')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="bank-ownership" class="sm:pt-2" value="Ownership" />
                        <div>
                            <select id="bank-ownership" wire:model="form.ownership" class="block w-full rounded-md border-slate-300 text-sm"><option value="sole">Sole</option><option value="joint">Joint</option><option value="other">Other</option></select>
                            <x-input-error :messages="$errors->get('form.ownership')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="bank-holder" class="sm:pt-2" value="Account Holder Name" />
                        <div>
                            <x-text-input id="bank-holder" wire:model="form.account_holder_name" class="block w-full" required />
                            <x-input-error :messages="$errors->get('form.account_holder_name')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="bank-number" class="sm:pt-2" value="Account Number" />
                        <div>
                            <x-text-input id="bank-number" type="password" autocomplete="off" wire:model="form.account_number" class="block w-full" />
                            @if ($selectedId)<p class="mt-1 text-xs text-slate-500">Leave blank to keep the current number.</p>@endif
                            <x-input-error :messages="$errors->get('form.account_number')" class="mt-1" />
                        </div>
                    </div>
                    @if ($selectedId)
                        <div class="sm:pl-[calc(9rem+0.75rem)]">
                            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="confirmNumberChange" class="rounded border-slate-300 text-navy-700">Confirm replacement of the account number</label>
                            <x-input-error :messages="$errors->get('confirmNumberChange')" class="mt-1" />
                        </div>
                    @endif
                </div></fieldset>
                @if (! $selected || $selected->is_active)
                    <div class="sm:pl-[calc(9rem+0.75rem)]">
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="form.is_primary" class="rounded border-slate-300 text-navy-700">Primary active account</label>
                    </div>
                @endif
                <x-input-error :messages="$errors->get('form.is_primary')" class="mt-1" />
            </div><div class="flex gap-3 border-t border-slate-200 px-4 py-3 sm:px-6"><x-secondary-button type="button" wire:click="cancel" class="flex-1 justify-center">Cancel</x-secondary-button><x-primary-button class="flex-1 justify-center" wire:loading.attr="disabled" wire:target="save">Save</x-primary-button></div></form>
        </div></div>
    @elseif ($mode === 'view' && $selected)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.cancel()"><div class="absolute inset-0 bg-slate-900/40" wire:click="cancel"></div><div role="dialog" aria-modal="true" aria-labelledby="bank-details-title" x-trap.noscroll="true" class="relative w-full max-w-lg rounded-lg bg-white shadow-xl"><div class="border-b border-slate-200 px-4 py-3 sm:px-6"><h3 id="bank-details-title" class="font-semibold text-navy-900">{{ $selected->bank_name }}</h3></div><dl class="grid gap-3 p-4 text-sm sm:grid-cols-2 sm:p-6">@foreach (['bank_country' => 'Country', 'branch_address' => 'Branch / Address', 'account_type' => 'Account Type', 'account_holder_name' => 'Account Holder', 'ownership' => 'Ownership'] as $field => $label)<div><dt class="text-slate-500">{{ $label }}</dt><dd class="font-medium text-slate-900">{{ $selected->{$field} ?: 'Not provided' }}</dd></div>@endforeach<div><dt class="text-slate-500">Account Number</dt><dd class="font-medium text-slate-900">{{ $selected->maskedAccountNumber() }}</dd></div><div><dt class="text-slate-500">Status</dt><dd class="font-medium text-slate-900">{{ $selected->is_active ? 'Active' : 'Inactive' }}</dd></div></dl><div class="flex justify-end gap-3 border-t border-slate-200 px-4 py-3 sm:px-6"><button type="button" wire:click="cancel" class="text-sm text-slate-600">Close</button><x-primary-button type="button" wire:click="edit({{ $selected->id }})">Edit Account</x-primary-button></div></div></div>
    @endif
</section>
