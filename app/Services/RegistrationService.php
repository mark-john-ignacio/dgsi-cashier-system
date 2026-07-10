<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function register(Student $student, SchoolYear $year, string $gradeLevel, ?string $section = null): Enrollment
    {
        $structure = FeeStructure::where('school_year_id', $year->id)
            ->where('grade_level', $gradeLevel)->with('items.feeType')->first();

        if (! $structure) {
            throw new \RuntimeException("No fee structure for {$gradeLevel} in {$year->name}.");
        }

        return DB::transaction(function () use ($student, $year, $gradeLevel, $section, $structure) {
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'school_year_id' => $year->id,
                'grade_level' => $gradeLevel,
                'section' => $section,
            ]);

            foreach ($structure->items as $item) {
                $enrollment->ledgerEntries()->create([
                    'fee_type_id' => $item->fee_type_id,
                    'type' => 'charge',
                    'description' => $item->feeType->name,
                    'amount' => $item->amount,
                    'created_by' => auth()->id(),
                ]);
            }

            return $enrollment;
        });
    }
}
