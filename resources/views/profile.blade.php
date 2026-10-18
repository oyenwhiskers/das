<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-navy-900 leading-tight">
            Account
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-6">
            <div class="p-4 sm:p-8 bg-white border border-slate-200 shadow-sm rounded-lg">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white border border-slate-200 shadow-sm rounded-lg">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
