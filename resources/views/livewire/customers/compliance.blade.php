<div class="space-y-4">
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-3 sm:px-5"><h2 class="text-base font-semibold text-navy-900">Compliance Declarations</h2><p class="text-xs text-slate-500">Customer statements only; these are not independently verified findings.</p></div>
        <div class="divide-y divide-slate-100">
            @foreach (['pep' => 'PEP Declaration', 'bankruptcy' => 'Bankruptcy Declaration', 'petition' => 'Bankruptcy Petition Awareness'] as $kind => $label)
                @php $current = $declarations->get($kind); @endphp
                <div class="px-4 py-4 sm:px-5">
                    <div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="text-sm font-semibold text-slate-800">{{ $label }}</h3><p class="text-xs text-slate-500">{{ match ($kind) { 'pep' => 'Is the customer, a family member, or close associate currently holding, previously holding, seeking, or being considered for a prominent public position?', 'bankruptcy' => 'Has the customer been adjudicated or declared bankrupt?', default => 'Is the customer aware of a bankruptcy petition or proceedings against them?' } }}</p></div><button type="button" wire:click="editDeclaration('{{ $kind }}')" class="text-sm font-medium text-navy-700 hover:text-navy-900">Edit</button></div>
                    @if ($editingKind === $kind)
                        <form wire:submit="saveDeclaration" class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div><x-input-label value="Answer" /><select wire:model="declaration.answer" class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="not_declared">Not Declared</option><option value="yes">Yes</option><option value="no">No</option></select><x-input-error :messages="$errors->get('declaration.answer')" class="mt-1" /></div>
                            <div><x-input-label value="Declaration Date" /><x-text-input type="date" wire:model="declaration.declared_on" class="mt-1 w-full" /><x-input-error :messages="$errors->get('declaration.declared_on')" class="mt-1" /></div>
                            <div><x-input-label value="Declaration Source" /><select wire:model.live="declaration.source" class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="staff_recorded">Staff recorded; customer source not established</option><option value="customer_verbal">Customer verbal statement</option><option value="customer_written">Customer written statement</option></select><x-input-error :messages="$errors->get('declaration.source')" class="mt-1" /></div>
                            <div><x-input-label value="Confirmation Status" /><select wire:model="declaration.confirmation_status" class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="unconfirmed">Unconfirmed</option><option value="customer_confirmed">Customer confirmed</option></select><x-input-error :messages="$errors->get('declaration.confirmation_status')" class="mt-1" /></div>
                            <div class="sm:col-span-2"><x-input-label value="Explanation / Remarks (optional)" /><textarea wire:model="declaration.remarks" rows="2" class="mt-1 w-full rounded-md border-slate-300 text-sm"></textarea><x-input-error :messages="$errors->get('declaration.remarks')" class="mt-1" /></div>
                            <div class="flex gap-3 sm:col-span-2"><x-primary-button>Save Declaration</x-primary-button><button type="button" wire:click="cancel" class="text-sm text-slate-600">Cancel</button></div>
                        </form>
                    @else
                        <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-3"><div><dt class="text-slate-500">Answer</dt><dd class="font-medium text-slate-900">{{ match ($current?->answer) { 'yes' => 'Yes', 'no' => 'No', default => 'Not Declared' } }}</dd></div><div><dt class="text-slate-500">Declaration Date</dt><dd>{{ $current?->declared_on?->format('d M Y') ?? 'Not recorded' }}</dd></div><div><dt class="text-slate-500">Confirmation</dt><dd>{{ $current?->confirmation_status === 'customer_confirmed' ? 'Customer confirmed' : 'Unconfirmed' }}</dd></div></dl>
                        @if ($current)<p class="mt-2 text-xs text-slate-500">Source: {{ str_replace('_', ' ', ucfirst($current->source)) }} · Recorded by {{ $current->recorder?->name ?? 'Former user' }} · Updated {{ $current->updated_at?->format('d M Y, h:i A') }}</p>@endif
                        @if ($current?->remarks)<p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $current->remarks }}</p>@endif
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
                        <div class="grid min-h-0 gap-3 overflow-y-auto px-4 py-5 sm:grid-cols-2 sm:px-6">
                            @if (! $selectedAssociationId)
                                <div><x-input-label value="Person source" /><select wire:model.live="association.source" class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="new">New close associate or other person</option><option value="relationship">Existing family relationship</option><option value="self">Customer directly</option></select><x-input-error :messages="$errors->get('association.source')" class="mt-1" /></div>
                            @else
                                <p class="text-sm text-slate-700 sm:col-span-2">Editing association for <span class="font-medium">{{ $selected?->displayName() }}</span>. Family member identity is edited in Relationships.</p>
                            @endif
                            @if (($association['source'] ?? '') === 'relationship')
                                <div><x-input-label value="Existing related person" /><select wire:model="association.relationship_id" @disabled($selectedAssociationId) class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="">Select relationship</option>@foreach ($relationships as $relationship)<option value="{{ $relationship->id }}">{{ $relationship->displayName() }} ({{ $relationship->relationshipLabel() }})</option>@endforeach</select><x-input-error :messages="$errors->get('association.relationship_id')" class="mt-1" /></div>
                            @elseif (($association['source'] ?? '') === 'new')
                                <div><x-input-label value="Full Legal Name *" /><x-text-input wire:model="association.name" class="mt-1 w-full" /><x-input-error :messages="$errors->get('association.name')" class="mt-1" /></div>
                                <div><x-input-label value="Relationship / Association *" /><x-text-input wire:model="association.relationship" class="mt-1 w-full" /><x-input-error :messages="$errors->get('association.relationship')" class="mt-1" /></div>
                                <div><x-input-label value="Identification Type (optional)" /><select wire:model="association.identification_type" class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="">Select type</option>@foreach (\App\Enums\IdentificationType::cases() as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select><x-input-error :messages="$errors->get('association.identification_type')" class="mt-1" /></div>
                                <div><x-input-label value="{{ $selectedAssociationId ? 'Replacement Identification Number (leave blank to keep)' : 'Identification Number (optional)' }}" /><x-text-input wire:model="association.identification_number" class="mt-1 w-full" autocomplete="off" /><x-input-error :messages="$errors->get('association.identification_number')" class="mt-1" /></div>
                            @endif
                            <div><x-input-label value="Occupation" /><x-text-input wire:model="association.occupation" class="mt-1 w-full" /><x-input-error :messages="$errors->get('association.occupation')" class="mt-1" /></div>
                            <div><x-input-label :value="($selectedAssociationId && ($association['position_meaning'] ?? '') === '') ? 'Legacy occupation / public position (meaning unverified)' : 'Public Position'" /><x-text-input wire:model="association.position" class="mt-1 w-full" /></div>
                            @if ($selectedAssociationId && $selected?->position_meaning === null && $selected?->position)
                                <div><x-input-label value="Review legacy value as" /><select wire:model="association.position_meaning" class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="">Meaning not verified</option><option value="public_position">Public position</option><option value="occupation">Occupation (move from legacy field)</option></select><x-input-error :messages="$errors->get('association.position_meaning')" class="mt-1" /></div>
                            @endif
                            <div><x-input-label value="Employer / Organization" /><x-text-input wire:model="association.organization" class="mt-1 w-full" /></div>
                            <div><x-input-label value="Country / Jurisdiction" /><x-text-input wire:model="association.country" class="mt-1 w-full" /></div>
                            <div><x-input-label value="Position Status" /><select wire:model="association.position_status" class="mt-1 w-full rounded-md border-slate-300 text-sm"><option value="current">Current</option><option value="former">Former</option><option value="seeking">Seeking</option><option value="being_considered">Being Considered</option></select><x-input-error :messages="$errors->get('association.position_status')" class="mt-1" /></div>
                            <div><x-input-label value="Association Date" /><x-text-input type="date" wire:model="association.started_on" class="mt-1 w-full" /><x-input-error :messages="$errors->get('association.started_on')" class="mt-1" /></div>
                            <div class="sm:col-span-2"><x-input-label value="Remarks" /><textarea wire:model="association.remarks" rows="2" class="mt-1 w-full rounded-md border-slate-300 text-sm"></textarea></div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-slate-200 px-4 py-3 sm:px-6"><button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" class="text-sm text-slate-600">Cancel</button><x-primary-button wire:loading.attr="disabled" wire:target="saveAssociation">Save Association</x-primary-button></div>
                    </form>
                </div>
            </div>
        @endif
        @if ($associationMode === 'view' && $selected)
            <div class="border-b border-slate-100 p-4 text-sm sm:p-5"><div class="flex justify-between gap-2"><h3 class="font-semibold">{{ $selected->displayName() }}</h3><button type="button" wire:click="cancel" class="text-navy-700">Close</button></div><dl class="mt-2 grid gap-2 sm:grid-cols-2"><div>Relationship: {{ $selected->relationship }}</div><div>Occupation: {{ $selected->occupation ?: 'Not provided' }}</div><div>{{ $selected->position_meaning === null ? 'Legacy occupation / public position (unverified)' : 'Public Position' }}: {{ $selected->position ?: 'Not provided' }}</div><div>Organization: {{ $selected->organization ?: 'Not provided' }}</div><div>Country: {{ $selected->country ?: 'Not provided' }}</div><div>Status: {{ ucfirst(str_replace('_', ' ', $selected->position_status)) }}</div><div>Identifier: {{ $selected->relatedPerson?->identification_number ?: 'Not provided' }}</div><div>Recorded: {{ $selected->started_on->format('d M Y') }}</div></dl>@if ($selected->remarks)<p class="mt-2 whitespace-pre-line">{{ $selected->remarks }}</p>@endif</div>
        @endif
        @if ($associationMode === 'end')
            <form wire:submit="endAssociation" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4 sm:p-5"><div><x-input-label value="End Association Date" /><x-text-input type="date" wire:model="endDate" class="mt-1" /><x-input-error :messages="$errors->get('endDate')" class="mt-1" /></div><x-primary-button>End Association</x-primary-button><button type="button" wire:click="cancel" class="text-sm text-slate-600">Cancel</button></form>
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
