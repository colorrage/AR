<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Filament\Resources\CampaignResource\RelationManagers\FailedLogsRelationManager;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Services\CampaignService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
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
                    // Only the filter types CampaignService::resolveRecipients() implements.
                    // Offering more produces campaigns that resolve nobody.
                    Radio::make('filter_type')
                        ->options([
                            'manual_emails' => '✉️ Manual Email List (comma-separated)',
                            'mailer_lists' => '📋 Mailer Lists (reusable lists)',
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

                    Toggle::make('filter_params.ignore_unsubscribed')
                        ->label('Ignore Unsubscribed Emails')
                        ->default(true)
                        ->columnSpanFull(),

                    // Resolves the audience through the same service the send uses, so the
                    // number shown is the number that will actually be mailed.
                    FormAction::make('preview_recipients')
                        ->label('Preview recipient count')
                        ->icon('heroicon-o-users')
                        ->color('gray')
                        ->action(function (Get $get) {
                            $count = app(CampaignService::class)
                                ->resolveRecipients($get('filter_type'), [
                                    'manual_emails' => $get('filter_params.manual_emails') ?? '',
                                    'selected_lists' => $get('filter_params.selected_lists') ?? [],
                                    'ignore_unsubscribed' => (bool) ($get('filter_params.ignore_unsubscribed') ?? true),
                                ])
                                ->count();

                            Notification::make()
                                ->title($count === 1 ? '1 recipient matches' : "{$count} recipients match")
                                ->info()
                                ->send();
                        }),
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

                // The package's full status set. There is no `queued` and no `completed`
                // — `sent` is the terminal success state.
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'info' => 'scheduled',
                        'warning' => 'sending',
                        'success' => 'sent',
                        'danger' => 'failed',
                        'secondary' => 'cancelled',
                    ])
                    ->label('Status'),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->getStateUsing(function ($record) {
                        if ($record->status === 'sent') return '100%';
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
                // Routed through CampaignService, which owns the template guard, the
                // atomic claim and send-log pre-creation. Writing a status here instead
                // is what made this button do nothing for the package's entire history.
                Action::make('send')
                    ->label('Send Now')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    // `failed` is included deliberately: a campaign that failed for a
                    // fixable reason — no template, empty list — stays retryable, and
                    // this button is how an operator retries it.
                    ->visible(fn ($record) => in_array($record->status, ['draft', 'failed'], true))
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        try {
                            $count = app(CampaignService::class)->sendCampaign($record);

                            if ($count === 0) {
                                Notification::make()
                                    ->title('Already being sent')
                                    ->body('Another process is already sending this campaign.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Campaign sending')
                                ->body("Queued for {$count} recipient(s).")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            // sendCampaign() throws for a missing template, an empty
                            // audience, or an over-ceiling count. The operator needs the
                            // reason — reporting success regardless is the old behavior.
                            Notification::make()
                                ->title('Campaign could not be sent')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // Scheduling was collected on the form but never applied: nothing moved
                // the campaign to `scheduled`, so process-scheduled-campaigns — which
                // selects on that status — never saw it.
                Action::make('schedule')
                    ->label('Schedule')
                    ->icon('heroicon-o-clock')
                    ->color('info')
                    ->visible(fn ($record) => in_array($record->status, ['draft', 'failed'], true)
                        && $record->scheduled_at
                        && $record->scheduled_at->isFuture())
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        try {
                            app(CampaignService::class)->scheduleCampaign($record, $record->scheduled_at);

                            Notification::make()
                                ->title('Campaign scheduled')
                                ->body('Sends at ' . $record->scheduled_at->toDayDateTimeString() . '.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Campaign could not be scheduled')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'scheduled')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        app(CampaignService::class)->cancelCampaign($record);
                        Notification::make()->title('Campaign cancelled')->success()->send();
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
