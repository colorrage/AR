<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Models;

use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * The Campaign model and the campaigns migration.
 *
 * tests/Unit/Models/ had files for Enrollment, SendLog, Sequence, Step and Template
 * but none for Campaign, despite it carrying the status machine the whole broadcast
 * feature turns on.
 */
class CampaignTest extends TestCase
{
    // ── Migration shape ───────────────────────────────────────────────

    public function test_the_campaigns_table_has_a_failure_reason_column(): void
    {
        $this->assertTrue(Schema::hasColumn('ar_campaigns', 'failure_reason'));
    }

    public function test_the_campaigns_table_brackets_a_send_with_timestamps(): void
    {
        foreach (['scheduled_at', 'started_at', 'finished_at', 'sent_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('ar_campaigns', $column), $column);
        }
    }

    public function test_every_valid_status_can_be_persisted(): void
    {
        foreach (['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'] as $status) {
            $campaign = Campaign::create(['name' => "S {$status}", 'status' => $status]);
            $this->assertSame($status, $campaign->fresh()->status);
        }
    }

    public function test_the_removed_queued_status_is_absent_from_package_code(): void
    {
        // Asserts the status literal, not the English word — a comment in src/
        // legitimately mentions queues. stubs/ is deliberately excluded: the Filament
        // scaffold still writes the removed value, and that belongs to T4.
        $root = dirname(__DIR__, 3);
        exec(sprintf("grep -rn %s %s %s 2>/dev/null", escapeshellarg("'queued'"), escapeshellarg("{$root}/src"), escapeshellarg("{$root}/database")), $hits);

        $this->assertSame([], $hits, "unexpected 'queued' occurrences:\n" . implode("\n", $hits));
    }

    // ── Status transitions ────────────────────────────────────────────

    public function test_mark_as_sending_sets_the_status(): void
    {
        $campaign = Campaign::create(['name' => 'C', 'status' => 'draft']);

        $campaign->markAsSending();

        $this->assertSame('sending', $campaign->fresh()->status);
    }

    public function test_mark_as_sent_sets_the_status_and_timestamp(): void
    {
        $campaign = Campaign::create(['name' => 'C', 'status' => 'sending']);

        $campaign->markAsSent();

        $fresh = $campaign->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
    }

    public function test_mark_as_failed_persists_its_reason(): void
    {
        // The method accepted and discarded this argument before T2.
        $campaign = Campaign::create(['name' => 'C', 'status' => 'sending']);

        $campaign->markAsFailed('transport refused the batch');

        $fresh = $campaign->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('transport refused the batch', $fresh->failure_reason);
    }

    public function test_mark_as_failed_will_not_relabel_a_sent_campaign(): void
    {
        // A fan-out job's failed() callback can arrive after the per-email jobs have
        // already completed the campaign; without the guard a fully delivered
        // campaign would be reported as failed.
        $campaign = Campaign::create(['name' => 'C', 'status' => 'sent']);

        $campaign->markAsFailed('late fan-out failure');

        $fresh = $campaign->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNull($fresh->failure_reason);
    }

    public function test_mark_as_failed_will_not_relabel_a_cancelled_campaign(): void
    {
        $campaign = Campaign::create(['name' => 'C', 'status' => 'cancelled']);

        $campaign->markAsFailed('too late');

        $this->assertSame('cancelled', $campaign->fresh()->status);
    }

    public function test_mark_as_failed_still_works_on_a_live_campaign(): void
    {
        foreach (['draft', 'scheduled', 'sending'] as $status) {
            $campaign = Campaign::create(['name' => "C {$status}", 'status' => $status]);

            $campaign->markAsFailed('genuine failure');

            $this->assertSame('failed', $campaign->fresh()->status, $status);
        }
    }

    public function test_mark_as_failed_refreshes_the_instance(): void
    {
        $campaign = Campaign::create(['name' => 'C', 'status' => 'sending']);

        $returned = $campaign->markAsFailed('reason');

        $this->assertSame('failed', $returned->status, 'the returned instance must reflect the write');
    }

    // ── Casts ─────────────────────────────────────────────────────────

    public function test_filter_params_round_trips_as_an_array(): void
    {
        $campaign = Campaign::create([
            'name' => 'C',
            'status' => 'draft',
            'filter_params' => ['selected_lists' => [1, 2], 'ignore_unsubscribed' => true],
        ]);

        $fresh = $campaign->fresh();
        $this->assertIsArray($fresh->filter_params);
        $this->assertSame([1, 2], $fresh->filter_params['selected_lists']);
    }

    public function test_counters_and_dates_are_cast(): void
    {
        $campaign = Campaign::create([
            'name' => 'C',
            'status' => 'sent',
            'total_recipients' => '5',
            'sent_count' => '4',
            'failed_count' => '1',
            'sent_at' => now(),
        ]);

        $fresh = $campaign->fresh();
        $this->assertIsInt($fresh->total_recipients);
        $this->assertIsInt($fresh->sent_count);
        $this->assertIsInt($fresh->failed_count);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $fresh->sent_at);
    }

