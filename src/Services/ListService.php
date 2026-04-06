<?php

namespace CmrManagement\Autoresponder\Services;

use CmrManagement\Autoresponder\Contracts\Subscribable;
use CmrManagement\Autoresponder\Models\ListSubscriber;
use CmrManagement\Autoresponder\Models\MailerList;
use Illuminate\Support\Collection;

use function CmrManagement\Autoresponder\ar_log;
use function CmrManagement\Autoresponder\ar_subscriber_model;
use function CmrManagement\Autoresponder\ar_subscriber_key;

class ListService
{
    /**
     * Return all active Subscribable instances for a mailer list.
     *
     * Static lists: directly enrolled ListSubscriber rows (status = subscribed).
     * Dynamic lists: resolved from the subscriber model via filter_config.
     */
    public function getSubscribers(MailerList $list): Collection
    {
        if ($list->type === 'dynamic') {
            return $this->resolveDynamicSubscribers($list);
        }

        return $this->resolveStaticSubscribers($list);
    }

    /**
     * Add a subscriber to a static mailer list (upsert).
     */
    public function addSubscriber(
        MailerList $list,
        string $email,
        ?string $name = null,
        ?int $subscriberId = null,
        ?string $locale = null,
    ): ListSubscriber {
        $existing = ListSubscriber::where('list_id', $list->id)
            ->where('email', $email)
            ->first();

        if ($existing) {
            $existing->update(array_filter([
                'name'          => $name,
                'subscriber_id' => $subscriberId,
                'locale'        => $locale,
                'status'        => 'subscribed',
                'unsubscribed_at' => null,
            ], fn ($v) => $v !== null));

            return $existing->fresh();
        }

        return ListSubscriber::create([
            'list_id'       => $list->id,
            'subscriber_id' => $subscriberId,
            'email'         => $email,
            'name'          => $name,
            'locale'        => $locale,
            'status'        => 'subscribed',
            'subscribed_at' => now(),
        ]);
    }

    /**
     * Remove (unsubscribe) a subscriber from a list by email.
     */
    public function removeSubscriber(MailerList $list, string $email): void
    {
        ListSubscriber::where('list_id', $list->id)
            ->where('email', $email)
            ->update([
                'status'          => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);
    }

    /**
     * Synchronise a dynamic list's subscribers from the host subscriber model.
     *
     * The list's `filter_config` is expected to be an array of column => value
     * conditions applied to the subscriber model's query builder.
     *
     * @return int Number of subscribers synced.
     */
    public function syncFromModel(MailerList $list): int
    {
        if ($list->type !== 'dynamic') {
            return 0;
        }

        $modelClass = ar_subscriber_model();
        $filters    = $list->filter_config ?? [];

        $query = $modelClass::query();

        foreach ($filters as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        $emailCol = config('autoresponder.subscriber_columns.email', 'email');
        $query->whereNotNull($emailCol)->where($emailCol, '!=', '');

        $keyCol  = ar_subscriber_key();
        $nameCol = config('autoresponder.subscriber_columns.name', 'name');
        $localeCol = config('autoresponder.subscriber_columns.locale');

        $subscribers = $query->get();

        $synced = 0;

        foreach ($subscribers as $model) {
            ListSubscriber::updateOrCreate(
                [
                    'list_id' => $list->id,
                    'email'   => $model->{$emailCol},
                ],
                [
                    'subscriber_id' => $model->{$keyCol},
                    'name'          => $model->{$nameCol} ?? null,
                    'locale'        => $localeCol ? ($model->{$localeCol} ?? null) : null,
                    'status'        => 'subscribed',
                    'subscribed_at' => now(),
                ],
            );
            $synced++;
        }

        // Mark removed subscribers
        ListSubscriber::where('list_id', $list->id)
            ->where('status', 'subscribed')
            ->whereNotIn('email', $subscribers->pluck($emailCol))
            ->update([
                'status'          => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);

        ar_log()->info('Dynamic list synced', [
            'list_id' => $list->id,
            'synced'  => $synced,
        ]);

        return $synced;
    }

    /**
     * Return the active subscriber count for a list.
     */
    public function getSubscriberCount(MailerList $list): int
    {
        if ($list->type === 'dynamic') {
            return $this->resolveDynamicSubscribers($list)->count();
        }

        return ListSubscriber::where('list_id', $list->id)
            ->where('status', 'subscribed')
            ->count();
    }

    // ── Internal Resolvers ────────────────────────────────────────────

    protected function resolveStaticSubscribers(MailerList $list): Collection
    {
        $rows = ListSubscriber::where('list_id', $list->id)
            ->where('status', 'subscribed')
            ->get();

        $keyCol    = ar_subscriber_key();
        $modelClass = ar_subscriber_model();

        $subscriberIds = $rows->pluck('subscriber_id')->filter()->all();

        // Pre-load actual models for rows that have a subscriber_id
        $models = collect();
        if (! empty($subscriberIds)) {
            $models = $modelClass::whereIn($keyCol, $subscriberIds)
                ->get()
                ->keyBy($keyCol);
        }

        return $rows->map(function (ListSubscriber $row) use ($models) {
            // Return the Eloquent model if it implements Subscribable
            if ($row->subscriber_id && $models->has($row->subscriber_id)) {
                $model = $models->get($row->subscriber_id);
                if ($model instanceof Subscribable) {
                    return $model;
                }
            }

            // Fallback: wrap the ListSubscriber row itself
            return new class ($row) implements Subscribable {
                public function __construct(private readonly ListSubscriber $row) {}
                public function getSubscribableId(): int|string { return $this->row->subscriber_id ?? 0; }
                public function getSubscribableEmail(): string { return $this->row->email; }
                public function getSubscribableName(): string { return $this->row->name ?? $this->row->email; }
                public function getSubscribableLocale(): ?string { return $this->row->locale; }
            };
        });
    }

    protected function resolveDynamicSubscribers(MailerList $list): Collection
    {
        $modelClass = ar_subscriber_model();
        $filters    = $list->filter_config ?? [];

        $query = $modelClass::query();

        foreach ($filters as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        $emailCol = config('autoresponder.subscriber_columns.email', 'email');
        $query->whereNotNull($emailCol)->where($emailCol, '!=', '');

        $results = $query->get();

        // If the model implements Subscribable, return directly
        if ($results->isNotEmpty() && $results->first() instanceof Subscribable) {
            return $results;
        }

        // Otherwise wrap each row
        $keyCol    = ar_subscriber_key();
        $nameCol   = config('autoresponder.subscriber_columns.name', 'name');
        $localeCol = config('autoresponder.subscriber_columns.locale');

        return $results->map(function ($model) use ($emailCol, $keyCol, $nameCol, $localeCol) {
            return new class ($model, $emailCol, $keyCol, $nameCol, $localeCol) implements Subscribable {
                public function __construct(
                    private readonly object $model,
                    private readonly string $emailCol,
                    private readonly string $keyCol,
                    private readonly string $nameCol,
                    private readonly ?string $localeCol,
                ) {}
                public function getSubscribableId(): int|string { return $this->model->{$this->keyCol} ?? 0; }
                public function getSubscribableEmail(): string { return $this->model->{$this->emailCol}; }
                public function getSubscribableName(): string { return $this->model->{$this->nameCol} ?? ''; }
                public function getSubscribableLocale(): ?string { return $this->localeCol ? ($this->model->{$this->localeCol} ?? null) : null; }
            };
        });
    }
}
