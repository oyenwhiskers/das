<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Enums\IdentificationType;
use App\Enums\RepresentativeRole;
use App\Models\BimbsecApplication;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Models\User;
use App\Services\Bimbsec\CorporateGenerator;
use App\Services\CustomerCreator;
use App\Services\CustomerProfileWrites;
use Illuminate\Database\Seeder;
use RuntimeException;

class CorporateCustomerSeeder extends Seeder
{
    /** Stable demo BRN — 11 chars to fit the corporate form character cells. */
    private const BRN = '20190123456';

    public function run(CustomerCreator $creator, CustomerProfileWrites $writes, CorporateGenerator $generator): void
    {
        $admin = User::query()->where('email', env('ADMIN_EMAIL', 'admin@hairaa.local'))->first()
            ?? User::query()->where('is_active', true)->orderBy('id')->first();
        if ($admin === null) {
            throw new RuntimeException('Seed an admin user before CorporateCustomerSeeder.');
        }

        $existing = CustomerIdentification::query()
            ->where('normalized_number', CustomerIdentification::normalizeNumber(self::BRN))
            ->where('type', IdentificationType::BusinessRegistration)
            ->first();
        $customer = $existing?->customer;
        if ($customer === null) {
            $customer = $creator->create([
                'type' => CustomerType::Corporate->value,
                'full_legal_name' => 'Aurora Capital Sdn Bhd',
                'identification_type' => IdentificationType::BusinessRegistration->value,
                'identification_number' => self::BRN,
                'mobile' => '0326131600',
                'email' => 'ops@aurora.my',
            ], $admin);
        }

        $writes->saveCorporateCompany($customer, [
            'full_legal_name' => 'Aurora Capital Sdn Bhd',
            'identification_number' => self::BRN,
            'mobile' => '0326131600',
            'email' => 'ops@aurora.my',
            'fax' => '0326131601',
            'registered_at' => '2019-03-15',
            'registration_country' => 'MY',
            'entity_type' => 'Companies',
            'entity_sub_type' => 'Private limited',
            'industry' => 'Investment',
            'business_activity' => 'Fund mgmt',
            'authorized_capital_rm' => '5000000',
            'paid_up_capital_rm' => '2500000',
        ], $admin, true);

        $writes->saveCorporateAddress($customer, [
            'registered_line_1' => 'Level 12 Menara Aurora',
            'registered_line_2' => '3 Jalan Ampang',
            'registered_city' => 'Kuala Lumpur',
            'registered_state' => 'Wilayah Persekutuan',
            'registered_postcode' => '50450',
            'registered_country' => 'Malaysia',
            'same_as_registered' => true,
            'mailing_line_1' => '',
            'mailing_line_2' => '',
            'mailing_city' => '',
            'mailing_state' => '',
            'mailing_postcode' => '',
            'mailing_country' => '',
        ], $admin);

        $writes->saveCorporateSst($customer, 'W10-1808-32000012', $admin);
        $writes->saveCorporateAum($customer, ['aum_bracket' => '1000001_10000000'], $admin);

        $this->seedRepresentatives($writes, $customer, $admin);
        $this->seedBank($writes, $customer, $admin);
        $this->seedTaxInvestmentCompliance($writes, $customer, $admin);
        $this->seedBimbsec($generator, $customer, $admin);

        $this->command?->info('Corporate demo: '.$customer->fresh()->reference.' — Aurora Capital Sdn Bhd');
    }

    private function seedRepresentatives(CustomerProfileWrites $writes, Customer $customer, User $admin): void
    {
        if ($customer->relationships()->whereNotNull('representative_role')->exists()) {
            return;
        }

        $reps = [
            [
                'representativeRole' => RepresentativeRole::PrimaryContact->value,
                'signatoryOrder' => '',
                'fullLegalName' => 'Aisha Rahman',
                'identificationType' => IdentificationType::Nric->value,
                'identificationNumber' => '850101145678',
                'mobile' => '0123456789',
                'homeTelephone' => '0326131610',
                'officeExtension' => '210',
                'email' => 'aisha@aurora.my',
                'occupation' => 'Company Secretary',
            ],
            [
                'representativeRole' => RepresentativeRole::AuthorizedSignatory->value,
                'signatoryOrder' => '1',
                'fullLegalName' => 'Daniel Tan',
                'identificationType' => IdentificationType::Nric->value,
                'identificationNumber' => '780215085432',
                'mobile' => '0198765432',
                'homeTelephone' => '0326131620',
                'officeExtension' => '101',
                'email' => 'daniel@aurora.my',
                'occupation' => 'Managing Director',
            ],
            [
                'representativeRole' => RepresentativeRole::AuthorizedSignatory->value,
                'signatoryOrder' => '2',
                'fullLegalName' => 'Mei Ling Ong',
                'identificationType' => IdentificationType::Nric->value,
                'identificationNumber' => '900330145123',
                'mobile' => '0171122334',
                'homeTelephone' => '0326131630',
                'officeExtension' => '102',
                'email' => 'meiling@aurora.my',
                'occupation' => 'Finance Director',
            ],
        ];

        foreach ($reps as $rep) {
            $writes->saveRepresentative($customer, $rep, $admin);
        }
    }

