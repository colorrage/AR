<?php

namespace CmrManagement\Autoresponder\Jobs;

use CmrManagement\Autoresponder\Models\Campaign;
use CmrManagement\Autoresponder\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use function CmrManagement\Autoresponder\ar_log;
use function CmrManagement\Autoresponder\ar_queue;

class SendScheduledCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $campaignId,
    ) {
        $this->onQueue(ar_queue());
    }

    public function handle(CampaignService $campaignService): void
    {
        $campaign = Campaign::find($this->campaignId);

        if (! $campaign) {
            ar_log()->warning('SendScheduledCampaign: campaign not found', [
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        if ($campaign->status !== 'scheduled') {
            ar_log()->info('SendScheduledCampaign: campaign is not in scheduled state', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);

            return;
        }

        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            ar_log()->info('SendScheduledCampaign: scheduled time not yet reached', [
                'campaign_id' => $campaign->id,
                'scheduled_at' => $campaign->scheduled_at->toDateTimeString(),
            ]);

            return;
        }

        ar_log()->info('SendScheduledCampaign: launching campaign batch', [
            'campaign_id' => $campaign->id,
            'scheduled_at' => $campaign->scheduled_at?->toDateTimeString(),
        ]);

        SendCampaignBatch::dispatch($campaign->id);
    }

    public function failed(\Throwable $exception): void
    {
        ar_log()->error('SendScheduledCampaign job failed', [
            'campaign_id' => $this->campaignId,
            'error' => $exception->getMessage(),
        ]);

        $campaign = Campaign::find($this->campaignId);
        $campaign?->update(['status' => 'failed']);
    }
}
