<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\RegistrationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index()
    {
        return view('students.index', [
            'students' => Student::with('enrollments')
                ->orderBy('last_name')->orderBy('first_name')->paginate(30),
            'activeYear' => SchoolYear::active(),
        ]);
    }

    public function create()
    {
        return view('students.form', ['student' => new Student]);
    }

    public function store(Request $request)
    {
        $student = Student::create($this->validated($request));

        return redirect()->route('students.index')->with('status', "Student {$student->full_name} added.");
    }

    public function edit(Student $student)
    {
        return view('students.form', compact('student'));
    }

    public function update(Request $request, Student $student)
    {
        $student->update($this->validated($request, $student));

        return redirect()->route('students.index')->with('status', 'Student updated.');
    }

    public function register(Request $request, Student $student, RegistrationService $service)
    {
        $data = $request->validate([
            'grade_level' => ['required', 'string', 'max:30'],
            'section' => ['nullable', 'string', 'max:50'],
        ]);

        $year = SchoolYear::active();
        abort_unless($year !== null, 422, 'No active school year.');

        try {
            $enrollment = $service->register($student, $year, $data['grade_level'], $data['section'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['grade_level' => $e->getMessage()]);
        }

        return redirect()->route('ledger.show', $enrollment)->with('status', 'Student registered and assessed.');
    }

    private function validated(Request $request, ?Student $student = null): array
    {
        return $request->validate([
            'student_no' => ['required', 'string', 'max:30', Rule::unique('students')->ignore($student)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'guardian_name' => ['required', 'string', 'max:150'],
            'guardian_contact' => ['required', 'string', 'max:30'],
            'status' => ['sometimes', 'in:enrolled,withdrawn'],
        ]);
    }
}
