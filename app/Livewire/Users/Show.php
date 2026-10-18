<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('User Profile — DAS')]
class Show extends Component
{
    use NotifiesBrowser;

    public User $user;

    public bool $editing = false;

    public string $name = '';

    public string $email = '';

    public string $role = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(User $user): void
    {
        $this->user = $user;
    }

    public function edit(): void
    {
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->role = $this->user->role->value;
        $this->password = '';
        $this->password_confirmation = '';
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['nullable', 'confirmed', PasswordRule::defaults()],
        ]);

        if ($this->user->id === auth()->id() && UserRole::from($validated['role']) !== UserRole::Admin) {
            $this->addError('role', 'You cannot remove your own administrator role.');

            return;
        }

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => UserRole::from($validated['role']),
        ];

        if (filled($validated['password'] ?? null)) {
            $payload['password'] = Hash::make($validated['password']);
        }

        $this->user->update($payload);
        $this->user->refresh();
        $this->editing = false;
        $this->notifySuccess('User updated successfully.');
    }

    public function toggleActive(): void
    {
        if ($this->user->id === auth()->id()) {
            $this->notifyError('You cannot deactivate your own account.');

            return;
        }

        $this->user->update(['is_active' => ! $this->user->is_active]);
        $this->user->refresh();

        $this->notifySuccess($this->user->is_active ? 'User activated.' : 'User deactivated.');
    }

    public function sendPasswordReset(): void
    {
        Password::sendResetLink(['email' => $this->user->email]);

        $this->notifySuccess('Password reset link sent to '.$this->user->email.'.');
    }

    public function render(): View
    {
        return view('livewire.users.show')->layout('layouts.app');
    }
}
