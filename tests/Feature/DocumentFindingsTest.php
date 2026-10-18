<?php

namespace Tests\Feature;

use App\Enums\DocumentScanStatus;
use App\Models\Customer;
use App\Models\DocumentScan;
use App\Services\StoreDocumentFindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\DocumentFindingsFixtures;
use Tests\TestCase;

class DocumentFindingsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function documentTypes(): array
    {
        return [
            'identity without choices' => ['identity'],
            'payslip with two owners' => ['payslip'],
            'bank statement with rows' => ['bankStatement'],
            'application with blank and choices' => ['application'],
            'conflicting and uncertain form' => ['conflictingForm'],
            'contract without questions' => ['contract'],
        ];
    }

    #[DataProvider('documentTypes')]
    public function test_valid_document_types_are_stored_and_retrieved_without_changing_phase_one(string $fixture): void
    {
        $scan = DocumentScan::factory()->create([
            'status' => DocumentScanStatus::Completed,
            'document_type' => 'Original type',
            'summary' => 'Phase 1 summary',
        ]);
        $payload = DocumentFindingsFixtures::$fixture($scan->id);

        $finding = app(StoreDocumentFindings::class)->store($scan, $payload);

        $this->assertSame('1.0', $finding->schema_version);
        $this->assertSame($payload, $finding->fresh()->observations);
        $this->assertSame('completed', $scan->fresh()->extraction_status);
        $this->assertSame(DocumentScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame('Phase 1 summary', $scan->fresh()->summary);
        $this->assertSame($finding->id, $scan->fresh()->findings->id);
    }

    public function test_phase_one_summary_does_not_mark_scan_as_extracted(): void
    {
        $scan = DocumentScan::factory()->create(['status' => DocumentScanStatus::Completed, 'summary' => 'Known summary']);

        $this->assertSame('not_started', $scan->fresh()->extraction_status);
        $this->assertSame(null, $scan->fresh()->findings);
    }

    public function test_original_findings_cannot_be_overwritten(): void
    {
        $scan = DocumentScan::factory()->create();
        $payload = DocumentFindingsFixtures::identity($scan->id);
        app(StoreDocumentFindings::class)->store($scan, $payload);

        $this->expectException(LogicException::class);
        app(StoreDocumentFindings::class)->store($scan, DocumentFindingsFixtures::contract($scan->id));
    }

    public function test_complex_blank_form_preserves_every_printed_option_and_owner(): void
    {
        $scan = DocumentScan::factory()->create();
        $payload = DocumentFindingsFixtures::complexBlankForm($scan->id);

        $observations = app(StoreDocumentFindings::class)->store($scan, $payload)->fresh()->observations;

        $choices = collect($observations['fields'])->where('type', 'choice')->keyBy('id');
        foreach (['annual-income', 'joint-income', 'net-assets', 'investment-portfolio'] as $id) {
            $this->assertCount(6, $choices[$id]['options']);
            $this->assertSame('unanswered', $choices[$id]['answer_status']);
            $this->assertNull($choices[$id]['normalized_value']);
        }
        $this->assertCount(11, $choices['source-of-wealth']['options']);
        $this->assertSame('Others (specify)', $choices['source-of-wealth']['options'][10]['label']);
        $this->assertSame('unmarked', $choices['fatca']['options'][1]['mark_state']);
        $this->assertSame('spouse', collect($observations['fields'])->firstWhere('id', 'spouse-name')['entity_id']);
        $this->assertNull(collect($observations['fields'])->firstWhere('id', 'witness-name')['entity_id']);
        $this->assertSame('applicant', $observations['tables'][0]['entity_id']);
    }

    public function test_explicit_no_and_multiple_marks_have_distinct_meanings(): void
    {
        $scan = DocumentScan::factory()->create();
        $payload = DocumentFindingsFixtures::complexBlankForm($scan->id);
        $payload['fields'][5]['options'][1]['mark_state'] = 'marked';
        $payload['fields'][5]['normalized_value'] = ['no'];
        $payload['fields'][5]['answer_status'] = 'answered';
        $payload['fields'][0]['options'][0]['mark_state'] = 'marked';
        $payload['fields'][0]['options'][1]['mark_state'] = 'marked';
        $payload['fields'][0]['answer_status'] = 'conflict';
        $payload['issues'][] = [
            'id' => 'bracket-conflict', 'type' => 'conflicting_selection', 'severity' => 'warning',
            'description' => 'Two annual income brackets are marked.', 'finding_id' => 'annual-income',
            'page_number' => 1, 'resolution_status' => 'open',
        ];

        $observations = app(StoreDocumentFindings::class)->store($scan, $payload)->fresh()->observations;

        $this->assertSame(['no'], $observations['fields'][5]['normalized_value']);
        $this->assertSame('unmarked', $observations['fields'][5]['options'][0]['mark_state']);
        $this->assertSame('conflict', $observations['fields'][0]['answer_status']);
        $this->assertNull($observations['fields'][0]['normalized_value']);
    }

    public function test_unanswered_choice_rejects_a_marked_or_uncertain_option(): void
    {
        foreach (['marked', 'ambiguous'] as $markState) {
            $scan = DocumentScan::factory()->create();
            $payload = DocumentFindingsFixtures::complexBlankForm($scan->id);
            $payload['fields'][5]['options'][1]['mark_state'] = $markState;

            try {
                app(StoreDocumentFindings::class)->store($scan, $payload);
                $this->fail('An unanswered choice with a '.$markState.' option was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('fields.5.answer_status', $exception->errors());
                $this->assertNull($scan->fresh()->findings);
            }
        }
    }

    public function test_empty_table_unanswered_choice_and_spanning_section_are_valid(): void
    {
        $scan = DocumentScan::factory()->create();
        $payload = DocumentFindingsFixtures::application($scan->id);
        $payload['document']['page_count'] = 2;
        $payload['pages'][] = ['number' => 2, 'reading_status' => 'partial', 'section_ids' => ['personal'], 'issues' => ['Faded ink']];
        $payload['sections'][0]['page_numbers'] = [1, 2];
        $payload['fields'][3]['answer_status'] = 'unanswered';
        $payload['fields'][3]['normalized_value'] = null;
        foreach ($payload['fields'][3]['options'] as &$option) {
            $option['mark_state'] = 'unmarked';
        }
        unset($option);
        $payload['tables'][] = [
            'id' => 'empty-schedule', 'section_id' => 'personal', 'entity_id' => 'applicant',
            'columns' => [['id' => 'year', 'heading' => 'Year']], 'rows' => [], 'evidence' => ['page_number' => 2, 'bbox' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.5, 'height' => 0.3]],
        ];

        $finding = app(StoreDocumentFindings::class)->store($scan, $payload);

        $this->assertSame([], $finding->fresh()->observations['tables'][0]['rows']);
        $this->assertSame(null, $finding->fresh()->observations['fields'][3]['normalized_value']);
        $this->assertSame([1, 2], $finding->fresh()->observations['sections'][0]['page_numbers']);
    }

    public function test_bbox_contract_accepts_valid_missing_and_null_and_rejects_malformed(): void
    {
        $customer = Customer::factory()->create();
        $customerBefore = $customer->fresh()->getAttributes();
        $scan = DocumentScan::factory()->create();
        $payload = DocumentFindingsFixtures::identity($scan->id);
        $payload['fields'][0]['evidence']['bbox'] = ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.1];
        $payload['fields'][1]['evidence']['bbox'] = null;
        unset($payload['fields'][1]['evidence']['region_ref']);

        $finding = app(StoreDocumentFindings::class)->store($scan, $payload);

        $observations = $finding->fresh()->observations;
        $this->assertSame(['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.1], $observations['fields'][0]['evidence']['bbox']);
        $this->assertArrayNotHasKey('bbox', $observations['fields'][1]['evidence']);
        $this->assertSame(1, $observations['fields'][1]['evidence']['page_number']);
        $this->assertCount(2, $observations['fields']);
        $this->assertSame($customerBefore, $customer->fresh()->getAttributes());

        $missingBboxScan = DocumentScan::factory()->create();
        $missingPayload = DocumentFindingsFixtures::identity($missingBboxScan->id);
        $this->assertArrayNotHasKey('bbox', $missingPayload['fields'][0]['evidence']);
        app(StoreDocumentFindings::class)->store($missingBboxScan, $missingPayload);
        $this->assertArrayNotHasKey('bbox', $missingBboxScan->fresh()->findings->observations['fields'][0]['evidence']);

        foreach ([
            'list' => [0.1, 0.2, 0.3, 0.1],
            'incomplete' => ['x' => 0.1, 'y' => 0.2],
            'out_of_range' => ['x' => 0.1, 'y' => 0.2, 'width' => 1.5, 'height' => 0.1],
            'overflow' => ['x' => 0.9, 'y' => 0.1, 'width' => 0.2, 'height' => 0.2],
        ] as $case => $bbox) {
            $invalidScan = DocumentScan::factory()->create();
            $invalidPayload = DocumentFindingsFixtures::identity($invalidScan->id);
            $invalidPayload['fields'][0]['evidence']['bbox'] = $bbox;

            try {
                app(StoreDocumentFindings::class)->store($invalidScan, $invalidPayload);
                $this->fail("Malformed bbox case {$case} was stored.");
            } catch (ValidationException $exception) {
                $this->assertTrue(
                    collect($exception->errors())->keys()->contains(
                        fn (string $key): bool => str_starts_with($key, 'fields.0.evidence.bbox')
                    ),
                    "Malformed bbox case {$case} did not report a bbox error."
                );
            }

            $this->assertDatabaseMissing('document_findings', ['document_scan_id' => $invalidScan->id]);
            $this->assertSame('not_started', $invalidScan->fresh()->extraction_status);
        }

        $this->assertSame($customerBefore, $customer->fresh()->getAttributes());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidCases(): array
    {
        return [
            'version' => ['version', 'schema_version'],
            'missing array' => ['missing_array', 'sections'],
            'foreign scan' => ['foreign_scan', 'document.reference'],
            'page outside range' => ['page', 'fields.0.evidence.page_number'],
            'duplicate finding id' => ['duplicate_id', 'fields.1.id'],
            'unknown entity' => ['entity', 'fields.0.entity_id'],
            'unknown section' => ['section', 'fields.0.section_id'],
            'invalid field type' => ['type', 'fields.0.type'],
            'invalid date' => ['date', 'fields.1.normalized_value'],
            'numeric money' => ['money', 'fields.2.normalized_value'],
            'invalid table column' => ['table', 'tables.0.rows.0.cells.0.column_id'],
            'invalid table amount' => ['table_amount', 'tables.0.rows.0.cells.2.normalized_value'],
            'unflagged choice conflict' => ['conflict', 'fields.3.answer_status'],
            'invalid mark' => ['mark', 'fields.3.options.0.mark_state'],
            'unknown top key' => ['top_key', 'schema_version'],
            'duplicate option' => ['option', 'fields.3.options.1.id'],
            'unmarked normalized choice' => ['choice_value', 'fields.3.normalized_value'],
            'invalid bounding box' => ['bbox', 'fields.0.evidence.bbox'],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_findings_are_rejected_without_storage(string $case, string $errorKey): void
    {
        $scan = DocumentScan::factory()->create();
        $payload = match ($case) {
            'duplicate_id' => DocumentFindingsFixtures::identity($scan->id),
            'date' => DocumentFindingsFixtures::identity($scan->id),
            'money' => DocumentFindingsFixtures::payslip($scan->id),
            'table', 'table_amount' => DocumentFindingsFixtures::bankStatement($scan->id),
            'conflict', 'mark', 'option', 'choice_value' => DocumentFindingsFixtures::application($scan->id),
            default => DocumentFindingsFixtures::identity($scan->id),
        };
        match ($case) {
            'version' => $payload['schema_version'] = '2.0',
            'missing_array' => $payload['sections'] = null,
            'foreign_scan' => $payload['document']['reference'] = 'other',
            'page' => $payload['fields'][0]['evidence']['page_number'] = 2,
            'duplicate_id' => $payload['fields'][1]['id'] = 'name',
            'entity' => $payload['fields'][0]['entity_id'] = 'missing',
            'section' => $payload['fields'][0]['section_id'] = 'missing',
            'type' => $payload['fields'][0]['type'] = 'unknown',
            'date' => $payload['fields'][1]['normalized_value'] = '2026-02-31',
            'money' => $payload['fields'][2]['normalized_value']['amount'] = 4280.35,
            'table' => $payload['tables'][0]['rows'][0]['cells'][0]['column_id'] = 'missing',
            'table_amount' => $payload['tables'][0]['rows'][0]['cells'][2]['normalized_value']['amount'] = -12.50,
            'conflict' => $payload['fields'][3]['options'][0]['mark_state'] = 'marked',
            'mark' => $payload['fields'][3]['options'][0]['mark_state'] = 'maybe',
            'top_key' => $payload['customer_id'] = 123,
            'option' => $payload['fields'][3]['options'][1]['id'] = 'single',
            'choice_value' => $payload['fields'][3]['normalized_value'] = ['single'],
            'bbox' => $payload['fields'][0]['evidence']['bbox'] = ['x' => 0.9, 'y' => 0.1, 'width' => 0.2, 'height' => 0.2],
        };

        try {
            app(StoreDocumentFindings::class)->store($scan, $payload);
            $this->fail('Invalid findings were stored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        $this->assertDatabaseCount('document_findings', 0);
        $this->assertSame('not_started', $scan->fresh()->extraction_status);
    }
}
