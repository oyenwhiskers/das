<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('bimbsec.index') }}" wire:navigate class="text-sm text-navy-800 hover:underline">BIMBSEC</a>
                <h1 class="text-xl font-semibold text-navy-900">{{ $customer->full_legal_name }}</h1>
                <p class="text-sm text-slate-500">{{ $customer->primary_identification_number ?: 'No NRIC / passport on the profile' }}</p>
            </div>
        </div>

        <form wire:submit="saveForm" class="space-y-3">
            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Choices</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="account_type">Account type</label>
                        <select id="account_type" wire:model="account_type" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="trading">Trading</option>
                            <option value="margin">Margin</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="margin_credit_limit">Margin limit</label>
                        <input id="margin_credit_limit" wire:model="margin_credit_limit" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="lth_group_employee">LTH Group employee</label>
                        <select id="lth_group_employee" wire:model="lth_group_employee" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">No, unless changed</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="online_trading">Online trading</label>
                        <select id="online_trading" wire:model="online_trading" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="estatement">Statement delivery</label>
                        <select id="estatement" wire:model="estatement" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="email">By e-mail</option>
                            <option value="bisonline">BISonline</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="payment">Payment instruction</label>
                        <select id="payment" wire:model="payment" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="cheque">Cheque</option>
                            <option value="epayment">e-Payment to bank</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="relationship_id">Spouse or next of kin</label>
                        <select id="relationship_id" wire:model.live="relationship_id" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">Use the spouse or next of kin on the profile</option>
                            <option value="none">Leave blank</option>
                            @foreach ($relationships as $relationship)
                                <option value="{{ $relationship->id }}">{{ $relationship->displayName() }} ({{ $relationship->relationshipLabel() }})</option>
                            @endforeach
                        </select>
                        @error('relationship_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="bank_account_id">Bank account</label>
                        <select id="bank_account_id" wire:model="bank_account_id" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">Use the primary Malaysia account</option>
                            <option value="none">Leave blank</option>
                            @foreach ($banks as $bank)
                                <option value="{{ $bank->id }}">{{ $bank->bank_name }} {{ $bank->maskedAccountNumber() }}</option>
                            @endforeach
                        </select>
                        @error('bank_account_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </details>

            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Applicant details</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="old_nric">Old NRIC</label>
                        <input id="old_nric" wire:model="old_nric" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="home_telephone">Home telephone</label>
                        <input id="home_telephone" wire:model="home_telephone" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        @error('home_telephone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="malaysian_tin">Malaysian TIN</label>
                        <input id="malaysian_tin" wire:model="malaysian_tin" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                </div>
            </details>

            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Employment</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="occupation">Occupation</label>
                        <input id="occupation" wire:model="occupation" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        @error('occupation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="employer_name">Employer</label>
                        <input id="employer_name" wire:model="employer_name" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="registration_number">Business registration</label>
                        <input id="registration_number" wire:model="registration_number" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        @error('registration_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="industry">Industry</label>
                        <input id="industry" wire:model="industry" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="sub_industry">Sub-industry</label>
                        <input id="sub_industry" wire:model="sub_industry" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="employment_date">Employment date</label>
                        <input id="employment_date" type="date" wire:model="employment_date" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        @error('employment_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-slate-500" for="employer_address">Employer address</label>
                        <input id="employer_address" wire:model="employer_address" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="employer_city">Employer city</label>
                        <input id="employer_city" wire:model="employer_city" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="employer_state">Employer state</label>
                        <input id="employer_state" wire:model="employer_state" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="employer_postcode">Employer postcode</label>
                        <input id="employer_postcode" wire:model="employer_postcode" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="employer_country">Employer country</label>
                        <input id="employer_country" wire:model="employer_country" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="office_telephone">Office telephone</label>
                        <input id="office_telephone" wire:model="office_telephone" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        @error('office_telephone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="fax">Fax</label>
                        <input id="fax" wire:model="fax" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        @error('fax') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </details>

            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Financial</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="monthly_income">Monthly income</label>
                        <input id="monthly_income" wire:model="monthly_income" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        @error('monthly_income') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="personal_income">Personal income</label>
                        <select id="personal_income" wire:model="personal_income" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">Not set</option>
                            @foreach ($personalIncome as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="joint_income">Joint income</label>
                        <select id="joint_income" wire:model="joint_income" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">Not set</option>
                            @foreach ($jointIncome as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="net_assets">Net assets</label>
                        <select id="net_assets" wire:model="net_assets" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">Not set</option>
                            @foreach ($netAssets as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="portfolio">Portfolio</label>
                        <select id="portfolio" wire:model="portfolio" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">Not set</option>
                            @foreach ($portfolioOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-xs font-medium text-slate-500">Source of wealth</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($wealthOptions as $value => $label)
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" value="{{ $value }}" wire:model="wealth_sources" class="rounded border-slate-300 text-navy-800" />
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @if (in_array('other', $wealth_sources, true))
                            <input wire:model="other_wealth_source" class="mt-2 w-full rounded-md border-slate-300 text-sm" placeholder="Other source" />
                            @error('other_wealth_source') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @endif
                    </div>
                </div>
            </details>

            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Spouse or next of kin</summary>
                @if ($kin_linked)
                    <p class="mt-3 text-sm text-slate-600">This person is another customer. Edit their occupation and contact on that customer profile.</p>
                @elseif ($kin_editable)
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="text-xs font-medium text-slate-500" for="kin_occupation">Occupation</label>
                            <input id="kin_occupation" wire:model="kin_occupation" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-500" for="kin_home_telephone">Home telephone</label>
                            <input id="kin_home_telephone" wire:model="kin_home_telephone" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                            @error('kin_home_telephone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-500" for="kin_email">Email</label>
                            <input id="kin_email" wire:model="kin_email" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                            @error('kin_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-500" for="kin_employer_city">Employer city</label>
                            <input id="kin_employer_city" wire:model="kin_employer_city" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-500" for="kin_employer_state">Employer state</label>
                            <input id="kin_employer_state" wire:model="kin_employer_state" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-500" for="kin_employer_postcode">Employer postcode</label>
                            <input id="kin_employer_postcode" wire:model="kin_employer_postcode" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                        </div>
                    </div>
                @else
                    <p class="mt-3 text-sm text-slate-600">Choose one spouse or next of kin above when the profile has more than one person who could fill this section.</p>
                @endif
            </details>

            <button type="submit" class="rounded-md bg-navy-800 px-3 py-1.5 text-sm font-medium text-white">Save</button>
        </form>

        @php
            $groups = ['blocking' => 'Needs correction before printing', 'attention' => 'Needs attention', 'manual' => 'Reserved for manual signing'];
            $edited = collect($blocking)->pluck('message');
        @endphp
        @foreach ($groups as $level => $heading)
            @php
                $rows = collect($warnings)->where('level', $level)->reject(
                    fn (array $warning): bool => $level === 'blocking' && $edited->contains($warning['message'] ?? ''),
                );
            @endphp
            @if ($rows->isNotEmpty())
                <details @if($level === 'blocking' || $level === 'attention') open @endif class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <summary class="cursor-pointer text-sm font-medium text-slate-800">{{ $heading }} ({{ $rows->count() }})</summary>
                    <ul class="mt-2 space-y-1 text-sm text-slate-600">
                        @foreach ($rows as $warning)
                            <li>{{ $warning['message'] }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endforeach

        @if ($blocked && $blocking !== [])
            <section class="rounded-lg border border-amber-200 bg-amber-50 shadow-sm">
                <div class="border-b border-amber-200 px-4 py-3">
                    <h2 class="text-sm font-semibold text-slate-900">Fix these before printing</h2>
                    <p class="mt-1 text-sm text-slate-600">Shorten each box so it fits the form. Saving updates the customer profile.</p>
                </div>
                <form wire:submit="saveOverflow" class="space-y-4 px-4 py-4">
                    @foreach ($blocking as $field)
                        <div class="rounded-md bg-amber-50">
                            <label class="text-sm font-medium text-slate-800" for="overflow_{{ $field['key'] }}">{{ $field['label'] }}</label>
                            <textarea id="overflow_{{ $field['key'] }}" wire:model="overflow_values.{{ $field['key'] }}" rows="2" class="mt-1.5 w-full rounded-md border-amber-300 bg-amber-50 text-sm text-slate-900 shadow-sm focus:border-amber-500 focus:ring-amber-500"></textarea>
                            <p class="mt-1.5 text-xs leading-5 text-amber-800">{{ $field['message'] }}</p>
                            @error('overflow_values.'.$field['key']) <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <button type="submit" class="rounded-md bg-navy-800 px-3 py-1.5 text-sm font-medium text-white">Save these fields</button>
                </form>
            </section>
        @endif

        @if ($document)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('bimbsec.file', [$customer, $application, 'download' => 1]) }}" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700">{{ $blocked ? 'Download review copy' : 'Download' }}</a>
                @if ($blocked)
                    <span class="rounded-md bg-slate-200 px-3 py-1.5 text-sm text-slate-500">Print locked</span>
                @else
                    <button type="button" x-on:click="printPdf(@js(route('bimbsec.print', [$customer, $application])))" class="rounded-md bg-navy-800 px-3 py-1.5 text-sm font-medium text-white">Print</button>
                @endif
            </div>
            <iframe title="BIMBSEC preview" src="{{ route('bimbsec.file', [$customer, $application]) }}" class="h-[75vh] w-full rounded-lg border border-slate-200 bg-white"></iframe>
        @else
            <p class="text-sm text-slate-500">No PDF has been generated yet.</p>
        @endif
    </div>
</div>
