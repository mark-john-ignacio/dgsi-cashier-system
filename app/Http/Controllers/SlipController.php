<?php

namespace App\Http\Controllers;

use App\Models\Payment;

class SlipController extends Controller
{
    public function show(Payment $payment)
    {
        $payment->load(['enrollment.student', 'enrollment.schoolYear', 'allocations.ledgerEntry', 'receivedBy']);

        return view('print.slip', compact('payment'));
    }
}
