<?php

namespace App\Livewire;

use App\Exceptions\DuplicateOrNumber;
use App\Models\Enrollment;
use App\Services\PaymentService;
use Livewire\Component;

class RecordPayment extends Component
{
    public Enrollment $enrollment;

    public string $or_number = '';

    public string $payment_date = '';

    public string $amount = '';

    public string $method = 'cash';

    public bool $confirmOverpay = false;

    public function mount(): void
    {
        $this->payment_date = now()->toDateString();
    }

    public function save(PaymentService $service)
    {
        $this->validate([
            'or_number' => ['required', 'string', 'max:50'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,gcash,bank'],
        ]);

        $amount = (float) $this->amount;
        $balance = $this->enrollment->balance();

        if ($amount > $balance + 0.005 && ! $this->confirmOverpay) {
            $this->addError('amount',
                'Amount exceeds the remaining balance of ₱'.number_format($balance, 2)
                .'. Tick "Record as advance/credit" to confirm.');

            return;
        }

        try {
            $payment = $service->record($this->enrollment, trim($this->or_number),
                $this->payment_date, $amount, $this->method, auth()->user());
        } catch (DuplicateOrNumber $e) {
            $this->addError('or_number', $e->getMessage());

            return;
        }

        return $this->redirectRoute('slips.show', $payment);
    }

    public function render()
    {
        return view('livewire.record-payment');
    }
}
