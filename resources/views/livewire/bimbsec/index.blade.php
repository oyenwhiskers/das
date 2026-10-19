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

        @if ($selected !== [])
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="clearSelection" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50">Clear</button>
                @unless ($confirming)
                    <button type="button" wire:click="askGenerate" @disabled(count($selected) === 0) title="Generate selected PDF" aria-label="Generate selected PDF" class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-navy-800 text-white hover:bg-navy-900 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    </button>
                    <button type="button" x-on:click="prepareBimbsec($wire, 'download')" @disabled(count($selected) === 0) title="Download all" aria-label="Download all" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    </button>
                    <button type="button" x-on:click="prepareBimbsec($wire, 'print')" @disabled(count($selected) === 0) title="Print all" aria-label="Print all" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 0 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                    </button>
                @else
                    <span class="text-slate-600">Generate PDFs for {{ count($selected) }} selected customers?</span>
                    <button type="button" wire:click="generateSelected" class="rounded-md bg-navy-800 px-3 py-1.5 font-medium text-white hover:bg-navy-900">Confirm</button>
                    <button type="button" wire:click="cancelGenerate" class="rounded-md border border-slate-300 px-2.5 py-1 text-slate-700">Cancel</button>
                @endunless
            </div>
            <p class="font-medium text-slate-800">{{ count($selected) }} {{ count($selected) === 1 ? 'customer' : 'customers' }} selected</p>
        </div>
        @endif

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            @if ($customers->isEmpty())
                <div class="px-6 py-10 text-center">
                    <p class="text-sm font-medium text-slate-800">{{ $hasFilters ? 'No customers match your search.' : 'No customers yet.' }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $hasFilters ? 'Try another name or NRIC.' : 'Customer profiles are created in the Customers module.' }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full table-fixed divide-y divide-slate-200 text-sm">
                        <colgroup>
                            <col class="w-12" />
                            <col />
                            <col class="w-48" />
                            <col class="w-32" />
                            <col class="w-28" />
                        </colgroup>
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-2.5 text-left">
                                    <input type="checkbox" @checked($pageSelected) wire:click="togglePage(@js($pageIds))" aria-label="Select all customers on this page" class="rounded border-slate-300 text-navy-800 focus:ring-navy-500" />
                                </th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Customer name</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">NRIC / passport</th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($customers as $customer)
                                @php
                                    $application = $customer->bimbsecApplications->first(fn ($row) => $row->document !== null) ?? $customer->bimbsecApplications->first();
                                    $issues = collect($fit[$customer->id]['issues'] ?? []);
                                    $blocked = (bool) ($fit[$customer->id]['blocked'] ?? false);
                                @endphp
                                <tr @class(['bg-amber-50' => $blocked])
                                    @if ($issues->isNotEmpty())
                                        x-data="{ show: false, top: 0, left: 0 }"
                                        x-on:mouseenter="show = true"
                                        x-on:mousemove="
                                            const width = 384;
                                            const height = 160;
                                            const offset = 16;
                                            left = Math.max(8, Math.min($event.clientX + offset, window.innerWidth - width - 8));
                                            top = $event.clientY + offset + height > window.innerHeight
                                                ? Math.max(8, $event.clientY - height - offset)
                                                : $event.clientY + offset;
                                        "
                                        x-on:mouseleave="show = false"
                                    @endif
                                >
                                    <td class="px-4 py-3">
                                        <input type="checkbox" @checked(in_array($customer->id, $selected, true)) wire:click="toggle({{ $customer->id }})" aria-label="Select {{ $customer->full_legal_name }}" class="rounded border-slate-300 text-navy-800 focus:ring-navy-500" />
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-900">
                                        {{ $customer->full_legal_name }}
                                        @if ($issues->isNotEmpty())
                                            <template x-teleport="body">
                                                {{-- shortcut: 160px height guess when flipping above the cursor --}}
                                                <div x-show="show" x-cloak x-bind:style="`left:${left}px;top:${top}px`" class="pointer-events-none fixed z-50 w-96 rounded-lg border border-amber-200 bg-white px-3 py-2.5 text-left text-sm font-normal leading-5 text-slate-700 shadow-lg">
                                                    <p class="text-xs font-semibold text-amber-800">Does not fit the form</p>
                                                    <ul class="mt-1.5 space-y-1">
                                                        @foreach ($issues as $issue)
                                                            <li>{{ $issue }}</li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            </template>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $customer->primary_identification_number ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 align-middle">
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-emerald-50 text-emerald-700' => $customer->isActive(),
                                            'bg-slate-100 text-slate-600' => $customer->isArchived(),
                                        ])>
                                            {{ $customer->status->label() }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <button type="button" wire:click="openCustomer({{ $customer->id }})" title="View PDF" aria-label="View PDF" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-navy-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                        </button>
                                        <button type="button" title="Print" aria-label="Print" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-navy-800 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500"
                                            @if (! $blocked && $application?->document?->allowsPrint())
                                                x-on:click="printPdf(@js(route('bimbsec.print', [$customer, $application])))"
                                            @else
                                                x-on:click="prepareBimbsecPrint($wire, {{ $customer->id }})"
                                            @endif
                                        >
                                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 0 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                                        </button>
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
