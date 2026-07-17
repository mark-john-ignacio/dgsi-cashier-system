<?php

namespace App\Filament\Resources\SchoolYears\Tables;

use App\Models\SchoolYear;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SchoolYearsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('activate')
                    ->requiresConfirmation()
                    ->action(fn (SchoolYear $record) => $record->activate()),
                EditAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
