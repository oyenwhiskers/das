@php
    $format = static function (float|int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, (float) $bytes);
        $power = $value > 0 ? min((int) floor(log($value, 1024)), count($units) - 1) : 0;

        return number_format($value / (1024 ** $power), $power === 0 ? 0 : 2).' '.$units[$power];
    };

    $status = ! $available
        ? ['label' => 'Capacity unknown', 'tone' => 'slate', 'blurb' => 'We can still show how much DAS itself is using.']
        : ($usedPercent >= 90
            ? ['label' => 'Almost full', 'tone' => 'red', 'blurb' => 'Free up space soon so uploads do not fail.']
            : ($usedPercent >= 70
                ? ['label' => 'Getting full', 'tone' => 'amber', 'blurb' => 'Still usable, but plan for more space.']
                : ['label' => 'Plenty of space', 'tone' => 'emerald', 'blurb' => 'You have room for more uploads.']));

    $tones = [
        'emerald' => ['badge' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'ring' => '#059669', 'track' => '#d1fae5'],
        'amber' => ['badge' => 'bg-amber-50 text-amber-900 ring-amber-200', 'ring' => '#d97706', 'track' => '#fde68a'],
        'red' => ['badge' => 'bg-red-50 text-red-800 ring-red-200', 'ring' => '#dc2626', 'track' => '#fecaca'],
        'slate' => ['badge' => 'bg-slate-100 text-slate-700 ring-slate-200', 'ring' => '#64748b', 'track' => '#e2e8f0'],
    ];
    $tone = $tones[$status['tone']];
    $usedSlice = $available ? min(100, max(0, $usedPercent)) : 0;

    $appTotal = max(0, (int) $app);
    $docShare = $appTotal > 0 ? ($documents / $appTotal) * 100 : 0;
    $attShare = $appTotal > 0 ? ($attachments / $appTotal) * 100 : 0;
    $otherShare = $appTotal > 0 ? ($other / $appTotal) * 100 : 0;
    $docEnd = $docShare;
    $attEnd = $docShare + $attShare;
@endphp

