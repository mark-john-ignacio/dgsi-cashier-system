<?php

namespace App\Filament\Resources\FeeStructures\Tables;

use App\Models\FeeStructure;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeeStructuresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('schoolYear.name')
                    ->label('School Year'),
                TextColumn::make('grade_level'),
                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Items'),
                TextColumn::make('total')
                    ->label('Total')
                    ->getStateUsing(fn (FeeStructure $record) => '₱'.number_format($record->total(), 2)),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
