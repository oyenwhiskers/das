<?php

namespace Tests\Unit;

use App\Services\BankStatementProfileRules;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BankStatementProfileRulesTest extends TestCase
{
    #[Test]
    public function clear_employment_kind_prefers_arrangement_and_rejects_noise(): void
    {
        $this->assertSame('employed', BankStatementProfileRules::clearEmploymentKind('self_employed', 'employed'));
        $this->assertSame('self_employed', BankStatementProfileRules::clearEmploymentKind('self_employed', null));
        $this->assertSame('retired', BankStatementProfileRules::clearEmploymentKind('retired', ''));
        $this->assertNull(BankStatementProfileRules::clearEmploymentKind('ambiguous', null));
        $this->assertNull(BankStatementProfileRules::clearEmploymentKind(null, null));
        $this->assertNull(BankStatementProfileRules::clearEmploymentKind('', ''));
    }

    #[Test]
    public function resolve_keeps_clear_ticks_and_falls_back_on_employer_name(): void
    {
        $this->assertSame(
            'self_employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind('self_employed', null, 'Pasar Besar'),
        );
        $this->assertSame(
            'employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind('employed', null, null),
        );
        $this->assertSame(
            'employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, 'employed', ''),
        );
        $this->assertSame(
            'retired',
            BankStatementProfileRules::resolveApplicantEmploymentKind('retired', null, null),
        );
        $this->assertSame(
            'self_employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, null),
        );
        $this->assertSame(
            'self_employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind('ambiguous', null, ''),
        );
        $this->assertSame(
            'employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, 'Maybank'),
        );
        $this->assertSame(
            'self_employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, 'self_employed', 'Maybank'),
        );
    }

    #[Test]
    public function copy_mobile_into_blank_employer_phone_only(): void
    {
        $this->assertSame(
            ['general_telephone' => '0123456789'],
            BankStatementProfileRules::copyMobileIntoEmploymentPhoneIfBlank([], '0123456789'),
        );
        $this->assertSame(
            ['general_telephone' => '03-111'],
            BankStatementProfileRules::copyMobileIntoEmploymentPhoneIfBlank(['general_telephone' => '03-111'], '0123456789'),
        );
        $this->assertSame([], BankStatementProfileRules::copyMobileIntoEmploymentPhoneIfBlank([], null));
        $this->assertSame([], BankStatementProfileRules::copyMobileIntoEmploymentPhoneIfBlank([], ''));
    }
}
