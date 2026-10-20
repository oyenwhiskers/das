<section id="family" class="rounded-lg border border-slate-200 bg-white">
    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
        <h2 class="min-w-0 flex-1 text-base font-semibold text-navy-900">Family &amp; Relationships</h2>
        @if ($mode !== 'edit')<button type="button" wire:click="add" class="rounded-md px-2 py-1 text-sm font-medium text-navy-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">+ Add Related Person</button>@endif
    </div>

    <div class="px-4 py-4 sm:px-5">
        @if ($mode === 'view' && $selected)
            @php $person = $selected->relatedPerson; $linked = $selected->relatedCustomer; @endphp
            <div class="space-y-4 text-sm">
                <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-900">{{ $selected->displayName() }}</h3><p class="text-slate-600">{{ $selected->relationshipLabel() }} @if ($selected->is_primary_next_of_kin) · Active for BIMBSEC @endif</p></div><button type="button" wire:click="cancel" class="text-navy-700">Close</button></div>
                @if ($linked)<p class="rounded-md bg-slate-50 p-3">Linked DAS customer: <a href="{{ route('customers.show', $linked) }}" class="font-medium text-navy-700 underline">{{ $linked->reference }}</a>. Master details are edited on that profile.</p>@endif
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-slate-500">Identification</dt><dd>{{ $person?->identification_type?->label() ?? $linked?->primary_identification_type?->label() ?? '—' }} · {{ $person?->identification_number ?? $linked?->primary_identification_number ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Date of Birth</dt><dd>{{ $person?->date_of_birth?->format('d M Y') ?? $linked?->date_of_birth?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Gender</dt><dd>{{ $person?->gender ?? $linked?->gender ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Nationality</dt><dd>{{ $person?->nationality ?? $linked?->nationality ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Mobile</dt><dd>{{ $person?->mobile ?? $linked?->primary_mobile ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Home Telephone</dt><dd>{{ $person?->home_telephone ?? $linked?->contacts->firstWhere('type', \App\Enums\ContactType::Home)?->value ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Email</dt><dd>{{ $person?->email ?? $linked?->primary_email ?? '—' }}</dd></div>
                    @if ($person)<div><dt class="text-slate-500">Occupation</dt><dd>{{ $person->occupation ?: '—' }}</dd></div><div><dt class="text-slate-500">Employer / Firm</dt><dd>{{ $person->employer_name ?: '—' }}</dd></div><div class="sm:col-span-2"><dt class="text-slate-500">Employer Address</dt><dd class="whitespace-pre-line">{{ collect([$person->employer_address_line_1, $person->employer_address_line_2, collect([$person->employer_postcode, $person->employer_city])->filter()->implode(' '), $person->employer_state, $person->employer_country])->filter()->implode("\n") ?: '—' }}</dd></div>@if ($person->employer_address)<div class="sm:col-span-2"><dt class="text-slate-500">Legacy unstructured employer address</dt><dd class="whitespace-pre-line">{{ $person->employer_address }}</dd></div>@endif @endif
                </dl>
            </div>
        @elseif ($relationships->isEmpty())
            <div class="py-8 text-center"><p class="text-sm font-medium text-slate-800">No relationships added yet</p><p class="mt-1 text-sm text-slate-500">Add a related person or link an existing DAS customer.</p></div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($relationships as $record)
                    <div wire:key="relationship-{{ $record->id }}" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <div class="min-w-0"><p class="font-medium text-slate-900">{{ $record->displayName() }}</p><div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-600"><span class="rounded bg-slate-100 px-2 py-0.5">{{ $record->relationshipLabel() }}</span>@if ($record->is_primary_next_of_kin)<span class="rounded bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">Active</span>@endif @if ($record->contactNumber())<span class="text-slate-500">{{ $record->contactNumber() }}</span>@endif</div></div>
                        <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm"><button type="button" wire:click="view({{ $record->id }})" class="font-medium text-navy-700">View</button><button type="button" wire:click="edit({{ $record->id }})" class="font-medium text-navy-700">Edit</button><button type="button" x-on:click.prevent="if (await confirmAction({ title: 'Remove relationship?', text: 'The person and any customer record will be kept.', confirmButtonText: 'Remove' })) $wire.remove({{ $record->id }})" class="font-medium text-red-700">Remove</button></div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    @if ($mode === 'edit')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" x-on:keydown.escape.window="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }">
            <div class="absolute inset-0 bg-slate-900/40" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" aria-hidden="true"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="family-form-title" x-trap.noscroll="true" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
                    <h3 id="family-form-title" class="font-semibold text-navy-900">{{ $selectedId ? 'Edit Relationship' : 'Add Related Person' }}</h3>
                    <button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" aria-label="Close relationship form" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">&times;</button>
                </div>
                <form wire:submit="save" class="flex min-h-0 flex-col">
                    <div class="min-h-0 space-y-5 overflow-y-auto px-4 py-5 sm:px-6">
                        @if (! $selectedId)
                            <div class="flex flex-wrap gap-4 text-sm">
                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="source" value="new" class="text-navy-700">Enter a new person</label>
                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="source" value="customer" class="text-navy-700">Link existing DAS customer</label>
                            </div>
                        @endif

                        @if ($source === 'customer')
                            @if (! $selectedId)
                                <div>
                                    <x-input-label for="family-search" value="Search customer by name or reference" />
                                    <x-text-input id="family-search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" />
                                    @if (mb_strlen(trim($search)) >= 2)
                                        <div class="mt-2 max-h-48 overflow-y-auto rounded-md border border-slate-200">
                                            @forelse ($matches as $match)
                                                <label class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-0 hover:bg-slate-50">
                                                    <input type="radio" wire:model.live="linkedCustomerId" value="{{ $match->id }}" class="mt-1 text-navy-700">
                                                    <span>{{ $match->reference }} — {{ $match->full_legal_name }}</span>
                                                </label>
                                            @empty
                                                <p class="px-3 py-2 text-sm text-slate-500">No matching customers.</p>
                                            @endforelse
                                        </div>
                                    @endif
                                </div>
                            @else
                                <p class="text-sm text-slate-600">Customer details are managed from their own profile.</p>
                            @endif
                            @php $linked = $selected?->relatedCustomer ?? $matches->firstWhere('id', (int) $linkedCustomerId); @endphp
                            @if ($linked)<p class="rounded-md bg-slate-50 px-3 py-2 text-sm">Selected: {{ $linked->reference }} — {{ $linked->full_legal_name }}</p>@endif
                            <x-input-error :messages="$errors->get('linkedCustomerId')" class="mt-1" />
                        @else
                            @php $bimbsecFit = app(\App\Services\Bimbsec\Catalog::class); @endphp
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2"><x-input-label for="family-name" value="Full Legal Name *" /><x-text-input id="family-name" wire:model="fullLegalName" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'legal_name')" for="family-name" /><x-input-error :messages="$errors->get('fullLegalName')" class="mt-1" /></div>
                                <div><x-input-label for="family-id-type" value="Identification Type" /><select id="family-id-type" wire:model="identificationType" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">— Select —</option>@foreach (\App\Enums\IdentificationType::cases() as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select><x-input-error :messages="$errors->get('identificationType')" class="mt-1" /></div>
                                <div><x-input-label for="family-id-number" value="Identification Number" /><x-text-input id="family-id-number" wire:model="identificationNumber" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'nric_or_passport')" for="family-id-number" /><x-input-error :messages="$errors->get('identificationNumber')" class="mt-1" /></div>
                                <div><x-input-label for="family-dob" value="Date of Birth" /><x-text-input id="family-dob" type="date" wire:model="dateOfBirth" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('dateOfBirth')" class="mt-1" /></div>
                                <div><x-input-label for="family-gender" value="Gender" /><x-text-input id="family-gender" wire:model="gender" class="mt-1 block w-full" /></div>
                                <div><x-input-label for="family-nationality" value="Nationality" /><x-text-input id="family-nationality" wire:model="nationality" class="mt-1 block w-full" /></div>
                                <div><x-input-label for="family-mobile" value="Mobile Number" /><x-text-input id="family-mobile" wire:model="mobile" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'mobile_phone')" for="family-mobile" /><x-input-error :messages="$errors->get('mobile')" class="mt-1" /></div>
                                <div><x-input-label for="family-home" value="Home Telephone" /><x-text-input id="family-home" wire:model="homeTelephone" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'home_phone')" for="family-home" /><x-input-error :messages="$errors->get('homeTelephone')" class="mt-1" /></div>
                                <div><x-input-label for="family-email" value="Email Address" /><x-text-input id="family-email" type="email" wire:model="email" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'email')" for="family-email" /><x-input-error :messages="$errors->get('email')" class="mt-1" /></div>
                                <div><x-input-label for="family-occupation" value="Occupation" /><x-text-input id="family-occupation" wire:model="occupation" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'occupation')" for="family-occupation" /></div>
                                <div><x-input-label for="family-employer" value="Employer / Firm Name" /><x-text-input id="family-employer" wire:model="employerName" class="mt-1 block w-full" /></div>
                                <div class="sm:col-span-2"><x-input-label for="family-employer-line-1" value="Employer Address Line 1" /><x-text-input id="family-employer-line-1" wire:model="employerAddressLine1" class="mt-1 block w-full" /></div>
                                <div class="sm:col-span-2"><x-input-label for="family-employer-line-2" value="Employer Address Line 2" /><x-text-input id="family-employer-line-2" wire:model="employerAddressLine2" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_name_and_address')" :ids="array_values(array_filter(['family-employer', 'family-employer-line-1', 'family-employer-line-2', $employerAddress !== '' ? 'family-employer-address' : null]))" together /></div>
                                <div><x-input-label for="family-employer-city" value="Employer City" /><x-text-input id="family-employer-city" wire:model="employerCity" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_city')" for="family-employer-city" /></div>
                                <div><x-input-label for="family-employer-state" value="Employer State" /><x-text-input id="family-employer-state" wire:model="employerState" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_state')" for="family-employer-state" /></div>
                                <div><x-input-label for="family-employer-postcode" value="Employer Postcode" /><x-text-input id="family-employer-postcode" wire:model="employerPostcode" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_postcode')" for="family-employer-postcode" /></div>
                                <div><x-input-label for="family-employer-country" value="Employer Country" /><x-text-input id="family-employer-country" wire:model="employerCountry" class="mt-1 block w-full" /><x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_country')" for="family-employer-country" /></div>
                                @if ($employerAddress !== '')<div class="sm:col-span-2"><x-input-label for="family-employer-address" value="Legacy unstructured employer address" /><textarea id="family-employer-address" wire:model="employerAddress" rows="2" class="mt-1 block w-full rounded-md border-slate-300 text-sm"></textarea><x-input-error :messages="$errors->get('employerAddress')" class="mt-1" /></div>@endif
                            </div>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><x-input-label for="family-relationship" value="Relationship *" /><select id="family-relationship" wire:model.live="relationship" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">— Select —</option>@foreach (['spouse', 'father', 'mother', 'son', 'daughter', 'brother', 'sister', 'other'] as $option)<option value="{{ $option }}">{{ ucfirst($option) }}</option>@endforeach</select><x-input-error :messages="$errors->get('relationship')" class="mt-1" /></div>
                            @if ($relationship === 'other')<div><x-input-label for="family-other" value="Specify Relationship *" /><x-text-input id="family-other" wire:model="otherRelationship" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('otherRelationship')" class="mt-1" /></div>@endif
                        </div>
                        <div class="space-y-2 text-sm text-slate-700">
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-navy-700">Active for BIMBSEC</label>
                            <x-input-error :messages="$errors->get('isActive')" />
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:px-6"><button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" class="text-sm text-slate-600">Cancel</button><x-primary-button wire:loading.attr="disabled" wire:target="save">Save</x-primary-button><span wire:loading wire:target="save" role="status" class="self-center text-xs text-slate-500">Saving...</span></div>
                </form>
            </div>
        </div>
    @endif
</section>
