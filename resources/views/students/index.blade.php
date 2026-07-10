<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Students</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 space-y-4">
        @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
        <a href="{{ route('students.create') }}" class="bg-blue-600 text-white rounded px-4 py-2 inline-block">Add Student</a>
        <div class="bg-white shadow rounded p-6">
            <table class="w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">Student No.</th><th>Name</th><th>Guardian</th><th>Status</th><th></th></tr></thead>
                @foreach ($students as $student)
                    @php $enrollment = $activeYear ? $student->enrollments->firstWhere('school_year_id', $activeYear->id) : null; @endphp
                    <tr class="border-b">
                        <td class="py-2">{{ $student->student_no }}</td>
                        <td>{{ $student->full_name }}</td>
                        <td>{{ $student->guardian_name }} ({{ $student->guardian_contact }})</td>
                        <td>{{ $student->status }}</td>
                        <td class="text-right space-x-2">
                            <a href="{{ route('students.edit', $student) }}" class="text-blue-600 underline">Edit</a>
                            @if ($activeYear && ! $enrollment)
                                <form method="POST" action="{{ route('students.register', $student) }}" class="inline-flex gap-1">
                                    @csrf
                                    <input type="text" name="grade_level" placeholder="Grade 1" class="border rounded px-2 py-1 w-24 text-xs" required>
                                    <input type="text" name="section" placeholder="Section" class="border rounded px-2 py-1 w-24 text-xs">
                                    <button class="text-green-700 underline">Register {{ $activeYear->name }}</button>
                                </form>
                            @elseif ($enrollment)
                                <a href="{{ route('ledger.show', $enrollment) }}" class="text-green-700 underline">Ledger</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            {{ $students->links() }}
        </div>
    </div>
</x-app-layout>
