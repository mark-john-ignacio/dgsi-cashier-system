<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
