<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_has_full_name_accessor(): void
    {
        $s = Student::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $this->assertSame('Dela Cruz, Juan', $s->full_name);
    }

    public function test_student_cannot_enroll_twice_in_same_year(): void
    {
        $year = SchoolYear::factory()->create();
        $student = Student::factory()->create();
        Enrollment::create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level' => 'Grade 3']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Enrollment::create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level' => 'Grade 4']);
    }
}
