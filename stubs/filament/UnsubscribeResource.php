<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnsubscribeResource\Pages;
use CmrManagement\Autoresponder\Models\Unsubscribe;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnsubscribeResource extends Resource
{
    protected static ?string $model = Unsubscribe::class;
    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';
    protected static ?string $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Unsubscribes';

    public static function form(Form $form): Form
    {
        return $form->schema([
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
