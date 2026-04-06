<?php

namespace App\Filament\Resources\MailerListResource\Pages;

use App\Filament\Resources\MailerListResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMailerLists extends ListRecords
{
    protected static string $resource = MailerListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
