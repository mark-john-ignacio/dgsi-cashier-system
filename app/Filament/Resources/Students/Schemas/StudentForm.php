<?php

namespace App\Filament\Resources\Students\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('student_no')
                    ->required()
                    ->maxLength(30)
                    ->unique(ignoreRecord: true),
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('middle_name')
                    ->maxLength(100),
                TextInput::make('guardian_name')
                    ->required()
                    ->maxLength(150),
                TextInput::make('guardian_contact')
                    ->required()
                    ->maxLength(30),
                Select::make('status')
                    ->options([
                        'enrolled' => 'Enrolled',
                        'withdrawn' => 'Withdrawn',
                    ])
                    ->default('enrolled')
                    ->required(),
            ]);
    }
}
