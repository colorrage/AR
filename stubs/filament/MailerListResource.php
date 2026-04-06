<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MailerListResource\Pages;
use CmrManagement\Autoresponder\Models\MailerList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;

class MailerListResource extends Resource
{
    protected static ?string $model = MailerList::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Mailer Lists';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('type')
                ->required()
                ->default('manual')
                ->maxLength(255),
            Textarea::make('description')
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')
                ->searchable()
                ->sortable(),
            TextColumn::make('type')
                ->sortable(),
            TextColumn::make('total_subscribers')
                ->sortable(),
            IconColumn::make('is_active')
                ->boolean(),
            TextColumn::make('created_at')
                ->dateTime()
                ->sortable(),
        ])
        ->filters([
            // filters
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMailerLists::route('/'),
            'create' => Pages\CreateMailerList::route('/create'),
            'edit' => Pages\EditMailerList::route('/{record}/edit'),
        ];
    }
}
