<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Services;

use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Services\CampaignService;
use ColorrageAR\Autoresponder\Tests\TestCase;

class CampaignServiceTest extends TestCase
{
    private CampaignService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CampaignService::class);
    }

    public function test_resolve_manual_emails(): void
    {
        $recipients = $this->service->resolveRecipients([
            'filter_type' => 'manual_emails',
            'manual_emails' => 'a@example.com,b@example.com',
        ]);

        $this->assertCount(2, $recipients);
        $this->assertEquals('a@example.com', $recipients->first()->getSubscribableEmail());
    }

    public function test_resolve_empty_mailer_lists(): void
    {
        $recipients = $this->service->resolveRecipients([
            'filter_type' => 'mailer_lists',
            'selected_lists' => [],
        ]);

        $this->assertCount(0, $recipients);
    }

    public function test_resolve_manual_emails_empty(): void
    {
        $recipients = $this->service->resolveRecipients([
            'filter_type' => 'manual_emails',
            'manual_emails' => '',
        ]);

        $this->assertCount(0, $recipients);
    }

    public function test_resolve_unknown_filter_type(): void
    {
        $recipients = $this->service->resolveRecipients([
            'filter_type' => 'unknown',
        ]);

        $this->assertCount(0, $recipients);
    }

    public function test_schedule_campaign_in_past_throws(): void
    {
        $campaign = Campaign::create([
            'name' => 'Test Campaign',
            'status' => 'draft',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sendAt must be in the future');

        $this->service->scheduleCampaign($campaign, now()->subDay());
    }

    public function test_schedule_campaign_in_future(): void
    {
        $campaign = Campaign::create([
            'name' => 'Future Campaign',
            'status' => 'draft',
        ]);

        $future = now()->addDay();
        $this->service->scheduleCampaign($campaign, $future);

        $this->assertEquals('scheduled', $campaign->fresh()->status);
    }

    public function test_cancel_campaign(): void
    {
        $campaign = Campaign::create([
            'name' => 'To Cancel',
            'status' => 'scheduled',
        ]);

        $this->service->cancelCampaign($campaign);

        $fresh = $campaign->fresh();
        $this->assertEquals('cancelled', $fresh->status);
        $this->assertNull($fresh->scheduled_at);
    }

    public function test_send_campaign_with_no_recipients_throws(): void
    {
        $campaign = Campaign::create([
            'name' => 'No Recipients',
            'status' => 'draft',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No recipients found');

        $this->service->sendCampaign($campaign);
    }
}
