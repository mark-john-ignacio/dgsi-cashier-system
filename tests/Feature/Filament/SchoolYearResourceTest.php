<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SchoolYears\Pages\ListSchoolYears;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolYearResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_cashier_cannot_see_resource(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->get(ListSchoolYears::getUrl())->assertForbidden();
    }

    public function test_admin_lists_school_years(): void
    {
        $year = SchoolYear::factory()->create(['name' => 'SY 2026-2027']);
        $this->actingAs($this->admin());
        Livewire::test(ListSchoolYears::class)->assertCanSeeTableRecords([$year]);
    }

    public function test_activate_action_switches_active_year(): void
    {
        $old = SchoolYear::factory()->create(['is_active' => true]);
        $new = SchoolYear::factory()->create(['is_active' => false]);
        $this->actingAs($this->admin());

        Livewire::test(ListSchoolYears::class)->callTableAction('activate', $new);

        $this->assertTrue($new->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_activating_active_year_keeps_it_active(): void
    {
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $this->actingAs($this->admin());
        Livewire::test(ListSchoolYears::class)->callTableAction('activate', $year);
        $this->assertTrue($year->fresh()->is_active);
    }
}
