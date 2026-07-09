<?php

namespace ColorrageAR\Autoresponder\Concerns;

use ColorrageAR\Autoresponder\Models\SendLog;
use Illuminate\Support\Str;

trait TracksEmailEngagement
{
    /**
     * Append a 1x1 tracking pixel to the email body.
     */
    protected function addTrackingPixel(string $body, int $logId): string
    {
        $pixelUrl = route('autoresponder.track.open', ['id' => $logId]);
        $pixel = '<img src="' . $pixelUrl . '" width="1" height="1" alt="" style="display:block" />';

        return $body . $pixel;
    }

    /**
     * Wrap all anchor hrefs with the click tracking route.
     */
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

    /**
     * Append the unsubscribe footer to the email body.
     */
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

    /**
     * Get unsubscribe link text for the given language.
     */
    protected function getUnsubscribeText(string $language): array
    {
        $translations = config('autoresponder.unsubscribe_translations', []);

        return $translations[$language]
            ?? $translations['en']
            ?? ['main' => 'To unsubscribe', 'link' => 'click here'];
    }

    /**
     * Convert markdown to HTML, falling back to nl2br for plain text.
     */
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
