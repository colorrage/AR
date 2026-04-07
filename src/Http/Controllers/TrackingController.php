<?php

namespace ColorrageAR\Autoresponder\Http\Controllers;

use ColorrageAR\Autoresponder\Models\SendLog;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TrackingController extends Controller
{
    private const TRANSPARENT_GIF = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function trackOpen(int $id): Response
    {
        try {
            $sendLog = SendLog::find($id);

            if ($sendLog) {
                $sendLog->increment('opens_count');

                if (! $sendLog->opened_at) {
                    $sendLog->update(['opened_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Autoresponder open tracking failed', [
                'send_log_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        return new Response(self::TRANSPARENT_GIF, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => strlen(self::TRANSPARENT_GIF),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function trackClick(int $id, string $url): RedirectResponse
    {
        try {
            $decodedUrl = base64_decode($url, true);

            if ($decodedUrl === false) {
                $decodedUrl = config('app.url', '/');
            }

            if (! $this->isSafeRedirectUrl($decodedUrl)) {
                Log::warning('Autoresponder click tracking blocked unsafe redirect', [
                    'send_log_id' => $id,
                    'url' => $decodedUrl,
                ]);

                return new RedirectResponse(config('app.url', '/'), 302);
            }

            $sendLog = SendLog::find($id);

            if ($sendLog) {
                $sendLog->increment('clicks_count');

                if (! $sendLog->clicked_at) {
                    $sendLog->update(['clicked_at' => now()]);
                }
            }

            return new RedirectResponse($decodedUrl, 302);
        } catch (\Throwable $e) {
            Log::warning('Autoresponder click tracking failed', [
                'send_log_id' => $id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return new RedirectResponse(config('app.url', '/'), 302);
        }
    }

    /**
     * Validate that a URL is safe to redirect to.
     */
    private function isSafeRedirectUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $allowedDomains = config('autoresponder.allowed_redirect_domains', []);

        if (! empty($allowedDomains)) {
            $host = parse_url($url, PHP_URL_HOST);
            return in_array($host, $allowedDomains, true);
        }

        return true;
    }
}
