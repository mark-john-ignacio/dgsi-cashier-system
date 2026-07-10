<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">{{ $student->exists ? 'Edit' : 'Add' }} Student</h2></x-slot>
    <div class="py-6 max-w-xl mx-auto px-4 bg-white shadow rounded p-6">
        <form method="POST" action="{{ $student->exists ? route('students.update', $student) : route('students.store') }}" class="space-y-3">
            @csrf
            @if ($student->exists) @method('PUT') @endif
            @foreach (['student_no' => 'Student No.', 'first_name' => 'First Name', 'last_name' => 'Last Name',
                       'middle_name' => 'Middle Name (optional)', 'guardian_name' => 'Guardian Name',
                       'guardian_contact' => 'Guardian Contact'] as $field => $label)
                <div>
                    <label class="block text-sm">{{ $label }}</label>
                    <input type="text" name="{{ $field }}" value="{{ old($field, $student->$field) }}"
                           class="border rounded px-3 py-2 w-full" @if (! str_contains($field, 'middle')) required @endif>
                    @error($field) <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
                </div>
            @endforeach
            @if ($student->exists)
                <div>
                    <label class="block text-sm">Status</label>
                    <select name="status" class="border rounded px-3 py-2 w-full">
                        <option value="enrolled" @selected($student->status === 'enrolled')>Enrolled</option>
                        <option value="withdrawn" @selected($student->status === 'withdrawn')>Withdrawn</option>
                    </select>
                </div>
            @endif
            <button class="bg-blue-600 text-white rounded px-4 py-2">Save</button>
        </form>
    </div>
</x-app-layout>
