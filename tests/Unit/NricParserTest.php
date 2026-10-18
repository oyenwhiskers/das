<?php

namespace Tests\Unit;

use App\Services\NricParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NricParserTest extends TestCase
{
    private NricParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new NricParser;
    }

    #[Test]
    public function it_parses_valid_nric_with_dashes(): void
    {
        $result = $this->parser->parse('900101-14-1235');

        $this->assertSame([
            'date_of_birth' => '1990-01-01',
            'gender' => 'Male',
            'nationality' => 'Malaysian',
        ], $result);
    }

    #[Test]
    public function it_parses_female_from_even_last_digit(): void
    {
        $result = $this->parser->parse('900101141234');

        $this->assertSame('Female', $result['gender']);
        $this->assertSame('1990-01-01', $result['date_of_birth']);
    }

    #[Test]
    public function it_uses_2000s_for_recent_two_digit_years(): void
    {
        $result = $this->parser->parse('050615145678');

        $this->assertSame('2005-06-15', $result['date_of_birth']);
        $this->assertSame('Female', $result['gender']);
    }

    #[Test]
    public function it_returns_null_for_invalid_length(): void
    {
        $this->assertNull($this->parser->parse('90010114123'));
    }

    #[Test]
    public function it_returns_null_for_invalid_date(): void
    {
        $this->assertNull($this->parser->parse('901332141234'));
    }

    #[Test]
    public function it_returns_null_for_blank_input(): void
    {
        $this->assertNull($this->parser->parse(null));
        $this->assertNull($this->parser->parse(''));
        $this->assertNull($this->parser->parse('   '));
    }

    #[Test]
    public function it_normalizes_spaces_and_dashes(): void
    {
        $this->assertSame('900101141234', $this->parser->normalize('900101-14-1234'));
        $this->assertSame('900101141234', $this->parser->normalize('900101 14 1234'));
    }
}
