<x-filament-panels::page>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-filament::section heading="Assessment">
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($enrollment->ledgerEntries as $entry)
                            <tr class="border-b border-gray-100 dark:border-white/5 {{ $entry->voided_at ? 'line-through text-gray-400' : '' }}">
                                <td class="py-1">{{ $entry->description }}</td>
                                <td class="text-right">₱{{ number_format($entry->amount, 2) }}</td>
                                <td class="text-right text-gray-500">
                                    Paid ₱{{ number_format($entry->paidAmount(), 2) }}
                                </td>
                                <td class="text-right text-gray-500">
                                    Unpaid ₱{{ number_format($entry->unpaidAmount(), 2) }}
                                </td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="py-2">Total Assessed</td>
                            <td class="text-right">₱{{ number_format($enrollment->totalAssessed(), 2) }}</td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section heading="Payments">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b border-gray-100 dark:border-white/5">
                            <th class="py-1">OR No.</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Received By</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($enrollment->payments as $payment)
                            <tr class="border-b border-gray-100 dark:border-white/5 {{ $payment->isVoided() ? 'line-through text-gray-400' : '' }}">
                                <td class="py-1">{{ $payment->or_number }}</td>
                                <td>{{ $payment->payment_date->format('M d, Y') }}</td>
                                <td>{{ strtoupper($payment->method) }}</td>
                                <td>{{ $payment->receivedBy?->name }}</td>
                                <td class="text-right">₱{{ number_format($payment->amount, 2) }}</td>
                                <td class="text-right">
                                    @if ($payment->isVoided())
                                        <span class="text-xs">Voided: {{ $payment->void_reason }}</span>
                                    @else
                                        <a href="{{ route('slips.show', $payment) }}" target="_blank" class="text-primary-600 underline text-xs">Slip</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-2 text-gray-500">No payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>

            @if ($enrollment->promissoryNotes->isNotEmpty())
                <x-filament::section heading="Promissory Notes">
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($enrollment->promissoryNotes as $note)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1">Due {{ $note->due_date->format('M d, Y') }}</td>
                                    <td>{{ $note->notes }}</td>
                                    <td class="uppercase text-xs">{{ $note->status }}</td>
                                    <td class="text-right">₱{{ number_format($note->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif
        </div>

        <div class="space-y-6">
            <x-filament::section heading="Current Balance">
                <div class="text-3xl font-bold {{ $enrollment->balance() > 0.005 ? 'text-danger-600' : 'text-success-600' }}">
                    ₱{{ number_format($enrollment->balance(), 2) }}
                </div>
                @if ($enrollment->advanceCredit() > 0.005)
                    <div class="mt-2 text-sm text-gray-500">
                        Advance credit: ₱{{ number_format($enrollment->advanceCredit(), 2) }}
                    </div>
                @endif
                <div class="mt-3 text-sm space-x-2">
                    <a class="text-primary-600 underline" target="_blank"
                       href="{{ route('reports.statement', $enrollment) }}">Statement</a>
                    <a class="text-primary-600 underline" target="_blank"
                       href="{{ route('reports.notice', $enrollment) }}">Notice</a>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
