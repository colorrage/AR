<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnrollmentResource\Pages;
use CmrManagement\Autoresponder\Models\Enrollment;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Table;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';
    protected static ?string $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Enrollments';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('sequence_id')
                ->relationship('sequence', 'name')
                ->required(),
            TextInput::make('subscriber_id')
                ->required()
                ->helperText('ID of the subscriber in your system'),
            Select::make('state')
                ->options([
                    'active' => 'Active',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ])
                ->required(),
            DateTimePicker::make('next_run_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sequence.name')
                ->sortable()
                ->searchable(),
            TextColumn::make('subscriber_id')
                ->searchable(),
            BadgeColumn::make('state')
                ->colors([
                    'success' => 'active',
                    'secondary' => 'completed',
                    'danger' => 'cancelled',
                ]),
            TextColumn::make('next_run_at')
                ->dateTime(),
            TextColumn::make('created_at')
                ->dateTime()
                ->sortable(),
        ])
        ->filters([
            // Filters
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnrollments::route('/'),
            'create' => Pages\CreateEnrollment::route('/create'),
            'edit' => Pages\EditEnrollment::route('/{record}/edit'),
        ];
    }
}
