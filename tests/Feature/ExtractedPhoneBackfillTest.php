<?php

namespace Tests\Feature;

use App\Models\DocumentScan;
use App\Services\CustomerProfileSchema;
use App\Services\ExtractedPhoneBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtractedPhoneBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_strips_punctuation_from_extracted_phones(): void
    {
        $snapshot = CustomerProfileSchema::snapshot();
        $scan = DocumentScan::factory()->create();
        $projection = [
            'record_ref' => 'p0001',
            'anchor' => 'Applicant',
            'grouping_status' => 'active',
            'values' => [
                [
                    'field_id' => 'contacts[mobile].value',
                    'entity_ref' => 'person-1',
                    'row_ref' => null,
                    'state' => 'provided',
                    'value' => '012-883 4419',
                    'evidence_ids' => [],
                ],
                [
                    'field_id' => 'contacts[home].value',
                    'entity_ref' => 'person-1',
                    'row_ref' => null,
                    'state' => 'provided',
                    'value' => '03-7721 4400',
                    'evidence_ids' => [],
                ],
                [
                    'field_id' => 'employments[].general_telephone',
                    'entity_ref' => 'person-1',
                    'row_ref' => 'job-1',
                    'state' => 'provided',
                    'value' => '(03) 2118-8888',
                    'evidence_ids' => [],
                ],
                [
                    'field_id' => 'relationships[].related_person.mobile',
                    'entity_ref' => 'kin-1',
                    'row_ref' => 'rel-1',
                    'state' => 'provided',
                    'value' => '+60 12-345 6789',
                    'evidence_ids' => [],
                ],
                [
                    'field_id' => 'customer.full_legal_name',
                    'entity_ref' => 'person-1',
                    'row_ref' => null,
                    'state' => 'provided',
                    'value' => 'Amina Noor',
                    'evidence_ids' => [],
                ],
            ],
            'warnings' => [],
        ];
        $profile = $scan->extractedProfiles()->create([
            'record_ref' => 'p0001',
            'source_pages' => [1],
            'field_schema_hash' => $snapshot->hash,
            'field_schema_version' => $snapshot->version,
            'prompt_version' => '1',
            'contract_version' => '1.0',
            'original_projection' => $projection,
            'reviewed_projection' => $projection,
            'review_status' => 'pending',
        ]);

        app(ExtractedPhoneBackfill::class)->run();

        $values = collect($profile->fresh()->reviewed_projection['values'])->pluck('value', 'field_id');
        $this->assertSame('0128834419', $values['contacts[mobile].value']);
        $this->assertSame('0377214400', $values['contacts[home].value']);
        $this->assertSame('0321188888', $values['employments[].general_telephone']);
        $this->assertSame('+60123456789', $values['relationships[].related_person.mobile']);
        $this->assertSame('Amina Noor', $values['customer.full_legal_name']);
        $this->assertSame(
            '0128834419',
            collect($profile->fresh()->original_projection['values'])->firstWhere('field_id', 'contacts[mobile].value')['value'],
        );
    }
}
