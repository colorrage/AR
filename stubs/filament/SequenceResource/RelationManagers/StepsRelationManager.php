<?php

namespace App\Filament\Resources\SequenceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Schemas\Schema;

class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    protected static ?string $recordTitleAttribute = 'template.name';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('step_number')
                ->required()
                ->numeric()
                ->default(1)
                ->helperText('Order of this step in the sequence'),

            Select::make('template_id')
                ->relationship('template', 'name')
                ->required()
                ->searchable()
                ->helperText('The email template to send'),

            TextInput::make('delay_days')
                ->required()
                ->numeric()
                ->default(0)
                ->helperText('Days to wait after the previous step (or after trigger for step 1)'),

            TextInput::make('delay_hours')
                ->required()
                ->numeric()
                ->default(0)
                ->helperText('Additional hours to wait'),

            TextInput::make('delay_minutes')
                ->required()
                ->numeric()
                ->default(0)
                ->helperText('Additional minutes to wait'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('template.name')
            ->columns([
                TextColumn::make('step_number')
                    ->label('#')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('template.name')
                    ->label('Template')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('delay')
                    ->label('Delay')
                    ->getStateUsing(fn ($record) => "{$record->delay_days}d {$record->delay_hours}h {$record->delay_minutes}m"),

                TextColumn::make('sent_count')
                    ->label('Sent')
                    ->sortable()
                    ->alignCenter(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('step_number', 'asc');
    }
}
