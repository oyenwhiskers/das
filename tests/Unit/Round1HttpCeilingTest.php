<?php

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/scripts/round1/HttpCeiling.php';

class Round1HttpCeilingTest extends TestCase
{
    public function test_the_seventh_gemini_call_is_blocked_before_it_is_sent(): void
    {
        $path = storage_path('framework/round1-ceiling.json');
        $ceiling = new \Round1HttpCeiling($path, 6);
        $ceiling->write(0);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $ceiling->before('https://generativelanguage.googleapis.com/v1beta/interactions');
        }

        $this->assertSame(6, $ceiling->attempts());
        $this->expectException(RuntimeException::class);
        $ceiling->before('https://generativelanguage.googleapis.com/v1beta/interactions');
    }

    public function test_missing_token_counts_stop_the_round(): void
    {
        $result = (new \Round1HttpCeiling(storage_path('framework/unused.json'), 6))
            ->assess(['total_input_tokens' => 10], 0, 1, 'gemini-3.1-flash-lite');

        $this->assertSame('usage or cost unavailable', $result['stop']);
    }
}
