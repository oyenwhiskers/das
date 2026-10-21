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

    #[Test]
    public function related_looks_self_employed_includes_business_and_self_working(): void
    {
        $this->assertTrue(BankStatementProfileRules::relatedLooksSelfEmployed('Business'));
        $this->assertTrue(BankStatementProfileRules::relatedLooksSelfEmployed('self working'));
        $this->assertTrue(BankStatementProfileRules::relatedLooksSelfEmployed('Self-Employed'));
        $this->assertFalse(BankStatementProfileRules::relatedLooksSelfEmployed('Teacher'));
        $this->assertSame('ENTREPRENEUR', BankStatementProfileRules::SELF_EMPLOYED_OCCUPATION);
    }

    #[Test]
    public function preferred_self_employed_address_uses_mailing_then_residential(): void
    {
        $mailing = [
            'line_1' => 'Lot 9', 'line_2' => '', 'city' => 'Tanah Merah',
            'postcode' => '17500', 'state' => 'Kelantan', 'country' => 'Malaysia',
        ];
        $residential = [
            'line_1' => '12 Melati', 'line_2' => '', 'city' => 'Kota Bharu',
            'postcode' => '15000', 'state' => 'Kelantan', 'country' => 'Malaysia',
        ];
        $this->assertSame('Lot 9', BankStatementProfileRules::preferredSelfEmployedAddressParts($mailing, $residential)['line_1']);
        $this->assertSame(
            '12 Melati',
            BankStatementProfileRules::preferredSelfEmployedAddressParts([], $residential)['line_1'],
        );
        $this->assertSame(
            '12 Melati',
            BankStatementProfileRules::preferredSelfEmployedAddressParts(
                ['line_1' => '', 'line_2' => '', 'city' => '', 'postcode' => '', 'state' => '', 'country' => ''],
                $residential,
            )['line_1'],
        );
    }
}
