<?php

namespace ColorrageAR\Autoresponder\Commands;

use ColorrageAR\Autoresponder\Contracts\TriggerHandler;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Services\AutoresponderService;
use Illuminate\Console\Command;

use function ColorrageAR\Autoresponder\ar_log;

class CheckTriggers extends Command
{
    protected $signature = 'autoresponder:check-triggers
        {--trigger= : Specific trigger type to process}
        {--dry-run : Preview without enrolling subscribers}';

    protected $description = 'Check and process time-based autoresponder triggers';

    private const DEFAULT_TRIGGERS = [
        'subscription_expiring',
        'subscription_renewal',
        'date_based',
    ];

    public function handle(AutoresponderService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $specificTrigger = $this->option('trigger');

        $triggerTypes = $specificTrigger
            ? [$specificTrigger]
            : self::DEFAULT_TRIGGERS;

        $handlers = config('autoresponder.trigger_handlers', []);
        $totalEnrolled = 0;
        $totalSkipped = 0;

        foreach ($triggerTypes as $triggerType) {
            $this->info("Processing trigger: {$triggerType}");

            $sequences = Sequence::active()
                ->byTrigger($triggerType)
                ->orderByPriority()
                ->get();

            if ($sequences->isEmpty()) {
                $this->line("  No active sequences for trigger '{$triggerType}'.");

                continue;
            }

            $handlerClass = $handlers[$triggerType] ?? null;

            if (! $handlerClass) {
                $this->warn("  No TriggerHandler registered for '{$triggerType}'. Skipping.");

                continue;
            }

            if (! class_exists($handlerClass)) {
                $this->error("  TriggerHandler class '{$handlerClass}' not found. Skipping.");

                continue;
            }

            $handler = app($handlerClass);

            if (! $handler instanceof TriggerHandler) {
                $this->error("  '{$handlerClass}' does not implement TriggerHandler. Skipping.");

                continue;
            }

            foreach ($sequences as $sequence) {
                $this->line("  Sequence: {$sequence->name} (#{$sequence->id})");

                $subscribers = $handler->getSubscribers($sequence);

                if ($subscribers->isEmpty()) {
                    $this->line("    No matching subscribers.");

                    continue;
                }

                $enrolled = 0;
                $skipped = 0;

                foreach ($subscribers as $subscriber) {
                    if ($dryRun) {
                        \Illuminate\Support\Facades\DB::beginTransaction();
                        $enrollment = $service->enrollInSequence($subscriber, $sequence);
                        \Illuminate\Support\Facades\DB::rollBack();

                        $email = method_exists($subscriber, 'getSubscribableEmail')
                            ? $subscriber->getSubscribableEmail()
                            : ($subscriber->email ?? '?');

                        if ($enrollment) {
                            $this->line("    [DRY-RUN] Would enroll: {$email}");
                            $enrolled++;
                        } else {
                            $this->line("    [DRY-RUN] Would skip: {$email} (already enrolled/unsubscribed)");
                            $skipped++;
                        }

                        continue;
                    }

                    $enrollment = $service->enrollInSequence($subscriber, $sequence);

                    if ($enrollment) {
                        $enrolled++;
                    } else {
                        $skipped++;
                    }
                }

                $action = $dryRun ? 'Would enroll' : 'Enrolled';
                $this->line("    {$action}: {$enrolled}, Skipped (already enrolled/unsubscribed): {$skipped}");

                $totalEnrolled += $enrolled;
                $totalSkipped += $skipped;
            }
        }

        $this->newLine();
        $action = $dryRun ? 'Would have enrolled' : 'Total enrolled';
        $this->info("{$action}: {$totalEnrolled}, Total skipped: {$totalSkipped}");

        ar_log()->info('CheckTriggers completed', [
            'triggers_checked' => $triggerTypes,
            'enrolled' => $totalEnrolled,
            'skipped' => $totalSkipped,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
