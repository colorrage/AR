<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LogResource\Pages;
use CmrManagement\Autoresponder\Models\SendLog;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogResource extends Resource
{
    protected static ?string $model = SendLog::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Send Logs';
    protected static ?string $pluralModelLabel = 'Send Logs';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('email')
                ->required()
                ->maxLength(255),
            TextInput::make('status')
                ->required(),
            TextInput::make('error_message')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email')
                ->searchable()
                ->sortable(),
            TextColumn::make('status')
                ->badge()
                ->colors([
                    'success' => 'sent',
                    'danger' => 'failed',
                ]),
            TextColumn::make('opened_at')
                ->dateTime()
                ->sortable(),
            TextColumn::make('clicked_at')
                ->dateTime()
                ->sortable(),
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
            'index' => Pages\ListLogs::route('/'),
            // Using only list/view since logs are usually read-only
            'view' => Pages\ViewLog::route('/{record}'),
        ];
    }
}
