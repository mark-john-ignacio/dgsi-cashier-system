<x-filament-panels::page>
    <x-filament::section heading="Filter">
        <form wire:submit.prevent>
            <label class="text-sm font-medium block mb-1" for="unpaid-balances-grade-level">Grade Level</label>
            <select
                id="unpaid-balances-grade-level"
                wire:model.live="gradeLevel"
                class="fi-select-input block w-full rounded-lg border-none bg-white px-3 py-1.5 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm"
            >
                <option value="">All grades</option>
                @foreach ($this->gradeLevels() as $level)
                    <option value="{{ $level }}">{{ $level }}</option>
                @endforeach
            </select>
        </form>
    </x-filament::section>

    <x-filament::section heading="Unpaid Balances">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-100 dark:border-white/5">
                    <th class="py-1">Student</th>
                    <th>Grade &amp; Section</th>
                    <th class="text-right">Assessed</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Balance</th>
                    <th class="text-right">Promissory</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows() as $enrollment)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-1">{{ $enrollment->student->full_name }}</td>
                        <td>{{ $enrollment->grade_level }} {{ $enrollment->section }}</td>
                        <td class="text-right">₱{{ number_format($enrollment->assessed_total, 2) }}</td>
                        <td class="text-right">₱{{ number_format($enrollment->paid_total, 2) }}</td>
                        <td class="text-right text-red-600">₱{{ number_format($enrollment->balance_amount, 2) }}</td>
                        <td class="text-right text-amber-600">
                            {{ $enrollment->promised_total > 0 ? '₱'.number_format($enrollment->promised_total, 2) : '—' }}
                        </td>
                        <td class="text-right">
                            <a class="text-primary-600 underline" href="{{ route('print.notice', $enrollment) }}" target="_blank">Print Notice</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-3 text-gray-500">Everyone is fully paid.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
