<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users — DAS')]
class Index extends Component
{
    use NotifiesBrowser;
    use WithPagination;

    public bool $showForm = false;

    public string $name = '';

    public string $email = '';

    public string $role = 'staff';

    public string $password = '';

    public string $password_confirmation = '';

    public string $search = '';

    public string $status = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => UserRole::from($validated['role']),
            'password' => Hash::make($validated['password']),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->notifySuccess('User created successfully.');

        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->showForm = false;
        $this->name = '';
        $this->email = '';
        $this->role = UserRole::Staff->value;
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetValidation();
    }

    public function render(): View
    {
        $hasFilters = filled($this->search) || $this->status !== 'all';

        $users = User::query()
            ->when($this->search, function ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%';
                $query->where(function ($builder) use ($like): void {
                    $builder->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.users.index', [
            'users' => $users,
            'hasFilters' => $hasFilters,
        ])->layout('layouts.app');
    }
}
