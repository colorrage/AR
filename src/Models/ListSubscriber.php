<?php

namespace CmrManagement\Autoresponder\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function CmrManagement\Autoresponder\ar_table;

class ListSubscriber extends Model
{
    public function getTable(): string
    {
        return ar_table('list_subscribers');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function mailerList(): BelongsTo
    {
        return $this->belongsTo(MailerList::class, 'list_id');
    }

    public function subscriber(): BelongsTo
    {
        $model = config('autoresponder.subscriber_model', 'App\\Models\\User');
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->belongsTo($model, 'subscriber_id', $key);
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
