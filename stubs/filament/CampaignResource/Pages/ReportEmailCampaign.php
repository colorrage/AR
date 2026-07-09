<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;

class ReportEmailCampaign extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CampaignResource::class;

    protected string $view = 'vendor.autoresponder.campaign-report';

    public function mount($record): void
    {
        $this->record = $this->resolveRecord($record);
    }
}