<div class="py-6" wire:poll.30s>
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-navy-900">Storage Usage</h1>
                <p class="mt-1 text-sm text-slate-500">A simple view of how full the server disk is, and how much space DAS files take.</p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset {{ $tone['badge'] }}">{{ $status['label'] }}</span>
        </div>

        @if ($available)
            <div class="rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                <p class="text-lg font-semibold text-navy-900">
                    {{ $format($free) }} free
                    <span class="font-normal text-slate-500">out of {{ $format($total) }}</span>
                </p>
                <p class="mt-1 text-sm text-slate-500">{{ $status['blurb'] }}</p>
            </div>
        @else
            <p class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
                This host could not report free or total disk capacity. {{ $status['blurb'] }}
            </p>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">
            {{-- Disk fullness --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-navy-900">Server disk</h2>
                        <p class="mt-1 text-sm text-slate-500">How full the storage volume is right now.</p>
                    </div>
                    <p class="text-sm font-medium text-slate-600">{{ $available ? number_format($usedPercent, 1).'% used' : '—' }}</p>
                </div>

                <div class="mt-6 flex flex-col items-center gap-6 sm:flex-row sm:items-center">
                    <div
                        class="relative h-44 w-44 shrink-0 rounded-full"
                        style="background: conic-gradient({{ $tone['ring'] }} {{ $usedSlice }}%, {{ $tone['track'] }} 0);"
                        role="img"
                        aria-label="{{ $available ? number_format($usedPercent, 1).'% of the disk is used' : 'Disk capacity unavailable' }}"
                    >
                        <div class="absolute inset-5 flex flex-col items-center justify-center rounded-full bg-white text-center shadow-inner">
                            <span class="text-3xl font-semibold tabular-nums text-navy-900">{{ $available ? number_format($usedPercent, 0) : '—' }}{{ $available ? '%' : '' }}</span>
                            <span class="mt-0.5 text-xs font-medium uppercase tracking-wide text-slate-500">used</span>
                        </div>
                    </div>

                    <ul class="w-full space-y-3 text-sm">
                        <li class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2.5">
                            <span class="inline-flex items-center gap-2 text-slate-700">
                                <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $tone['ring'] }}"></span>
                                Used
                            </span>
                            <span class="font-semibold tabular-nums text-navy-900">{{ $available ? $format($used) : '—' }}</span>
                        </li>
                        <li class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2.5">
                            <span class="inline-flex items-center gap-2 text-slate-700">
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                Free
                            </span>
                            <span class="font-semibold tabular-nums text-navy-900">{{ $available ? $format($free) : '—' }}</span>
                        </li>
                        <li class="flex items-center justify-between gap-3 px-3 py-1 text-slate-500">
                            <span>Total capacity</span>
                            <span class="tabular-nums">{{ $available ? $format($total) : '—' }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            {{-- DAS files --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-navy-900">DAS files</h2>
                        <p class="mt-1 text-sm text-slate-500">What kinds of files this app is storing.</p>
                    </div>
                    <p class="text-sm font-medium text-slate-600">{{ $format($app) }} total</p>
                </div>

                <div class="mt-6 flex flex-col items-center gap-6 sm:flex-row sm:items-center">
                    @if ($appTotal > 0)
                        <div
                            class="relative h-44 w-44 shrink-0 rounded-full"
                            style="background: conic-gradient(#163044 0% {{ $docEnd }}%, #0284c7 {{ $docEnd }}% {{ $attEnd }}%, #94a3b8 {{ $attEnd }}% 100%);"
                            role="img"
                            aria-label="Breakdown of DAS files by category"
                        >
                            <div class="absolute inset-5 flex flex-col items-center justify-center rounded-full bg-white text-center shadow-inner">
                                <span class="text-xl font-semibold tabular-nums text-navy-900">{{ $format($app) }}</span>
                                <span class="mt-0.5 text-xs font-medium uppercase tracking-wide text-slate-500">App files</span>
                            </div>
                        </div>
                    @else
                        <div class="relative flex h-44 w-44 shrink-0 items-center justify-center rounded-full bg-slate-100" role="img" aria-label="No DAS files yet">
                            <div class="absolute inset-5 rounded-full bg-white"></div>
                            <span class="relative text-sm font-medium text-slate-500">Empty</span>
                        </div>
                    @endif

                    <ul class="w-full space-y-3 text-sm">
                        <li>
                            <div class="mb-1.5 flex items-center justify-between gap-3">
                                <span class="inline-flex items-center gap-2 text-slate-700">
                                    <span class="h-2.5 w-2.5 rounded-full bg-navy-800"></span>
                                    Document scans
                                </span>
                                <span class="font-semibold tabular-nums text-navy-900">{{ $format($documents) }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-navy-800" style="width: {{ min(100, $docShare) }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ number_format($documentCount) }} file(s)</p>
                        </li>
                        <li>
                            <div class="mb-1.5 flex items-center justify-between gap-3">
                                <span class="inline-flex items-center gap-2 text-slate-700">
                                    <span class="h-2.5 w-2.5 rounded-full bg-sky-600"></span>
                                    Attachments
                                </span>
                                <span class="font-semibold tabular-nums text-navy-900">{{ $format($attachments) }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-sky-600" style="width: {{ min(100, $attShare) }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ number_format($attachmentCount) }} file(s)</p>
                        </li>
                        <li>
                            <div class="mb-1.5 flex items-center justify-between gap-3">
                                <span class="inline-flex items-center gap-2 text-slate-700">
                                    <span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>
                                    Other app files
                                </span>
                                <span class="font-semibold tabular-nums text-navy-900">{{ $format($other) }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-slate-400" style="width: {{ min(100, $otherShare) }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Generated PDFs, caches, and temp files</p>
                        </li>
                    </ul>
                </div>
            </section>
        </div>

        <details class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600 shadow-sm">
            <summary class="cursor-pointer font-medium text-navy-900">Technical details</summary>
            <div class="mt-3 space-y-1 text-xs text-slate-500">
                <p>Free space is for the whole volume, not a DAS-only quota. In Docker, uploads live on the persistent mount at <code class="text-slate-600">/app/storage/app</code>.</p>
                <p class="break-all">Uploads path: {{ $path }}</p>
                <p class="break-all">Volume probe: {{ $mount }}</p>
            </div>
        </details>
    </div>
</div>
