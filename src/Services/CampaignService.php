<?php

namespace ColorrageAR\Autoresponder\Services;

use Carbon\Carbon;
use ColorrageAR\Autoresponder\Contracts\Subscribable;
use ColorrageAR\Autoresponder\Jobs\SendCampaignBatch;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function ColorrageAR\Autoresponder\ar_log;

class CampaignService
{
    /**
     * Rows inserted per bulk statement when pre-creating send logs.
     */
    protected const INSERT_CHUNK = 500;

    public function __construct(
        protected ListService $listService,
    ) {}

    /**
     * Prepare and dispatch a campaign.
     *
     * Every send log row is created here, before any send job runs. That is what
     * makes `total_recipients` knowable and what makes the `pending == 0`
     * completion test in SendSingleCampaignEmail sound.
     *
     * @return int Number of recipients the campaign was prepared for.
     *
     * @throws \RuntimeException When the campaign has no template, no recipients,
     *                           or exceeds the configured recipient ceiling.
     */
    public function sendCampaign(Campaign $campaign): int
    {
        // A campaign without a template can never render. Fail the campaign itself
        // rather than dropping recipients one by one inside the send job.
        if (! $campaign->template_id) {
            $campaign->markAsFailed('Campaign has no template assigned.');

            throw new \RuntimeException('Campaign has no template assigned');
        }

        // Resolve and validate before claiming, so a campaign that cannot be sent
        // yet is left in its current state and stays retryable once corrected.
        $recipients = $this->resolveRecipients(
            $campaign->filter_type,
            $campaign->filter_params ?? [],
        );

        if ($recipients->isEmpty()) {
            throw new \RuntimeException('No recipients found for this campaign');
        }

        $maxEmails = config('autoresponder.rate_limit.max_emails_per_campaign', 3000);

        if ($recipients->count() > $maxEmails) {
            throw new \RuntimeException("Campaign exceeds maximum of {$maxEmails} recipients");
        }

        // Claim, pre-create and count in one transaction. All three must land together:
        // a partial row set under a claimed campaign can never be completed correctly
        // and can never be retried, because `sending` is not a claimable state.
        $created = DB::transaction(function () use ($campaign, $recipients): ?int {
            // Atomic claim. Only one caller can move a campaign into `sending`, which is
            // what prevents a concurrent second call from inserting a duplicate row set.
            // This must stay a single conditional statement — see 04-execution-plan.md.
            // Inside the transaction it also takes a row lock, so a competing claim
            // blocks until commit and then correctly sees `sending` and fails.
            $claimed = Campaign::query()
                ->whereKey($campaign->getKey())
                ->whereIn('status', ['draft', 'scheduled'])
                ->update([
                    'status' => 'sending',
                    'started_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($claimed === 0) {
                return null;
            }

            $campaign->refresh();

            $count = $this->preCreateSendLogs($campaign, $recipients);

            $campaign->update(['total_recipients' => $count]);

            return $count;
        });

        if ($created === null) {
            ar_log()->info('Campaign already claimed by another dispatch', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->fresh()?->status,
            ]);

            return 0;
        }

        ar_log()->info('Campaign prepared for sending', [
            'campaign_id' => $campaign->id,
            'recipients' => $created,
        ]);

        // Dispatched after commit, so a worker can never pick the campaign up before
        // its send log rows are visible.
        SendCampaignBatch::dispatch($campaign->id);

        return $created;
    }