    private function seedBank(CustomerProfileWrites $writes, Customer $customer, User $admin): void
    {
        if ($customer->bankAccounts()->exists()) {
            return;
        }

        $writes->saveBankAccount($customer, [
            'bank_name' => 'Bank Islam Malaysia Berhad',
            'bank_country' => 'Malaysia',
            'branch_address' => 'Jalan Ampang Branch, KL',
            'account_type' => 'CURRENT',
            'account_number' => '1401234567890',
            'account_holder_name' => 'Aurora Capital Sdn Bhd',
            'ownership' => 'sole',
            'is_primary' => true,
        ], $admin);
    }

    private function seedTaxInvestmentCompliance(CustomerProfileWrites $writes, Customer $customer, User $admin): void
    {
        $writes->saveTin($customer, 'C201901234560', $admin);

        $fatca = $customer->fatcaDeclarations()->where('current_customer_key', $customer->id)->first();
        $writes->saveFatca($customer, [
            'us_person_for_tax' => 'no',
            'us_green_card_holder' => 'no',
            'us_resident' => 'no',
            'selected_classification' => 'non_us_person',
            'declared_on' => today()->toDateString(),
            'source' => 'staff_entry',
        ], $admin, $fatca?->id);

        if (! $customer->investmentProfiles()->exists()) {
            $writes->saveInvestmentProfile($customer, [
                'objectives' => ['long_term', 'capital_growth'],
                'risk_tolerance' => 'moderate',
                'trading_knowledge' => 'intermediate',
                'products' => ['local_equities', 'share_margin'],
                'other_product_details' => '',
            ], $admin);
        }

        $brokerage = $customer->brokerageDeclarations()->where('current_customer_key', $customer->id)->first();
        if ($brokerage === null) {
            $writes->saveBrokerageDeclaration($customer, [
                'answer' => 'yes',
                'declared_on' => today()->toDateString(),
                'source' => 'staff_entry',
            ], $admin);
            $writes->saveBrokerage($customer, [
                'broker_name' => 'Maybank Investment Bank',
                'branch_address' => 'Dataran Maybank, KL',
                'account_type' => 'trading',
            ], $admin);
        }

        foreach (['pep' => 'no', 'bssb_staff' => 'no', 'bssb_client' => 'no'] as $kind => $answer) {
            if ($customer->complianceDeclarations()->where('current_key', $customer->id.':'.$kind)->exists()) {
                continue;
            }
            $writes->saveComplianceDeclaration($customer, $kind, [
                'answer' => $answer,
                'remarks' => '',
                'declared_on' => today()->toDateString(),
                'source' => 'staff_recorded',
                'confirmation_status' => 'unconfirmed',
            ], $admin);
        }
    }

    private function seedBimbsec(CorporateGenerator $generator, Customer $customer, User $admin): void
    {
        $application = $customer->bimbsecApplications()
            ->where('form_kind', BimbsecApplication::KIND_CORPORATE)
            ->latest('id')
            ->first();

        if ($application === null) {
            $application = $customer->bimbsecApplications()->create([
                'form_kind' => BimbsecApplication::KIND_CORPORATE,
                'choices' => BimbsecApplication::normalizeChoices([
                    'account_type' => 'margin',
                    'margin_credit_limit' => '500000',
                    'dealings_capacity' => 'principal',
                    'online_trading' => 'yes',
                    'estatement' => 'email',
                    'payment' => 'epayment',
                ]),
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        } else {
            $application->update([
                'choices' => BimbsecApplication::normalizeChoices([
                    'account_type' => 'margin',
                    'margin_credit_limit' => '500000',
                    'dealings_capacity' => 'principal',
                    'online_trading' => 'yes',
                    'estatement' => 'email',
                    'payment' => 'epayment',
                ]),
                'updated_by' => $admin->id,
            ]);
        }

        $generator->generate($application->fresh(), $admin);
    }
}
