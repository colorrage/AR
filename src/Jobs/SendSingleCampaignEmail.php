<?php

namespace ColorrageAR\Autoresponder\Jobs;

use ColorrageAR\Autoresponder\Concerns\TracksEmailEngagement;
use ColorrageAR\Autoresponder\Contracts\Subscribable;
use ColorrageAR\Autoresponder\Mail\AutoresponderMail;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Services\TokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

use function ColorrageAR\Autoresponder\ar_log;
use function ColorrageAR\Autoresponder\ar_queue;
use function ColorrageAR\Autoresponder\ar_subscriber_model;

class SendSingleCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksEmailEngagement;

    public int $tries;

    public array $backoff;

    public int $timeout = 60;

    public function __construct(
        public int $sendLogId,
    ) {
        $this->onQueue(ar_queue());
        $this->tries = config('autoresponder.retry.max_attempts', 3);
        $this->backoff = config('autoresponder.retry.backoff', [60, 300, 900]);
    }

    public function handle(TokenService $tokenService): void
    {
        $sendLog = SendLog::find($this->sendLogId);

        if (! $sendLog) {
            ar_log()->warning('SendSingleCampaignEmail: send log not found', [
                'send_log_id' => $this->sendLogId,
            ]);

            return;
        }

        // Only pending rows are sendable. A row stays pending across retries and
        // becomes terminal exactly once, which is what keeps the completion test
        // in checkCampaignCompletion() honest.
        if ($sendLog->status !== 'pending') {
            ar_log()->info('SendSingleCampaignEmail: send log is not pending, skipping', [
                'send_log_id' => $sendLog->id,
                'status' => $sendLog->status,
            ]);

            return;
        }

        $campaign = Campaign::with('template')->find($sendLog->campaign_id);

        // CampaignService fails a template-less campaign before any row is created,
        // so this is a defensive path rather than an expected one.
        if (! $campaign || ! $campaign->template) {
            ar_log()->error('SendSingleCampaignEmail: campaign or template missing', [
                'send_log_id' => $sendLog->id,
                'campaign_id' => $sendLog->campaign_id,
            ]);

            $sendLog->markAsFailed('Campaign or template no longer available.');
            $campaign?->increment('failed_count');
            $this->checkCampaignCompletion($sendLog->campaign_id);

            return;
        }

        $template = $campaign->template;
        $subscriber = $this->resolveSubscriber($sendLog);

        $subject = $tokenService->replaceTokens($campaign->subject ?: $template->subject, $subscriber);
        $body = $tokenService->replaceTokens($template->body_html ?? $template->body, $subscriber);
        $body = $this->parseMarkdownToHtml($body);

        $language = $template->locale
            ?? $sendLog->language
            ?? $subscriber->getSubscribableLocale()
            ?? $campaign->default_locale
            ?? 'en';

        $body = $this->addTrackingPixel($body, $sendLog->id);
        $body = $this->wrapLinksForTracking($body, $sendLog);
        $body = $this->addUnsubscribeLink($body, $sendLog, $language);

        $sendLog->update([
            'language' => $language,
            'subject' => $subject,
            'body_html' => $body,
        ]);

        try {
            Mail::to($sendLog->email)->send(new AutoresponderMail($subject, $body));

            $sendLog->markAsSent();
            $campaign->increment('sent_count');

            $this->checkCampaignCompletion($campaign->id);

            ar_log()->info('Campaign email sent', [
                'campaign_id' => $campaign->id,
                'email' => $sendLog->email,
                'send_log_id' => $sendLog->id,
            ]);
        } catch (\Exception $e) {
            $message = Str::limit($e->getMessage(), 500);

            ar_log()->warning('Campaign email send failed', [
                'campaign_id' => $campaign->id,
                'email' => $sendLog->email,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Leave the row pending while retries remain, so the campaign is not
            // reported complete before this recipient has actually finished.
            if ($this->attempts() >= $this->tries) {
                $sendLog->markAsFailed($message);
                $campaign->increment('failed_count');
                $this->checkCampaignCompletion($campaign->id);
            } else {
                $sendLog->update(['error_message' => $message]);
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $sendLog = SendLog::find($this->sendLogId);

        ar_log()->error('SendSingleCampaignEmail job permanently failed', [
            'send_log_id' => $this->sendLogId,
            'error' => $exception->getMessage(),
        ]);

        // Only act if handle() did not already close this row out — otherwise the
        // failed_count would be incremented twice for one recipient.
        if (! $sendLog || $sendLog->status !== 'pending') {
            return;
        }

        $sendLog->markAsFailed(Str::limit($exception->getMessage(), 500));
        Campaign::where('id', $sendLog->campaign_id)->increment('failed_count');

        $this->checkCampaignCompletion($sendLog->campaign_id);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Close the campaign out once no pending rows remain for it.
     *
     * Sound because CampaignService pre-creates every row before the first send
     * job runs: a zero pending count means finished, not "not started yet".
     * Counts are recomputed from the log rows rather than trusted from the
     * running increments, so a lost increment self-heals.
     */
    protected function checkCampaignCompletion(?int $campaignId): void
    {
        if (! $campaignId) {
            return;
        }

        $pending = SendLog::where('campaign_id', $campaignId)
            ->where('status', 'pending')
            ->count();

        if ($pending > 0) {
            return;
        }

        $sent = SendLog::where('campaign_id', $campaignId)->where('status', 'sent')->count();
        $failed = SendLog::where('campaign_id', $campaignId)->where('status', 'failed')->count();

        $status = $sent > 0 ? 'sent' : 'failed';

        // Conditional update so only the first worker to observe completion
        // transitions the campaign. Must stay a single statement.
        $closed = Campaign::query()
            ->where('id', $campaignId)
            ->where('status', 'sending')
            ->update([
                'status' => $status,
                'sent_at' => now(),
                'finished_at' => now(),
                'sent_count' => $sent,
                'failed_count' => $failed,
                'updated_at' => now(),
            ]);

        if ($closed > 0) {
            ar_log()->info('Campaign completed', [
                'campaign_id' => $campaignId,
                'status' => $status,
                'sent' => $sent,
                'failed' => $failed,
            ]);
        }
    }

    protected function resolveSubscriber(SendLog $sendLog): Subscribable
    {
        if ($sendLog->subscriber_id) {
            $modelClass = ar_subscriber_model();
            $subscriber = $modelClass::find($sendLog->subscriber_id);

            if ($subscriber instanceof Subscribable) {
                return $subscriber;
            }
        }

        $email = $sendLog->email;
        $locale = $sendLog->language;

        return new class ($email, $locale) implements Subscribable
        {
            public function __construct(
                private readonly string $email,
                private readonly ?string $locale,
            ) {}

            public function getSubscribableId(): int|string
            {
                return 0;
            }

            public function getSubscribableEmail(): string
            {
                return $this->email;
            }

            public function getSubscribableName(): string
            {
                return 'Subscriber';
            }

            public function getSubscribableLocale(): ?string
            {
                return $this->locale;
            }
        };
    }
}
