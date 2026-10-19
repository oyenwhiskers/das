<div class="space-y-4">
    <section aria-label="Employment Status" class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-3 sm:px-5">
            <h2 class="text-base font-semibold text-navy-900">Employment Status</h2>
        </div>
        @if ($mode !== 'status')
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-5">
                <dl class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div>
                        <dt class="sr-only">Current status</dt>
                        <dd class="text-sm font-semibold text-slate-900">{{ match ($customer->employment_status) { 'employed' => 'Employed', 'self_employed' => 'Self-Employed', 'retired' => 'Retired', 'other' => $customer->employment_status_other ? 'Others — '.$customer->employment_status_other : 'Others', 'unemployed' => 'Unemployed (not on Form 3)', default => 'Not specified' } }}</dd>
                    </div>
                    @if ($customer->employment_status === 'retired' && $customer->retirement_date)
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Retirement date</dt>
                            <dd class="text-sm font-medium text-slate-900">{{ $customer->retirement_date->format('d/m/Y') }}</dd>
                        </div>
                    @endif
                </dl>
                <button type="button" wire:click="editStatus" class="shrink-0 text-sm font-medium text-navy-700 hover:text-navy-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">Change</button>
            </div>
        @else
            <div class="px-4 py-3 sm:px-5">
                <form wire:submit="saveStatus" class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div class="grid min-w-0 flex-1 gap-3 sm:grid-cols-2">
                        <div><x-input-label for="employment-status" value="Employment Status" class="sr-only" /><select id="employment-status" wire:model.live="employmentStatus" class="block w-full rounded-md border-slate-300 text-sm"><option value="">Not specified</option><option value="employed">Employed</option><option value="self_employed">Self-Employed</option><option value="retired">Retired</option><option value="other">Others (please specify)</option></select><x-input-error :messages="$errors->get('employmentStatus')" class="mt-1" /></div>
                        @if ($employmentStatus === 'other')<div><x-input-label for="employment-other" value="Others (please specify)" /><x-text-input id="employment-other" wire:model="employmentStatusOther" class="mt-1 block w-full" maxlength="255" required /><x-input-error :messages="$errors->get('employmentStatusOther')" class="mt-1" /></div>@endif
                        @if ($employmentStatus === 'retired')<div><x-input-label for="retirement-date" value="Retirement Date" /><x-text-input id="retirement-date" type="date" wire:model="retirementDate" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('retirementDate')" class="mt-1" /></div>@endif
                    </div>
                    <div class="flex shrink-0 justify-end gap-3"><button type="button" x-on:click="if (!dirty || await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' })) { dirty = false; $wire.cancel() }" class="text-sm text-slate-600">Cancel</button><x-primary-button wire:loading.attr="disabled" wire:target="saveStatus">Save Status</x-primary-button></div>
                </form>
            </div>
        @endif
    </section>

    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
            <div>
                <h2 class="text-base font-semibold text-navy-900">Employment History</h2>
            </div>
            @if ($mode !== 'status')
                <button type="button" wire:click="add" class="inline-flex items-center rounded-md border border-navy-700 bg-navy-700 px-3 py-2 text-sm font-medium text-white hover:bg-navy-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">+ Add Employment</button>
            @endif
        </div>
        <div class="space-y-4 px-4 py-3 sm:px-5">
            @if ($employments->isEmpty())
                <div class="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">
                    No employment records yet. Add a current or previous role.
                </div>
            @endif

            @foreach ([1 => 'Current employment', 0 => 'Previous employment'] as $isCurrent => $heading)
                @php $records = $employments->where('is_current', (bool) $isCurrent); @endphp
                @if ($records->isNotEmpty())
                    <div>
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $heading }} <span class="font-normal">({{ $records->count() }})</span></h3>
                        <ol class="ml-2 space-y-5 border-l-2 border-slate-200">
                            @foreach ($records as $employment)
                                <li wire:key="employment-{{ $employment->id }}" class="relative pl-6">
                                    <span class="absolute -left-[7px] top-1/2 h-3 w-3 -translate-y-1/2 rounded-full border-2 border-white {{ $employment->is_current ? 'bg-navy-700' : 'bg-slate-400' }}" aria-hidden="true"></span>
                                    <div class="rounded-lg border border-slate-200 bg-white p-4 sm:flex sm:items-center sm:justify-between sm:gap-4">
                                        <div class="min-w-0 space-y-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="font-semibold text-slate-900">{{ $employment->occupation ?: ($employment->arrangement ? ucwords(str_replace('_', ' ', $employment->arrangement)) : 'Role not provided') }}</p>
                                                @if ($employment->is_primary)<span class="rounded-full bg-navy-100 px-2 py-0.5 text-xs font-medium text-navy-800">Primary</span>@endif
                                            </div>
                                            <p class="text-sm text-slate-700">{{ $employment->employer_name ?: ($employment->arrangement === 'self_employed' ? 'Self-employed' : 'Employer not provided') }}</p>
                                            <p class="text-xs text-slate-500">
                                                @if ($employment->start_date)<time datetime="{{ $employment->start_date->toDateString() }}">{{ $employment->start_date->format('d M Y') }}</time>@else Start date unknown @endif
                                                <span aria-hidden="true">–</span>
                                                @if ($employment->end_date)<time datetime="{{ $employment->end_date->toDateString() }}">{{ $employment->end_date->format('d M Y') }}</time>@else{{ $employment->is_current ? 'Present' : 'End date unknown' }}@endif
                                                @if ($employment->industry)<span aria-hidden="true">·</span> {{ $employment->industry }}@endif
                                            </p>
                                        </div>
                                        @if ($mode !== 'status')
                                            <div class="mt-3 flex shrink-0 gap-2 sm:mt-0">
                                                <button type="button" wire:click="view({{ $employment->id }})" aria-label="View employment details" title="View details" class="inline-flex items-center justify-center rounded-md p-2 text-navy-700 hover:bg-slate-50 hover:text-navy-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6.75 9.75-6.75S21.75 12 21.75 12s-3.5 6.75-9.75 6.75S2.25 12 2.25 12Z" />
                                                        <circle cx="12" cy="12" r="3" />
                                                    </svg>
                                                </button>
                                                <button type="button" wire:click="edit({{ $employment->id }})" aria-label="Edit employment" title="Edit" class="inline-flex items-center justify-center rounded-md p-2 text-navy-700 hover:bg-slate-50 hover:text-navy-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">
                                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 0 1 2.652 2.652L9.832 16.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.805.805-2.685a4.5 4.5 0 0 1 1.13-1.897L16.862 4.487Zm0 0L19.5 7.125M18 13.5V19.5a2.25 2.25 0 0 1-2.25 2.25h-11.25A2.25 2.25 0 0 1 2.25 19.5v-11.25A2.25 2.25 0 0 1 4.5 6h6" />
                                                    </svg>
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            @endforeach
        </div>
    </section>

    @if ($mode === 'edit')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" x-data="{ confirmingDiscard: false, async closeForm() { if (dirty) { this.confirmingDiscard = true; const confirmed = await confirmAction({ title: 'Discard unsaved changes?', text: 'Your changes have not been saved.', confirmButtonText: 'Discard changes' }); this.confirmingDiscard = false; if (!confirmed) return; } dirty = false; $wire.cancel(); } }" x-on:keydown.escape.window="if (!confirmingDiscard) closeForm()">
            <div class="absolute inset-0 bg-slate-900/40" x-on:click="closeForm()" aria-hidden="true"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="employment-form-title" x-trap.noscroll="!confirmingDiscard" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-xl sm:max-h-[calc(100dvh-3rem)]">
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
                    <div><h2 id="employment-form-title" class="text-base font-semibold text-navy-900">{{ $selectedId ? 'Edit employment' : 'Add employment' }}</h2><p class="text-xs text-slate-500">Record the role, dates and optional business details.</p></div>
                    <button type="button" x-on:click="closeForm()" aria-label="Close employment form" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">✕</button>
                </div>
                <form wire:submit="save" class="flex min-h-0 flex-col">
                    <div class="min-h-0 space-y-6 overflow-y-auto px-4 py-5 sm:px-6">
                        @if ($selectedId)<p class="rounded-md bg-slate-50 p-3 text-xs text-slate-600">For a new employer, add another record and mark this one as previous. Edit this record to update or correct its details.</p>@endif
                        @foreach (['Employment details' => ['arrangement' => 'Employment Arrangement', 'occupation' => 'Occupation / Designation', 'employer_name' => 'Employer / Business Name', 'registration_number' => 'Business Registration Number', 'industry' => 'Industry', 'sub_industry' => 'Sub-Industry', 'start_date' => 'Start Date', 'end_date' => 'End Date'], 'Employer contact' => ['general_telephone' => 'General Telephone', 'direct_telephone' => 'Office Direct Telephone (DID)', 'fax' => 'Fax Number'], 'Office / business address' => ['address_line_1' => 'Address Line 1', 'address_line_2' => 'Address Line 2', 'city' => 'City', 'state' => 'State', 'postcode' => 'Postcode', 'country' => 'Country']] as $group => $fields)
                            <fieldset class="space-y-3">@if ($group !== 'Employment details')<legend class="text-sm font-semibold text-slate-800">{{ $group }}</legend>@endif<div class="grid gap-4 sm:grid-cols-2">
                                @foreach ($fields as $field => $label)
                                    @if ($field === 'end_date' && $form['is_current']) @continue @endif
                                    <div><x-input-label :for="'employment-'.$field" :value="$label" />
                                        @if ($field === 'arrangement')
                                            <select id="employment-arrangement" wire:model.live="form.arrangement" class="mt-1 block w-full rounded-md border-slate-300 text-sm"><option value="">Select arrangement</option><option value="employed">Employed</option><option value="self_employed">Self-Employed</option><option value="retired">Retired</option><option value="other">Others (please specify)</option></select>
                                            @if (($form['arrangement'] ?? '') === 'other')
                                                <div class="mt-2"><x-input-label for="employment-arrangement-other" value="Others (please specify)" /><x-text-input id="employment-arrangement-other" wire:model="form.arrangement_other" class="mt-1 block w-full" maxlength="255" /><x-input-error :messages="$errors->get('form.arrangement_other')" class="mt-1" /></div>
                                            @endif
                                        @else<x-text-input :id="'employment-'.$field" :type="in_array($field, ['start_date', 'end_date']) ? 'date' : 'text'" :wire:model="'form.'.$field" class="mt-1 block w-full" />@endif
                                        <x-input-error :messages="$errors->get('form.'.$field)" class="mt-1" />
                                    </div>
                                @endforeach
                            </div></fieldset>
                        @endforeach
                        <div class="space-y-2 text-sm text-slate-700"><label class="flex items-center gap-2"><input type="checkbox" wire:model.live="form.is_current" class="rounded border-slate-300 text-navy-700">Current employment</label>
                            @if ($form['is_current'])<label class="flex items-center gap-2"><input type="checkbox" wire:model.live="form.is_primary" class="rounded border-slate-300 text-navy-700">Primary current employment</label>@endif
                            @if ($form['is_primary'] && $currentPrimary && $currentPrimary->id !== $selectedId)<label class="flex items-center gap-2 rounded-md bg-amber-50 p-3 text-amber-900"><input type="checkbox" wire:model="confirmPrimaryReplacement" class="rounded border-amber-400 text-navy-700">Confirm replacing the current primary designation. The other record will remain available.</label>@endif
                            <x-input-error :messages="$errors->get('form.is_primary')" />
                            @if ($selectedId)<label class="flex items-center gap-2"><input type="checkbox" wire:model="isCorrection" class="rounded border-slate-300 text-navy-700">This edit corrects inaccurate information</label>@endif
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:px-6"><button type="button" x-on:click="closeForm()" class="text-sm text-slate-600">Cancel</button><x-primary-button wire:loading.attr="disabled" wire:target="save">Save Employment</x-primary-button></div>
                </form>
            </div>
        </div>
    @elseif ($mode === 'view' && $selected)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" x-on:keydown.escape.window="$wire.cancel()">
            <div class="absolute inset-0 bg-slate-900/40" x-on:click="$wire.cancel()" aria-hidden="true"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="employment-details-title" x-trap.noscroll="true" class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl sm:max-h-[calc(100dvh-3rem)]">
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6"><div><h2 id="employment-details-title" class="text-base font-semibold text-navy-900">{{ $selected->occupation ?: 'Employment details' }}</h2><p class="text-sm text-slate-600">{{ $selected->employer_name ?: ($selected->arrangement === 'self_employed' ? 'Self-employed' : 'Employer not provided') }}</p></div><button type="button" wire:click="cancel" aria-label="Close employment details" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">✕</button></div>
                <div class="min-h-0 overflow-y-auto px-4 py-5 text-sm sm:px-6"><dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">@foreach (['arrangement' => 'Arrangement', 'occupation' => 'Occupation', 'employer_name' => 'Employer / Business', 'registration_number' => 'Registration Number', 'industry' => 'Industry', 'sub_industry' => 'Sub-Industry', 'general_telephone' => 'General Telephone', 'direct_telephone' => 'Office Direct Telephone', 'fax' => 'Fax', 'address_line_1' => 'Address Line 1', 'address_line_2' => 'Address Line 2', 'city' => 'City', 'state' => 'State', 'postcode' => 'Postcode', 'country' => 'Country'] as $field => $label)<div><dt class="text-slate-500">{{ $label }}</dt><dd class="font-medium text-slate-900">{{ $field === 'arrangement' && $selected->arrangement ? ucwords(str_replace('_', ' ', $selected->arrangement)) : ($selected->{$field} ?: 'Not provided') }}</dd></div>@endforeach<div><dt class="text-slate-500">Start Date</dt><dd class="font-medium text-slate-900">{{ $selected->start_date?->format('d/m/Y') ?: 'Not provided' }}</dd></div><div><dt class="text-slate-500">End Date</dt><dd class="font-medium text-slate-900">{{ $selected->end_date?->format('d/m/Y') ?: ($selected->is_current ? 'Present' : 'Not provided') }}</dd></div></dl></div>
                <div class="grid grid-cols-2 gap-3 border-t border-slate-200 px-4 py-3 text-sm sm:px-6">
                    <button type="button" wire:click="cancel" class="w-full rounded-md border border-slate-300 px-3 py-2 text-center font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">Close</button>
                    <button type="button" wire:click="edit({{ $selected->id }})" class="w-full rounded-md border border-navy-700 bg-navy-700 px-3 py-2 text-center font-medium text-white hover:bg-navy-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500">Edit employment</button>
                </div>
            </div>
        </div>
    @endif
</div>
