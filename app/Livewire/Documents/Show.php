<?php

namespace App\Livewire\Documents;

use App\Enums\CustomerStatus;
use App\Enums\DocumentScanStatus;
use App\Jobs\ProcessDocumentExtraction;
use App\Jobs\ProcessDocumentScan;
use App\Jobs\ProcessProfileExtraction;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Models\CustomerProfileSchemaSnapshot;
use App\Models\DocumentScan;
use App\Models\ExtractedProfile;
use App\Services\BankStatementProfileRules;
use App\Services\CustomerCreator;
use App\Services\CustomerProfileSchema;
use App\Services\MergeExtractedProfile;
use App\Services\NricParser;
use App\Services\ProfileMergePreview;
use App\Services\ProfileProjection;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Document — DAS')]
class Show extends Component
{
    use AuthorizesRequests;

    public DocumentScan $documentScan;

    public ?int $matchProfileId = null;

    public string $customerQuery = '';

    public string $matchCustomerId = '';

    public string $matchStrategy = 'fill_empty';

    public string $matchRecommendation = '';

    public bool $recommendCreate = false;

    public bool $matchAll = false;

    /** @var list<array{profile_id: int, heading: string, customer_id: int, customer_name: string, number: string}> */
    public array $matchAllRows = [];

    /** @var list<string> */
    public array $matchAllSkipped = [];

    public function mount(DocumentScan $documentScan): void
    {
        $this->authorize('view', $documentScan);
        $this->documentScan = $documentScan;
    }

    public function cancelScan(): void
    {
        $this->authorize('view', $this->documentScan);
        $this->stopScan($this->documentScan->fresh());
    }

    public function rescan(): void
    {
        $this->authorize('view', $this->documentScan);
        $scan = $this->documentScan->fresh();
        if (in_array($scan->status, [DocumentScanStatus::Pending, DocumentScanStatus::Processing], true)) {
            $this->stopScan($scan);
        }
        $this->retryScan();
    }

    public function retryScan(): void
    {
        $this->authorize('view', $this->documentScan);

        $scan = $this->documentScan->fresh();

        if ($scan->status !== DocumentScanStatus::Failed || ! Storage::disk('local')->exists($scan->storage_path)) {
            return;
        }

        $updated = DocumentScan::query()->whereKey($scan->id)
            ->where('status', DocumentScanStatus::Failed->value)
            ->update([
                'status' => DocumentScanStatus::Pending->value,
                'processing_error' => null,
                'processing_started_at' => null,
                'processing_completed_at' => null,
            ]);

        if ($updated !== 1) {
            return;
        }

        Storage::disk('local')->delete('document-scans/'.$scan->id.'/read-progress.json');

        try {
            ProcessDocumentScan::dispatch($scan->id);
        } catch (Throwable $exception) {
            DocumentScan::query()->whereKey($scan->id)
                ->where('status', DocumentScanStatus::Pending->value)
                ->update([
                    'status' => DocumentScanStatus::Failed->value,
                    'processing_error' => 'Processing could not be queued. Please contact an administrator.',
                    'processing_completed_at' => now(),
                ]);

            report($exception);
        }
    }

    private function stopScan(DocumentScan $scan): void
    {
        if (! in_array($scan->status, [DocumentScanStatus::Pending, DocumentScanStatus::Processing], true)) {
            return;
        }
        $updated = DocumentScan::query()->whereKey($scan->id)
            ->whereIn('status', [DocumentScanStatus::Pending->value, DocumentScanStatus::Processing->value])
            ->update([
                'status' => DocumentScanStatus::Failed->value,
                'processing_error' => 'Scan cancelled.',
                'processing_completed_at' => now(),
            ]);
        if ($updated !== 1) {
            return;
        }
        Cache::put(DocumentScan::activeRunKey($scan->id), (string) Str::uuid(), now()->addHours(2));
        app(UniqueLock::class)->release(new ProcessDocumentScan($scan->id));
        Storage::disk('local')->delete('document-scans/'.$scan->id.'/read-progress.json');
    }

    public function retryExtraction(): void
    {
        $this->authorize('view', $this->documentScan);

        $scan = $this->documentScan->fresh();

        if ($scan->status !== DocumentScanStatus::Completed
            || ! $scan->readable
            || $scan->extraction_status !== 'failed'
            || $scan->findings()->exists()
            || ! Storage::disk('local')->exists($scan->storage_path)) {
            return;
        }

        $updated = DocumentScan::query()->whereKey($scan->id)
            ->where('status', DocumentScanStatus::Completed->value)
            ->where('extraction_status', 'failed')
            ->whereDoesntHave('findings')
            ->update([
                'extraction_status' => 'not_started',
                'extraction_error' => null,
            ]);

        if ($updated !== 1) {
            return;
        }

        try {
            ProcessDocumentExtraction::dispatch($scan->id);
        } catch (Throwable $exception) {
            DocumentScan::query()->whereKey($scan->id)
                ->where('extraction_status', 'not_started')
                ->update([
                    'extraction_status' => 'failed',
                    'extraction_error' => 'Detailed extraction could not be queued. Please contact an administrator.',
                ]);

            report($exception);
        }
    }

    public function retryProfileExtraction(): void
    {
        $this->authorize('view', $this->documentScan);
        $scan = $this->documentScan->fresh();
        if ($scan->profile_status !== 'failed' || $scan->profile_schema_hash === null
            || ! Storage::disk('local')->exists($scan->storage_path)) {
            return;
        }
        $updated = DocumentScan::query()->whereKey($scan->id)->where('profile_status', 'failed')
            ->update(['profile_status' => 'not_started', 'profile_error' => null]);
        if ($updated !== 1) {
            return;
        }
        try {
            ProcessProfileExtraction::dispatch($scan->id);
        } catch (Throwable $exception) {
            $scan->update(['profile_status' => 'failed', 'profile_error' => 'Profile extraction could not be queued.']);
            report($exception);
        }
    }

    public function openMatch(int $profileId): void
    {
        $this->authorize('view', $this->documentScan);
        if (! $this->documentScan->extractedProfiles()->whereKey($profileId)->exists()) {
            throw ValidationException::withMessages(['match' => 'This applicant is not stored on this document, so it cannot be matched.']);
        }
        $this->matchProfileId = $profileId;
        $this->customerQuery = '';
        $this->matchCustomerId = '';
        $this->matchStrategy = 'fill_empty';
        $this->matchRecommendation = '';
        $this->recommendCreate = false;
        $this->resetValidation();
    }

    public function autoMatch(int $profileId): void
    {
        $this->openMatch($profileId);
        $number = CustomerIdentification::normalizeNumber($this->applicantIdentification($profileId));
        if ($number !== null) {
            $customers = $this->customersWithIdentification($number);
            if ($customers->count() > 1) {
                $this->closeMatch();
                throw ValidationException::withMessages(['match' => 'More than one customer has identification number '.$number.'. Choose the customer with Match.']);
            }
            if ($customers->isEmpty()) {
                $this->authorize('create', Customer::class);
                $name = $this->applicantName($profileId);
                if ($name === null) {
                    $this->closeMatch();
                    throw ValidationException::withMessages(['match' => 'No customer has identification number '.$number.', and this applicant has no name to create.']);
                }
                $this->recommendCreate = true;
                $this->matchRecommendation = 'No customer has identification number '.$number.'. Create '.$name.' and match this applicant.';

                return;
            }
            $customer = $customers->first();
            $this->authorize('update', $customer);
            $this->matchCustomerId = (string) $customer->id;
            $this->customerQuery = $customer->reference;
            $this->matchRecommendation = 'Recommended from identification number '.$number.'. Choose a strategy, then match.';

            return;
        }
        $name = $this->applicantName($profileId);
        if ($name === null) {
            $this->closeMatch();
            throw ValidationException::withMessages(['match' => 'This applicant has no identification number or name to match.']);
        }
        $customers = $this->customersWithName($name);
        if ($customers->count() > 1) {
            $this->customerQuery = $name;
            $this->matchCustomerId = '';
            $this->matchRecommendation = 'More than one customer is named '.$name.'. Select which one to match.';

            return;
        }
        if ($customers->isEmpty()) {
            $this->authorize('create', Customer::class);
            $this->recommendCreate = true;
            $this->matchRecommendation = 'No customer matches the name '.$name.'. Create '.$name.' and match this applicant.';

            return;
        }
        $customer = $customers->first();
        $this->authorize('update', $customer);
        $this->matchCustomerId = (string) $customer->id;
        $this->customerQuery = $customer->reference;
        $this->matchRecommendation = 'Recommended from name '.$name.'. Choose a strategy, then match.';
    }

