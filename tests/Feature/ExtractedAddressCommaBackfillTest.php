<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerEmployment;
use App\Models\DocumentScan;
use App\Models\RelatedPerson;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\ExtractedAddressCommaBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtractedAddressCommaBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_strips_commas_from_extracted_and_stored_address_lines(): void
    {
        $snapshot = CustomerProfileSchema::snapshot();
        $scan = DocumentScan::factory()->create();
        $projection = [
            'record_ref' => 'p0001',
            'anchor' => 'Applicant',
            'grouping_status' => 'active',
            'values' => [
                [
                    'field_id' => 'addresses[residential].line_1',
                    'entity_ref' => 'person-1',
                    'row_ref' => null,
                    'state' => 'provided',
                    'value' => 'NO 11,RESIDENSI CYBERJAYA LAKEFRONT,',
                    'evidence_ids' => [],
                ],
                [
                    'field_id' => 'addresses[residential].city',
                    'entity_ref' => 'person-1',
                    'row_ref' => null,
                    'state' => 'provided',
                    'value' => 'Cyberjaya, Selangor',
                    'evidence_ids' => [],
                ],
                [
                    'field_id' => 'bank_accounts[].branch_address',
                    'entity_ref' => 'person-1',
                    'row_ref' => 'bank-1',
                    'state' => 'provided',
                    'value' => '15th Floor, Tower A',
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

        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $address = CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'type' => AddressType::Residential,
            'line_1' => 'LOT 1744 ,KAMPUNG LANDAS ,AJIL',
            'line_2' => 'JALAN MEGAH 8, TAMAN MEGAH',
            'city' => 'Ajil',
        ]);
        $job = CustomerEmployment::factory()->create([
            'customer_id' => $customer->id,
            'address_line_1' => '67,LORONG SERI KUANTAN 65',
            'address_line_2' => null,
        ]);
        $person = RelatedPerson::factory()->create([
            'full_legal_name' => 'Siti Amin',
            'employer_address_line_1' => 'LOT 9, PASAR BESAR,',
        ]);

        app(ExtractedAddressCommaBackfill::class)->run();

        $values = collect($profile->fresh()->reviewed_projection['values'])->pluck('value', 'field_id');
        $this->assertSame('NO 11 RESIDENSI CYBERJAYA LAKEFRONT', $values['addresses[residential].line_1']);
        $this->assertSame('Cyberjaya, Selangor', $values['addresses[residential].city']);
        $this->assertSame('15th Floor, Tower A', $values['bank_accounts[].branch_address']);
        $this->assertSame(
            'NO 11 RESIDENSI CYBERJAYA LAKEFRONT',
            collect($profile->fresh()->original_projection['values'])->firstWhere('field_id', 'addresses[residential].line_1')['value'],
        );

        $address->refresh();
        $this->assertSame('LOT 1744 KAMPUNG LANDAS AJIL', $address->line_1);
        $this->assertSame('JALAN MEGAH 8 TAMAN MEGAH', $address->line_2);
        $this->assertSame('Ajil', $address->city);
        $this->assertSame('67 LORONG SERI KUANTAN 65', $job->fresh()->address_line_1);
        $this->assertSame('LOT 9 PASAR BESAR', $person->fresh()->employer_address_line_1);
    }
}
