@php
    $hint = $hint ?? 'Unchecked sections are left unchanged on the customer.';
    $groupDefs = $this->matchSectionGroups();
    $groupFor = function (string $module) use ($groupDefs): ?array {
        foreach ($groupDefs as $def) {
            if (isset($def['prefix']) && str_starts_with($module, $def['prefix'])) {
                return $def;
            }
            if (isset($def['modules']) && in_array($module, $def['modules'], true)) {
                return $def;
            }
        }

        return null;
    };
    $indexesFor = function (array $def) use ($matchSections): array {
        return collect($matchSections)
            ->filter(function (array $section) use ($def): bool {
                $module = $section['module'];
                if (isset($def['prefix']) && str_starts_with($module, $def['prefix'])) {
                    return true;
                }

                return isset($def['modules']) && in_array($module, $def['modules'], true);
            })
            ->keys()
            ->all();
    };
    $renderedGroups = [];
@endphp
<fieldset>
    <legend class="text-sm text-slate-600">Sections to match</legend>
    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    <ul class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 overflow-visible sm:grid-cols-3">
        @foreach ($matchSections as $index => $section)
            @php $group = $groupFor($section['module']); @endphp
            @if ($group !== null)
                @if (isset($renderedGroups[$group['key']]))
                    @continue
                @endif
                @php
                    $renderedGroups[$group['key']] = true;
                    $groupIndexes = $indexesFor($group);
                    $includedCount = collect($groupIndexes)
                        ->filter(fn (int|string $i): bool => (bool) $matchSections[$i]['include'])
                        ->count();
                    $allIncluded = $groupIndexes !== [] && $includedCount === count($groupIndexes);
                    $partial = $includedCount > 0 && ! $allIncluded;
                @endphp
                <li class="relative overflow-visible" x-data="{ open: false }" @click.outside="open = false">
                    <div class="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            wire:key="match-group-{{ $group['key'] }}-{{ $includedCount }}-{{ count($groupIndexes) }}"
                            wire:click.prevent="toggleMatchSectionGroup(@js($group['key']))"
                            @checked($allIncluded)
                            @if ($partial) x-data x-init="$el.indeterminate = true" @endif
                            class="rounded border-slate-300 text-navy-800 focus:ring-navy-800"
                        />
                        <button type="button" @click="open = ! open" class="inline-flex items-center gap-1 text-left hover:text-navy-900" :aria-expanded="open.toString()">
                            <span>{{ $group['label'] }}</span>
                            <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                    <div
                        x-show="open"
                        x-cloak
                        x-transition
                        class="absolute bottom-full left-0 z-[60] mb-2 w-52 rounded-md bg-white py-2 shadow-lg ring-1 ring-black/5"
                        role="menu"
                        aria-label="{{ $group['label'] }} sections"
                    >
                        @foreach ($groupIndexes as $groupIndex)
                            <label class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                                <input type="checkbox" wire:model.live="matchSections.{{ $groupIndex }}.include" class="rounded border-slate-300 text-navy-800 focus:ring-navy-800" />
                                <span>{{ $matchSections[$groupIndex]['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </li>
            @else
                <li>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="matchSections.{{ $index }}.include" class="rounded border-slate-300 text-navy-800 focus:ring-navy-800" />
                        <span>{{ $section['label'] }}</span>
                    </label>
                </li>
            @endif
        @endforeach
    </ul>
</fieldset>
