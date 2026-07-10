<div>
    <div class="flex gap-3 mb-4">
        <input type="text" wire:model.live.debounce.300ms="query" placeholder="Search name or student no..."
               class="border rounded px-3 py-2 flex-1" autofocus>
        <select wire:model.live="gradeLevel" class="border rounded px-3 py-2">
            <option value="">All grades</option>
            @foreach ($gradeLevels as $g)
                <option value="{{ $g }}">{{ $g }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="withBalanceOnly"> With balance only
        </label>
    </div>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left border-b">
                <th class="py-2">Student No.</th><th>Name</th><th>Grade & Section</th>
                <th class="text-right">Balance</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($enrollments as $e)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-2">{{ $e->student->student_no }}</td>
                    <td>{{ $e->student->full_name }}</td>
                    <td>{{ $e->grade_level }}{{ $e->section ? ' — '.$e->section : '' }}</td>
                    <td class="text-right {{ $e->balance_amount > 0.005 ? ((float) $e->promised > 0 ? 'text-amber-600' : 'text-red-600') : 'text-green-700' }}">
                        ₱{{ number_format($e->balance_amount, 2) }}
                        @if ($e->balance_amount > 0.005 && (float) $e->promised > 0)
                            <span class="text-xs">(promissory)</span>
                        @endif
                    </td>
                    <td class="text-right">
                        <a href="{{ route('ledger.show', $e) }}" class="text-blue-600 underline">Open</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-4 text-gray-500">No students found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
