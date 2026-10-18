<?php

namespace App\Services;

use App\Enums\CustomerActivityAction;
use App\Models\Customer;
use App\Models\ExtractedProfile;
use App\Models\ExtractedProfileMerge;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MergeExtractedProfile
{
    private function ownershipResolved(ExtractedProfile $profile): bool
    {
        $projection = $profile->reviewed_projection;
        if (! is_array($projection) || ! array_key_exists('ownership', $projection)) {
            return $profile->prompt_version !== SinglePassExtraction::PROMPT_VERSION;
        }

        return $projection['ownership'] === 'resolved';
    }

    public function __construct(
        private ProfileMergePreview $preview,
        private CustomerProfileWrites $writes,
        private CustomerActivityLogger $activity,
    ) {}

    /** @param  array<string, string>  $targets
     * @param  list<string>  $excluded */
    public function commit(ExtractedProfile $profile, Customer $customer, User $actor, string $strategy, string $submissionKey, string $baseline, array $targets, array $excluded): ExtractedProfileMerge
    {
        if (! in_array($strategy, ['fill_empty', 'overwrite', 'replace_all'], true) || $submissionKey === '') {
            throw ValidationException::withMessages(['merge' => 'Choose a merge strategy.']);
        }
        $existing = ExtractedProfileMerge::query()->where('submission_key', $submissionKey)->first();
        if ($existing) {
            return $existing;
        }
        Gate::forUser($actor)->authorize('view', $profile->documentScan);
        Gate::forUser($actor)->authorize('update', $customer);
        if (! $this->ownershipResolved($profile) || ($profile->reviewed_projection['grouping_status'] ?? 'active') === 'withdrawn') {
            throw ValidationException::withMessages(['merge' => 'Resolve applicant ownership before matching this profile to a customer.']);
        }

        try {
            return DB::transaction(function () use ($profile, $customer, $actor, $strategy, $submissionKey, $baseline, $targets, $excluded): ExtractedProfileMerge {
                $profile = ExtractedProfile::query()->lockForUpdate()->findOrFail($profile->id);
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                $existing = ExtractedProfileMerge::query()->where('submission_key', $submissionKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
                if (! $this->ownershipResolved($profile) || ($profile->reviewed_projection['grouping_status'] ?? 'active') === 'withdrawn') {
                    throw ValidationException::withMessages(['merge' => 'Resolve applicant ownership before matching this profile to a customer.']);
                }
                $preview = $this->preview->make($profile, $customer, $strategy, $targets, $excluded);
                if ($preview['baseline'] !== $baseline || ! $preview['ready']) {
                    throw ValidationException::withMessages(['merge' => $preview['identity'] ?? 'This preview is out of date or still needs a row choice. Review it again before merging.']);
                }
                $changes = [];
                foreach ($preview['operations'] as $operation) {
                    $this->apply($customer, $actor, $operation);
                    $changes = array_merge($changes, $operation['changes']);
                }
                $merge = ExtractedProfileMerge::query()->create([
                    'submission_key' => $submissionKey,
                    'extracted_profile_id' => $profile->id,
                    'customer_id' => $customer->id,
                    'reviewer_revision' => $profile->revision,
                    'strategy' => $strategy,
                    'changes' => $changes,
                    'merged_by' => $actor->id,
                    'created_at' => now(),
                ]);
                $label = match ($strategy) {
                    'fill_empty' => 'fill only empty',
                    'replace_all' => 'replace all',
                    default => 'overwrite and fill empty',
                };
                $this->activity->log($customer, CustomerActivityAction::Updated, $actor, "Extracted profile {$profile->record_ref} merged ({$label}).");

                return $merge;
            });
        } catch (UniqueConstraintViolationException) {
            return ExtractedProfileMerge::query()->where('submission_key', $submissionKey)->firstOrFail();
        }
    }

    /** @param  array<string, mixed>  $operation */
    private function apply(Customer $customer, User $actor, array $operation): void
    {
        $payload = $operation['payload'];
        if ($operation['type'] === 'personal') {
            foreach (['identification_type', 'identification_number'] as $key) {
                if (blank($payload[$key] ?? null)) {
                    unset($payload[$key]);
                }
            }
        }
        match ($operation['type']) {
            'personal' => $this->writes->savePersonal($customer, $payload, $actor, true),
            'contact' => $this->writes->saveContact($customer, $payload, $actor),
            'address' => $this->writes->saveAddress($customer, $payload, $actor),
            'employment_status' => $this->writes->saveEmploymentStatus($customer, $payload, $actor),
            'employments' => $this->writes->saveEmployment($customer, $payload, $actor, $operation['id'], true),
            'bank_accounts' => $this->applyBank($customer, $actor, $operation),
            'relationships' => $this->writes->saveRelationship($customer, $payload, $actor, $operation['id'] ? $customer->relationships()->findOrFail($operation['id']) : null),
            'tax_residencies' => $this->applyTax($customer, $actor, $operation),
            'brokerage_accounts' => $this->applyBrokerage($customer, $actor, $operation),
            'pep_associations' => $this->applyPep($customer, $actor, $operation),
            'tin' => $this->writes->saveTin($customer, $payload['malaysian_tin'] ?? null, $actor),
            'fatca' => $this->writes->saveFatca($customer, $payload, $actor, $operation['id']),
            'financial' => $this->writes->saveFinancial($customer, $payload, $actor, $operation['id']),
            'investment' => $this->writes->saveInvestmentProfile($customer, $payload, $actor),
            'brokerage_declaration' => $this->writes->saveBrokerageDeclaration($customer, $payload, $actor, $operation['id']),
            'compliance' => $this->writes->saveComplianceDeclaration($customer, $operation['kind'], $payload, $actor),
            default => throw ValidationException::withMessages(['merge' => 'This profile field cannot be merged.']),
        };
    }

    /** @param  array<string, mixed>  $operation */
    private function applyBank(Customer $customer, User $actor, array $operation): void
    {
        $payload = $operation['payload'];
        $active = $payload['_is_active'] ?? null;
        unset($payload['_is_active']);
        $account = $this->writes->saveBankAccount($customer, $payload, $actor, $operation['id'], true);
        if ($active === false) {
            $this->writes->setBankActive($customer, $account->id, false, $actor);
        }
    }

    /** @param  array<string, mixed>  $operation */
    private function applyTax(Customer $customer, User $actor, array $operation): void
    {
        $endedOn = $operation['payload']['_ended_on'] ?? null;
        unset($operation['payload']['_ended_on']);
        $fieldChanges = collect($operation['changes'])->contains(fn (array $change): bool => ! str_ends_with($change['field_id'], '.ended_on'));
        if ($fieldChanges) {
            $this->writes->saveResidency($customer, $operation['payload'], $actor, $operation['id']);
        }
        if ($endedOn) {
            $id = $operation['id'];
            if ($fieldChanges) {
                $key = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $operation['payload']['jurisdiction'])) ?? '');
                $id = $customer->taxResidencies()->where('current_jurisdiction_key', $key)->value('id');
            }
            if ($id) {
                $this->writes->endResidency($customer, (int) $id, (string) $endedOn, $actor);
            }
        }
    }

    /** @param  array<string, mixed>  $operation */
    private function applyBrokerage(Customer $customer, User $actor, array $operation): void
    {
        $active = $operation['payload']['_is_active'] ?? null;
        unset($operation['payload']['_is_active']);
        $account = $this->writes->saveBrokerage($customer, $operation['payload'], $actor, $operation['id'], true);
        if ($active === false) {
            $this->writes->setBrokerageActive($customer, $account->id, false, $actor);
        }
    }

    /** @param  array<string, mixed>  $operation */
    private function applyPep(Customer $customer, User $actor, array $operation): void
    {
        if ($operation['id'] === null) {
            throw ValidationException::withMessages(['merge' => 'Choose an existing PEP association.']);
        }
        $endedOn = null;
        foreach ($operation['changes'] as $change) {
            if (str_ends_with($change['field_id'], '.ended_on')) {
                $endedOn = $change['new'];
            }
        }
        $fields = collect($operation['changes'])->contains(fn (array $change): bool => ! str_ends_with($change['field_id'], '.ended_on'));
        if ($fields) {
            $this->writes->savePepAssociation($customer, $operation['payload'], $actor, $operation['id']);
        }
        if ($endedOn) {
            $this->writes->endPepAssociation($customer, (int) $operation['id'], (string) $endedOn, $actor);
        }
    }
}
