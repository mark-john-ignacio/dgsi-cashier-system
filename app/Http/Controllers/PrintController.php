<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Thin pass-through controller rendering the same print Blade views as the
 * old SlipController/ReportController print methods. New route names
 * (print.*), same views, same view data — this is the target for Task 14's
 * link cutover, added alongside (not replacing) the old routes.
 */
class PrintController extends Controller
{
    public function slip(Payment $payment)
    {
        $payment->load(['enrollment.student', 'enrollment.schoolYear', 'allocations.ledgerEntry', 'receivedBy']);

        return view('print.slip', compact('payment'));
    }

    public function notice(Enrollment $enrollment)
    {
        return view('print.notice', ['enrollments' => collect([$this->loadForNotice($enrollment)])]);
    }

    public function statement(Enrollment $enrollment)
    {
        $enrollment->load(['student', 'schoolYear', 'ledgerEntries.feeType',
            'payments' => fn ($q) => $q->orderBy('payment_date')->orderBy('id')]);

        return view('print.statement', compact('enrollment'));
    }

    public function batchNotices(Request $request)
    {
        $year = SchoolYear::active();
        abort_unless($year !== null, 404);

        $enrollments = $this->unpaidEnrollments($year, $request->input('grade_level'))
            ->map(fn (Enrollment $e) => $this->loadForNotice($e));

        return view('print.notice', compact('enrollments'));
    }

    private function loadForNotice(Enrollment $enrollment): Enrollment
    {
        return $enrollment->load(['student', 'schoolYear', 'ledgerEntries.feeType']);
    }

    /**
     * Copied verbatim from ReportController@unpaidEnrollments — kept private
     * here too since PrintController must remain a standalone pass-through
     * (old ReportController is untouched until Task 14's cutover).
     *
     * @return Collection<int, Enrollment>
     */
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
