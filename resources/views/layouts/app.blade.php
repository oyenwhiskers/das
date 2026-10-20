<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'DAS') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=source-sans-3:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-[#F9F7F5] text-slate-900 h-full">
        <x-flash-alerts />
        <div class="flex min-h-full" x-data="{ sidebarOpen: false }">
            <livewire:layout.navigation />

            <div class="flex min-w-0 flex-1 flex-col">
                <div class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white px-4 py-2 lg:hidden">
                    <button
                        type="button"
                        @click="sidebarOpen = true"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500"
                        aria-label="Open menu"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <a href="{{ route('customers.index') }}" wire:navigate class="min-w-0">
                        <x-application-logo class="text-lg" />
                    </a>
                </div>

                @if (isset($header))
                    <header class="border-b border-slate-200 bg-white shrink-0">
                        <div class="w-full px-4 py-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif

                <main class="flex-1">
                    {{ $slot }}
                </main>

                <footer class="border-t border-slate-200 bg-white shrink-0">
                    <div class="flex w-full flex-col gap-2 px-4 py-4 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                        <span>DAS — Document Automation System</span>
                        <span>Hairaa Capital Sdn Bhd</span>
                    </div>
                </footer>
            </div>
        </div>
    </body>
</html>
