<section aria-label="Data Source" class="flex min-h-[calc(100dvh-16rem)] flex-col rounded-lg border border-slate-200 bg-white">
    <div class="shrink-0 border-b border-slate-100 px-4 py-3 sm:px-5">
        <h2 class="text-base font-semibold text-navy-900">Data Source</h2>
        <p class="text-xs text-slate-500">Log of scanned documents merged into this customer profile and the strategy used.</p>
    </div>
    @if ($merges->isEmpty())
        <div class="flex flex-1 flex-col p-4 sm:p-5">
            <div class="flex flex-1 flex-col items-center justify-center rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-12 text-center">
                <svg class="h-10 w-10 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12H9.75m9-6.75H15m-1.5-12.75H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <p class="mt-3 text-sm font-medium text-slate-700">No scanned documents have been merged into this profile yet.</p>
                <p class="mt-1 max-w-sm text-xs text-slate-500">Merges from document review appear here with the strategy used and a link back to the original scan.</p>
            </div>
        </div>
    @else
        <div class="min-h-0 flex-1 overflow-x-auto">
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
                <tbody class="divide-y divide-slate-100 bg-white">
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
