<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MailerListResource\Pages;
use App\Filament\Resources\MailerListResource\RelationManagers\SubscribersRelationManager;
use ColorrageAR\Autoresponder\Models\MailerList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;

class MailerListResource extends Resource
{
    protected static ?string $model = MailerList::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-queue-list';
    protected static string | \UnitEnum | null $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Mailer Lists';
    protected static ?int $navigationSort = 32;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('List Details')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    
                    Select::make('type')
                        ->required()
                        ->options([
                            'clients' => 'Emails (dynamic)',
                            'manual' => 'Manual (static emails)',
                            'segments' => 'Segments (advanced filters)',
                        ])
                        ->helperText('Clients: Select specific emails. Manual: Enter emails manually. Segments: Advanced filters.')
                        ->live(),

                    Textarea::make('description')
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->default(true),
                ])
                ->columns(2),

            Section::make('Manual Email Addresses')
                ->description('Enter email addresses to add to this list (comma-separated)')
                ->visible(fn (Get $get) => $get('type') === 'manual')
                ->schema([
                    Textarea::make('manual_emails_input')
                        ->label('Email Addresses')
                        ->helperText('Enter email addresses separated by commas (e.g., user1@example.com, user2@example.com)')
                        ->placeholder('email1@example.com, email2@example.com')
                        ->rows(6)
                        ->columnSpanFull(),
                ]),

            Section::make('Add Emails')
                ->description('Search and select emails to add to this list')
                ->visible(fn (Get $get) => $get('type') === 'clients')
                ->schema([
                    Select::make('client_ids')
                        ->label('Select Emails to Add')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Type to search by email or name. Select multiple clients to add them to the list.')
                        ->columnSpanFull(),
                ]),

            Section::make('Advanced Filters')
                ->description('Define dynamic filters for segments')
                ->visible(fn (Get $get) => $get('type') === 'segments')
                ->schema([
                    Placeholder::make('segments_info')
                        ->label('')
                        ->content('Segments feature is coming soon - use "Clients" type for now.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'clients' => 'primary',
                        'manual' => 'warning',
                        'segments' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('subscriber_count')
                    ->label('Subscribers')
                    ->sortable()
                    ->alignCenter(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            SubscribersRelationManager::class,
        ];
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

