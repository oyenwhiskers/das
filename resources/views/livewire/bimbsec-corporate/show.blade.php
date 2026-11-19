<div class="py-6">
    <div class="w-full space-y-4 px-4 sm:px-6 lg:px-8 xl:px-10">
        <div>
            <a href="{{ route('bimbsec-corporate.index') }}" wire:navigate class="text-sm text-navy-800 hover:underline">BIMBSEC Corporate</a>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-navy-900">{{ $customer->full_legal_name }}</h1>
                    <p class="text-sm text-slate-500">{{ $customer->primary_identification_number ?: 'No business registration number on the profile' }}</p>
                </div>
                @if ($fromProfile)
                    <a href="{{ route('customers.show', $customer) }}?tab=company" wire:navigate class="text-sm text-navy-800 hover:underline">&larr; Back to profile</a>
                @endif
            </div>
        </div>

        <form wire:submit="saveForm" class="space-y-3">
            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3" open>
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Application choices</summary>
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
                        <label class="text-xs font-medium text-slate-500" for="verification_mode">Verification</label>
                        <select id="verification_mode" wire:model="verification_mode" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">Leave blank</option>
                            <option value="face">Face to Face</option>
                            <option value="non_face">Non Face-To-Face</option>
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
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-slate-500" for="bank_account_id">Bank account</label>
                        <select id="bank_account_id" wire:model="bank_account_id" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="">Use the primary account</option>
                            <option value="none">Leave blank</option>
                            @foreach ($banks as $bank)
                                <option value="{{ $bank->id }}">{{ $bank->bank_name }} {{ $bank->maskedAccountNumber() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </details>

            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Shorten overflow fields</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-slate-500" for="company_name">Registered name</label>
                        <input id="company_name" wire:model="company_name" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500" for="business_registration_no">Business registration no.</label>
                        <input id="business_registration_no" wire:model="business_registration_no" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                    </div>
                </div>
            </details>

            <div class="flex flex-wrap gap-2">
                <x-primary-button type="submit">Save &amp; regenerate</x-primary-button>
                <x-secondary-button type="button" wire:click="regenerate">Regenerate only</x-secondary-button>
                @if ($previewUrl)
                    <a href="{{ $previewUrl }}?download=1" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Download PDF</a>
                    @unless ($blocked)
                        <button type="button" x-on:click="printPdf(@js(route('bimbsec-corporate.print', [$customer, $application])))" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Print</button>
                    @endunless
                @endif
            </div>
        </form>

        @if ($warnings !== [])
            <div @class(['rounded-lg border px-4 py-3 text-sm', 'border-amber-200 bg-amber-50 text-amber-900' => $blocked, 'border-slate-200 bg-white text-slate-700' => ! $blocked])>
                <p class="font-medium">{{ $blocked ? 'Print blocked until overflow is fixed' : 'Warnings' }}</p>
                <ul class="mt-2 list-disc space-y-1 ps-5">
                    @foreach ($warnings as $warning)
                        <li>{{ $warning['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($previewUrl)
            <iframe title="Corporate BIMBSEC preview" src="{{ $previewUrl }}" class="h-[75vh] w-full rounded-lg border border-slate-200 bg-white"></iframe>
        @endif
    </div>
</div>
