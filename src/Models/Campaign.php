<?php

namespace CmrManagement\Autoresponder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function CmrManagement\Autoresponder\ar_table;

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

    public function markAsFailed(string $message): self
    {
        $this->update([
            'status' => 'failed',
        ]);

        return $this;
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
