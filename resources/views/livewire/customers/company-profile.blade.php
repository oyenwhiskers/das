<div class="space-y-4">
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 sm:px-5">
            <div>
                <h2 class="text-base font-semibold text-navy-900">Company Information</h2>
                <p class="text-xs text-slate-500">Registered entity details from the corporate application form.</p>
            </div>
            @if ($editingSection !== 'company')
                <button type="button" wire:click="editSection('company')" class="text-sm font-medium text-navy-700 hover:text-navy-900">Edit</button>
            @endif
        </div>
        <div class="p-4 sm:p-5">
            @if ($editingSection === 'company')
                @if (count($weakMatches) > 0)
                    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <p class="font-medium">Possible duplicate customers were found.</p>
                        <ul class="mt-2 list-disc ps-5">
                            @foreach ($weakMatches as $match)
                                <li><a href="{{ route('customers.show', $match['reference']) }}" class="underline font-medium" wire:navigate>{{ $match['reference'] }} — {{ $match['full_legal_name'] }}</a></li>
                            @endforeach
                        </ul>
                        <label class="mt-3 flex items-center gap-2"><input type="checkbox" wire:model="confirmWeakDuplicates" class="rounded border-slate-300 text-navy-700"> I reviewed these possible matches.</label>
                    </div>
                @endif
                <form wire:submit="saveCompany" class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="full_legal_name" class="sm:pt-2" value="Registered Name *" />
                        <div>
                            <x-text-input wire:model="full_legal_name" id="full_legal_name" class="block w-full" />
                            <x-input-error :messages="$errors->get('full_legal_name')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="identification_number" class="sm:pt-2" value="Business Registration No." />
                        <div>
                            <x-text-input wire:model="identification_number" id="identification_number" class="block w-full" />
                            <x-input-error :messages="$errors->get('identification_number')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="registered_at" class="sm:pt-2" value="Date of Registration" />
                        <div>
                            <x-text-input wire:model="registered_at" id="registered_at" type="date" class="block w-full" />
                            <x-input-error :messages="$errors->get('registered_at')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="registration_country" class="sm:pt-2" value="Country of Registration" />
                        <div>
                            <x-text-input wire:model="registration_country" id="registration_country" maxlength="2" placeholder="e.g. MY" class="block w-full" />
                            <x-input-error :messages="$errors->get('registration_country')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="entity_type" class="sm:pt-2" value="Entity Type" />
                        <div>
                            <x-text-input wire:model="entity_type" id="entity_type" class="block w-full" placeholder="e.g. Companies" />
                            <x-input-error :messages="$errors->get('entity_type')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="entity_sub_type" class="sm:pt-2" value="Entity Sub Type" />
                        <div>
                            <x-text-input wire:model="entity_sub_type" id="entity_sub_type" class="block w-full" placeholder="e.g. Ordinary Company" />
                            <x-input-error :messages="$errors->get('entity_sub_type')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="industry" class="sm:pt-2" value="Industry of Business" />
                        <div>
                            <x-text-input wire:model="industry" id="industry" class="block w-full" />
                            <x-input-error :messages="$errors->get('industry')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="business_activity" class="sm:pt-2" value="Activity" />
                        <div>
                            <x-text-input wire:model="business_activity" id="business_activity" class="block w-full" />
                            <x-input-error :messages="$errors->get('business_activity')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="authorized_capital_rm" class="sm:pt-2" value="Authorized Capital (RM)" />
                        <div>
                            <x-text-input wire:model="authorized_capital_rm" id="authorized_capital_rm" type="number" min="0" step="0.01" class="block w-full" />
                            <x-input-error :messages="$errors->get('authorized_capital_rm')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="paid_up_capital_rm" class="sm:pt-2" value="Paid-up Capital (RM)" />
                        <div>
                            <x-text-input wire:model="paid_up_capital_rm" id="paid_up_capital_rm" type="number" min="0" step="0.01" class="block w-full" />
                            <x-input-error :messages="$errors->get('paid_up_capital_rm')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="mobile" class="sm:pt-2" value="Phone No." />
                        <div>
                            <x-text-input wire:model="mobile" id="mobile" class="block w-full" />
                            <x-input-error :messages="$errors->get('mobile')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="fax" class="sm:pt-2" value="Fax No." />
                        <div>
                            <x-text-input wire:model="fax" id="fax" class="block w-full" />
                            <x-input-error :messages="$errors->get('fax')" class="mt-1" />
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="email" class="sm:pt-2" value="Email Address" />
                        <div>
                            <x-text-input wire:model="email" id="email" type="email" class="block w-full" />
                            <x-input-error :messages="$errors->get('email')" class="mt-1" />
                        </div>
                    </div>
                    <div class="flex gap-3 sm:col-span-2">
                        <x-secondary-button type="button" wire:click="cancel" class="flex-1 justify-center">Cancel</x-secondary-button>
                        <x-primary-button class="flex-1 justify-center">Save</x-primary-button>
                    </div>
                </form>
            @else
                @php $details = $customer->corporateDetails; @endphp
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Registered Name</dt>
                        <dd class="font-medium text-slate-900">{{ $customer->full_legal_name }}</dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Business Registration No.</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$customer->primary_identification_number" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Date of Registration</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$details?->registered_at?->format('d M Y')" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Country of Registration</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$details?->registration_country" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Entity Type</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$details?->entity_type" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Entity Sub Type</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$details?->entity_sub_type" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Industry</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$details?->industry" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Activity</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$details?->business_activity" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Authorized Capital</dt>
                        <dd class="text-slate-900">{{ $details?->authorized_capital_rm !== null ? 'RM '.number_format((float) $details->authorized_capital_rm, 2) : 'Not provided' }}</dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Paid-up Capital</dt>
                        <dd class="text-slate-900">{{ $details?->paid_up_capital_rm !== null ? 'RM '.number_format((float) $details->paid_up_capital_rm, 2) : 'Not provided' }}</dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Phone No.</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$customer->primary_mobile" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Fax No.</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$customer->contacts->firstWhere('type', \App\Enums\ContactType::Fax)?->value" optional /></dd>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <dt class="text-slate-500">Email</dt>
                        <dd class="text-slate-900"><x-missing-field :value="$customer->primary_email" optional /></dd>
                    </div>
                </dl>
            @endif
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 sm:px-5">
            <div>
                <h2 class="text-base font-semibold text-navy-900">Addresses</h2>
                <p class="text-xs text-slate-500">Registered and mailing addresses.</p>
            </div>
            @if ($editingSection !== 'address')
                <button type="button" wire:click="editSection('address')" class="text-sm font-medium text-navy-700 hover:text-navy-900">Edit</button>
            @endif
        </div>
        <div class="p-4 sm:p-5">
            @if ($editingSection === 'address')
                <form wire:submit="saveAddress" class="space-y-4">
                    <div>
                        <h3 class="mb-3 text-sm font-medium text-slate-800">Registered Address</h3>
                        <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                            <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Address Line 1" />
                                <x-text-input wire:model="registered_line_1" class="block w-full" />
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Address Line 2" />
                                <x-text-input wire:model="registered_line_2" class="block w-full" />
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="City" />
                                <x-text-input wire:model="registered_city" class="block w-full" />
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="State" />
                                <x-text-input wire:model="registered_state" class="block w-full" />
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Postcode" />
                                <x-text-input wire:model="registered_postcode" class="block w-full" />
                            </div>
                            <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                <x-input-label class="sm:pt-2" value="Country" />
                                <x-text-input wire:model="registered_country" class="block w-full" />
                            </div>
                        </div>
                    </div>
                    <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="same_as_registered" class="sm:pt-2" value="Mailing Address" />
                        <div>
                            <select id="same_as_registered" wire:model.live="same_as_registered" class="block w-full rounded-md border-slate-300 text-sm">
                                <option value="same">Same with registered address</option>
                                <option value="not_same">Not same with registered address</option>
                            </select>
                        </div>
                    </div>
                    @if ($same_as_registered === 'not_same')
                        <div>
                            <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                                <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Address Line 1" />
                                    <x-text-input wire:model="mailing_line_1" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Address Line 2" />
                                    <x-text-input wire:model="mailing_line_2" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="City" />
                                    <x-text-input wire:model="mailing_city" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="State" />
                                    <x-text-input wire:model="mailing_state" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Postcode" />
                                    <x-text-input wire:model="mailing_postcode" class="block w-full" />
                                </div>
                                <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                                    <x-input-label class="sm:pt-2" value="Country" />
                                    <x-text-input wire:model="mailing_country" class="block w-full" />
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="flex gap-3">
                        <x-secondary-button type="button" wire:click="cancel" class="flex-1 justify-center">Cancel</x-secondary-button>
                        <x-primary-button class="flex-1 justify-center">Save</x-primary-button>
                    </div>
                </form>
            @else
                @php
                    $registered = $customer->addresses->firstWhere('type', \App\Enums\AddressType::Registered);
                    $mailing = $customer->addresses->firstWhere('type', \App\Enums\AddressType::Mailing);
                @endphp
                <div class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <h3 class="mb-2 font-medium text-slate-800">Registered Address</h3>
                        @if ($registered && filled($registered->line_1))
                            <p class="whitespace-pre-line text-slate-900">{{ collect([$registered->line_1, $registered->line_2, collect([$registered->postcode, $registered->city])->filter()->implode(' '), $registered->state, $registered->country])->filter()->implode("\n") }}</p>
                        @else
                            <x-missing-field />
                        @endif
                    </div>
                    <div>
                        <h3 class="mb-2 font-medium text-slate-800">Mailing Address</h3>
                        @if ($mailing?->same_as_residential)
                            <p class="text-slate-900">Same as Registered Address</p>
                        @elseif ($mailing && filled($mailing->line_1))
                            <p class="whitespace-pre-line text-slate-900">{{ collect([$mailing->line_1, $mailing->line_2, collect([$mailing->postcode, $mailing->city])->filter()->implode(' '), $mailing->state, $mailing->country])->filter()->implode("\n") }}</p>
                        @else
                            <x-missing-field optional />
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </section>
</div>
