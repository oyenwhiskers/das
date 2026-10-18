@props(['value' => null, 'optional' => false, 'error' => null, 'mark' => false])
@if ($mark)
    <svg {{ $attributes->merge(['class' => 'inline h-4 w-4 shrink-0 text-amber-600', 'title' => 'Still needs to be filled']) }} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
    </svg>
@elseif (filled($value) && filled($error))
    <span class="inline-flex flex-wrap items-center gap-1.5">
        <span>{{ $value }}</span>
        <span class="inline-flex items-center gap-1 font-medium text-amber-700" title="{{ $error }}">
            <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
            </svg>
            <span>{{ $error }}</span>
        </span>
    </span>
@elseif (filled($value))
    {{ $value }}
@elseif ($optional)
    <span class="text-slate-500">Not provided</span>
@else
    <span class="font-medium text-amber-700">Not provided</span>
@endif
