<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SequenceResource\Pages;
use App\Filament\Resources\SequenceResource\RelationManagers\StepsRelationManager;
use ColorrageAR\Autoresponder\Models\Sequence;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;

class SequenceResource extends Resource
{
    protected static ?string $model = Sequence::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-path';
    protected static string | \UnitEnum | null $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Autoresponders (Sequences)';
    protected static ?int $navigationSort = 33;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sequence Details')
                ->description('Configure the main trigger and settings for this autoresponder sequence')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Internal name for this sequence'),

                    Select::make('trigger_type')
                        ->options([
                            'signup' => 'User Signup',
                            'cmr_created' => 'CMR Created',
                            'cmr_printed' => 'CMR Printed',
                            'payment_succeeded' => 'Payment Succeeded',
                            'subscription_expired' => 'Subscription Expired',
                            'manual' => 'Manual Enrollment',
                        ])
                        ->required()
                        ->helperText('What event starts this sequence?'),

                    Select::make('default_locale')
                        ->options([
                            'en' => 'English',
                            'ro' => 'Romanian',
                            'hu' => 'Hungarian',
                            'de' => 'German',
                        ])
                        ->required()
                        ->default('en')
                        ->helperText('Fallback language if user language is unknown'),

                    TextInput::make('stop_sequence_on_event')
                        ->maxLength(255)
                        ->helperText('Trigger type that cancels this sequence (e.g. "payment_succeeded" if this is a reminder sequence)'),

                    Toggle::make('is_active')
                        ->label('Enabled')
                        ->default(true)
                        ->inline(false),

                    Toggle::make('test_mode')
                        ->label('Test Mode')
                        ->default(false)
                        ->helperText('If enabled, emails only go to the developer email address.')
                        ->inline(false),
                ])
                ->columns(2),

            Section::make('Statistics')
                ->schema([
                    Placeholder::make('stats_info')
                        ->label('')
                        ->content('Enrollment statistics will be available once the sequence is active.')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('trigger_type')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('active_enrollments_count')
                    ->label('Active')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('total_enrollments_count')
                    ->label('Total')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            StepsRelationManager::class,
        ];
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

