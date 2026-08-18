<?php

namespace ColorrageAR\Autoresponder\Jobs;

use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\SendLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use function ColorrageAR\Autoresponder\ar_log;
use function ColorrageAR\Autoresponder\ar_queue;

/**
 * Fans out one send job per pre-created send log row, paced by batch.
 *
 * Recipient resolution and row creation belong to CampaignService; by the time
 * this job runs every row already exists. This job only decides *when* each one
 * is dispatched.
 */
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

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);

        if (! $campaign) {
            ar_log()->warning('SendCampaignBatch: campaign not found', [
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        // Only a claimed campaign may fan out. Accepting `scheduled` here would let
        // a campaign be dispatched without passing through the atomic claim in
        // CampaignService::sendCampaign(), which is what guarantees exactly-once.
        if ($campaign->status !== 'sending') {
            ar_log()->info('SendCampaignBatch: campaign not in sendable state', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);

            return;
        }

        $batchSize = max(1, (int) config('autoresponder.rate_limit.emails_per_batch', 2));
        $batchDelay = (int) config('autoresponder.rate_limit.batch_delay_seconds', 30);

        $pending = SendLog::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->pluck('id');

        if ($pending->isEmpty()) {
            ar_log()->warning('SendCampaignBatch: no pending send logs for campaign', [
                'campaign_id' => $campaign->id,
            ]);

            return;
        }

        ar_log()->info('SendCampaignBatch: starting broadcast', [
            'campaign_id' => $campaign->id,
            'pending' => $pending->count(),
            'batch_size' => $batchSize,
        ]);

        $dispatched = 0;

        // Delay is per batch index, not per recipient: everyone in a batch goes out
        // together, and each subsequent batch waits one more interval.
        foreach ($pending->chunk($batchSize)->values() as $batchIndex => $batch) {
            foreach ($batch as $sendLogId) {
                SendSingleCampaignEmail::dispatch($sendLogId)
                    ->delay(now()->addSeconds($batchIndex * $batchDelay));

                $dispatched++;
            }
        }

        ar_log()->info('SendCampaignBatch: dispatched all individual emails', [
            'campaign_id' => $campaign->id,
            'total_dispatched' => $dispatched,
            'batches' => (int) ceil($pending->count() / $batchSize),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        ar_log()->error('SendCampaignBatch job failed', [
            'campaign_id' => $this->campaignId,
            'error' => $exception->getMessage(),
        ]);

        Campaign::find($this->campaignId)?->markAsFailed(
            'Campaign fan-out failed: ' . $exception->getMessage()
        );
    }
}
