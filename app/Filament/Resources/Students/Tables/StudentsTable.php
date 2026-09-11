<?php

namespace App\Filament\Resources\Students\Tables;

use App\Filament\Resources\Students\StudentResource;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\RegistrationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student_no')
                    ->searchable(),
                TextColumn::make('last_name')
                    ->searchable(),
                TextColumn::make('first_name')
                    ->searchable(),
                TextColumn::make('guardian_name'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('register')
                    ->label('Register')
                    ->form([
                        TextInput::make('grade_level')
                            ->required()
                            ->maxLength(30),
                        TextInput::make('section')
                            ->maxLength(50),
                    ])
                    ->action(function (Student $record, array $data) {
                        $year = SchoolYear::active();
                        if (! $year) {
                            Notification::make()->danger()->title('No active school year.')->send();

                            return;
                        }

                        try {
                            app(RegistrationService::class)->register($record, $year, $data['grade_level'], $data['section'] ?? null);
                            Notification::make()->success()->title('Enrolled.')->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('ledger')
                    ->label('Ledger')
                    ->url(function (Student $record) {
                        $enrollment = self::currentYearEnrollment($record);

                        return $enrollment ? StudentResource::ledgerUrl($enrollment) : null;
                    })
                    ->visible(fn (Student $record) => self::currentYearEnrollment($record) !== null),
                EditAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }

    private static function currentYearEnrollment(Student $record): ?Enrollment
    {
        $year = SchoolYear::active();

        if (! $year) {
            return null;
        }

        return $record->enrollments()->where('school_year_id', $year->id)->first();
    }
}
