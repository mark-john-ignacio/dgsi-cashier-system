<?php

namespace App\Filament\Resources\SchoolYears\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SchoolYearForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
            ]);
    }
}
