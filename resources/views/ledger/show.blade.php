<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl">
            {{ $enrollment->student->full_name }}
            <span class="text-sm text-gray-500">
                {{ $enrollment->student->student_no }} · {{ $enrollment->grade_level }}
                {{ $enrollment->section }} · {{ $enrollment->schoolYear->name }}
            </span>
        </h2>
    </x-slot>
    <div class="py-6 max-w-6xl mx-auto px-4 grid grid-cols-3 gap-6">
        <div class="col-span-2 space-y-6">
            @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
            @if ($errors->any()) <div class="bg-red-100 p-3 rounded">{{ $errors->first() }}</div> @endif

            <div class="bg-white shadow rounded p-6">
                <h3 class="font-semibold mb-3">Assessment</h3>
                <table class="w-full text-sm">
                    @foreach ($enrollment->ledgerEntries as $entry)
                        <tr class="border-b {{ $entry->voided_at ? 'line-through text-gray-400' : '' }}">
                            <td class="py-1">{{ $entry->description }}</td>
                            <td class="text-right">₱{{ number_format($entry->amount, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold">
                        <td class="py-2">Total Assessed</td>
                        <td class="text-right">₱{{ number_format($enrollment->totalAssessed(), 2) }}</td>
                    </tr>
                </table>
            </div>

            <div class="bg-white shadow rounded p-6">
                <h3 class="font-semibold mb-3">Payments</h3>
                <table class="w-full text-sm">
                    <thead><tr class="text-left border-b"><th class="py-1">OR No.</th><th>Date</th><th>Method</th><th class="text-right">Amount</th><th></th></tr></thead>
                    @forelse ($enrollment->payments as $payment)
                        <tr class="border-b {{ $payment->isVoided() ? 'line-through text-gray-400' : '' }}">
                            <td class="py-1">{{ $payment->or_number }}</td>
                            <td>{{ $payment->payment_date->format('M d, Y') }}</td>
                            <td>{{ strtoupper($payment->method) }}</td>
                            <td class="text-right">₱{{ number_format($payment->amount, 2) }}</td>
                            <td class="text-right">
                                @if ($payment->isVoided())
                                    <span class="text-xs">VOID: {{ $payment->void_reason }}</span>
                                @else
                                    <a href="{{ route('slips.show', $payment) }}" class="text-blue-600 underline text-xs">Slip</a>
                                    <form method="POST" action="{{ route('payments.void', $payment) }}" class="inline"
                                          onsubmit="const r = prompt('Reason for voiding OR {{ $payment->or_number }}:'); if (!r) return false; this.reason.value = r;">
                                        @csrf
                                        <input type="hidden" name="reason">
                                        <button class="text-red-600 underline text-xs">Void</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-gray-500">No payments yet.</td></tr>
                    @endforelse
                </table>
            </div>

            @if ($enrollment->promissoryNotes->isNotEmpty())
                <div class="bg-white shadow rounded p-6">
                    <h3 class="font-semibold mb-3">Promissory Notes</h3>
                    <table class="w-full text-sm">
                        @foreach ($enrollment->promissoryNotes as $note)
                            <tr class="border-b">
                                <td class="py-1">Due {{ $note->due_date->format('M d, Y') }}</td>
                                <td>{{ $note->notes }}</td>
                                <td class="uppercase text-xs">{{ $note->status }}</td>
                                <td class="text-right">₱{{ number_format($note->amount, 2) }}</td>
                                <td class="text-right">
                                    @if ($note->status === 'pending')
                                        @foreach (['fulfilled', 'broken'] as $newStatus)
                                            <form method="POST" action="{{ route('promissory.status', $note) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ $newStatus }}">
                                                <button class="text-xs underline {{ $newStatus === 'fulfilled' ? 'text-green-700' : 'text-red-600' }}">
                                                    Mark {{ $newStatus }}
                                                </button>
                                            </form>
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white shadow rounded p-6">
                <div class="text-sm text-gray-500">Current Balance</div>
                <div class="text-3xl font-bold {{ $enrollment->balance() > 0.005 ? 'text-red-600' : 'text-green-700' }}">
                    ₱{{ number_format($enrollment->balance(), 2) }}
                </div>
                <div class="mt-3 text-sm space-x-2">
                    <a class="text-blue-600 underline" target="_blank"
                       href="{{ route('reports.statement', $enrollment) }}">Statement</a>
                    <a class="text-blue-600 underline" target="_blank"
                       href="{{ route('reports.notice', $enrollment) }}">Notice</a>
                </div>
            </div>
            <div class="bg-white shadow rounded p-6">
                <h3 class="font-semibold mb-3">Record Payment</h3>
                <livewire:record-payment :enrollment="$enrollment" />
            </div>
            <div class="bg-white shadow rounded p-6">
                <h3 class="font-semibold mb-3">Add Promissory Note</h3>
                <form method="POST" action="{{ route('promissory.store', $enrollment) }}" class="space-y-3">
                    @csrf
                    <input type="number" step="0.01" name="amount" placeholder="Amount" class="border rounded px-3 py-2 w-full" required>
                    <input type="date" name="due_date" class="border rounded px-3 py-2 w-full" required>
                    <input type="text" name="notes" placeholder="Notes (optional)" class="border rounded px-3 py-2 w-full">
                    <button class="bg-gray-700 text-white rounded px-4 py-2">Save Note</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
