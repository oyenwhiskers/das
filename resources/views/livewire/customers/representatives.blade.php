@php
    $slots = [
        [
            'title' => 'Primary Contact',
            'subtitle' => 'Primary contact person for this corporate account.',
            'row' => $primaryContact,
            'role' => 'primary_contact',
            'order' => '',
            'editing' => $mode === 'edit' && $representativeRole === 'primary_contact',
        ],
        [
            'title' => 'Signatory 1',
            'subtitle' => 'First authorized signatory.',
            'row' => $signatory1,
            'role' => 'authorized_signatory',
            'order' => '1',
            'editing' => $mode === 'edit' && $representativeRole === 'authorized_signatory' && $signatoryOrder === '1',
        ],
        [
            'title' => 'Signatory 2',
            'subtitle' => 'Second authorized signatory.',
            'row' => $signatory2,
            'role' => 'authorized_signatory',
            'order' => '2',
            'editing' => $mode === 'edit' && $representativeRole === 'authorized_signatory' && $signatoryOrder === '2',
        ],
    ];
@endphp

<div class="space-y-4">
    @foreach ($slots as $slot)
        @php
            $row = $slot['row'];
            $person = $row?->relatedPerson;
        @endphp
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 sm:px-5">
                <div>
                    <h2 class="text-base font-semibold text-navy-900">{{ $slot['title'] }}</h2>
                    <p class="text-xs text-slate-500">{{ $slot['subtitle'] }}</p>
                </div>
                @unless ($slot['editing'])
                    <div class="flex shrink-0 gap-3">
                        @if ($row)
                            <button type="button" wire:click="edit({{ $row->id }})" class="text-sm font-medium text-navy-700 hover:text-navy-900">Edit</button>
                            <button type="button" wire:click="remove({{ $row->id }})" wire:confirm="Remove this representative?" class="text-sm font-medium text-slate-600 hover:text-slate-900">Remove</button>
                        @elseif ($slot['order'] === '')
                            <button type="button" wire:click="add('{{ $slot['role'] }}')" class="text-sm font-medium text-navy-700 hover:text-navy-900">Edit</button>
                        @else
                            <button type="button" wire:click="add('{{ $slot['role'] }}', '{{ $slot['order'] }}')" class="text-sm font-medium text-navy-700 hover:text-navy-900">Edit</button>
                        @endif
                    </div>
                @endunless
            </div>

            <div class="p-4 sm:p-5">
                @if ($slot['editing'])
                    <form wire:submit="save" class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <x-input-label class="sm:pt-2" value="Full Name *" />
                            <div>
                                <x-text-input wire:model="fullLegalName" class="block w-full" />
                                <x-input-error :messages="$errors->get('fullLegalName')" class="mt-1" />
                            </div>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <x-input-label class="sm:pt-2" value="Identification Type" />
                            <div>
                                <select wire:model="identificationType" class="block w-full rounded-md border-slate-300 text-sm">
                                    <option value="">— Select —</option>
                                    @foreach ([\App\Enums\IdentificationType::Nric, \App\Enums\IdentificationType::Passport, \App\Enums\IdentificationType::Other] as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('identificationType')" class="mt-1" />
                            </div>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <x-input-label class="sm:pt-2" value="NRIC / Passport / Other ID" />
                            <div>
                                <x-text-input wire:model="identificationNumber" class="block w-full" />
                                <x-input-error :messages="$errors->get('identificationNumber')" class="mt-1" />
                            </div>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <x-input-label class="sm:pt-2" value="Mobile No." />
                            <div>
                                <x-text-input wire:model="mobile" class="block w-full" />
                                <x-input-error :messages="$errors->get('mobile')" class="mt-1" />
                            </div>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <x-input-label class="sm:pt-2" value="Office Telephone" />
                            <div>
                                <x-text-input wire:model="homeTelephone" class="block w-full" />
                                <x-input-error :messages="$errors->get('homeTelephone')" class="mt-1" />
                            </div>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <x-input-label class="sm:pt-2" value="Extension No." />
                            <div>
                                <x-text-input wire:model="officeExtension" class="block w-full" />
                                <x-input-error :messages="$errors->get('officeExtension')" class="mt-1" />
                            </div>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <x-input-label class="sm:pt-2" value="Email Address" />
                            <div>
                                <x-text-input wire:model="email" type="email" class="block w-full" />
                                <x-input-error :messages="$errors->get('email')" class="mt-1" />
                            </div>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <x-input-label class="sm:pt-2" value="Occupation / Designation" />
                            <div>
                                <x-text-input wire:model="occupation" class="block w-full" />
                                <x-input-error :messages="$errors->get('occupation')" class="mt-1" />
                            </div>
                        </div>
                        <div class="flex gap-3 sm:col-span-2">
                            <x-secondary-button type="button" wire:click="cancel" class="flex-1 justify-center">Cancel</x-secondary-button>
                            <x-primary-button class="flex-1 justify-center">Save</x-primary-button>
                        </div>
                    </form>
                @else
                    <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <dt class="text-slate-500">Full Name</dt>
                            <dd class="font-medium text-slate-900"><x-missing-field :value="$person?->full_legal_name" /></dd>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <dt class="text-slate-500">Identification Type</dt>
                            <dd class="text-slate-900"><x-missing-field :value="$person?->identification_type?->label()" optional /></dd>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <dt class="text-slate-500">NRIC / Passport / Other ID</dt>
                            <dd class="text-slate-900"><x-missing-field :value="$person?->identification_number" optional /></dd>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <dt class="text-slate-500">Mobile No.</dt>
                            <dd class="text-slate-900"><x-missing-field :value="$person?->mobile" optional /></dd>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <dt class="text-slate-500">Office Telephone</dt>
                            <dd class="text-slate-900"><x-missing-field :value="$person?->home_telephone" optional /></dd>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <dt class="text-slate-500">Extension No.</dt>
                            <dd class="text-slate-900"><x-missing-field :value="$person?->office_extension" optional /></dd>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <dt class="text-slate-500">Email</dt>
                            <dd class="text-slate-900"><x-missing-field :value="$person?->email" optional /></dd>
                        </div>
                        <div class="grid gap-x-3 gap-y-1 sm:col-span-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start">
                            <dt class="text-slate-500">Occupation / Designation</dt>
                            <dd class="text-slate-900"><x-missing-field :value="$person?->occupation" optional /></dd>
                        </div>
                    </dl>
                @endif
            </div>
        </section>
    @endforeach
</div>
