<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Fee Structures</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 space-y-4">
        @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
        <a href="{{ route('fee-structures.create') }}" class="bg-blue-600 text-white rounded px-4 py-2 inline-block">New Fee Structure</a>
        @foreach ($structures as $structure)
            <div class="bg-white shadow rounded p-6">
                <div class="flex justify-between">
                    <h3 class="font-semibold">{{ $structure->grade_level }} — {{ $structure->schoolYear->name }}</h3>
                    <a href="{{ route('fee-structures.edit', $structure) }}" class="text-blue-600 underline text-sm">Edit</a>
                </div>
                <table class="w-full text-sm mt-2">
                    @foreach ($structure->items as $item)
                        <tr class="border-b"><td class="py-1">{{ $item->feeType->name }}</td>
                            <td class="text-right">₱{{ number_format($item->amount, 2) }}</td></tr>
                    @endforeach
                    <tr class="font-semibold"><td class="py-1">Total</td>
                        <td class="text-right">₱{{ number_format($structure->total(), 2) }}</td></tr>
                </table>
            </div>
        @endforeach
    </div>
</x-app-layout>
