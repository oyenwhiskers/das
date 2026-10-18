<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-4">
        {{-- Page header --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-navy-900">Customers</h1>
                <p class="mt-1 text-sm text-slate-500">Manage and maintain customer records.</p>
            </div>
            <a href="{{ route('customers.create') }}" wire:navigate
               class="inline-flex items-center justify-center self-start rounded-md bg-navy-800 px-3.5 py-2 text-sm font-medium text-white hover:bg-navy-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500 focus-visible:ring-offset-2">
                + Add Customer
            </a>
        </div>

        {{-- Search & filters --}}
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative flex-1 min-w-0">
                <label for="search" class="sr-only">Search customers</label>
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400" aria-hidden="true">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                </span>
                <input
                    wire:model.live.debounce.400ms="search"
                    id="search"
                    type="search"
                    placeholder="Search customers..."
                    autocomplete="off"
                    class="w-full rounded-md border-slate-300 py-2 pl-9 pr-9 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500"
                />
                @if (filled($search))
                    <button
                        type="button"
                        wire:click="clearSearch"
                        class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500 rounded-md"
                        aria-label="Clear search"
                    >
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endif
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center lg:w-auto">
                <div class="sm:w-44">
                    <label for="status" class="sr-only">Status</label>
                    <select
                        wire:model.live="status"
                        id="status"
                        class="w-full rounded-md border-slate-300 py-2 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500"
                    >
                        <option value="all">All Customers</option>
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
                <div class="sm:w-52">
                    <label for="sort" class="sr-only">Sort</label>
                    <select
                        wire:model.live="sort"
                        id="sort"
                        class="w-full rounded-md border-slate-300 py-2 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500"
                    >
                        <option value="name_asc">Customer Name A–Z</option>
                        <option value="name_desc">Customer Name Z–A</option>
                        <option value="updated">Recently Updated</option>
                        <option value="added">Recently Added</option>
                    </select>
                </div>
            </div>
        </div>

        <p class="sr-only" wire:loading.delay wire:target="search,status,sort" aria-live="polite">Updating…</p>

        @if ($selected !== [])
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3">
                <p class="text-sm text-slate-700">{{ count($selected) }} {{ count($selected) === 1 ? 'customer' : 'customers' }} selected</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="clearSelection" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Clear</button>
                    <button type="button" x-on:click.prevent="if (await confirmAction({ title: 'Delete selected customers?', text: 'Selected customers, their records, and links from other customers will be permanently deleted. This cannot be undone.', confirmButtonText: 'Delete', confirmButtonColor: '#b91c1c' })) $wire.deleteSelected()" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Delete selected</button>
                </div>
            </div>
        @endif

        {{-- Results --}}
        <div class="relative rounded-lg border border-slate-200 bg-white shadow-sm" wire:loading.class="opacity-70" wire:target="search,status,sort">
            @if ($customers->isEmpty())
                <div class="px-6 py-10 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                    </div>
                    @if ($hasFilters)
                        <p class="mt-3 text-sm font-medium text-slate-800">No customers match your search.</p>
                        <p class="mt-1 text-sm text-slate-500">Try a different name, reference, or clear your filters.</p>
                    @else
                        <p class="mt-3 text-sm font-medium text-slate-800">No customers yet.</p>
                        <p class="mt-1 text-sm text-slate-500">Add your first customer to get started.</p>
                    @endif
                </div>
            @else
                {{-- Desktop table --}}
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm font-normal">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 text-left">
                                    <input type="checkbox" @checked($pageSelected) wire:click="togglePage(@js($pageIds))" aria-label="Select all customers on this page" class="rounded border-slate-300 text-navy-800 focus:ring-navy-500" />
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">#</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500" aria-sort="{{ $sort === 'name_asc' ? 'ascending' : ($sort === 'name_desc' ? 'descending' : 'none') }}">
                                    <button type="button" wire:click="sortByName" class="inline-flex items-center gap-1 hover:text-navy-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500 rounded">
                                        Name
                                        <span class="text-[10px] tabular-nums" aria-hidden="true">{{ $sort === 'name_asc' ? '↑' : ($sort === 'name_desc' ? '↓' : '') }}</span>
                                    </button>
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Identification</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Mobile</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Email</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Last Updated</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Profile</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm text-slate-800">
                            @foreach ($customers as $customer)
                                <tr
                                    class="cursor-pointer hover:bg-slate-50/80 focus-within:bg-slate-50/80"
                                    role="link"
                                    tabindex="0"
                                    aria-label="View {{ $customer->full_legal_name }}"
                                    x-on:click="Livewire.navigate(@js(route('customers.show', $customer)))"
                                    x-on:keydown.enter.prevent="Livewire.navigate(@js(route('customers.show', $customer)))"
                                    x-on:keydown.space.prevent="Livewire.navigate(@js(route('customers.show', $customer)))"
                                >
                                    <td class="px-4 py-3 align-middle" @click.stop @keydown.stop>
                                        <input type="checkbox" @checked(in_array($customer->id, $selected, true)) wire:click="toggle({{ $customer->id }})" aria-label="Select {{ $customer->full_legal_name }}" class="rounded border-slate-300 text-navy-800 focus:ring-navy-500" />
                                    </td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap text-slate-500">
                                        {{ $customers->firstItem() + $loop->index }}
                                    </td>
                                    <td class="px-4 py-3 align-middle">
                                        {{ $customer->full_legal_name }}
                                    </td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap">
                                        {{ $customer->primary_identification_number ?: '—' }}
                                    </td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap">
                                        {{ $customer->primary_mobile ?: '—' }}
                                    </td>
                                    <td class="px-4 py-3 align-middle max-w-[16rem]">
                                        <span class="block truncate" title="{{ $customer->primary_email ?: '' }}">
                                            {{ $customer->primary_email ?: '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap">
                                        {{ $customer->updated_at?->timezone(config('app.timezone'))->format('d/m/Y, h:i A') ?: '—' }}
                                    </td>
                                    <td class="px-4 py-3 align-middle">
                                        <x-profile-completion :percent="$customer->profileCompletionPercent()" />
                                    </td>
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

                {{-- Mobile cards --}}
                <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2 md:hidden">
                    <input type="checkbox" @checked($pageSelected) wire:click="togglePage(@js($pageIds))" aria-label="Select all customers on this page" class="rounded border-slate-300 text-navy-800 focus:ring-navy-500" />
                    <span class="text-sm text-slate-600">Select page</span>
                </div>
                <ul class="divide-y divide-slate-100 md:hidden" role="list">
                    @foreach ($customers as $customer)
                        <li class="flex items-start gap-3 px-4 py-3">
                            <input type="checkbox" @checked(in_array($customer->id, $selected, true)) wire:click="toggle({{ $customer->id }})" aria-label="Select {{ $customer->full_legal_name }}" class="mt-1 rounded border-slate-300 text-navy-800 focus:ring-navy-500" />
                            <a
                                href="{{ route('customers.show', $customer) }}"
                                wire:navigate
                                class="block min-w-0 flex-1 hover:bg-slate-50/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-navy-500"
                            >
                                <div class="flex flex-wrap items-center gap-2 text-sm text-slate-800">
                                    <span class="text-slate-500">{{ $customers->firstItem() + $loop->index }}</span>
                                    <span>{{ $customer->full_legal_name }}</span>
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-emerald-50 text-emerald-700' => $customer->isActive(),
                                        'bg-slate-100 text-slate-600' => $customer->isArchived(),
                                    ])>
                                        {{ $customer->status->label() }}
                                    </span>
                                </div>
                                <div class="mt-2">
                                    <x-profile-completion :percent="$customer->profileCompletionPercent()" />
                                </div>
                                <p class="mt-1 text-sm text-slate-800">{{ $customer->primary_identification_number ?: '—' }}</p>
                                <p class="mt-1 text-sm text-slate-800">{{ $customer->primary_mobile ?: '—' }}</p>
                                <p class="mt-1 text-sm text-slate-800 truncate">{{ $customer->primary_email ?: '—' }}</p>
                                @if ($customer->updated_at)
                                    <p class="mt-1 text-sm text-slate-800">
                                        {{ $customer->updated_at->timezone(config('app.timezone'))->format('d/m/Y, h:i A') }}
                                    </p>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($customers->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3">
                        {{ $customers->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
