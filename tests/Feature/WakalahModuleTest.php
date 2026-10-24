<?php

namespace Tests\Feature;

use App\Enums\IdentificationType;
use App\Livewire\Wakalah\Index;
use App\Livewire\Wakalah\Show;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerIdentification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

class WakalahModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_wakalah(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');

        $this->get(route('wakalah.index'))->assertRedirect(route('login'));
        $this->get(route('wakalah.show', $customer))->assertRedirect(route('login'));
        $this->get(route('wakalah.file', $customer))->assertRedirect(route('login'));
    }

    public function test_staff_can_list_and_open_wakalah_letter(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Wakalah Letter')
            ->assertSee('ALI BIN ABU')
            ->call('openCustomer', $customer->id)
            ->assertRedirect(route('wakalah.show', $customer));

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->assertSee('ALI BIN ABU')
            ->assertSee('Witnessed by')
            ->assertSee(route('wakalah.file', $customer), false);

        $response = $this->actingAs($user)
            ->get(route('wakalah.file', $customer))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $served = $response->getContent();
        $this->assertSame(2, (new Fpdi)->setSourceFile(StreamReader::createByString($served)));
        $this->assertTrue($this->pdfContains($served, 'ALI BIN ABU'));
        $this->assertTrue($this->pdfContains($served, '900101011234'));
        $this->assertTrue($this->pdfContains($served, '1 JALAN DAMAI'));
        $this->assertTrue($this->pdfContains($served, now()->format('d/m/Y')));
        $this->assertFalse($this->pdfContains($served, 'WITNESS ONE'));
    }

    public function test_staff_can_save_wakalah_details_and_preview_updates(): void
    {
        $user = User::factory()->staff()->create();
        $customer = $this->customer($user, 'ALI BIN ABU', '900101011234');

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->set('designation', 'Director')
            ->set('witnessName', 'Witness One')
            ->set('witnessNric', '880202025678')
            ->set('witnessDate', '2026-10-20')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('previewVersion', 1);

        $customer->refresh();
        $this->assertSame('Director', $customer->wakalah_designation);
        $this->assertSame('Witness One', $customer->wakalah_witness_name);
        $this->assertSame('880202025678', $customer->wakalah_witness_nric);
        $this->assertSame('2026-10-20', $customer->wakalah_witness_date?->toDateString());

        $served = $this->actingAs($user)
            ->get(route('wakalah.file', $customer))
            ->assertOk()
            ->getContent();
        $this->assertTrue($this->pdfContains($served, 'DIRECTOR'));
        $this->assertTrue($this->pdfContains($served, 'WITNESS ONE'));
        $this->assertTrue($this->pdfContains($served, '880202025678'));
        $this->assertTrue($this->pdfContains($served, '20/10/2026'));

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->set('witnessName', 'Witness Two')
            ->set('witnessNric', '')
            ->set('witnessDate', '')
            ->call('save')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame('Witness Two', $customer->wakalah_witness_name);
        $this->assertNull($customer->wakalah_witness_nric);
        $this->assertNull($customer->wakalah_witness_date);

        $served = $this->actingAs($user)
            ->get(route('wakalah.file', $customer))
            ->assertOk()
            ->getContent();
        $this->assertTrue($this->pdfContains($served, 'WITNESS TWO'));
        $this->assertFalse($this->pdfContains($served, '880202025678'));
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

        return $customer;
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
}
