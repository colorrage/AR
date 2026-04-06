<?php

namespace CmrManagement\Autoresponder\Services;

use CmrManagement\Autoresponder\Contracts\Subscribable;
use CmrManagement\Autoresponder\Contracts\TokenResolver;

class TokenService
{
    /**
     * Replace every token family in the content string.
     *
     * Token syntax: ##namespace.key##
     * Button syntax: ##button|Text|URL|style##
     */
    public function replaceTokens(string $content, Subscribable $subscriber): string
    {
        $content = $this->replaceButtonTokens($content);
        $content = $this->replaceSubscriberTokens($content, $subscriber);
        $content = $this->replaceConfigTokens($content);
        $content = $this->replaceCustomTokens($content, $subscriber);

        return $content;
    }

    /**
     * Replace ##subscriber.*## tokens using the configurable attribute map.
     */
    public function replaceSubscriberTokens(string $content, Subscribable $subscriber): string
    {
        $map = config('autoresponder.tokens.subscriber', []);

        foreach ($map as $token => $attribute) {
            $placeholder = "##subscriber.{$token}##";

            if (str_contains($content, $placeholder)) {
                $value = $this->resolveSubscriberAttribute($subscriber, $attribute);
                $content = str_replace($placeholder, $value, $content);
            }
        }

        return $content;
    }

    /**
     * Replace ##config.*## tokens with Laravel config() values.
     */
    public function replaceConfigTokens(string $content): string
    {
        return preg_replace_callback(
            '/##config\.([\w.]+)##/',
            fn (array $m) => (string) config($m[1], ''),
            $content,
        );
    }

    /**
     * Replace ##button|Text|URL|style## tokens with email-safe HTML buttons.
     */
    public function replaceButtonTokens(string $content): string
    {
        return preg_replace_callback(
            '/##button\|([^|]+)\|([^|]+)(?:\|([^#]+))?##/',
            function (array $m) {
                $text  = $m[1];
                $url   = $m[2];
                $style = trim($m[3] ?? 'primary');

                $colors = [
                    'primary'   => '#667eea',
                    'secondary' => '#6b7280',
                    'success'   => '#10b981',
                    'danger'    => '#ef4444',
                    'warning'   => '#f59e0b',
                    'info'      => '#3b82f6',
                ];

                $bg = $colors[$style] ?? $colors['primary'];

                return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:20px 0;">
<tr><td align="center">
<a href="{$url}" style="display:inline-block;padding:14px 36px;background:{$bg};color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
{$text}
</a>
</td></tr>
</table>
HTML;
            },
            $content,
        );
    }

    /**
     * Replace custom tokens via the host-provided TokenResolver.
     */
    public function replaceCustomTokens(string $content, Subscribable $subscriber): string
    {
        $resolverClass = config('autoresponder.custom_token_resolver');

        if (! $resolverClass || ! class_exists($resolverClass)) {
            return $content;
        }

        /** @var TokenResolver $resolver */
        $resolver = app($resolverClass);

        return preg_replace_callback(
            '/##custom\.([\w.]+)##/',
            function (array $m) use ($resolver, $subscriber) {
                $resolved = $resolver->resolve($m[1], $subscriber);
                return $resolved ?? $m[0]; // keep original if unresolved
            },
            $content,
        );
    }

    /**
     * Return a categorised list of all available tokens for UI display.
     */
    public function getAvailableTokens(): array
    {
        $subscriberTokens = [];
        foreach (config('autoresponder.tokens.subscriber', []) as $token => $attribute) {
            $subscriberTokens["##subscriber.{$token}##"] = "Subscriber {$token} ({$attribute})";
        }

        $configTokens = [];
        foreach (config('autoresponder.tokens.config', []) as $key => $label) {
            $configTokens["##config.{$key}##"] = $label;
        }

        $tokens = [
            'Subscriber' => $subscriberTokens,
            'Configuration' => $configTokens,
            'Buttons & Components' => [
                '##button|Text|URL|style##' => 'Button (styles: primary, secondary, success, danger, warning, info)',
            ],
        ];

        if (config('autoresponder.custom_token_resolver')) {
            $tokens['Custom'] = [
                '##custom.<key>##' => 'Resolved by custom TokenResolver',
            ];
        }

        return $tokens;
    }

    /**
     * Find tokens in content that cannot be resolved.
     *
     * @return string[] List of invalid token strings (e.g. ##subscriber.foo##)
     */
    public function validateTokens(string $content): array
    {
        preg_match_all('/##([\w.]+)##/', $content, $matches);

        $usedTokens = $matches[1] ?? [];

        $validSubscriberKeys = array_keys(config('autoresponder.tokens.subscriber', []));

        $invalid = [];

        foreach ($usedTokens as $token) {
            if (str_starts_with($token, 'config.')) {
                continue; // config tokens are dynamic — always valid
            }

            if (str_starts_with($token, 'custom.')) {
                continue; // delegated to resolver — assume valid
            }

            if (str_starts_with($token, 'subscriber.')) {
                $key = substr($token, strlen('subscriber.'));
                if (! in_array($key, $validSubscriberKeys, true)) {
                    $invalid[] = "##{$token}##";
                }
                continue;
            }

            // Unknown namespace
            $invalid[] = "##{$token}##";
        }

        return $invalid;
    }

    // ── Internal ──────────────────────────────────────────────────────

    protected function resolveSubscriberAttribute(Subscribable $subscriber, string $attribute): string
    {
        // Try dedicated getters first
        return match ($attribute) {
            'email'  => $subscriber->getSubscribableEmail(),
            'name'   => $subscriber->getSubscribableName(),
            'locale' => $subscriber->getSubscribableLocale() ?? '',
            'id'     => (string) $subscriber->getSubscribableId(),
            default  => $this->readArbitraryAttribute($subscriber, $attribute),
        };
    }

    protected function readArbitraryAttribute(Subscribable $subscriber, string $attribute): string
    {
        if ($subscriber instanceof \Illuminate\Database\Eloquent\Model) {
            return (string) ($subscriber->{$attribute} ?? '');
        }

        if (method_exists($subscriber, 'toArray')) {
            $data = $subscriber->toArray();
            return (string) ($data[$attribute] ?? '');
        }

        return '';
    }
}
