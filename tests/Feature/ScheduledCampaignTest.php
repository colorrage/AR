<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use ColorrageAR\Autoresponder\Jobs\SendScheduledCampaign;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Services\CampaignService;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * The timed entry point: autoresponder:process-scheduled-campaigns and
 * SendScheduledCampaign.
 *
 * Exactly-once lives entirely in CampaignService's atomic claim. The job holds no
 * claim of its own by design — holding one would move the campaign to `sending` and
 * make the service's claim match zero rows, stranding it silently. See coding rule 7
 * in AR/.claude.md.
 */
class ScheduledCampaignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function dueCampaign(int $recipients = 2): Campaign
    {
        $campaign = $this->makeListCampaign($this->makeStaticList($recipients), [
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        return $campaign->fresh();
    }

    // ── The command ───────────────────────────────────────────────────

    public function test_the_command_dispatches_an_integer_id_not_a_model(): void
    {
        $campaign = $this->dueCampaign();

        $this->artisan('autoresponder:process-scheduled-campaigns')->assertSuccessful();

        // Passing the model raised a TypeError against the int-typed constructor and
        // broke every scheduled campaign. Asserting only "a job was queued" would
        // not have caught it.
        Queue::assertPushed(SendScheduledCampaign::class, function ($job) use ($campaign) {
            return $job->campaignId === $campaign->id;
        });
    }

    public function test_a_campaign_not_yet_due_is_not_selected(): void
    {
        $this->makeListCampaign($this->makeStaticList(2), [
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
        ]);

        $this->artisan('autoresponder:process-scheduled-campaigns')->assertSuccessful();

        Queue::assertNotPushed(SendScheduledCampaign::class);
    }

    public function test_a_draft_campaign_is_not_selected(): void
    {
        $this->makeListCampaign($this->makeStaticList(2), ['scheduled_at' => now()->subMinute()]);

        $this->artisan('autoresponder:process-scheduled-campaigns')->assertSuccessful();

        Queue::assertNotPushed(SendScheduledCampaign::class);
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        $this->dueCampaign();

        $this->artisan('autoresponder:process-scheduled-campaigns', ['--dry-run' => true])
            ->assertSuccessful();

        Queue::assertNotPushed(SendScheduledCampaign::class);
    }

    public function test_the_command_succeeds_with_no_due_campaigns(): void
    {
        $this->artisan('autoresponder:process-scheduled-campaigns')->assertSuccessful();

        Queue::assertNotPushed(SendScheduledCampaign::class);
    }

    // ── Exactly once ──────────────────────────────────────────────────

    public function test_repeated_command_runs_before_the_queue_drains_send_only_once(): void
    {
        $campaign = $this->dueCampaign(2);

        $this->artisan('autoresponder:process-scheduled-campaigns')->assertSuccessful();
        $this->artisan('autoresponder:process-scheduled-campaigns')->assertSuccessful();

        Queue::assertPushed(SendScheduledCampaign::class, 2);

        // Two jobs queued, but only one may prepare the campaign.
        $service = app(CampaignService::class);
        (new SendScheduledCampaign($campaign->id))->handle($service);
        (new SendScheduledCampaign($campaign->id))->handle($service);

        $this->assertSame(2, SendLog::where('campaign_id', $campaign->id)->count(), 'one row set, not two');
        $this->assertSame(2, $campaign->fresh()->total_recipients);
    }

    // ── The job ───────────────────────────────────────────────────────

    public function test_a_due_campaign_is_prepared_exactly_as_an_immediate_send_would_be(): void
    {
        $scheduled = $this->dueCampaign(3);
        (new SendScheduledCampaign($scheduled->id))->handle(app(CampaignService::class));

        $immediate = $this->makeListCampaign($this->makeStaticList(3, 'v%d@example.com'));
        app(CampaignService::class)->sendCampaign($immediate);

        // Routing through the service rather than reimplementing preparation is what
        // keeps the two paths from diverging again.
        $this->assertSame(
            SendLog::where('campaign_id', $immediate->id)->count(),
            SendLog::where('campaign_id', $scheduled->id)->count(),
        );
        $this->assertSame($immediate->fresh()->total_recipients, $scheduled->fresh()->total_recipients);
        $this->assertSame('sending', $scheduled->fresh()->status);
    }

    public function test_a_job_whose_campaign_is_no_longer_scheduled_is_a_no_op(): void
    {
        $campaign = $this->dueCampaign();
        $campaign->update(['status' => 'cancelled']);

        (new SendScheduledCampaign($campaign->id))->handle(app(CampaignService::class));

        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertSame(0, SendLog::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_job_whose_campaign_is_not_yet_due_is_a_no_op(): void
    {
        $campaign = $this->makeListCampaign($this->makeStaticList(2), [
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
        ]);

        (new SendScheduledCampaign($campaign->id))->handle(app(CampaignService::class));

        $this->assertSame('scheduled', $campaign->fresh()->status);
        $this->assertSame(0, SendLog::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_job_for_a_missing_campaign_is_a_no_op(): void
    {
        (new SendScheduledCampaign(999999))->handle(app(CampaignService::class));

        $this->assertSame(0, SendLog::count());
    }

    public function test_a_scheduled_campaign_with_a_null_send_time_is_treated_as_due(): void
    {
        $campaign = $this->makeListCampaign($this->makeStaticList(2), [
            'status' => 'scheduled',
            'scheduled_at' => null,
        ]);

        (new SendScheduledCampaign($campaign->id))->handle(app(CampaignService::class));

        $this->assertSame('sending', $campaign->fresh()->status);
        $this->assertSame(2, SendLog::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_failed_scheduled_dispatch_records_a_reason(): void
    {
        $campaign = $this->dueCampaign();

        (new SendScheduledCampaign($campaign->id))->failed(new \RuntimeException('queue exploded'));

        $fresh = $campaign->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('queue exploded', (string) $fresh->failure_reason);
    }
}
