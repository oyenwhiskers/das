<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10">
        <div class="max-w-3xl">
            <div class="mb-6">
                <a href="{{ route('customers.index') }}" wire:navigate class="text-sm text-navy-700 hover:text-navy-900">&larr; Back to Customers</a>
                <h1 class="mt-2 text-2xl font-semibold text-navy-900">Add Customer</h1>
                <p class="mt-1 text-sm text-slate-500">Register essential details now. You can complete the profile later.</p>
            </div>

            @if (count($strongMatches) > 0)
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
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
                <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
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

            <form wire:submit="save" class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <x-input-label for="full_legal_name" value="Full Legal Name *" />
                    <x-text-input wire:model="full_legal_name" id="full_legal_name" class="block mt-1 w-full" type="text" required />
                    <p class="mt-1 text-xs text-slate-500">Store the name as it appears on official identification.</p>
                    <x-input-error :messages="$errors->get('full_legal_name')" class="mt-2" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="identification_type" value="Identification Type" />
                        <select id="identification_type" wire:model.live="identification_type" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-navy-500 focus:ring-navy-500 text-sm">
                            <option value="">— Select —</option>
                            @foreach (\App\Enums\IdentificationType::cases() as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('identification_type')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="identification_number" value="Identification Number" />
                        <x-text-input wire:model.live.debounce.300ms="identification_number" id="identification_number" class="block mt-1 w-full" type="text" />
                        <p class="mt-1 text-xs text-slate-500">NRIC date of birth, gender, and nationality suggestions are unverified. Check and correct them before saving.</p>
                        <x-input-error :messages="$errors->get('identification_number')" class="mt-2" />
                    </div>
                </div>

                @if (in_array($identification_type, ['passport', 'other']))
                    <div>
                        <x-input-label for="issuing_jurisdiction" value="Issuing Country Code *" />
                        <x-text-input wire:model="issuing_jurisdiction" id="issuing_jurisdiction" class="block mt-1 w-full" maxlength="2" placeholder="e.g. MY" />
                        <p class="mt-1 text-xs text-slate-500">Two-letter country code of the document issuer.</p>
                        <x-input-error :messages="$errors->get('issuing_jurisdiction')" class="mt-2" />
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="date_of_birth" value="Date of Birth" />
                        <x-text-input wire:model="date_of_birth" id="date_of_birth" type="date" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('date_of_birth')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="gender" value="Gender" />
                        <select id="gender" wire:model="gender" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-navy-500 focus:ring-navy-500 text-sm">
                            <option value="">— Select —</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                        <x-input-error :messages="$errors->get('gender')" class="mt-2" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="mobile" value="Mobile Number" />
                        <x-text-input wire:model="mobile" id="mobile" class="block mt-1 w-full" type="text" />
                        <x-input-error :messages="$errors->get('mobile')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="email" value="Email Address" />
                        <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>
                </div>

                <div>
                    <x-input-label for="nationality" value="Nationality" />
                    <x-text-input wire:model="nationality" id="nationality" class="block mt-1 w-full" type="text" />
                    <x-input-error :messages="$errors->get('nationality')" class="mt-2" />
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="{{ route('customers.index') }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">Cancel</a>
                    <x-primary-button wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Customer</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>
</div>
