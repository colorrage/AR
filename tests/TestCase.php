<?php

namespace ColorrageAR\Autoresponder\Tests;

use ColorrageAR\Autoresponder\AutoresponderServiceProvider;
use ColorrageAR\Autoresponder\Tests\Support\CampaignFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            AutoresponderServiceProvider::class,
        ];
    }

    /**
     * Testbench's environment hook.
     *
     * Must be named `defineEnvironment` (or `getEnvironmentSetUp`) — Testbench
     * discovers it by name. The previous code named it `defineTestingEnvironment`
     * and decorated it with a bare `#[DefineEnvironment]` attribute, which is not
     * how that attribute works: it belongs on a test method or class and names the
     * setup method to call. The attribute was therefore inert, and the
     * `getEnvironmentSetUp()` shim labelled "legacy" was the only hook applying any
     * of this configuration.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:owrHT0vKEG3OIhFvTQ1c45y6av6abAyxNvFtOjKBq98=');
        $app['config']->set('app.url', 'http://localhost');

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        $app['config']->set('mail.default', 'array');

        $app['config']->set('queue.default', 'sync');

        $app['config']->set('autoresponder.table_prefix', 'ar_');
        $app['config']->set('autoresponder.route_prefix', 'autoresponder');
        $app['config']->set('autoresponder.route_middleware', ['web']);
        $app['config']->set('autoresponder.queue', 'emails');
        $app['config']->set('autoresponder.quiet_hours_timezone', 'UTC');
        $app['config']->set('autoresponder.subscriber_model', 'App\\Models\\User');
        $app['config']->set('autoresponder.subscriber_columns.key', 'id');
        $app['config']->set('autoresponder.subscriber_columns.email', 'email');
        $app['config']->set('autoresponder.subscriber_columns.name', 'name');
        $app['config']->set('autoresponder.subscriber_columns.locale', null);
        $app['config']->set('autoresponder.rate_limit.max_emails_per_campaign', 3000);
        $app['config']->set('autoresponder.rate_limit.emails_per_batch', 2);
        $app['config']->set('autoresponder.rate_limit.batch_delay_seconds', 30);
        $app['config']->set('autoresponder.retry.max_attempts', 3);
        $app['config']->set('autoresponder.retry.backoff', [60, 300, 900]);
        $app['config']->set('autoresponder.tokens.subscriber.name', 'name');
        $app['config']->set('autoresponder.tokens.subscriber.email', 'email');
        $app['config']->set('autoresponder.tokens.config', ['app.name' => 'Test App', 'app.url' => 'http://localhost']);
        $app['config']->set('autoresponder.languages', ['en' => ['name' => 'English', 'flag' => 'gb']]);
        $app['config']->set('autoresponder.unsubscribe_translations', [
            'en' => ['main' => 'Unsubscribe text', 'link' => 'click here'],
        ]);
        $app['config']->set('autoresponder.default_locale', 'en');
    }

    protected function defineDatabaseMigrations(): void
    {
        // A host `users` table first, so the configured subscriber_model can actually
        // be created and the host-model recipient branch is testable — which is why
        // tests/Support/App/Models/User.php implements Subscribable yet was referenced
        // by nothing. Testbench's loadLaravelMigrations() cannot be used: it shells out
        // to artisan on a second connection, which never sees SQLite :memory:.
        $this->loadMigrationsFrom(__DIR__ . '/Support/migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }
}
