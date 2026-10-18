<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Users\Index;
use App\Livewire\Users\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_user_opens_modal_over_user_list(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Index::class)
            ->assertDontSee('aria-labelledby="user-form-title"', false)
            ->call('create')
            ->assertSee('aria-labelledby="user-form-title"', false)
            ->assertSee('Search users')
            ->call('cancel')
            ->assertDontSee('aria-labelledby="user-form-title"', false);
    }

    public function test_staff_cannot_access_users_page(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_access_users_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Users');
    }

    public function test_admin_can_create_staff_user(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Staff Member')
            ->set('email', 'staff@example.com')
            ->set('role', UserRole::Staff->value)
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'staff@example.com',
            'role' => UserRole::Staff->value,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_deactivate_user(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        Livewire::actingAs($admin)
            ->test(Show::class, ['user' => $staff])
            ->call('toggleActive');

        $this->assertFalse($staff->fresh()->is_active);
    }

    public function test_staff_cannot_access_user_detail_page(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get(route('users.show', $staff))
            ->assertForbidden();
    }

    public function test_selecting_user_opens_detail_page(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create([
            'name' => 'Detail View User',
            'email' => 'detail@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee(route('users.show', $staff), false);

        $this->get(route('users.show', $staff))
            ->assertOk()
            ->assertSee('Account Details')
            ->assertSee('Detail View User')
            ->assertSee('detail@example.com')
            ->assertDontSee('Save User');
    }

    public function test_editing_user_returns_to_updated_details(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        Livewire::actingAs($admin)
            ->test(Show::class, ['user' => $staff])
            ->call('edit')
            ->assertSet('editing', true)
            ->set('name', 'Updated Staff Name')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertSee('Updated Staff Name')
            ->assertSee('Account Details');

        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'name' => 'Updated Staff Name',
        ]);
    }

    public function test_admin_cannot_remove_own_administrator_role(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(Show::class, ['user' => $admin])
            ->call('edit')
            ->set('role', UserRole::Staff->value)
            ->call('save')
            ->assertHasErrors('role');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(Show::class, ['user' => $admin])
            ->call('toggleActive');

        $this->assertTrue($admin->fresh()->is_active);
    }
}
