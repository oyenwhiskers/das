<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold text-navy-900">Scan Document</h1>
            <p class="mt-1 text-sm text-slate-500">Upload a document to extract customer profile fields. Customer records are not changed.</p>
        </div>

        <form wire:submit="processDocument" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-navy-900">Upload document</h2>
                <p class="mt-1 text-sm text-slate-500">PDF, JPG, JPEG or PNG, up to {{ number_format($maxUploadMb, 1) }} MB.</p>
            </div>
            <div class="p-5">
                <label for="document-upload" x-data="{ dragging: false }" x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false" x-on:drop.prevent.stop="dragging = false; const file = $event.dataTransfer?.files?.item(0); if (file) $wire.upload('document', file)" :class="{ 'border-navy-500 bg-navy-50': dragging }" class="flex cursor-pointer flex-col items-center rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 px-6 py-8 text-center hover:border-navy-400">
                    <input id="document-upload" type="file" wire:model="document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="sr-only" />
                    <span class="text-sm font-medium text-slate-800">Drag a file here</span>
                    <span class="mt-1 text-sm text-slate-500">or choose one from your computer</span>
                    <span class="mt-4 inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800">Choose file</span>
                </label>
                @error('document') <p class="mt-3 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                <p wire:loading wire:target="document" class="mt-3 text-sm text-slate-500">Preparing upload…</p>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
                <p class="min-w-0 truncate text-sm text-slate-600">{{ $document ? $document->getClientOriginalName() : 'No file selected' }}</p>
                <button type="submit" wire:loading.attr="disabled" wire:target="processDocument" @disabled(! $document) class="inline-flex h-9 items-center rounded-md bg-navy-800 px-3.5 text-sm font-medium text-white hover:bg-navy-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">Upload and process</button>
            </div>
        </form>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm" wire:poll.10s>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-navy-900">Documents</h2>
                @if ($selected !== [])
                    <button type="button" x-on:click.prevent="if (await confirmAction({ title: 'Delete selected documents?', text: 'The uploaded files and extracted profiles will be removed. Customer records stay.', confirmButtonText: 'Delete', confirmButtonColor: '#b91c1c' })) $wire.deleteSelected()" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Delete selected ({{ count($selected) }})</button>
                @endif
            </div>
            @if ($documents->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-slate-500">No documents uploaded yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3"><input type="checkbox" wire:click.prevent="toggleAll" @checked($documents->total() > 0 && count($selected) >= $documents->total()) aria-label="Select all documents" /></th>
                                <th scope="col" class="px-4 py-3">Filename</th><th scope="col" class="px-4 py-3">Document type</th><th scope="col" class="px-4 py-3">Summary</th><th scope="col" class="px-4 py-3">Uploaded</th><th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3">Uploaded by</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach ($documents as $scan)
                                <tr class="cursor-pointer hover:bg-slate-50" x-on:click="if (!$event.target.closest('button, input')) Livewire.navigate(@js(route('documents.show', $scan)))">
                                    <td class="px-4 py-3"><input type="checkbox" wire:model.live="selected" value="{{ $scan->id }}" aria-label="Select {{ $scan->original_filename }}" /></td>
                                    <td class="max-w-xs truncate px-4 py-3 font-medium text-slate-900">{{ $scan->original_filename }}</td>
                                    <td class="px-4 py-3">{{ $scan->document_type ?? '—' }}</td>
                                    <td class="max-w-sm px-4 py-3 text-slate-600">
                                        @if (filled($scan->summary))
                                            <span class="line-clamp-2" title="{{ $scan->summary }}">{{ $scan->summary }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $scan->created_at->timezone(config('app.timezone'))->format('d/m/Y, h:i A') }}</td>
                                    <td class="px-4 py-3"><span @class(['rounded-full px-2 py-1 text-xs font-medium', 'bg-emerald-50 text-emerald-700' => $scan->status->value === 'completed' && $scan->extraction_status === 'completed', 'bg-red-50 text-red-700' => $scan->status->value === 'failed' || $scan->extraction_status === 'failed', 'bg-slate-100 text-slate-700' => $scan->status->value !== 'failed' && in_array($scan->extraction_status, ['not_started', 'processing'])])>{{ $scan->status->value === 'completed' ? match ($scan->extraction_status) { 'completed' => 'Completed', 'processing' => 'Extracting', 'failed' => 'Extraction failed', default => 'Waiting for extraction' } : $scan->status->label() }}</span></td>
                                    <td class="px-4 py-3">{{ $scan->uploader?->name ?? 'Unknown' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-200 px-4 py-3">{{ $documents->links() }}</div>
            @endif
        </section>
    </div>
</div>
