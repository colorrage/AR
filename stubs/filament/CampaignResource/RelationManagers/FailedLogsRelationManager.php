<?php

namespace App\Filament\Resources\CampaignResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Support\Colors\Color;

class FailedLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'sendLogs';

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?string $title = 'Failed Deliveries';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->color('danger'),
                TextColumn::make('updated_at')
                    ->label('Failed At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->action(function ($record) {
                        // Logic to retry sending would go here
                        $record->update(['status' => 'pending', 'error_message' => null]);
                    })
                    ->requiresConfirmation(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->where('status', 'failed'));
    }
}
