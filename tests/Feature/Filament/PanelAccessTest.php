<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_panel_login(): void
    {
        $this->get('/app')->assertRedirect('/app/login');
    }

    public function test_cashier_and_admin_can_access_panel(): void
    {
        foreach (['cashier', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/app')->assertOk();
        }
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/app/login')->assertOk();
    }

    public function test_valid_credentials_authenticate_via_panel_login(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest();
    }

    public function test_logout_clears_session(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($user)
            ->post(route('filament.app.auth.logout'))
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_cashier_can_open_own_profile_page(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)
            ->get(route('filament.app.auth.profile'))
            ->assertOk();
    }

    public function test_cashier_can_update_own_name_via_profile_page(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Old Name']);

        Livewire::actingAs($cashier)
            ->test(EditProfile::class)
            ->fillForm(['name' => 'New Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('New Name', $cashier->fresh()->name);
    }
}
