<div class="space-y-4">
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-3 sm:px-5"><h2 class="text-base font-semibold text-navy-900">Compliance Declarations</h2><p class="text-xs text-slate-500">Customer statements only; these are not independently verified findings.</p></div>
        <div class="divide-y divide-slate-100">
            @foreach ($declarationKinds as $kind => $label)
                @php $current = $declarations->get($kind); @endphp
                <div class="px-4 py-4 sm:px-5">
                    <div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="text-sm font-semibold text-slate-800">{{ $label }}</h3><p class="text-xs text-slate-500">{{ match ($kind) { 'pep' => 'Is the customer, a family member, or close associate currently holding, previously holding, seeking, or being considered for a prominent public position?', 'bssb_staff' => 'Are you related to any Directors or employee or Dealer’s Representative of BSSB?', 'bssb_client' => 'Are you related to any client of BSSB?', 'bankruptcy' => 'Has the customer been adjudicated or declared bankrupt?', default => 'Is the customer aware of a bankruptcy petition or proceedings against them?' } }}</p></div><button type="button" wire:click="editDeclaration('{{ $kind }}')" class="text-sm font-medium text-navy-700 hover:text-navy-900">Edit</button></div>
                    @if ($editingKind === $kind)
                        <form wire:submit="saveDeclaration" class="mt-4 space-y-3">
                            @if (in_array($kind, ['bssb_staff', 'bssb_client'], true))
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Answer" />
                                    <div>
                                        <select wire:model.live="declaration.answer" class="block w-full rounded-md border-slate-300 text-sm"><option value="no">No</option><option value="yes">Yes</option></select>
                                        <x-input-error :messages="$errors->get('declaration.answer')" class="mt-1" />
                                    </div>
                                </div>
                                @if (($declaration['answer'] ?? '') === 'yes')
                                    <div class="space-y-3">
                                        @foreach ($bssbParties as $index => $party)
                                            <div class="space-y-3 rounded-md border border-slate-200 p-3">
                                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                                    <x-input-label class="sm:pt-2" value="Name" />
                                                    <x-text-input wire:model="bssbParties.{{ $index }}.name" class="block w-full" />
                                                </div>
                                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                                    <x-input-label class="sm:pt-2" value="Relationship" />
                                                    <x-text-input wire:model="bssbParties.{{ $index }}.relationship" class="block w-full" />
                                                </div>
                                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                                    <x-input-label class="sm:pt-2" value="NRIC / Reg. No." />
                                                    <x-text-input wire:model="bssbParties.{{ $index }}.identification_number" class="block w-full" />
                                                </div>
                                                <button type="button" wire:click="removeBssbPartyRow({{ $index }})" class="text-sm text-slate-600 sm:ms-[calc(9rem+0.75rem)]">Remove row</button>
                                            </div>
                                        @endforeach
                                        <button type="button" wire:click="addBssbPartyRow" class="text-sm font-medium text-navy-700">Add related person</button>
                                    </div>
                                @endif
                            @else
                                <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                        <x-input-label class="sm:pt-2" value="Answer" />
                                        <div>
                                            <select wire:model="declaration.answer" class="block w-full rounded-md border-slate-300 text-sm"><option value="not_declared">Not Declared</option><option value="yes">Yes</option><option value="no">No</option></select>
                                            <x-input-error :messages="$errors->get('declaration.answer')" class="mt-1" />
                                        </div>
                                    </div>
                                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                        <x-input-label class="sm:pt-2" value="Declaration Date" />
                                        <div>
                                            <x-text-input type="date" wire:model="declaration.declared_on" class="block w-full" />
                                            <x-input-error :messages="$errors->get('declaration.declared_on')" class="mt-1" />
                                        </div>
                                    </div>
                                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                        <x-input-label class="sm:pt-2" value="Declaration Source" />
                                        <div>
                                            <select wire:model.live="declaration.source" class="block w-full rounded-md border-slate-300 text-sm"><option value="staff_recorded">Staff recorded; customer source not established</option><option value="customer_verbal">Customer verbal statement</option><option value="customer_written">Customer written statement</option></select>
                                            <x-input-error :messages="$errors->get('declaration.source')" class="mt-1" />
                                        </div>
                                    </div>
                                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                        <x-input-label class="sm:pt-2" value="Confirmation Status" />
                                        <div>
                                            <select wire:model="declaration.confirmation_status" class="block w-full rounded-md border-slate-300 text-sm"><option value="unconfirmed">Unconfirmed</option><option value="customer_confirmed">Customer confirmed</option></select>
                                            <x-input-error :messages="$errors->get('declaration.confirmation_status')" class="mt-1" />
                                        </div>
                                    </div>
                                    <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                        <x-input-label class="sm:pt-2" value="Remarks (optional)" />
                                        <div>
                                            <textarea wire:model="declaration.remarks" rows="2" class="block w-full rounded-md border-slate-300 text-sm"></textarea>
                                            <x-input-error :messages="$errors->get('declaration.remarks')" class="mt-1" />
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <div class="flex gap-3"><x-secondary-button type="button" wire:click="cancel" class="flex-1 justify-center">Cancel</x-secondary-button><x-primary-button class="flex-1 justify-center">Save</x-primary-button></div>
                        </form>
                    @else
                        <dl @class([
                            'mt-3 grid gap-x-6 gap-y-2 text-sm',
                            'sm:grid-cols-3' => ! in_array($kind, ['bssb_staff', 'bssb_client'], true),
                        ])>
                            <div class="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-2">
                                <dt class="text-slate-500">Answer</dt>
                                <dd class="font-medium text-slate-900">{{ match ($current?->answer) { 'yes' => 'Yes', 'no' => 'No', default => in_array($kind, ['bssb_staff', 'bssb_client'], true) ? 'No (default)' : 'Not Declared' } }}</dd>
                            </div>
                            @unless (in_array($kind, ['bssb_staff', 'bssb_client'], true))
                                <div class="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-2">
                                    <dt class="text-slate-500">Declaration Date</dt>
                                    <dd class="font-medium text-slate-900">{{ $current?->declared_on?->format('d M Y') ?? 'Not recorded' }}</dd>
                                </div>
                                <div class="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-2">
                                    <dt class="text-slate-500">Confirmation</dt>
                                    <dd class="font-medium text-slate-900">{{ $current?->confirmation_status === 'customer_confirmed' ? 'Customer confirmed' : 'Unconfirmed' }}</dd>
                                </div>
                            @endunless
                        </dl>
                        @if ($current && ! in_array($kind, ['bssb_staff', 'bssb_client'], true))<p class="mt-2 text-xs text-slate-500">Source: {{ str_replace('_', ' ', ucfirst($current->source)) }} · Recorded by {{ $current->recorder?->name ?? 'Former user' }} · Updated {{ $current->updated_at?->format('d M Y, h:i A') }}</p>@endif
                        @if ($current?->remarks)<p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $current->remarks }}</p>@endif
                        @if (in_array($kind, ['bssb_staff', 'bssb_client'], true) && ($current?->answer === 'yes'))
                            @php $partyKind = $kind === 'bssb_staff' ? 'staff' : 'client'; @endphp
                            <ul class="mt-2 space-y-1 text-sm text-slate-700">
                                @forelse ($bssbPartiesByKind->get($partyKind, collect()) as $party)
                                    <li>{{ $party->name }} · {{ $party->relationship ?: 'Relationship not provided' }} · {{ $party->identification_number ?: 'ID not provided' }}</li>
                                @empty
                                    <li class="text-slate-500">No related persons listed.</li>
                                @endforelse
                            </ul>
                        @endif
                        @if ($declarationHistory->has($kind))<details class="mt-2 text-xs text-slate-600"><summary class="cursor-pointer text-navy-700">Previous declarations ({{ $declarationHistory->get($kind)->count() }})</summary><ul class="mt-2 space-y-1">@foreach ($declarationHistory->get($kind) as $previous)<li>{{ $previous->declared_on->format('d M Y') }} · {{ match ($previous->answer) { 'yes' => 'Yes', 'no' => 'No', default => 'Not Declared' } }} · {{ $previous->confirmation_status === 'customer_confirmed' ? 'Customer confirmed' : 'Unconfirmed' }}</li>@endforeach</ul></details>@endif
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 sm:px-5"><div><h2 class="text-base font-semibold text-navy-900">PEP-Related Persons</h2><p class="text-xs text-slate-500">Associations are recorded separately from family relationships and customer declarations.</p></div><button type="button" wire:click="addAssociation" class="text-sm font-medium text-navy-700 hover:text-navy-900">Add Related Person</button></div>
        @if ($associationMode === 'edit')
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" x-on:keydown.escape.window="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }">
                <div class="absolute inset-0 bg-slate-900/40" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" aria-hidden="true"></div>
                <div role="dialog" aria-modal="true" aria-labelledby="association-form-title" x-trap.noscroll="true" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
                        <h3 id="association-form-title" class="font-semibold text-navy-900">{{ $selectedAssociationId ? 'Edit PEP-Related Person' : 'Add PEP-Related Person' }}</h3>
                        <button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" aria-label="Close association form" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">&times;</button>
                    </div>
                    <form wire:submit="saveAssociation" class="flex min-h-0 flex-col">
                        <div class="grid min-h-0 gap-x-6 gap-y-3 overflow-y-auto px-4 py-5 sm:grid-cols-2 sm:px-6">
                            @if (! $selectedAssociationId)
                                <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Person source" />
                                    <div>
                                        <select wire:model.live="association.source" class="block w-full rounded-md border-slate-300 text-sm"><option value="new">New close associate or other person</option><option value="relationship">Existing family relationship</option><option value="self">Customer directly</option></select>
                                        <x-input-error :messages="$errors->get('association.source')" class="mt-1" />
                                    </div>
                                </div>
                            @else
                                <p class="text-sm text-slate-700 sm:col-span-2">Editing association for <span class="font-medium">{{ $selected?->displayName() }}</span>. Family member identity is edited in Relationships.</p>
                            @endif
                            @if (($association['source'] ?? '') === 'relationship')
                                <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Related person" />
                                    <div>
                                        <select wire:model="association.relationship_id" @disabled($selectedAssociationId) class="block w-full rounded-md border-slate-300 text-sm"><option value="">Select relationship</option>@foreach ($relationships as $relationship)<option value="{{ $relationship->id }}">{{ $relationship->displayName() }} ({{ $relationship->relationshipLabel() }})</option>@endforeach</select>
                                        <x-input-error :messages="$errors->get('association.relationship_id')" class="mt-1" />
                                    </div>
                                </div>
                            @elseif (($association['source'] ?? '') === 'new')
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Full Legal Name *" />
                                    <div>
                                        <x-text-input wire:model="association.name" class="block w-full" />
                                        <x-input-error :messages="$errors->get('association.name')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Relationship *" />
                                    <div>
                                        <x-text-input wire:model="association.relationship" class="block w-full" />
                                        <x-input-error :messages="$errors->get('association.relationship')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="ID Type" />
                                    <div>
                                        <select wire:model="association.identification_type" class="block w-full rounded-md border-slate-300 text-sm"><option value="">Select type</option>@foreach (\App\Enums\IdentificationType::cases() as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select>
                                        <x-input-error :messages="$errors->get('association.identification_type')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" :value="$selectedAssociationId ? 'New ID Number' : 'ID Number'" />
                                    <div>
                                        <x-text-input wire:model="association.identification_number" class="block w-full" autocomplete="off" />
                                        @if ($selectedAssociationId)<p class="mt-1 text-xs text-slate-500">Leave blank to keep current.</p>@endif
                                        <x-input-error :messages="$errors->get('association.identification_number')" class="mt-1" />
                                    </div>
                                </div>
                            @endif
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Occupation" />
                                <div>
                                    <x-text-input wire:model="association.occupation" class="block w-full" />
                                    <x-input-error :messages="$errors->get('association.occupation')" class="mt-1" />
                                </div>
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" :value="($selectedAssociationId && ($association['position_meaning'] ?? '') === '') ? 'Legacy value' : 'Public Position'" />
                                <x-text-input wire:model="association.position" class="block w-full" />
                            </div>
                            @if ($selectedAssociationId && $selected?->position_meaning === null && $selected?->position)
                                <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Review legacy as" />
                                    <div>
                                        <select wire:model="association.position_meaning" class="block w-full rounded-md border-slate-300 text-sm"><option value="">Meaning not verified</option><option value="public_position">Public position</option><option value="occupation">Occupation (move from legacy field)</option></select>
                                        <x-input-error :messages="$errors->get('association.position_meaning')" class="mt-1" />
                                    </div>
                                </div>
                            @endif
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Organization" />
                                <x-text-input wire:model="association.organization" class="block w-full" />
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Country" />
                                <x-text-input wire:model="association.country" class="block w-full" />
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Position Status" />
                                <div>
                                    <select wire:model="association.position_status" class="block w-full rounded-md border-slate-300 text-sm"><option value="current">Current</option><option value="former">Former</option><option value="seeking">Seeking</option><option value="being_considered">Being Considered</option></select>
                                    <x-input-error :messages="$errors->get('association.position_status')" class="mt-1" />
                                </div>
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Association Date" />
                                <div>
                                    <x-text-input type="date" wire:model="association.started_on" class="block w-full" />
                                    <x-input-error :messages="$errors->get('association.started_on')" class="mt-1" />
                                </div>
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Remarks" />
                                <textarea wire:model="association.remarks" rows="2" class="block w-full rounded-md border-slate-300 text-sm"></textarea>
                            </div>
                        </div>
                        <div class="flex gap-3 border-t border-slate-200 px-4 py-3 sm:px-6"><x-secondary-button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" class="flex-1 justify-center">Cancel</x-secondary-button><x-primary-button class="flex-1 justify-center" wire:loading.attr="disabled" wire:target="saveAssociation">Save</x-primary-button></div>
                    </form>
                </div>
            </div>
        @endif
        @if ($associationMode === 'view' && $selected)
            <div class="border-b border-slate-100 p-4 text-sm sm:p-5">
                <div class="flex justify-between gap-2"><h3 class="font-semibold">{{ $selected->displayName() }}</h3><button type="button" wire:click="cancel" class="text-navy-700">Close</button></div>
                <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Relationship</dt><dd class="font-medium text-slate-900">{{ $selected->relationship }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Occupation</dt><dd class="font-medium text-slate-900">{{ $selected->occupation ?: 'Not provided' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">{{ $selected->position_meaning === null ? 'Legacy value (unverified)' : 'Public Position' }}</dt><dd class="font-medium text-slate-900">{{ $selected->position ?: 'Not provided' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Organization</dt><dd class="font-medium text-slate-900">{{ $selected->organization ?: 'Not provided' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Country</dt><dd class="font-medium text-slate-900">{{ $selected->country ?: 'Not provided' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Status</dt><dd class="font-medium text-slate-900">{{ ucfirst(str_replace('_', ' ', $selected->position_status)) }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Identifier</dt><dd class="font-medium text-slate-900">{{ $selected->relatedPerson?->maskedIdentificationNumber() ?: 'Not provided' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Recorded</dt><dd class="font-medium text-slate-900">{{ $selected->started_on->format('d M Y') }}</dd></div>
                </dl>
                @if ($selected->remarks)<p class="mt-2 whitespace-pre-line">{{ $selected->remarks }}</p>@endif
            </div>
        @endif
        @if ($associationMode === 'end')
            <form wire:submit="endAssociation" class="space-y-3 border-b border-slate-100 p-4 sm:p-5">
                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                    <x-input-label class="sm:pt-2" value="End Date" />
                    <div>
                        <x-text-input type="date" wire:model="endDate" class="block w-full" />
                        <x-input-error :messages="$errors->get('endDate')" class="mt-1" />
                    </div>
                </div>
                <div class="flex gap-3"><x-secondary-button type="button" wire:click="cancel" class="flex-1 justify-center">Cancel</x-secondary-button><x-primary-button class="flex-1 justify-center">End Association</x-primary-button></div>
            </form>
        @endif
        <div class="divide-y divide-slate-100">
            @forelse ($associations as $person)
                <div class="flex flex-col gap-2 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-5"><div class="min-w-0"><p class="font-medium text-slate-900">{{ $person->displayName() }} @if ($person->ended_on)<span class="ml-2 text-xs text-slate-500">Ended {{ $person->ended_on->format('d M Y') }}</span>@elseif ($person->superseded_at)<span class="ml-2 text-xs text-slate-500">Previous revision</span>@endif</p><p class="text-slate-600">{{ $person->relationship }} · {{ $person->position ? ($person->position_meaning === null ? 'Unverified legacy value: ' : 'Public position: ').$person->position : 'Position not provided' }} · {{ $person->country ?: 'Country not provided' }} · {{ ucfirst(str_replace('_', ' ', $person->position_status)) }}</p></div><div class="flex shrink-0 gap-3"><button type="button" wire:click="viewAssociation({{ $person->id }})" class="text-navy-700">View</button>@if (! $person->ended_on && ! $person->superseded_at)<button type="button" wire:click="editAssociation({{ $person->id }})" class="text-navy-700">Edit</button><button type="button" wire:click="startEndAssociation({{ $person->id }})" class="text-slate-600">End</button>@endif</div></div>
            @empty
                <p class="px-4 py-5 text-sm text-slate-500 sm:px-5">No PEP associations recorded.</p>
            @endforelse
        </div>
    </section>
</div>
