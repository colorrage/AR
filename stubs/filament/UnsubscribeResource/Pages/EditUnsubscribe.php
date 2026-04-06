<?php

namespace App\Filament\Resources\UnsubscribeResource\Pages;

use App\Filament\Resources\UnsubscribeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUnsubscribe extends EditRecord
{
    protected static string $resource = UnsubscribeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
