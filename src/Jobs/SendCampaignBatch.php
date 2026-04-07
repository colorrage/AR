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

class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $campaignId,
    ) {
        $this->onQueue(ar_queue());
    }

    public function handle(CampaignService $campaignService): void
    {
        $campaign = Campaign::find($this->campaignId);

        if (! $campaign) {
            ar_log()->warning('SendCampaignBatch: campaign not found', [
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        if (! in_array($campaign->status, ['sending', 'scheduled'])) {
            ar_log()->info('SendCampaignBatch: campaign not in sendable state', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);

            return;
        }

        $campaign->update(['status' => 'sending']);

        ar_log()->info('SendCampaignBatch: starting broadcast', [
            'campaign_id' => $campaign->id,
        ]);

        $recipients = $campaignService->resolveRecipients($campaign->filter_params ?? []);

        if ($recipients->isEmpty()) {
            $campaign->update(['status' => 'failed']);

            ar_log()->warning('SendCampaignBatch: no recipients found, marking as failed', [
                'campaign_id' => $campaign->id,
            ]);

            return;
        }
        $batchDelay = config('autoresponder.rate_limit.batch_delay_seconds', 30);
        $dispatched = 0;

        foreach ($recipients as $recipient) {
            $email = $recipient['email'] ?? null;
            $subscriberId = $recipient['subscriber_id'] ?? null;

            if (! $email) {
                continue;
            }

            SendSingleCampaignEmail::dispatch($campaign->id, $email, $subscriberId)
                ->delay(now()->addSeconds($dispatched * $batchDelay));

            $dispatched++;
        }

        $campaign->update([
            'total_recipients' => $dispatched,
        ]);

        ar_log()->info('SendCampaignBatch: dispatched all individual emails', [
            'campaign_id' => $campaign->id,
            'total_dispatched' => $dispatched,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        ar_log()->error('SendCampaignBatch job failed', [
            'campaign_id' => $this->campaignId,
            'error' => $exception->getMessage(),
        ]);

        $campaign = Campaign::find($this->campaignId);
        $campaign?->update(['status' => 'failed']);
    }
}
