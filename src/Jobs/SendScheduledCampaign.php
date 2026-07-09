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
        $affected = Campaign::where('id', $this->campaignId)
            ->where('status', 'scheduled')
            ->where(function ($q) {
                $q->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->update(['status' => 'sending']);

        if ($affected === 0) {
            $campaign = Campaign::find($this->campaignId);

            if (! $campaign) {
                ar_log()->warning('SendScheduledCampaign: campaign not found', [
                    'campaign_id' => $this->campaignId,
                ]);

                return;
            }

            ar_log()->info('SendScheduledCampaign: campaign already dispatched or not ready', [
                'campaign_id' => $this->campaignId,
                'status' => $campaign->status,
            ]);

            return;
        }

        ar_log()->info('SendScheduledCampaign: launching campaign batch', [
            'campaign_id' => $this->campaignId,
        ]);

        SendCampaignBatch::dispatch($this->campaignId);
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
