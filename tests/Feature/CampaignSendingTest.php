<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use ColorrageAR\Autoresponder\Jobs\SendSingleCampaignEmail;
use ColorrageAR\Autoresponder\Mail\AutoresponderMail;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Services\CampaignService;
use ColorrageAR\Autoresponder\Services\TokenService;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * SendSingleCampaignEmail — what is delivered, what is recorded, how a campaign closes.
 *
 * The queue is faked so each recipient can be driven by hand. Under the sync driver
 * all recipients run inside preparation and the campaign is already closed, which
 * looks exactly like the premature-completion bug this guards against.
 */
class CampaignSendingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** @return array{0: Campaign, 1: list<int>} */
    private function prepared(int $recipients): array
    {
        $campaign = $this->makeListCampaign($this->makeStaticList($recipients));
        app(CampaignService::class)->sendCampaign($campaign);

        return [
            $campaign->fresh(),
            SendLog::where('campaign_id', $campaign->id)->orderBy('id')->pluck('id')->all(),
        ];
    }

    private function send(int $sendLogId): void
    {
        (new SendSingleCampaignEmail($sendLogId))->handle(app(TokenService::class));
    }

    // ── Delivery and rendering ────────────────────────────────────────

    public function test_the_email_is_delivered_to_the_row_address(): void
    {
        [, $ids] = $this->prepared(1);

        $this->send($ids[0]);

        Mail::assertSent(AutoresponderMail::class);
        $this->assertSame('sent', SendLog::find($ids[0])->status);
    }

    public function test_the_persisted_body_carries_tracking_and_the_unsubscribe_footer(): void
    {
        [, $ids] = $this->prepared(1);

        $this->send($ids[0]);

        // Mail::fake() captures without rendering, so the mailable cannot be asserted
        // against. body_html is the persisted artifact the unsubscribe page reads.
        $row = SendLog::find($ids[0]);
        $this->assertStringContainsString('track/open/' . $row->id, $row->body_html, 'tracking pixel');
        $this->assertStringContainsString('track/click/' . $row->id, $row->body_html, 'links rewritten');
        $this->assertStringContainsString($row->unsubscribe_token, $row->body_html, 'unsubscribe footer');
        $this->assertStringNotContainsString('https://example.com/go"', $row->body_html, 'original href replaced');
    }

    public function test_tokens_are_substituted_in_subject_and_body(): void
    {
        [, $ids] = $this->prepared(1);

        $this->send($ids[0]);

        $row = SendLog::find($ids[0]);
        $this->assertStringNotContainsString('##subscriber.name##', (string) $row->subject);
        $this->assertStringNotContainsString('##subscriber.name##', (string) $row->body_html);
    }

    public function test_the_row_records_the_language_used(): void
    {
        [, $ids] = $this->prepared(1);

        $this->send($ids[0]);

        $this->assertSame('en', SendLog::find($ids[0])->language);
    }

    // ── No premature completion ───────────────────────────────────────

    public function test_the_campaign_stays_sending_until_every_recipient_is_processed(): void
    {
        [$campaign, $ids] = $this->prepared(3);

        $this->send($ids[0]);
        $this->assertSame('sending', $campaign->fresh()->status, 'must not close after 1 of 3');

        $this->send($ids[1]);
        $this->assertSame('sending', $campaign->fresh()->status, 'must not close after 2 of 3');

        $this->send($ids[2]);
        $this->assertSame('sent', $campaign->fresh()->status);
    }

    // ── Accounting ────────────────────────────────────────────────────

    public function test_counts_reconcile_against_the_recipient_total(): void
    {
        [$campaign, $ids] = $this->prepared(3);

        foreach ($ids as $id) {
            $this->send($id);
        }

        $fresh = $campaign->fresh();
        $this->assertSame(3, $fresh->sent_count);
        $this->assertSame(0, $fresh->failed_count);
        $this->assertSame(3, $fresh->total_recipients);
        $this->assertSame($fresh->total_recipients, $fresh->sent_count + $fresh->failed_count);
        $this->assertNotNull($fresh->sent_at);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_a_partial_failure_still_reports_sent_with_mixed_counts(): void
    {
        [$campaign, $ids] = $this->prepared(3);

        $this->send($ids[0]);
        // Fail the second recipient outright rather than through the retry path.
        SendLog::find($ids[1])->markAsFailed('transport refused');
        Campaign::where('id', $campaign->id)->increment('failed_count');
        $this->send($ids[2]);

        $fresh = $campaign->fresh();
        $this->assertSame('sent', $fresh->status, 'at least one delivered means sent, not failed');
        $this->assertSame(2, $fresh->sent_count);
        $this->assertSame(1, $fresh->failed_count);
        $this->assertSame($fresh->total_recipients, $fresh->sent_count + $fresh->failed_count);
    }

    public function test_a_campaign_where_every_recipient_fails_reports_failed(): void
    {
        [$campaign, $ids] = $this->prepared(2);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        // Drive both recipients through the production failure path on their final
        // attempt, so the job's own completion logic closes the campaign.
        foreach ($ids as $id) {
            try {
                (new SendSingleCampaignEmailOnAttempt($id, attempt: 3))->handle(app(TokenService::class));
            } catch (\RuntimeException) {
                // expected — the job rethrows so the queue can record the failure
            }
        }

        $fresh = $campaign->fresh();
        $this->assertSame('failed', $fresh->status, 'nothing delivered means failed');
        $this->assertSame(0, $fresh->sent_count);
        $this->assertSame(2, $fresh->failed_count);
        $this->assertSame($fresh->total_recipients, $fresh->sent_count + $fresh->failed_count);
        $this->assertNotNull($fresh->finished_at);
    }

    // ── Isolation between concurrent campaigns ────────────────────────

    public function test_completion_is_scoped_to_its_own_campaign(): void
    {
        // Two campaigns in flight at once. Completion counts pending rows
        // `where('campaign_id', …)`; drop that scope and one campaign's rows would
        // block the other from closing, or a closing campaign would recompute its
        // counts from rows belonging to a different send.
        $first = $this->makeListCampaign($this->makeStaticList(2, 'first%d@example.com'));
        app(CampaignService::class)->sendCampaign($first);

        $second = $this->makeListCampaign($this->makeStaticList(3, 'second%d@example.com'));
        app(CampaignService::class)->sendCampaign($second);

        foreach (SendLog::where('campaign_id', $first->id)->pluck('id') as $id) {
            $this->send($id);
        }

        $firstFresh = $first->fresh();
        $this->assertSame('sent', $firstFresh->status, "the first campaign's own rows are all done");
        $this->assertSame(2, $firstFresh->sent_count, "counts must not include the other campaign's rows");
        $this->assertSame(2, $firstFresh->total_recipients);

        $secondFresh = $second->fresh();
        $this->assertSame('sending', $secondFresh->status, 'an untouched campaign must not be closed by another');
        $this->assertSame(0, $secondFresh->sent_count);
        $this->assertNull($secondFresh->finished_at);
        $this->assertSame(3, SendLog::where('campaign_id', $second->id)->where('status', 'pending')->count());
    }

    public function test_a_campaign_with_rows_outstanding_is_not_closed_by_another_campaign_finishing(): void
    {
        // The inverse framing: finish the *second* campaign and confirm the first,
        // still mid-flight, is untouched.
        $inFlight = $this->makeListCampaign($this->makeStaticList(2, 'flight%d@example.com'));
        app(CampaignService::class)->sendCampaign($inFlight);

        $other = $this->makeListCampaign($this->makeStaticList(1, 'other%d@example.com'));
        app(CampaignService::class)->sendCampaign($other);

        $this->send((int) SendLog::where('campaign_id', $other->id)->value('id'));

        $this->assertSame('sent', $other->fresh()->status);
        $this->assertSame('sending', $inFlight->fresh()->status);
        $this->assertSame(2, SendLog::where('campaign_id', $inFlight->id)->where('status', 'pending')->count());
    }

    // ── Idempotency ───────────────────────────────────────────────────

    public function test_replaying_a_sent_row_does_not_send_or_count_again(): void
    {
        [$campaign, $ids] = $this->prepared(1);

        $this->send($ids[0]);
        $this->send($ids[0]);

        $this->assertSame(1, $campaign->fresh()->sent_count);
        Mail::assertSent(AutoresponderMail::class, 1);
    }

    public function test_a_missing_row_is_a_no_op(): void
    {
        $this->send(999999);

        Mail::assertNothingSent();
    }

    public function test_closing_a_campaign_twice_does_not_double_transition(): void
    {
        [$campaign, $ids] = $this->prepared(1);

        $this->send($ids[0]);
        $closedAt = $campaign->fresh()->finished_at;

        // A second job arriving late must not reopen or re-stamp the campaign.
        $this->send($ids[0]);

        $this->assertSame('sent', $campaign->fresh()->status);
        $this->assertEquals($closedAt, $campaign->fresh()->finished_at);
    }

    // ── The retry boundary ────────────────────────────────────────────

    public function test_a_failure_with_attempts_remaining_leaves_the_row_pending(): void
    {
        [$campaign, $ids] = $this->prepared(1);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $job = new SendSingleCampaignEmailOnAttempt($ids[0], attempt: 1);

        try {
            $job->handle(app(TokenService::class));
            $this->fail('expected the transport failure to propagate so the queue retries');
        } catch (\RuntimeException $e) {
            $this->assertSame('smtp down', $e->getMessage());
        }

        $row = SendLog::find($ids[0]);
        // Staying pending is what lets the retry run at all — the job's guard rejects
        // anything not pending — and what stops the campaign closing early while a
        // recipient is still being retried.
        $this->assertSame('pending', $row->status);
        $this->assertStringContainsString('smtp down', (string) $row->error_message);
        $this->assertSame(0, $campaign->fresh()->failed_count);
        $this->assertSame('sending', $campaign->fresh()->status);
    }

    public function test_a_failure_on_the_final_attempt_marks_the_row_failed_once(): void
    {
        [$campaign, $ids] = $this->prepared(1);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $job = new SendSingleCampaignEmailOnAttempt($ids[0], attempt: 3);

        try {
            $job->handle(app(TokenService::class));
            $this->fail('expected the transport failure to propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $row = SendLog::find($ids[0]);
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, $campaign->fresh()->failed_count, 'exactly once, not once per path');
        $this->assertSame('failed', $campaign->fresh()->status, 'sole recipient failed');
    }

    public function test_the_failed_hook_does_not_double_count_an_already_failed_row(): void
    {
        [$campaign, $ids] = $this->prepared(1);
        SendLog::find($ids[0])->markAsFailed('already closed out');
        Campaign::where('id', $campaign->id)->increment('failed_count');

        (new SendSingleCampaignEmail($ids[0]))->failed(new \RuntimeException('late'));

        $this->assertSame(1, $campaign->fresh()->failed_count);
    }

    public function test_the_failed_hook_closes_out_a_row_handle_never_reached(): void
    {
        [$campaign, $ids] = $this->prepared(1);

        // A timeout or killed worker: no exception path ran, the row is still pending.
        (new SendSingleCampaignEmail($ids[0]))->failed(new \RuntimeException('worker died'));

        $this->assertSame('failed', SendLog::find($ids[0])->status);
        $this->assertSame(1, $campaign->fresh()->failed_count);
    }
}

/**
 * A send job with a fixed attempt number.
 *
 * `attempts()` is supplied by the queue at runtime and stays at 0 on a job that is
 * constructed and invoked directly, so the retry boundary — which branches on
 * `attempts() >= tries` — cannot otherwise be exercised without a real worker.
 * Overriding only that one method keeps the production logic under test.
 */
class SendSingleCampaignEmailOnAttempt extends SendSingleCampaignEmail
{
    public function __construct(int $sendLogId, private readonly int $attempt = 1)
    {
        parent::__construct($sendLogId);
    }

    public function attempts(): int
    {
        return $this->attempt;
    }
}
