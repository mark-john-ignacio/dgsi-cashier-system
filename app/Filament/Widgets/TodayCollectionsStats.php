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

        // Get per-method breakdown
        /** @var object{method: string, total: float}[] $breakdown */
        $breakdown = (clone $today)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->get()
            ->toArray();

        $methodBreakdown = collect($breakdown)
            ->mapWithKeys(fn ($row) => [$row['method'] => number_format((float) $row['total'], 2)])
            ->implode(', ');

        return [
            Stat::make('Total today', '₱'.number_format($todayTotal, 2)),
            Stat::make('Payments count', (string) $todayCount)
                ->description($methodBreakdown),
        ];
    }
}
