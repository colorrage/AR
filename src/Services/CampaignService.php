<?php

namespace ColorrageAR\Autoresponder\Services;

use Carbon\Carbon;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use Illuminate\Support\Collection;

use function ColorrageAR\Autoresponder\ar_log;
use function ColorrageAR\Autoresponder\ar_queue;

class CampaignService
{
    public function __construct(
        protected ListService $listService,
    ) {}

    /**
     * Send a campaign: mark as sending, resolve recipients, dispatch batch jobs.
     */
    public function sendCampaign(Campaign $campaign): void
    {
        $recipients = $this->resolveRecipients($campaign->filter_params ?? []);

        if ($recipients->isEmpty()) {
            throw new \RuntimeException('No recipients found for this campaign');
        }

        $maxEmails = config('autoresponder.rate_limit.max_emails_per_campaign', 3000);
        if ($recipients->count() > $maxEmails) {
            throw new \RuntimeException("Campaign exceeds maximum of {$maxEmails} recipients");
        }

        $campaign->update([
            'status'           => 'sending',
            'total_recipients' => $recipients->count(),
            'started_at'       => now(),
        ]);

        ar_log()->info('Campaign sending initiated', [
            'campaign_id' => $campaign->id,
            'recipients'  => $recipients->count(),
        ]);

        SendCampaignBatch::dispatch($campaign->id)->onQueue(ar_queue());
    }

    /**
     * Resolve the recipient collection based on filter parameters.
     *
     * Supported filter_type values:
     *  - mailer_lists   → delegates to ListService
     *  - manual_emails  → splits a comma string and wraps each in an anonymous Subscribable
     *  - (extensible via host app override)
     *
     * Returns a collection of Subscribable instances.
     */
    public function resolveRecipients(array $params): Collection
    {
        $filterType = $params['filter_type'] ?? null;

        $recipients = match ($filterType) {
            'mailer_lists'  => $this->resolveFromMailerLists($params['selected_lists'] ?? []),
            'manual_emails' => $this->resolveManualEmails($params['manual_emails'] ?? ''),
            default         => collect(),
        };

        // Exclude unsubscribed
        $ignoreUnsubscribed = $params['ignore_unsubscribed'] ?? true;
        if ($ignoreUnsubscribed) {
            $recipients = $this->filterUnsubscribed($recipients);
        }

        return $recipients;
    }

    /**
     * Schedule a campaign for future delivery.
     */
    public function scheduleCampaign(Campaign $campaign, Carbon $sendAt): void
    {
        if ($sendAt->isPast()) {
            throw new \InvalidArgumentException('sendAt must be in the future');
        }

        $campaign->update([
            'status'       => 'scheduled',
            'scheduled_at' => $sendAt,
        ]);

        ar_log()->info('Campaign scheduled', [
            'campaign_id'  => $campaign->id,
            'scheduled_at' => $sendAt->toDateTimeString(),
        ]);
    }

    /**
     * Cancel a campaign (scheduled or sending).
     */
    public function cancelCampaign(Campaign $campaign): void
    {
        $campaign->update([
            'status'       => 'cancelled',
            'scheduled_at' => null,
        ]);

        ar_log()->info('Campaign cancelled', [
            'campaign_id' => $campaign->id,
        ]);
    }

    // ── Internal Resolvers ────────────────────────────────────────────

    protected function resolveFromMailerLists(array $listIds): Collection
    {
        if (empty($listIds)) {
            return collect();
        }

        $subscribers = collect();
        foreach ($listIds as $listId) {
            $list = \ColorrageAR\Autoresponder\Models\MailerList::find($listId);
            if ($list) {
                $subscribers = $subscribers->merge($this->listService->getSubscribers($list));
            }
        }

        return $subscribers->unique(fn ($s) => $s->getSubscribableEmail());
    }

    protected function resolveManualEmails(string $emailsCsv): Collection
    {
        if (empty($emailsCsv)) {
            return collect();
        }

        $emails = array_filter(array_map('trim', explode(',', $emailsCsv)));

        return collect($emails)->map(function (string $email) {
            return new class ($email) implements \ColorrageAR\Autoresponder\Contracts\Subscribable {
                public function __construct(private readonly string $email) {}
                public function getSubscribableId(): int|string { return 0; }
                public function getSubscribableEmail(): string { return $this->email; }
                public function getSubscribableName(): string { return $this->email; }
                public function getSubscribableLocale(): ?string { return null; }
            };
        });
    }

    protected function filterUnsubscribed(Collection $recipients): Collection
    {
        if ($recipients->isEmpty()) {
            return $recipients;
        }

        $emails = $recipients->map(fn ($s) => $s->getSubscribableEmail())->all();

        $unsubscribed = Unsubscribe::whereIn('email', $emails)
            ->pluck('email')
            ->flip();

        return $recipients->reject(
            fn ($s) => $unsubscribed->has($s->getSubscribableEmail())
        )->values();
    }
}
