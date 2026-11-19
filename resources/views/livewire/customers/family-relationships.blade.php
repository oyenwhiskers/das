<section id="family" class="rounded-lg border border-slate-200 bg-white">
    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
        <h2 class="min-w-0 flex-1 text-base font-semibold text-navy-900">Family &amp; Relationships</h2>
        @if ($mode !== 'edit')<button type="button" wire:click="add" class="text-sm font-medium text-navy-700">Add</button>@endif
    </div>

    <div class="px-4 py-4 sm:px-5">
        @if ($mode === 'view' && $selected)
            @php $person = $selected->relatedPerson; $linked = $selected->relatedCustomer; @endphp
            <div class="space-y-4 text-sm">
                <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-900">{{ $selected->displayName() }}</h3><p class="text-slate-600">{{ $selected->relationshipLabel() }} @if ($selected->is_primary_next_of_kin) · Active for BIMBSEC @endif</p></div><button type="button" wire:click="cancel" class="text-navy-700">Close</button></div>
                @if ($linked)<p class="rounded-md bg-slate-50 p-3">Linked DAS customer: <a href="{{ route('customers.show', $linked) }}" class="font-medium text-navy-700 underline">{{ $linked->reference }}</a>. Master details are edited on that profile.</p>@endif
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Identification</dt><dd class="font-medium text-slate-900">{{ $person?->identification_type?->label() ?? $linked?->primary_identification_type?->label() ?? '—' }} · {{ $person?->identification_number ?? $linked?->primary_identification_number ?? '—' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Date of Birth</dt><dd class="font-medium text-slate-900">{{ $person?->date_of_birth?->format('d M Y') ?? $linked?->date_of_birth?->format('d M Y') ?? '—' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Gender</dt><dd class="font-medium text-slate-900">{{ $person?->gender ?? $linked?->gender ?? '—' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Nationality</dt><dd class="font-medium text-slate-900">{{ $person?->nationality ?? $linked?->nationality ?? '—' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Mobile</dt><dd class="font-medium text-slate-900">{{ $person?->mobile ?? $linked?->primary_mobile ?? '—' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Home Telephone</dt><dd class="font-medium text-slate-900">{{ $person?->home_telephone ?? $linked?->contacts->firstWhere('type', \App\Enums\ContactType::Home)?->value ?? '—' }}</dd></div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900">{{ $person?->email ?? $linked?->primary_email ?? '—' }}</dd></div>
                    @if ($person)
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Occupation</dt><dd class="font-medium text-slate-900">{{ $person->occupation ?: '—' }}</dd></div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Employer / Firm</dt><dd class="font-medium text-slate-900">{{ $person->employer_name ?: '—' }}</dd></div>
                        <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Employer Address</dt><dd class="whitespace-pre-line font-medium text-slate-900">{{ collect([$person->employer_address_line_1, $person->employer_address_line_2, collect([$person->employer_postcode, $person->employer_city])->filter()->implode(' '), $person->employer_state, $person->employer_country])->filter()->implode("\n") ?: '—' }}</dd></div>
                        @if ($person->employer_address)<div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"><dt class="text-slate-500">Legacy address</dt><dd class="whitespace-pre-line font-medium text-slate-900">{{ $person->employer_address }}</dd></div>@endif
                    @endif
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
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-search" class="sm:pt-2" value="Search customer" />
                                    <div>
                                        <x-text-input id="family-search" wire:model.live.debounce.300ms="search" class="block w-full" placeholder="Name or reference" />
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
                                </div>
                            @else
                                <p class="text-sm text-slate-600">Customer details are managed from their own profile.</p>
                            @endif
                            @php $linked = $selected?->relatedCustomer ?? $matches->firstWhere('id', (int) $linkedCustomerId); @endphp
                            @if ($linked)<p class="rounded-md bg-slate-50 px-3 py-2 text-sm">Selected: {{ $linked->reference }} — {{ $linked->full_legal_name }}</p>@endif
                            <x-input-error :messages="$errors->get('linkedCustomerId')" class="mt-1" />
                        @else
                            @php $bimbsecFit = app(\App\Services\Bimbsec\Catalog::class); @endphp
                            <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-name" class="sm:pt-2" value="Full Legal Name *" />
                                    <div>
                                        <x-text-input id="family-name" wire:model="fullLegalName" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'legal_name')" for="family-name" />
                                        <x-input-error :messages="$errors->get('fullLegalName')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-id-type" class="sm:pt-2" value="ID Type" />
                                    <div>
                                        <select id="family-id-type" wire:model="identificationType" class="block w-full rounded-md border-slate-300 text-sm"><option value="">— Select —</option>@foreach (\App\Enums\IdentificationType::cases() as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select>
                                        <x-input-error :messages="$errors->get('identificationType')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-id-number" class="sm:pt-2" value="ID Number" />
                                    <div>
                                        <x-text-input id="family-id-number" wire:model="identificationNumber" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'nric_or_passport')" for="family-id-number" />
                                        <x-input-error :messages="$errors->get('identificationNumber')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-dob" class="sm:pt-2" value="Date of Birth" />
                                    <div>
                                        <x-text-input id="family-dob" type="date" wire:model="dateOfBirth" class="block w-full" />
                                        <x-input-error :messages="$errors->get('dateOfBirth')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-gender" class="sm:pt-2" value="Gender" />
                                    <x-text-input id="family-gender" wire:model="gender" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-nationality" class="sm:pt-2" value="Nationality" />
                                    <x-text-input id="family-nationality" wire:model="nationality" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-mobile" class="sm:pt-2" value="Mobile Number" />
                                    <div>
                                        <x-text-input id="family-mobile" wire:model="mobile" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'mobile_phone')" for="family-mobile" />
                                        <x-input-error :messages="$errors->get('mobile')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-home" class="sm:pt-2" value="Home Telephone" />
                                    <div>
                                        <x-text-input id="family-home" wire:model="homeTelephone" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'home_phone')" for="family-home" />
                                        <x-input-error :messages="$errors->get('homeTelephone')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-email" class="sm:pt-2" value="Email Address" />
                                    <div>
                                        <x-text-input id="family-email" type="email" wire:model="email" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'email')" for="family-email" />
                                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-occupation" class="sm:pt-2" value="Occupation" />
                                    <div>
                                        <x-text-input id="family-occupation" wire:model="occupation" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'occupation')" for="family-occupation" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-employer" class="sm:pt-2" value="Employer / Firm" />
                                    <x-text-input id="family-employer" wire:model="employerName" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-employer-line-1" class="sm:pt-2" value="Employer Address 1" />
                                    <x-text-input id="family-employer-line-1" wire:model="employerAddressLine1" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-employer-line-2" class="sm:pt-2" value="Employer Address 2" />
                                    <div>
                                        <x-text-input id="family-employer-line-2" wire:model="employerAddressLine2" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_name_and_address')" :ids="array_values(array_filter(['family-employer', 'family-employer-line-1', 'family-employer-line-2', $employerAddress !== '' ? 'family-employer-address' : null]))" together />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-employer-city" class="sm:pt-2" value="Employer City" />
                                    <div>
                                        <x-text-input id="family-employer-city" wire:model="employerCity" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_city')" for="family-employer-city" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-employer-state" class="sm:pt-2" value="Employer State" />
                                    <div>
                                        <x-text-input id="family-employer-state" wire:model="employerState" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_state')" for="family-employer-state" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-employer-postcode" class="sm:pt-2" value="Employer Postcode" />
                                    <div>
                                        <x-text-input id="family-employer-postcode" wire:model="employerPostcode" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_postcode')" for="family-employer-postcode" />
                                    </div>
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-employer-country" class="sm:pt-2" value="Employer Country" />
                                    <div>
                                        <x-text-input id="family-employer-country" wire:model="employerCountry" class="block w-full" />
                                        <x-bimbsec-fit-hint :max="$bimbsecFit->cellCapacity('relationships.spouse_or_next_of_kin', 'employer_country')" for="family-employer-country" />
                                    </div>
                                </div>
                                @if ($employerAddress !== '')
                                    <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                        <x-input-label for="family-employer-address" class="sm:pt-2" value="Legacy address" />
                                        <div>
                                            <textarea id="family-employer-address" wire:model="employerAddress" rows="2" class="block w-full rounded-md border-slate-300 text-sm"></textarea>
                                            <x-input-error :messages="$errors->get('employerAddress')" class="mt-1" />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label for="family-relationship" class="sm:pt-2" value="Relationship *" />
                                <div>
                                    <select id="family-relationship" wire:model.live="relationship" class="block w-full rounded-md border-slate-300 text-sm"><option value="">— Select —</option>@foreach (['spouse', 'father', 'mother', 'son', 'daughter', 'brother', 'sister', 'other'] as $option)<option value="{{ $option }}">{{ ucfirst($option) }}</option>@endforeach</select>
                                    <x-input-error :messages="$errors->get('relationship')" class="mt-1" />
                                </div>
                            </div>
                            @if ($relationship === 'other')
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label for="family-other" class="sm:pt-2" value="Specify *" />
                                    <div>
                                        <x-text-input id="family-other" wire:model="otherRelationship" class="block w-full" />
                                        <x-input-error :messages="$errors->get('otherRelationship')" class="mt-1" />
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div class="space-y-2 text-sm text-slate-700">
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-navy-700">Active for BIMBSEC</label>
                            <x-input-error :messages="$errors->get('isActive')" />
                        </div>
                    </div>
                    <div class="flex gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:px-6"><x-secondary-button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" class="flex-1 justify-center">Cancel</x-secondary-button><x-primary-button class="flex-1 justify-center" wire:loading.attr="disabled" wire:target="save">Save</x-primary-button></div>
                </form>
            </div>
        </div>
    @endif
</section>
