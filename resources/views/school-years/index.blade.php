<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">School Years</h2></x-slot>
    <div class="py-6 max-w-xl mx-auto px-4 space-y-4">
        @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
        <div class="bg-white shadow rounded p-6">
            <form method="POST" action="{{ route('school-years.store') }}" class="flex gap-2 mb-4">
                @csrf
                <input type="text" name="name" placeholder="e.g. 2026-2027" class="border rounded px-3 py-2 flex-1" required>
                <button class="bg-blue-600 text-white rounded px-4 py-2">Add</button>
            </form>
            <table class="w-full text-sm">
                @foreach ($years as $year)
                    <tr class="border-b">
                        <td class="py-2">{{ $year->name }}</td>
                        <td class="text-right">
                            @if ($year->is_active)
                                <span class="text-green-700 font-semibold">ACTIVE</span>
                            @else
                                <form method="POST" action="{{ route('school-years.activate', $year) }}" class="inline">
                                    @csrf <button class="text-blue-600 underline">Make active</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</x-app-layout>
