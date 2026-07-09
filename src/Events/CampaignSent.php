<?php

namespace ColorrageAR\Autoresponder\Events;

use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Step;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CampaignSent
{
    use Dispatchable, SerializesModels;

    public Campaign $campaign;
    public int $sentCount;
    public int $failedCount;

    public function __construct(Campaign $campaign, int $sentCount, int $failedCount)
    {
        $this->campaign = $campaign;
        $this->sentCount = $sentCount;
        $this->failedCount = $failedCount;
    }
}
