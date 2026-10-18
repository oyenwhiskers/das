<div class="py-6" @if(in_array($batch->status, ['queued', 'running'])) wire:poll.3s @endif>
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-4">
        <div>
            <a href="{{ route('bimbsec.index') }}" wire:navigate class="text-sm text-navy-800 hover:underline">BIMBSEC</a>
            <h1 class="text-xl font-semibold text-navy-900">Selected PDF</h1>
            <p class="text-sm text-slate-500">
                @if ($batch->status === 'ready' && $batch->complete)
                    Every selected customer is in this PDF, in the selected order.
                @elseif ($batch->status === 'queued' || $batch->status === 'running')
                    Generating. Larger selections run in the queue, {{ config('bimbsec.chunk_size') }} customers at a time.
                @else
                    This batch is not complete. No combined PDF was offered.
                @endif
            </p>
        </div>

        <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            @forelse ($batch->summary ?? [] as $row)
                <li class="flex items-start justify-between gap-3 px-4 py-3">
                    <span>
                        <span class="font-medium text-slate-900">{{ $row['name'] ?: 'Customer' }}</span>
                        <span class="text-slate-500">{{ $row['reference'] }}</span>
                    </span>
                    <span class="{{ ($row['ok'] ?? false) ? 'text-slate-600' : 'text-red-700' }}">{{ ($row['ok'] ?? false) ? 'Generated' : ($row['reason'] ?? 'Failed') }}</span>
                </li>
            @empty
                <li class="px-4 py-3 text-slate-500">Waiting for the first result.</li>
            @endforelse
        </ul>

        @if ($batch->complete)
            <div class="flex gap-2">
                <a href="{{ route('bimbsec.batches.file', [$batch, 'download' => 1]) }}" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Download all</a>
                <button type="button" x-on:click="printPdf(@js(route('bimbsec.batches.print', $batch)))" class="rounded-md bg-navy-800 px-3 py-1.5 text-sm font-medium text-white">Print all</button>
            </div>
            <iframe title="Combined BIMBSEC preview" src="{{ route('bimbsec.batches.file', $batch) }}" class="h-[75vh] w-full rounded-lg border border-slate-200 bg-white"></iframe>
        @endif
    </div>
</div>
