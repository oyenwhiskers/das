<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Enums\ContactType;
use App\Enums\IdentificationType;
use App\Livewire\Bimbsec\Index;
use App\Livewire\Bimbsec\Show;
use App\Models\BimbsecApplication;
use App\Models\BimbsecBatch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerBankAccount;
use App\Models\CustomerEmployment;
use App\Models\CustomerIdentification;
use App\Models\RelatedPerson;
use App\Models\User;
use App\Services\Bimbsec\BatchRunner;
use App\Services\Bimbsec\Catalog;
use App\Services\Bimbsec\Generator;
use App\Services\Bimbsec\Renderer;
use App\Services\Bimbsec\Resolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class BimbsecModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_inactive_user_cannot_open_bimbsec(): void
    {
        $this->get(route('bimbsec.index'))->assertRedirect(route('login'));

        $user = User::factory()->inactive()->create();
        $this->actingAs($user)->get(route('bimbsec.index'))->assertRedirect(route('login'));
    }

    public function test_staff_can_search_and_page_selection_does_not_select_every_customer(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Ali Bin Abu', 'primary_identification_number' => '900101011234']);
        Customer::factory()->count(55)->for($user, 'creator')->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', '900101011234')
            ->assertSee('Ali Bin Abu')
            ->assertDontSee('Generate PDFs for 56');

        $component = Livewire::actingAs($user)->test(Index::class);
        $pageIds = $component->viewData('pageIds');
        $this->assertCount(50, $pageIds);
        $component->call('togglePage', $pageIds)->assertSet('selected', $pageIds);
        $this->assertCount(50, $component->get('selected'));
        $this->assertSame(56, Customer::query()->count());
    }

    public function test_index_lists_customers_alphabetically_by_name(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Zara Binti Omar']);
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Ali Bin Abu']);
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Mira Binti Lim']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSeeInOrder(['Ali Bin Abu', 'Mira Binti Lim', 'Zara Binti Omar']);
    }

    public function test_migration_clears_stored_non_face_verification_default(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $choices = BimbsecApplication::normalizeChoices([]);
        $choices['verification_mode'] = 'non_face';
        $application = $customer->bimbsecApplications()->create([
            'choices' => $choices,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $ticked = collect(app(Resolver::class)->resolve($customer, $application->choices)['draws'])
            ->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Non Face-To-Face');
        $this->assertTrue($ticked['on'] ?? false);

        $migration = require database_path('migrations/2026_10_21_094700_clear_bimbsec_default_verification_mode.php');
        $migration->up();

        $application->refresh();
        $this->assertNull($application->choices['verification_mode']);
        $blank = collect(app(Resolver::class)->resolve($customer, $application->choices)['draws'])
            ->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Non Face-To-Face');
        $this->assertNull($blank);
    }

    public function test_profile_maps_name_nric_and_does_not_copy_another_customers_relative(): void
    {
        $user = User::factory()->staff()->create();
        $ali = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $other = $this->customer($user, 'SITI BINTI OMAR', '910202021111');
        $person = RelatedPerson::factory()->create(['full_legal_name' => 'ZARA BINTI ALI']);
        $other->relationships()->create([
            'related_person_id' => $person->id,
            'relationship' => 'spouse',
            'is_next_of_kin' => true,
            'is_primary_next_of_kin' => true,
        ]);
        $ali->update(['marital_status' => 'Married', 'gender' => 'male']);
        $ali->bankAccounts()->create([
            'bank_name' => 'MAYBANK',
            'bank_country' => 'Malaysia',
            'account_type' => 'SAVINGS',
            'account_number' => '123456789012',
            'account_holder_name' => 'ALI BIN ABU',
            'ownership' => '',
            'is_primary' => true,
            'is_active' => true,
            'identity_hash' => CustomerBankAccount::identityHash('MAYBANK', 'Malaysia', '123456789012'),
        ]);
        $ali->financialDeclarations()->create([
            'current_customer_key' => $ali->id,
            'monthly_income_rm' => 8000,
            'personal_annual_income_bracket' => '60001_120000',
            'net_asset_bracket' => 'up_to_50000',
            'portfolio_bracket' => 'up_to_50000',
            'wealth_sources' => ['salary'],
            'declared_on' => today()->toDateString(),
            'source' => 'staff_entry',
            'recorded_by' => $user->id,
        ]);
        $other->bankAccounts()->create([
            'bank_name' => 'CIMB',
            'bank_country' => 'Malaysia',
            'account_type' => 'SAVINGS',
            'account_number' => '999',
            'account_holder_name' => 'SITI BINTI OMAR',
            'ownership' => 'joint',
            'is_primary' => true,
            'is_active' => true,
            'identity_hash' => CustomerBankAccount::identityHash('CIMB', 'Malaysia', '999'),
        ]);

        $before = $ali->fresh()->updated_at?->toJSON();
        $resolved = app(Resolver::class)->resolve($ali->fresh(), []);
        $blob = json_encode($resolved['draws'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('ALI BIN ABU', $blob);
        $this->assertStringContainsString('900101011234', $blob);
        $this->assertStringNotContainsString('ZARA BINTI ALI', $blob);
        $this->assertStringNotContainsString('SITI BINTI OMAR', $blob);
        $notes = collect($resolved['warnings'])->pluck('message')->implode(' ');
        $this->assertStringNotContainsString('compliance.', $notes);
        $this->assertStringNotContainsString('_', $notes);

        $male = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Male');
        $married = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Married');
        $this->assertTrue($male['on'] ?? false);
        $this->assertTrue($married['on'] ?? false);
        $single = collect(app(Resolver::class)->resolve($other->fresh(), [])['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Single');
        $this->assertTrue($single['on'] ?? false);
        $other->update(['marital_status' => 'Divorced']);
        $divorced = collect(app(Resolver::class)->resolve($other->fresh(), [])['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Divorced');
        $this->assertTrue($divorced['on'] ?? false);
        $trading = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Trading Account');
        $remote = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Non Face-To-Face');
        $this->assertTrue($trading['on'] ?? false);
        $this->assertNull($remote);
        foreach ([
            'Certified true copy of Applicant NRIC/Passport',
            'Certified true copy of latest 1 month bank statement/passbook',
        ] as $doc) {
            $tick = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === $doc);
            $this->assertTrue($tick['on'] ?? false, $doc);
        }
        $staffId = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Certified true copy of Staff ID Card (LTH Group employee)');
        $this->assertNull($staffId);
        $principal = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'For me as principal');
        $this->assertTrue($principal['on'] ?? false);
        foreach (['tax.us_person_for_irs', 'tax.us_green_card_holder', 'tax.us_resident'] as $key) {
            $answer = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === $key);
            $this->assertSame('no', $answer['choice'] ?? null);
        }
        $nonUs = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Non US Person');
        $this->assertTrue($nonUs['on'] ?? false);
        $online = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'application.online_trading_requested');
        $email = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'by e-mail');
        $epayment = collect($resolved['draws'])->first(fn (array $draw): bool => str_starts_with((string) ($draw['field']['printed_item'] ?? ''), 'e-Payment'));
        $bank = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'bank');
        $income = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Monthly Income');
        $personal = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'RM60,001-RM120,000');
        $salary = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Salary');
        $this->assertSame(['MAYBANK'], $bank['rows'] ?? null);
        $this->assertSame('8,000.00', $income['text'] ?? null);
        $this->assertTrue($personal['on'] ?? false);
        $this->assertTrue($salary['on'] ?? false);
        $joint = collect(app(Resolver::class)->resolve($other->fresh(), [])['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'bank');
        $this->assertNull($joint);
        $brokers = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'investment.has_other_brokers');
        $this->assertSame('no', $brokers['choice'] ?? null);
        foreach (['compliance.related_to_bssb_staff', 'compliance.related_to_bssb_clients'] as $key) {
            $related = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === $key);
            $this->assertSame('no', $related['choice'] ?? null);
        }
        $lth = collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'employment.lth_group_employee');
        $this->assertSame('no', $lth['choice'] ?? null);
        $lthYes = collect(app(Resolver::class)->resolve($ali->fresh(), ['lth_group_employee' => 'yes'])['draws'])->first(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'employment.lth_group_employee');
        $this->assertSame('yes', $lthYes['choice'] ?? null);
        $this->assertSame('no', $online['choice'] ?? null);
        $this->assertTrue($email['on'] ?? false);
        $this->assertTrue($epayment['on'] ?? false);

        $application = $ali->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);
        $document = app(Generator::class)->generate($application, $user);
        $this->assertSame(14, $document->page_count);
        $this->assertSame($before, $ali->fresh()->updated_at?->toJSON());
        $pdf = new Fpdi;
        $this->assertSame(14, $pdf->setSourceFile(Storage::disk('local')->path($document->storage_path)));
    }

    public function test_a_sole_malaysia_bank_maps_without_being_marked_primary(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $customer->bankAccounts()->create([
            'bank_name' => 'CIMB',
            'bank_country' => 'Malaysia',
            'branch_address' => 'KL Main',
            'account_type' => 'SAVINGS',
            'account_number' => '9876543210',
            'account_holder_name' => 'ALI BIN ABU',
            'ownership' => 'sole',
            'is_primary' => false,
            'is_active' => true,
            'identity_hash' => CustomerBankAccount::identityHash('CIMB', 'Malaysia', '9876543210'),
        ]);

        $draws = collect(app(Resolver::class)->resolve($customer->fresh(), [])['draws']);
        $this->assertSame(['CIMB'], $draws->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'bank')['rows'] ?? null);
        $this->assertSame(['KL Main'], $draws->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'branch_or_address')['rows'] ?? null);
        $this->assertSame(['Savings'], $draws->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'account_type')['rows'] ?? null);
        $this->assertSame(['9876543210'], $draws->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'account_number')['rows'] ?? null);
    }

    public function test_a_long_bank_name_still_prints_with_an_attention_warning(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $customer->bankAccounts()->create([
            'bank_name' => 'RHB BANK ISLAMIC BERHAD',
            'bank_country' => 'Malaysia',
            'branch_address' => '',
            'account_type' => 'SAVINGS',
            'account_number' => '16301500112375',
            'account_holder_name' => 'ALI BIN ABU',
            'ownership' => 'sole',
            'is_primary' => true,
            'is_active' => true,
            'identity_hash' => CustomerBankAccount::identityHash('RHB BANK ISLAMIC BERHAD', 'Malaysia', '16301500112375'),
        ]);

        $resolved = app(Resolver::class)->resolve($customer->fresh(), []);
        $this->assertSame(
            ['RHB BANK ISLAMIC BERHAD'],
            collect($resolved['draws'])->first(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'bank')['rows'] ?? null,
        );

        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);
        $document = app(Generator::class)->generate($application, $user);

        $this->assertFalse($document->print_blocked);
        $this->assertTrue(collect($document->warnings)->contains(
            fn (array $warning): bool => ($warning['level'] ?? '') === 'attention'
                && str_contains((string) ($warning['message'] ?? ''), 'too long for the form')
                && str_contains(strtolower((string) ($warning['label'] ?? '')), 'bank'),
        ));
    }

    public function test_a_long_name_stays_locked_until_it_is_shortened_on_the_profile(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, str_repeat('LONGNAME ', 12), '900101011234');
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);
        $document = app(Generator::class)->generate($application, $user);
        $this->assertTrue($document->print_blocked);
        $application->update(['overflow_attachment_approved' => true, 'overflow_attachment_note' => 'Continuation sheet attached.']);
        $this->actingAs($user)->get(route('bimbsec.print', [$customer, $application]))->assertForbidden();

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer->fresh(), 'application' => $application])
            ->set('overflow_values', ['personal_legal_name__Name of applicant' => 'ALI BIN ABU'])
            ->call('saveOverflow')
            ->assertHasNoErrors();

        $this->assertSame('ALI BIN ABU', $customer->fresh()->full_legal_name);
        $this->assertFalse($application->fresh()->document->print_blocked);
        $this->actingAs($user)->get(route('bimbsec.print', [$customer, $application]))->assertOk();
    }

    public function test_an_overflowing_address_can_be_shortened_from_the_form(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $customer->residentialAddress->update(['line_1' => str_repeat('JALAN PANJANG ', 8), 'line_2' => 'TAMAN']);
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);
        $this->assertTrue(app(Generator::class)->assess($customer->fresh(), [])['blocked']);

        Livewire::actingAs($user)->test(Index::class)
            ->assertSee('bg-amber-50')
            ->assertSee('Resident address is too long for the form.');

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer->fresh(), 'application' => $application])
            ->assertSee('Fix these before printing')
            ->assertSee('bg-amber-50')
            ->assertSee('Resident address is too long for the form.')
            ->assertDontSee('address_lines has')
            ->assertSeeInOrder(['JALAN PANJANG', 'Resident address is too long for the form.'])
            ->set('overflow_values.addresses_residential__address_lines', '1 Jalan Damai')
            ->call('saveOverflow')
            ->assertHasNoErrors();

        $address = $customer->residentialAddress()->first();
        $this->assertSame('1 Jalan Damai', $address->line_1);
        $this->assertNull($address->line_2);
        $this->assertFalse(app(Generator::class)->assess($customer->fresh(), [])['blocked']);
        $this->assertFalse($application->fresh()->document->print_blocked);
    }

    public function test_mailing_that_mirrors_residential_is_not_a_separate_fix_field(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $long = str_repeat('JALAN PANJANG ', 8).'TAMAN';
        $customer->residentialAddress->update(['line_1' => $long, 'line_2' => null, 'state' => 'W.P.KUALA LUMPUR']);
        $customer->addresses()->create([
            'type' => 'mailing',
            'same_as_residential' => true,
            'line_1' => null,
        ]);
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);

        $messages = collect(app(Generator::class)->assess($customer->fresh(), [])['warnings'])
            ->where('level', 'blocking')
            ->pluck('message');
        $this->assertTrue($messages->contains(fn (string $message): bool => str_contains($message, 'Resident address')));
        $this->assertFalse($messages->contains(fn (string $message): bool => str_contains($message, 'Mailing address')));

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer->fresh(), 'application' => $application])
            ->assertSee('Resident address is too long for the form.')
            ->assertDontSee('Mailing address is too long for the form.')
            ->set('overflow_values.addresses_residential__address_lines', '1 Jalan Damai')
            ->set('overflow_values.addresses_residential__state', 'WP KL')
            ->call('saveOverflow')
            ->assertHasNoErrors();

        $this->assertSame('1 Jalan Damai', $customer->fresh()->residentialAddress->line_1);
        $this->assertTrue((bool) $customer->fresh()->mailingAddress->same_as_residential);
        $this->assertFalse(app(Generator::class)->assess($customer->fresh(), [])['blocked']);

        $draws = app(Resolver::class)->resolve($customer->fresh(), [])['draws'];
        $mailingDraws = collect($draws)->filter(
            fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'addresses.mailing'
                && filled($draw['text'] ?? null),
        );
        $this->assertTrue($mailingDraws->isEmpty(), 'Mailing PDF fields must stay blank when same as residential.');
    }

    public function test_self_employed_with_empty_office_address_prefers_mailing_then_residential_on_the_form(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $customer->update(['employment_status' => 'self_employed']);
        CustomerEmployment::factory()->selfEmployed()->create([
            'customer_id' => $customer->id,
            'occupation' => 'BUSINESS',
            'employer_name' => 'PASAR BESAR',
            'is_primary' => true,
            'is_current' => true,
        ]);
        $customer->addresses()->updateOrCreate(
            ['type' => AddressType::Mailing],
            [
                'line_1' => 'LOT 9 PASAR',
                'city' => 'Tanah Merah',
                'postcode' => '17500',
                'state' => 'Kelantan',
                'country' => 'Malaysia',
                'same_as_residential' => false,
            ],
        );

        $draws = collect(app(Resolver::class)->resolve($customer->fresh(), [])['draws']);
        $officeLines = $draws->first(
            fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'employment.business_address'
                && ($draw['field']['printed_item'] ?? '') === 'address_lines',
        );
        $this->assertSame('LOT 9 PASAR', $officeLines['text'] ?? null);
        $employerPhone = $draws->first(
            fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'employment.employer_general_phone',
        );
        $this->assertSame('0123456789', $employerPhone['text'] ?? null);

        CustomerEmployment::query()->where('customer_id', $customer->id)->update([
            'address_line_1' => null,
            'address_line_2' => null,
            'city' => null,
            'postcode' => null,
        ]);
        $customer->mailingAddress?->delete();
        $long = str_repeat('JALAN PANJANG ', 8).'TAMAN';
        $customer->residentialAddress->update(['line_1' => $long, 'line_2' => null]);

        $draws = collect(app(Resolver::class)->resolve($customer->fresh(), [])['draws']);
        $officeLines = $draws->first(
            fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'employment.business_address'
                && ($draw['field']['printed_item'] ?? '') === 'address_lines',
        );
        $this->assertSame($long, $officeLines['text'] ?? null);
        $employerPhone = $draws->first(
            fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'employment.employer_general_phone',
        );
        $this->assertSame('0123456789', $employerPhone['text'] ?? null);

        $messages = collect(app(Generator::class)->assess($customer->fresh(), [])['warnings'])
            ->where('level', 'blocking')
            ->pluck('message');
        $this->assertTrue($messages->contains(fn (string $message): bool => str_contains($message, 'Resident address')));
        $this->assertFalse($messages->contains(fn (string $message): bool => str_contains($message, 'Employer address')));

        CustomerEmployment::query()->where('customer_id', $customer->id)->update([
            'address_line_1' => 'LOT 9 PASAR',
            'city' => null,
            'postcode' => null,
        ]);
        $customer->residentialAddress->update(['city' => 'Tanah Merah', 'postcode' => '17500']);
        $draws = collect(app(Resolver::class)->resolve($customer->fresh(), [])['draws']);
        $officeLines = $draws->first(
            fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'employment.business_address'
                && ($draw['field']['printed_item'] ?? '') === 'address_lines',
        );
        $officeCity = $draws->first(
            fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'employment.business_address'
                && ($draw['field']['printed_item'] ?? '') === 'city',
        );
        $this->assertSame('LOT 9 PASAR', $officeLines['text'] ?? null);
        $this->assertSame('Tanah Merah', $officeCity['text'] ?? null);
    }

    public function test_opening_the_pdf_stamps_the_date_without_rewriting_the_file(): void
    {
        $user = User::factory()->staff()->create(['name' => 'Siti Aminah']);
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices(['account_type' => 'trading']),
            'created_by' => $user->id,
        ]);
        $document = app(Generator::class)->generate($application, $user);
        $bytes = Storage::disk('local')->get($document->storage_path);

        $response = $this->actingAs($user)
            ->get(route('bimbsec.file', [$customer, $application]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $served = $response->getContent();
        $this->assertTrue($this->pdfContains($served, now()->format('d/m/Y')));
        $this->assertTrue($this->pdfContains($served, 'ALI BIN ABU'));
        $this->assertTrue($this->pdfContains($served, '900101011234'));
        $this->assertFalse($this->pdfContains($served, 'SITI AMINAH'));
        $this->assertSame($bytes, Storage::disk('local')->get($document->fresh()->storage_path));
        $this->assertFalse($this->pdfContains($bytes, now()->format('d/m/Y')));
    }

    public function test_email_on_the_form_keeps_lowercase_and_is_not_uppercased(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $customer->contacts()->create([
            'type' => ContactType::Email,
            'value' => 'ali.bin.abu@example.com',
            'normalized_value' => 'ali.bin.abu@example.com',
            'is_primary' => true,
        ]);
        $customer->update(['primary_email' => 'ali.bin.abu@example.com']);

        $email = collect(app(Resolver::class)->resolve($customer->fresh(), [])['draws'])
            ->first(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'contact.email');
        $this->assertSame('ali.bin.abu@example.com', $email['text'] ?? null);
        $this->assertFalse((bool) ($email['field']['placement']['uppercase'] ?? false));
        $this->assertFalse((bool) ($email['field']['print_setting']['input_uppercase'] ?? false));

        app(Generator::class)->generate($customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]), $user);
        $this->assertFalse(app(Generator::class)->assess($customer->fresh(), [])['blocked']);
    }

    public function test_a_customer_has_one_bimbsec_form(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices(['account_type' => 'trading']),
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer, 'application' => $application])
            ->assertDontSee('New application')
            ->assertSee('Choices')
            ->assertSee('<details class="rounded-lg border border-slate-200 bg-white px-4 py-3">', false)
            ->assertDontSee('<details open class="rounded-lg border border-slate-200 bg-white px-4 py-3">', false);

        $this->expectException(QueryException::class);
        $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);
    }

    public function test_catalog_exposes_character_cell_capacities_used_by_renderer(): void
    {
        $catalog = app(Catalog::class);

        $this->assertSame(64, $catalog->cellCapacity('personal.legal_name', 'Name of applicant'));
        $this->assertSame(15, $catalog->cellCapacity('identification.current_nric_or_passport', 'New NRIC/Passport/Registration No.'));
        $this->assertSame(64, $catalog->cellCapacity('addresses.residential', 'address_lines'));
        $this->assertNull($catalog->cellCapacity('tax.malaysian_tin', 'tin'));
    }

    public function test_bulk_merge_keeps_order_and_reports_a_failure_without_a_final_pdf(): void
    {
        $user = User::factory()->staff()->create();
        $first = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $second = $this->customer($user, 'SITI BINTI OMAR', '910202021111');
        config(['bimbsec.chunk_size' => 1, 'bimbsec.max_selection' => 5]);

        $component = Livewire::actingAs($user)
            ->test(Index::class)
            ->call('toggle', $first->id)
            ->call('toggle', $second->id)
            ->call('askGenerate')
            ->call('generateSelected')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true
                && $data['pending'] === true
                && ($data['total'] ?? 0) === 2);

        $stored = BimbsecBatch::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($stored);
        $component->call('batchAdvance', $stored->id, 'generate')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true && $data['pending'] === true && ($data['done'] ?? 0) === 1);
        $component->call('batchAdvance', $stored->id, 'generate')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true
                && $data['pending'] === false
                && str_contains($data['url'] ?? '', '/bimbsec/batches/'));

        $stored->refresh();
        $this->assertTrue($stored->complete);
        $this->assertSame([$stored->summary[0]['reference'], $stored->summary[1]['reference']], [$first->reference, $second->reference]);
        $pdf = new Fpdi;
        $this->assertSame(28, $pdf->setSourceFile(Storage::disk('local')->path($stored->storage_path)));

        $real = new Renderer(app(Catalog::class));
        $this->mock(Renderer::class, function ($mock) use ($real): void {
            $mock->shouldReceive('render')->andReturnUsing(function (array $draws) use ($real): array {
                $blob = json_encode($draws);
                if (is_string($blob) && str_contains($blob, 'SITI BINTI OMAR')) {
                    throw new \RuntimeException('boom');
                }

                return $real->render($draws);
            });
        });
        $again = app(BatchRunner::class)->start([$first->id, $second->id], $user);
        $this->assertFalse($again->complete);
        $this->assertNull($again->storage_path);
        $this->assertSame('PDF generation failed.', collect($again->summary)->firstWhere('reference', $second->reference)['reason']);
    }

    public function test_print_all_and_download_all_open_the_combined_pdf(): void
    {
        $user = User::factory()->staff()->create();
        $first = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $second = $this->customer($user, 'SITI BINTI OMAR', '910202021111');
        config(['bimbsec.chunk_size' => 1]);

        $download = Livewire::actingAs($user)
            ->test(Index::class)
            ->assertDontSee('Download all')
            ->call('toggle', $first->id)
            ->assertSee('Download all')
            ->assertSee('Print all')
            ->assertSee('Clear')
            ->call('toggle', $second->id)
            ->call('downloadAll')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true
                && $data['pending'] === true
                && ($data['total'] ?? 0) === 2);

        $batch = BimbsecBatch::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($batch);
        $download->call('batchAdvance', $batch->id, 'download')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true && $data['pending'] === true && ($data['done'] ?? 0) === 1);
        $download->call('batchAdvance', $batch->id, 'download')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true
                && $data['pending'] === false
                && str_contains($data['url'] ?? '', 'download=1'));
        $this->assertTrue($batch->fresh()->complete);

        $print = Livewire::actingAs($user)
            ->test(Index::class)
            ->call('toggle', $first->id)
            ->call('toggle', $second->id)
            ->call('printAll');
        $printBatch = BimbsecBatch::query()->where('user_id', $user->id)->latest('id')->first();
        $print->call('batchAdvance', $printBatch->id, 'print')
            ->call('batchAdvance', $printBatch->id, 'print')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true
                && $data['pending'] === false
                && str_contains($data['url'] ?? '', '/print')
                && ! str_contains($data['url'] ?? '', 'download=1'));
    }

    public function test_select_all_on_a_full_page_can_start_download_without_a_selection_cap(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->count(45)->for($user, 'creator')->create();
        config(['bimbsec.max_selection' => 0, 'bimbsec.chunk_size' => 8]);

        $component = Livewire::actingAs($user)->test(Index::class);
        $pageIds = $component->viewData('pageIds');
        $this->assertCount(45, $pageIds);

        $component->call('togglePage', $pageIds)
            ->call('downloadAll')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true
                && $data['pending'] === true
                && ($data['total'] ?? 0) === 45);

        $batch = BimbsecBatch::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($batch);
        $component->call('batchAdvance', $batch->id, 'download')
            ->assertReturned(fn (array $data): bool => $data['ok'] === true
                && $data['pending'] === true
                && ($data['done'] ?? 0) === 8);
    }

    public function test_another_staff_member_cannot_open_a_batch_file(): void
    {
        $owner = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $customer = $this->customer($owner, 'ALI BIN ABU', '900101011234');
        $batch = app(BatchRunner::class)->start([$customer->id], $owner);

        $this->actingAs($other)->get(route('bimbsec.batches.file', $batch))->assertForbidden();
        $this->actingAs($owner)->get(route('bimbsec.batches.file', $batch))->assertOk();
    }

    public function test_saving_the_bimbsec_form_writes_the_customer_profile(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer, 'application' => $application])
            ->set('old_nric', 'A123456')
            ->set('home_telephone', '0312345678')
            ->set('occupation', 'Doctor')
            ->set('employer_name', 'IIUM Medical')
            ->set('monthly_income', '9000')
            ->set('personal_income', '60001_120000')
            ->call('saveForm')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame('A123456', $customer->primaryIdentification->old_nric);
        $this->assertSame('0312345678', $customer->contacts()->where('type', 'home')->value('value'));
        $job = $customer->employments()->first();
        $this->assertSame('Doctor', $job->occupation);
        $this->assertSame('IIUM Medical', $job->employer_name);
        $financial = $customer->financialDeclarations()->where('current_customer_key', $customer->id)->first();
        $this->assertSame('9000.00', $financial->monthly_income_rm);
        $this->assertSame('60001_120000', $financial->personal_annual_income_bracket);
        $this->assertNotNull($application->fresh()->document);
    }

    public function test_an_updated_profile_replaces_the_stored_pdf(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);
        $document = app(Generator::class)->generate($application, $user);
        $before = Storage::disk('local')->get($document->storage_path);
        $customer->update(['full_legal_name' => 'ALI BIN BARU']);

        $this->actingAs($user)->get(route('bimbsec.file', [$customer, $application]))->assertOk();

        $document->refresh();
        $live = app(Resolver::class)->resolve($customer->fresh(), $application->choices ?? []);
        $this->assertSame($live['source_hash'], $document->source_hash);
        $this->assertNotSame($before, Storage::disk('local')->get($document->storage_path));
        $this->actingAs($user)->get(route('bimbsec.show', [$customer, $application]))
            ->assertOk()
            ->assertDontSee('Print and download still use this file');
    }

    public function test_a_sole_relationship_fills_bimbsec_even_when_not_marked_active(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $kin = $this->customer($user, 'AHMAD BIN ALI', '800101011111');
        $kin->update(['primary_email' => 'ahmad@example.com']);
        $kin->contacts()->create([
            'type' => ContactType::Home,
            'value' => '0345678901',
            'normalized_value' => '0345678901',
            'is_primary' => false,
        ]);
        $kin->employments()->create([
            'employer_name' => 'Kin Employer Sdn Bhd',
            'occupation' => 'Engineer',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'postcode' => '40000',
            'country' => 'Malaysia',
            'is_primary' => true,
            'is_current' => true,
        ]);
        $customer->relationships()->create([
            'related_customer_id' => $kin->id,
            'relationship' => 'father',
            'is_next_of_kin' => false,
            'is_primary_next_of_kin' => false,
            'active_identity_key' => "{$customer->id}:customer:{$kin->id}",
        ]);
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
        ]);

        $blob = json_encode(app(Resolver::class)->resolve($customer->fresh(), [])['draws'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('AHMAD BIN ALI', $blob);
        $this->assertStringContainsString('800101011111', $blob);
        $this->assertStringContainsString('Engineer', $blob);

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer->fresh(), 'application' => $application])
            ->assertSet('kin_linked', true)
            ->assertSet('kin_editable', false)
            ->assertSet('kin_occupation', 'Engineer')
            ->assertSet('kin_employer_city', 'Shah Alam')
            ->assertSet('kin_employer_state', 'Selangor')
            ->assertSet('kin_employer_postcode', '40000')
            ->assertSet('kin_home_telephone', '0345678901')
            ->assertSet('kin_email', 'ahmad@example.com')
            ->assertDontSee('Choose one spouse or next of kin above');
    }

    public function test_active_relationship_is_used_when_multiple_exist(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');
        $customer->update(['marital_status' => 'Married']);
        $spouse = RelatedPerson::factory()->create(['full_legal_name' => 'SITI BINTI OMAR', 'mobile' => '0123456789']);
        $brother = RelatedPerson::factory()->create(['full_legal_name' => 'AHMAD BIN ALI', 'mobile' => '0133456789']);
        $customer->relationships()->create([
            'related_person_id' => $spouse->id,
            'relationship' => 'spouse',
            'is_next_of_kin' => false,
            'is_primary_next_of_kin' => false,
            'active_identity_key' => "{$customer->id}:person:{$spouse->id}",
        ]);
        $customer->relationships()->create([
            'related_person_id' => $brother->id,
            'relationship' => 'brother',
            'is_next_of_kin' => true,
            'is_primary_next_of_kin' => true,
            'active_identity_key' => "{$customer->id}:person:{$brother->id}",
        ]);

        $blob = json_encode(app(Resolver::class)->resolve($customer->fresh(), [])['draws'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('AHMAD BIN ALI', $blob);
        $this->assertStringNotContainsString('SITI BINTI OMAR', $blob);
    }

    private function pdfContains(string $pdf, string $text): bool
    {
        if (str_contains($pdf, $text)) {
            return true;
        }
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress($stream) ?: @gzinflate($stream);
            if (is_string($decoded) && str_contains($decoded, $text)) {
                return true;
            }
        }

        return false;
    }

    private function customer(User $user, string $name, string $nric): Customer
    {
        $customer = Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => $name,
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => $nric,
            'gender' => 'male',
        ]);
        CustomerIdentification::query()->create([
            'customer_id' => $customer->id,
            'type' => IdentificationType::Nric,
            'number' => $nric,
            'normalized_number' => $nric,
            'is_primary' => true,
        ]);
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'type' => 'residential',
            'line_1' => '1 Jalan Damai',
            'city' => 'KL',
            'state' => 'Wilayah',
            'postcode' => '50000',
            'country' => 'Malaysia',
        ]);
        $customer->contacts()->create([
            'type' => ContactType::Mobile,
            'value' => '0123456789',
            'normalized_value' => '0123456789',
            'is_primary' => true,
        ]);

        return $customer;
    }
}
