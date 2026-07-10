<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Unpaid Balances — {{ $year->name }}</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 space-y-4">
        <form method="GET" class="flex gap-2 items-center">
            <select name="grade_level" class="border rounded px-3 py-2">
                <option value="">All grades</option>
                @foreach ($gradeLevels as $g)
                    <option value="{{ $g }}" @selected(request('grade_level') === $g)>{{ $g }}</option>
                @endforeach
            </select>
            <button class="bg-blue-600 text-white rounded px-4 py-2">Filter</button>
            <a href="{{ route('reports.unpaid', array_filter(['grade_level' => request('grade_level'), 'csv' => 1])) }}"
               class="text-blue-600 underline">Download CSV</a>
            <a href="{{ route('reports.notices.batch', array_filter(['grade_level' => request('grade_level')])) }}"
               target="_blank" class="text-blue-600 underline">Print Notices (batch)</a>
        </form>
        <div class="bg-white shadow rounded p-6">
            <table class="w-full text-sm">
                <thead><tr class="text-left border-b">
                    <th class="py-2">Student</th><th>Grade & Section</th>
                    <th class="text-right">Assessed</th><th class="text-right">Paid</th>
                    <th class="text-right">Balance</th><th class="text-right">Promissory</th><th></th>
                </tr></thead>
                @forelse ($rows as $e)
                    <tr class="border-b">
                        <td class="py-1">{{ $e->student->full_name }}</td>
                        <td>{{ $e->grade_level }} {{ $e->section }}</td>
                        <td class="text-right">₱{{ number_format($e->assessed_total, 2) }}</td>
                        <td class="text-right">₱{{ number_format($e->paid_total, 2) }}</td>
                        <td class="text-right text-red-600">₱{{ number_format($e->balance_amount, 2) }}</td>
                        <td class="text-right text-amber-600">{{ $e->promised_total > 0 ? '₱'.number_format($e->promised_total, 2) : '—' }}</td>
                        <td class="text-right"><a class="text-blue-600 underline" href="{{ route('ledger.show', $e) }}">Ledger</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-3 text-gray-500">Everyone is fully paid. 🎉</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</x-app-layout>
