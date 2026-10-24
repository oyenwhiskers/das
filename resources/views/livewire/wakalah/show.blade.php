<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-4">
        <div>
            <a href="{{ route('wakalah.index') }}" wire:navigate class="text-sm text-navy-800 hover:underline">Wakalah Letter</a>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-navy-900">{{ $customer->full_legal_name }}</h1>
                    <p class="text-sm text-slate-500">{{ $customer->primary_identification_number ?: 'No NRIC / passport on the profile' }}</p>
                </div>
                <a href="{{ route('wakalah.file', [$customer, 'download' => 1]) }}" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700">Download</a>
            </div>
        </div>

        <form wire:submit="save" class="space-y-3">
            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Client</summary>
                <div class="mt-3 space-y-3">
                    <p class="text-xs text-slate-500">Name and NRIC come from the customer profile. Designation is optional.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-medium text-slate-500">Name</p>
                            <p class="mt-1 text-sm font-medium text-slate-900">{{ $customer->full_legal_name }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500">NRIC</p>
                            <p class="mt-1 text-sm font-medium text-slate-900">{{ $customer->primary_identification_number ?: '—' }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-medium text-slate-500" for="wakalah-designation">Designation</label>
                            <input id="wakalah-designation" wire:model="designation" autocomplete="organization-title" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                            @error('designation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </details>

            <details class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">Witnessed by</summary>
                <div class="mt-3 space-y-3">
                    <p class="text-xs text-slate-500">Optional. Leave blank to print empty lines for handwriting.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="text-xs font-medium text-slate-500" for="wakalah-witness-name">Name</label>
                            <input id="wakalah-witness-name" wire:model="witnessName" autocomplete="name" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                            @error('witnessName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-500" for="wakalah-witness-nric">NRIC</label>
                            <input id="wakalah-witness-nric" wire:model="witnessNric" autocomplete="off" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                            @error('witnessNric') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-500" for="wakalah-witness-date">Date</label>
                            <input id="wakalah-witness-date" type="date" wire:model="witnessDate" class="mt-1 w-full rounded-md border-slate-300 text-sm" />
                            @error('witnessDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </details>

            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-md bg-navy-800 px-3 py-1.5 text-sm font-medium text-white">Save</button>
        </form>

        <iframe wire:key="wakalah-preview-{{ $previewVersion }}" title="Wakalah Letter preview" src="{{ route('wakalah.file', $customer) }}?v={{ $previewVersion }}" class="h-[75vh] w-full rounded-lg border border-slate-200 bg-white"></iframe>
    </div>
</div>
