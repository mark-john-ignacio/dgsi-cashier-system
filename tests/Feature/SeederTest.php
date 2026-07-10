<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SchoolYear;
use App\Models\Student;
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

    /**
     * The production image is built with `composer install --no-dev`, which omits
     * fakerphp/faker. Model factories call fake(), so the seeder must not use them.
     */
    public function test_demo_seeder_does_not_depend_on_model_factories(): void
    {
        $code = collect(token_get_all(file_get_contents(database_path('seeders/DemoDataSeeder.php'))))
            ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)
            ->implode('');

        $this->assertStringNotContainsString('factory(', $code);
        $this->assertStringNotContainsString('fake(', $code);
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        $students = Student::count();
        $enrollments = Enrollment::count();
        $payments = Payment::count();

        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        $this->assertSame($students, Student::count());
        $this->assertSame($enrollments, Enrollment::count());
        $this->assertSame($payments, Payment::count());
    }
}
