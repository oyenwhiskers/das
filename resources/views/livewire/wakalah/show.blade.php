<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-4">
        <div>
            <a href="{{ route('wakalah.index') }}" wire:navigate class="text-sm text-navy-800 hover:underline">Wakalah Letter</a>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-navy-900">{{ $customer->full_legal_name }}</h1>
                    <p class="text-sm text-slate-500">{{ $customer->primary_identification_number ?: 'No NRIC / passport on the profile' }}</p>
                </div>
                <a href="{{ route('wakalah.file', [$customer, 'download' => 1]) }}" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700">Download</a>
            </div>
        </div>

        <iframe title="Wakalah Letter preview" src="{{ route('wakalah.file', $customer) }}" class="h-[75vh] w-full rounded-lg border border-slate-200 bg-white"></iframe>
    </div>
</div>
