<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_create_student_and_register_to_active_year(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 1']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 20000]);

        $this->actingAs($cashier)->post(route('students.store'), [
            'student_no' => 'DGS-0001', 'first_name' => 'Ana', 'last_name' => 'Lim',
            'guardian_name' => 'Ben Lim', 'guardian_contact' => '09171234567',
        ])->assertRedirect();

        $student = Student::where('student_no', 'DGS-0001')->firstOrFail();

        $this->actingAs($cashier)->post(route('students.register', $student), [
            'grade_level' => 'Grade 1', 'section' => 'St. Mark',
        ])->assertRedirect();

        $enrollment = Enrollment::where('student_id', $student->id)->firstOrFail();
        $this->assertEqualsWithDelta(20000.00, $enrollment->balance(), 0.001);
    }

    public function test_fee_structure_management_is_admin_only(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);
        SchoolYear::factory()->create(['is_active' => true]);

        $this->actingAs($cashier)->get(route('fee-structures.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('fee-structures.index'))->assertOk();
    }

    public function test_admin_can_create_fee_structure_with_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $tuition = FeeType::create(['name' => 'Tuition Fee']);
        $books = FeeType::create(['name' => 'Books']);

        $this->actingAs($admin)->post(route('fee-structures.store'), [
            'school_year_id' => $year->id,
            'grade_level' => 'Grade 2',
            'items' => [
                ['fee_type_id' => $tuition->id, 'amount' => 22000],
                ['fee_type_id' => $books->id, 'amount' => 3000],
            ],
        ])->assertRedirect();

        $structure = FeeStructure::where('grade_level', 'Grade 2')->firstOrFail();
        $this->assertEqualsWithDelta(25000.00, $structure->total(), 0.001);
    }

    public function test_admin_can_activate_school_year(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $old = SchoolYear::factory()->create(['is_active' => true]);
        $new = SchoolYear::factory()->create();

        $this->actingAs($admin)->post(route('school-years.activate', $new))->assertRedirect();

        $this->assertTrue($new->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
    }
}
