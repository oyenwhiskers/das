<div class="py-6">
    <div class="w-full space-y-5 px-4 sm:px-6 lg:px-8 xl:px-10">
        <div>
            <a href="{{ route('attachments.index') }}" wire:navigate class="text-sm text-navy-700 hover:text-navy-900">&larr; Back to Scan Attachment</a>
            <h1 class="mt-2 text-xl font-semibold text-navy-900 sm:text-2xl">{{ $attachmentScan->original_filename }}</h1>
            <p class="mt-1 text-sm text-slate-500">Uploaded {{ $attachmentScan->created_at->timezone(config('app.timezone'))->format('d M Y, h:i A') }} by {{ $attachmentScan->uploader?->name ?? 'Unknown' }}</p>
        </div>

        @if (session('status'))
            <p class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</p>
        @endif

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" @if (in_array($attachmentScan->status, [\App\Enums\AttachmentScanStatus::Pending, \App\Enums\AttachmentScanStatus::Processing], true)) wire:poll.5s @endif>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-navy-900">Filing status</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        <span @class([
                            'rounded-full px-2 py-1 text-xs font-medium',
                            'bg-emerald-50 text-emerald-700' => $attachmentScan->status === \App\Enums\AttachmentScanStatus::Attached,
                            'bg-amber-50 text-amber-800' => $attachmentScan->status === \App\Enums\AttachmentScanStatus::NeedsReview,
                            'bg-red-50 text-red-700' => $attachmentScan->status === \App\Enums\AttachmentScanStatus::Failed,
                            'bg-slate-100 text-slate-700' => in_array($attachmentScan->status, [\App\Enums\AttachmentScanStatus::Pending, \App\Enums\AttachmentScanStatus::Processing], true),
                        ])>{{ $attachmentScan->status->label() }}</span>
                    </p>
                </div>
                <a href="{{ route('attachments.file', $attachmentScan) }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 items-center rounded-md border border-slate-300 px-3 text-sm font-medium text-navy-800 hover:bg-slate-50">Open file</a>
            </div>
            <dl class="grid gap-4 px-5 py-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Type</dt>
                    <dd class="mt-1 text-sm text-slate-900">{{ $attachmentScan->attachment_type?->label() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Extracted name</dt>
                    <dd class="mt-1 text-sm text-slate-900">{{ $attachmentScan->extracted_name ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Extracted IC</dt>
                    <dd class="mt-1 text-sm text-slate-900">{{ $attachmentScan->extracted_identification_number ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Attached to</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        @if ($attachmentScan->customer)
                            <a href="{{ route('customers.show', ['customer' => $attachmentScan->customer, 'tab' => 'attachments']) }}" wire:navigate class="font-medium text-navy-800 hover:text-navy-900">{{ $attachmentScan->customer->full_legal_name }}</a>
                            <span class="text-slate-500">({{ $attachmentScan->customer->reference }})</span>
                            @if ($attachmentScan->matched_by)
                                <p class="mt-0.5 text-xs text-slate-500">Matched manually by {{ $attachmentScan->matcher?->name ?? 'former user' }}</p>
                            @elseif ($attachmentScan->status === \App\Enums\AttachmentScanStatus::Attached)
                                <p class="mt-0.5 text-xs text-slate-500">Matched automatically by IC</p>
                            @endif
                        @elseif ($attachmentScan->status === \App\Enums\AttachmentScanStatus::NeedsReview)
                            <span class="text-amber-800">Needs review</span>
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
            @if ($attachmentScan->processing_error)
                <p class="border-t border-slate-100 px-5 py-3 text-sm text-red-700" role="alert">{{ $attachmentScan->processing_error }}</p>
            @endif
        </section>

        @if ($attachmentScan->status === \App\Enums\AttachmentScanStatus::NeedsReview)
            <section class="overflow-hidden rounded-lg border border-amber-200 bg-white shadow-sm">
                <div class="border-b border-amber-100 bg-amber-50 px-5 py-4">
                    <h2 class="text-sm font-semibold text-navy-900">Match customer</h2>
                    <p class="mt-1 text-sm text-slate-600">No unique customer matched this IC. Search and attach the file manually.</p>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label for="attachment-match-customer" class="block text-sm font-medium text-slate-700">Customer</label>
                        <input id="attachment-match-customer" wire:model.live.debounce.300ms="customerQuery" type="search" placeholder="Name, reference, or identification" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                        <div class="mt-2 max-h-56 overflow-y-auto rounded-md border border-slate-200">
                            @forelse ($matchCustomers as $customer)
                                <button type="button" wire:click="selectMatchCustomer({{ $customer->id }})" class="block w-full px-3 py-2 text-left text-sm {{ (string) $customer->id === $matchCustomerId ? 'bg-slate-100 font-medium text-navy-900' : 'text-slate-700 hover:bg-slate-50' }}">
                                    {{ $customer->full_legal_name }} · {{ $customer->reference }}
                                    @if ($customer->primary_identification_number)
                                        <span class="text-slate-500">· {{ $customer->primary_identification_number }}</span>
                                    @endif
                                </button>
                            @empty
                                <p class="px-3 py-3 text-sm text-slate-500">No active customers match that search.</p>
                            @endforelse
                        </div>
                    </div>
                    @error('match') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    <div class="flex justify-end">
                        <button type="button" wire:click="confirmMatch" wire:loading.attr="disabled" @disabled($matchCustomerId === '') class="inline-flex h-9 items-center rounded-md bg-navy-800 px-3.5 text-sm font-medium text-white hover:bg-navy-900 disabled:cursor-not-allowed disabled:opacity-50">Attach to customer</button>
                    </div>
                </div>
            </section>
        @endif
    </div>
</div>
