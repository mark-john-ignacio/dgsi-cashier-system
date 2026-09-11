<?php

namespace App\Filament\Pages;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class UnpaidBalances extends Page
{
    protected string $view = 'filament.pages.unpaid-balances';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Unpaid Balances';

    protected static ?string $title = 'Unpaid Balances';

    /**
     * CSV header, copied verbatim from ReportController@unpaid's csv branch —
     * this is a compatibility contract for existing consumers of the export.
     *
     * @var array<int, string>
     */
    private const CSV_HEADER = ['Student No.', 'Student', 'Grade', 'Section', 'Assessed', 'Paid', 'Balance', 'Promissory (pending)'];

    public ?string $gradeLevel = null;

    /**
     * Per-render memo of the unpaid-enrollments query. Keyed by the grade
     * level filter it was built for, so a `$gradeLevel` change transparently
     * invalidates it.
     *
     * @var Collection<int, Enrollment>|null
     */
    protected ?Collection $rowsCache = null;

    protected bool $rowsCached = false;

    protected ?string $rowsCacheGradeLevel = null;

    /**
     * @return Collection<int, Enrollment>
     */
    protected function rows(): Collection
    {
        if (! $this->rowsCached || $this->rowsCacheGradeLevel !== $this->gradeLevel) {
            $year = SchoolYear::active();
            $this->rowsCache = $year === null ? collect() : $this->unpaidEnrollments($year, $this->gradeLevel);
            $this->rowsCacheGradeLevel = $this->gradeLevel;
            $this->rowsCached = true;
        }

        return $this->rowsCache;
    }

    /**
     * @return Collection<int, string>
     */
    public function gradeLevels(): Collection
    {
        $year = SchoolYear::active();

        if ($year === null) {
            return collect();
        }

        return Enrollment::where('school_year_id', $year->id)
            ->distinct()->orderBy('grade_level')->pluck('grade_level');
    }

    /**
     * Same row shape as ReportController@unpaid's csv branch.
     *
     * @return array<int, array<int, string>>
     */
    protected function reportRows(): array
    {
        return $this->rows()->map(fn (Enrollment $e) => [
            $e->student->student_no,
            $e->student->full_name,
            $e->grade_level,
            $e->section,
            number_format($e->assessed_total, 2, '.', ''),
            number_format($e->paid_total, 2, '.', ''),
            number_format($e->balance_amount, 2, '.', ''),
            number_format($e->promised_total, 2, '.', ''),
        ])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printNotices')
                ->label('Print Notices (Batch)')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('print.notices.batch', array_filter(['grade_level' => $this->gradeLevel])))
                ->openUrlInNewTab(),
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
                    }, 'unpaid-balances.csv', ['Content-Type' => 'text/csv']);
                }),
        ];
    }

    /**
     * Copied verbatim from ReportController@unpaidEnrollments (same query
     * shape, same balance filter) so the page's rows/totals/CSV match the
     * old report exactly.
     *
     * @return Collection<int, Enrollment>
     */
    private function unpaidEnrollments(SchoolYear $year, ?string $gradeLevel): Collection
    {
        $q = Enrollment::with('student')
            ->where('school_year_id', $year->id)
            ->withSum(['ledgerEntries as assessed_total' => fn ($s) => $s->whereNull('voided_at')], 'amount')
            ->withSum(['payments as paid_total' => fn ($s) => $s->whereNull('voided_at')], 'amount')
            ->withSum(['promissoryNotes as promised_total' => fn ($s) => $s->where('status', 'pending')], 'amount');

        if ($gradeLevel) {
            $q->where('grade_level', $gradeLevel);
        }

        return $q->orderBy('grade_level')->get()
            ->each(function (Enrollment $e) {
                $e->assessed_total = (float) $e->assessed_total;
                $e->paid_total = (float) $e->paid_total;
                $e->promised_total = (float) $e->promised_total;
                $e->balance_amount = round($e->assessed_total - $e->paid_total, 2);
            })
            ->filter(fn (Enrollment $e) => $e->balance_amount > 0.005)
            ->sortBy([['grade_level', 'asc'], fn ($a, $b) => strcmp($a->student->full_name, $b->student->full_name)])
            ->values();
    }
}
