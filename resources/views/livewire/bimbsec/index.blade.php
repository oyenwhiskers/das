<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold text-navy-900">BIMBSEC</h1>
            <p class="mt-1 text-sm text-slate-500">Generate BIMB Securities trading account forms from existing Customer Profiles.</p>
        </div>

        <div class="relative flex-1 min-w-0">
            <label for="bimbsec-search" class="sr-only">Search by name or NRIC</label>
            <input wire:model.live.debounce.400ms="search" id="bimbsec-search" type="search" placeholder="Search by name or NRIC / passport" class="w-full rounded-md border-slate-300 py-2 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500" />
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="font-medium text-slate-800">{{ count($selected) }} {{ count($selected) === 1 ? 'customer' : 'customers' }} selected</span>
            <button type="button" wire:click="selectMatching" class="rounded-md border border-slate-300 px-2.5 py-1 text-slate-700 hover:bg-slate-50">Select all matching results</button>
            <button type="button" wire:click="clearSelection" class="rounded-md border border-slate-300 px-2.5 py-1 text-slate-700 hover:bg-slate-50">Clear selection</button>
            @unless ($confirming)
                <button type="button" wire:click="askGenerate" @disabled(count($selected) === 0) class="rounded-md bg-navy-800 px-3 py-1.5 font-medium text-white hover:bg-navy-900 disabled:opacity-40">Generate selected PDF</button>
            @else
                <span class="text-slate-600">Generate PDFs for {{ count($selected) }} selected customers?</span>
                <button type="button" wire:click="generateSelected" class="rounded-md bg-navy-800 px-3 py-1.5 font-medium text-white hover:bg-navy-900">Confirm</button>
                <button type="button" wire:click="cancelGenerate" class="rounded-md border border-slate-300 px-2.5 py-1 text-slate-700">Cancel</button>
            @endunless
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            @if ($customers->isEmpty())
                <div class="px-6 py-10 text-center">
                    <p class="text-sm font-medium text-slate-800">{{ $hasFilters ? 'No customers match your search.' : 'No customers yet.' }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $hasFilters ? 'Try another name or NRIC.' : 'Customer profiles are created in the Customers module.' }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-2.5 text-left">
                                    <input type="checkbox" @checked($pageSelected) wire:click="togglePage(@js($pageIds))" aria-label="Select all customers on this page" class="rounded border-slate-300 text-navy-800 focus:ring-navy-500" />
                                </th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Customer name</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">NRIC / passport</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($customers as $customer)
                                <tr>
                                    <td class="px-4 py-3">
                                        <input type="checkbox" @checked(in_array($customer->id, $selected, true)) wire:click="toggle({{ $customer->id }})" aria-label="Select {{ $customer->full_legal_name }}" class="rounded border-slate-300 text-navy-800 focus:ring-navy-500" />
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-900">{{ $customer->full_legal_name }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ $customer->primary_identification_number ?: '—' }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ $customer->status->label() }}</td>
                                    <td class="px-4 py-3">
                                        <button type="button" wire:click="openCustomer({{ $customer->id }})" class="font-medium text-navy-800 hover:underline">View PDF</button>
                                        <button type="button" wire:click="printCustomer({{ $customer->id }})" class="ml-3 font-medium text-navy-800 hover:underline">Print</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($customers->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3">{{ $customers->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</div>
