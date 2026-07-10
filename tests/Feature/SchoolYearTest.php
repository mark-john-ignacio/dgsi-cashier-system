<?php

namespace Tests\Feature;

use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_makes_exactly_one_year_active(): void
    {
        $old = SchoolYear::factory()->create(['name' => '2025-2026', 'is_active' => true]);
        $new = SchoolYear::factory()->create(['name' => '2026-2027', 'is_active' => false]);

        $new->activate();

        $this->assertTrue($new->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame(1, SchoolYear::where('is_active', true)->count());
        $this->assertTrue(SchoolYear::active()->is($new));
    }

    public function test_active_returns_null_when_no_active_year(): void
    {
        $this->assertNull(SchoolYear::active());
    }

    public function test_activating_an_already_active_year_keeps_it_active(): void
    {
        $year = SchoolYear::factory()->create(['is_active' => true]);

        $year->activate();

        $this->assertTrue($year->fresh()->is_active);
        $this->assertTrue(SchoolYear::active()?->is($year) ?? false);
    }

    public function test_activate_is_idempotent_across_reloads(): void
    {
        SchoolYear::factory()->create(['name' => '2025-2026', 'is_active' => true]);
        $year = SchoolYear::factory()->create(['name' => '2026-2027']);

        $year->activate();
        SchoolYear::firstOrCreate(['name' => '2026-2027'])->activate();

        $this->assertSame(1, SchoolYear::where('is_active', true)->count());
        $this->assertTrue(SchoolYear::active()->is($year));
    }
}
