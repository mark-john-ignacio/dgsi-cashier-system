<?php

namespace App\Filament\Pages;

use App\Models\Enrollment;
use Filament\Pages\Page;
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
}
