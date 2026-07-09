<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LogResource\Pages;
use ColorrageAR\Autoresponder\Models\SendLog;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogResource extends Resource
{
    protected static ?string $model = SendLog::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static string | \UnitEnum | null $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Send Logs';
    protected static ?string $pluralModelLabel = 'Send Logs';

    public static function form(Schema $schema): Schema
    {
        return  $schema->components([
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
                ->color(fn (string $state): string => match ($state) {
                    'sent' => 'success',
                    'failed' => 'danger',
                    default => 'gray',
                }),
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
