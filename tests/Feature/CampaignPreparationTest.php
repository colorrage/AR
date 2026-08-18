<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use ColorrageAR\Autoresponder\Jobs\SendCampaignBatch;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use ColorrageAR\Autoresponder\Services\CampaignService;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Everything CampaignService::sendCampaign() does before a job is dispatched.
 *
 * The queue is faked throughout: preparation dispatches the fan-out job, and under
 * the sync driver that runs the entire campaign to completion before any prepared
 * state can be observed.
 */
class CampaignPreparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(): CampaignService
    {
        return app(CampaignService::class);
    }

    // ── Recipient resolution ──────────────────────────────────────────

    public function test_filter_type_is_read_from_the_campaign_column_not_filter_params(): void
    {
        $list = $this->makeStaticList(3);

        // filter_type on the column only — filter_params carries just the list ids,
        // exactly what the Filament stub writes. Reading filter_type from
        // filter_params instead resolved zero recipients for every campaign.
        $campaign = $this->makeListCampaign($list);
        $this->assertSame('mailer_lists', $campaign->filter_type);
        $this->assertArrayNotHasKey('filter_type', $campaign->filter_params);

        $this->assertSame(3, $this->service()->sendCampaign($campaign));
    }

    public function test_manual_addresses_resolve_one_recipient_each(): void
    {
        $campaign = $this->makeManualCampaign('a@example.com, b@example.com');

        $this->assertSame(2, $this->service()->sendCampaign($campaign));
        $this->assertEqualsCanonicalizing(
            ['a@example.com', 'b@example.com'],
            SendLog::where('campaign_id', $campaign->id)->pluck('email')->all(),
        );
    }

    public function test_static_list_rows_without_a_host_model_resolve(): void
    {
        // The imported-address shape: list rows with no subscriber_id. Reading these
        // as arrays was fatal before T2.
        $campaign = $this->makeListCampaign($this->makeStaticList(3));

        $this->assertSame(3, $this->service()->sendCampaign($campaign));
        $this->assertSame(3, SendLog::where('campaign_id', $campaign->id)->whereNull('subscriber_id')->count());
    }

    public function test_duplicate_addresses_within_one_source_collapse_to_one_recipient(): void
    {
        $campaign = $this->makeManualCampaign('dup@example.com, other@example.com, DUP@example.com');

        $this->assertSame(2, $this->service()->sendCampaign($campaign));
    }

    public function test_addresses_shared_by_two_lists_collapse_to_one_recipient(): void
    {
        $a = $this->makeStaticList(2, 'shared%d@example.com');
        $b = $this->makeStaticList(2, 'shared%d@example.com');

        $campaign = $this->makeListCampaign($a, [
            'filter_params' => ['selected_lists' => [$a->id, $b->id]],
        ]);

        $this->assertSame(2, $this->service()->sendCampaign($campaign));
    }

    // ── Suppression ───────────────────────────────────────────────────

    public function test_suppressed_addresses_are_excluded(): void
    {
        Unsubscribe::create(['email' => 'u0@example.com', 'unsubscribed_at' => now()]);
        $campaign = $this->makeListCampaign($this->makeStaticList(3));

        $this->assertSame(2, $this->service()->sendCampaign($campaign));
        $this->assertNotContains(
            'u0@example.com',
            SendLog::where('campaign_id', $campaign->id)->pluck('email')->all(),
        );
    }

    public function test_suppression_matches_regardless_of_address_casing(): void
    {
        // Casing deliberately mismatched on both sides. This was invisible on MySQL,
        // whose default collation is case-insensitive, and live on PostgreSQL.
        Unsubscribe::create(['email' => 'U0@EXAMPLE.COM', 'unsubscribed_at' => now()]);
        $campaign = $this->makeListCampaign($this->makeStaticList(3));

        $this->assertSame(2, $this->service()->sendCampaign($campaign));
        $this->assertNotContains(
            'u0@example.com',
            SendLog::where('campaign_id', $campaign->id)->pluck('email')->all(),
        );
    }

    // ── Guards ────────────────────────────────────────────────────────

    public function test_campaign_without_a_template_fails_with_a_reason_and_creates_no_rows(): void
    {
        $campaign = Campaign::create([
            'name' => 'No template',
            'status' => 'draft',
            'filter_type' => 'manual_emails',
            'filter_params' => ['manual_emails' => 'z@example.com'],
        ]);

        try {
            $this->service()->sendCampaign($campaign);
            $this->fail('expected a RuntimeException for a template-less campaign');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no template', $e->getMessage());
        }

        $fresh = $campaign->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->failure_reason);
        $this->assertSame(0, SendLog::where('campaign_id', $campaign->id)->count(), 'no recipient may be silently dropped');
    }

    public function test_empty_recipient_set_refuses_and_leaves_the_campaign_retryable(): void
    {
        $campaign = $this->makeListCampaign($this->makeStaticList(0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No recipients found');

        try {
            $this->service()->sendCampaign($campaign);
        } finally {
            // Still draft, so the operator can fix the list and send again.
            $this->assertSame('draft', $campaign->fresh()->status);
            $this->assertSame(0, SendLog::where('campaign_id', $campaign->id)->count());
        }
    }

    public function test_recipient_count_above_the_ceiling_refuses_and_leaves_the_campaign_retryable(): void
    {
        config(['autoresponder.rate_limit.max_emails_per_campaign' => 2]);
        $campaign = $this->makeListCampaign($this->makeStaticList(3));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum');

        try {
            $this->service()->sendCampaign($campaign);
        } finally {
            $this->assertSame('draft', $campaign->fresh()->status);
            $this->assertSame(0, SendLog::where('campaign_id', $campaign->id)->count());
        }
    }

    // ── The claim ─────────────────────────────────────────────────────

    public function test_repeat_preparation_cannot_duplicate_the_row_set(): void
    {
        $campaign = $this->makeListCampaign($this->makeStaticList(3));

        $first = $this->service()->sendCampaign($campaign);
        $second = $this->service()->sendCampaign($campaign->fresh());

        $this->assertSame(3, $first);
        $this->assertSame(0, $second, 'a second dispatch must find nothing to claim');
        $this->assertSame(3, SendLog::where('campaign_id', $campaign->id)->count());
    }

    public function test_claiming_moves_the_campaign_to_sending(): void
    {
        $campaign = $this->makeListCampaign($this->makeStaticList(2));

        $this->service()->sendCampaign($campaign);

        $this->assertSame('sending', $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->started_at);
    }

    // ── Log pre-creation ──────────────────────────────────────────────

    public function test_every_created_row_carries_a_distinct_token_and_timestamps(): void
    {
        $campaign = $this->makeListCampaign($this->makeStaticList(4));
        $this->service()->sendCampaign($campaign);

        $rows = SendLog::where('campaign_id', $campaign->id)->get();

        $this->assertCount(4, $rows);
        // A bulk insert bypasses model events, so these are exactly the fields that
        // go missing silently. A null token surfaces only when someone unsubscribes.
        $this->assertCount(4, $rows->pluck('unsubscribe_token')->filter()->unique());
        foreach ($rows as $row) {
            $this->assertSame(64, strlen($row->unsubscribe_token));
            $this->assertNotNull($row->created_at);
            $this->assertNotNull($row->updated_at);
            $this->assertSame('pending', $row->status);
        }
    }

    public function test_created_rows_are_campaign_rows_not_sequence_rows(): void
    {
        $campaign = $this->makeListCampaign($this->makeStaticList(2));
        $this->service()->sendCampaign($campaign);

        $this->assertSame(
            0,
            SendLog::where('campaign_id', $campaign->id)->whereNotNull('autoresponder_id')->count(),
        );
    }

    public function test_total_recipients_matches_the_rows_actually_created(): void
    {
        Unsubscribe::create(['email' => 'u1@example.com', 'unsubscribed_at' => now()]);
        $campaign = $this->makeListCampaign($this->makeStaticList(3));

        $created = $this->service()->sendCampaign($campaign);

        $this->assertSame(2, $created);
        $this->assertSame(2, $campaign->fresh()->total_recipients);
        $this->assertSame(2, SendLog::where('campaign_id', $campaign->id)->count());
    }

    public function test_preparation_runs_in_a_transaction(): void
    {
        $opened = 0;
        Event::listen(TransactionBeginning::class, function () use (&$opened) {
            $opened++;
        });

        $campaign = $this->makeListCampaign($this->makeStaticList(3));
        $this->service()->sendCampaign($campaign);

        $this->assertGreaterThan(0, $opened, 'claim, insert and count must land together');
    }

    public function test_rows_are_committed_before_the_fan_out_job_is_dispatched(): void
    {
        $campaign = $this->makeListCampaign($this->makeStaticList(3));
        $this->service()->sendCampaign($campaign);

        $visible = null;
        Queue::assertPushed(SendCampaignBatch::class, function () use ($campaign, &$visible) {
            $visible = SendLog::where('campaign_id', $campaign->id)->count();

            return true;
        });

        $this->assertSame(3, $visible, 'a worker must never see the campaign before its rows');
    }

    // ── Isolation from the drip path ──────────────────────────────────

    public function test_sequence_rows_are_untouched_by_a_campaign(): void
    {
        SendLog::create(['email' => 'seq@example.com', 'status' => 'pending']);

        $campaign = $this->makeListCampaign($this->makeStaticList(2));
        $this->service()->sendCampaign($campaign);

        $this->assertSame(1, SendLog::whereNull('campaign_id')->count(), 'the drip path shares this table');
    }

    public function test_unknown_and_missing_filter_types_resolve_no_recipients(): void
    {
        $this->assertCount(0, $this->service()->resolveRecipients('not_a_filter'));
        $this->assertCount(0, $this->service()->resolveRecipients(null));
    }

    public function test_a_list_row_marked_unsubscribed_is_not_a_recipient(): void
    {
        $list = $this->makeStaticList(3);
        ListSubscriber::where('list_id', $list->id)
            ->where('email', 'u0@example.com')
            ->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);

        $this->assertSame(2, $this->service()->sendCampaign($this->makeListCampaign($list)));
    }
}
