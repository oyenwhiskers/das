@php
    $initials = collect(explode(' ', $customer->full_legal_name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $updatedBy = $customer->updater?->name ?? $customer->creator?->name;
@endphp

<div class="bg-[#F8FAFC] py-4 sm:py-5" x-data="{
    activeTab: (() => { const tabs = { personal: 'personal', relationships: 'relationships', work: 'work', financial: 'financial', investment: 'investment', tax: 'tax', compliance: 'compliance', 'work-finance': 'work' }; const selected = tabs[new URLSearchParams(window.location.search).get('tab')]; return selected ? selected : 'personal'; })(),
    tabOrder: ['personal', 'relationships', 'work', 'financial', 'investment', 'tax', 'compliance'],
    activityOpen: false,
    dirty: false,
    openActivity() {
        this.activityOpen = true;
        this.$nextTick(() => this.$refs.closeActivity.focus());
    },
    closeActivity() {
        this.activityOpen = false;
        this.$nextTick(() => this.$refs.viewActivity.focus());
    },
    async switchTab(tab) {
        if (tab === this.activeTab) return;
        if (this.dirty && ! await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes in the current section have not been saved.', confirmButtonText: 'Discard changes' })) return;
        if ($wire.editingSection) await $wire.cancelEdit();
        if (this.activeTab === 'relationships') this.$dispatch('discard-relationship-edit');
        if (this.activeTab === 'work') this.$dispatch('discard-employment-edit');
        if (this.activeTab === 'financial') { this.$dispatch('discard-bank-account-edit'); this.$dispatch('discard-financial-edit'); }
        if (this.activeTab === 'investment') this.$dispatch('discard-investment-edit');
        if (this.activeTab === 'tax') this.$dispatch('discard-tax-residency-edit');
        if (this.activeTab === 'compliance') this.$dispatch('discard-compliance-edit');
        this.dirty = false;
        this.activeTab = tab;
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState(window.history.state, '', url);
        this.$nextTick(() => this.$refs[tab + 'Tab'].focus());
    },
    moveTab(direction) {
        const nextIndex = (this.tabOrder.indexOf(this.activeTab) + direction + this.tabOrder.length) % this.tabOrder.length;
        this.switchTab(this.tabOrder[nextIndex]);
    }
}" x-init="$watch('$wire.editingSection', value => { if (value === null) dirty = false })" x-on:input.capture="if ($event.target.closest('form')) dirty = true" x-on:change.capture="if ($event.target.closest('form')) dirty = true" x-on:relationship-updated.window="dirty = false" x-on:employment-updated.window="dirty = false" x-on:bank-account-updated.window="dirty = false" x-on:financial-updated.window="dirty = false" x-on:investment-updated.window="dirty = false" x-on:tax-updated.window="dirty = false" x-on:compliance-updated.window="dirty = false" x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }" x-on:livewire:navigate.window="if (dirty && ! window.confirm('Discard unsaved changes?')) $event.preventDefault(); else dirty = false">
    <div class="w-full space-y-4 px-4 sm:px-6 lg:px-8 xl:px-10">
            <div>
                <a href="{{ route('customers.index') }}" x-on:click.prevent="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; window.location.href = $el.href }" class="text-sm text-navy-700 hover:text-navy-900">&larr; Back to Customers</a>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 sm:px-5 sm:py-3">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-navy-100 text-xl font-semibold text-navy-800 sm:h-20 sm:w-20 sm:text-2xl" aria-hidden="true">
                            {{ $initials ?: 'C' }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="min-w-0 text-xl font-semibold text-[#0F172A] sm:text-2xl">{{ $customer->full_legal_name }}</h1>
                                <span @class([
                                    'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
                                    'bg-emerald-50 text-emerald-700' => $customer->isActive(),
                                    'bg-slate-100 text-slate-600' => $customer->isArchived(),
                                ])>
                                    {{ $customer->status->label() }}
                                </span>
                            </div>
                            <dl class="mt-1.5 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="text-slate-500">DAS Reference:</dt>
                                    <dd class="font-medium text-navy-800">{{ $customer->reference }}</dd>
                                </div>
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="text-slate-500">Customer Type:</dt>
                                    <dd class="text-slate-800">{{ $customer->type?->label() ?? 'Not provided' }}</dd>
                                </div>
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="text-slate-500">Identification No.:</dt>
                                    <dd class="text-slate-800">
                                        @if ($customer->primary_identification_number)
                                            {{ $customer->primary_identification_number }}
                                            @if ($customer->primary_identification_type)
                                                <span class="text-slate-500">({{ $customer->primary_identification_type->label() }})</span>
                                            @endif
                                        @else
                                            Not provided
                                        @endif
                                    </dd>
                                </div>
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="text-slate-500">Last Updated:</dt>
                                    <dd class="text-slate-800">
                                        {{ $customer->updated_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') ?: 'Not provided' }}
                                        @if ($updatedBy)
                                            <span class="text-slate-500">by {{ $updatedBy }}</span>
                                        @endif
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <button type="button" x-ref="viewActivity" x-on:click="openActivity()" :aria-expanded="activityOpen.toString()" aria-controls="customer-activity-modal" class="rounded-md px-3 py-2 text-sm font-medium text-navy-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">View Activity</button>
                        @if ($customer->isArchived())
                            <button type="button"
                                    x-on:click.prevent="if (await confirmAction({ title: 'Restore customer?', text: dirty ? 'Unsaved changes will be discarded. This customer will be set back to Active.' : 'This customer will be set back to Active.', confirmButtonText: 'Restore' })) { if ($wire.editingSection) await $wire.cancelEdit(); dirty = false; $wire.restore() }"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-emerald-50 hover:border-emerald-200 hover:text-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                                </svg>
                                Restore
                            </button>
                        @endif
                        <button type="button"
                                x-on:click.prevent="if (await confirmAction({ title: 'Delete customer?', text: dirty ? 'Unsaved changes will be discarded. This customer, their records, and links from other customers will be permanently deleted. This cannot be undone.' : 'This customer, their records, and links from other customers will be permanently deleted. This cannot be undone.', confirmButtonText: 'Delete', confirmButtonColor: '#b91c1c' })) { dirty = false; $wire.delete() }"
                                class="inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 7.5h12m-10.5 0 .625 11.25a2.25 2.25 0 0 0 2.246 2.125h3.258a2.25 2.25 0 0 0 2.246-2.125L16.5 7.5m-6-3h3a1.5 1.5 0 0 1 1.5 1.5v1.5h-6V6a1.5 1.5 0 0 1 1.5-1.5Z" />
                            </svg>
                            Delete
                        </button>
                    </div>
                </div>
            </div>

            <div class="border-b border-slate-200" role="tablist" aria-label="Customer profile sections" x-on:keydown.arrow-right.prevent="moveTab(1)" x-on:keydown.arrow-left.prevent="moveTab(-1)">
                <div class="flex flex-wrap gap-1">
                    @foreach (['personal' => 'Personal', 'relationships' => 'Relationships', 'work' => 'Work Information', 'financial' => 'Financial Information', 'investment' => 'Investment', 'tax' => 'Tax & Residency', 'compliance' => 'Compliance'] as $tab => $label)
                        <button type="button" x-ref="{{ $tab }}Tab" id="tab-{{ $tab }}" role="tab" aria-controls="panel-{{ $tab }}" :aria-selected="activeTab === '{{ $tab }}'" :tabindex="activeTab === '{{ $tab }}' ? 0 : -1" @click="switchTab('{{ $tab }}')" class="border-b-2 px-4 py-3 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500" :class="activeTab === '{{ $tab }}' ? 'border-navy-700 text-navy-900' : 'border-transparent text-slate-600 hover:text-navy-900'">{{ $label }}</button>
                    @endforeach
                </div>
            </div>


            <div id="panel-personal" role="tabpanel" aria-labelledby="tab-personal" x-show="activeTab === 'personal'" x-cloak class="space-y-4">
                    {{-- Personal --}}
                    <section
                        id="personal"
                        class="rounded-lg border border-slate-200 bg-white"
                    >
                        <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
                            <h2 class="min-w-0 flex-1 text-base font-semibold text-navy-900">Personal Information</h2>
                            @if ($editingSection !== 'personal')
                                <button type="button" @click="if (dirty && ! await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) return; dirty = false; $wire.editSection('personal')" class="text-sm font-medium text-navy-700 hover:text-navy-900 shrink-0">Edit</button>
                            @endif
                        </div>
                        <div
                            class="px-4 py-4 sm:px-5"
                        >
                            @if ($editingSection === 'personal')
                                <form wire:submit="savePersonal" class="grid gap-4 sm:grid-cols-2">
                                    @if (count($weakMatches) > 0)
                                        <div class="sm:col-span-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                            <p>Possible duplicate customers were found:</p>
                                            @foreach ($weakMatches as $match)
                                                <a class="block underline" href="{{ route('customers.show', $match['reference']) }}">{{ $match['reference'] }} — {{ $match['full_legal_name'] }}</a>
                                            @endforeach
                                            <label class="mt-2 flex items-center gap-2"><input type="checkbox" wire:model="confirmWeakDuplicates"> I reviewed these possible matches.</label>
                                        </div>
                                    @endif
                                    <div>
                                        <x-input-label for="salutation" value="Salutation" />
                                        <x-text-input wire:model="salutation" id="salutation" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="full_legal_name" value="Full Legal Name *" />
                                        <x-text-input wire:model="full_legal_name" id="full_legal_name" class="mt-1 block w-full" required />
                                        <x-input-error :messages="$errors->get('full_legal_name')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-input-label for="identification_type" value="Identification Type" />
                                        <select wire:model.live="identification_type" id="identification_type" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-navy-500 focus:ring-navy-500 text-sm">
                                            <option value="">— Select —</option>
                                            @foreach (\App\Enums\IdentificationType::cases() as $type)
                                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label for="identification_number" value="Identification Number" />
                                        <x-text-input wire:model.live.debounce.300ms="identification_number" id="identification_number" class="mt-1 block w-full" />
                                        <p class="mt-1 text-xs text-slate-500">NRIC date of birth, gender, and nationality suggestions are unverified. Check and correct them before saving.</p>
                                        <x-input-error :messages="$errors->get('identification_number')" class="mt-2" />
                                    </div>
                                    @if (in_array($identification_type, ['passport', 'other']))
                                        <div>
                                            <x-input-label for="issuing_jurisdiction" value="Issuing Country Code *" />
                                            <x-text-input wire:model="issuing_jurisdiction" id="issuing_jurisdiction" class="mt-1 block w-full" maxlength="2" placeholder="e.g. MY" />
                                            <x-input-error :messages="$errors->get('issuing_jurisdiction')" class="mt-2" />
                                        </div>
                                    @endif
                                    <div>
                                        <x-input-label for="old_nric" value="Old NRIC" />
                                        <x-text-input wire:model="old_nric" id="old_nric" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="date_of_birth" value="Date of Birth" />
                                        <x-text-input wire:model="date_of_birth" id="date_of_birth" type="date" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="gender" value="Gender" />
                                        <select wire:model.live="gender" id="gender" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-navy-500 focus:ring-navy-500 text-sm">
                                            <option value="">— Select —</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label for="nationality" value="Nationality" />
                                        <x-text-input wire:model="nationality" id="nationality" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="country_of_residence" value="Country of Residence" />
                                        <x-text-input wire:model="country_of_residence" id="country_of_residence" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="race" value="Race" />
                                        <x-text-input wire:model="race" id="race" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="marital_status" value="Marital Status" />
                                        <select wire:model.live="marital_status" id="marital_status" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-navy-500 focus:ring-navy-500 text-sm">
                                            <option value="">— Select —</option>
                                            <option value="Single">Single</option>
                                            <option value="Married">Married</option>
                                            <option value="Divorced">Divorced</option>
                                            <option value="Widowed">Widowed</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2 flex justify-end gap-3">
                                        <button type="button" @click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancelEdit() }" class="text-sm text-slate-600">Cancel</button>
                                        <x-primary-button wire:loading.attr="disabled">Save</x-primary-button><span wire:loading role="status" class="self-center text-xs text-slate-500">Saving...</span>
                                    </div>
                                </form>
                            @else
                                <dl class="grid gap-x-6 gap-y-2 text-sm lg:grid-cols-2 [&>div]:grid [&>div]:gap-x-3 sm:[&>div]:grid-cols-[9rem_minmax(0,1fr)] [&_dd]:min-w-0 [&_dd]:break-words [&_dd]:!mt-0">
                                    <div><dt class="text-slate-500">Salutation</dt><dd class="mt-1 text-slate-900">{{ $customer->salutation ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Full Legal Name</dt><dd class="mt-1 text-slate-900">{{ $customer->full_legal_name }}</dd></div>
                                    <div><dt class="text-slate-500">Identification Type</dt><dd class="mt-1 text-slate-900">{{ $customer->primary_identification_type?->label() ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Identification Number</dt><dd class="mt-1 text-slate-900">{{ $customer->primary_identification_number ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Issuing Jurisdiction</dt><dd class="mt-1 text-slate-900">{{ $customer->identifications->firstWhere('is_primary', true)?->issuing_jurisdiction ?: 'Not provided' }}</dd></div>
                                    @php $oldNric = $customer->identifications->firstWhere('is_primary', true)?->old_nric; @endphp
                                    <div><dt class="text-slate-500">Old NRIC</dt><dd class="mt-1 text-slate-900">{{ $oldNric ?: 'Not provided' }}</dd></div>
                                    <div>
                                        <dt class="text-slate-500">Date of Birth</dt>
                                        <dd class="mt-1 text-slate-900">
                                            @if ($customer->date_of_birth)
                                                {{ $customer->date_of_birth->format('d M Y') }}
                                                <span class="text-slate-500">({{ $customer->date_of_birth->age }} years)</span>
                                            @else
                                                Not provided
                                            @endif
                                        </dd>
                                    </div>
                                    <div><dt class="text-slate-500">Gender</dt><dd class="mt-1 text-slate-900">{{ $customer->gender ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Nationality</dt><dd class="mt-1 text-slate-900">{{ $customer->nationality ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Country of Residence</dt><dd class="mt-1 text-slate-900">{{ $customer->country_of_residence ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Race</dt><dd class="mt-1 text-slate-900">{{ $customer->race ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Marital Status</dt><dd class="mt-1 text-slate-900">{{ $customer->marital_status ?: 'Not provided' }}</dd></div>
                                </dl>
                            @endif
                        </div>
                    </section>

                    {{-- Contact --}}
                    <section
                        id="contact"
                        class="rounded-lg border border-slate-200 bg-white"
                    >
                        <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
                            <h2 class="min-w-0 flex-1 text-base font-semibold text-navy-900">Contact Information</h2>
                            @if ($editingSection !== 'contact')
                                <button type="button" @click="if (dirty && ! await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) return; dirty = false; $wire.editSection('contact')" class="text-sm font-medium text-navy-700 hover:text-navy-900 shrink-0">Edit</button>
                            @endif
                        </div>
                        <div class="px-4 py-4 sm:px-5">
                            @if ($editingSection === 'contact')
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                    <h3 class="text-sm font-semibold text-slate-800 mb-4">Edit Contact Information</h3>
                                    <form wire:submit="saveContact" class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <x-input-label for="mobile" value="Mobile Number" />
                                            <x-text-input wire:model="mobile" id="mobile" class="mt-1 block w-full" />
                                            <x-input-error :messages="$errors->get('mobile')" class="mt-2" />
                                        </div>
                                        <div>
                                            <x-input-label for="home_telephone" value="Home Telephone" />
                                            <x-text-input wire:model="home_telephone" id="home_telephone" class="mt-1 block w-full" />
                                            <x-input-error :messages="$errors->get('home_telephone')" class="mt-2" />
                                        </div>
                                        <div>
                                            <x-input-label for="email" value="Email Address" />
                                            <x-text-input wire:model="email" id="email" type="email" class="mt-1 block w-full" />
                                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                                        </div>
                                        @if ($social !== '')<div>
                                            <x-input-label for="social" value="Legacy social contact (platform unknown)" />
                                            <x-text-input wire:model="social" id="social" class="mt-1 block w-full" />
                                        </div>@endif
                                        @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'twitter' => 'Twitter / X'] as $platform => $label)
                                            <div><x-input-label :for="'social-'.$platform" :value="$label.' account'" /><x-text-input :id="'social-'.$platform" :wire:model="'socialAccounts.'.$platform" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('socialAccounts.'.$platform)" class="mt-1" /></div>
                                        @endforeach
                                        <div class="sm:col-span-2 flex justify-end gap-3">
                                            <button type="button" @click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancelEdit() }" class="text-sm text-slate-600">Cancel</button>
                                            <x-primary-button wire:loading.attr="disabled">Save</x-primary-button><span wire:loading role="status" class="self-center text-xs text-slate-500">Saving...</span>
                                        </div>
                                    </form>
                                </div>
                            @else
                                <dl class="grid gap-x-6 gap-y-2 text-sm lg:grid-cols-2 [&>div]:grid [&>div]:gap-x-3 sm:[&>div]:grid-cols-[9rem_minmax(0,1fr)] [&_dd]:min-w-0 [&_dd]:break-words [&_dd]:!mt-0">
                                    <div><dt class="text-slate-500">Mobile Number</dt><dd class="mt-1 text-slate-900">{{ $customer->primary_mobile ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Home Telephone</dt><dd class="mt-1 text-slate-900">{{ $customer->contacts->firstWhere('type', \App\Enums\ContactType::Home)?->value ?: 'Not provided' }}</dd></div>
                                    <div><dt class="text-slate-500">Email Address</dt><dd class="mt-1 text-slate-900">{{ $customer->primary_email ?: 'Not provided' }}</dd></div>
                                    @if ($customer->contacts->first(fn ($contact) => $contact->type === \App\Enums\ContactType::Social && $contact->platform === null))<div><dt class="text-slate-500">Legacy social contact (platform unknown)</dt><dd class="mt-1 text-slate-900">{{ $customer->contacts->first(fn ($contact) => $contact->type === \App\Enums\ContactType::Social && $contact->platform === null)?->value }}</dd></div>@endif
                                    @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'twitter' => 'Twitter / X'] as $platform => $label)
                                        <div><dt class="text-slate-500">{{ $label }}</dt><dd class="mt-1 text-slate-900">{{ $customer->contacts->first(fn ($contact) => $contact->type === \App\Enums\ContactType::Social && $contact->platform === $platform)?->value ?: 'Not provided' }}</dd></div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                    </section>

                    {{-- Address --}}
                    <section
                        id="address"
                        class="rounded-lg border border-slate-200 bg-white"
                    >
                        <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
                            <h2 class="min-w-0 flex-1 text-base font-semibold text-navy-900">Address Information</h2>
                            @if ($editingSection !== 'address')
                                <button type="button" @click="if (dirty && ! await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) return; dirty = false; $wire.editSection('address')" class="text-sm font-medium text-navy-700 hover:text-navy-900 shrink-0">Edit</button>
                            @endif
                        </div>
                        <div class="px-4 py-4 sm:px-5">
                            @php
                                $residential = $customer->addresses->firstWhere('type', \App\Enums\AddressType::Residential);
                                $mailing = $customer->addresses->firstWhere('type', \App\Enums\AddressType::Mailing);
                            @endphp
                            @if ($editingSection === 'address')
                                <form wire:submit="saveAddress" class="space-y-6">
                                    <div>
                                        <h3 class="text-sm font-semibold text-slate-800 mb-3">Residential Address</h3>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div class="sm:col-span-2">
                                                <x-input-label for="residential_line_1" value="Address Line 1" />
                                                <x-text-input wire:model="residential_line_1" id="residential_line_1" class="mt-1 block w-full" />
                                            </div>
                                            <div class="sm:col-span-2">
                                                <x-input-label for="residential_line_2" value="Address Line 2" />
                                                <x-text-input wire:model="residential_line_2" id="residential_line_2" class="mt-1 block w-full" />
                                            </div>
                                            <div>
                                                <x-input-label for="residential_city" value="City" />
                                                <x-text-input wire:model="residential_city" id="residential_city" class="mt-1 block w-full" />
                                            </div>
                                            <div>
                                                <x-input-label for="residential_state" value="State" />
                                                <x-text-input wire:model="residential_state" id="residential_state" class="mt-1 block w-full" />
                                            </div>
                                            <div>
                                                <x-input-label for="residential_postcode" value="Postcode" />
                                                <x-text-input wire:model="residential_postcode" id="residential_postcode" class="mt-1 block w-full" />
                                            </div>
                                            <div>
                                                <x-input-label for="residential_country" value="Country" />
                                                <x-text-input wire:model="residential_country" id="residential_country" class="mt-1 block w-full" />
                                            </div>
                                            <div>
                                                <x-input-label for="occupancy_status" value="Property Occupancy" />
                                                <select wire:model.live="occupancy_status" id="occupancy_status" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-navy-500 focus:ring-navy-500 text-sm">
                                                    <option value="">— Select —</option>
                                                    @foreach (\App\Enums\OccupancyStatus::cases() as $status)
                                                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            @if ($occupancy_status === 'other')<div><x-input-label for="occupancy_other" value="Other property status (specify)" /><x-text-input wire:model="occupancy_other" id="occupancy_other" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('occupancy_other')" class="mt-1" /></div>@endif
                                        </div>
                                    </div>

                                    <div>
                                        <label class="flex items-center gap-2 text-sm text-slate-700">
                                            <input type="checkbox" wire:model.live="same_as_residential" class="rounded border-slate-300 text-navy-700 focus:ring-navy-500">
                                            Mailing address same as residential
                                        </label>
                                    </div>

                                    @if (! $same_as_residential)
                                        <div>
                                            <h3 class="text-sm font-semibold text-slate-800 mb-3">Mailing Address</h3>
                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <div class="sm:col-span-2">
                                                    <x-input-label for="mailing_line_1" value="Address Line 1" />
                                                    <x-text-input wire:model="mailing_line_1" id="mailing_line_1" class="mt-1 block w-full" />
                                                </div>
                                                <div class="sm:col-span-2">
                                                    <x-input-label for="mailing_line_2" value="Address Line 2" />
                                                    <x-text-input wire:model="mailing_line_2" id="mailing_line_2" class="mt-1 block w-full" />
                                                </div>
                                                <div>
                                                    <x-input-label for="mailing_city" value="City" />
                                                    <x-text-input wire:model="mailing_city" id="mailing_city" class="mt-1 block w-full" />
                                                </div>
                                                <div>
                                                    <x-input-label for="mailing_state" value="State" />
                                                    <x-text-input wire:model="mailing_state" id="mailing_state" class="mt-1 block w-full" />
                                                </div>
                                                <div>
                                                    <x-input-label for="mailing_postcode" value="Postcode" />
                                                    <x-text-input wire:model="mailing_postcode" id="mailing_postcode" class="mt-1 block w-full" />
                                                </div>
                                                <div>
                                                    <x-input-label for="mailing_country" value="Country" />
                                                    <x-text-input wire:model="mailing_country" id="mailing_country" class="mt-1 block w-full" />
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    <div class="flex justify-end gap-3">
                                        <button type="button" @click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancelEdit() }" class="text-sm text-slate-600">Cancel</button>
                                        <x-primary-button wire:loading.attr="disabled">Save</x-primary-button><span wire:loading role="status" class="self-center text-xs text-slate-500">Saving...</span>
                                    </div>
                                </form>
                            @else
                                <div class="grid gap-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <h3 class="font-medium text-slate-800 mb-2">Residential Address</h3>
                                        @if ($residential && ! $residential->isEmpty())
                                            <p class="text-slate-900 whitespace-pre-line">{{ collect([$residential->line_1, $residential->line_2, collect([$residential->postcode, $residential->city])->filter()->implode(' '), $residential->state, $residential->country])->filter()->implode("\n") }}</p>
                                            <p class="mt-2 text-slate-500">Property Occupancy: {{ $residential->occupancy_status?->label() ?: 'Not provided' }}{{ $residential->occupancy_status === \App\Enums\OccupancyStatus::Other && $residential->occupancy_other ? ': '.$residential->occupancy_other : '' }}</p>
                                        @else
                                            <p class="text-slate-500">Not provided</p>
                                        @endif
                                    </div>
                                    <div>
                                        <h3 class="font-medium text-slate-800 mb-2">Mailing Address</h3>
                                        @if ($mailing?->same_as_residential)
                                            <p class="text-slate-900">Same as Residential Address</p>
                                        @elseif ($mailing && ! $mailing->isEmpty())
                                            <p class="text-slate-900 whitespace-pre-line">{{ collect([$mailing->line_1, $mailing->line_2, collect([$mailing->postcode, $mailing->city])->filter()->implode(' '), $mailing->state, $mailing->country])->filter()->implode("\n") }}</p>
                                        @else
                                            <p class="text-slate-500">Not provided</p>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </section>

            </div>
            <div id="panel-relationships" role="tabpanel" aria-labelledby="tab-relationships" x-show="activeTab === 'relationships'" x-cloak>
                <livewire:customers.family-relationships :customer="$customer" :key="'family-'.$customer->id" />
            </div>
            <div id="panel-work" role="tabpanel" aria-labelledby="tab-work" x-show="activeTab === 'work'" x-cloak class="space-y-4">
                <livewire:customers.work-finance :customer="$customer" :key="'work-'.$customer->id" />
            </div>
            <div id="panel-financial" role="tabpanel" aria-labelledby="tab-financial" x-show="activeTab === 'financial'" x-cloak class="space-y-4">
                <livewire:customers.financial :customer="$customer" :key="'financial-'.$customer->id" />
                <livewire:customers.bank-accounts :customer="$customer" :key="'bank-'.$customer->id" />
            </div>
            <div id="panel-investment" role="tabpanel" aria-labelledby="tab-investment" x-show="activeTab === 'investment'" x-cloak class="space-y-4">
                <livewire:customers.investment :customer="$customer" :key="'investment-'.$customer->id" />
            </div>
            <div id="panel-tax" role="tabpanel" aria-labelledby="tab-tax" x-show="activeTab === 'tax'" x-cloak class="space-y-4">
                <livewire:customers.tax-residency :customer="$customer" :key="'tax-'.$customer->id" />
            </div>
            <div id="panel-compliance" role="tabpanel" aria-labelledby="tab-compliance" x-show="activeTab === 'compliance'" x-cloak class="space-y-4">
                <livewire:customers.compliance :customer="$customer" :key="'compliance-'.$customer->id" />
            </div>
    </div>
    <div x-show="activityOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" x-on:keydown.escape.window="if (activityOpen) closeActivity()">
        <div class="absolute inset-0 bg-slate-900/30" x-on:click="closeActivity()" aria-hidden="true"></div>
        <div id="customer-activity-modal" role="dialog" aria-modal="true" aria-labelledby="activity-title" x-trap.noscroll="activityOpen" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-xl flex-col overflow-hidden rounded-lg bg-white shadow-xl sm:max-h-[calc(100dvh-3rem)]">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 sm:px-5">
                <h2 id="activity-title" class="text-base font-semibold text-[#0F172A]">Activity log</h2>
                <button type="button" x-ref="closeActivity" x-on:click="closeActivity()" aria-label="Close activity log" class="rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">Close</button>
            </div>
            <ol class="min-h-0 divide-y divide-slate-200 overflow-y-auto px-4 sm:px-5">
                @forelse ($customer->activities as $activity)
                    <li class="py-4 text-sm">
                        <p class="font-medium text-[#0F172A]">{{ $activity->description ?: $activity->action->label() }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}</time>
                            <span aria-hidden="true"> · </span>by {{ $activity->user?->name ?: 'System' }}
                        </p>
                    </li>
                @empty
                    <li class="py-5 text-sm text-slate-500">No activity has been recorded for this customer.</li>
                @endforelse
            </ol>
        </div>
    </div>
</div>
