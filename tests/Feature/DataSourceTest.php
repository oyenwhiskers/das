<?php

namespace Tests\Feature;

use App\Livewire\Customers\DataSource;
use App\Models\Customer;
use App\Models\DocumentScan;
use App\Models\ExtractedProfile;
use App\Models\ExtractedProfileMerge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DataSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_profile_shows_data_source_tab_and_merge_log(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Amina Noor']);
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'original_filename' => 'account-opening.pdf',
        ]);
        $profile = ExtractedProfile::query()->create([
            'document_scan_id' => $scan->id,
            'record_ref' => 'r1',
            'source_pages' => [1],
            'field_schema_hash' => str_repeat('a', 64),
            'field_schema_version' => '1',
            'contract_version' => '1',
            'original_projection' => [],
            'reviewed_projection' => [],
        ]);
        ExtractedProfileMerge::query()->create([
            'submission_key' => 'data-source-test',
            'extracted_profile_id' => $profile->id,
            'customer_id' => $customer->id,
            'reviewer_revision' => 0,
            'strategy' => 'overwrite',
            'changes' => [['field_id' => 'customer.marital_status', 'result' => 'add']],
            'merged_by' => $staff->id,
            'created_at' => now(),
        ]);

        $this->actingAs($staff)->get(route('customers.show', $customer).'?tab=data-source')
            ->assertOk()
            ->assertSee('Data Source')
            ->assertSee('account-opening.pdf')
            ->assertSee('Overwrite and fill empty')
            ->assertSee('Open original')
            ->assertSee(route('documents.file', $scan), false);

        $scanUrl = route('documents.show', ['documentScan' => $scan, 'q' => 'Amina Noor']);

        Livewire::actingAs($staff)->test(DataSource::class, ['customer' => $customer])
            ->assertSee('account-opening.pdf')
            ->assertSee('r1')
            ->assertSee('1 field changed')
            ->assertSee('View scan')
            ->assertSee($scanUrl, false);
    }

    public function test_data_source_empty_state_when_no_merges(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($staff)->test(DataSource::class, ['customer' => $customer])
            ->assertSee('No scanned documents have been merged into this profile yet.');
    }
}
