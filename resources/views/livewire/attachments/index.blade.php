<div class="py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold text-navy-900">Scan Attachment</h1>
            <p class="mt-1 text-sm text-slate-500">Upload IC, signature, or CDS maintenance files to file them against the matching customer profile. Profile fields are not changed.</p>
        </div>

        <form wire:submit="processDocument" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-navy-900">Upload attachment</h2>
                <p class="mt-1 text-sm text-slate-500">PDF, JPG, JPEG or PNG, up to {{ number_format($maxUploadMb, 1) }} MB.</p>
            </div>
            <div class="p-5">
                <label for="attachment-upload" x-data="{ dragging: false }" x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false" x-on:drop.prevent.stop="dragging = false; const file = $event.dataTransfer?.files?.item(0); if (file) $wire.upload('document', file)" :class="{ 'border-navy-500 bg-navy-50': dragging }" class="flex cursor-pointer flex-col items-center rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 px-6 py-8 text-center hover:border-navy-400">
                    <input id="attachment-upload" type="file" wire:model="document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="sr-only" />
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
                <h2 class="text-sm font-semibold text-navy-900">Attachments</h2>
                @if ($selected !== [])
                    <button type="button" x-on:click.prevent="if (await confirmAction({ title: 'Delete selected attachments?', text: 'The uploaded files will be removed. Customer records stay.', confirmButtonText: 'Delete', confirmButtonColor: '#b91c1c' })) $wire.deleteSelected()" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Delete selected ({{ count($selected) }})</button>
                @endif
            </div>
            @if ($documents->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-slate-500">No attachments uploaded yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3"><input type="checkbox" wire:click.prevent="toggleAll" @checked($documents->total() > 0 && count($selected) >= $documents->total()) aria-label="Select all attachments" /></th>
                                <th scope="col" class="px-4 py-3">Filename</th>
                                <th scope="col" class="px-4 py-3">Type</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Attached to</th>
                                <th scope="col" class="px-4 py-3">Uploaded</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach ($documents as $scan)
                                <tr class="cursor-pointer hover:bg-slate-50" x-on:click="if (!$event.target.closest('button, input, a')) Livewire.navigate(@js(route('attachments.show', $scan)))">
                                    <td class="px-4 py-3"><input type="checkbox" wire:model.live="selected" value="{{ $scan->id }}" aria-label="Select {{ $scan->original_filename }}" /></td>
                                    <td class="max-w-xs truncate px-4 py-3 font-medium text-slate-900">{{ $scan->original_filename }}</td>
                                    <td class="px-4 py-3">{{ $scan->attachment_type?->label() ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'rounded-full px-2 py-1 text-xs font-medium',
                                            'bg-emerald-50 text-emerald-700' => $scan->status === \App\Enums\AttachmentScanStatus::Attached,
                                            'bg-amber-50 text-amber-800' => $scan->status === \App\Enums\AttachmentScanStatus::NeedsReview,
                                            'bg-red-50 text-red-700' => $scan->status === \App\Enums\AttachmentScanStatus::Failed,
                                            'bg-slate-100 text-slate-700' => in_array($scan->status, [\App\Enums\AttachmentScanStatus::Pending, \App\Enums\AttachmentScanStatus::Processing], true),
                                        ])>{{ $scan->status->label() }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($scan->customer)
                                            <a href="{{ route('customers.show', $scan->customer) }}" wire:navigate class="font-medium text-navy-800 hover:text-navy-900" x-on:click.stop>{{ $scan->customer->full_legal_name }}</a>
                                        @elseif ($scan->status === \App\Enums\AttachmentScanStatus::NeedsReview)
                                            <span class="text-amber-800">Needs review</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $scan->created_at->timezone(config('app.timezone'))->format('d/m/Y, h:i A') }}</td>
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
