<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TemplateResource\Pages;
use CmrManagement\Autoresponder\Models\Template;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TemplateResource extends Resource
{
    protected static ?string $model = Template::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Email Templates';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Select::make('locale')
                ->options(config('autoresponder.languages', ['en' => 'English', 'ro' => 'Romanian']))
                ->required()
                ->default('en'),
            TextInput::make('subject')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            RichEditor::make('body')
                ->required()
                ->columnSpanFull(),
            Textarea::make('body_text')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')
                ->searchable()
                ->sortable(),
            TextColumn::make('locale')
                ->sortable(),
            TextColumn::make('subject')
                ->searchable(),
            TextColumn::make('created_at')
                ->dateTime()
                ->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTemplates::route('/'),
            'create' => Pages\CreateTemplate::route('/create'),
            'edit' => Pages\EditTemplate::route('/{record}/edit'),
        ];
    }
}
