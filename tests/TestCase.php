<?php

namespace ColorrageAR\Autoresponder\Tests;

use ColorrageAR\Autoresponder\AutoresponderServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\Attributes\DefineDatabaseMigrations;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            AutoresponderServiceProvider::class,
        ];
    }

    #[DefineEnvironment]
    protected function defineTestingEnvironment($app): void
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
        $migrationsPath = __DIR__ . '/../database/migrations';
        $this->loadMigrationsFrom($migrationsPath);
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Legacy setup for backward compat with older Testbench
        $this->defineTestingEnvironment($app);
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
    }
}
