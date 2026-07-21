<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestPayments extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Payment::active()
                ->whereDate('payment_date', today())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
            )
            ->columns([
                TextColumn::make('or_number')
                    ->label('OR Number')
                    ->sortable(),
                TextColumn::make('enrollment.student.full_name')
                    ->label('Student Name'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => '₱'.number_format($state, 2))
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Method')
                    ->sortable(),
                TextColumn::make('receivedBy.name')
                    ->label('Received By')
                    ->sortable(),
            ])
            ->paginated(false);
    }
}
