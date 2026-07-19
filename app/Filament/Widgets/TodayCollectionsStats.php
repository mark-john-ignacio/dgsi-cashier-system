<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayCollectionsStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = Payment::active()->whereDate('payment_date', today());

        $todayTotal = (float) (clone $today)->sum('amount');
        $todayCount = (clone $today)->count();

        // Per-method breakdown, e.g. "cash 1,000.00, gcash 500.00"
        $methodBreakdown = (clone $today)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($total, $method) => $method.' '.number_format((float) $total, 2))
            ->implode(', ');

        return [
            Stat::make('Total today', '₱'.number_format($todayTotal, 2)),
            Stat::make('Payments count', (string) $todayCount)
                ->description($methodBreakdown),
        ];
    }
}
