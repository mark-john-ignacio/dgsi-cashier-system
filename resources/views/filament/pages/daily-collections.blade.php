<x-filament-panels::page>
    <x-filament::section heading="Filter">
        <form wire:submit.prevent>
            <label class="text-sm font-medium block mb-1" for="daily-collections-date">Date</label>
            <input
                id="daily-collections-date"
                type="date"
                wire:model.live="date"
                class="fi-input block w-full rounded-lg border-none bg-white px-3 py-1.5 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm"
            >
        </form>
    </x-filament::section>

    <x-filament::section heading="Payments — {{ \Illuminate\Support\Carbon::parse($date)->format('F d, Y') }}">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-100 dark:border-white/5">
                    <th class="py-1">OR No.</th>
                    <th>Student</th>
                    <th>Method</th>
                    <th>Cashier</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->payments() as $payment)
                    <tr class="border-b border-gray-100 dark:border-white/5 {{ $payment->isVoided() ? 'line-through text-gray-400' : '' }}">
                        <td class="py-1">{{ $payment->or_number }}</td>
                        <td>{{ $payment->enrollment->student->full_name }}</td>
                        <td>{{ strtoupper($payment->method) }}</td>
                        <td>{{ $payment->receivedBy?->name }}</td>
                        <td class="text-right">₱{{ number_format($payment->amount, 2) }}</td>
                        <td class="text-right text-xs">
                            @if ($payment->isVoided())
                                VOIDED: {{ $payment->void_reason }}
                            @else
                                OK
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-3 text-gray-500">No payments on this date.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-filament::section heading="Totals by Cashier">
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($this->totalsByCashier() as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1">{{ $row['name'] }}</td>
                            <td class="text-right">₱{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-gray-500">No collections.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Totals by Method">
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($this->totalsByMethod() as $method => $total)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1">{{ strtoupper($method) }}</td>
                            <td class="text-right">₱{{ number_format($total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-gray-500">No collections.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>

    <x-filament::section>
        <div class="text-right font-bold text-lg">
            GRAND TOTAL: ₱{{ number_format($this->grandTotal(), 2) }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
