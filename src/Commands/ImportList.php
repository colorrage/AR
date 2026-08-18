<?php

namespace ColorrageAR\Autoresponder\Commands;

use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Services\ListImportService;
use ColorrageAR\Autoresponder\Support\ListImportReport;
use ColorrageAR\Autoresponder\Support\RejectedRow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportList extends Command
{
    protected $signature = 'autoresponder:import-list
        {file : Path to the CSV file}
        {--list= : Id of an existing mailer list to import into}
        {--create= : Name for a new manual mailer list to create}
        {--email-column= : CSV header holding the email address}
        {--name-column= : CSV header holding the name}
        {--locale-column= : CSV header holding the locale}
        {--delimiter= : Field delimiter (defaults to config)}
        {--source= : Provenance label stored on each row (defaults to the file basename)}
        {--dry-run : Parse and report without writing anything}';

    protected $description = 'Import subscribers into a mailer list from a CSV file';

    public function handle(ListImportService $service): int
    {
        $file = (string) $this->argument('file');
        $listId = $this->option('list');
        $createName = $this->option('create');
        $dryRun = (bool) $this->option('dry-run');

        if (($listId === null) === ($createName === null)) {
            $this->error('Provide exactly one of --list=<id> or --create="<name>".');

            return self::FAILURE;
        }

        $columns = [
            'email' => $this->option('email-column') ?: config('autoresponder.import.columns.email', 'email'),
            'name' => $this->option('name-column') ?: config('autoresponder.import.columns.name', 'name'),
            'locale' => $this->option('locale-column') ?: config('autoresponder.import.columns.locale', 'locale'),
        ];

        $delimiter = $this->option('delimiter') ?: config('autoresponder.import.delimiter', ',');
        $source = $this->option('source') ?: null;

        try {
            $report = $this->runImport($service, $file, $listId, $createName, $columns, $delimiter, $source, $dryRun);
        } catch (Throwable $e) {
            // Import-level failure: the import could not run at all.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->render($report);

        // Rejected rows are a normal outcome, not a command failure. Only an import that
        // could not run returns FAILURE.
        return self::SUCCESS;
    }

    /**
     * @param  array<string, string|null>  $columns
     */
    private function runImport(
        ListImportService $service,
        string $file,
        ?string $listId,
        ?string $createName,
        array $columns,
        string $delimiter,
        ?string $source,
        bool $dryRun,
    ): ListImportReport {
        $import = function () use ($service, $file, $listId, $createName, $columns, $delimiter, $source, $dryRun) {
            $list = $listId !== null
                ? $service->findList((int) $listId)
                : $service->createManualList((string) $createName);

            $report = $service->importCsv($file, $list, $columns, $delimiter, $source, $dryRun);

            $this->announceTarget($list, $dryRun);

            return $report;
        };

        if (! $dryRun || $listId !== null) {
            return $import();
        }

        // --dry-run with --create: the list creation itself must not persist either. The
        // service's own dry-run transaction nests as a savepoint inside this one, so the
        // outer rollback discards both the list and its rows.
        DB::beginTransaction();

        try {
            return $import();
        } finally {
            DB::rollBack();
        }
    }

    private function announceTarget(MailerList $list, bool $dryRun): void
    {
        $target = $dryRun
            ? "list \"{$list->name}\" (dry run — nothing was written)"
            : "list \"{$list->name}\" [id {$list->id}]";

        $this->info("Imported into {$target}");
    }

    private function render(ListImportReport $report): void
    {
        if ($report->isDryRun()) {
            $this->warn('DRY RUN — no changes were saved. Re-run without --dry-run to apply.');
        }

        $this->info("Source: {$report->source()}");

        $this->table(
            ['Rows read', 'Accepted', 'Duplicate', 'Suppressed', 'Invalid'],
            [[
                $report->rowsRead(),
                $report->accepted(),
                $report->duplicate(),
                $report->suppressed(),
                $report->invalid(),
            ]],
        );

        if ($report->reconciles()) {
            $this->info('Reconciled: every row read is accounted for in exactly one column above.');
        } else {
            // Should be unreachable. If it ever fires, rows were lost and that must be loud.
            $this->error('DOES NOT RECONCILE: the counts above do not sum to the rows read. Rows were lost.');
        }

        $this->renderRejected($report);
    }

    private function renderRejected(ListImportReport $report): void
    {
        if ($report->rejectedCount() === 0) {
            return;
        }

        $this->newLine();
        $this->warn("{$report->rejectedCount()} row(s) were rejected or skipped:");

        $this->table(
            ['Line', 'Row', 'Reason', 'Detail'],
            array_map(static fn (RejectedRow $row): array => [
                $row->line,
                $row->ordinal,
                $row->reason,
                $row->message,
            ], $report->rejected()),
        );

        if ($report->rejectedTruncated()) {
            // Never let a truncated list read as a complete one.
            $this->warn(sprintf(
                'Detail above is truncated — only the first %d of %d rejected rows are listed. '
                . 'Raise autoresponder.import.max_rejected_rows to see more.',
                count($report->rejected()),
                $report->rejectedCount(),
            ));
        }
    }
}
