<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold text-navy-900">Wakalah Letter</h1>
            <p class="mt-1 text-sm text-slate-500">Generate a Wakalah Letter from an existing Customer Profile.</p>
        </div>

        <div class="relative flex-1 min-w-0">
            <label for="wakalah-search" class="sr-only">Search by name or NRIC</label>
            <input wire:model.live.debounce.400ms="search" id="wakalah-search" type="search" placeholder="Search by name or NRIC / passport" class="w-full rounded-md border-slate-300 py-2 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500" />
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            @if ($customers->isEmpty())
                <div class="px-6 py-10 text-center">
                    <p class="text-sm font-medium text-slate-800">{{ $hasFilters ? 'No customers match your search.' : 'No customers yet.' }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $hasFilters ? 'Try another name or NRIC.' : 'Customer profiles are created in the Customers module.' }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm font-normal">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Customer name</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">NRIC / passport</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm text-slate-800">
                            @foreach ($customers as $customer)
                                <tr
                                    class="cursor-pointer hover:bg-slate-50/80 focus-within:bg-slate-50/80"
                                    role="link"
                                    tabindex="0"
                                    aria-label="View Wakalah Letter for {{ $customer->full_legal_name }}"
                                    wire:click="openCustomer({{ $customer->id }})"
                                    x-on:keydown.enter.prevent="$wire.openCustomer({{ $customer->id }})"
                                    x-on:keydown.space.prevent="$wire.openCustomer({{ $customer->id }})"
                                >
                                    <td class="px-4 py-3 align-middle font-medium text-navy-900">{{ $customer->full_legal_name }}</td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap">{{ $customer->primary_identification_number ?: '—' }}</td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap">
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-emerald-50 text-emerald-700' => $customer->isActive(),
                                            'bg-slate-100 text-slate-600' => $customer->isArchived(),
                                        ])>
                                            {{ $customer->status->label() }}
                                        </span>
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
