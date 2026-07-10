<form wire:submit="save" class="space-y-3">
    <div>
        <label class="block text-sm">OR Number (from booklet)</label>
        <input type="text" wire:model="or_number" class="border rounded px-3 py-2 w-full">
        @error('or_number') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm">Payment Date</label>
        <input type="date" wire:model="payment_date" class="border rounded px-3 py-2 w-full">
        @error('payment_date') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm">Amount (₱)</label>
        <input type="number" step="0.01" wire:model="amount" class="border rounded px-3 py-2 w-full">
        @error('amount') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
        <label class="flex items-center gap-2 text-sm mt-1">
            <input type="checkbox" wire:model="confirmOverpay"> Record as advance/credit if over the balance
        </label>
    </div>
    <div>
        <label class="block text-sm">Method</label>
        <select wire:model="method" class="border rounded px-3 py-2 w-full">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="bank">Bank Deposit</option>
        </select>
    </div>
    <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2" wire:loading.attr="disabled">
        <span wire:loading.remove>Save Payment</span><span wire:loading>Saving…</span>
    </button>
</form>
