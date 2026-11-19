@php
    $isCorporate = $customerType === \App\Enums\CustomerType::Corporate->value;
    $indexRoute = $isCorporate ? 'corporate-customers.index' : 'customers.index';
@endphp
<div class="py-6">
    <div class="w-full space-y-4 px-4 sm:px-6 lg:px-8 xl:px-10">
        <div>
            <a href="{{ route($indexRoute) }}" wire:navigate class="text-sm text-navy-700 hover:text-navy-900">&larr; Back to {{ $isCorporate ? 'Corporate' : 'Individual' }}</a>
            <h1 class="mt-2 text-2xl font-semibold text-navy-900">{{ $isCorporate ? 'Add Corporate' : 'Add Individual' }}</h1>
            <p class="mt-1 text-sm text-slate-500">Register essential details now. You can complete the profile later.</p>
        </div>

        @if (count($strongMatches) > 0)
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">A customer with the same identification already exists. Creation is blocked.</p>
                <ul class="mt-2 list-disc ps-5">
                    @foreach ($strongMatches as $match)
                        <li>
                            <a href="{{ route('customers.show', $match['reference']) }}" class="underline font-medium" wire:navigate>
                                {{ $match['reference'] }} — {{ $match['full_legal_name'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (count($weakMatches) > 0 && count($strongMatches) === 0)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <p class="font-medium">Possible duplicate customers were found.</p>
                <ul class="mt-2 list-disc ps-5">
                    @foreach ($weakMatches as $match)
                        <li>
                            <a href="{{ route('customers.show', $match['reference']) }}" class="underline font-medium" wire:navigate>
                                {{ $match['reference'] }} — {{ $match['full_legal_name'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
                <label class="mt-3 flex items-center gap-2">
                    <input type="checkbox" wire:model="confirmWeakDuplicates" class="rounded border-slate-300 text-navy-700 focus:ring-navy-500">
                    <span>I have reviewed the matches and want to create a new customer anyway.</span>
                </label>
            </div>
        @endif

        <form wire:submit="save" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                <x-input-label for="full_legal_name" class="sm:pt-2" :value="$isCorporate ? 'Registered Name *' : 'Full Legal Name *'" />
                <div>
                    <x-text-input wire:model="full_legal_name" id="full_legal_name" class="block w-full" type="text" required />
                    <p class="mt-1 text-xs text-slate-500">{{ $isCorporate ? 'Store the name as registered with the company registrar.' : 'Store the name as it appears on official identification.' }}</p>
                    <x-input-error :messages="$errors->get('full_legal_name')" class="mt-2" />
                </div>
            </div>

            @if ($isCorporate)
                <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                    <x-input-label for="identification_number" class="sm:pt-2" value="Business Registration No." />
                    <div>
                        <x-text-input wire:model.live.debounce.300ms="identification_number" id="identification_number" class="block w-full" type="text" />
                        <x-input-error :messages="$errors->get('identification_number')" class="mt-2" />
                        <x-input-error :messages="$errors->get('identification_type')" class="mt-2" />
                    </div>
                </div>
            @else
                <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                    <x-input-label for="identification_type" class="sm:pt-2" value="Identification Type" />
                    <div>
                        <select id="identification_type" wire:model.live="identification_type" class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500">
                            <option value="">— Select —</option>
                            @foreach ([\App\Enums\IdentificationType::Nric, \App\Enums\IdentificationType::Passport, \App\Enums\IdentificationType::Other] as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('identification_type')" class="mt-2" />
                    </div>
                </div>

                <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                    <x-input-label for="identification_number" class="sm:pt-2" value="Identification Number" />
                    <div>
                        <x-text-input wire:model.live.debounce.300ms="identification_number" id="identification_number" class="block w-full" type="text" />
                        <p class="mt-1 text-xs text-slate-500">NRIC date of birth, gender, and nationality suggestions are unverified. Check and correct them before saving.</p>
                        <x-input-error :messages="$errors->get('identification_number')" class="mt-2" />
                    </div>
                </div>

                @if (in_array($identification_type, ['passport', 'other']))
                    <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                        <x-input-label for="issuing_jurisdiction" class="sm:pt-2" value="Issuing Country Code *" />
                        <div>
                            <x-text-input wire:model="issuing_jurisdiction" id="issuing_jurisdiction" class="block w-full" maxlength="2" placeholder="e.g. MY" />
                            <p class="mt-1 text-xs text-slate-500">Two-letter country code of the document issuer.</p>
                            <x-input-error :messages="$errors->get('issuing_jurisdiction')" class="mt-2" />
                        </div>
                    </div>
                @endif

                <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                    <x-input-label for="date_of_birth" class="sm:pt-2" value="Date of Birth" />
                    <div>
                        <x-text-input wire:model="date_of_birth" id="date_of_birth" type="date" class="block w-full" />
                        <x-input-error :messages="$errors->get('date_of_birth')" class="mt-2" />
                    </div>
                </div>

                <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                    <x-input-label for="gender" class="sm:pt-2" value="Gender" />
                    <div>
                        <select id="gender" wire:model="gender" class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500">
                            <option value="">— Select —</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                        <x-input-error :messages="$errors->get('gender')" class="mt-2" />
                    </div>
                </div>
            @endif

            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                <x-input-label for="mobile" class="sm:pt-2" :value="$isCorporate ? 'Phone No.' : 'Mobile Number'" />
                <div>
                    <x-text-input wire:model="mobile" id="mobile" class="block w-full" type="text" />
                    <x-input-error :messages="$errors->get('mobile')" class="mt-2" />
                </div>
            </div>

            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                <x-input-label for="email" class="sm:pt-2" value="Email Address" />
                <div>
                    <x-text-input wire:model="email" id="email" class="block w-full" type="email" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>
            </div>

            @unless ($isCorporate)
                <div class="grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-start">
                    <x-input-label for="nationality" class="sm:pt-2" value="Nationality" />
                    <div>
                        <x-text-input wire:model="nationality" id="nationality" class="block w-full" type="text" />
                        <x-input-error :messages="$errors->get('nationality')" class="mt-2" />
                    </div>
                </div>
            @endunless

            <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                <a href="{{ route($indexRoute) }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">Cancel</a>
                <x-primary-button wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
