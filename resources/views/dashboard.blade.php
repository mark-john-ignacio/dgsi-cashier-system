<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl">Dashboard
            <span class="text-sm text-gray-500">{{ $activeYear?->name ?? 'No active school year' }}</span>
        </h2>
    </x-slot>
    <div class="py-6 max-w-5xl mx-auto space-y-6 px-4">
        <div class="grid grid-cols-2 gap-4">
            <div class="bg-white shadow rounded p-6">
                <div class="text-sm text-gray-500">Today's Collections</div>
                <div class="text-3xl font-bold">₱{{ number_format($todayTotal, 2) }}</div>
            </div>
            <div class="bg-white shadow rounded p-6">
                <div class="text-sm text-gray-500">Payments Today</div>
                <div class="text-3xl font-bold">{{ $todayCount }}</div>
            </div>
        </div>
        <div class="bg-white shadow rounded p-6">
            <livewire:student-search />
        </div>
    </div>
</x-app-layout>
