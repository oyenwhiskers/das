<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'DAS') }} — Document Automation System</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=source-sans-3:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-900 antialiased">
        <x-flash-alerts />
        <div class="min-h-screen flex flex-col justify-center items-center px-4 py-10 bg-gradient-to-b from-slate-100 via-slate-50 to-white">
            <div class="text-center mb-6">
                <a href="{{ route('login') }}" wire:navigate class="inline-block">
                    <x-application-logo class="justify-center text-3xl" />
                </a>
                <p class="mt-2 text-sm text-slate-600">Document Automation System</p>
                <p class="mt-1 text-xs text-slate-500">Hairaa Capital Sdn Bhd</p>
            </div>

            <div class="w-full sm:max-w-md px-6 py-5 bg-white border border-slate-200 shadow-sm overflow-hidden rounded-lg">
                {{ $slot }}
            </div>

            <p class="mt-8 text-xs text-slate-500">&copy; {{ date('Y') }} Hairaa Capital Sdn Bhd</p>
        </div>
    </body>
</html>