    // ── Computed attributes ───────────────────────────────────────────

    public function test_status_color_covers_every_valid_status_and_falls_back(): void
    {
        foreach (['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'] as $status) {
            $campaign = Campaign::create(['name' => "C {$status}", 'status' => $status]);
            $this->assertNotSame('secondary', $campaign->statusColor, $status);
        }

        $unknown = Campaign::create(['name' => 'C', 'status' => 'draft']);
        $unknown->forceFill(['status' => 'not_a_status']);
        $this->assertSame('secondary', $unknown->statusColor);
    }

    public function test_rates_are_zero_with_no_sent_rows(): void
    {
        $campaign = Campaign::create(['name' => 'C', 'status' => 'draft']);

        // Division-by-zero candidates that nothing covered.
        $this->assertSame(0.0, $campaign->openRate);
        $this->assertSame(0.0, $campaign->clickRate);
    }

    public function test_rates_reflect_engagement_on_sent_rows(): void
    {
        $campaign = Campaign::create(['name' => 'C', 'status' => 'sent']);

        foreach ([true, true, false, false] as $i => $opened) {
            SendLog::create([
                'campaign_id' => $campaign->id,
                'email' => "r{$i}@example.com",
                'status' => 'sent',
                'opened_at' => $opened ? now() : null,
                'clicked_at' => $i === 0 ? now() : null,
            ]);
        }

        $this->assertSame(50.0, $campaign->openRate);
        $this->assertSame(25.0, $campaign->clickRate);
    }

    public function test_rates_ignore_rows_that_were_never_sent(): void
    {
        $campaign = Campaign::create(['name' => 'C', 'status' => 'sending']);

        SendLog::create(['campaign_id' => $campaign->id, 'email' => 'a@example.com', 'status' => 'sent', 'opened_at' => now()]);
        SendLog::create(['campaign_id' => $campaign->id, 'email' => 'b@example.com', 'status' => 'pending']);
        SendLog::create(['campaign_id' => $campaign->id, 'email' => 'c@example.com', 'status' => 'failed']);

        $this->assertSame(100.0, $campaign->openRate, 'denominator is sent rows only');
    }

    // ── Relationships ─────────────────────────────────────────────────

    public function test_send_logs_are_scoped_to_the_campaign(): void
    {
        $campaign = Campaign::create(['name' => 'C', 'status' => 'sending']);
        SendLog::create(['campaign_id' => $campaign->id, 'email' => 'mine@example.com', 'status' => 'sent']);
        SendLog::create(['email' => 'sequence@example.com', 'status' => 'sent']);

        $this->assertCount(1, $campaign->sendLogs);
        $this->assertSame('mine@example.com', $campaign->sendLogs->first()->email);
    }

    public function test_the_scheduled_scope_selects_only_due_campaigns(): void
    {
        Campaign::create(['name' => 'due', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
        Campaign::create(['name' => 'future', 'status' => 'scheduled', 'scheduled_at' => now()->addHour()]);
        Campaign::create(['name' => 'draft', 'status' => 'draft', 'scheduled_at' => now()->subMinute()]);

        $due = Campaign::scheduled()->pluck('name')->all();

        $this->assertSame(['due'], $due);
    }

    public function test_the_by_status_scope_filters(): void
    {
        Campaign::create(['name' => 'a', 'status' => 'sent']);
        Campaign::create(['name' => 'b', 'status' => 'draft']);

        $this->assertSame(['a'], Campaign::byStatus('sent')->pluck('name')->all());
    }
}
