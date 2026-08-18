<?php

namespace App\Filament\Resources\MailerListResource\RelationManagers;

use ColorrageAR\Autoresponder\Services\ListImportService;
use ColorrageAR\Autoresponder\Support\ListImportReport;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

class SubscribersRelationManager extends RelationManager
{
    protected static string $relationship = 'listSubscribers';

    protected static ?string $recordTitleAttribute = 'email';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255),
            TextInput::make('name')
                ->maxLength(255),
            TextInput::make('locale')
                ->maxLength(10),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subscribed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                $this->importCsvAction(),
            ])
            ->actions([
                DeleteAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    /**
     * Import subscribers into this list from a CSV file.
     *
     * IMPORTANT — consent is YOUR responsibility, not the package's. This action records
     * only where a row came from (`import_source`, `imported_at`). It captures no consent,
     * no legal basis and no retention policy, deliberately: those are host concerns. Gather
     * and store consent before you let an operator reach this button, and add whatever
     * confirmation your jurisdiction requires to the modal below.
     *
     * The importer itself never overrides an explicit opt-out: an address in the global
     * unsubscribe table, or already on this list as `unsubscribed`, is skipped and counted
     * as suppressed rather than re-added.
     */
    protected function importCsvAction(): Action
    {
        return Action::make('importCsv')
            ->label('Import CSV')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading('Import subscribers from CSV')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('CSV file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                    ->storeFiles(false)
                    ->required()
                    ->helperText('First row must be a header row.'),
                TextInput::make('email_column')
                    ->label('Email column heading')
                    ->default(config('autoresponder.import.columns.email', 'email'))
                    ->required(),
                TextInput::make('name_column')
                    ->label('Name column heading')
                    ->default(config('autoresponder.import.columns.name', 'name')),
                TextInput::make('locale_column')
                    ->label('Locale column heading')
                    ->default(config('autoresponder.import.columns.locale', 'locale')),
                TextInput::make('delimiter')
                    ->label('Delimiter')
                    ->default(config('autoresponder.import.delimiter', ','))
                    ->maxLength(1)
                    ->helperText('Use ; for most European spreadsheet exports.'),
            ])
            ->action(function (array $data): void {
                $upload = $data['file'];

                try {
                    $report = app(ListImportService::class)->importCsv(
                        $upload->getRealPath(),
                        $this->getOwnerRecord(),
                        [
                            'email' => $data['email_column'] ?: null,
                            'name' => $data['name_column'] ?: null,
                            'locale' => $data['locale_column'] ?: null,
                        ],
                        $data['delimiter'] ?: ',',
                        $upload->getClientOriginalName(),
                    );
                } catch (\Throwable $e) {
                    // Import-level failure: unreadable file, no header row, missing email
                    // column. Nothing was written.
                    Notification::make()
                        ->title('Import could not run')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Import complete')
                    ->body($this->summarise($report))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Every row read, accounted for. Rejected rows are listed with the file line number so
     * the operator can open the CSV and fix them.
     */
    protected function summarise(ListImportReport $report): string
    {
        $lines = [
            sprintf(
                '%d rows read — %d added, %d already known, %d suppressed, %d invalid.',
                $report->rowsRead(),
                $report->accepted(),
                $report->duplicate(),
                $report->suppressed(),
                $report->invalid(),
            ),
        ];

        if (! $report->reconciles()) {
            $lines[] = 'WARNING: those counts do not sum to the rows read. Rows were lost.';
        }

        foreach ($report->rejected() as $row) {
            $lines[] = sprintf('Line %d: %s', $row->line, $row->message);
        }

        if ($report->rejectedTruncated()) {
            // A short list must never read as a complete one.
            $lines[] = sprintf(
                'Only the first %d of %d rejected rows are shown; raise '
                . 'autoresponder.import.max_rejected_rows to see more.',
                count($report->rejected()),
                $report->rejectedCount(),
            );
        }

        return implode("\n", $lines);
    }
}
