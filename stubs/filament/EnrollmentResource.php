<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnrollmentResource\Pages;
use ColorrageAR\Autoresponder\Models\Enrollment;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Notifications\Notification;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';
    protected static string | \UnitEnum | null $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Enrollments';
    protected static ?int $navigationSort = 34;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('sequence_id')
                ->relationship('sequence', 'name')
                ->required()
                ->disabled(),
            TextInput::make('email')
                ->required()
                ->email()
                ->disabled(),
            Select::make('state')
                ->options([
                    'active' => 'Active',
                    'paused' => 'Paused',
                    'completed' => 'Completed',
                    'exited' => 'Exited',
                    'failed' => 'Failed',
                ])
                ->required()
                ->disabled(),
            DateTimePicker::make('next_run_at')
                ->label('Next Email At')
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('sequence.name')
                    ->label('Sequence')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('state')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'paused' => 'warning',
                        'completed' => 'info',
                        'exited' => 'gray',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('current_step_number')
                    ->label('Step')
                    ->alignCenter(),

                TextColumn::make('next_run_at')
                    ->label('Next Email')
                    ->dateTime('M d, H:i')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('enrolled_at')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('pause')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn ($record) => $record->state === 'active')
                    ->action(function ($record) {
                        $record->update(['state' => 'paused']);
                        Notification::make()->title('Enrollment Paused')->success()->send();
                    }),
                Action::make('resume')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn ($record) => $record->state === 'paused')
                    ->action(function ($record) {
                        $record->update(['state' => 'active']);
                        Notification::make()->title('Enrollment Resumed')->success()->send();
                    }),
            ])
            ->defaultSort('enrolled_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                InfoSection::make('Enrollment Details')
                    ->schema([
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('sequence.name')->label('Sequence'),
                        TextEntry::make('state')
                            ->badge()
                            ->color(fn ($state) => match($state) {
                                'active' => 'success',
                                'paused' => 'warning',
                                'completed' => 'info',
                                'exited' => 'gray',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('enrolled_at')->dateTime(),
                    ])
                    ->columns(4),

                InfoSection::make('Progress')
                    ->schema([
                        TextEntry::make('current_step_number')->label('Current Step'),
                        TextEntry::make('next_run_at')->label('Next Run')->dateTime()->placeholder('—'),
                        TextEntry::make('completed_at')->label('Completed At')->dateTime()->placeholder('—'),
                    ])
                    ->columns(3),

                InfoSection::make('Email Timeline')
                    ->schema([
                        RepeatableEntry::make('stepLogs')
                            ->schema([
                                TextEntry::make('step.step_number')->label('Step'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn ($state) => match($state) {
                                        'sent' => 'success',
                                        'scheduled' => 'info',
                                        'pending' => 'warning',
                                        'failed' => 'danger',
                                        'skipped' => 'gray',
                                        default => 'gray',
                                    }),
                                TextEntry::make('sent_at')->dateTime()->placeholder('—'),
                                IconEntry::make('was_opened')->label('Opened')->boolean(),
                                IconEntry::make('was_clicked')->label('Clicked')->boolean(),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnrollments::route('/'),
            'view' => Pages\ViewEnrollment::route('/{record}'),
        ];
    }
}

