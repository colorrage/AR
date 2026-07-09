<?php

namespace ColorrageAR\Autoresponder\Commands;

use Carbon\Carbon;
use ColorrageAR\Autoresponder\Jobs\ProcessEnrollment;
use ColorrageAR\Autoresponder\Models\Enrollment;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function ColorrageAR\Autoresponder\ar_log;
use function ColorrageAR\Autoresponder\ar_table;

class ProcessEnrollments extends Command
{
    protected $signature = 'autoresponder:process-enrollments
        {--limit=100 : Maximum enrollments to claim per run}
        {--dry-run : Preview without dispatching jobs}
        {--release-stale=15 : Release claims older than N minutes}';

    protected $description = 'Process autoresponder enrollments ready to send (atomic claiming)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $staleMinutes = (int) $this->option('release-stale');

        $released = $this->releaseStale($staleMinutes);

        if ($released > 0) {
            $this->info("Released {$released} stale claim(s).");
        }

        $token = (string) Str::uuid();
        $claimed = $this->claimEnrollments($token, $limit);

        if ($claimed === 0) {
            $this->info('No enrollments ready to process.');

            return self::SUCCESS;
        }

        $this->info("Claimed {$claimed} enrollment(s).");

        $enrollments = Enrollment::where('processing_token', $token)
            ->with('sequence')
            ->get();

        $dispatched = 0;
        $skipped = 0;

        foreach ($enrollments as $enrollment) {
            if (! $enrollment->sequence || ! $enrollment->sequence->isActive()) {
                $enrollment->update([
                    'processing_token' => null,
                    'processing_started_at' => null,
                ]);
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would dispatch enrollment #{$enrollment->id} (sequence: {$enrollment->sequence->name})");
            } else {
                ProcessEnrollment::dispatch($enrollment->id);
            }

            $dispatched++;
        }

        $action = $dryRun ? 'Would dispatch' : 'Dispatched';
        $this->info("{$action} {$dispatched} job(s), skipped {$skipped}.");

        ar_log()->info('ProcessEnrollments completed', [
            'claimed' => $claimed,
            'dispatched' => $dispatched,
            'skipped' => $skipped,
            'released_stale' => $released,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }

    private function releaseStale(int $minutes): int
    {
        return Enrollment::query()
            ->whereNotNull('processing_token')
            ->where('processing_started_at', '<', Carbon::now()->subMinutes($minutes))
            ->update([
                'processing_token' => null,
                'processing_started_at' => null,
            ]);
    }

    private function claimEnrollments(string $token, int $limit): int
    {
        $table = ar_table('enrollments');
        $sequencesTable = ar_table('sequences');

        return Enrollment::query()
            ->where("{$table}.state", 'active')
            ->where("{$table}.next_run_at", '<=', Carbon::now())
            ->whereNull("{$table}.processing_token")
            ->whereExists(function ($query) use ($sequencesTable, $table) {
                $query->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from($sequencesTable)
                    ->whereColumn("{$sequencesTable}.id", "{$table}.sequence_id")
                    ->where("{$sequencesTable}.status", 'active');
            })
            ->limit($limit)
            ->update([
                'processing_token' => $token,
                'processing_started_at' => Carbon::now(),
            ]);
    }
}
