<?php

namespace CmrManagement\Autoresponder\Commands;

use CmrManagement\Autoresponder\Models\Template;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function CmrManagement\Autoresponder\ar_log;

class ImportTemplates extends Command
{
    protected $signature = 'autoresponder:import-templates
        {directory : Path to directory containing .html template files}
        {--locale=en : Locale code for imported templates}';

    protected $description = 'Import email templates from HTML files in a directory';

    public function handle(): int
    {
        $directory = $this->argument('directory');
        $locale = $this->option('locale');

        if (! File::isDirectory($directory)) {
            $this->error("Directory not found: {$directory}");

            return self::FAILURE;
        }

        $files = File::glob($directory . '/*.html');

        if (empty($files)) {
            $this->warn("No .html files found in: {$directory}");

            return self::SUCCESS;
        }

        $this->info("Found " . count($files) . " template file(s) in {$directory}");

        $imported = 0;

        foreach ($files as $filePath) {
            $filename = pathinfo($filePath, PATHINFO_FILENAME);
            $bodyHtml = File::get($filePath);

            $template = Template::create([
                'name' => $filename,
                'body_html' => $bodyHtml,
                'locale' => $locale,
            ]);

            $this->line("  Imported: {$filename} (#{$template->id}, locale: {$locale})");
            $imported++;
        }

        $this->info("Imported {$imported} template(s).");

        ar_log()->info('ImportTemplates completed', [
            'directory' => $directory,
            'locale' => $locale,
            'imported' => $imported,
        ]);

        return self::SUCCESS;
    }
}
