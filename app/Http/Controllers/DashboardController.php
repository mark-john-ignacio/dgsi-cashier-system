<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\SchoolYear;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = Payment::active()->whereDate('payment_date', today());

        return view('dashboard', [
            'activeYear' => SchoolYear::active(),
            'todayTotal' => (float) (clone $today)->sum('amount'),
            'todayCount' => (clone $today)->count(),
        ]);
    }
}
