<?php

namespace Tests\Feature;

use App\Enums\DocumentScanStatus;
use App\Models\AttachmentScan;
use App\Models\Customer;
use App\Models\DocumentScan;
use App\Models\ExtractedProfile;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\ExtractedNricBackfill;
use App\Services\StoreDocumentFindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\DocumentFindingsFixtures;
use Tests\TestCase;

class ExtractedNricBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_dashes_nric_values_and_collapses_same_ic_duplicates(): void
    {
        Queue::fake();
        $snapshot = CustomerProfileSchema::snapshot();
        $scan = DocumentScan::factory()->create([
            'status' => DocumentScanStatus::Completed,
            'readable' => true,
            'mime_type' => 'image/png',
            'profile_schema_hash' => $snapshot->hash,
            'record_plan' => [['anchor' => 'Applicant', 'regions' => [['page_number' => 1, 'bbox' => null]]]],
        ]);
        app(StoreDocumentFindings::class)->store($scan, DocumentFindingsFixtures::identity($scan->id));
        $scan->findings->update([
            'record_ownership' => [
                'records' => [
                    ['id' => 'p0001', 'anchor' => 'Applicant', 'regions' => [['page_number' => 1, 'bbox' => null]]],
                    ['id' => 'p0002', 'anchor' => 'Applicant', 'regions' => [['page_number' => 2, 'bbox' => null]]],
                ],
                'findings' => [],
                'fragments' => [],
            ],
        ]);
        $scan->update([
            'record_plan' => [
                ['id' => 'p0001', 'anchor' => 'Applicant', 'regions' => [['page_number' => 1, 'bbox' => null]]],
                ['id' => 'p0002', 'anchor' => 'Applicant', 'regions' => [['page_number' => 2, 'bbox' => null]]],
            ],
        ]);

        $first = $this->profile($scan, $snapshot, 'p0001', [1], [
            $this->value('customer.full_legal_name', 'Amina Noor'),
            $this->value('primary_identification.number', '900101145678'),
        ]);
        $second = $this->profile($scan, $snapshot, 'p0002', [2], [
            $this->value('customer.full_legal_name', 'Amina Noor'),
            $this->value('primary_identification.number', '900101-14-5678'),
            $this->value('employments[].employer_name', 'ACME', '1'),
        ]);

        app(ExtractedNricBackfill::class)->run();

        $first->refresh();
        $second->refresh();
        $this->assertSame('active', $first->reviewed_projection['grouping_status'] ?? 'active');
        $this->assertSame('withdrawn', $second->reviewed_projection['grouping_status']);
        $values = collect($first->reviewed_projection['values'])->pluck('value', 'field_id');
        $this->assertSame('900101-14-5678', $values['primary_identification.number']);
        $this->assertSame('ACME', $values['employments[].employer_name']);
        $this->assertSame([1, 2], $first->source_pages);
        $this->assertCount(1, $scan->fresh()->record_plan);
        $this->assertSame('p0001', $scan->fresh()->record_plan[0]['id']);
        $this->assertSame('p0001', $scan->fresh()->findings->record_ownership['records'][0]['id']);
    }

    public function test_backfill_skips_profiles_that_were_already_matched(): void
    {
        Queue::fake();
        $snapshot = CustomerProfileSchema::snapshot();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $scan = DocumentScan::factory()->create([
            'status' => DocumentScanStatus::Completed,
            'readable' => true,
            'mime_type' => 'image/png',
            'profile_schema_hash' => $snapshot->hash,
            'record_plan' => [['anchor' => 'Applicant', 'regions' => [['page_number' => 1, 'bbox' => null]]]],
        ]);
        app(StoreDocumentFindings::class)->store($scan, DocumentFindingsFixtures::identity($scan->id));

        $first = $this->profile($scan, $snapshot, 'p0001', [1], [
            $this->value('customer.full_legal_name', 'Amina Noor'),
            $this->value('primary_identification.number', '900101145678'),
        ]);
        $second = $this->profile($scan, $snapshot, 'p0002', [2], [
            $this->value('customer.full_legal_name', 'Amina Noor'),
            $this->value('primary_identification.number', '900101-14-5678'),
        ]);
        $first->merges()->create([
            'submission_key' => 'matched-once',
            'customer_id' => $customer->id,
            'reviewer_revision' => 0,
            'strategy' => 'fill_empty',
            'changes' => [],
            'merged_by' => $staff->id,
            'created_at' => now(),
        ]);

        app(ExtractedNricBackfill::class)->run();

        $this->assertSame('900101-14-5678', collect($first->fresh()->reviewed_projection['values'])->firstWhere('field_id', 'primary_identification.number')['value']);
        $this->assertSame('active', $first->fresh()->reviewed_projection['grouping_status'] ?? 'active');
        $this->assertSame('active', $second->fresh()->reviewed_projection['grouping_status'] ?? 'active');
    }

    public function test_backfill_formats_attachment_identification_numbers(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = AttachmentScan::factory()->for($staff, 'uploader')->create([
            'extracted_identification_number' => '900101145678',
        ]);

        app(ExtractedNricBackfill::class)->run();

        $this->assertSame('900101-14-5678', $scan->fresh()->extracted_identification_number);
    }

    /** @param list<array<string, mixed>> $values */
    private function profile(DocumentScan $scan, $snapshot, string $ref, array $pages, array $values): ExtractedProfile
    {
        $projection = [
            'record_ref' => $ref,
            'anchor' => 'Applicant',
            'grouping_status' => 'active',
            'values' => $values,
            'warnings' => [],
        ];

        return $scan->extractedProfiles()->create([
            'record_ref' => $ref,
            'source_pages' => $pages,
            'field_schema_hash' => $snapshot->hash,
            'field_schema_version' => $snapshot->version,
            'prompt_version' => '1',
            'contract_version' => '1.0',
            'original_projection' => $projection,
            'reviewed_projection' => $projection,
            'review_status' => 'pending',
        ]);
    }

    /** @return array<string, mixed> */
    private function value(string $field, mixed $value, ?string $row = null): array
    {
        return [
            'field_id' => $field,
            'entity_ref' => 'person-1',
            'row_ref' => $row,
            'state' => 'provided',
            'value' => $value,
            'evidence_ids' => ['name'],
        ];
    }
}
