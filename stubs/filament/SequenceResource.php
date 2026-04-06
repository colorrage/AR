<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SequenceResource\Pages;
use CmrManagement\Autoresponder\Models\Sequence;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;

class SequenceResource extends Resource
{
    protected static ?string $model = Sequence::class;
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?string $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Autoresponders (Sequences)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Select::make('trigger_type')
                ->options(config('autoresponder.trigger_types', ['signup' => 'Signup']))
                ->required(),
            Select::make('default_locale')
                ->options(config('autoresponder.languages', ['en' => 'English', 'ro' => 'Romanian']))
                ->required()
                ->default('en'),
            TextInput::make('stop_sequence_on_event')
                ->maxLength(255)
                ->helperText('Trigger type to stop this sequence (e.g. payment_succeeded)'),
            Toggle::make('is_active')
                ->default(true),
            Toggle::make('test_mode')
                ->default(false)
                ->helperText('If true, sequence only sends to config(autoresponder.mail.test_email)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')
                ->searchable()
                ->sortable(),
            TextColumn::make('trigger_type')
                ->sortable(),
            IconColumn::make('is_active')
                ->boolean(),
            IconColumn::make('test_mode')
                ->boolean(),
            TextColumn::make('active_enrollments')
                ->sortable(),
            TextColumn::make('completed_enrollments')
                ->sortable(),
        ])
        ->filters([
            // Filters
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSequences::route('/'),
            'create' => Pages\CreateSequence::route('/create'),
            'edit' => Pages\EditSequence::route('/{record}/edit'),
        ];
    }
}
