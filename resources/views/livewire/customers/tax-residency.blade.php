<div class="space-y-4">
    <section aria-label="Tax Identification and Residency" class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-3 sm:px-5"><h2 class="text-base font-semibold text-navy-900">Tax Identification &amp; Residency</h2></div>
        <div class="space-y-4 px-4 py-4 sm:px-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-xs font-medium uppercase tracking-wide text-slate-500">Malaysian TIN</p><p class="mt-1 text-sm font-medium text-slate-900">{{ $taxIdentification?->maskedTin() ?? 'Not Provided' }}</p></div>
                <button type="button" wire:click="editTin" class="text-sm font-medium text-navy-700">Edit TIN</button>
            </div>
            @if ($editing === 'tin')
                <form wire:submit="saveTin" class="flex flex-wrap items-end gap-3 rounded-md bg-slate-50 p-3">
                    <div class="min-w-48 flex-1"><x-input-label for="malaysian-tin" value="Replacement Malaysian TIN" /><x-text-input id="malaysian-tin" type="password" autocomplete="off" wire:model="malaysianTin" class="mt-1 block w-full" /><p class="mt-1 text-xs text-slate-500">Leave blank to clear the recorded TIN.</p><x-input-error :messages="$errors->get('malaysianTin')" class="mt-1" /></div>
                    <button type="button" wire:click="cancel" class="text-sm text-slate-600">Cancel</button><x-primary-button>Save TIN</x-primary-button>
                </form>
            @endif
            <div class="border-t border-slate-100 pt-4">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="text-sm font-semibold text-slate-900">Foreign tax residencies</h3><button type="button" wire:click="addResidency" class="rounded-md bg-navy-700 px-3 py-2 text-sm font-medium text-white hover:bg-navy-800">+ Add Residency</button></div>
                <div class="mt-3 divide-y divide-slate-100">
                    @forelse ($residencies as $record)
                        <div wire:key="tax-residency-{{ $record->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                            <div><div class="flex items-center gap-2"><p class="font-medium text-slate-900">{{ $record->jurisdiction }}</p><span class="rounded-full px-2 py-0.5 text-xs {{ $record->ended_on ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700' }}">{{ $record->ended_on ? 'Historical' : 'Current' }}</span></div><p class="mt-1 text-slate-600">{{ $record->foreign_tin ? 'TIN '.$record->maskedTin() : 'Missing TIN reason '.$record->missing_tin_reason }} · Effective {{ $record->effective_date->format('d M Y') }}@if ($record->ended_on) · Ended {{ $record->ended_on->format('d M Y') }}@endif</p>@if ($record->missing_tin_reason === 'B')<p class="mt-1 text-slate-600">{{ $record->missing_tin_explanation }}</p>@endif</div>
                            @unless ($record->ended_on)<div class="flex gap-3"><button type="button" wire:click="editResidency({{ $record->id }})" class="font-medium text-navy-700">Edit</button><button type="button" wire:click="startEndResidency({{ $record->id }})" class="font-medium text-slate-600">End</button></div>@endunless
                        </div>
                    @empty
                        <p class="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600">No foreign tax residencies recorded. Add one when the customer declares it.</p>
                    @endforelse
                </div>
            </div>
            @if ($editing === 'residency')
                <div class="!mt-0 fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" x-on:keydown.escape.window="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }">
                    <div class="absolute inset-0 bg-slate-900/40" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" aria-hidden="true"></div>
                    <div role="dialog" aria-modal="true" aria-labelledby="residency-form-title" x-trap.noscroll="true" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
                            <h3 id="residency-form-title" class="font-semibold text-navy-900">{{ $selectedResidencyId ? 'Edit residency declaration' : 'Add residency declaration' }}</h3>
                            <button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" aria-label="Close residency form" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">&times;</button>
                        </div>
                        <form wire:submit="saveResidency" class="flex min-h-0 flex-col">
                            <div class="min-h-0 space-y-3 overflow-y-auto px-4 py-5 sm:px-6">
                                @if ($selectedResidencyId)<p class="text-xs text-slate-600">Saving a change preserves the previous declaration as historical. Enter a replacement TIN if it has changed.</p>@endif
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div><x-input-label for="jurisdiction" value="Country / Jurisdiction" /><x-text-input id="jurisdiction" wire:model="residency.jurisdiction" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('residency.jurisdiction')" class="mt-1" /></div>
                                    <div><x-input-label for="foreign-tin" value="Foreign TIN (if available)" /><x-text-input id="foreign-tin" type="password" autocomplete="off" wire:model="residency.foreign_tin" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('residency.foreign_tin')" class="mt-1" /></div>
                                    <div><x-input-label for="missing-reason" value="Missing TIN reason" /><select id="missing-reason" wire:model.live="residency.missing_tin_reason" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">None</option><option value="A">A — Jurisdiction does not issue TINs</option><option value="B">B — Unable to obtain TIN</option><option value="C">C — Disclosure not required</option></select><x-input-error :messages="$errors->get('residency.missing_tin_reason')" class="mt-1" /></div>
                                    <div><x-input-label for="effective-date" value="Declaration / effective date" /><x-text-input id="effective-date" type="date" wire:model="residency.effective_date" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('residency.effective_date')" class="mt-1" /></div>
                                    @if ($residency['missing_tin_reason'] === 'B')<div class="sm:col-span-2"><x-input-label for="missing-explanation" value="Reason B explanation" /><textarea id="missing-explanation" wire:model="residency.missing_tin_explanation" rows="2" class="mt-1 block w-full rounded-md border-slate-300 text-sm"></textarea><x-input-error :messages="$errors->get('residency.missing_tin_explanation')" class="mt-1" /></div>@endif
                                </div>
                            </div>
                            <div class="flex justify-end gap-3 border-t border-slate-200 px-4 py-3 sm:px-6"><button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" class="text-sm text-slate-600">Cancel</button><x-primary-button wire:loading.attr="disabled" wire:target="saveResidency">Save Residency</x-primary-button></div>
                        </form>
                    </div>
                </div>
            @endif
            @if ($editing === 'end')
                <form wire:submit="endResidency" class="flex flex-wrap items-end gap-3 rounded-md border border-slate-200 bg-slate-50 p-4"><div><x-input-label for="end-date" value="Residency end date" /><x-text-input id="end-date" type="date" wire:model="endDate" class="mt-1 block" /><x-input-error :messages="$errors->get('endDate')" class="mt-1" /></div><button type="button" wire:click="cancel" class="text-sm text-slate-600">Cancel</button><x-primary-button>End Residency</x-primary-button></form>
            @endif
        </div>
    </section>

    <section aria-label="FATCA Self-Certification" class="rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5"><h2 class="text-base font-semibold text-navy-900">FATCA Self-Certification</h2><button type="button" wire:click="editFatca" class="text-sm font-medium text-navy-700">Edit</button></div>
        <div class="px-4 py-4 sm:px-5">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['us_person_for_tax' => 'US Person for tax purposes', 'us_green_card_holder' => 'US Permanent Resident Card holder', 'us_resident' => 'US Resident'] as $field => $label)
                    <div><dt class="text-slate-500">{{ $label }}</dt><dd class="font-medium text-slate-900">{{ $currentFatca?->{$field} === null ? 'Not Declared' : ($currentFatca->{$field} ? 'Yes' : 'No') }}</dd></div>
                @endforeach
                <div><dt class="text-slate-500">Derived classification</dt><dd class="font-medium text-slate-900">{{ $currentFatca?->classification() ?? 'Undetermined' }}</dd></div>
                <div><dt class="text-slate-500">Explicitly selected classification</dt><dd class="font-medium text-slate-900">{{ match ($currentFatca?->selected_classification) { 'us_person' => 'US Person', 'non_us_person' => 'Non US Person', default => 'Not Declared' } }}</dd></div>
                <div><dt class="text-slate-500">Completeness</dt><dd class="font-medium text-slate-900">{{ $currentFatca?->isComplete() ? 'Complete' : 'Incomplete' }}</dd></div>
                <div><dt class="text-slate-500">Declaration date</dt><dd class="font-medium text-slate-900">{{ $currentFatca?->declared_on?->format('d M Y') ?? 'Not Provided' }}</dd></div>
                @if ($currentFatca)
                    <div class="sm:col-span-2 lg:col-span-3">
                        <dt class="text-slate-500">Source</dt>
                        <dd class="text-slate-900">
                            @if ($currentFatca->source === 'system_default')
                                System default
                            @elseif ($currentFatca->source === 'customer_attestation')
                                Customer attestation · Recorded by {{ $currentFatca->recorder?->name ?? 'Former user' }}
                            @else
                                Staff data entry · Recorded by {{ $currentFatca->recorder?->name ?? 'Former user' }}
                            @endif
                        </dd>
                    </div>
                @endif
            </dl>
            @if ($currentFatca?->classification() === 'US Person')<p class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">W-9 may be required. W-9 processing is handled separately.</p>@endif
            @if ($editing === 'fatca')
                <form wire:submit="saveFatca" class="mt-4 space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs text-slate-600">A saved declaration replaces the current record and keeps the previous one in history. Select customer attestation only when the customer supplied these answers.</p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach (['us_person_for_tax' => 'US Person for tax purposes', 'us_green_card_holder' => 'US Permanent Resident Card holder', 'us_resident' => 'US Resident'] as $field => $label)
                            <div><x-input-label for="fatca-{{ $field }}" :value="$label" /><select id="fatca-{{ $field }}" wire:model="fatca.{{ $field }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">Not Declared</option><option value="yes">Yes</option><option value="no">No</option></select><x-input-error :messages="$errors->get('fatca.'.$field)" class="mt-1" /></div>
                        @endforeach
                        <div><x-input-label for="fatca-selected" value="Classification selected on source" /><select id="fatca-selected" wire:model="fatca.selected_classification" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">Not Declared</option><option value="non_us_person">Non US Person</option><option value="us_person">US Person</option></select><x-input-error :messages="$errors->get('fatca.selected_classification')" class="mt-1" /></div>
                        <div><x-input-label for="fatca-date" value="Declaration date" /><x-text-input id="fatca-date" type="date" wire:model="fatca.declared_on" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('fatca.declared_on')" class="mt-1" /></div>
                        <div><x-input-label for="fatca-source" value="Source" /><select id="fatca-source" wire:model="fatca.source" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="staff_entry">Staff data entry</option><option value="customer_attestation">Customer attestation</option></select><x-input-error :messages="$errors->get('fatca.source')" class="mt-1" /></div>
                    </div>
                    <div class="flex justify-end gap-3"><button type="button" wire:click="cancel" class="text-sm text-slate-600">Cancel</button><x-primary-button>Save Declaration</x-primary-button></div>
                </form>
            @endif
        </div>
    </section>
</div>