    /**
     * Resolve the recipient collection for a filter type and its parameters.
     *
     * `filter_type` lives on the campaigns table, not inside `filter_params`;
     * both are passed explicitly so a missing key cannot silently resolve to
     * an empty recipient set.
     *
     * Supported filter types:
     *  - mailer_lists   → delegates to ListService
     *  - manual_emails  → splits a comma string and wraps each in an anonymous Subscribable
     *
     * @return Collection<int, Subscribable>
     */
    public function resolveRecipients(?string $filterType, array $params = []): Collection
    {
        $recipients = match ($filterType) {
            'mailer_lists' => $this->resolveFromMailerLists($params['selected_lists'] ?? []),
            'manual_emails' => $this->resolveManualEmails($params['manual_emails'] ?? ''),
            default => collect(),
        };

        if (($params['ignore_unsubscribed'] ?? true) === true) {
            $recipients = $this->filterUnsubscribed($recipients);
        }

        // One row per address, whatever the source. Guards acceptance proof 3
        // against duplicates inside a manual list or across merged mailer lists.
        return $recipients
            ->unique(fn (Subscribable $s) => Str::lower($s->getSubscribableEmail()))
            ->values();
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
            'status' => 'scheduled',
            'scheduled_at' => $sendAt,
        ]);

        ar_log()->info('Campaign scheduled', [
            'campaign_id' => $campaign->id,
            'scheduled_at' => $sendAt->toDateTimeString(),
        ]);
    }

    /**
     * Cancel a campaign (scheduled or sending).
     */
    public function cancelCampaign(Campaign $campaign): void
    {
        $campaign->update([
            'status' => 'cancelled',
            'scheduled_at' => null,
        ]);

        ar_log()->info('Campaign cancelled', [
            'campaign_id' => $campaign->id,
        ]);
    }

    // ── Send Log Pre-creation ─────────────────────────────────────────

    /**
     * Insert one pending send log per recipient.
     *
     * Uses a bulk insert, which bypasses model events — so `unsubscribe_token`
     * (normally set by SendLog::booted) and the timestamps are written here
     * explicitly. Dropping any of them would leave rows that cannot be
     * unsubscribed from.
     *
     * @param  Collection<int, Subscribable>  $recipients
     * @return int Rows inserted.
     */
    protected function preCreateSendLogs(Campaign $campaign, Collection $recipients): int
    {
        $now = Carbon::now();
        $inserted = 0;

        foreach ($recipients->chunk(self::INSERT_CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $recipient) {
                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'autoresponder_id' => null,
                    'autoresponder_step_id' => null,
                    'subscriber_id' => $this->subscriberKey($recipient),
                    'email' => $recipient->getSubscribableEmail(),
                    'language' => $recipient->getSubscribableLocale(),
                    'status' => 'pending',
                    'unsubscribe_token' => Str::random(64),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            SendLog::insert($rows);
            $inserted += count($rows);
        }

        return $inserted;
    }

    /**
     * Normalize a subscribable id to a storable foreign key.
     *
     * The anonymous wrappers used for addresses with no host model return 0;
     * that is not a real key and must be stored as null.
     */
    protected function subscriberKey(Subscribable $recipient): ?int
    {
        $id = $recipient->getSubscribableId();

        if (is_numeric($id) && (int) $id > 0) {
            return (int) $id;
        }

        return null;
    }

    // ── Internal Resolvers ────────────────────────────────────────────

    protected function resolveFromMailerLists(array $listIds): Collection
    {
        if (empty($listIds)) {
            return collect();
        }

        $subscribers = collect();

        foreach ($listIds as $listId) {
            $list = MailerList::find($listId);

            if ($list) {
                $subscribers = $subscribers->merge($this->listService->getSubscribers($list));
            }
        }

        return $subscribers;
    }

    protected function resolveManualEmails(string $emailsCsv): Collection
    {
        if (empty($emailsCsv)) {
            return collect();
        }

        $emails = array_filter(array_map('trim', explode(',', $emailsCsv)));

        return collect($emails)->map(function (string $email) {
            return new class ($email) implements Subscribable {
                public function __construct(private readonly string $email) {}

                public function getSubscribableId(): int|string
                {
                    return 0;
                }

                public function getSubscribableEmail(): string
                {
                    return $this->email;
                }

                public function getSubscribableName(): string
                {
                    return $this->email;
                }

                public function getSubscribableLocale(): ?string
                {
                    return null;
                }
            };
        });
    }

    /**
     * Drop recipients present in the global suppression list.
     *
     * Matched case-insensitively on both sides. Mailbox providers treat addresses
     * that way in practice, and a suppression that fails to match is the expensive
     * direction of the error — it mails someone who asked not to be mailed.
     */
    protected function filterUnsubscribed(Collection $recipients): Collection
    {
        if ($recipients->isEmpty()) {
            return $recipients;
        }

        $lowered = $recipients
            ->map(fn (Subscribable $s) => Str::lower($s->getSubscribableEmail()))
            ->unique()
            ->values();

        $unsubscribed = collect();

        // Chunked so the bound-parameter count stays well inside every driver's
        // limit (SQLite's is the tightest) on a max-size campaign.
        foreach ($lowered->chunk(self::INSERT_CHUNK) as $chunk) {
            $values = $chunk->values()->all();
            $placeholders = implode(',', array_fill(0, count($values), '?'));

            $found = Unsubscribe::query()
                ->whereRaw("LOWER(email) IN ({$placeholders})", $values)
                ->pluck('email');

            $unsubscribed = $unsubscribed->merge($found);
        }

        $suppressed = $unsubscribed->map(fn ($email) => Str::lower($email))->flip();

        return $recipients->reject(
            fn (Subscribable $s) => $suppressed->has(Str::lower($s->getSubscribableEmail()))
        )->values();
    }
}
