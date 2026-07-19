<?php

namespace App\Filament\Pages;

use App\Models\Payment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class DailyCollections extends Page
{
    protected string $view = 'filament.pages.daily-collections';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Daily Collections';

    protected static ?string $title = 'Daily Collections';

    /**
     * CSV header, copied verbatim from ReportController@daily's csv branch —
     * this is a compatibility contract for existing consumers of the export.
     *
     * @var array<int, string>
     */
    private const CSV_HEADER = ['OR No.', 'Student', 'Method', 'Amount', 'Status', 'Cashier'];

    public ?string $date = null;

    public function mount(): void
    {
        $this->date ??= now()->toDateString();
    }

    /**
     * @return Collection<int, Payment>
     */
    protected function payments(): Collection
    {
        return Payment::with(['enrollment.student', 'receivedBy'])
            ->whereDate('payment_date', $this->date)
            ->orderBy('or_number')
            ->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    protected function activePayments(): Collection
    {
        return $this->payments()->whereNull('voided_at');
    }

    public function grandTotal(): float
    {
        return $this->activePayments()->sum(fn (Payment $p) => (float) $p->amount);
    }

    /**
     * @return Collection<string, float>
     */
    public function totalsByMethod(): Collection
    {
        return $this->activePayments()
            ->groupBy('method')
            ->map(fn (Collection $group) => $group->sum(fn (Payment $p) => (float) $p->amount));
    }

    /**
     * @return Collection<int, array{name: string, total: float}>
     */
    public function totalsByCashier(): Collection
    {
        return $this->activePayments()
            ->groupBy('received_by')
            ->map(fn (Collection $group) => [
                'name' => $group->first()->receivedBy->name,
                'total' => $group->sum(fn (Payment $p) => (float) $p->amount),
            ])
            ->values();
    }

    /**
     * Same row shape as ReportController@daily's csv branch.
     *
     * @return array<int, array<int, string|float>>
     */
    protected function reportRows(): array
    {
        return $this->payments()->map(fn (Payment $p) => [
            $p->or_number,
            $p->enrollment->student->full_name,
            strtoupper($p->method),
            $p->amount,
            $p->voided_at ? 'VOIDED: '.$p->void_reason : 'OK',
            $p->receivedBy->name,
        ])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    $rows = $this->reportRows();

                    return response()->streamDownload(function () use ($rows) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, self::CSV_HEADER);
                        foreach ($rows as $row) {
                            fputcsv($out, $row);
                        }
                        fclose($out);
                    }, 'daily-collections-'.$this->date.'.csv', ['Content-Type' => 'text/csv']);
                }),
        ];
    }
}
