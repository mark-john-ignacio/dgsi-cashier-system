<?php

namespace App\Filament\Resources\Students;

use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\Schemas\StudentForm;
use App\Filament\Resources\Students\Tables\StudentsTable;
use App\Models\Enrollment;
use App\Models\Student;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return StudentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'create' => CreateStudent::route('/create'),
            'edit' => EditStudent::route('/{record}/edit'),
        ];
    }

    /**
     * Single source of the StudentLedger page URL. Task 8 introduces
     * `App\Filament\Pages\StudentLedger` at slug `ledger/{enrollment}` (see
     * docs/superpowers/specs/2026-07-17-filament-rewrite-design.md); once that
     * class exists this should delegate to
     * `StudentLedger::getUrl(['enrollment' => $enrollment->id])` instead of
     * building the path by hand.
     */
    public static function ledgerUrl(Enrollment $enrollment): string
    {
        return '/app/ledger/'.$enrollment->id;
    }
}
