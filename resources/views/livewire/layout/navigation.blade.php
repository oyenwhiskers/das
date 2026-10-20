<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect(route('login'), navigate: true);
    }
}; ?>

@php
    $customersActive = request()->routeIs('customers.*');
    $usersActive = request()->routeIs('users.*');
    $documentsActive = request()->routeIs('documents.*');
    $attachmentsActive = request()->routeIs('attachments.*');
    $bimbsecActive = request()->routeIs('bimbsec.*');
    $aiUsageActive = request()->routeIs('ai-usage.*');
@endphp

<div>
    {{-- Desktop sidebar --}}
    <aside class="hidden lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-64 lg:shrink-0 lg:flex-col border-r border-slate-200 bg-white">
        <div class="flex h-14 items-center border-b border-slate-200 px-5">
            <a href="{{ route('customers.index') }}" wire:navigate>
                <x-application-logo class="text-xl" />
            </a>
        </div>

        <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4" aria-label="Main">
            <div>
                <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Input</p>
                <div class="space-y-1">
                    <a href="{{ route('documents.index') }}" wire:navigate @class(['flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition', 'bg-navy-50 text-navy-900' => $documentsActive, 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $documentsActive])>
                        <svg class="h-4 w-4 shrink-0 opacity-80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75h6l3.75 3.75v12.75H7.5a3 3 0 01-3-3V6.75a3 3 0 013-3zM13.5 3.75V7.5h3.75M8.25 12h6.75m-6.75 3.75h6.75" /></svg>
                        Scan Document
                    </a>
                    <a href="{{ route('attachments.index') }}" wire:navigate @class(['flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition', 'bg-navy-50 text-navy-900' => $attachmentsActive, 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $attachmentsActive])>
                        <svg class="h-4 w-4 shrink-0 opacity-80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" /></svg>
                        Scan Attachment
                    </a>
                </div>
            </div>

            <div>
                <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Customers</p>
                <a
                    href="{{ route('customers.index') }}"
                    wire:navigate
                    @class([
                        'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition',
                        'bg-navy-50 text-navy-900' => $customersActive,
                        'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $customersActive,
                    ])
                >
                    <svg class="h-4 w-4 shrink-0 opacity-80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    Customers
                </a>
            </div>

            <div>
                <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Documents</p>
                <a href="{{ route('bimbsec.index') }}" wire:navigate @class(['flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition', 'bg-navy-50 text-navy-900' => $bimbsecActive, 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $bimbsecActive])>
                    <svg class="h-4 w-4 shrink-0 opacity-80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    BIMBSEC
                </a>
            </div>

            @if (auth()->user()?->isAdmin())
                <div>
                    <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Administration</p>
                    <div class="space-y-1">
                        <a
                            href="{{ route('users.index') }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition',
                                'bg-navy-50 text-navy-900' => $usersActive,
                                'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $usersActive,
                            ])
                        >
                            <svg class="h-4 w-4 shrink-0 opacity-80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                            </svg>
                            Users
                        </a>
                        <a href="{{ route('ai-usage.index') }}" wire:navigate @class(['flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition', 'bg-navy-50 text-navy-900' => $aiUsageActive, 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $aiUsageActive])>
                            <svg class="h-4 w-4 shrink-0 opacity-80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                            AI Usage
                        </a>
                    </div>
                </div>
            @endif
        </nav>

        <div class="border-t border-slate-200 px-2 py-1.5" x-data="{ userMenuOpen: false }">
            <button
                type="button"
                @click="userMenuOpen = ! userMenuOpen"
                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500"
                :aria-expanded="userMenuOpen.toString()"
                aria-controls="desktop-user-menu"
            >
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-navy-100 text-[11px] font-semibold leading-none text-navy-800">
                    {{ collect(explode(' ', auth()->user()->name))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}
                </span>
                <span class="min-w-0 flex-1 leading-tight">
                    <span
                        class="block truncate text-sm font-medium text-slate-800"
                        x-data="{{ json_encode(['name' => auth()->user()->name]) }}"
                        x-text="name"
                        x-on:profile-updated.window="name = $event.detail.name"
                    ></span>
                    <span class="block truncate text-[11px] text-slate-500">{{ auth()->user()->email }}</span>
                </span>
                <svg
                    class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform"
                    :class="{ 'rotate-180': userMenuOpen }"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                </svg>
            </button>

            <div
                id="desktop-user-menu"
                x-cloak
                x-show="userMenuOpen"
                x-transition
                class="mt-0.5 space-y-0.5"
            >
                <a
                    href="{{ route('profile') }}"
                    wire:navigate
                    class="block rounded-md px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
                >
                    Account
                </a>
                <button
                    type="button"
                    wire:click="logout"
                    class="block w-full rounded-md px-2 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
                >
                    Log Out
                </button>
            </div>
        </div>
    </aside>

    {{-- Mobile drawer --}}
    <div
        x-cloak
        x-show="sidebarOpen"
        class="lg:hidden"
        x-on:keydown.escape.window="sidebarOpen = false"
    >
        <div
            class="fixed inset-0 z-40 bg-slate-900/40"
            x-show="sidebarOpen"
            x-transition.opacity
            @click="sidebarOpen = false"
        ></div>

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col bg-white shadow-xl"
            x-show="sidebarOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full"
            @click.stop
        >
            <div class="flex h-14 items-center justify-between border-b border-slate-200 px-4">
                <a href="{{ route('customers.index') }}" wire:navigate @click="sidebarOpen = false">
                    <x-application-logo class="text-xl" />
                </a>
                <button
                    type="button"
                    @click="sidebarOpen = false"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500"
                    aria-label="Close menu"
                >
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4" aria-label="Main">
                <div>
                    <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Input</p>
                    <div class="space-y-1">
                        <a href="{{ route('documents.index') }}" wire:navigate @click="sidebarOpen = false" @class(['flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium', 'bg-navy-50 text-navy-900' => $documentsActive, 'text-slate-600 hover:bg-slate-50' => ! $documentsActive])>Scan Document</a>
                        <a href="{{ route('attachments.index') }}" wire:navigate @click="sidebarOpen = false" @class(['flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium', 'bg-navy-50 text-navy-900' => $attachmentsActive, 'text-slate-600 hover:bg-slate-50' => ! $attachmentsActive])>Scan Attachment</a>
                    </div>
                </div>
                <div>
                    <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Customers</p>
                    <a
                        href="{{ route('customers.index') }}"
                        wire:navigate
                        @click="sidebarOpen = false"
                        @class([
                            'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium',
                            'bg-navy-50 text-navy-900' => $customersActive,
                            'text-slate-600 hover:bg-slate-50' => ! $customersActive,
                        ])
                    >
                        Customers
                    </a>
                </div>
                <div>
                    <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Documents</p>
                    <a href="{{ route('bimbsec.index') }}" wire:navigate @click="sidebarOpen = false" @class(['flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium', 'bg-navy-50 text-navy-900' => $bimbsecActive, 'text-slate-600 hover:bg-slate-50' => ! $bimbsecActive])>BIMBSEC</a>
                </div>
                @if (auth()->user()?->isAdmin())
                    <div>
                        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Administration</p>
                        <div class="space-y-1">
                            <a
                                href="{{ route('users.index') }}"
                                wire:navigate
                                @click="sidebarOpen = false"
                                @class([
                                    'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium',
                                    'bg-navy-50 text-navy-900' => $usersActive,
                                    'text-slate-600 hover:bg-slate-50' => ! $usersActive,
                                ])
                            >
                                Users
                            </a>
                            <a href="{{ route('ai-usage.index') }}" wire:navigate @click="sidebarOpen = false" @class(['flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium', 'bg-navy-50 text-navy-900' => $aiUsageActive, 'text-slate-600 hover:bg-slate-50' => ! $aiUsageActive])>AI Usage</a>
                        </div>
                    </div>
                @endif
            </nav>

            <div class="border-t border-slate-200 px-2 py-1.5" x-data="{ userMenuOpen: false }">
                <button
                    type="button"
                    @click="userMenuOpen = ! userMenuOpen"
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500"
                    :aria-expanded="userMenuOpen.toString()"
                    aria-controls="mobile-user-menu"
                >
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-navy-100 text-[11px] font-semibold leading-none text-navy-800">
                        {{ collect(explode(' ', auth()->user()->name))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}
                    </span>
                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block truncate text-sm font-medium text-slate-800">{{ auth()->user()->name }}</span>
                        <span class="block truncate text-[11px] text-slate-500">{{ auth()->user()->email }}</span>
                    </span>
                    <svg
                        class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform"
                        :class="{ 'rotate-180': userMenuOpen }"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                    </svg>
                </button>

                <div
                    id="mobile-user-menu"
                    x-cloak
                    x-show="userMenuOpen"
                    x-transition
                    class="mt-0.5 space-y-0.5"
                >
                    <a
                        href="{{ route('profile') }}"
                        wire:navigate
                        @click="sidebarOpen = false"
                        class="block rounded-md px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50"
                    >
                        Account
                    </a>
                    <button
                        type="button"
                        wire:click="logout"
                        class="block w-full rounded-md px-2 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-50"
                    >
                        Log Out
                    </button>
                </div>
            </div>
        </aside>
    </div>
</div>
