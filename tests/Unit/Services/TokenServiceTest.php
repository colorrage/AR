<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Services;

use ColorrageAR\Autoresponder\Services\TokenService;
use ColorrageAR\Autoresponder\Tests\Support\SubscriberMocker;
use ColorrageAR\Autoresponder\Tests\TestCase;

class TokenServiceTest extends TestCase
{
    private TokenService $tokenService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenService = app(TokenService::class);
    }

    public function test_replace_subscriber_name_token(): void
    {
        $subscriber = SubscriberMocker::default();
        $content = 'Hello ##subscriber.name##!';

        $result = $this->tokenService->replaceTokens($content, $subscriber);

        $this->assertStringContainsString('Hello Test User!', $result);
        $this->assertStringNotContainsString('##subscriber.name##', $result);
    }

    public function test_replace_subscriber_email_token(): void
    {
        $subscriber = SubscriberMocker::default();
        $content = 'Email: ##subscriber.email##';

        $result = $this->tokenService->replaceTokens($content, $subscriber);

        $this->assertStringContainsString('test@example.com', $result);
        $this->assertStringNotContainsString('##subscriber.email##', $result);
    }

    public function test_replace_config_tokens(): void
    {
        config(['app.name' => 'Test App']);
        config(['app.url' => 'http://localhost']);

        $subscriber = SubscriberMocker::default();
        $content = 'App: ##config.app.name## at ##config.app.url##';

        $result = $this->tokenService->replaceTokens($content, $subscriber);

        $this->assertStringContainsString('Test App', $result);
        $this->assertStringContainsString('http://localhost', $result);
        $this->assertStringNotContainsString('##config.', $result);
    }

    public function test_replace_button_token_primary(): void
    {
        $subscriber = SubscriberMocker::default();
        $content = '##button|Click Me|https://example.com|primary##';

        $result = $this->tokenService->replaceTokens($content, $subscriber);

        $this->assertStringContainsString('#667eea', $result);
        $this->assertStringContainsString('Click Me', $result);
        $this->assertStringContainsString('https://example.com', $result);
        $this->assertStringNotContainsString('##button', $result);
    }

    public function test_replace_button_token_danger(): void
    {
        $subscriber = SubscriberMocker::default();
        $content = '##button|Unsubscribe|https://example.com/unsub|danger##';

        $result = $this->tokenService->replaceTokens($content, $subscriber);

        $this->assertStringContainsString('#ef4444', $result);
        $this->assertStringContainsString('Unsubscribe', $result);
    }

    public function test_get_available_tokens(): void
    {
        $tokens = $this->tokenService->getAvailableTokens();

        $this->assertArrayHasKey('Subscriber', $tokens);
        $this->assertArrayHasKey('Configuration', $tokens);
        $this->assertArrayHasKey('Buttons & Components', $tokens);
        $this->assertNotEmpty($tokens['Subscriber']);
    }

    public function test_validate_tokens_returns_invalid_for_unknown(): void
    {
        $invalid = $this->tokenService->validateTokens('Hello ##subscriber.unknown_field##');

        $this->assertNotEmpty($invalid);
        $this->assertContains('##subscriber.unknown_field##', $invalid);
    }

    public function test_validate_tokens_allows_config_tokens(): void
    {
        $invalid = $this->tokenService->validateTokens('Site: ##config.app.name##');

        $this->assertEmpty($invalid);
    }

    public function test_validate_tokens_allows_custom_tokens(): void
    {
        $invalid = $this->tokenService->validateTokens('Custom: ##custom.my_field##');

        $this->assertEmpty($invalid);
    }

    public function test_unknown_token_namespace_is_invalid(): void
    {
        $invalid = $this->tokenService->validateTokens('Value: ##bad.namespace##');

        $this->assertNotEmpty($invalid);
        $this->assertContains('##bad.namespace##', $invalid);
    }

    public function test_replace_tokens_with_null_locale_subscriber(): void
    {
        $subscriber = (new SubscriberMocker)->withLocale(null)->make();
        $content = 'Hello ##subscriber.name##';

        $result = $this->tokenService->replaceTokens($content, $subscriber);

        $this->assertStringContainsString('Hello Test User', $result);
    }
}
