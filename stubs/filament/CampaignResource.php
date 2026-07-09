<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Filament\Resources\CampaignResource\RelationManagers\FailedLogsRelationManager;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\Template;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Actions\Action as FormAction;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-paper-airplane';
    protected static string | \UnitEnum | null $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Email Campaigns';
    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaign Details')
                ->description('Basic information about this email campaign')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->label('Campaign Name')
                        ->helperText('Internal name (e.g., "September Promo")'),

                    Select::make('template_id')
                        ->relationship('template', 'name')
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            if ($state) {
                                $template = Template::find($state);
                                if ($template) {
                                    $set('subject', $template->subject);
                                }
                            }
                        })
                        ->label('Email Template'),

                    TextInput::make('subject')
                        ->required()
                        ->maxLength(500)
                        ->label('Email Subject')
                        ->helperText('Pulled from template, but you can override it here')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Recipient Selection')
                ->description('Choose who will receive this email campaign')
                ->schema([
                    Radio::make('filter_type')
                        ->options([
                            'admin_only' => '📧 Send to me only (Admin - for testing)',
                            'manual_emails' => '✉️ Manual Email List (comma-separated)',
                            'mailer_lists' => '📋 Mailer Lists (reusable lists)',
                            'all_paid' => 'All Paid Customers',
                            'all_unpaid' => 'All Unpaid/Free Customers',
                            'custom_sql' => 'Custom SQL Query',
                        ])
                        ->required()
                        ->live()
                        ->label('Filter Type')
                        ->columnSpanFull(),

                    Textarea::make('filter_params.manual_emails')
                        ->label('Email Addresses')
                        ->placeholder('contact@example.com, test@example.com')
                        ->rows(4)
                        ->visible(fn (Get $get) => $get('filter_type') === 'manual_emails')
                        ->required(fn (Get $get) => $get('filter_type') === 'manual_emails')
                        ->columnSpanFull(),

                    CheckboxList::make('filter_params.selected_lists')
                        ->label('Select Mailer Lists')
                        ->relationship('mailerLists', 'name')
                        ->visible(fn (Get $get) => $get('filter_type') === 'mailer_lists')
                        ->required(fn (Get $get) => $get('filter_type') === 'mailer_lists')
                        ->columns(2)
                        ->columnSpanFull(),

                    Textarea::make('custom_sql')
                        ->rows(6)
                        ->label('Custom SQL Query')
                        ->helperText('Must return: id, email, name. Must include LIMIT clause.')
                        ->visible(fn (Get $get) => $get('filter_type') === 'custom_sql')
                        ->required(fn (Get $get) => $get('filter_type') === 'custom_sql')
                        ->columnSpanFull(),

                    Toggle::make('filter_params.ignore_unsubscribed')
                        ->label('Ignore Unsubscribed Emails')
                        ->default(true)
                        ->columnSpanFull(),

                    Placeholder::make('preview_recipients')
                        ->label('Recipient Preview')
                        ->content('Click the action below to check how many recipients match your selection.')
                        ->columnSpanFull(),
                ]),

            Section::make('UTM Tracking')
                ->description('Add UTM parameters for Google Analytics')
                ->schema([
                    Toggle::make('enable_utm_tracking')
                        ->label('Enable UTM Tracking')
                        ->default(true)
                        ->live()
                        ->columnSpanFull(),

                    TextInput::make('utm_source')
                        ->default('newsletter')
                        ->visible(fn (Get $get) => $get('enable_utm_tracking')),

                    TextInput::make('utm_medium')
                        ->default('email')
                        ->visible(fn (Get $get) => $get('enable_utm_tracking')),

                    TextInput::make('utm_campaign')
                        ->placeholder('summer_sale')
                        ->visible(fn (Get $get) => $get('enable_utm_tracking')),
                ])
                ->columns(3)
                ->collapsible()
                ->collapsed(),

            Section::make('Scheduling')
                ->schema([
                    DateTimePicker::make('scheduled_at')
                        ->label('Schedule For')
                        ->helperText('Leave empty to send immediately'),
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
                    ->sortable()
                    ->label('Campaign Name'),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'warning' => 'queued',
                        'info' => 'sending',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ])
                    ->label('Status'),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->getStateUsing(function ($record) {
                        if ($record->status === 'completed') return '100%';
                        if ($record->total_recipients > 0) {
                            return round(($record->sent_count / $record->total_recipients) * 100, 1) . '%';
                        }
                        return '0%';
                    })
                    ->badge()
                    ->color(fn ($state) => $state === '100%' ? 'success' : 'info'),

                TextColumn::make('sent_count')
                    ->label('Sent / Total')
                    ->formatStateUsing(fn ($record) => "{$record->sent_count} / {$record->total_recipients}")
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->label('Created'),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->action(function ($record) {
                        $duplicate = $record->replicate();
                        $duplicate->name = $record->name . ' (Copy)';
                        $duplicate->status = 'draft';
                        $duplicate->sent_count = 0;
                        $duplicate->save();
                        Notification::make()->title('Campaign duplicated')->success()->send();
                    }),
                Action::make('send')
                    ->label('Send Now')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'draft')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update(['status' => 'queued', 'started_at' => now()]);
                        Notification::make()->title('Campaign queued for sending')->success()->send();
                    }),
                EditAction::make()
                    ->visible(fn ($record) => $record->status === 'draft'),
                DeleteAction::make()
                    ->visible(fn ($record) => $record->status === 'draft'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            FailedLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'view' => Pages\ViewCampaign::route('/{record}'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}

