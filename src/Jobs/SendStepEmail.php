<?php

namespace CmrManagement\Autoresponder\Jobs;

use CmrManagement\Autoresponder\Contracts\Subscribable;
use CmrManagement\Autoresponder\Mail\AutoresponderMail;
use CmrManagement\Autoresponder\Models\Enrollment;
use CmrManagement\Autoresponder\Models\SendLog;
use CmrManagement\Autoresponder\Models\Step;
use CmrManagement\Autoresponder\Models\StepLog;
use CmrManagement\Autoresponder\Models\Template;
use CmrManagement\Autoresponder\Services\AutoresponderService;
use CmrManagement\Autoresponder\Services\TokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

use function CmrManagement\Autoresponder\ar_log;
use function CmrManagement\Autoresponder\ar_queue;

class SendStepEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public array $backoff;

    public int $timeout = 60;

    public function __construct(
        public int $enrollmentId,
        public int $stepId,
    ) {
        $this->onQueue(ar_queue());
        $this->tries = config('autoresponder.retry.max_attempts', 3);
        $this->backoff = config('autoresponder.retry.backoff', [60, 300, 900]);
    }

    public function handle(AutoresponderService $autoresponderService, TokenService $tokenService): void
    {
        $enrollment = Enrollment::find($this->enrollmentId);
        $step = Step::find($this->stepId);

        if (! $enrollment || ! $step) {
            ar_log()->warning('SendStepEmail: enrollment or step not found', [
                'enrollment_id' => $this->enrollmentId,
                'step_id' => $this->stepId,
            ]);

            return;
        }

        $sequence = $enrollment->sequence;

        if (! $autoresponderService->shouldSendStep($step, $enrollment)) {
            $this->createSkippedLog($enrollment, $step, 'Condition not met or unsubscribed');
            $autoresponderService->scheduleNextStep($enrollment, $step);

            return;
        }

        try {
            $variant = $autoresponderService->selectAbVariant($step, $enrollment);
            $template = $step->getTemplateForVariant($variant);

            if (! $template) {
                throw new \RuntimeException("Template not found for step {$step->id}, variant {$variant}");
            }

            $subscriber = $this->resolveSubscriber($enrollment);

            $subject = $step->subject_override ?? $template->subject;
            $subject = $tokenService->replaceTokens($subject, $subscriber);
            $body = $tokenService->replaceTokens($template->body_html, $subscriber);

            $body = $this->parseMarkdownToHtml($body);

            $language = $template->locale
                ?? $subscriber->getSubscribableLocale()
                ?? $sequence->default_locale
                ?? 'en';

            $sendLog = SendLog::create([
                'campaign_id' => null,
                'autoresponder_id' => $sequence->id,
                'autoresponder_step_id' => $step->id,
                'subscriber_id' => $enrollment->subscriber_id,
                'email' => $enrollment->email,
                'language' => $language,
                'subject' => $subject,
                'body_html' => $body,
                'status' => 'pending',
                'unsubscribe_token' => bin2hex(random_bytes(32)),
            ]);

            $body = $this->addTrackingPixel($body, $sendLog->id);
            $body = $this->wrapLinksForTracking($body, $sendLog, $sequence);

            if ($sequence->enable_utm_tracking) {
                $utmParams = $sequence->getUtmParameters();
                if (! empty($utmParams)) {
                    $body = preg_replace_callback(
                        '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
                        function ($matches) use ($utmParams) {
                            $url = $matches[2];
                            if (str_contains($url, 'track/click') || str_contains($url, 'mailto:') || str_contains($url, 'tel:')) {
                                return $matches[0];
                            }

                            return "<a {$matches[1]}href=\"" . $this->appendUtmToUrl($url, $utmParams) . "\"{$matches[3]}>";
                        },
                        $body
                    );
                }
            }

            $body = $this->addUnsubscribeLink($body, $sendLog, $language);

            $sendLog->update(['body_html' => $body]);

            Mail::to($enrollment->email)->send(new AutoresponderMail($subject, $body));

            $sendLog->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            StepLog::create([
                'enrollment_id' => $enrollment->id,
                'sequence_id' => $sequence->id,
                'step_id' => $step->id,
                'send_log_id' => $sendLog->id,
                'status' => 'sent',
                'scheduled_at' => now(),
                'sent_at' => now(),
                'ab_variant_used' => $variant,
            ]);

            $enrollment->advance($step->step_number);

            ar_log()->info('Autoresponder step email sent', [
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'step_number' => $step->step_number,
                'email' => $enrollment->email,
                'variant' => $variant,
            ]);

            $autoresponderService->scheduleNextStep($enrollment, $step);
        } catch (\Exception $e) {
            $this->handleSendError($enrollment, $step, $e);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        ar_log()->error('SendStepEmail job permanently failed', [
            'enrollment_id' => $this->enrollmentId,
            'step_id' => $this->stepId,
            'error' => $exception->getMessage(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    protected function resolveSubscriber(Enrollment $enrollment): Subscribable
    {
        $subscriber = $enrollment->subscriber;

        if ($subscriber instanceof Subscribable) {
            return $subscriber;
        }

        $email = $enrollment->email;
        $name = $enrollment->trigger_data['name'] ?? 'Subscriber';

        return new class($email, $name) implements Subscribable
        {
            public function __construct(
                private readonly string $email,
                private readonly string $name,
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
                return $this->name;
            }

            public function getSubscribableLocale(): ?string
            {
                return null;
            }
        };
    }

    protected function addTrackingPixel(string $body, int $logId): string
    {
        $pixelUrl = route('autoresponder.track.open', ['id' => $logId]);
        $pixel = '<img src="' . $pixelUrl . '" width="1" height="1" alt="" style="display:block" />';

        return $body . $pixel;
    }

    protected function wrapLinksForTracking(string $body, SendLog $sendLog, $sequence): string
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

    protected function appendUtmToUrl(string $url, array $utmParams): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }

        $existing = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $existing);
        }

        $merged = array_merge($existing, $utmParams);

        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = ! empty($merged) ? '?' . http_build_query($merged) : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $scheme . $host . $port . $path . $query . $fragment;
    }

    protected function addUnsubscribeLink(string $body, SendLog $sendLog, string $language): string
    {
        $unsubscribeUrl = route('autoresponder.unsubscribe', ['token' => $sendLog->unsubscribe_token]);
        $text = $this->getUnsubscribeText($language);

        $footer = <<<HTML
            <div style="margin-top: 32px; padding-top: 24px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 12px; color: #6b7280;">
                <p>{$text['main']} <a href="{$unsubscribeUrl}" style="color: #667eea; text-decoration: underline;">{$text['link']}</a>.</p>
            </div>
            HTML;

        return $body . $footer;
    }

    protected function getUnsubscribeText(string $language): array
    {
        $translations = config('autoresponder.unsubscribe_translations', []);

        return $translations[$language]
            ?? $translations['en']
            ?? ['main' => 'To unsubscribe', 'link' => 'click here'];
    }

    protected function createSkippedLog(Enrollment $enrollment, Step $step, string $reason): void
    {
        StepLog::create([
            'enrollment_id' => $enrollment->id,
            'sequence_id' => $enrollment->sequence_id,
            'step_id' => $step->id,
            'send_log_id' => null,
            'status' => 'skipped',
            'scheduled_at' => now(),
            'skip_reason' => $reason,
        ]);

        ar_log()->debug('Step skipped', [
            'enrollment_id' => $enrollment->id,
            'step_id' => $step->id,
            'reason' => $reason,
        ]);
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
            'allow_unsafe_links' => true,
        ]);
    }

    protected function handleSendError(Enrollment $enrollment, Step $step, \Exception $e): void
    {
        ar_log()->warning('Autoresponder step email send failed', [
            'enrollment_id' => $enrollment->id,
            'step_id' => $step->id,
            'attempt' => $this->attempts(),
            'error' => $e->getMessage(),
        ]);

        if ($this->attempts() >= $this->tries) {
            StepLog::create([
                'enrollment_id' => $enrollment->id,
                'sequence_id' => $enrollment->sequence_id,
                'step_id' => $step->id,
                'send_log_id' => null,
                'status' => 'failed',
                'scheduled_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            ar_log()->error('Autoresponder step email permanently failed', [
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
