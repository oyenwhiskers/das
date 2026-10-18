<?php

namespace Tests\Feature;

use App\Livewire\Customers\Compliance;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_pep_related_person_opens_modal_over_association_list(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer])
            ->assertDontSee('aria-labelledby="association-form-title"', false)
            ->call('addAssociation')
            ->assertSee('aria-labelledby="association-form-title"', false)
            ->assertSee('No PEP associations recorded')
            ->call('cancel')
            ->assertDontSee('aria-labelledby="association-form-title"', false);
    }

    public function test_declarations_are_independent_and_revisions_preserve_history(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer])
            ->assertSee('Not Declared');

        $component->call('editDeclaration', 'pep')->set('declaration.answer', 'yes')
            ->call('saveDeclaration')->assertHasNoErrors();
        $component->call('editDeclaration', 'pep')->set('declaration.answer', 'no')
            ->call('saveDeclaration')->assertHasNoErrors();
        $component->call('editDeclaration', 'bankruptcy')->set('declaration.answer', 'yes')
            ->call('saveDeclaration')->assertHasNoErrors();

        $this->assertSame(['yes', 'no'], $customer->complianceDeclarations()->where('kind', 'pep')->orderBy('id')->pluck('answer')->all());
        $this->assertSame('yes', $customer->complianceDeclarations()->where('kind', 'bankruptcy')->firstOrFail()->answer);
        $this->assertDatabaseMissing('customer_compliance_declarations', ['customer_id' => $customer->id, 'kind' => 'petition']);
        $this->assertSame(['pep_declaration_created', 'pep_declaration_updated', 'bankruptcy_declaration_created'], $customer->activities()->reorder()->orderBy('id')->pluck('action')->map(fn ($action): string => $action->value)->all());
    }

    public function test_staff_recording_does_not_imply_customer_confirmation(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer]);
        $component->call('editDeclaration', 'petition')->set('declaration.confirmation_status', 'customer_confirmed')
            ->call('saveDeclaration')->assertHasErrors('declaration.confirmation_status');
        $component->set('declaration.source', 'customer_verbal')->call('saveDeclaration')->assertHasNoErrors();
        $this->assertSame('customer_confirmed', $customer->complianceDeclarations()->firstOrFail()->confirmation_status);
    }

    public function test_each_declaration_accepts_explicit_not_declared_and_rejects_invalid_or_future_values(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer]);

        foreach (['pep', 'bankruptcy', 'petition'] as $kind) {
            $component->call('editDeclaration', $kind)->set('declaration.answer', 'not_declared')
                ->call('saveDeclaration')->assertHasNoErrors();
        }
        $component->call('editDeclaration', 'bankruptcy')->set('declaration.answer', 'unknown')
            ->call('saveDeclaration')->assertHasErrors('declaration.answer');
        $component->set('declaration.answer', 'no')->set('declaration.declared_on', now()->addDay()->toDateString())
            ->call('saveDeclaration')->assertHasErrors('declaration.declared_on');

        $this->assertSame(3, $customer->complianceDeclarations()->count());
        $this->assertSame('not_declared', $customer->complianceDeclarations()->where('kind', 'bankruptcy')->firstOrFail()->answer);
    }

    public function test_existing_relative_and_close_associate_are_separate_pep_associations(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $relative = CustomerRelationship::factory()->for($customer)->create();
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer]);
        $component->call('addAssociation')->set('association.source', 'relationship')
            ->set('association.relationship_id', (string) $relative->id)->call('saveAssociation')->assertHasNoErrors();
        $component->call('addAssociation')->set('association.source', 'relationship')
            ->set('association.relationship_id', (string) $relative->id)->call('saveAssociation')->assertHasErrors();
        $component->call('addAssociation')->set('association.name', 'Close Associate')
            ->set('association.relationship', 'Business associate')->call('saveAssociation')->assertHasNoErrors();
        $this->assertSame(2, $customer->pepAssociations()->count());

        $association = $customer->pepAssociations()->where('customer_relationship_id', $relative->id)->firstOrFail();
        $component->call('startEndAssociation', $association->id)->call('endAssociation')->assertHasNoErrors();
        $this->assertNotNull($association->fresh()->ended_on);
        $this->assertNotNull($relative->fresh());
        $this->assertSame(1, $customer->pepAssociations()->whereNull('ended_on')->count());
    }

    public function test_inactive_staff_cannot_view_or_modify_compliance(): void
    {
        $staff = User::factory()->staff()->inactive()->create();
        $customer = Customer::factory()->create();
        Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer])->assertForbidden();
    }

    public function test_association_edits_keep_previous_position_and_other_customer_records_are_inaccessible(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $other = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer]);
        $component->call('addAssociation')->set('association.name', 'Public Official')
            ->set('association.relationship', 'Close associate')->set('association.position', 'Minister')
            ->call('saveAssociation')->assertHasNoErrors();
        $original = $customer->pepAssociations()->firstOrFail();
        $component->call('editAssociation', $original->id)->set('association.position', 'Former minister')
            ->set('association.position_status', 'former')->call('saveAssociation')->assertHasNoErrors();
        $this->assertSame('Minister', $original->fresh()->position);
        $this->assertNotNull($original->fresh()->superseded_at);
        $this->assertSame('Former minister', $customer->pepAssociations()->whereNull('superseded_at')->firstOrFail()->position);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $other])
            ->call('viewAssociation', $original->id);
    }

    public function test_self_association_is_unique_and_does_not_change_with_pep_answer(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer]);
        $component->call('addAssociation')->set('association.source', 'self')->call('saveAssociation')->assertHasNoErrors();
        $component->call('addAssociation')->set('association.source', 'self')->call('saveAssociation')->assertHasErrors();
        $component->call('editDeclaration', 'pep')->set('declaration.answer', 'no')->call('saveDeclaration')->assertHasNoErrors();
        $this->assertSame(1, $customer->pepAssociations()->whereNull('ended_on')->count());
    }

    public function test_pep_identifier_is_masked_and_not_copied_to_activity_log(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer]);
        $component->call('addAssociation')->set('association.name', 'Associate')
            ->set('association.relationship', 'Close associate')
            ->set('association.identification_type', 'passport')
            ->set('association.identification_number', 'SECRET12345')
            ->call('saveAssociation')->assertHasNoErrors();

        $record = $customer->pepAssociations()->firstOrFail();
        $component->call('viewAssociation', $record->id)->assertSee('*******2345')->assertDontSee('SECRET12345');
        $this->assertStringNotContainsString('SECRET12345', $customer->activities()->firstOrFail()->description);
        $this->assertStringNotContainsString('SECRET12345', $record->relatedPerson->getRawOriginal('identification_number'));
    }

    public function test_standalone_pep_identity_correction_preserves_previous_revision_and_separates_occupation(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer]);
        $component->call('addAssociation')->set('association.name', 'Old Name')
            ->set('association.relationship', 'Associate')
            ->set('association.identification_type', 'passport')
            ->set('association.identification_number', 'OLD1234')
            ->set('association.occupation', 'Accountant')
            ->set('association.position', 'Minister')
            ->call('saveAssociation')->assertHasNoErrors();
        $original = $customer->pepAssociations()->firstOrFail();

        $component->call('editAssociation', $original->id)
            ->set('association.identification_type', 'nric')
            ->call('saveAssociation')->assertHasErrors('association.identification_number')
            ->set('association.identification_type', 'passport')
            ->set('association.name', 'Corrected Name')
            ->set('association.relationship', 'Close associate')
            ->set('association.identification_number', 'NEW5678')
            ->set('association.occupation', 'Lawyer')
            ->call('saveAssociation')->assertHasNoErrors();

        $current = $customer->pepAssociations()->whereNull('superseded_at')->firstOrFail();
        $this->assertSame('Old Name', $original->fresh()->relatedPerson->full_legal_name);
        $this->assertSame('OLD1234', $original->fresh()->relatedPerson->identification_number);
        $this->assertSame('Corrected Name', $current->relatedPerson->full_legal_name);
        $this->assertSame('NEW5678', $current->relatedPerson->identification_number);
        $this->assertSame('Close associate', $current->relationship);
        $this->assertSame('Lawyer', $current->occupation);
        $this->assertSame('Minister', $current->position);
    }

    public function test_legacy_pep_position_stays_unverified_until_staff_classifies_it(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $legacy = $customer->pepAssociations()->create([
            'relationship' => 'Self',
            'position' => 'Director',
            'position_status' => 'current',
            'active_identity_key' => "{$customer->id}:self",
            'started_on' => today(),
        ]);
        $component = Livewire::actingAs($staff)->test(Compliance::class, ['customer' => $customer]);

        $component->call('viewAssociation', $legacy->id)->assertSee('Legacy occupation / public position (unverified)');
        $component->call('editAssociation', $legacy->id)
            ->set('association.position_meaning', 'occupation')
            ->call('saveAssociation')->assertHasNoErrors();

        $current = $customer->pepAssociations()->whereNull('superseded_at')->firstOrFail();
        $this->assertNull($legacy->fresh()->position_meaning);
        $this->assertSame('Director', $legacy->fresh()->position);
        $this->assertSame('Director', $current->occupation);
        $this->assertNull($current->position);
        $this->assertSame('public_position', $current->position_meaning);
    }

    public function test_profile_has_compliance_tab(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        $this->actingAs($staff)->get(route('customers.show', $customer))->assertOk()->assertSee('Compliance Declarations')->assertSee('PEP-Related Persons');
    }
}
