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
    public function resolve_keeps_clear_ticks_and_gates_self_employed_on_keywords(): void
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
            'other',
            BankStatementProfileRules::resolveApplicantEmploymentKind('other', null, null, 'OIL & GAS'),
        );
        $this->assertNull(BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, null));
        $this->assertNull(BankStatementProfileRules::resolveApplicantEmploymentKind('ambiguous', null, ''));
        $this->assertNull(
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, null, 'OIL & GAS'),
        );
        $this->assertSame(
            'employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, 'Maybank'),
        );
        $this->assertSame(
            'employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, 'Petronas', 'OIL & GAS'),
        );
        $this->assertSame(
            'self_employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, 'self_employed', 'Maybank'),
        );
        $this->assertSame(
            'self_employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, null, 'Business'),
        );
        $this->assertSame(
            'self_employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, null, 'kerja sendiri'),
        );
        $this->assertSame(
            'self_employed',
            BankStatementProfileRules::resolveApplicantEmploymentKind(null, null, null, 'bekerja sendiri'),
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
        $this->assertTrue(BankStatementProfileRules::relatedLooksSelfEmployed('bekerja sendiri'));
        $this->assertTrue(BankStatementProfileRules::relatedLooksSelfEmployed('kerja sendiri'));
        $this->assertTrue(BankStatementProfileRules::relatedLooksSelfEmployed('Trader'));
        $this->assertTrue(BankStatementProfileRules::relatedLooksSelfEmployed('berniaga'));
        $this->assertTrue(BankStatementProfileRules::relatedLooksSelfEmployed('freelancer'));
        $this->assertFalse(BankStatementProfileRules::relatedLooksSelfEmployed('Teacher'));
        $this->assertFalse(BankStatementProfileRules::relatedLooksSelfEmployed('OIL & GAS'));
        $this->assertFalse(BankStatementProfileRules::relatedLooksSelfEmployed('Business Analyst'));
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

    #[Test]
    public function statement_branch_keeps_labeled_bank_office_and_drops_uncertain(): void
    {
        $mailing = [
            'line_1' => '12 Jalan Melati', 'line_2' => '', 'city' => 'Kuala Lumpur',
            'postcode' => '50000', 'state' => 'Wilayah Persekutuan', 'country' => 'Malaysia',
        ];
        $this->assertSame(
            'Jalan Ampang',
            BankStatementProfileRules::confidentStatementBranch('Jalan Ampang', 'Branch: Jalan Ampang', $mailing, 'Maybank'),
        );
        $this->assertSame(
            'KL Main Branch',
            BankStatementProfileRules::confidentStatementBranch('KL Main Branch', 'KL Main Branch', $mailing),
        );
        $this->assertSame(
            'Cawangan Kota Bharu',
            BankStatementProfileRules::confidentStatementBranch('Cawangan Kota Bharu', 'Cawangan Kota Bharu', $mailing),
        );
        $this->assertNull(BankStatementProfileRules::confidentStatementBranch('Jalan Ampang', 'Jalan Ampang', $mailing));
        $this->assertNull(BankStatementProfileRules::confidentStatementBranch('Kuala Lumpur', 'Branch: Kuala Lumpur', $mailing));
        $this->assertNull(BankStatementProfileRules::confidentStatementBranch('12 Jalan Melati', 'Branch: 12 Jalan Melati', $mailing));
        $this->assertNull(BankStatementProfileRules::confidentStatementBranch('Maybank', 'Branch: Maybank', $mailing, 'Maybank'));
        $this->assertNull(BankStatementProfileRules::confidentStatementBranch('', 'Branch:', $mailing));
    }

    #[Test]
    public function statement_branch_rejects_hq_letterhead_even_when_labeled_branch(): void
    {
        $mailing = [
            'line_1' => '12 Jalan Melati', 'line_2' => '', 'city' => 'Kota Bharu',
            'postcode' => '15000', 'state' => 'Kelantan', 'country' => 'Malaysia',
        ];
        $this->assertTrue(BankStatementProfileRules::looksLikeBankHeadquarters(
            'Menara Maybank, 100 Jalan Tun Perak',
            'Branch: Menara Maybank, 100 Jalan Tun Perak',
        ));
        $this->assertTrue(BankStatementProfileRules::looksLikeBankHeadquarters(
            'Level 17, Menara CIMB',
            'Head Office Level 17, Menara CIMB',
        ));
        $this->assertTrue(BankStatementProfileRules::looksLikeBankHeadquarters('HQ Kuala Lumpur', 'Branch HQ Kuala Lumpur'));
        $this->assertFalse(BankStatementProfileRules::looksLikeBankHeadquarters('Jalan Ampang', 'Branch: Jalan Ampang'));
        $this->assertNull(BankStatementProfileRules::confidentStatementBranch(
            'Menara Maybank, 100 Jalan Tun Perak',
            'Branch: Menara Maybank, 100 Jalan Tun Perak',
            $mailing,
            'Maybank',
        ));
        $this->assertNull(BankStatementProfileRules::confidentStatementBranch(
            'Level 17, Menara CIMB',
            'Branch: Level 17, Menara CIMB',
            $mailing,
            'CIMB',
        ));
        $this->assertNull(BankStatementProfileRules::confidentStatementBranch(
            'Ibu Pejabat',
            'Branch: Ibu Pejabat',
            $mailing,
        ));
        $this->assertSame(
            'Cawangan Tanah Merah',
            BankStatementProfileRules::confidentStatementBranch('Cawangan Tanah Merah', 'Cawangan Tanah Merah', $mailing),
        );
    }

    #[Test]
    public function drop_uncertain_statement_branches_removes_guessed_branch_only(): void
    {
        $values = [
            ['field_id' => 'addresses[mailing].line_1', 'row_ref' => null, 'state' => 'provided', 'value' => '12 Jalan Melati', 'evidence_text' => '12 Jalan Melati'],
            ['field_id' => 'addresses[mailing].city', 'row_ref' => null, 'state' => 'provided', 'value' => 'Kuala Lumpur', 'evidence_text' => 'Kuala Lumpur'],
            ['field_id' => 'bank_accounts[].bank_name', 'row_ref' => 'acct-1', 'state' => 'provided', 'value' => 'Maybank', 'evidence_text' => 'Maybank'],
            ['field_id' => 'bank_accounts[].branch_address', 'row_ref' => 'acct-1', 'state' => 'provided', 'value' => 'Jalan Ampang', 'evidence_text' => 'Branch: Jalan Ampang'],
            ['field_id' => 'bank_accounts[].branch_address', 'row_ref' => 'acct-2', 'state' => 'provided', 'value' => 'Kuala Lumpur', 'evidence_text' => 'Kuala Lumpur'],
        ];
        $kept = collect(BankStatementProfileRules::dropUncertainStatementBranches($values))
            ->where('field_id', 'bank_accounts[].branch_address')
            ->pluck('value', 'row_ref')
            ->all();

        $this->assertSame(['acct-1' => 'Jalan Ampang'], $kept);
    }
}
