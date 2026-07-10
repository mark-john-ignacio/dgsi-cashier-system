<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Reports — {{ $activeYear?->name }}</h2></x-slot>
    <div class="py-6 max-w-3xl mx-auto px-4 space-y-4">
        <div class="bg-white shadow rounded p-6 space-y-2">
            <p><a class="text-blue-600 underline" href="{{ route('reports.daily') }}">Daily Collection Report</a> — today's payments; pick another date on the page.</p>
            <p><a class="text-blue-600 underline" href="{{ route('reports.unpaid') }}">Unpaid Balances</a> — who still owes, per grade; batch-print notices from there.</p>
        </div>
    </div>
</x-app-layout>
