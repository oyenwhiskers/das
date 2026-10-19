@php
    $initials = collect(explode(' ', $user->name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-6">
        <a href="{{ route('users.index') }}" wire:navigate class="inline-block text-sm text-navy-700 hover:text-navy-900">&larr; Back to Users</a>

        <div class="rounded-lg border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex min-w-0 gap-4">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-navy-100 text-xl font-semibold text-navy-800 sm:h-20 sm:w-20 sm:text-2xl" aria-hidden="true">
                        {{ $initials ?: 'U' }}
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="truncate text-2xl font-semibold text-navy-900">{{ $user->name }}</h1>
                            <span @class([
                                'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-emerald-50 text-emerald-700' => $user->is_active,
                                'bg-slate-100 text-slate-600' => ! $user->is_active,
                            ])>
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        <dl class="mt-3 grid gap-1.5 text-sm">
                            <div class="flex flex-wrap gap-x-2">
                                <dt class="text-slate-500">Role:</dt>
                                <dd class="font-medium text-navy-800">{{ $user->role->label() }}</dd>
                            </div>
                            <div class="flex flex-wrap gap-x-2">
                                <dt class="text-slate-500">Last Updated:</dt>
                                <dd class="text-slate-800">{{ $user->updated_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') ?: '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <button type="button" wire:click="edit" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                        Edit User
                    </button>
                    @if ($user->id !== auth()->id())
                        <button type="button" wire:click="toggleActive" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                            {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    @endif
                    <button type="button" wire:click="sendPasswordReset" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                        Reset password
                    </button>
                </div>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm" aria-labelledby="account-details-heading">
            <div class="border-b border-slate-100 px-5 py-3 sm:px-6">
                <h2 id="account-details-heading" class="text-base font-semibold text-navy-900">Account Details</h2>
            </div>

            <div class="p-5 sm:p-6">
                @if ($editing)
                    <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
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
                            <x-input-label for="nric" value="NRIC / Passport" />
                            <x-text-input wire:model="nric" id="nric" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('nric')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="role" value="Role" />
                            <select wire:model="role" id="role" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-navy-500 focus:ring-navy-500">
                                @foreach (\App\Enums\UserRole::cases() as $userRole)
                                    <option value="{{ $userRole->value }}">{{ $userRole->label() }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('role')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="password" value="New Password (optional)" />
                            <x-text-input wire:model="password" id="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="password_confirmation" value="Confirm Password" />
                            <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                        </div>
                        <div class="flex justify-end gap-3 sm:col-span-2">
                            <button type="button" wire:click="cancel" class="text-sm text-slate-600 hover:text-slate-900">Cancel</button>
                            <x-primary-button>Save User</x-primary-button>
                        </div>
                    </form>
                @else
                    <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">Full Name</dt><dd class="mt-1 font-medium text-slate-900">{{ $user->name }}</dd></div>
                        <div><dt class="text-slate-500">Email</dt><dd class="mt-1 break-all font-medium text-slate-900">{{ $user->email }}</dd></div>
                        <div><dt class="text-slate-500">NRIC / Passport</dt><dd class="mt-1 font-medium text-slate-900">{{ $user->nric ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Role</dt><dd class="mt-1 font-medium text-slate-900">{{ $user->role->label() }}</dd></div>
                        <div><dt class="text-slate-500">Status</dt><dd class="mt-1 font-medium text-slate-900">{{ $user->is_active ? 'Active' : 'Inactive' }}</dd></div>
                    </dl>
                @endif
            </div>
        </section>
    </div>
</div>
