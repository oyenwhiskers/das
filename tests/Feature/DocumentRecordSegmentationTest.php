<?php

namespace Tests\Feature;

use App\Models\DocumentScan;
use App\Services\DocumentRecordOwnership;
use App\Services\GeminiDocumentReader;
use App\Services\StoreDocumentFindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use UnexpectedValueException;

class DocumentRecordSegmentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nine_applicants_with_repeated_headings_and_shared_names_stay_separate_across_out_of_order_batches(): void
    {
        Storage::fake('local');
        $scan = DocumentScan::factory()->create(['record_plan' => array_map(
            fn (int $page): array => $this->record([$page], 'Applicant on page '.$page), range(1, 9)
        )]);
        $reader = app(GeminiDocumentReader::class);
        $ranges = [];
        foreach ([9, 5, 1, 7, 3] as $start) {
            $end = min(9, $start + 1);
            $ranges[] = ['start' => $start, 'end' => $end];
            $batch = $this->batch($scan, 9, $start, $end, fn (int $page): string => 'r'.$page,
                fn (int $page): string => $page <= 2 ? 'Same Name' : 'Applicant '.$page);
            Storage::disk('local')->put('document-scans/'.$scan->id.'/extraction-batches/'.$start.'.json', json_encode($batch, JSON_THROW_ON_ERROR));
        }

        $merged = $reader->mergePdfBatches($scan, 9, $ranges);
        $finding = app(StoreDocumentFindings::class)->store($scan, $merged)->fresh();

        $this->assertSame(range(1, 9), array_column($finding->observations['pages'], 'number'));
        $this->assertCount(9, $finding->observations['entities']);
        $this->assertCount(9, $finding->observations['sections']);
        $this->assertCount(9, $finding->observations['fields']);
        $this->assertCount(9, array_unique(array_filter(array_values($finding->record_ownership['findings']))));
        $this->assertSame('Same Name', $finding->observations['entities'][0]['display_name']);
        $this->assertSame('Same Name', $finding->observations['entities'][1]['display_name']);
    }

    public function test_one_application_spans_fourteen_pages_and_keeps_related_parties_in_one_record(): void
    {
        $scan = DocumentScan::factory()->create(['record_plan' => [$this->record(range(1, 14), 'Application number 123')]]);
        $results = [];
        foreach (range(1, 13, 2) as $start) {
            $results[$start] = $this->batch($scan, 14, $start, $start + 1, fn (): string => 'r1', fn (): string => 'Main Applicant');
        }
        foreach ([['spouse', 'Spouse', 'Related Person'], ['employer', 'Employer', 'Example Company']] as [$id, $role, $name]) {
            $results[1]['entities'][] = ['id' => $id, 'type' => $id === 'employer' ? 'organization' : 'person', 'role' => $role,
                'display_name' => $name, 'related_entity_ids' => [], 'page_numbers' => [1]];
            $results[1]['fields'][] = array_replace($results[1]['fields'][0], ['id' => $id.'-name', 'label' => $role, 'entity_id' => $id,
                'raw_value' => $name, 'normalized_value' => $name]);
            foreach ([$id, $id.'-name'] as $findingId) {
                $results[1]['record_ownership'][] = ['finding_id' => $findingId, 'record_id' => 'r1'];
            }
        }
        $merged = (new \ReflectionMethod(GeminiDocumentReader::class, 'mergeBatches'))->invoke(app(GeminiDocumentReader::class), $scan, $results, 14);
        $finding = app(StoreDocumentFindings::class)->store($scan, $merged)->fresh();

        $this->assertCount(1, $finding->record_ownership['records']);
        $this->assertCount(16, $finding->observations['fields']);
        $this->assertContains('Spouse', array_column($finding->observations['entities'], 'role'));
        $this->assertContains('Employer', array_column($finding->observations['entities'], 'role'));
        $this->assertSame(range(1, 14), array_column($finding->observations['pages'], 'number'));
        $this->assertTrue(collect($finding->record_ownership['findings'])->every(fn (?string $id): bool => $id === 'r1'));
    }

    public function test_two_records_can_share_one_page_and_uncertain_ownership_stays_null(): void
    {
        $scan = DocumentScan::factory()->create(['record_plan' => [
            $this->record([1], 'Upper form', ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.5]),
            $this->record([1], 'Lower form', ['x' => 0, 'y' => 0.5, 'width' => 1, 'height' => 0.5]),
        ]]);
        $batch = $this->batch($scan, 1, 1, 1, fn (int $page): string => 'r1', fn (): string => 'Same Name');
        $batch['fields'][0]['evidence']['bbox'] = ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.1];
        $batch['entities'][] = ['id' => 'person-2', 'type' => 'person', 'role' => 'Applicant', 'display_name' => 'Same Name', 'related_entity_ids' => [], 'page_numbers' => [1]];
        $batch['fields'][] = array_replace($batch['fields'][0], ['id' => 'field-2', 'entity_id' => 'person-2', 'section_id' => null,
            'evidence' => ['page_number' => 1, 'bbox' => ['x' => 0.1, 'y' => 0.6, 'width' => 0.5, 'height' => 0.1]]]);
        $batch['fields'][] = array_replace($batch['fields'][0], ['id' => 'uncertain', 'entity_id' => null, 'section_id' => null]);
        $batch['entities'][] = ['id' => 'unresolved-person', 'type' => 'person', 'role' => 'Representative', 'display_name' => null,
            'related_entity_ids' => [], 'page_numbers' => [1]];
        $batch['record_ownership'][] = ['finding_id' => 'person-2', 'record_id' => 'r2'];
        $batch['record_ownership'][] = ['finding_id' => 'field-2', 'record_id' => 'r2'];
        $batch['record_ownership'][] = ['finding_id' => 'uncertain', 'record_id' => null];
        $batch['record_ownership'][] = ['finding_id' => 'unresolved-person', 'record_id' => null];

        $finding = app(StoreDocumentFindings::class)->store($scan, $batch)->fresh();
        $this->assertSame('r1', $finding->record_ownership['findings']['field-1']);
        $this->assertSame('r2', $finding->record_ownership['findings']['field-2']);
        $this->assertNull($finding->record_ownership['findings']['uncertain']);
        $this->assertNull($finding->record_ownership['findings']['unresolved-person']);
    }

    public function test_cross_record_entity_attribution_is_rejected(): void
    {
        $scan = DocumentScan::factory()->create(['record_plan' => [
            $this->record([1], 'First applicant'), $this->record([2], 'Second applicant'),
        ]]);
        $batch = $this->batch($scan, 2, 1, 2, fn (int $page): string => 'r'.$page, fn (int $page): string => 'Applicant '.$page);
        $batch['fields'][1]['entity_id'] = 'person-1';

        $this->expectException(UnexpectedValueException::class);
        app(StoreDocumentFindings::class)->store($scan, $batch);
    }

    public function test_missing_ownership_is_recovered_only_when_source_pages_identify_one_record(): void
    {
        $scan = DocumentScan::factory()->create(['record_plan' => [
            $this->record([1], 'First applicant'), $this->record([2], 'Second applicant'),
        ]]);
        $batch = $this->batch($scan, 2, 1, 2, fn (int $page): string => 'r'.$page, fn (int $page): string => 'Applicant '.$page);
        $batch['record_ownership'] = array_values(array_filter($batch['record_ownership'],
            fn (array $entry): bool => $entry['finding_id'] !== 'field-2'));

        $finding = app(StoreDocumentFindings::class)->store($scan, $batch)->fresh();

        $this->assertSame('r2', $finding->record_ownership['findings']['field-2']);
    }

    public function test_missing_ownership_on_shared_page_stays_unresolved(): void
    {
        $scan = DocumentScan::factory()->create(['record_plan' => [
            $this->record([1], 'First applicant', ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.5]),
            $this->record([1], 'Second applicant', ['x' => 0, 'y' => 0.5, 'width' => 1, 'height' => 0.5]),
        ]]);
        $batch = $this->batch($scan, 1, 1, 1, fn (): string => 'r1', fn (): string => 'Applicant');
        $batch['fields'][0]['evidence']['bbox'] = ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.1];
        $batch['entities'][] = ['id' => 'person-2', 'type' => 'person', 'role' => 'Applicant', 'display_name' => 'Second applicant', 'related_entity_ids' => [], 'page_numbers' => [1]];
        $batch['fields'][] = array_replace($batch['fields'][0], ['id' => 'field-2', 'section_id' => null, 'entity_id' => 'person-2',
            'evidence' => ['page_number' => 1, 'bbox' => ['x' => 0.1, 'y' => 0.6, 'width' => 0.5, 'height' => 0.1]]]);
        $batch['record_ownership'][] = ['finding_id' => 'person-2', 'record_id' => 'r2'];
        $batch['record_ownership'][] = ['finding_id' => 'field-2', 'record_id' => 'r2'];
        $batch['fields'][] = array_replace($batch['fields'][0], ['id' => 'uncertain', 'section_id' => null, 'entity_id' => null]);
        $batch['record_ownership'] = array_values(array_filter($batch['record_ownership'],
            fn (array $entry): bool => $entry['finding_id'] !== 'uncertain'));

        $finding = app(StoreDocumentFindings::class)->store($scan, $batch)->fresh();

        $this->assertNull($finding->record_ownership['findings']['uncertain']);
    }

    public function test_two_applicants_in_one_ai_batch_keep_their_record_ids(): void
    {
        Storage::fake('local');
        $scan = DocumentScan::factory()->create(['record_plan' => [
            $this->record([1], 'First applicant'), $this->record([2], 'Second applicant'),
        ]]);
        $batch = $this->batch($scan, 2, 1, 2, fn (int $page): string => 'r'.$page, fn (int $page): string => 'Applicant '.$page);
        Storage::disk('local')->put('document-scans/'.$scan->id.'/extraction-batches/1.pdf', '%PDF-1.4 sample');
        config()->set('services.gemini.api_key', 'test-key');
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($batch) {
            $this->assertStringContainsString('"id":"r1"', $request['input'][1]['text']);
            $this->assertStringContainsString('"id":"r2"', $request['input'][1]['text']);

            return Http::response(['status' => 'completed', 'output_text' => json_encode($batch, JSON_THROW_ON_ERROR)]);
        });

        app(GeminiDocumentReader::class)->extractPdfBatch($scan, 2, 1, 2);
        $cached = json_decode(Storage::disk('local')->get('document-scans/'.$scan->id.'/extraction-batches/1.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['r1', 'r2'], [$cached['record_ownership'][0]['record_id'], $cached['record_ownership'][3]['record_id']]);
    }

    public function test_shared_page_assignment_outside_its_record_region_is_rejected(): void
    {
        $scan = DocumentScan::factory()->create(['record_plan' => [
            $this->record([1], 'Upper', ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.5]),
            $this->record([1], 'Lower', ['x' => 0, 'y' => 0.5, 'width' => 1, 'height' => 0.5]),
        ]]);
        $batch = $this->batch($scan, 1, 1, 1, fn (): string => 'r1', fn (): string => 'Applicant');
        $batch['fields'][0]['evidence']['bbox'] = ['x' => 0.1, 'y' => 0.7, 'width' => 0.5, 'height' => 0.1];

        $this->expectException(UnexpectedValueException::class);
        app(StoreDocumentFindings::class)->store($scan, $batch);
    }

    public function test_unrelated_document_without_an_applicant_keeps_its_findings_unassigned(): void
    {
        $scan = DocumentScan::factory()->create(['mime_type' => 'image/jpeg', 'record_plan' => []]);
        $batch = $this->batch($scan, 1, 1, 1, fn (): ?string => null, fn (): string => 'Unrelated notice');

        $finding = app(StoreDocumentFindings::class)->store($scan, $batch)->fresh();

        $this->assertSame([], $finding->record_ownership['records']);
        $this->assertTrue(collect($finding->record_ownership['findings'])->every(fn (?string $id): bool => $id === null));
        $this->assertCount(3, $finding->observations['issues']);
    }

    public function test_uncovered_page_cannot_be_assigned_to_a_record(): void
    {
        $scan = DocumentScan::factory()->create(['record_plan' => [$this->record([1], 'Applicant')]]);
        $batch = $this->batch($scan, 2, 1, 2, fn (): ?string => null, fn (): string => 'Applicant');
        $batch['record_ownership'] = [];

        $finding = app(StoreDocumentFindings::class)->store($scan, $batch)->fresh();

        $this->assertSame('r1', $finding->record_ownership['findings']['field-1']);
        $this->assertNull($finding->record_ownership['findings']['field-2']);
        $this->assertContains('field-2', array_column($finding->observations['issues'], 'finding_id'));
    }

    public function test_malformed_plan_is_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        app(DocumentRecordOwnership::class)->plan([$this->record([2, 2], 'Applicant')], 2);
    }

    public function test_repeated_headings_do_not_join_sections_across_batches(): void
    {
        $scan = DocumentScan::factory()->create(['record_plan' => [$this->record([1, 2], 'Applicant')]]);
        $results = [
            1 => $this->batch($scan, 2, 1, 1, fn (): string => 'r1', fn (): string => 'Same Name'),
            2 => $this->batch($scan, 2, 2, 2, fn (): string => 'r1', fn (): string => 'Same Name'),
        ];

        $merged = (new \ReflectionMethod(GeminiDocumentReader::class, 'mergeBatches'))->invoke(app(GeminiDocumentReader::class), $scan, $results, 2);

        $this->assertCount(2, $merged['sections']);
        $this->assertNotSame($merged['pages'][0]['section_ids'], $merged['pages'][1]['section_ids']);
    }

    private function record(array $pages, string $anchor, ?array $box = null): array
    {
        return ['anchor' => $anchor, 'regions' => array_map(
            fn (int $page): array => ['page_number' => $page, 'bbox' => $box], $pages
        )];
    }

    private function batch(DocumentScan $scan, int $count, int $start, int $end, callable $owner, callable $name): array
    {
        $payload = [
            'schema_version' => '1.0',
            'document' => ['reference' => (string) $scan->id, 'page_count' => $count, 'reading_status' => 'complete', 'limitations' => null],
            'pages' => [], 'sections' => [], 'entities' => [], 'fields' => [], 'tables' => [], 'content_blocks' => [], 'issues' => [], 'record_ownership' => [],
        ];
        foreach (range($start, $end) as $page) {
            $payload['pages'][] = ['number' => $page, 'reading_status' => 'complete', 'section_ids' => ['section-'.$page], 'issues' => []];
            $payload['sections'][] = ['id' => 'section-'.$page, 'heading' => 'Personal details', 'parent_id' => null, 'page_numbers' => [$page], 'order' => $page];
            $payload['entities'][] = ['id' => 'person-'.$page, 'type' => 'person', 'role' => 'Applicant', 'display_name' => $name($page), 'related_entity_ids' => [], 'page_numbers' => [$page]];
            $payload['fields'][] = ['id' => 'field-'.$page, 'label' => 'Name', 'type' => 'text', 'section_id' => 'section-'.$page, 'entity_id' => 'person-'.$page,
                'raw_value' => $name($page), 'normalized_value' => $name($page), 'answer_status' => 'answered', 'selection_mode' => null, 'options' => [], 'evidence' => ['page_number' => $page], 'notes' => null];
            foreach (['section-'.$page, 'person-'.$page, 'field-'.$page] as $id) {
                $payload['record_ownership'][] = ['finding_id' => $id, 'record_id' => $owner($page)];
            }
        }

        return $payload;
    }
}
