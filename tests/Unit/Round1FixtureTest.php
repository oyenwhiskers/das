<?php

namespace Tests\Unit;

use App\Services\CustomerProfileSchema;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class Round1FixtureTest extends TestCase
{
    public function test_the_round_1_form_matches_the_answer_key(): void
    {
        $path = base_path('tests/Fixtures/round1/synthetic-application.pdf');
        $key = json_decode((string) file_get_contents(base_path('tests/Fixtures/round1/answer-key.json')), true, 512, JSON_THROW_ON_ERROR);
        $bytes = (string) file_get_contents($path);
        $pages = (new Fpdi)->setSourceFile($path);
        $ids = array_column(CustomerProfileSchema::descriptors(), 'path');

        $this->assertSame(4, $pages);
        $this->assertSame(4, $key['pages']);
        $this->assertSame(hash('sha256', $bytes), $key['sha256']);
        $this->assertSame(1, $key['expected_primary_applicant_count']);
        $this->assertStringNotContainsString('field_id', $bytes);
        $this->assertStringNotContainsString('customer.full_legal_name', $bytes);

        foreach (array_merge($key['values'], $key['unanswered']) as $row) {
            $this->assertContains($row['field_id'], $ids);
        }
    }
}
