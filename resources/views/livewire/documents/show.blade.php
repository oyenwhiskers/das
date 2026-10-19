<div class="py-6" @if (in_array($scan->status->value, ['pending', 'processing']) || in_array($scan->extraction_status, ['not_started', 'processing']) && $scan->status->value === 'completed' || in_array($scan->profile_status, ['not_started', 'processing']) && $scan->profile_schema_hash && $scan->extraction_status === 'completed' || $scan->extraction_status === 'failed' && collect($extractionGroups)->contains(fn (array $group): bool => $group['status'] === 'Extracting')) wire:poll.5s @endif>
    <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 space-y-5">
        <div>
            <a href="{{ route('documents.index') }}" wire:navigate class="text-sm font-medium text-navy-800 hover:underline">← All documents</a>
            <h1 class="mt-3 break-all text-xl sm:text-2xl font-semibold text-navy-900">{{ $scan->original_filename }}</h1>
            <p class="mt-1 text-sm text-slate-500">Uploaded by {{ $scan->uploader?->name ?? 'Unknown' }} on {{ $scan->created_at->timezone(config('app.timezone'))->format('d/m/Y, h:i A') }}</p>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-navy-900">Processing</h2>
                <span @class(['rounded-full px-2.5 py-1 text-xs font-medium', 'bg-emerald-50 text-emerald-700' => $scan->status->value === 'completed', 'bg-red-50 text-red-700' => $scan->status->value === 'failed', 'bg-slate-100 text-slate-700' => in_array($scan->status->value, ['pending', 'processing'])])>{{ $scan->status->label() }}</span>
            </div>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-slate-500">File type</dt><dd class="mt-1 font-medium text-slate-800">{{ $scan->mime_type }}</dd></div>
                <div><dt class="text-slate-500">File size</dt><dd class="mt-1 font-medium text-slate-800">{{ number_format($scan->file_size / 1024, 1) }} KB</dd></div>
                <div><dt class="text-slate-500">Model</dt><dd class="mt-1 font-medium text-slate-800">{{ $scan->gemini_model ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Completed</dt><dd class="mt-1 font-medium text-slate-800">{{ $scan->processing_completed_at?->timezone(config('app.timezone'))->format('d/m/Y, h:i A') ?? '—' }}</dd></div>
            </dl>
            <a href="{{ route('documents.file', $scan) }}" class="mt-5 inline-flex rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-navy-800 hover:bg-slate-50">Download original</a>
        </section>

        @php
            $storedFindings = $scan->findings
                ? count($scan->findings->observations['fields'] ?? []) + count($scan->findings->observations['tables'] ?? []) + count($scan->findings->observations['content_blocks'] ?? [])
                : 0;
        @endphp
        @if ($scan->status->value === 'completed' && ($storedFindings > 0 || $scan->extraction_status !== 'completed' || $extractionGroups !== []))
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-navy-900">Detailed extraction</h2>
                <p class="mt-2 text-sm text-slate-700">Status: {{ match ($scan->extraction_status) { 'completed' => 'Completed', 'processing' => 'Processing', 'failed' => 'Failed', default => 'Pending' } }}</p>
                @if ($scan->extraction_status === 'not_started')
                    <p class="mt-2 text-sm text-slate-600" role="status">Waiting for the extraction worker. This page refreshes automatically.</p>
                @elseif ($scan->extraction_status === 'processing')
                    <div class="mt-3 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900" role="status" aria-live="polite">
                        @if ($extractionGroups !== [])
                            <p class="font-medium">{{ collect($extractionGroups)->every(fn (array $group): bool => $group['status'] === 'Complete') ? 'Combining extracted pages' : 'Reading PDF page groups' }}</p>
                            <p class="mt-1">This page refreshes automatically.</p>
                        @else
                            <p class="font-medium">{{ $scan->mime_type === 'application/pdf' ? 'Preparing PDF pages or reading the document' : 'Reading the document with AI' }}</p>
                            <p class="mt-1">A page count is not available yet. This page refreshes automatically.</p>
                        @endif
                    </div>
                @endif
                @if ($extractionGroups !== [])
                    <div class="mt-3">
                        <h3 class="text-sm font-medium text-slate-700">PDF page groups</h3>
                        <ul class="mt-2 divide-y divide-slate-200 rounded-md border border-slate-200" aria-label="PDF page group statuses" aria-live="polite">
                            @foreach ($extractionGroups as $group)
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span>Group {{ $loop->iteration }} · from page {{ $group['start'] }}</span>
                                    <span @class(['font-medium', 'text-emerald-700' => $group['status'] === 'Complete', 'text-blue-700' => $group['status'] === 'Extracting', 'text-red-700' => $group['status'] === 'Failed', 'text-slate-500' => $group['status'] === 'Waiting'])>{{ $group['status'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($scan->extraction_status === 'completed' && $scan->findings && $findingsView)
                    <p class="mt-1 text-sm text-slate-600">{{ count($scan->findings->observations['fields']) + count($scan->findings->observations['tables']) + count($scan->findings->observations['content_blocks']) }} findings stored.</p>

                    @if ($findingsView['entities'] !== [])
                        <div class="mt-5">
                            <h3 class="text-sm font-medium text-slate-500">Entities</h3>
                            <ul class="mt-2 divide-y divide-slate-100 border-t border-slate-100">
                                @foreach ($findingsView['entities'] as $entity)
                                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2 text-sm">
                                        <span class="font-medium text-slate-900">{{ $entity['name'] }}</span>
                                        <span class="text-slate-500">{{ $entity['role'] }} · {{ $entity['type'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @foreach ($findingsView['sections'] as $section)
                        @if ($section['fields'] !== [])
                            <div class="mt-5">
                                <h3 class="text-sm font-medium text-slate-500">{{ $section['heading'] }}</h3>
                                <div class="mt-2 overflow-x-auto">
                                    <table class="w-full min-w-[48rem] table-fixed text-left text-sm">
                                        <colgroup>
                                            <col style="width: 32%">
                                            <col style="width: 48%">
                                            <col style="width: 14%">
                                            <col style="width: 6%">
                                        </colgroup>
                                        <thead class="border-b border-slate-200 text-slate-500">
                                            <tr>
                                                <th scope="col" class="py-2 pr-4 font-medium">Field</th>
                                                <th scope="col" class="py-2 pr-4 font-medium">Value</th>
                                                <th scope="col" class="py-2 pr-4 font-medium">Status</th>
                                                <th scope="col" class="py-2 font-medium">Page</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($section['fields'] as $field)
                                                <tr class="align-top">
                                                    <td class="py-2 pr-4 break-words font-medium text-slate-800">{{ $field['label'] }}</td>
                                                    <td class="py-2 pr-4 whitespace-pre-wrap break-words text-slate-900">{{ $field['value'] }}</td>
                                                    <td class="py-2 pr-4 capitalize text-slate-600">{{ $field['status'] }}</td>
                                                    <td class="py-2 text-slate-600">{{ $field['page'] ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @endforeach

                    @if ($findingsView['unsectioned_fields'] !== [])
                        <div class="mt-5">
                            <h3 class="text-sm font-medium text-slate-500">{{ $findingsView['sections'] === [] ? 'Extracted fields' : 'Other fields' }}</h3>
                            <div class="mt-2 overflow-x-auto">
                                <table class="w-full min-w-[48rem] table-fixed text-left text-sm">
                                    <colgroup>
                                        <col style="width: 32%">
                                        <col style="width: 48%">
                                        <col style="width: 14%">
                                        <col style="width: 6%">
                                    </colgroup>
                                    <thead class="border-b border-slate-200 text-slate-500">
                                        <tr>
                                            <th scope="col" class="py-2 pr-4 font-medium">Field</th>
                                            <th scope="col" class="py-2 pr-4 font-medium">Value</th>
                                            <th scope="col" class="py-2 pr-4 font-medium">Status</th>
                                            <th scope="col" class="py-2 font-medium">Page</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($findingsView['unsectioned_fields'] as $field)
                                            <tr class="align-top">
                                                <td class="py-2 pr-4 break-words font-medium text-slate-800">{{ $field['label'] }}</td>
                                                <td class="py-2 pr-4 whitespace-pre-wrap break-words text-slate-900">{{ $field['value'] }}</td>
                                                <td class="py-2 pr-4 capitalize text-slate-600">{{ $field['status'] }}</td>
                                                <td class="py-2 text-slate-600">{{ $field['page'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    @foreach ($findingsView['tables'] as $table)
                        <div class="mt-5">
                            <h3 class="text-sm font-medium text-slate-500">Table: {{ $table['heading'] }}</h3>
                            @if ($table['columns'] === [])
                                <p class="mt-2 text-sm text-slate-600">No columns extracted.</p>
                            @else
                                <div class="mt-2 overflow-x-auto">
                                    <table class="min-w-full text-left text-sm">
                                        <thead class="border-b border-slate-200 text-slate-500">
                                            <tr>
                                                @foreach ($table['columns'] as $column)
                                                    <th scope="col" class="py-2 pr-4 font-medium">{{ $column }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @forelse ($table['rows'] as $row)
                                                <tr class="align-top">
                                                    @foreach ($row as $cell)
                                                        <td class="py-2 pr-4 whitespace-pre-wrap text-slate-900">{{ $cell }}</td>
                                                    @endforeach
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="{{ count($table['columns']) }}" class="py-2 text-slate-600">No rows extracted.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($findingsView['content_blocks'] !== [])
                        <div class="mt-5">
                            <h3 class="text-sm font-medium text-slate-500">Content</h3>
                            <ul class="mt-2 space-y-3">
                                @foreach ($findingsView['content_blocks'] as $block)
                                    <li class="text-sm">
                                        <p class="capitalize text-slate-500">{{ $block['type'] }}@if (! empty($block['page'])) · p{{ $block['page'] }}@endif</p>
                                        <p class="mt-1 whitespace-pre-wrap text-slate-800">{{ $block['text'] }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($findingsView['issues'] !== [])
                        <div class="mt-5">
                            <h3 class="text-sm font-medium text-slate-500">Extraction issues</h3>
                            <ul class="mt-2 space-y-2">
                                @foreach ($findingsView['issues'] as $issue)
                                    <li class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                        <span class="font-medium capitalize">{{ $issue['severity'] }}:</span>
                                        {{ $issue['description'] }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
                @if ($scan->extraction_status === 'failed')
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                        <p class="font-medium">Detailed extraction did not complete</p>
                        <p class="mt-1 text-red-700">Basic document understanding finished successfully. The separate detailed extraction step did not:</p>
                        <p class="mt-2 whitespace-pre-wrap">{{ $scan->extraction_error ?? 'Detailed extraction could not be completed. Please try again or contact an administrator.' }}</p>
                        @if ($scan->readable && ! $scan->findings)
                            <button type="button" wire:click="retryExtraction" wire:loading.attr="disabled" class="mt-3 rounded-md border border-red-300 px-3 py-2 font-medium hover:bg-red-100 disabled:opacity-50">Retry extraction</button>
                        @endif
                    </div>
                @endif
            </section>
        @endif

        @error('match') <p class="whitespace-pre-line text-sm text-red-700">{{ $message }}</p> @enderror
        @if ($fieldFill)
            <div class="space-y-5">
                @if ($fieldFill['profiles'] !== [])
                    @php
                        $applicantRows = [];
                        foreach ($fieldFill['profiles'] as $index => $profile) {
                            $filled = collect($profile['sections'])->flatMap(fn (array $section): array => $section['filled']);
                            $identification = (string) ($filled->firstWhere('field_id', 'primary_identification.number')['value'] ?? '');
                            $pagesLabel = $profile['pages'] === [] ? '' : (count($profile['pages']) === 1 ? 'Page ' : 'Pages ').implode(', ', $profile['pages']);
                            $warning = (string) ($filled->pluck('warning')->first(fn ($warning): bool => is_string($warning) && $warning !== '') ?? '');
                            $applicantRows[] = [
                                'profile' => $profile,
                                'name' => $profile['heading'] !== '' ? $profile['heading'] : 'Unnamed applicant',
                                'identification' => $identification,
                                'pages_label' => $pagesLabel,
                                'warning' => $warning,
                                'search' => mb_strtolower(implode(' ', array_filter([
                                    (string) ($index + 1),
                                    'applicant '.($index + 1),
                                    $profile['heading'] !== '' ? $profile['heading'] : 'Unnamed applicant',
                                    $identification,
                                    preg_replace('/\D+/', '', $identification) ?: null,
                                    $pagesLabel,
                                    implode(' ', array_map(fn ($page): string => 'page '.$page, $profile['pages'])),
                                ], fn ($part): bool => is_string($part) && $part !== ''))),
                            ];
                        }
                    @endphp
                    <div
                        x-data="{
                            query: (() => { const value = new URLSearchParams(window.location.search).get('q'); return value ? value : ''; })(),
                            viewing: null,
                            searches: @js(array_column($applicantRows, 'search')),
                            matches(text) {
                                const term = this.query.trim().toLowerCase();
                                return term === '' || text.includes(term);
                            },
                            anyMatch() {
                                const term = this.query.trim().toLowerCase();
                                return term === '' || this.searches.some((text) => text.includes(term));
                            }
                        }"
                        x-on:keydown.escape.window="viewing = null"
                        class="space-y-3"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 class="text-sm font-semibold text-navy-900">Extracted profiles</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ count($applicantRows) }} {{ count($applicantRows) === 1 ? 'applicant' : 'applicants' }}</p>
                            </div>
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <div class="w-full sm:w-80">
                                    <label for="applicant-filter" class="sr-only">Find an applicant</label>
                                    <input id="applicant-filter" x-model="query" type="search" placeholder="Name, identification, or page" class="block h-9 w-full rounded-md border-slate-300 text-sm" />
                                </div>
                                @if (collect($fieldFill['profiles'])->contains(fn (array $profile): bool => $profile['review_profile_id'] !== null))
                                    <button type="button" x-on:click="matchWithLoader($wire, 'autoMatchAll', 'Finding customers')" wire:loading.attr="disabled" wire:target="autoMatchAll" class="inline-flex h-9 items-center justify-center rounded-md border border-navy-800 bg-white px-3 text-sm font-medium text-navy-800 disabled:opacity-50">Auto-Match all</button>
                                @endif
                            </div>
                        </div>
                        <div x-show="anyMatch()" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50">
                                        <tr>
                                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">#</th>
                                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Applicant</th>
                                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Identification</th>
                                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Pages</th>
                                            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-slate-800">
                                        @foreach ($applicantRows as $row)
                                            @php $openId = (int) ($row['profile']['review_profile_id'] ?? $loop->iteration); @endphp
                                            <tr
                                                x-show="matches(searches[{{ $loop->index }}])"
                                                class="cursor-pointer hover:bg-slate-50/80 focus-within:bg-slate-50/80"
                                                tabindex="0"
                                                aria-label="View {{ $row['name'] }}"
                                                x-on:click="viewing = {{ $openId }}"
                                                x-on:keydown.enter.prevent="viewing = {{ $openId }}"
                                                x-on:keydown.space.prevent="viewing = {{ $openId }}"
                                            >
                                                <td class="px-4 py-3 align-middle whitespace-nowrap text-slate-500">{{ $loop->iteration }}</td>
                                                <td class="px-4 py-3 align-middle">
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Applicant {{ $loop->iteration }}@if ($loop->count > 1) of {{ $loop->count }}@endif</p>
                                                    <p class="mt-0.5 font-medium text-navy-900">{{ $row['name'] }}@if ($row['warning'] !== '') <span class="ml-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-800" title="{{ $row['warning'] }}">!</span>@endif</p>
                                                </td>
                                                <td class="px-4 py-3 align-middle whitespace-nowrap">{{ $row['identification'] !== '' ? $row['identification'] : '—' }}</td>
                                                <td class="px-4 py-3 align-middle whitespace-nowrap text-slate-600">{{ $row['pages_label'] !== '' ? $row['pages_label'] : '—' }}</td>
                                                <td class="px-4 py-3 align-middle" x-on:click.stop x-on:keydown.stop>
                                                    <div class="flex items-center justify-end gap-2">
                                                        <button type="button" x-on:click="viewing = {{ $openId }}" class="inline-flex h-8 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700">View</button>
                                                        @if ($row['profile']['review_profile_id'])
                                                            <button type="button" x-on:click="viewing = null; matchWithLoader($wire, 'autoMatch', 'Finding customer', {{ $row['profile']['review_profile_id'] }})" wire:loading.attr="disabled" wire:target="autoMatch" class="inline-flex h-8 items-center rounded-md border border-navy-800 bg-white px-3 text-sm font-medium leading-none text-navy-800 disabled:opacity-50">Auto-Match</button>
                                                            <button type="button" x-on:click="viewing = null" wire:click="openMatch({{ $row['profile']['review_profile_id'] }})" class="inline-flex h-8 items-center rounded-md bg-navy-800 px-3 text-sm font-medium leading-none text-white">Match</button>
                                                        @endif
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <p x-show="!anyMatch()" x-cloak class="rounded-lg border border-slate-200 bg-white px-5 py-8 text-center text-sm text-slate-600">No applicant matches that search.</p>
                        {{-- shortcut: every profile stays in the page until opened; load one profile on View if a document is slow to open. --}}
                        @foreach ($applicantRows as $row)
                            @php $openId = (int) ($row['profile']['review_profile_id'] ?? $loop->iteration); $profile = $row['profile']; @endphp
                            <div x-show="viewing === {{ $openId }}" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                                <div class="absolute inset-0 bg-slate-900/40" x-on:click="viewing = null"></div>
                                <div role="dialog" aria-modal="true" aria-labelledby="profile-title-{{ $openId }}" x-trap.noscroll="viewing === {{ $openId }}" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                                    <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                                        <div class="min-w-0">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Applicant {{ $loop->iteration }}@if ($loop->count > 1) of {{ $loop->count }}@endif</p>
                                            <h3 id="profile-title-{{ $openId }}" class="mt-1 truncate text-lg font-semibold text-navy-900">{{ $row['name'] }}</h3>
                                            @if ($row['pages_label'] !== '')
                                                <p class="mt-1 text-sm text-slate-500">{{ $row['pages_label'] }}</p>
                                            @endif
                                        </div>
                                        <button type="button" x-on:click="viewing = null" class="rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">Close</button>
                                    </div>
                                    <div class="space-y-6 overflow-y-auto px-5 py-4">
                                        @foreach ($profile['sections'] as $section)
                                            @if ($section['filled'] !== [])
                                                <section>
                                                    <h4 class="text-sm font-semibold text-navy-900">{{ $section['title'] }}</h4>
                                                    <div class="mt-2 overflow-x-auto">
                                                        <table class="min-w-full text-left text-sm">
                                                            <thead class="border-b border-slate-200 text-slate-500">
                                                                <tr>
                                                                    <th scope="col" class="w-1/3 py-2 pr-4 font-medium">Field</th>
                                                                    <th scope="col" class="py-2 font-medium">Value</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100">
                                                                @foreach ($section['filled'] as $field)
                                                                    <tr class="align-top">
                                                                        <td class="py-2 pr-4 text-slate-500">{{ $field['label'] }}</td>
                                                                        <td class="py-2 font-medium whitespace-pre-wrap text-slate-900">{{ $field['value'] }}@if ($field['from_ic'] ?? false) <span class="ml-2 text-xs font-normal text-slate-500">From IC</span>@elseif (! empty($field['note'])) <span class="ml-2 text-xs font-normal text-slate-500">{{ $field['note'] }}</span>@endif @if (! empty($field['warning'])) <span class="ml-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-800" title="{{ $field['warning'] }}">!</span> <span class="mt-1 block text-xs font-normal text-amber-800">{{ $field['warning'] }}</span>@endif</td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </section>
                                            @endif
                                        @endforeach
                                        <section>
                                            <h4 class="text-sm font-semibold text-navy-900">Fields left blank</h4>
                                            <div class="mt-3 space-y-2">
                                                @foreach ($profile['sections'] as $section)
                                                    @if ($section['blank'] !== [])
                                                        <details class="rounded-md border border-slate-200 bg-slate-50">
                                                            <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-slate-700">{{ $section['title'] }} · {{ count($section['blank']) }}</summary>
                                                            <ul class="grid gap-2 border-t border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-3">
                                                                @foreach ($section['blank'] as $label)
                                                                    <li class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">{{ $label }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </details>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </section>
                                    </div>
                                    @if ($profile['review_profile_id'])
                                        <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
                                            <button type="button" x-on:click="viewing = null; matchWithLoader($wire, 'autoMatch', 'Finding customer', {{ $profile['review_profile_id'] }})" wire:loading.attr="disabled" wire:target="autoMatch" class="inline-flex h-8 items-center rounded-md border border-navy-800 bg-white px-3 text-sm font-medium text-navy-800 disabled:opacity-50">Auto-Match</button>
                                            <button type="button" x-on:click="viewing = null" wire:click="openMatch({{ $profile['review_profile_id'] }})" class="inline-flex h-8 items-center rounded-md bg-navy-800 px-3 text-sm font-medium text-white">Match</button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @elseif ($readProgress === null)
                    <p class="rounded-lg border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600">No listed field was filled.</p>
                @endif
                @if ($scan->profile_status === 'failed')
                    <div class="mt-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                        <p>{{ $scan->profile_error }}</p>
                        <button type="button" wire:click="retryProfileExtraction" wire:loading.attr="disabled" class="mt-3 rounded-md border border-red-300 px-3 py-2 font-medium hover:bg-red-100 disabled:opacity-50">Retry profile extraction</button>
                    </div>
                @endif
            </div>
        @endif

        @if (is_array($scan->ai_responses) && $scan->ai_responses !== [])
            <details class="group overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <summary class="cursor-pointer list-none px-5 py-4 group-open:border-b group-open:border-slate-200 [&::-webkit-details-marker]:hidden">
                    <h2 class="text-sm font-semibold text-navy-900">Raw AI response</h2>
                    <p class="mt-1 text-sm text-slate-600">The JSON the AI returned, before those values were sorted into profile fields.</p>
                </summary>
                <div class="space-y-4 px-5 py-4">
                    @foreach ($scan->ai_responses as $response)
                        @php
                            $start = $response['start'] ?? null;
                            $end = $response['end'] ?? null;
                            $label = is_int($start) && is_int($end) ? ($start === $end ? 'Page '.$start : 'Pages '.$start.'–'.$end) : 'Attached document';
                        @endphp
                        <div x-data="{ copied: false }">
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h3 class="text-sm font-medium text-slate-700">{{ $label }}</h3>
                                <button type="button" x-on:click="navigator.clipboard.writeText($refs.json.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1500) }).catch(() => window.showToast('error', 'The response could not be copied.'))" class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                            </div>
                            <pre x-ref="json" class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-words rounded-md bg-slate-950 p-4 text-xs leading-5 text-slate-100">{{ json_encode($response['body'] ?? $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        @if ($scan->status->value === 'completed')
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                <h2 class="text-sm font-semibold text-navy-900">Basic document understanding</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Document type</dt><dd class="mt-1 font-medium text-slate-900">{{ $scan->document_type ?? 'Unknown' }}</dd></div>
                    <div><dt class="text-slate-500">Title</dt><dd class="mt-1 font-medium text-slate-900">{{ $scan->document_title ?? 'Unknown' }}</dd></div>
                    <div><dt class="text-slate-500">Readability</dt><dd class="mt-1 font-medium text-slate-900">{{ $scan->readable ? 'Readable' : 'Not readable' }}</dd></div>
                </dl>
                <div><h3 class="text-sm font-medium text-slate-500">Summary</h3><p class="mt-1 whitespace-pre-wrap text-sm text-slate-800">{{ $scan->summary ?? 'No summary available.' }}</p></div>
                <div><h3 class="text-sm font-medium text-slate-500">Reading limitations</h3><p class="mt-1 whitespace-pre-wrap text-sm text-slate-800">{{ $scan->limitations ?? 'None reported.' }}</p></div>
            </section>
        @elseif ($scan->status->value === 'failed')
            <div class="rounded-lg border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800" role="alert">
                <p>{{ $scan->processing_error }}</p>
                <button type="button" wire:click="retryScan" wire:loading.attr="disabled" class="mt-3 rounded-md border border-red-300 px-3 py-2 font-medium hover:bg-red-100 disabled:opacity-50">Retry scan</button>
            </div>
        @else
            @php
                $progressDone = (int) ($readProgress['done'] ?? 0);
                $progressTotal = (int) ($readProgress['total'] ?? 0);
                $progressPercent = $progressTotal > 0 ? (int) round(min(100, $progressDone / $progressTotal * 100)) : 0;
            @endphp
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" role="status" aria-live="polite">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-navy-900">{{ $readProgress['label'] ?? 'Reading the document' }}</h2>
                    @if ($progressTotal > 0)
                        <p class="text-sm text-slate-500">{{ $progressDone }} of {{ $progressTotal }}</p>
                    @endif
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                    @if ($progressPercent > 0)
                        <div class="h-full rounded-full bg-navy-800" style="width: {{ $progressPercent }}%"></div>
                    @else
                        <div class="h-full w-1/3 animate-pulse rounded-full bg-navy-800"></div>
                    @endif
                </div>
                <p class="mt-3 text-sm text-slate-600">This page refreshes automatically.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="cancelScan" wire:loading.attr="disabled" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">Cancel scan</button>
                    <button type="button" wire:click="rescan" wire:loading.attr="disabled" class="rounded-md bg-navy-800 px-3 py-2 text-sm font-medium text-white hover:bg-navy-900 disabled:opacity-50">Rescan</button>
                </div>
            </section>
        @endif

        @if ($matchAll)
            @php
                $existingCount = collect($matchAllRows)->where('create', false)->count();
                $newCount = collect($matchAllRows)->where('create', true)->count();
            @endphp
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-900/40" wire:click="closeMatchAll"></div>
                <div role="dialog" aria-modal="true" aria-labelledby="match-all-title" x-data="{ filter: 'all' }" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h3 id="match-all-title" class="text-base font-semibold text-navy-900">Auto-Match all</h3>
                        <p class="mt-1 text-sm text-slate-600">Review who will be updated and who will be created, then choose a strategy.</p>
                        <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="Filter applicants">
                            <button type="button" x-on:click="filter = 'all'" :class="filter === 'all' ? 'border-navy-800 bg-navy-800 text-white' : 'border-slate-300 bg-white text-slate-700'" class="inline-flex h-8 items-center gap-2 rounded-full border px-3 text-sm font-medium">All <span class="rounded-full bg-white/20 px-1.5 text-xs" :class="filter === 'all' ? 'bg-white/20' : 'bg-slate-100'">{{ $existingCount + $newCount }}</span></button>
                            <button type="button" x-on:click="filter = 'existing'" :class="filter === 'existing' ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-emerald-200 bg-emerald-50 text-emerald-800'" class="inline-flex h-8 items-center gap-2 rounded-full border px-3 text-sm font-medium">Existing <span class="rounded-full px-1.5 text-xs" :class="filter === 'existing' ? 'bg-white/20' : 'bg-white'">{{ $existingCount }}</span></button>
                            <button type="button" x-on:click="filter = 'new'" :class="filter === 'new' ? 'border-amber-700 bg-amber-700 text-white' : 'border-amber-200 bg-amber-50 text-amber-900'" class="inline-flex h-8 items-center gap-2 rounded-full border px-3 text-sm font-medium">New <span class="rounded-full px-1.5 text-xs" :class="filter === 'new' ? 'bg-white/20' : 'bg-white'">{{ $newCount }}</span></button>
                        </div>
                    </div>
                    <div class="space-y-4 overflow-y-auto px-5 py-4">
                        <p x-show="filter === 'existing' && {{ $existingCount }} === 0" x-cloak class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">No applicant matches an existing customer.</p>
                        <p x-show="filter === 'new' && {{ $newCount }} === 0" x-cloak class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">No applicant needs a new customer.</p>
                        <ul class="space-y-2">
                            @foreach ($matchAllRows as $row)
                                <li x-show="filter === 'all' || filter === '{{ $row['create'] ? 'new' : 'existing' }}'" x-cloak class="grid gap-3 rounded-md border border-slate-200 px-3 py-3 sm:grid-cols-[1fr_auto] sm:items-center">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-navy-900">{{ $row['heading'] }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500">Identification {{ $row['number'] }}</p>
                                    </div>
                                    <div class="sm:max-w-xs sm:text-right">
                                        @if ($row['create'])
                                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">New customer</p>
                                            <p class="mt-0.5 text-sm text-slate-700">Not in the customer list. A customer will be created.</p>
                                        @else
                                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Existing customer</p>
                                            <p class="mt-0.5 text-sm font-medium text-slate-900">{{ $row['customer_name'] }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        @if ($matchAllSkipped !== [])
                            <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                                <h4 class="text-sm font-medium text-slate-700">Not included</h4>
                                <ul class="mt-1 space-y-1 text-sm text-slate-600">
                                    @foreach ($matchAllSkipped as $skipped)
                                        <li>{{ $skipped }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @error('merge') <p class="whitespace-pre-line text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div class="space-y-3 border-t border-slate-200 px-5 py-4">
                        <div>
                            <label for="match-all-strategy" class="text-sm text-slate-600">Strategy</label>
                            <select id="match-all-strategy" wire:model="matchStrategy" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                <option value="fill_empty">Fill empty only</option>
                                <option value="overwrite">Overwrite and fill empty</option>
                                <option value="replace_all">Replace all</option>
                            </select>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" wire:click="closeMatchAll" class="text-sm text-slate-600">Cancel</button>
                            <button type="button" x-on:click="matchWithLoader($wire, 'confirmMatchAll', 'Matching applicants')" wire:loading.attr="disabled" wire:target="confirmMatchAll" class="rounded-md bg-navy-800 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50">Match all</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($matchProfileId)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-900/40" wire:click="closeMatch"></div>
                <div role="dialog" aria-modal="true" aria-labelledby="match-title" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h3 id="match-title" class="text-base font-semibold text-navy-900">Match applicant</h3>
                    </div>
                    <div class="space-y-4 overflow-y-auto px-5 py-4">
                        @if ($matchRecommendation !== '')
                            <p class="text-sm text-slate-700">{{ $matchRecommendation }}</p>
                        @endif
                        <div>
                            <label for="match-customer" class="text-sm text-slate-600">Customer</label>
                            <input id="match-customer" wire:model.live.debounce.300ms="customerQuery" type="search" placeholder="Name, reference, or identification" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                            <ul class="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
                                @forelse ($matchCustomers as $customer)
                                    <li>
                                        <button type="button" wire:click="selectMatchCustomer({{ $customer->id }})" class="block w-full px-3 py-2 text-left text-sm {{ (string) $customer->id === $matchCustomerId ? 'bg-slate-100 font-medium text-navy-900' : 'text-slate-700' }}">{{ $customer->full_legal_name }} · {{ $customer->reference }}</button>
                                    </li>
                                @empty
                                    <li class="px-3 py-2 text-sm text-slate-500">No matching customer.</li>
                                @endforelse
                            </ul>
                        </div>
                        <div>
                            <label for="match-strategy" class="text-sm text-slate-600">Strategy</label>
                            <select id="match-strategy" wire:model="matchStrategy" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                <option value="fill_empty">Fill empty only</option>
                                <option value="overwrite">Overwrite and fill empty</option>
                                <option value="replace_all">Replace all</option>
                            </select>
                        </div>
                        @error('merge') <p class="whitespace-pre-line text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4">
                        <button type="button" wire:click="closeMatch" class="text-sm text-slate-600">Cancel</button>
                        <button type="button" x-on:click="matchWithLoader($wire, 'confirmMatch', 'Matching')" wire:loading.attr="disabled" wire:target="confirmMatch" class="rounded-md bg-navy-800 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50">{{ $recommendCreate && $matchCustomerId === '' ? 'Create and match' : 'Match' }}</button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
