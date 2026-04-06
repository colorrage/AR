<?php

namespace App\Filament\Resources\UnsubscribeResource\Pages;

use App\Filament\Resources\UnsubscribeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUnsubscribes extends ListRecords
{
    protected static string $resource = UnsubscribeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
