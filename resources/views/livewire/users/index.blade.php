<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-4">
        {{-- Page header --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-navy-900">Users</h1>
                <p class="mt-1 text-sm text-slate-500">Manage staff and administrator access to DAS.</p>
            </div>
            <button type="button" wire:click="create" class="inline-flex items-center justify-center self-start rounded-md bg-navy-800 px-3.5 py-2 text-sm font-medium text-white hover:bg-navy-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500 focus-visible:ring-offset-2">
                + Add User
            </button>
        </div>

        @if ($showForm)
            <div class="!mt-0 fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" x-data="{ dirty: false }" x-on:input.capture="dirty = true" x-on:change.capture="dirty = true" x-on:keydown.escape.window="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }">
                <div class="absolute inset-0 bg-slate-900/40" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" aria-hidden="true"></div>
                <div role="dialog" aria-modal="true" aria-labelledby="user-form-title" x-trap.noscroll="true" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
                        <h2 id="user-form-title" class="font-semibold text-navy-900">Add User</h2>
                        <button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" aria-label="Close user form" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">&times;</button>
                    </div>
                    <form wire:submit="save" class="flex min-h-0 flex-col">
                        <div class="grid min-h-0 gap-4 overflow-y-auto px-4 py-5 sm:grid-cols-2 sm:px-6">
                            <div>
                                <x-input-label for="name" value="Full Name" />
                                <x-text-input wire:model="name" id="name" class="mt-1 block w-full" required />
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="email" value="Email" />
                                <x-text-input wire:model="email" id="email" type="email" class="mt-1 block w-full" required />
                                <x-input-error :messages="$errors->get('email')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="role" value="Role" />
                                <select wire:model="role" id="role" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-navy-500 focus:ring-navy-500 text-sm">
                                    @foreach (\App\Enums\UserRole::cases() as $userRole)
                                        <option value="{{ $userRole->value }}">{{ $userRole->label() }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('role')" class="mt-2" />
                            </div>
                            <div></div>
                            <div>
                                <x-input-label for="password" value="Password" />
                                <x-text-input wire:model="password" id="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="password_confirmation" value="Confirm Password" />
                                <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-slate-200 px-4 py-3 sm:px-6"><button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" class="text-sm text-slate-600 hover:text-slate-900">Cancel</button><x-primary-button wire:loading.attr="disabled" wire:target="save">Save User</x-primary-button></div>
                    </form>
                </div>
            </div>
        @endif

        {{-- Search & filters --}}
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative flex-1 min-w-0">
                <label for="search" class="sr-only">Search users</label>
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400" aria-hidden="true">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                </span>
                <input
                    wire:model.live.debounce.400ms="search"
                    id="search"
                    type="search"
                    placeholder="Search users..."
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

            <div class="sm:w-44">
                <label for="status" class="sr-only">Status</label>
                <select
                    wire:model.live="status"
                    id="status"
                    class="w-full rounded-md border-slate-300 py-2 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500"
                >
                    <option value="all">All Users</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <p class="sr-only" wire:loading.delay wire:target="search,status" aria-live="polite">Updating…</p>

        {{-- Results --}}
        <div class="relative rounded-lg border border-slate-200 bg-white shadow-sm" wire:loading.class="opacity-70" wire:target="search,status">
            @if ($users->isEmpty())
                <div class="px-6 py-10 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                    </div>
                    @if ($hasFilters)
                        <p class="mt-3 text-sm font-medium text-slate-800">No users match your search.</p>
                        <p class="mt-1 text-sm text-slate-500">Try a different name, email, or clear your filters.</p>
                    @else
                        <p class="mt-3 text-sm font-medium text-slate-800">No users yet.</p>
                        <p class="mt-1 text-sm text-slate-500">Add your first user to get started.</p>
                    @endif
                </div>
            @else
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm font-normal">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Email</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Role</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Last Updated</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm text-slate-800">
                            @foreach ($users as $user)
                                <tr
                                    class="cursor-pointer hover:bg-slate-50/80 focus-within:bg-slate-50/80"
                                    role="link"
                                    tabindex="0"
                                    aria-label="View {{ $user->name }}"
                                    x-on:click="Livewire.navigate(@js(route('users.show', $user)))"
                                    x-on:keydown.enter.prevent="Livewire.navigate(@js(route('users.show', $user)))"
                                    x-on:keydown.space.prevent="Livewire.navigate(@js(route('users.show', $user)))"
                                >
                                    <td class="px-4 py-3 align-middle">
                                        {{ $user->name }}
                                    </td>
                                    <td class="px-4 py-3 align-middle max-w-[16rem]">
                                        <span class="block truncate" title="{{ $user->email }}">
                                            {{ $user->email }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap">
                                        {{ $user->role->label() }}
                                    </td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap">
                                        {{ $user->updated_at?->timezone(config('app.timezone'))->format('d/m/Y, h:i A') ?: '—' }}
                                    </td>
                                    <td class="px-4 py-3 align-middle whitespace-nowrap">
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-emerald-50 text-emerald-700' => $user->is_active,
                                            'bg-slate-100 text-slate-600' => ! $user->is_active,
                                        ])>
                                            {{ $user->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <ul class="divide-y divide-slate-100 md:hidden" role="list">
                    @foreach ($users as $user)
                        <li>
                            <a
                                href="{{ route('users.show', $user) }}"
                                wire:navigate
                                class="block w-full px-4 py-3 text-left hover:bg-slate-50/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-navy-500"
                            >
                                <div class="flex flex-wrap items-center gap-2 text-sm text-slate-800">
                                    <span>{{ $user->name }}</span>
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-emerald-50 text-emerald-700' => $user->is_active,
                                        'bg-slate-100 text-slate-600' => ! $user->is_active,
                                    ])>
                                        {{ $user->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-slate-800 truncate">{{ $user->email }}</p>
                                <p class="mt-1 text-sm text-slate-800">{{ $user->role->label() }}</p>
                                @if ($user->updated_at)
                                    <p class="mt-1 text-sm text-slate-800">
                                        {{ $user->updated_at->timezone(config('app.timezone'))->format('d/m/Y, h:i A') }}
                                    </p>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($users->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3">
                        {{ $users->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
