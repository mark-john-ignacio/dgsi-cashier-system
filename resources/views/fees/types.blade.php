<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Fee Types</h2></x-slot>
    <div class="py-6 max-w-xl mx-auto px-4 space-y-4">
        @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
        <div class="bg-white shadow rounded p-6">
            <form method="POST" action="{{ route('fee-types.store') }}" class="flex gap-2 mb-4">
                @csrf
                <input type="text" name="name" placeholder="e.g. Uniform" class="border rounded px-3 py-2 flex-1" required>
                <button class="bg-blue-600 text-white rounded px-4 py-2">Add</button>
            </form>
            @error('name') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            <ul class="text-sm divide-y">
                @foreach ($feeTypes as $type) <li class="py-2">{{ $type->name }}</li> @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>
