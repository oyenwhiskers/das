<section aria-label="Attachments" class="space-y-4">
    <div class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-3 sm:px-5">
            <h2 class="text-base font-semibold text-navy-900">Attachments</h2>
            <p class="text-xs text-slate-500">IC, signature, and CDS maintenance files for this customer. Upload here or via Store Attachment.</p>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($types as $type)
            @php
                $rows = $grouped->get($type->value, collect());
                $inputId = 'attachment-upload-'.$type->value;
            @endphp
            <section class="flex flex-col rounded-lg border border-slate-200 bg-white" aria-label="{{ $type->label() }}">
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-navy-900">{{ $type->label() }}</h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            @if ($rows->isEmpty())
                                No file yet
                            @else
                                {{ $rows->count() }} {{ $rows->count() === 1 ? 'file' : 'files' }}
                            @endif
                        </p>
                    </div>
                    <label for="{{ $inputId }}" class="inline-flex shrink-0 cursor-pointer items-center rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-navy-800 hover:bg-slate-50">
                        <span wire:loading.remove wire:target="uploads.{{ $type->value }}">Upload</span>
                        <span wire:loading wire:target="uploads.{{ $type->value }}">Uploading…</span>
                    </label>
                    <input
                        id="{{ $inputId }}"
                        type="file"
                        class="sr-only"
                        wire:model="uploads.{{ $type->value }}"
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                    />
                </div>

                <div class="flex flex-1 flex-col p-4">
                    @error('uploads.'.$type->value)
                        <p class="mb-3 text-sm text-red-700" role="alert">{{ $message }}</p>
                    @enderror

                    @if ($rows->isEmpty())
                        <div class="flex flex-1 flex-col items-center justify-center rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center">
                            <svg class="h-10 w-10 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                            <p class="mt-3 text-sm font-medium text-slate-700">No {{ $type->label() }} yet</p>
                            <p class="mt-1 max-w-[14rem] text-xs text-slate-500">PDF, JPG or PNG up to {{ number_format($maxUploadMb, 1) }} MB.</p>
                            <label for="{{ $inputId }}" class="mt-4 inline-flex cursor-pointer items-center rounded-md bg-navy-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-navy-900">
                                Choose file
                            </label>
                        </div>
                    @else
                        <ul class="divide-y divide-slate-100 rounded-md border border-slate-100">
                            @foreach ($rows as $scan)
                                <li class="flex items-start justify-between gap-3 px-3 py-2.5">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-900" title="{{ $scan->original_filename }}">{{ $scan->original_filename }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            {{ ($scan->attached_at ?? $scan->created_at)?->timezone(config('app.timezone'))->format('d M Y, h:i A') ?? '—' }}
                                            · {{ $scan->uploader?->name ?? 'Unknown' }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 flex-col items-end gap-1">
                                        <a href="{{ route('attachments.file', $scan) }}" target="_blank" rel="noopener noreferrer" class="text-xs font-medium text-navy-800 hover:text-navy-900">Open</a>
                                        <a href="{{ route('attachments.show', $scan) }}" wire:navigate class="text-xs font-medium text-slate-500 hover:text-navy-800">Details</a>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</section>
