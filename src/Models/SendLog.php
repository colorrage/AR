<?php

namespace CmrManagement\Autoresponder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

use function CmrManagement\Autoresponder\ar_table;

class SendLog extends Model
{
    public function getTable(): string
    {
        return ar_table('send_logs');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'opens_count' => 'integer',
            'clicks_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (empty($log->unsubscribe_token)) {
                $log->unsubscribe_token = Str::random(64);
            }
        });
    }

    // ── Relationships ────────────────────────────────────────────────

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'autoresponder_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class, 'autoresponder_step_id');
    }

    public function subscriber(): BelongsTo
    {
        $model = config('autoresponder.subscriber_model', 'App\\Models\\User');
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->belongsTo($model, 'subscriber_id', $key);
    }

    public function unsubscribe(): HasOne
    {
        return $this->hasOne(Unsubscribe::class, 'email', 'email');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeAutoresponder(Builder $query): Builder
    {
        return $query->whereNotNull('autoresponder_id');
    }

    public function scopeCampaigns(Builder $query): Builder
    {
        return $query->whereNotNull('campaign_id');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    // ── Type Checks ──────────────────────────────────────────────────

    public function isAutoresponderEmail(): bool
    {
        return $this->autoresponder_id !== null;
    }

    public function isCampaignEmail(): bool
    {
        return $this->campaign_id !== null;
    }

    // ── Status Transitions ───────────────────────────────────────────

    public function markAsSent(): self
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => Carbon::now(),
        ]);

        return $this;
    }

    public function markAsFailed(string $message): self
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $message,
        ]);

        return $this;
    }
}
