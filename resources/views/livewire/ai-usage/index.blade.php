<div class="py-6" wire:poll.10s>
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold text-navy-900">AI Usage</h1>
            <p class="mt-1 text-sm text-slate-500">Model gemini-3.1-flash-lite. Costs are estimates in RM, converted at RM {{ number_format($rate, 2) }} per USD.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm">
            @foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'year' => 'This year', 'date' => 'Specific date'] as $key => $label)
                <button type="button" wire:click="setPeriod('{{ $key }}')" @class(['inline-flex h-8 items-center rounded-md px-3 text-sm font-medium', 'bg-navy-800 text-white' => $period === $key, 'text-slate-700 hover:bg-slate-50' => $period !== $key])>{{ $label }}</button>
            @endforeach
            @if ($period === 'date')
                <input id="usage-on" aria-label="Specific date" wire:model.live="on" type="date" class="h-8 rounded-md border-slate-300 py-0 text-sm" />
            @endif
            <button type="button" x-on:click.prevent="if (await confirmAction({ title: 'Reset usage history?', text: 'Recorded AI requests will be removed.', confirmButtonText: 'Reset history', confirmButtonColor: '#b91c1c' })) $wire.resetHistory()" class="ml-auto inline-flex h-8 items-center rounded-md border border-red-300 px-3 text-sm font-medium text-red-700 hover:bg-red-50">Reset history</button>
        </div>
        <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['Requests', number_format($totals->requests), 'AI calls in this period'],
                ['Documents', number_format($totals->documents), 'Uploads those calls belong to'],
                ['Total tokens', number_format(($totals->input_tokens ?? 0) + ($totals->output_tokens ?? 0) + ($totals->thought_tokens ?? 0)), 'Input, output, and thought tokens'],
                ['Total cost', 'RM '.number_format($cost, 4), 'Estimated spend for this period'],
                ['Cost per request', 'RM '.number_format($averageRequest, 4), 'Total cost divided by priced calls'],
                ['Cost per document', $averageDocument === null ? '—' : 'RM '.number_format($averageDocument, 4), $averageDocument === null ? 'No document is linked to these requests' : 'Spend still linked to an upload, divided by those uploads'],
            ] as [$label, $value, $hint])
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <dt class="text-sm text-slate-500">{{ $label }}</dt>
                    <dd class="mt-2 text-xl font-semibold text-navy-900">{{ $value }}</dd>
                    <dd class="mt-2 text-xs text-slate-500">{{ $hint }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($totals->unpriced)
            <p class="text-sm text-amber-800" role="status">{{ number_format($totals->unpriced) }} request(s) have unknown cost because usage data was unavailable. Cost totals leave these requests out.</p>
        @endif
        @if ($totals->unlinked_priced)
            <p class="text-sm text-amber-800" role="status">{{ number_format($totals->unlinked_priced) }} priced request(s) are no longer linked to an upload (RM {{ number_format($unlinkedCost, 4) }}). Cost per document leaves that spend out.</p>
        @endif
        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-sm font-semibold text-navy-900">Requests</h2></div>
            @if ($requests->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-slate-500">No AI requests recorded for this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th scope="col" class="px-4 py-3">Time</th><th scope="col" class="px-4 py-3">Document</th><th scope="col" class="px-4 py-3">HTTP</th><th scope="col" class="px-4 py-3">Input</th><th scope="col" class="px-4 py-3">Output</th><th scope="col" class="px-4 py-3">Thought</th><th scope="col" class="px-4 py-3">Cost (RM)</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach ($requests as $request)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $request->created_at->timezone(config('app.timezone'))->format('d/m/Y, h:i A') }}</td>
                                    <td class="px-4 py-3">@if ($request->document_scan_id)<a class="font-medium text-navy-800 hover:underline" href="{{ route('documents.show', $request->document_scan_id) }}" wire:navigate>#{{ $request->document_scan_id }}</a>@else — @endif</td>
                                    <td class="px-4 py-3">{{ $request->http_status ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $request->input_tokens === null ? '—' : number_format($request->input_tokens) }}</td>
                                    <td class="px-4 py-3">{{ $request->output_tokens === null ? '—' : number_format($request->output_tokens) }}</td>
                                    <td class="px-4 py-3">{{ $request->thought_tokens === null ? '—' : number_format($request->thought_tokens) }}</td>
                                    <td class="px-4 py-3">{{ $request->cost_usd === null ? '—' : 'RM '.number_format($request->cost_usd * $rate, 4) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-200 px-4 py-3">{{ $requests->links() }}</div>
            @endif
        </section>
    </div>
</div>
