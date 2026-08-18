<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use ColorrageAR\Autoresponder\Jobs\SendCampaignBatch;
use ColorrageAR\Autoresponder\Jobs\SendSingleCampaignEmail;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Services\CampaignService;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * SendCampaignBatch — which decides *when* each pre-created row is dispatched,
 * and nothing else.
 */
class CampaignFanOutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * Prepare a campaign and return it already claimed, with rows created.
     */
    private function preparedCampaign(int $recipients): Campaign
    {
        $campaign = $this->makeListCampaign($this->makeStaticList($recipients));
        app(CampaignService::class)->sendCampaign($campaign);

        return $campaign->fresh();
    }

    /**
     * Delays of every dispatched send job, in seconds, ascending.
     *
     * Carbon's diffInSeconds is signed — a future delay reads negative — so this
     * normalises with abs() and rounds away sub-second drift.
     *
     * @return list<int>
     */
    private function dispatchedDelays(): array
    {
        $delays = [];

        Queue::assertPushed(SendSingleCampaignEmail::class, function ($job) use (&$delays) {
            $delays[] = $job->delay
                ? (int) round(abs((int) now()->diffInSeconds($job->delay)) / 10) * 10
                : 0;

            return true;
        });

        sort($delays);

        return $delays;
    }

    public function test_delay_is_applied_per_batch_and_shared_within_a_batch(): void
    {
        config([
            'autoresponder.rate_limit.emails_per_batch' => 2,
            'autoresponder.rate_limit.batch_delay_seconds' => 30,
        ]);
        $campaign = $this->preparedCampaign(6);

        (new SendCampaignBatch($campaign->id))->handle();

        // Three batches of two: 0s, 30s, 60s. Before T2 this accumulated per
        // recipient, so six recipients meant 0/30/60/90/120/150.
        $this->assertSame([0, 0, 30, 30, 60, 60], $this->dispatchedDelays());
    }

    public function test_batch_size_of_one_gives_every_recipient_its_own_interval(): void
    {
        config([
            'autoresponder.rate_limit.emails_per_batch' => 1,
            'autoresponder.rate_limit.batch_delay_seconds' => 30,
        ]);
        $campaign = $this->preparedCampaign(3);

        (new SendCampaignBatch($campaign->id))->handle();

        $this->assertSame([0, 30, 60], $this->dispatchedDelays());
    }

    public function test_a_batch_size_larger_than_the_audience_dispatches_one_batch(): void
    {
        config([
            'autoresponder.rate_limit.emails_per_batch' => 50,
            'autoresponder.rate_limit.batch_delay_seconds' => 30,
        ]);
        $campaign = $this->preparedCampaign(3);

        (new SendCampaignBatch($campaign->id))->handle();

        $this->assertSame([0, 0, 0], $this->dispatchedDelays());
    }

    public function test_a_misconfigured_batch_size_of_zero_still_dispatches(): void
    {
        config(['autoresponder.rate_limit.emails_per_batch' => 0]);
        $campaign = $this->preparedCampaign(3);

        (new SendCampaignBatch($campaign->id))->handle();

        Queue::assertPushed(SendSingleCampaignEmail::class, 3);
    }

    public function test_every_dispatched_job_carries_a_pending_send_log_id(): void
    {
        $campaign = $this->preparedCampaign(3);
        $expected = SendLog::where('campaign_id', $campaign->id)->pluck('id')->all();

        (new SendCampaignBatch($campaign->id))->handle();

        $dispatched = [];
        Queue::assertPushed(SendSingleCampaignEmail::class, function ($job) use (&$dispatched) {
            $dispatched[] = $job->sendLogId;

            return true;
        });

        $this->assertEqualsCanonicalizing($expected, $dispatched);
    }

    public function test_the_job_does_not_resolve_recipients(): void
    {
        $campaign = $this->preparedCampaign(3);

        // Emptying the filters after preparation proves the job reads rows rather
        // than re-resolving. Before T2 it resolved, and then read the results as
        // arrays — fatal for any recipient without a host model.
        $campaign->update(['filter_type' => null, 'filter_params' => []]);

        (new SendCampaignBatch($campaign->id))->handle();

        Queue::assertPushed(SendSingleCampaignEmail::class, 3);
    }

    public function test_a_scheduled_campaign_is_not_fanned_out(): void
    {
        $campaign = $this->preparedCampaign(3);
        $campaign->update(['status' => 'scheduled']);

        (new SendCampaignBatch($campaign->id))->handle();

        // Accepting `scheduled` here would let fan-out bypass the atomic claim,
        // which is the exactly-once guarantee.
        Queue::assertNotPushed(SendSingleCampaignEmail::class);
    }

    public function test_a_sent_campaign_is_not_fanned_out(): void
    {
        $campaign = $this->preparedCampaign(3);
        $campaign->update(['status' => 'sent']);

        (new SendCampaignBatch($campaign->id))->handle();

        Queue::assertNotPushed(SendSingleCampaignEmail::class);
    }

    public function test_a_missing_campaign_is_a_no_op(): void
    {
        (new SendCampaignBatch(999999))->handle();

        Queue::assertNotPushed(SendSingleCampaignEmail::class);
    }

    public function test_no_pending_rows_dispatches_nothing(): void
    {
        $campaign = $this->preparedCampaign(2);
        SendLog::where('campaign_id', $campaign->id)->update(['status' => 'sent']);

        (new SendCampaignBatch($campaign->id))->handle();

        Queue::assertNotPushed(SendSingleCampaignEmail::class);
    }

    public function test_already_sent_rows_are_not_re_dispatched(): void
    {
        $campaign = $this->preparedCampaign(3);
        $first = SendLog::where('campaign_id', $campaign->id)->orderBy('id')->first();
        $first->update(['status' => 'sent']);

        (new SendCampaignBatch($campaign->id))->handle();

        Queue::assertPushed(SendSingleCampaignEmail::class, 2);
    }

    public function test_fan_out_does_not_overwrite_the_recipient_total(): void
    {
        $campaign = $this->preparedCampaign(3);
        $this->assertSame(3, $campaign->total_recipients);

        (new SendCampaignBatch($campaign->id))->handle();

        // Preparation owns this count; writing it here too would create a second
        // source of truth.
        $this->assertSame(3, $campaign->fresh()->total_recipients);
    }

    public function test_sequence_rows_are_never_fanned_out_as_campaign_email(): void
    {
        SendLog::create(['email' => 'seq@example.com', 'status' => 'pending']);
        $campaign = $this->preparedCampaign(2);

        (new SendCampaignBatch($campaign->id))->handle();

        Queue::assertPushed(SendSingleCampaignEmail::class, 2);
    }
}
