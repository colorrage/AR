<?php

namespace ColorrageAR\Autoresponder;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use ColorrageAR\Autoresponder\Services\AutoresponderService;
use ColorrageAR\Autoresponder\Services\CampaignService;
use ColorrageAR\Autoresponder\Services\TokenService;
use ColorrageAR\Autoresponder\Services\ListService;
use ColorrageAR\Autoresponder\Services\AnalyticsService;
use ColorrageAR\Autoresponder\Commands\ProcessEnrollments;
use ColorrageAR\Autoresponder\Commands\CheckTriggers;
use ColorrageAR\Autoresponder\Commands\ProcessScheduledCampaigns;
use ColorrageAR\Autoresponder\Commands\ImportTemplates;
use ColorrageAR\Autoresponder\Listeners\TriggerListener;

class AutoresponderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/autoresponder.php', 'autoresponder');

        if (file_exists(__DIR__ . '/helpers.php')) {
            require_once __DIR__ . '/helpers.php';
        }

        $this->app->singleton(AutoresponderService::class);
        $this->app->singleton(CampaignService::class);
        $this->app->singleton(TokenService::class);
        $this->app->singleton(ListService::class);
        $this->app->singleton(AnalyticsService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'autoresponder');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessEnrollments::class,
                CheckTriggers::class,
                ProcessScheduledCampaigns::class,
                ImportTemplates::class,
                \ColorrageAR\Autoresponder\Commands\InstallUICommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/autoresponder.php' => config_path('autoresponder.php'),
            ], 'autoresponder-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/autoresponder'),
            ], 'autoresponder-views');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'autoresponder-migrations');
        }

        Event::subscribe(TriggerListener::class);
    }
}
