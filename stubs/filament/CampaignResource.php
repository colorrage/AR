<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use CmrManagement\Autoresponder\Models\Campaign;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Table;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;
    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Email Campaigns';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Campaign Details')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Select::make('template_id')
                        ->relationship('template', 'name')
                        ->required(),
                    TextInput::make('subject')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])->columns(2),

            Section::make('Recipient Selection')
                ->schema([
                    Select::make('filter_type')
                        ->options([
                            'all' => 'All Subscribers',
                            'mailer_lists' => 'Mailer Lists',
                            'manual' => 'Manual (Comma separated)',
                        ])
                        ->required(),
                    // Simplified: We assume a text/json input for parameters for ease of scaffolding. 
                    // Host apps will likely customize this.
                ]),

            Section::make('Scheduling')
                ->schema([
                    DateTimePicker::make('scheduled_at'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')
                ->searchable()
                ->sortable(),
            BadgeColumn::make('status')
                ->colors([
                    'secondary' => 'draft',
                    'warning' => 'queued',
                    'success' => 'completed',
                    'danger' => 'failed',
                ]),
            TextColumn::make('sent_count')
                ->label('Sent')
                ->sortable(),
            TextColumn::make('total_recipients')
                ->label('Total'),
            TextColumn::make('created_at')
                ->dateTime()
                ->sortable(),
        ])
        ->filters([
            // Add custom filters here
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}