    public function autoMatchAll(): void
    {
        $this->authorize('view', $this->documentScan);
        $this->closeMatch();
        $rows = [];
        $skipped = [];
        foreach ($this->fieldFill($this->documentScan)['profiles'] ?? [] as $card) {
            $profileId = $card['review_profile_id'] ?? null;
            if (! is_int($profileId)) {
                continue;
            }
            $heading = is_string($card['heading'] ?? null) && $card['heading'] !== '' ? $card['heading'] : 'Unnamed applicant';
            $number = CustomerIdentification::normalizeNumber($this->applicantIdentification($profileId));
            if ($number !== null) {
                $customers = $this->customersWithIdentification($number);
                if ($customers->count() > 1) {
                    $skipped[] = $heading.': more than one customer has identification number '.$number.'.';

                    continue;
                }
                if ($customers->isEmpty()) {
                    $name = $this->applicantName($profileId);
                    if ($name === null) {
                        $skipped[] = $heading.': no customer has identification number '.$number.', and this applicant has no name to create.';

                        continue;
                    }
                    $this->authorize('create', Customer::class);
                    $rows[] = [
                        'profile_id' => $profileId,
                        'heading' => $heading !== 'Unnamed applicant' ? $heading : $name,
                        'customer_id' => 0,
                        'customer_name' => 'Create '.$name,
                        'number' => $number,
                        'create' => true,
                    ];

                    continue;
                }
                $customer = $customers->first();
                $this->authorize('update', $customer);
                $rows[] = [
                    'profile_id' => $profileId,
                    'heading' => $heading,
                    'customer_id' => $customer->id,
                    'customer_name' => (string) $customer->full_legal_name,
                    'number' => $number,
                    'create' => false,
                ];

                continue;
            }
            $name = $this->applicantName($profileId);
            if ($name === null) {
                $skipped[] = $heading.': no identification number or name.';

                continue;
            }
            $customers = $this->customersWithName($name);
            if ($customers->count() > 1) {
                $skipped[] = $heading.': more than one customer is named '.$name.'. Use Auto-Match to choose which one.';

                continue;
            }
            if ($customers->isEmpty()) {
                $this->authorize('create', Customer::class);
                $rows[] = [
                    'profile_id' => $profileId,
                    'heading' => $heading !== 'Unnamed applicant' ? $heading : $name,
                    'customer_id' => 0,
                    'customer_name' => 'Create '.$name,
                    'number' => '',
                    'create' => true,
                ];

                continue;
            }
            $customer = $customers->first();
            $this->authorize('update', $customer);
            $rows[] = [
                'profile_id' => $profileId,
                'heading' => $heading,
                'customer_id' => $customer->id,
                'customer_name' => (string) $customer->full_legal_name,
                'number' => '',
                'create' => false,
            ];
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['match' => $skipped === [] ? 'No applicant can be matched.' : implode("\n", $skipped)]);
        }
        $this->matchAllRows = $rows;
        $this->matchAllSkipped = $skipped;
        $this->matchStrategy = 'fill_empty';
        $this->matchAll = true;
        $this->resetValidation();
    }

    public function closeMatchAll(): void
    {
        $this->matchAll = false;
        $this->matchAllRows = [];
        $this->matchAllSkipped = [];
        $this->resetValidation();
    }

    public function confirmMatchAll(ProfileMergePreview $preview, MergeExtractedProfile $merge): void
    {
        $this->authorize('view', $this->documentScan);
        if ($this->matchAllRows === []) {
            throw ValidationException::withMessages(['merge' => 'No applicant can be matched.']);
        }
        if (! in_array($this->matchStrategy, ['fill_empty', 'overwrite', 'replace_all'], true)) {
            throw ValidationException::withMessages(['merge' => 'Choose a merge strategy.']);
        }
        $count = count($this->matchAllRows);
        DB::transaction(function () use ($preview, $merge): void {
            foreach ($this->matchAllRows as $row) {
                $profile = $this->documentScan->extractedProfiles()->find($row['profile_id']);
                if (! $profile instanceof ExtractedProfile) {
                    throw ValidationException::withMessages(['merge' => 'A recommended applicant could not be found.']);
                }
                if ($row['create']) {
                    $this->authorize('create', Customer::class);
                    try {
                        $customer = app(CustomerCreator::class)->create($this->creationData($profile), auth()->user());
                    } catch (\InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['merge' => $row['heading'].': '.$exception->getMessage()]);
                    }
                } else {
                    $customer = Customer::query()->find($row['customer_id']);
                }
                if (! $customer instanceof Customer) {
                    throw ValidationException::withMessages(['merge' => 'A recommended customer could not be found.']);
                }
                $this->authorize('update', $customer);
                $this->copyCardOnto($profile);
                $built = $preview->make($profile->fresh(), $customer, $this->matchStrategy, [], []);
                if (! $built['ready']) {
                    throw ValidationException::withMessages(['merge' => $row['heading'].': '.$this->matchRefusal($built)]);
                }
                try {
                    $merge->commit($profile->fresh(), $customer, auth()->user(), $this->matchStrategy, (string) Str::uuid(), $built['baseline'], [], []);
                } catch (ValidationException $exception) {
                    $messages = collect($exception->errors())->flatten()->unique()->implode("\n");
                    throw ValidationException::withMessages(['merge' => $row['heading'].': '.($messages !== '' ? $messages : 'This applicant cannot be matched.')]);
                } catch (ModelNotFoundException) {
                    throw ValidationException::withMessages(['merge' => $row['heading'].': a record needed for this match could not be found.']);
                }
            }
        });
        $this->closeMatchAll();
        $this->dispatch('matched', message: $count === 1 ? 'Matched 1 applicant.' : 'Matched '.$count.' applicants.');
    }

    public function closeMatch(): void
    {
        $this->matchProfileId = null;
        $this->matchCustomerId = '';
        $this->matchRecommendation = '';
        $this->recommendCreate = false;
        $this->resetValidation();
    }

    public function selectMatchCustomer(int $customerId): void
    {
        $customer = Customer::query()->find($customerId);
        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages(['merge' => 'That customer could not be found.']);
        }
        $this->authorize('update', $customer);
        $this->matchCustomerId = (string) $customer->id;
    }

    public function confirmMatch(ProfileMergePreview $preview, MergeExtractedProfile $merge): void
    {
        $this->authorize('view', $this->documentScan);
        $profile = $this->documentScan->extractedProfiles()->find($this->matchProfileId);
        if ($this->matchCustomerId === '' && $this->recommendCreate) {
            if (! $profile instanceof ExtractedProfile) {
                throw ValidationException::withMessages(['merge' => 'This applicant is not stored on this document, so it cannot be matched.']);
            }
            $this->authorize('create', Customer::class);
            try {
                $created = app(CustomerCreator::class)->create($this->creationData($profile), auth()->user());
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['merge' => $exception->getMessage()]);
            }
            $this->matchCustomerId = (string) $created->id;
        }
        $customer = Customer::query()->find($this->matchCustomerId);
        if (! $profile instanceof ExtractedProfile || ! $customer instanceof Customer) {
            throw ValidationException::withMessages(['merge' => $customer instanceof Customer ? 'This applicant is not stored on this document, so it cannot be matched.' : 'Choose a customer before matching.']);
        }
        $this->authorize('update', $customer);
        if (! in_array($this->matchStrategy, ['fill_empty', 'overwrite', 'replace_all'], true)) {
            throw ValidationException::withMessages(['merge' => 'Choose a merge strategy.']);
        }
        $this->copyCardOnto($profile);
        $built = $preview->make($profile->fresh(), $customer, $this->matchStrategy, [], []);
        if (! $built['ready']) {
            throw ValidationException::withMessages(['merge' => $this->matchRefusal($built)]);
        }
        try {
            $merge->commit($profile->fresh(), $customer, auth()->user(), $this->matchStrategy, (string) Str::uuid(), $built['baseline'], [], []);
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->unique()->implode("\n");
            throw ValidationException::withMessages(['merge' => $messages !== '' ? $messages : 'This applicant cannot be matched to that customer.']);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['merge' => 'A record needed for this match could not be found.']);
        }
        $name = trim((string) $customer->fresh()->full_legal_name);
        $this->closeMatch();
        $this->dispatch('matched', message: $name !== '' ? 'Matched to '.$name.'.' : 'The applicant was matched.');
    }

    private function applicantIdentification(int $profileId): ?string
    {
        $card = collect($this->fieldFill($this->documentScan)['profiles'] ?? [])->firstWhere('review_profile_id', $profileId);
        foreach ($card['sections'] ?? [] as $section) {
            foreach ($section['filled'] as $field) {
                if (($field['field_id'] ?? '') === 'primary_identification.number' && is_string($field['raw'] ?? null) && trim($field['raw']) !== '') {
                    return $field['raw'];
                }
            }
        }

        return null;
    }

    private function applicantName(int $profileId): ?string
    {
        $name = trim((string) ($this->cardRaw($profileId, 'customer.full_legal_name') ?? ''));
        if ($name === '') {
            $card = collect($this->fieldFill($this->documentScan)['profiles'] ?? [])->firstWhere('review_profile_id', $profileId);
            $name = trim((string) ($card['heading'] ?? ''));
        }

        return $name === '' ? null : mb_strtoupper($name, 'UTF-8');
    }

    /** @return array{full_legal_name: string, identification_type: ?string, identification_number: ?string} */
    private function creationData(ExtractedProfile $profile): array
    {
        $name = $this->applicantName($profile->id);
        if ($name === null) {
            throw ValidationException::withMessages(['merge' => 'This applicant has no name, so a customer cannot be created.']);
        }
        $number = $this->applicantIdentification($profile->id);
        $type = strtolower(trim((string) ($this->cardRaw($profile->id, 'primary_identification.type') ?? '')));
        if ($type === '' && app(NricParser::class)->parse($number) !== null) {
            $type = 'nric';
        }
        if (! $this->usableIdentification($type, $number)) {
            $type = '';
        }

        return [
            'full_legal_name' => $name,
            'identification_type' => $type !== '' ? $type : null,
            'identification_number' => $number,
        ];
    }

    private function cardRaw(int $profileId, string $fieldId): ?string
    {
        $card = collect($this->fieldFill($this->documentScan)['profiles'] ?? [])->firstWhere('review_profile_id', $profileId);
        foreach ($card['sections'] ?? [] as $section) {
            foreach ($section['filled'] as $field) {
                if (($field['field_id'] ?? '') === $fieldId && is_string($field['raw'] ?? null) && trim($field['raw']) !== '') {
                    return $field['raw'];
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $card */
    private function cardField(array $card, string $fieldId): ?string
    {
        foreach ($card['sections'] ?? [] as $section) {
            foreach ($section['filled'] as $field) {
                if (($field['field_id'] ?? '') === $fieldId && is_string($field['raw'] ?? null) && trim($field['raw']) !== '') {
                    return $field['raw'];
                }
            }
        }

        return null;
    }

    private function usableIdentification(?string $type, ?string $number): bool
    {
        $type = strtolower(trim((string) $type));
        $number = trim((string) $number);
        if ($type === '' || $number === '') {
            return false;
        }
        if ($type === 'nric') {
            return app(NricParser::class)->parse($number) !== null;
        }

        return true;
    }

    /** @param  list<array{field_id: string, value: string}>  $filled */
    private function identificationWarning(array $filled): string
    {
        $type = '';
        $number = '';
        foreach ($filled as $row) {
            if ($row['field_id'] === 'primary_identification.type') {
                $type = (string) $row['value'];
            }
            if ($row['field_id'] === 'primary_identification.number') {
                $number = (string) $row['value'];
            }
        }
        if (($type === '' && $number === '') || $this->usableIdentification($type, $number)) {
            return '';
        }

        return 'This identification was saved without a type. Correct the type and number on the customer.';
    }

    /** @return Collection<int, Customer> */
    private function customersWithIdentification(string $number)
    {
        return Customer::query()
            ->where('status', CustomerStatus::Active)
            ->where(function ($query) use ($number): void {
                $query->whereHas('identifications', fn ($identifications) => $identifications->where('normalized_number', $number))
                    ->orWhereRaw("REPLACE(REPLACE(UPPER(primary_identification_number), '-', ''), ' ', '') = ?", [$number]);
            })
            ->orderBy('id')
            ->limit(2)
            ->get();
    }

    /** @return Collection<int, Customer> */
    private function customersWithName(string $name)
    {
        $normalized = $this->normalizeMatchName($name);
        $query = Customer::query()->where('status', CustomerStatus::Active);
        foreach (preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (mb_strlen($part) < 2) {
                continue;
            }
            $query->where('full_legal_name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $part).'%');
        }

        return $query->orderBy('id')->limit(25)->get()
            ->filter(fn (Customer $customer): bool => $this->normalizeMatchName((string) $customer->full_legal_name) === $normalized)
            ->take(2)
            ->values();
    }

    private function normalizeMatchName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    /** @param  array<string, mixed>  $built */
    private function matchRefusal(array $built): string
    {
        return is_string($built['identity'] ?? null) && $built['identity'] !== ''
            ? $built['identity']
            : 'This applicant cannot be matched to that customer.';
    }

    private function copyCardOnto(ExtractedProfile $profile): void
    {
        $scan = $this->documentScan->fresh()->load(['uploader', 'findings', 'extractedProfiles']);
        $card = collect($this->fieldFill($scan)['profiles'] ?? [])->firstWhere('review_profile_id', $profile->id);
        if (! is_array($card)) {
            return;
        }
        $usableIdentification = $this->usableIdentification(
            $this->cardField($card, 'primary_identification.type'),
            $this->cardField($card, 'primary_identification.number'),
        );
        $values = [];
        foreach ($card['sections'] as $section) {
            foreach ($section['filled'] as $field) {
                if (! is_string($field['field_id'] ?? null) || ($field['raw'] ?? '') === '') {
                    continue;
                }
                if (! $usableIdentification && in_array($field['field_id'], ['primary_identification.type', 'primary_identification.number', 'primary_identification.issuing_jurisdiction'], true)) {
                    continue;
                }
                $value = $field['raw'];
                if (preg_match('/(?:same_as_residential|is_next_of_kin|is_primary_next_of_kin|is_active|is_primary|is_current)$/', $field['field_id']) === 1) {
                    $value = in_array(strtolower((string) $value), ['yes', 'true', '1'], true);
                } elseif (is_string($value) && ! $this->preserveMatchCase($field['field_id'], $value)) {
                    $value = mb_strtoupper($value, 'UTF-8');
                }
                if (in_array($field['field_id'], ['investment.objectives', 'investment.products'], true)) {
                    $value = [$value];
                }
                $values[] = [
                    'field_id' => $field['field_id'],
                    'entity_ref' => 'person-1',
                    'row_ref' => str_contains($field['field_id'], '[]') ? '1' : null,
                    'state' => 'provided',
                    'value' => $value,
                    'evidence_ids' => [],
                ];
            }
        }
        $projection = $profile->reviewed_projection;
        $projection['values'] = $values;
        $profile->reviewed_projection = $projection;
        $profile->save();
    }

    private function preserveMatchCase(string $fieldId, string $value): bool
    {
        if (str_contains(strtolower($fieldId), 'email') || preg_match('/date|_on$/', $fieldId) === 1) {
            return true;
        }
        foreach (['identification_type', 'occupancy_status', 'relationship', 'employment_status', 'arrangement', 'bank_account_type', 'bank_ownership', 'missing_tin_reason', 'yes_no', 'fatca_classification', 'personal_income', 'joint_income', 'net_assets', 'portfolio', 'portfolio_ownership', 'wealth_sources', 'objectives', 'risk_tolerance', 'trading_knowledge', 'products', 'brokerage_answer', 'brokerage_account_type', 'position_meaning', 'position_status'] as $set) {
            if (in_array($value, CustomerProfileSchema::options($set), true)) {
                return true;
            }
        }

        return false;
    }

    public function render(): View
    {
        $this->authorize('view', $this->documentScan);

        $scan = $this->documentScan->fresh()->load(['uploader', 'findings', 'extractedProfiles']);
        $extractionGroups = [];
        if (in_array($scan->extraction_status, ['processing', 'failed', 'completed'], true) && $scan->mime_type === 'application/pdf') {
            $disk = Storage::disk('local');
            // shortcut: old page-group files can briefly appear after a batch-size change; use a manifest if batch sizing changes during retries.
            $batches = array_filter(
                $disk->files('document-scans/'.$scan->id.'/extraction-batches'),
                fn (string $path): bool => str_ends_with($path, '.pdf') && ctype_digit(pathinfo($path, PATHINFO_FILENAME))
            );
            if (count($batches) > 1) {
                usort($batches, fn (string $a, string $b): int => (int) pathinfo($a, PATHINFO_FILENAME) <=> (int) pathinfo($b, PATHINFO_FILENAME));
                foreach ($batches as $path) {
                    $base = substr($path, 0, -4);
                    $running = $base.'.running';
                    // shortcut: a marker older than the job timeout is treated as waiting; read queue reservations if jobs can run longer.
                    $extractionGroups[] = [
                        'start' => (int) pathinfo($path, PATHINFO_FILENAME),
                        'status' => $disk->exists($base.'.json') ? 'Complete'
                            : ($scan->extraction_status === 'failed' && $disk->exists($base.'.failed') ? 'Failed'
                                : ($disk->exists($running) && $disk->lastModified($running) >= time() - 180 ? 'Extracting' : 'Waiting')),
                    ];
                }
            }
        }

        return view('livewire.documents.show', [
            'scan' => $scan,
            'readProgress' => in_array($scan->status, [DocumentScanStatus::Pending, DocumentScanStatus::Processing], true)
                ? $this->readProgress($scan)
                : null,
            'extractionGroups' => $extractionGroups,
            'findingsView' => $scan->findings !== null
                ? $this->buildFindingsView($scan->findings->observations)
                : null,
            'fieldFill' => $this->fieldFill($scan),
            'matchCustomers' => $this->matchProfileId === null ? collect() : Customer::query()->where('status', CustomerStatus::Active)->search($this->customerQuery)->orderBy('full_legal_name')->limit(8)->get(),
        ])->layout('layouts.app');
    }

    /**
     * @return array{
     *     requested: array<string, list<array{label: string, field_id: string, description: string, type: string}>>,
     *     profiles: list<array{record_ref: string, pages: list<int>, filled: list<array{label: string, description: string, field_id: string, value: string, state: string, quotes: list<array{text: string, page: int|null}>}>, missing: list<array{label: string, field_id: string, description: string}>}>
     * }|null
     */
    /** @return array{done: int, total: int, label: string} */
    private function readProgress(DocumentScan $scan): array
    {
        $waiting = [
            'done' => 0,
            'total' => 0,
            'label' => $scan->status === DocumentScanStatus::Pending ? 'Waiting for the reader to start' : 'Reading the document',
        ];
        $path = 'document-scans/'.$scan->id.'/read-progress.json';
        if (! Storage::disk('local')->exists($path)) {
            return $waiting;
        }
        $decoded = json_decode(Storage::disk('local')->get($path), true);
        if (! is_array($decoded) || ! is_int($decoded['done'] ?? null) || ! is_int($decoded['total'] ?? null) || ! is_string($decoded['label'] ?? null) || $decoded['label'] === '') {
            return $waiting;
        }

        return ['done' => $decoded['done'], 'total' => $decoded['total'], 'label' => $decoded['label']];
    }

    private function fieldFill(DocumentScan $scan): ?array
    {
        if ($scan->profile_schema_hash === null) {
            return null;
        }
        $snapshot = CustomerProfileSchemaSnapshot::query()->where('hash', $scan->profile_schema_hash)->first();
        if ($snapshot === null) {
            return null;
        }
        $fields = $snapshot->schema()['fields'] ?? [];
        $byPath = [];
        $requested = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['path'] ?? null)) {
                continue;
            }
            $byPath[$field['path']] = $field;
            $requested[$field['module'] ?? 'other'][] = [
                'label' => is_string($field['label'] ?? null) ? $field['label'] : $field['path'],
                'field_id' => $field['path'],
                'description' => is_string($field['description'] ?? null) ? $field['description'] : '',
                'type' => is_string($field['type'] ?? null) ? $field['type'] : '',
            ];
        }
        $profiles = [];
        foreach ($scan->extractedProfiles as $profile) {
            if (($profile->reviewed_projection['grouping_status'] ?? 'active') === 'withdrawn') {
                continue;
            }
            if (($profile->reviewed_projection['values'] ?? []) === []) {
                continue;
            }
            $filled = [];
            $heading = $profile->record_ref;
            foreach ($profile->reviewed_projection['values'] ?? [] as $item) {
                if (! is_array($item) || ! is_string($item['field_id'] ?? null)) {
                    continue;
                }
                if ($heading === $profile->record_ref && $item['field_id'] === 'customer.full_legal_name' && is_string($item['value'] ?? null) && trim($item['value']) !== '') {
                    $heading = $item['value'];
                }
                $definition = $byPath[$item['field_id']] ?? null;
                $filled[] = [
                    'field_id' => $item['field_id'],
                    'label' => $this->staffLabel($item['field_id'], is_string($definition['label'] ?? null) ? $definition['label'] : $item['field_id']),
                    'value' => $this->displayProfileValue($item),
                ];
            }
            $profiles[] = $this->profileCard($heading, $profile->source_pages ?? [], $filled, $requested, $profile->id);
        }

        return ['requested' => $requested, 'profiles' => $profiles];
    }

    /**
     * @param  list<array{field_id: string, label: string, value: string}>  $filled
     * @param  array<string, list<array{label: string, field_id: string, description: string, type: string}>>  $requested
     * @param  list<int>  $pages
     * @return array{heading: string, pages: list<int>, sections: list<array{title: string, filled: list<array{label: string, value: string}>, blank: list<string>}>, review_profile_id: int|null}
     */
    private function profileCard(string $heading, array $pages, array $filled, array $requested, ?int $reviewId): array
    {
        foreach ($filled as $index => $row) {
            [$path] = CustomerProfileSchema::canonicalFieldId((string) $row['field_id']);
            $filled[$index]['field_id'] = $path;
            $filled[$index]['label'] = $this->staffLabel($path, (string) ($row['label'] ?? $path));
        }
        $filled = $this->fillDefaults($this->fillFromIc($filled, $requested), $requested);
        $identificationWarning = $this->identificationWarning($filled);
        $grouped = [];
        foreach ($filled as $row) {
            $grouped[$row['field_id']][] = $row;
        }
        $sections = [];
        $readingOrder = array_flip(CustomerProfileSchema::readingOrder());
        foreach (['personal' => 'Personal details', 'relationships' => 'Family and next of kin', 'employment' => 'Employment', 'bank_accounts' => 'Bank accounts', 'tax' => 'Tax', 'financial' => 'Financial', 'investment' => 'Investment', 'compliance' => 'Compliance'] as $module => $title) {
            $fields = $requested[$module] ?? [];
            usort($fields, fn (array $left, array $right): int => ($readingOrder[$left['field_id']] ?? PHP_INT_MAX) <=> ($readingOrder[$right['field_id']] ?? PHP_INT_MAX));
            $rows = [];
            $blank = [];
            foreach ($fields as $field) {
                if (isset($grouped[$field['field_id']])) {
                    foreach ($grouped[$field['field_id']] as $row) {
                        $rows[] = [
                            'field_id' => $field['field_id'],
                            'label' => $row['label'],
                            'raw' => $row['value'],
                            'value' => $this->presentedRelationship($filled, $field['field_id'], (string) $row['value']),
                            'from_ic' => (bool) ($row['from_ic'] ?? false),
                            'note' => (string) ($row['note'] ?? ''),
                            'warning' => $identificationWarning !== '' && in_array($field['field_id'], ['primary_identification.type', 'primary_identification.number'], true) ? $identificationWarning : '',
                        ];
                    }
                    unset($grouped[$field['field_id']]);
                } else {
                    $blank[] = $this->staffLabel($field['field_id'], $field['label']);
                }
            }
            if ($rows !== [] || $blank !== []) {
                $sections[] = ['title' => $title, 'filled' => $rows, 'blank' => $blank];
            }
        }
        if ($grouped !== []) {
            $other = [];
            foreach ($grouped as $rowsForField) {
                foreach ($rowsForField as $row) {
                    $other[] = ['field_id' => $row['field_id'], 'label' => $row['label'], 'raw' => $row['value'], 'value' => $this->presentedValue($row['field_id'], $row['value']), 'from_ic' => (bool) ($row['from_ic'] ?? false), 'note' => (string) ($row['note'] ?? '')];
                }
            }
            $sections[] = ['title' => 'Other', 'filled' => $other, 'blank' => []];
        }
        $name = app(ProfileProjection::class)->repairBrokenFfLigature(trim($heading));
        if ($name === '' || preg_match('/^(?:applicant[_-]?\d+|r\d+|p\d+)$/i', $name) === 1) {
            $name = '';
        }
        if ($name === '') {
            foreach ($filled as $row) {
                if (($row['field_id'] ?? '') === 'customer.full_legal_name' && is_string($row['value'] ?? null) && trim($row['value']) !== '') {
                    $name = trim($row['value']);
                    break;
                }
            }
        }

        return [
            'heading' => $name === '' ? '' : mb_strtoupper($name, 'UTF-8'),
            'pages' => $pages,
            'sections' => $sections,
            'review_profile_id' => $reviewId,
        ];
    }

    /**
     * @param  list<array{field_id: string, value: string}>  $filled
     */
    private function presentedRelationship(array $filled, string $fieldId, string $value): string
    {
        if ($fieldId === 'relationships[].relationship' && strcasecmp($value, 'other') === 0) {
            foreach ($filled as $row) {
                if ($row['field_id'] === 'relationships[].other_relationship' && strcasecmp(trim((string) $row['value']), 'next of kin') === 0) {
                    return 'NEXT OF KIN';
                }
            }
        }

        return $this->presentedValue($fieldId, $value);
    }

    private function presentedValue(string $fieldId, string $value): string
    {
        if (str_contains(strtolower($fieldId), 'email')) {
            return $value;
        }
        $value = match ($fieldId) {
            'investment.objectives' => CustomerProfileSchema::OBJECTIVES[$value] ?? $value,
            'investment.products' => CustomerProfileSchema::PRODUCTS[$value] ?? $value,
            default => $value,
        };

        return mb_strtoupper(str_replace('_', ' ', $value), 'UTF-8');
    }

    /**
     * @param  list<array{field_id: string, label: string, value: string, from_ic?: bool}>  $filled
     * @param  array<string, list<array{label: string, field_id: string, description: string, type: string}>>  $requested
     * @return list<array{field_id: string, label: string, value: string, from_ic: bool}>
     */
    private function fillFromIc(array $filled, array $requested): array
    {
        $labels = [];
        foreach ($requested as $fields) {
            foreach ($fields as $field) {
                $labels[$field['field_id']] = $this->staffLabel($field['field_id'], $field['label']);
            }
        }
        $byId = [];
        foreach ($filled as $index => $row) {
            $filled[$index]['from_ic'] = (bool) ($row['from_ic'] ?? false);
            $byId[$row['field_id']] = $index;
        }
        $derived = [];
        $related = $this->parsedNric(
            isset($byId['relationships[].related_person.identification_type']) ? (string) $filled[$byId['relationships[].related_person.identification_type']]['value'] : '',
            isset($byId['relationships[].related_person.identification_number']) ? (string) $filled[$byId['relationships[].related_person.identification_number']]['value'] : '',
        );
        if ($related !== null) {
            $derived['relationships[].related_person.identification_type'] = 'nric';
            $derived['relationships[].related_person.date_of_birth'] = $related['date_of_birth'];
            $derived['relationships[].related_person.gender'] = $related['gender'];
            $derived['relationships[].related_person.nationality'] = $related['nationality'];
        }
        $relationship = isset($byId['relationships[].relationship']) ? trim((string) $filled[$byId['relationships[].relationship']]['value']) : '';
        $maritalStatus = isset($byId['customer.marital_status']) ? (string) $filled[$byId['customer.marital_status']]['value'] : null;
        $type = isset($byId['primary_identification.type']) ? strtolower((string) $filled[$byId['primary_identification.type']]['value']) : '';
        $number = isset($byId['primary_identification.number']) ? (string) $filled[$byId['primary_identification.number']]['value'] : '';
        $parsed = ($type === '' || $type === 'nric') ? app(NricParser::class)->parse($number) : null;
        $applicantGender = isset($byId['customer.gender']) ? (string) $filled[$byId['customer.gender']]['value'] : ($parsed['gender'] ?? null);
        $relatedGender = isset($byId['relationships[].related_person.gender']) ? (string) $filled[$byId['relationships[].related_person.gender']]['value'] : ($related['gender'] ?? null);
        if (strcasecmp($relationship, 'spouse') === 0 && ! $this->sameGender($applicantGender, $relatedGender) && ($maritalStatus === null || trim($maritalStatus) === '')) {
            $maritalStatus = 'Married';
        }
        if ($parsed !== null) {
            $derived['primary_identification.type'] = 'nric';
            $derived['customer.date_of_birth'] = $parsed['date_of_birth'];
            $derived['customer.gender'] = $parsed['gender'];
            $derived['customer.nationality'] = $parsed['nationality'];
            $salutation = Customer::defaultSalutationForGender($parsed['gender'], $maritalStatus);
            if ($salutation !== null) {
                $derived['customer.salutation'] = $salutation;
            }
        }
        foreach ($derived as $fieldId => $value) {
            if (isset($byId[$fieldId])) {
                continue;
            }
            $filled[] = [
                'field_id' => $fieldId,
                'label' => $labels[$fieldId] ?? $fieldId,
                'value' => $value,
                'from_ic' => true,
            ];
        }
        $this->rejectSameGenderSpouse($filled, $labels);
        $this->rejectUnmarriedSpouse($filled, $labels);

        return $filled;
    }

    /**
     * @param  list<array{field_id: string, label: string, value: string, from_ic?: bool, note?: string}>  $filled
     * @param  array<string, string>  $labels
     */
    private function rejectSameGenderSpouse(array &$filled, array $labels): void
    {
        $applicant = null;
        $related = null;
        $hasOther = false;
        foreach ($filled as $row) {
            if ($row['field_id'] === 'customer.gender') {
                $applicant = (string) $row['value'];
            }
            if ($row['field_id'] === 'relationships[].related_person.gender') {
                $related = (string) $row['value'];
            }
            if ($row['field_id'] === 'relationships[].other_relationship' && trim((string) $row['value']) !== '') {
                $hasOther = true;
            }
        }
        // shortcut: one gender pair for the card; split when a form has two family rows.
        if (! $this->sameGender($applicant, $related)) {
            return;
        }
        $this->demoteSpouseRows($filled, $labels, 'Same gender', $hasOther);
    }

    /**
     * @param  list<array{field_id: string, label: string, value: string, from_ic?: bool, note?: string}>  $filled
     * @param  array<string, string>  $labels
     */
    private function rejectUnmarriedSpouse(array &$filled, array $labels): void
    {
        $marital = null;
        $hasOther = false;
        foreach ($filled as $row) {
            if ($row['field_id'] === 'customer.marital_status') {
                $marital = strtolower(trim((string) $row['value']));
            }
            if ($row['field_id'] === 'relationships[].other_relationship' && trim((string) $row['value']) !== '') {
                $hasOther = true;
            }
        }
        if ($marital === null || $marital === '' || $marital === 'married') {
            return;
        }
        $this->demoteSpouseRows($filled, $labels, 'Not married', $hasOther);
    }

    /**
     * @param  list<array{field_id: string, label: string, value: string, from_ic?: bool, note?: string}>  $filled
     * @param  array<string, string>  $labels
     */
    private function demoteSpouseRows(array &$filled, array $labels, string $note, bool $hasOther): void
    {
        $changed = false;
        foreach ($filled as $index => $row) {
            if ($row['field_id'] !== 'relationships[].relationship' || strcasecmp(trim((string) $row['value']), 'spouse') !== 0) {
                continue;
            }
            $filled[$index]['value'] = 'other';
            $filled[$index]['note'] = $note;
            $changed = true;
        }
        if (! $changed || $hasOther) {
            return;
        }
        $filled[] = [
            'field_id' => 'relationships[].other_relationship',
            'label' => $labels['relationships[].other_relationship'] ?? 'Other Relationship',
            'value' => 'Next of kin',
            'from_ic' => false,
            'note' => $note,
        ];
    }

    private function sameGender(?string $left, ?string $right): bool
    {
        $left = match (strtolower(trim((string) $left))) {
            'male', 'm', 'lelaki' => 'male',
            'female', 'f', 'perempuan' => 'female',
            default => null,
        };
        $right = match (strtolower(trim((string) $right))) {
            'male', 'm', 'lelaki' => 'male',
            'female', 'f', 'perempuan' => 'female',
            default => null,
        };

        return $left !== null && $left === $right;
    }

    /**
     * @param  list<array{field_id: string, label: string, value: string, from_ic?: bool, note?: string}>  $filled
     * @param  array<string, list<array{label: string, field_id: string, description: string, type: string}>>  $requested
     * @return list<array{field_id: string, label: string, value: string, from_ic: bool, note?: string}>
     */
    private function fillDefaults(array $filled, array $requested): array
    {
        $labels = [];
        foreach ($requested as $fields) {
            foreach ($fields as $field) {
                $labels[$field['field_id']] = $this->staffLabel($field['field_id'], $field['label']);
            }
        }
        $byId = [];
        foreach ($filled as $row) {
            $byId[$row['field_id']] = $row;
        }
        $derived = [
            'customer.race' => 'Malay',
            'customer.country_of_residence' => 'Malaysia',
            'investment.objectives' => 'capital_growth',
            'investment.risk_tolerance' => 'moderate',
            'investment.trading_knowledge' => 'intermediate',
            'investment.products' => 'local_equities',
        ];
        $type = isset($byId['primary_identification.type']) ? strtolower((string) $byId['primary_identification.type']['value']) : '';
        $number = isset($byId['primary_identification.number']) ? (string) $byId['primary_identification.number']['value'] : '';
        $nric = $type === 'nric' || ($type === '' && app(NricParser::class)->parse($number) !== null);
        if ($nric) {
            $derived['primary_identification.issuing_jurisdiction'] = 'MY';
            $derived['addresses[residential].country'] = 'Malaysia';
        }
        if (strcasecmp(trim((string) ($byId['relationships[].relationship']['value'] ?? '')), 'spouse') === 0) {
            $derived['customer.marital_status'] = 'Married';
        }
        $familyPhone = trim((string) ($byId['relationships[].related_person.mobile']['value'] ?? ''));
        if ($familyPhone === '') {
            $familyPhone = trim((string) ($byId['relationships[].related_person.home_telephone']['value'] ?? ''));
        }
        if ($familyPhone !== '') {
            $derived['relationships[].is_next_of_kin'] = 'Yes';
            $derived['relationships[].is_primary_next_of_kin'] = 'Yes';
        }
        if ($this->parsedNric(
            isset($byId['relationships[].related_person.identification_type']) ? (string) $byId['relationships[].related_person.identification_type']['value'] : '',
            isset($byId['relationships[].related_person.identification_number']) ? (string) $byId['relationships[].related_person.identification_number']['value'] : '',
        ) !== null) {
            $derived['relationships[].related_person.employer_country'] = 'Malaysia';
        }
        $hasBankAccount = false;
        foreach (array_keys($byId) as $fieldId) {
            if (str_starts_with($fieldId, 'bank_accounts[]')) {
                $hasBankAccount = true;
                break;
            }
        }
        if ($hasBankAccount) {
            $legalName = trim((string) ($byId['customer.full_legal_name']['value'] ?? ''));
            $accountHolder = trim((string) ($byId['bank_accounts[].account_holder_name']['value'] ?? ''));
            if ($legalName !== '') {
                $derived['bank_accounts[].account_holder_name'] = $legalName;
            } elseif (BankStatementProfileRules::isStatementScan($this->documentScan) && $accountHolder !== '') {
                $derived['customer.full_legal_name'] = $accountHolder;
            }
            $derived['bank_accounts[].bank_country'] = 'Malaysia';
            $derived['bank_accounts[].is_active'] = 'Yes';
            $derived['bank_accounts[].is_primary'] = 'Yes';
        }
        $hasPepInfo = false;
        foreach (array_keys($byId) as $fieldId) {
            if (str_starts_with($fieldId, 'pep_associations[]') || (str_starts_with($fieldId, 'compliance[pep].') && $fieldId !== 'compliance[pep].answer')) {
                $hasPepInfo = true;
                break;
            }
        }
        if (! $hasPepInfo) {
            $derived['compliance[pep].answer'] = 'no';
        }
        $parts = ['line_1', 'line_2', 'city', 'state', 'postcode', 'country'];
        $mailingFilled = false;
        foreach ($parts as $part) {
            if (isset($byId['addresses[mailing].'.$part])) {
                $mailingFilled = true;
            }
        }
        $same = strtolower((string) ($byId['addresses[mailing].same_as_residential']['value'] ?? ''));
        $statement = BankStatementProfileRules::isStatementScan($this->documentScan);
        if (! $mailingFilled && ! in_array($same, ['no', 'false', '0'], true) && ! $statement) {
            $copied = false;
            foreach ($parts as $part) {
                $value = $byId['addresses[residential].'.$part]['value'] ?? null;
                if (($value === null || $value === '') && $part === 'country' && $nric) {
                    $value = 'Malaysia';
                }
                if ($value === null || $value === '') {
                    continue;
                }
                $derived['addresses[mailing].'.$part] = $value;
                $copied = true;
            }
            if ($copied) {
                $derived['addresses[mailing].same_as_residential'] = 'Yes';
            }
        }
        if ($statement && $mailingFilled && ! isset($byId['addresses[mailing].same_as_residential'])) {
            $residential = [];
            $mailing = [];
            foreach ($parts as $part) {
                $residential[$part] = $byId['addresses[residential].'.$part]['value'] ?? ($derived['addresses[residential].'.$part] ?? '');
                $mailing[$part] = $byId['addresses[mailing].'.$part]['value'] ?? '';
            }
            if (BankStatementProfileRules::addressesEqual($residential, $mailing)) {
                $derived['addresses[mailing].same_as_residential'] = 'Yes';
            }
        }
        if ($statement) {
            $status = strtolower((string) ($byId['customer.employment_status']['value'] ?? ($derived['customer.employment_status'] ?? '')));
            $arrangement = strtolower((string) ($byId['employments[].arrangement']['value'] ?? ''));
            $selfEmployed = $status === 'self_employed' || $arrangement === 'self_employed';
            if ($selfEmployed && $mailingFilled) {
                $map = [
                    'address_line_1' => 'line_1',
                    'address_line_2' => 'line_2',
                    'postcode' => 'postcode',
                    'city' => 'city',
                    'state' => 'state',
                    'country' => 'country',
                ];
                $employmentAddressBlank = true;
                foreach (array_keys($map) as $field) {
                    if (isset($byId['employments[].'.$field])) {
                        $employmentAddressBlank = false;
                        break;
                    }
                }
                if ($employmentAddressBlank) {
                    foreach ($map as $employmentField => $mailField) {
                        $value = $byId['addresses[mailing].'.$mailField]['value'] ?? null;
                        if ($value === null || $value === '') {
                            continue;
                        }
                        $derived['employments[].'.$employmentField] = $value;
                    }
                }
            }
        }
        foreach ($derived as $fieldId => $value) {
            if (isset($byId[$fieldId])) {
                continue;
            }
            $filled[] = [
                'field_id' => $fieldId,
                'label' => $labels[$fieldId] ?? $fieldId,
                'value' => (string) $value,
                'from_ic' => false,
                'note' => 'Default',
            ];
        }

        return $filled;
    }

    /**
     * @return array{date_of_birth: string, gender: string, nationality: string}|null
     */
    private function parsedNric(?string $type, ?string $number): ?array
    {
        $type = strtolower(trim((string) $type));
        if ($type !== '' && $type !== 'nric') {
            return null;
        }

        return app(NricParser::class)->parse($number);
    }

    private function staffLabel(string $path, string $label): string
    {
        if (preg_match('/contacts\[([^\]]+)\]\.value$/', $path, $match) === 1) {
            return match ($match[1]) {
                'mobile' => 'Mobile Telephone',
                'home' => 'Home Telephone',
                'email' => 'Email Address',
                'social' => 'Social Media Handle',
                'social:facebook' => 'Facebook',
                'social:instagram' => 'Instagram',
                'social:twitter' => 'Twitter',
                default => ucwords(str_replace(['_', ':'], ' ', $match[1])),
            };
        }
        if (preg_match('/^primary_identification\.(number|type|old_nric|issuing_jurisdiction)$/', $path, $match) === 1) {
            return match ($match[1]) {
                'number' => 'Identification Number',
                'type' => 'Identification Type',
                'old_nric' => 'Old NRIC',
                default => 'Issuing Country',
            };
        }
        if (preg_match('/^addresses\[([^\]]+)\]\.(occupancy_status|occupancy_other|same_as_residential)$/', $path, $match) === 1) {
            $place = ucwords($match[1]);

            return match ($match[2]) {
                'occupancy_status' => $place.' Occupancy',
                'occupancy_other' => 'Other '.$place.' Occupancy',
                default => 'Mailing Address Same As Residential',
            };
        }
        if (preg_match('/^relationships\[\]\.related_person\.(.+)$/', $path, $match) === 1) {
            return 'Family Member '.ucwords(str_replace('_', ' ', $match[1]));
        }
        if (preg_match('/^relationships\[\]\.(relationship|other_relationship)$/', $path, $match) === 1) {
            return $match[1] === 'relationship' ? 'Relationship To Applicant' : 'Other Relationship';
        }
        if (preg_match('/^employments\[\]\.(.+)$/', $path, $match) === 1) {
            $part = match ($match[1]) {
                'arrangement' => 'Employment Arrangement',
                'occupation' => 'Occupation',
                'employer_name' => 'Employer Name',
                'registration_number' => 'Employer Registration Number',
                'industry' => 'Employer Industry',
                'sub_industry' => 'Employer Sub-industry',
                'start_date' => 'Employment Start Date',
                'end_date' => 'Employment End Date',
                'is_current' => 'Current Employment',
                'is_primary' => 'Primary Employment',
                'general_telephone' => 'Employer Telephone',
                'direct_telephone' => 'Direct Telephone At Employer',
                'fax' => 'Employer Fax',
                'address_line_1' => 'Employer Address Line 1',
                'address_line_2' => 'Employer Address Line 2',
                'city' => 'Employer City',
                'state' => 'Employer State',
                'postcode' => 'Employer Postcode',
                'country' => 'Employer Country',
                default => ucwords(str_replace('_', ' ', $match[1])),
            };

            return $part;
        }
        if ($path === 'customer.employment_status_other') {
            return 'Other Employment Status';
        }
        if (preg_match('/^bank_accounts\[\]\.(.+)$/', $path, $match) === 1) {
            return match ($match[1]) {
                'bank_name' => 'Bank Name',
                'bank_country' => 'Bank Country',
                'branch_address' => 'Bank Branch Address',
                'account_type' => 'Bank Account Type',
                'account_number' => 'Bank Account Number',
                'account_holder_name' => 'Account Holder Name',
                'ownership' => 'Sole Or Joint Account',
                'is_primary' => 'Primary Bank Account',
                'is_active' => 'Bank Account Active',
                default => ucwords(str_replace('_', ' ', $match[1])),
            };
        }
        if (preg_match('/^tax_residencies\[\]\.(.+)$/', $path, $match) === 1) {
            return match ($match[1]) {
                'jurisdiction' => 'Foreign Tax Country',
                'foreign_tin' => 'Foreign Tax Number',
                'missing_tin_reason' => 'Reason Foreign Tax Number Is Missing',
                'missing_tin_explanation' => 'Explanation For Missing Foreign Tax Number',
                'effective_date' => 'Foreign Tax Residency From',
                'ended_on' => 'Foreign Tax Residency Until',
                default => ucwords(str_replace('_', ' ', $match[1])),
            };
        }
        if (preg_match('/^fatca\.(.+)$/', $path, $match) === 1) {
            return match ($match[1]) {
                'us_person_for_tax' => 'US Person For Tax',
                'us_green_card_holder' => 'US Green Card Holder',
                'us_resident' => 'US Tax Resident',
                'selected_classification' => 'FATCA Classification',
                'declared_on' => 'FATCA Declaration Date',
                default => ucwords(str_replace('_', ' ', $match[1])),
            };
        }
        if ($path === 'tax_identification.malaysian_tin') {
            return 'Malaysian Tax Number';
        }
        if (preg_match('/^financial\.(.+)$/', $path, $match) === 1) {
            return match ($match[1]) {
                'monthly_income_rm' => 'Monthly Income (RM)',
                'personal_annual_income_bracket' => 'Personal Annual Income',
                'joint_annual_income_bracket' => 'Joint Annual Income',
                'net_asset_bracket' => 'Net Assets',
                'portfolio_bracket' => 'Portfolio Value',
                'portfolio_ownership' => 'Who Owns The Portfolio',
                'wealth_sources' => 'Sources Of Wealth',
                'other_wealth_source' => 'Other Source Of Wealth',
                'declared_on' => 'Financial Declaration Date',
                default => ucwords(str_replace('_', ' ', $match[1])),
            };
        }
        if (preg_match('/^addresses\[([^\]]+)\]\.(.+)$/', $path, $match) === 1) {
            return ucwords(str_replace('_', ' ', $match[1])).' '.ucwords(str_replace('_', ' ', $match[2]));
        }
        if (preg_match('/^compliance\[(pep|bankruptcy|petition)\]\.(answer|remarks|declared_on)$/', $path, $match) === 1) {
            $topic = match ($match[1]) {
                'pep' => 'PEP declaration',
                'bankruptcy' => 'Bankruptcy declaration',
                default => 'Bankruptcy petition',
            };
            $part = $match[2] === 'declared_on' ? 'date' : $match[2];

            return ucwords($topic.' '.$part);
        }
        if (preg_match('/^pep_associations\[\]\.(.+)$/', $path, $match) === 1) {
            $part = $match[1] === 'position_meaning' ? 'position type' : str_replace('_', ' ', $match[1]);

            return ucwords('PEP associate '.$part);
        }
        if (preg_match('/^investment\.(.+)$/', $path, $match) === 1) {
            return match ($match[1]) {
                'objectives' => 'Investment Objectives',
                'risk_tolerance' => 'Investment Risk Tolerance',
                'trading_knowledge' => 'Investment Trading Knowledge',
                'products' => 'Investment Products',
                'other_product_details' => 'Other Investment Product Details',
                default => ucwords(str_replace('_', ' ', $match[1])),
            };
        }
        if (preg_match('/^brokerage_declaration\.(answer|declared_on)$/', $path, $match) === 1) {
            return $match[1] === 'answer' ? 'Other Brokerage Answer' : 'Other Brokerage Declaration Date';
        }
        if (preg_match('/^brokerage_accounts\[\]\.(.+)$/', $path, $match) === 1) {
            $part = match ($match[1]) {
                'broker_name' => 'name',
                'branch_address' => 'branch address',
                'account_type' => 'account type',
                'is_active' => 'account active',
                default => str_replace('_', ' ', $match[1]),
            };

            return ucwords('Other brokerage '.$part);
        }

        return $label;
    }

    /** @param array<string, mixed> $item */
    private function displayProfileValue(array $item): string
    {
        if (($item['state'] ?? 'provided') !== 'provided') {
            return ucfirst((string) ($item['state'] ?? 'unfilled'));
        }
        $value = $item['value'] ?? null;
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        if (! is_scalar($value) || (string) $value === '') {
            return '—';
        }
        $text = app(ProfileProjection::class)->repairBrokenFfLigature((string) $value);

        return app(ProfileProjection::class)->dayFirstDate($text) ?? $text;
    }

    /**
     * @param  array<string, mixed>  $observations
     * @return array{
     *     entities: list<array{role: string, name: string, type: string}>,
     *     sections: list<array{heading: string, fields: list<array{label: string, value: string, status: string, page: int|null}>}>,
     *     unsectioned_fields: list<array{label: string, value: string, status: string, page: int|null}>,
     *     tables: list<array{heading: string, columns: list<string>, rows: list<list<string>>}>,
     *     content_blocks: list<array{type: string, text: string, page: int|null}>,
     *     issues: list<array{severity: string, description: string}>
     * }
     */
    private function buildFindingsView(array $observations): array
    {
        $sectionOrder = [];
        foreach ($observations['sections'] ?? [] as $section) {
            if (! is_array($section) || ! is_string($section['id'] ?? null)) {
                continue;
            }
            $sectionOrder[$section['id']] = [
                'heading' => is_string($section['heading'] ?? null) ? $section['heading'] : $section['id'],
                'fields' => [],
            ];
        }

        $unsectioned = [];
        foreach ($observations['fields'] ?? [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $row = [
                'label' => is_string($field['label'] ?? null) ? $field['label'] : 'Field',
                'value' => $this->formatFieldValue($field),
                'status' => is_string($field['answer_status'] ?? null) ? str_replace('_', ' ', $field['answer_status']) : 'unknown',
                'page' => is_int($field['evidence']['page_number'] ?? null) ? $field['evidence']['page_number'] : null,
            ];

            $sectionId = $field['section_id'] ?? null;
            if (is_string($sectionId) && isset($sectionOrder[$sectionId])) {
                $sectionOrder[$sectionId]['fields'][] = $row;
            } else {
                $unsectioned[] = $row;
            }
        }

        $tables = [];
        foreach ($observations['tables'] ?? [] as $table) {
            if (! is_array($table)) {
                continue;
            }

            $columns = [];
            foreach ($table['columns'] ?? [] as $column) {
                if (is_array($column) && is_string($column['heading'] ?? null)) {
                    $columns[] = $column['heading'];
                }
            }

            $rows = [];
            foreach ($table['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $cellsByColumn = [];
                foreach ($row['cells'] ?? [] as $cell) {
                    if (is_array($cell) && is_string($cell['column_id'] ?? null)) {
                        $cellsByColumn[$cell['column_id']] = $this->formatCellValue($cell);
                    }
                }
                $rowValues = [];
                foreach ($table['columns'] ?? [] as $column) {
                    if (! is_array($column) || ! is_string($column['id'] ?? null)) {
                        continue;
                    }
                    $rowValues[] = $cellsByColumn[$column['id']] ?? '—';
                }
                $rows[] = $rowValues;
            }

            $tables[] = [
                'heading' => is_string($table['id'] ?? null) ? $table['id'] : 'Table',
                'columns' => $columns,
                'rows' => $rows,
            ];
        }

        $contentBlocks = [];
        foreach ($observations['content_blocks'] ?? [] as $block) {
            if (! is_array($block) || ! is_string($block['text'] ?? null) || $block['text'] === '') {
                continue;
            }
            $contentBlocks[] = [
                'type' => is_string($block['type'] ?? null) ? str_replace('_', ' ', $block['type']) : 'text',
                'text' => $block['text'],
                'page' => is_int($block['evidence']['page_number'] ?? null) ? $block['evidence']['page_number'] : null,
            ];
        }

        $issues = [];
        foreach ($observations['issues'] ?? [] as $issue) {
            if (! is_array($issue) || ! is_string($issue['description'] ?? null)) {
                continue;
            }
            $issues[] = [
                'severity' => is_string($issue['severity'] ?? null) ? $issue['severity'] : 'info',
                'description' => $issue['description'],
            ];
        }

        $entities = [];
        foreach ($observations['entities'] ?? [] as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $entities[] = [
                'role' => is_string($entity['role'] ?? null) ? $entity['role'] : 'Entity',
                'name' => is_string($entity['display_name'] ?? null) && $entity['display_name'] !== ''
                    ? $entity['display_name']
                    : 'Unknown',
                'type' => is_string($entity['type'] ?? null) ? $entity['type'] : 'other',
            ];
        }

        return [
            'entities' => $entities,
            'sections' => array_values($sectionOrder),
            'unsectioned_fields' => $unsectioned,
            'tables' => $tables,
            'content_blocks' => $contentBlocks,
            'issues' => $issues,
        ];
    }

    /** @param array<string, mixed> $field */
    private function formatFieldValue(array $field): string
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : 'other';
        $value = $field['normalized_value'] ?? null;

        if ($type === 'choice') {
            $optionLabels = [];
            foreach ($field['options'] ?? [] as $option) {
                if (! is_array($option) || ! is_string($option['id'] ?? null)) {
                    continue;
                }
                $optionLabels[$option['id']] = is_string($option['label'] ?? null) ? $option['label'] : $option['id'];
            }

            if (is_array($value) && $value !== []) {
                $labels = [];
                foreach ($value as $selectedId) {
                    if (! is_string($selectedId)) {
                        continue;
                    }
                    $labels[] = $optionLabels[$selectedId] ?? $selectedId;
                }

                return $labels !== [] ? implode(', ', $labels) : '—';
            }

            $marked = [];
            foreach ($field['options'] ?? [] as $option) {
                if (! is_array($option) || ($option['mark_state'] ?? null) !== 'marked') {
                    continue;
                }
                $label = is_string($option['label'] ?? null) ? $option['label'] : (string) ($option['id'] ?? 'option');
                if (is_string($option['free_text'] ?? null) && $option['free_text'] !== '') {
                    $label .= ' ('.$option['free_text'].')';
                }
                $marked[] = $label;
            }

            return $marked !== [] ? implode(', ', $marked) : '—';
        }

        if ($value === null) {
            $raw = $field['raw_value'] ?? null;

            return is_string($raw) && $raw !== '' ? $raw : '—';
        }

        return $this->formatTypedValue($type, $value);
    }

    /** @param array<string, mixed> $cell */
    private function formatCellValue(array $cell): string
    {
        $type = is_string($cell['type'] ?? null) ? $cell['type'] : 'text';
        $value = $cell['normalized_value'] ?? null;

        if ($value === null) {
            $raw = $cell['raw_value'] ?? null;

            return is_string($raw) && $raw !== '' ? $raw : '—';
        }

        return $this->formatTypedValue($type, $value);
    }

    private function formatTypedValue(string $type, mixed $value): string
    {
        return match ($type) {
            'currency' => is_array($value) && isset($value['amount'], $value['currency'])
                ? (string) $value['currency'].' '.(string) $value['amount']
                : '—',
            'boolean' => $value === true ? 'Yes' : ($value === false ? 'No' : '—'),
            'choice' => is_array($value) ? implode(', ', array_map('strval', $value)) : '—',
            default => is_scalar($value) ? (string) $value : '—',
        };
    }
}
