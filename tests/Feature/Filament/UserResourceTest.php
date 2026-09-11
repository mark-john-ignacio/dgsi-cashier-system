<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_cashier_cannot_see_resource(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->get(ListUsers::getUrl())->assertForbidden();
    }

    public function test_admin_creates_user(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Cash One',
                'email' => 'cash1@example.com',
                'role' => 'cashier',
                'password' => 'secret123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'cash1@example.com',
            'role' => 'cashier',
        ]);

        $user = User::where('email', 'cash1@example.com')->first();
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_duplicate_email_validation(): void
    {
        User::factory()->create(['email' => 'duplicate@example.com']);

        $this->actingAs($this->admin());
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Another User',
                'email' => 'duplicate@example.com',
                'role' => 'admin',
                'password' => 'password123',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_admin_edits_user_without_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'user@example.com',
            'password' => Hash::make('original_password'),
            'role' => 'cashier',
        ]);

        $this->actingAs($this->admin());
        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm([
                'name' => 'Updated Name',
                'email' => 'user@example.com',
                'role' => 'admin',
                'password' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('admin', $user->role);
        // Password should remain unchanged
        $this->assertTrue(Hash::check('original_password', $user->password));
    }

    public function test_admin_edits_user_with_password(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('original_password'),
        ]);

        $this->actingAs($this->admin());
        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm([
                'name' => 'Updated Name',
                'email' => 'user@example.com',
                'role' => 'cashier',
                'password' => 'new_password_123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('new_password_123', $user->password));
    }

    public function test_password_minimum_length(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'role' => 'cashier',
                'password' => 'short',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }
}
