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
        public int $campaignId,
        public string $email,
        public ?int $subscriberId = null,
    ) {
        $this->onQueue(ar_queue());
        $this->tries = config('autoresponder.retry.max_attempts', 3);
        $this->backoff = config('autoresponder.retry.backoff', [60, 300, 900]);
    }

    public function handle(TokenService $tokenService): void
    {
        $campaign = Campaign::with('template')->find($this->campaignId);

        if (! $campaign || ! $campaign->template) {
            ar_log()->warning('SendSingleCampaignEmail: campaign or template not found', [
                'campaign_id' => $this->campaignId,
                'email' => $this->email,
            ]);

            return;
        }

        $template = $campaign->template;
        $subscriber = $this->resolveSubscriber();

        $subject = $tokenService->replaceTokens($campaign->subject ?? $template->subject, $subscriber);
        $body = $tokenService->replaceTokens($template->body_html, $subscriber);

        $body = $this->parseMarkdownToHtml($body);

        $language = $template->locale
            ?? $subscriber->getSubscribableLocale()
            ?? $campaign->default_locale
            ?? 'en';

        $sendLog = SendLog::create([
            'campaign_id' => $campaign->id,
            'autoresponder_id' => null,
            'autoresponder_step_id' => null,
            'subscriber_id' => $this->subscriberId,
            'email' => $this->email,
            'language' => $language,
            'subject' => $subject,
            'body_html' => $body,
            'status' => 'pending',
            'unsubscribe_token' => bin2hex(random_bytes(32)),
        ]);

        $body = $this->addTrackingPixel($body, $sendLog->id);
        $body = $this->wrapLinksForTracking($body, $sendLog);
        $body = $this->addUnsubscribeLink($body, $sendLog, $language);

        $sendLog->update(['body_html' => $body]);

        try {
            Mail::to($this->email)->send(new AutoresponderMail($subject, $body));

            $sendLog->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $this->checkCampaignCompletion($campaign->id);

            ar_log()->info('Campaign email sent', [
                'campaign_id' => $campaign->id,
                'email' => $this->email,
                'send_log_id' => $sendLog->id,
            ]);
        } catch (\Exception $e) {
            $sendLog->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 500),
            ]);

            ar_log()->warning('Campaign email send failed', [
                'campaign_id' => $campaign->id,
                'email' => $this->email,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        ar_log()->error('SendSingleCampaignEmail job permanently failed', [
            'campaign_id' => $this->campaignId,
            'email' => $this->email,
            'error' => $exception->getMessage(),
        ]);

        $this->checkCampaignCompletion($this->campaignId);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Check if all campaign emails have been processed and update status.
     */
    protected function checkCampaignCompletion(int $campaignId): void
    {
        $pendingCount = SendLog::where('campaign_id', $campaignId)
            ->where('status', 'pending')
            ->count();

        if ($pendingCount === 0) {
            $sentCount = SendLog::where('campaign_id', $campaignId)
                ->where('status', 'sent')
                ->count();

            $failedCount = SendLog::where('campaign_id', $campaignId)
                ->where('status', 'failed')
                ->count();

            $status = $failedCount > 0 && $sentCount === 0 ? 'failed' : 'sent';

            Campaign::where('id', $campaignId)
                ->where('status', 'sending')
                ->update([
                    'status' => $status,
                    'sent_at' => now(),
                    'sent_count' => $sentCount,
                    'failed_count' => $failedCount,
                ]);

            ar_log()->info('Campaign completed', [
                'campaign_id' => $campaignId,
                'status' => $status,
                'sent' => $sentCount,
                'failed' => $failedCount,
            ]);
        }
    }

    protected function resolveSubscriber(): Subscribable
    {
        if ($this->subscriberId) {
            $modelClass = ar_subscriber_model();
            $subscriber = $modelClass::find($this->subscriberId);

            if ($subscriber instanceof Subscribable) {
                return $subscriber;
            }
        }

        $email = $this->email;

        return new class($email) implements Subscribable
        {
            public function __construct(private readonly string $email) {}

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
                return null;
            }
        };
    }

    protected function wrapLinksForTracking(string $body, SendLog $sendLog): string
    {
        return preg_replace_callback(
            '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
            function ($matches) use ($sendLog) {
                $originalUrl = $matches[2];

                $skipPatterns = ['unsubscribe', 'track/open', 'track/click', 'mailto:', 'tel:', '#'];
                foreach ($skipPatterns as $pattern) {
                    if (str_contains($originalUrl, $pattern) || $originalUrl === $pattern) {
                        return $matches[0];
                    }
                }

                $trackingUrl = route('autoresponder.track.click', [
                    'id' => $sendLog->id,
                    'url' => base64_encode($originalUrl),
                ]);

                return "<a {$matches[1]}href=\"{$trackingUrl}\"{$matches[3]}>";
            },
            $body
        );
    }

    protected function addUnsubscribeLink(string $body, SendLog $sendLog, string $language): string
    {
        $unsubscribeUrl = route('autoresponder.unsubscribe', ['token' => $sendLog->unsubscribe_token]);
        $translations = config('autoresponder.unsubscribe_translations', []);
        $text = $translations[$language] ?? $translations['en'] ?? ['main' => 'To unsubscribe', 'link' => 'click here'];

        $footer = <<<HTML
            <div style="margin-top: 32px; padding-top: 24px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 12px; color: #6b7280;">
                <p>{$text['main']} <a href="{$unsubscribeUrl}" style="color: #667eea; text-decoration: underline;">{$text['link']}</a>.</p>
            </div>
            HTML;

        return $body . $footer;
    }

    protected function parseMarkdownToHtml(string $content): string
    {
        if (str_contains($content, '<html') || str_contains($content, '<!DOCTYPE')) {
            return $content;
        }

        $hasMarkdown = preg_match('/\*\*[^*]+\*\*|\[[^\]]+\]\([^)]+\)|^#+\s|^-\s|\n\n/m', $content);

        if (! $hasMarkdown) {
            return nl2br($content);
        }

        return Str::markdown($content, [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
    }
}
