<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Daily Collections — {{ $date->format('F d, Y') }}</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 space-y-4">
        <form method="GET" class="flex gap-2 items-center">
            <input type="date" name="date" value="{{ $date->toDateString() }}" class="border rounded px-3 py-2">
            <button class="bg-blue-600 text-white rounded px-4 py-2">View</button>
            <a href="{{ route('reports.daily', ['date' => $date->toDateString(), 'csv' => 1]) }}"
               class="text-blue-600 underline">Download CSV</a>
            <button type="button" onclick="window.print()" class="text-blue-600 underline">Print</button>
        </form>
        <div class="bg-white shadow rounded p-6">
            <table class="w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">OR No.</th><th>Student</th><th>Method</th><th>Cashier</th><th class="text-right">Amount</th></tr></thead>
                @forelse ($payments as $p)
                    <tr class="border-b {{ $p->voided_at ? 'line-through text-gray-400' : '' }}">
                        <td class="py-1">{{ $p->or_number }} {{ $p->voided_at ? '(VOIDED)' : '' }}</td>
                        <td>{{ $p->enrollment->student->full_name }}</td>
                        <td>{{ strtoupper($p->method) }}</td>
                        <td>{{ $p->receivedBy->name }}</td>
                        <td class="text-right">₱{{ number_format($p->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-3 text-gray-500">No payments on this date.</td></tr>
                @endforelse
                @foreach ($totals['byMethod'] as $method => $amount)
                    <tr><td colspan="4" class="text-right py-1">Total ({{ strtoupper($method) }})</td>
                        <td class="text-right">₱{{ number_format($amount, 2) }}</td></tr>
                @endforeach
                <tr class="font-bold"><td colspan="4" class="text-right py-2">GRAND TOTAL</td>
                    <td class="text-right">₱{{ number_format($totals['grand'], 2) }}</td></tr>
            </table>
        </div>
    </div>
</x-app-layout>
