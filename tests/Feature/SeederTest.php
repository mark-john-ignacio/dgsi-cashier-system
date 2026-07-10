<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_a_realistic_dataset(): void
    {
        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        $this->assertNotNull(SchoolYear::active());
        $this->assertGreaterThanOrEqual(50, Enrollment::count());

        $enrollments = Enrollment::all();
        $this->assertTrue($enrollments->contains(fn ($e) => $e->balance() <= 0.005), 'some fully paid');
        $this->assertTrue($enrollments->contains(fn ($e) => $e->balance() > 0.005 && $e->totalPaid() > 0), 'some partial');
        $this->assertTrue($enrollments->contains(fn ($e) => $e->pendingPromissoryTotal() > 0), 'some promissory');
    }
}
