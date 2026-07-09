<?php

namespace ColorrageAR\Autoresponder\Services;

use Carbon\Carbon;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\StepLog;

class AnalyticsService
{
    /**
     * Aggregate stats for an entire autoresponder sequence.
     */
    public function getSequenceStats(Sequence $sequence): array
    {
        $enrollments = $sequence->enrollments();

        $totalEnrollments  = $enrollments->count();
        $activeEnrollments = (clone $enrollments)->where('state', 'active')->count();
        $completed         = (clone $enrollments)->where('state', 'completed')->count();

        $completionRate = $totalEnrollments > 0
            ? round(($completed / $totalEnrollments) * 100, 2)
            : 0.0;

        $sendLogs = $sequence->sendLogs();
        $sentCount = (clone $sendLogs)->where('status', 'sent')->count();

        $openRate  = $this->calculateRate($sendLogs, 'opened_at', $sentCount);
        $clickRate = $this->calculateRate($sendLogs, 'clicked_at', $sentCount);

        return [
            'total_enrollments'  => $totalEnrollments,
            'active_enrollments' => $activeEnrollments,
            'completed'          => $completed,
            'completion_rate'    => $completionRate,
            'total_sent'         => $sentCount,
            'open_rate'          => $openRate,
            'click_rate'         => $clickRate,
        ];
    }

    /**
     * Stats for a single step: sent / skipped / failed counts plus engagement rates.
     */
    public function getStepStats(Step $step): array
    {
        $logs = StepLog::where('step_id', $step->id);

        $sent    = (clone $logs)->where('status', 'sent')->count();
        $skipped = (clone $logs)->where('status', 'skipped')->count();
        $failed  = (clone $logs)->where('status', 'failed')->count();

        // Engagement via the related send logs — single JOIN query
        $opened = StepLog::where('step_id', $step->id)
            ->where('status', 'sent')
            ->whereHas('sendLog', fn ($q) => $q->whereNotNull('opened_at'))
            ->count();

        $clicked = StepLog::where('step_id', $step->id)
            ->where('status', 'sent')
            ->whereHas('sendLog', fn ($q) => $q->whereNotNull('clicked_at'))
            ->count();

        $openRate  = $sent > 0 ? round(($opened / $sent) * 100, 2) : 0.0;
        $clickRate = $sent > 0 ? round(($clicked / $sent) * 100, 2) : 0.0;

        return [
            'sent'       => $sent,
            'skipped'    => $skipped,
            'failed'     => $failed,
            'total'      => $sent + $skipped + $failed,
            'open_rate'  => $openRate,
            'click_rate' => $clickRate,
        ];
    }

    /**
     * Campaign-level stats: recipients, sent, failed, open/click rates.
     */
    public function getCampaignStats(Campaign $campaign): array
    {
        $logs = SendLog::where('campaign_id', $campaign->id);

        $total   = (clone $logs)->count();
        $sent    = (clone $logs)->where('status', 'sent')->count();
        $failed  = (clone $logs)->where('status', 'failed')->count();
        $pending = $total - $sent - $failed;

        $opened  = (clone $logs)->where('status', 'sent')->whereNotNull('opened_at')->count();
        $clicked = (clone $logs)->where('status', 'sent')->whereNotNull('clicked_at')->count();

        $openRate  = $sent > 0 ? round(($opened / $sent) * 100, 2) : 0.0;
        $clickRate = $sent > 0 ? round(($clicked / $sent) * 100, 2) : 0.0;

        return [
            'total_recipients' => $total,
            'sent'             => $sent,
            'failed'           => $failed,
            'pending'          => $pending,
            'open_rate'        => $openRate,
            'click_rate'       => $clickRate,
        ];
    }

    /**
     * Compare A/B variant performance for a step that has ab_test_enabled.
     *
     * @return array|null Null if the step has no A/B testing.
     */
    public function getAbTestResults(Step $step): ?array
    {
        if (! $step->ab_test_enabled) {
            return null;
        }

        $results = [];

        foreach (['A', 'B'] as $variant) {
            $logs = StepLog::where('step_id', $step->id)
                ->where('ab_variant_used', $variant);

            $sent = (clone $logs)->where('status', 'sent')->count();

            $sendLogIds = (clone $logs)->where('status', 'sent')
                ->whereNotNull('send_log_id')
                ->pluck('send_log_id');

            $opened  = 0;
            $clicked = 0;

            if ($sendLogIds->isNotEmpty()) {
                $opened  = SendLog::whereIn('id', $sendLogIds)->whereNotNull('opened_at')->count();
                $clicked = SendLog::whereIn('id', $sendLogIds)->whereNotNull('clicked_at')->count();
            }

            $results[$variant] = [
                'sent'       => $sent,
                'open_rate'  => $sent > 0 ? round(($opened / $sent) * 100, 2) : 0.0,
                'click_rate' => $sent > 0 ? round(($clicked / $sent) * 100, 2) : 0.0,
            ];
        }

        // Statistical winner (simple comparison)
        $winnerMetric = 'click_rate';
        $winner = null;
        if ($results['A'][$winnerMetric] !== $results['B'][$winnerMetric]) {
            $winner = $results['A'][$winnerMetric] > $results['B'][$winnerMetric] ? 'A' : 'B';
        }

        return [
            'variants' => $results,
            'winner'   => $winner,
        ];
    }

    /**
     * High-level overview stats across all sequences and campaigns.
     */
    public function getOverviewStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->subDays(30);
        $to   ??= now();

        $totalSent = SendLog::where('status', 'sent')
            ->whereBetween('sent_at', [$from, $to])
            ->count();

        $totalOpened = SendLog::where('status', 'sent')
            ->whereBetween('sent_at', [$from, $to])
            ->whereNotNull('opened_at')
            ->count();

        $totalClicked = SendLog::where('status', 'sent')
            ->whereBetween('sent_at', [$from, $to])
            ->whereNotNull('clicked_at')
            ->count();

        $activeSequences = Sequence::active()->count();

        $activeEnrollments = Enrollment::where('state', 'active')->count();

        $activeCampaigns = Campaign::whereIn('status', ['sending', 'scheduled'])->count();

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
            ],
            'total_sent'          => $totalSent,
            'total_opened'        => $totalOpened,
            'total_clicked'       => $totalClicked,
            'open_rate'           => $totalSent > 0 ? round(($totalOpened / $totalSent) * 100, 2) : 0.0,
            'click_rate'          => $totalSent > 0 ? round(($totalClicked / $totalSent) * 100, 2) : 0.0,
            'active_sequences'    => $activeSequences,
            'active_enrollments'  => $activeEnrollments,
            'active_campaigns'    => $activeCampaigns,
        ];
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * Calculate percentage rate for a nullable timestamp column on sent logs.
     */
    protected function calculateRate(
        \Illuminate\Database\Eloquent\Relations\HasMany|\Illuminate\Database\Eloquent\Builder $query,
        string $timestampColumn,
        int $sentCount,
    ): float {
        if ($sentCount === 0) {
            return 0.0;
        }

        $count = (clone $query)
            ->where('status', 'sent')
            ->whereNotNull($timestampColumn)
            ->count();

        return round(($count / $sentCount) * 100, 2);
    }
}
