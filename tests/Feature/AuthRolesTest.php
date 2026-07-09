<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_role_middleware_blocks_cashier_from_admin_routes(): void
    {
        \Illuminate\Support\Facades\Route::get('/_test-admin', fn () => 'ok')
            ->middleware(['web', 'auth', 'role:admin']);

        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($cashier)->get('/_test-admin')->assertForbidden();
        $this->actingAs($admin)->get('/_test-admin')->assertOk();
    }

    public function test_admin_passes_cashier_role_checks(): void
    {
        \Illuminate\Support\Facades\Route::get('/_test-cashier', fn () => 'ok')
            ->middleware(['web', 'auth', 'role:cashier']);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/_test-cashier')->assertOk();
    }
}
