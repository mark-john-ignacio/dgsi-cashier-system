<?php

namespace App\Filament\Pages;

use App\Exceptions\DuplicateOrNumber;
use App\Models\Enrollment;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class StudentLedger extends Page
{
    protected string $view = 'filament.pages.student-ledger';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'ledger/{enrollment}';

    #[Locked]
    public Enrollment|int|string $enrollment;

    public function mount(int|string $enrollment): void
    {
        $this->enrollment = Enrollment::with([
            'student',
            'schoolYear',
            'ledgerEntries.feeType',
            'ledgerEntries.allocations' => fn ($q) => $q->whereHas('payment', fn ($q) => $q->whereNull('voided_at')),
            'ledgerEntries.allocations.payment',
            'payments' => fn ($q) => $q->orderBy('payment_date')->orderBy('id'),
            'payments.receivedBy',
            'promissoryNotes',
        ])->findOrFail($enrollment);
    }

    public function getTitle(): string
    {
        return $this->enrollment->student->full_name.' — Ledger';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordPayment')
                ->label('Record Payment')
                ->form([
                    TextInput::make('or_number')->label('OR Number')->required()->maxLength(50),
                    DatePicker::make('payment_date')->default(now())->required()->maxDate(now()),
                    TextInput::make('amount')->numeric()->required()->minValue(0.01),
                    Select::make('method')->options(['cash' => 'Cash', 'gcash' => 'GCash', 'bank' => 'Bank'])
                        ->default('cash')->required(),
                    Checkbox::make('confirm_overpay')->label('Record excess as advance/credit'),
                ])
                ->action(function (array $data) {
                    $statePath = $this->getMountedActionSchema()?->getStatePath();

                    $balance = $this->enrollment->balance();
                    $amount = (float) $data['amount'];

                    if ($amount > $balance + 0.005 && ! ($data['confirm_overpay'] ?? false)) {
                        $message = 'Amount exceeds the remaining balance of ₱'
                            .number_format($balance, 2).'. Tick "Record as advance/credit" to confirm.';

                        Notification::make()->danger()->title($message)->send();

                        throw ValidationException::withMessages(["{$statePath}.amount" => $message]);
                    }

                    try {
                        $payment = app(PaymentService::class)->record(
                            $this->enrollment,
                            trim($data['or_number']),
                            $data['payment_date'],
                            $amount,
                            $data['method'],
                            auth()->user(),
                        );
                    } catch (DuplicateOrNumber $e) {
                        throw ValidationException::withMessages([
                            "{$statePath}.or_number" => $e->getMessage(),
                        ]);
                    }

                    $this->redirect(route('slips.show', $payment));
                }),
        ];
    }
}
