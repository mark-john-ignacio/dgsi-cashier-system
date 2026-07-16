<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index', ['activeYear' => SchoolYear::active()]);
    }

    public function daily(Request $request)
    {
        $date = $request->date('date') ?? today();
        $payments = Payment::with(['enrollment.student', 'receivedBy'])
            ->whereDate('payment_date', $date)
            ->orderBy('or_number')->get();

        $activeTotal = $payments->whereNull('voided_at');
        $totals = [
            'grand' => $activeTotal->sum(fn ($p) => (float) $p->amount),
            'byMethod' => $activeTotal->groupBy('method')->map(fn ($g) => $g->sum(fn ($p) => (float) $p->amount)),
        ];

        if ($request->boolean('csv')) {
            return $this->csv("daily-collections-{$date->toDateString()}.csv",
                ['OR No.', 'Student', 'Method', 'Amount', 'Status', 'Cashier'],
                $payments->map(fn ($p) => [
                    $p->or_number,
                    $p->enrollment->student->full_name,
                    strtoupper($p->method),
                    $p->amount,
                    $p->voided_at ? 'VOIDED: '.$p->void_reason : 'OK',
                    $p->receivedBy->name,
                ]));
        }

        return view('reports.daily', compact('payments', 'totals', 'date'));
    }

    public function unpaid(Request $request)
    {
        $year = SchoolYear::active();
        abort_unless($year !== null, 404, 'No active school year.');

        $rows = $this->unpaidEnrollments($year, $request->input('grade_level'));

        if ($request->boolean('csv')) {
            return $this->csv('unpaid-balances.csv',
                ['Student No.', 'Student', 'Grade', 'Section', 'Assessed', 'Paid', 'Balance', 'Promissory (pending)'],
                $rows->map(fn ($e) => [
                    $e->student->student_no, $e->student->full_name, $e->grade_level, $e->section,
                    number_format($e->assessed_total, 2, '.', ''), number_format($e->paid_total, 2, '.', ''),
                    number_format($e->balance_amount, 2, '.', ''), number_format($e->promised_total, 2, '.', ''),
                ]));
        }

        $gradeLevels = Enrollment::where('school_year_id', $year->id)
            ->distinct()->orderBy('grade_level')->pluck('grade_level');

        return view('reports.unpaid', compact('rows', 'year', 'gradeLevels'));
    }

    public function notice(Enrollment $enrollment)
    {
        return view('print.notice', ['enrollments' => collect([$this->loadForNotice($enrollment)])]);
    }

    public function batchNotices(Request $request)
    {
        $year = SchoolYear::active();
        abort_unless($year !== null, 404);

        $enrollments = $this->unpaidEnrollments($year, $request->input('grade_level'))
            ->map(fn ($e) => $this->loadForNotice($e));

        return view('print.notice', compact('enrollments'));
    }

    public function statement(Enrollment $enrollment)
    {
        $enrollment->load(['student', 'schoolYear', 'ledgerEntries.feeType',
            'payments' => fn ($q) => $q->orderBy('payment_date')->orderBy('id')]);

        return view('print.statement', compact('enrollment'));
    }

    private function unpaidEnrollments(SchoolYear $year, ?string $gradeLevel)
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
            ->each(function ($e) {
                $e->assessed_total = (float) $e->assessed_total;
                $e->paid_total = (float) $e->paid_total;
                $e->promised_total = (float) $e->promised_total;
                $e->balance_amount = round($e->assessed_total - $e->paid_total, 2);
            })
            ->filter(fn ($e) => $e->balance_amount > 0.005)
            ->sortBy([['grade_level', 'asc'], fn ($a, $b) => strcmp($a->student->full_name, $b->student->full_name)])
            ->values();
    }

    private function loadForNotice(Enrollment $enrollment): Enrollment
    {
        return $enrollment->load(['student', 'schoolYear', 'ledgerEntries.feeType']);
    }

    private function csv(string $filename, array $header, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, is_array($row) ? $row : $row->toArray());
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
