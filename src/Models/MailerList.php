<?php

namespace CmrManagement\Autoresponder\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function CmrManagement\Autoresponder\ar_table;

class MailerList extends Model
{
    public function getTable(): string
    {
        return ar_table('mailer_lists');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filter_config' => 'array',
            'subscriber_count' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function listSubscribers(): HasMany
    {
        return $this->hasMany(ListSubscriber::class, 'list_id');
    }

    public function listFilters(): HasMany
    {
        return $this->hasMany(ListFilter::class, 'list_id');
    }

    public function listUsages(): HasMany
    {
        return $this->hasMany(ListUsage::class, 'list_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', 'archived');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function isManual(): bool
    {
        return $this->type === 'manual';
    }

    public function isDynamic(): bool
    {
        return $this->type === 'dynamic';
    }

    public function refreshSubscriberCount(): self
    {
        $this->update([
            'subscriber_count' => $this->listSubscribers()->active()->count(),
        ]);

        return $this;
    }

    // ── Computed Attributes ──────────────────────────────────────────

    protected function statusColor(): Attribute
    {
        return Attribute::get(fn () => match ($this->status) {
            'active' => 'success',
            'archived' => 'gray',
            default => 'secondary',
        });
    }
}
