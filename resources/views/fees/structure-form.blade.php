<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">{{ $structure->exists ? 'Edit' : 'New' }} Fee Structure</h2></x-slot>
    <div class="py-6 max-w-xl mx-auto px-4 bg-white shadow rounded p-6">
        @if ($errors->any()) <div class="bg-red-100 p-3 rounded mb-3">{{ $errors->first() }}</div> @endif
        <form method="POST"
              action="{{ $structure->exists ? route('fee-structures.update', $structure) : route('fee-structures.store') }}"
              class="space-y-3">
            @csrf
            @if ($structure->exists) @method('PUT') @endif
            <div>
                <label class="block text-sm">School Year</label>
                <select name="school_year_id" class="border rounded px-3 py-2 w-full" required>
                    @foreach ($years as $year)
                        <option value="{{ $year->id }}" @selected(old('school_year_id', $structure->school_year_id) == $year->id)>
                            {{ $year->name }}{{ $year->is_active ? ' (active)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm">Grade Level</label>
                <input type="text" name="grade_level" value="{{ old('grade_level', $structure->grade_level) }}"
                       placeholder="e.g. Grade 3" class="border rounded px-3 py-2 w-full" required>
            </div>
            <div id="items" class="space-y-2">
                <label class="block text-sm">Fees</label>
                @foreach (old('items', $structure->items->map(fn ($i) => ['fee_type_id' => $i->fee_type_id, 'amount' => $i->amount])->all() ?: [['fee_type_id' => '', 'amount' => '']]) as $index => $item)
                    <div class="flex gap-2 item-row">
                        <select name="items[{{ $index }}][fee_type_id]" class="border rounded px-3 py-2 flex-1" required>
                            @foreach ($feeTypes as $type)
                                <option value="{{ $type->id }}" @selected(($item['fee_type_id'] ?? '') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        <input type="number" step="0.01" name="items[{{ $index }}][amount]"
                               value="{{ $item['amount'] ?? '' }}" placeholder="Amount" class="border rounded px-3 py-2 w-32" required>
                    </div>
                @endforeach
            </div>
            <button type="button" onclick="addRow()" class="text-blue-600 underline text-sm">+ Add fee line</button>
            <div><button class="bg-blue-600 text-white rounded px-4 py-2">Save Structure</button></div>
        </form>
    </div>
    <script>
        function addRow() {
            const container = document.getElementById('items');
            const rows = container.querySelectorAll('.item-row');
            const clone = rows[rows.length - 1].cloneNode(true);
            const nextIndex = rows.length;
            clone.querySelectorAll('select, input').forEach(el => {
                el.name = el.name.replace(/\d+/, nextIndex);
                if (el.tagName === 'INPUT') el.value = '';
            });
            container.appendChild(clone);
        }
    </script>
</x-app-layout>
