<?php

namespace ColorrageAR\Autoresponder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallUICommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autoresponder:install-ui {--type= : The UI type (filament or blade)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the autoresponder UI scaffolding into your application';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');

        if (!in_array($type, ['filament', 'blade'])) {
            $type = $this->choice('Which UI stack do you prefer for the Autoresponder interface?', ['filament', 'blade'], 0);
        }

        $this->info("Installing Autoresponder scaffolding for: {$type}");

        if ($type === 'filament') {
            $this->installFilamentStubs();
        } else {
            $this->installBladeStubs();
        }

        $this->info('UI Scaffolding installed successfully.');
        return self::SUCCESS;
    }

    protected function installFilamentStubs(): void
    {
        $stubsPath = __DIR__ . '/../../stubs/filament';
        $targetPath = app_path('Filament/Resources');

        if (!File::isDirectory($stubsPath)) {
            $this->warn("No Filament stubs found in package. Creating empty directory for future use.");
            File::ensureDirectoryExists($stubsPath);
            return;
        }

        File::ensureDirectoryExists($targetPath);
        File::copyDirectory($stubsPath, $targetPath);
        $this->info("Copied Filament resources to {$targetPath}");

        // Also copy the views used by Filament (email-preview, campaign-report)
        $viewsStubPath = __DIR__ . '/../../stubs/views';
        $viewsTargetPath = resource_path('views/vendor/autoresponder');

        if (File::isDirectory($viewsStubPath)) {
            File::ensureDirectoryExists($viewsTargetPath);
            File::copyDirectory($viewsStubPath, $viewsTargetPath);
            $this->info("Copied supporting views to {$viewsTargetPath}");
        }
    }

    protected function installBladeStubs(): void
    {
        // Copy Controllers
        $controllersStubPath = __DIR__ . '/../../stubs/blade/Controllers';
        $controllersTargetPath = app_path('Http/Controllers/Autoresponder');

        if (File::isDirectory($controllersStubPath)) {
            File::ensureDirectoryExists($controllersTargetPath);
            File::copyDirectory($controllersStubPath, $controllersTargetPath);
            $this->info("Copied Blade controllers to {$controllersTargetPath}");
        }

        // Copy Views
        $viewsStubPath = __DIR__ . '/../../stubs/blade/views';
        $viewsTargetPath = resource_path('views/vendor/autoresponder');

        if (!File::isDirectory($viewsStubPath) && !File::isDirectory($controllersStubPath)) {
            $this->warn("No Blade stubs found in package. Extracted directories will be created for future additions.");
            File::ensureDirectoryExists($viewsStubPath);
            File::ensureDirectoryExists($controllersStubPath);
            return;
        }

        if (File::isDirectory($viewsStubPath)) {
            File::ensureDirectoryExists($viewsTargetPath);
            File::copyDirectory($viewsStubPath, $viewsTargetPath);
            $this->info("Copied Blade views to {$viewsTargetPath}");
        }
    }
}
