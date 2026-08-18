<?php

namespace ColorrageAR\Autoresponder\Jobs;

use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use function ColorrageAR\Autoresponder\ar_log;
use function ColorrageAR\Autoresponder\ar_queue;

/**
 * Hands a due scheduled campaign to CampaignService.
 *
 * Deliberately performs no claim of its own. Exactly-once comes from the atomic
 * claim inside CampaignService::sendCampaign(); a claim here as well would move
 * the campaign to `sending` first, causing the service's claim to match zero rows
 * and return early — leaving the campaign stuck in `sending` having sent nothing.
 */
class SendScheduledCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

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

        // Guard against a stale job sitting on the queue — not the exactly-once mechanism.
        if ($campaign->status !== 'scheduled') {
            ar_log()->info('SendScheduledCampaign: campaign no longer scheduled', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);

            return;
        }

        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            ar_log()->info('SendScheduledCampaign: campaign is not due yet', [
                'campaign_id' => $campaign->id,
                'scheduled_at' => $campaign->scheduled_at->toDateTimeString(),
            ]);

            return;
        }

        ar_log()->info('SendScheduledCampaign: handing campaign to CampaignService', [
            'campaign_id' => $campaign->id,
        ]);

        $campaignService->sendCampaign($campaign);
    }

    public function failed(\Throwable $exception): void
    {
        ar_log()->error('SendScheduledCampaign job failed', [
            'campaign_id' => $this->campaignId,
            'error' => $exception->getMessage(),
        ]);

        Campaign::find($this->campaignId)?->markAsFailed(
            'Scheduled dispatch failed: ' . $exception->getMessage()
        );
    }
}
