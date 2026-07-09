<?php

namespace ColorrageAR\Autoresponder\Concerns;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trait to add to your User model alongside the Subscribable interface.
 * Provides default implementations and relationship helpers.
 */
trait HasSubscriptions
{
    public function getSubscribableId(): int|string
    {
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->{$key};
    }

    public function getSubscribableEmail(): string
    {
        $col = config('autoresponder.subscriber_columns.email', 'email');

        return $this->{$col} ?? '';
    }

    public function getSubscribableName(): string
    {
        $col = config('autoresponder.subscriber_columns.name', 'name');

        return $this->{$col} ?? '';
    }

    public function getSubscribableLocale(): ?string
    {
        $col = config('autoresponder.subscriber_columns.locale');

        return $col ? ($this->{$col} ?? null) : null;
    }

    public function autoresponderEnrollments(): HasMany
    {
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->hasMany(Enrollment::class, 'subscriber_id', $key);
    }

    public function autoresponderSendLogs(): HasMany
    {
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->hasMany(SendLog::class, 'subscriber_id', $key);
    }

    public function autoresponderUnsubscribes(): HasMany
    {
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->hasMany(Unsubscribe::class, 'subscriber_id', $key);
    }
}
