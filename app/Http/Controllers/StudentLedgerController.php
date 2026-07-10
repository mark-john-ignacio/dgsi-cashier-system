<?php

namespace App\Http\Controllers;

use App\Exceptions\VoidNotAllowed;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class StudentLedgerController extends Controller
{
    public function show(Enrollment $enrollment)
    {
        $enrollment->load(['student', 'schoolYear',
            'ledgerEntries.feeType',
            'payments' => fn ($q) => $q->orderBy('payment_date')->orderBy('id'),
            'promissoryNotes']);

        return view('ledger.show', compact('enrollment'));
    }

    public function storePromissory(Request $request, Enrollment $enrollment)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'due_date' => ['required', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $enrollment->promissoryNotes()->create($data + ['created_by' => $request->user()->id]);

        return redirect()->route('ledger.show', $enrollment)->with('status', 'Promissory note added.');
    }

    public function updatePromissoryStatus(Request $request, PromissoryNote $promissoryNote)
    {
        $data = $request->validate(['status' => ['required', 'in:pending,fulfilled,broken']]);
        $promissoryNote->update($data);

        return redirect()->route('ledger.show', $promissoryNote->enrollment)
            ->with('status', 'Promissory note marked '.$data['status'].'.');
    }

    public function voidPayment(Request $request, Payment $payment, PaymentService $service)
    {
        $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $service->void($payment, $request->user(), $request->input('reason'));
        } catch (VoidNotAllowed $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('ledger.show', $payment->enrollment)->with('status', 'Payment voided.');
    }
}
