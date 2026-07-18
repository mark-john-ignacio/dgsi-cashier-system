<?php

namespace App\Filament\Resources\FeeStructures\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class FeeStructureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('school_year_id')
                    ->relationship('schoolYear', 'name')
                    ->required(),
                TextInput::make('grade_level')
                    ->required()
                    ->maxLength(30)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('school_year_id', $get('school_year_id')),
                    ),
                Repeater::make('items')
                    ->relationship()
                    ->schema([
                        Select::make('fee_type_id')
                            ->relationship('feeType', 'name')
                            ->required(),
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.01),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->required(),
            ]);
    }
}
