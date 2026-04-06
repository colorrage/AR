<?php

namespace CmrManagement\Autoresponder;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use CmrManagement\Autoresponder\Services\AutoresponderService;
use CmrManagement\Autoresponder\Services\CampaignService;
use CmrManagement\Autoresponder\Services\TokenService;
use CmrManagement\Autoresponder\Services\ListService;
use CmrManagement\Autoresponder\Services\AnalyticsService;
use CmrManagement\Autoresponder\Commands\ProcessEnrollments;
use CmrManagement\Autoresponder\Commands\CheckTriggers;
use CmrManagement\Autoresponder\Commands\ProcessScheduledCampaigns;
use CmrManagement\Autoresponder\Commands\ImportTemplates;
use CmrManagement\Autoresponder\Listeners\TriggerListener;

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
                \CmrManagement\Autoresponder\Commands\InstallUICommand::class,
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
