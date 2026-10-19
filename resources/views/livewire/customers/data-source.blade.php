<section aria-label="Data Source" class="rounded-lg border border-slate-200 bg-white">
    <div class="border-b border-slate-100 px-4 py-3 sm:px-5">
        <h2 class="text-base font-semibold text-navy-900">Data Source</h2>
        <p class="text-xs text-slate-500">Log of scanned documents merged into this customer profile and the strategy used.</p>
    </div>
    @if ($merges->isEmpty())
        <p class="px-4 py-8 text-center text-sm text-slate-500 sm:px-5">No scanned documents have been merged into this profile yet.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 sm:px-5">Merged</th>
                        <th scope="col" class="px-4 py-3 sm:px-5">Document</th>
                        <th scope="col" class="px-4 py-3 sm:px-5">Strategy</th>
                        <th scope="col" class="px-4 py-3 sm:px-5">Profile</th>
                        <th scope="col" class="px-4 py-3 sm:px-5">Merged by</th>
                        <th scope="col" class="px-4 py-3 sm:px-5"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($merges as $merge)
                        @php
                            $scan = $merge->extractedProfile?->documentScan;
                            $filename = $scan?->original_filename ?? 'Unknown document';
                            $changeCount = is_array($merge->changes) ? count($merge->changes) : 0;
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-slate-700 sm:px-5">
                                {{ $merge->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') ?? '—' }}
                            </td>
                            <td class="max-w-xs px-4 py-3 align-top sm:px-5">
                                <p class="break-all font-medium text-slate-900">{{ $filename }}</p>
                                @if ($changeCount > 0)
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $changeCount }} {{ $changeCount === 1 ? 'field' : 'fields' }} changed</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-slate-700 sm:px-5">{{ $merge->strategyLabel() }}</td>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-slate-700 sm:px-5">{{ $merge->extractedProfile?->record_ref ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-slate-700 sm:px-5">{{ $merge->merger?->name ?? 'Former user' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-right sm:px-5">
                                @if ($scan)
                                    <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                        <a href="{{ route('documents.file', $scan) }}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-navy-800 hover:bg-slate-50">Open original</a>
                                        <a href="{{ route('documents.show', ['documentScan' => $scan, 'q' => $customer->full_legal_name]) }}" wire:navigate class="text-xs font-medium text-navy-700 hover:text-navy-900">View scan</a>
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">Unavailable</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
