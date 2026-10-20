<?php

namespace Tests\Feature;

use App\Enums\AttachmentScanStatus;
use App\Enums\AttachmentType;
use App\Enums\IdentificationType;
use App\Jobs\ProcessAttachmentScan;
use App\Livewire\Attachments\Index;
use App\Livewire\Attachments\Show;
use App\Livewire\Customers\Attachments;
use App\Models\AttachmentScan;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Models\User;
use App\Services\AttachmentFiling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AttachmentFilingTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_upload_attachment_and_dispatch_processing(): void
    {
        Storage::fake('local');
        Queue::fake();
        $staff = User::factory()->staff()->create();

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('files', [UploadedFile::fake()->create('ic.pdf', 100, 'application/pdf')])
            ->call('processDocuments')
            ->assertHasNoErrors()
            ->assertSet('files', []);

        $scan = AttachmentScan::query()->sole();
        $this->assertSame(AttachmentScanStatus::Pending, $scan->status);
        $this->assertSame($staff->id, $scan->uploaded_by);
        $this->assertSame('ic.pdf', $scan->original_filename);
        $this->assertStringStartsWith('attachment-scans/', $scan->storage_path);
        Storage::disk('local')->assertExists($scan->storage_path);
        Queue::assertPushed(ProcessAttachmentScan::class, fn (ProcessAttachmentScan $job): bool => $job->attachmentScanId === $scan->id);
    }

    public function test_staff_can_queue_many_attachments_as_separate_jobs(): void
    {
        Storage::fake('local');
        Queue::fake();
        $staff = User::factory()->staff()->create();

        $files = [];
        for ($i = 1; $i <= 5; $i++) {
            $files[] = UploadedFile::fake()->create("file-{$i}.pdf", 50, 'application/pdf');
        }

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('files', $files)
            ->call('processDocuments')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('attachment_scans', 5);
        Queue::assertPushed(ProcessAttachmentScan::class, 5);
        $this->assertEqualsCanonicalizing(
            AttachmentScan::query()->orderBy('id')->pluck('id')->all(),
            collect(Queue::pushed(ProcessAttachmentScan::class))->map(fn (ProcessAttachmentScan $job) => $job->attachmentScanId)->all()
        );
    }

    public function test_dropping_more_files_appends_instead_of_replacing(): void
    {
        Storage::fake('local');
        $staff = User::factory()->staff()->create();

        $first = [
            UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('b.pdf', 50, 'application/pdf'),
        ];
        $second = [
            UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('d.pdf', 50, 'application/pdf'),
        ];

        $component = Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('incoming', $first)
            ->assertCount('files', 2)
            ->set('incoming', $second)
            ->assertCount('files', 4)
            ->assertSet('incoming', []);

        $names = collect($component->get('files'))->map(fn ($file) => $file->getClientOriginalName())->all();
        $this->assertSame(['a.pdf', 'b.pdf', 'c.pdf', 'd.pdf'], $names);
    }

    public function test_unique_ic_auto_attaches_to_the_matching_customer(): void
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');

        $staff = User::factory()->staff()->create();
        $customer = $this->customerWithNric($staff, 'Amina Noor', '900101015678');

        $scan = AttachmentScan::factory()->for($staff, 'uploader')->create([
            'storage_path' => 'attachment-scans/ic.pdf',
            'status' => AttachmentScanStatus::Pending,
        ]);
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 fake');

        $this->fakeGemini([
            'attachment_type' => 'ic_pdf',
            'full_legal_name' => 'Amina Noor',
            'identification_number' => '900101-01-5678',
            'readable' => true,
            'limitations' => null,
        ]);

        (new ProcessAttachmentScan($scan->id))->handle(app(AttachmentFiling::class));

        $scan->refresh();
        $this->assertSame(AttachmentScanStatus::Attached, $scan->status);
        $this->assertSame(AttachmentType::IcPdf, $scan->attachment_type);
        $this->assertSame($customer->id, $scan->customer_id);
        $this->assertNull($scan->matched_by);
        $this->assertNotNull($scan->attached_at);
        $this->assertSame('Amina Noor', $scan->extracted_name);
    }

    public function test_unknown_ic_needs_review_and_manual_match_attaches(): void
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');

        $staff = User::factory()->staff()->create();
        $customer = $this->customerWithNric($staff, 'Nora Azmi', '880202025432');

        $scan = AttachmentScan::factory()->for($staff, 'uploader')->create([
            'storage_path' => 'attachment-scans/signature.pdf',
            'status' => AttachmentScanStatus::Pending,
        ]);
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 fake');

        $this->fakeGemini([
            'attachment_type' => 'signature',
            'full_legal_name' => 'Unknown Person',
            'identification_number' => '010101011111',
            'readable' => true,
            'limitations' => null,
        ]);

        (new ProcessAttachmentScan($scan->id))->handle(app(AttachmentFiling::class));

        $scan->refresh();
        $this->assertSame(AttachmentScanStatus::NeedsReview, $scan->status);
        $this->assertSame(AttachmentType::Signature, $scan->attachment_type);
        $this->assertNull($scan->customer_id);

        Livewire::actingAs($staff)
            ->test(Show::class, ['attachmentScan' => $scan])
            ->assertSee('Needs review')
            ->set('customerQuery', 'Nora')
            ->call('selectMatchCustomer', $customer->id)
            ->call('confirmMatch')
            ->assertHasNoErrors();

        $scan->refresh();
        $this->assertSame(AttachmentScanStatus::Attached, $scan->status);
        $this->assertSame($customer->id, $scan->customer_id);
        $this->assertSame($staff->id, $scan->matched_by);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->assertSee('Nora Azmi');
    }

    public function test_customer_attachments_tab_lists_filed_files_by_type(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = $this->customerWithNric($staff, 'Amina Noor', '900101015678');

        AttachmentScan::factory()->for($staff, 'uploader')->for($customer)->create([
            'original_filename' => 'amina-ic.pdf',
            'attachment_type' => AttachmentType::IcPdf,
            'status' => AttachmentScanStatus::Attached,
            'attached_at' => now(),
        ]);
        AttachmentScan::factory()->for($staff, 'uploader')->for($customer)->create([
            'original_filename' => 'amina-cds.pdf',
            'attachment_type' => AttachmentType::CdsBimbsec,
            'status' => AttachmentScanStatus::Attached,
            'attached_at' => now(),
        ]);

        Livewire::actingAs($staff)
            ->test(Attachments::class, ['customer' => $customer])
            ->assertSee('IC PDF')
            ->assertSee('amina-ic.pdf')
            ->assertSee('CDS Maintenance (BIMBSEC)')
            ->assertSee('amina-cds.pdf')
            ->assertSee('No Signature yet');
    }

    public function test_customer_attachments_tab_accepts_manual_upload_for_a_type(): void
    {
        Storage::fake('local');
        $staff = User::factory()->staff()->create();
        $customer = $this->customerWithNric($staff, 'Amina Noor', '900101015678');

        Livewire::actingAs($staff)
            ->test(Attachments::class, ['customer' => $customer])
            ->set('uploads.ic_pdf', UploadedFile::fake()->create('manual-ic.pdf', 100, 'application/pdf'))
            ->assertHasNoErrors();

        $scan = AttachmentScan::query()->sole();
        $this->assertSame(AttachmentScanStatus::Attached, $scan->status);
        $this->assertSame(AttachmentType::IcPdf, $scan->attachment_type);
        $this->assertSame($customer->id, $scan->customer_id);
        $this->assertSame($staff->id, $scan->matched_by);
        $this->assertSame('manual-ic.pdf', $scan->original_filename);
        Storage::disk('local')->assertExists($scan->storage_path);
    }

    /** @param array<string, mixed> $payload */
    private function fakeGemini(array $payload): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'output_text' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])]);
    }

    private function customerWithNric(User $user, string $name, string $nric): Customer
    {
        $customer = Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => $name,
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => $nric,
        ]);
        CustomerIdentification::query()->create([
            'customer_id' => $customer->id,
            'type' => IdentificationType::Nric,
            'number' => $nric,
            'normalized_number' => $nric,
            'is_primary' => true,
        ]);

        return $customer;
    }
}
