<?php

namespace ColorrageAR\Autoresponder\Commands;

use Carbon\Carbon;
use ColorrageAR\Autoresponder\Jobs\SendScheduledCampaign;
use ColorrageAR\Autoresponder\Models\Campaign;
use Illuminate\Console\Command;

use function ColorrageAR\Autoresponder\ar_log;

class ProcessScheduledCampaigns extends Command
{
    protected $signature = 'autoresponder:process-scheduled-campaigns
        {--dry-run : Preview without dispatching jobs}';

    protected $description = 'Process scheduled campaigns that are ready to send';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $campaigns = Campaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', Carbon::now())
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info('No scheduled campaigns ready to send.');

            return self::SUCCESS;
        }

        $this->info("Found {$campaigns->count()} campaign(s) ready to send.");

        $dispatched = 0;

        foreach ($campaigns as $campaign) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Would dispatch campaign #{$campaign->id}: {$campaign->name}");
            } else {
                SendScheduledCampaign::dispatch($campaign->id);
                $this->line("  Dispatched campaign #{$campaign->id}: {$campaign->name}");
            }

            $dispatched++;
        }

        $action = $dryRun ? 'Would dispatch' : 'Dispatched';
        $this->info("{$action} {$dispatched} campaign(s).");

        ar_log()->info('ProcessScheduledCampaigns completed', [
            'dispatched' => $dispatched,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
