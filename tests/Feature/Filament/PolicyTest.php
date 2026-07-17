<?php

namespace Tests\Feature\Filament;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_only_models_deny_cashier(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([SchoolYear::class, FeeType::class, FeeStructure::class, User::class] as $model) {
            $this->assertFalse($cashier->can('viewAny', $model), "$model should deny cashier");
            $this->assertTrue($admin->can('viewAny', $model), "$model should allow admin");
            $this->assertFalse($admin->can('delete', new $model), "$model delete stays closed");
        }
    }

    public function test_void_policy_cashier_today_only(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);
        $today = Payment::factory()->create();
        $old = Payment::factory()->create(['created_at' => now()->subDays(2)]);

        $this->assertTrue($cashier->can('void', $today));
        $this->assertFalse($cashier->can('void', $old));
        $this->assertTrue($admin->can('void', $old));
    }
}
