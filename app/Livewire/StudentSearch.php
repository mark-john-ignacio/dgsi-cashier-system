<?php

namespace App\Livewire;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use Livewire\Component;

class StudentSearch extends Component
{
    public string $query = '';

    public string $gradeLevel = '';

    public bool $withBalanceOnly = false;

    public function render()
    {
        $year = SchoolYear::active();
        $enrollments = collect();
        $gradeLevels = [];

        if ($year) {
            $q = Enrollment::with('student')
                ->where('school_year_id', $year->id)
                ->withSum(['ledgerEntries as assessed' => fn ($s) => $s->whereNull('voided_at')], 'amount')
                ->withSum(['payments as paid' => fn ($s) => $s->whereNull('voided_at')], 'amount')
                ->withSum(['promissoryNotes as promised' => fn ($s) => $s->where('status', 'pending')], 'amount');

            if ($this->query !== '') {
                $q->whereHas('student', function ($s) {
                    $s->where('first_name', 'like', "%{$this->query}%")
                        ->orWhere('last_name', 'like', "%{$this->query}%")
                        ->orWhere('student_no', 'like', "%{$this->query}%");
                });
            }
            if ($this->gradeLevel !== '') {
                $q->where('grade_level', $this->gradeLevel);
            }

            $enrollments = $q->orderBy('grade_level')->get()
                ->map(function ($e) {
                    $e->balance_amount = round((float) $e->assessed - (float) $e->paid, 2);

                    return $e;
                });

            if ($this->withBalanceOnly) {
                $enrollments = $enrollments->filter(fn ($e) => $e->balance_amount > 0.005)->values();
            }

            $gradeLevels = Enrollment::where('school_year_id', $year->id)
                ->distinct()->orderBy('grade_level')->pluck('grade_level');
        }

        return view('livewire.student-search', compact('enrollments', 'gradeLevels'));
    }
}
