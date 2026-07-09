<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnsubscribeResource\Pages;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnsubscribeResource extends Resource
{
    protected static ?string $model = Unsubscribe::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-no-symbol';
    protected static string | \UnitEnum | null $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Unsubscribes';

    public static function form(Schema $schema): Schema
    {
        return  $schema->components([
            TextInput::make('email')
                ->required()
                ->email()
                ->maxLength(255),
            Textarea::make('reason')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email')
                ->searchable()
                ->sortable(),
            TextColumn::make('reason')
                ->limit(50),
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
            'index' => Pages\ListUnsubscribes::route('/'),
            'create' => Pages\CreateUnsubscribe::route('/create'),
            'edit' => Pages\EditUnsubscribe::route('/{record}/edit'),
        ];
    }
}
