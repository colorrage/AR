<?php

namespace ColorrageAR\Autoresponder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function ColorrageAR\Autoresponder\ar_table;

class Campaign extends Model
{
    public function getTable(): string
    {
        return ar_table('campaigns');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filter_params' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'enable_utm_tracking' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function sendLogs(): HasMany
    {
        return $this->hasMany(SendLog::class, 'campaign_id');
    }

    public function mailerLists(): BelongsToMany
    {
        return $this->belongsToMany(MailerList::class, ar_table('list_usages'), 'campaign_id', 'list_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', Carbon::now());
    }

    // ── Status Transitions ───────────────────────────────────────────

    public function markAsSending(): self
    {
        $this->update(['status' => 'sending']);

        return $this;
    }

    public function markAsSent(): self
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => Carbon::now(),
        ]);

        return $this;
    }

    /**
     * Mark the campaign failed, unless it already reached a terminal state.
     *
     * Guarded because a late `failed()` callback from a fan-out job can arrive
     * after the per-email jobs have already completed the campaign; without the
     * guard a fully sent campaign would be relabelled as failed. Written as a
     * conditional update so the check and the write are one statement.
     */
    public function markAsFailed(string $message): self
    {
        static::query()
            ->whereKey($this->getKey())
            ->whereNotIn('status', ['sent', 'cancelled'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $message,
                'updated_at' => Carbon::now(),
            ]);

        return $this->refresh();
    }

    // ── Computed Attributes ──────────────────────────────────────────

    protected function statusColor(): Attribute
    {
        return Attribute::get(fn () => match ($this->status) {
            'draft' => 'gray',
            'scheduled' => 'info',
            'sending' => 'warning',
            'sent' => 'success',
            'failed' => 'danger',
            'cancelled' => 'gray',
            default => 'secondary',
        });
    }

    protected function openRate(): Attribute
    {
        return Attribute::get(function () {
            $sent = $this->sendLogs()->where('status', 'sent')->count();
            if ($sent === 0) {
                return 0.0;
            }

            $opened = $this->sendLogs()->where('status', 'sent')->whereNotNull('opened_at')->count();

            return round(($opened / $sent) * 100, 2);
        });
    }

    protected function clickRate(): Attribute
    {
        return Attribute::get(function () {
            $sent = $this->sendLogs()->where('status', 'sent')->count();
            if ($sent === 0) {
                return 0.0;
            }

            $clicked = $this->sendLogs()->where('status', 'sent')->whereNotNull('clicked_at')->count();

            return round(($clicked / $sent) * 100, 2);
        });
    }
}
