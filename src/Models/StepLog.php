<?php

namespace ColorrageAR\Autoresponder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function ColorrageAR\Autoresponder\ar_table;

class StepLog extends Model
{
    public function getTable(): string
    {
        return ar_table('step_logs');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class, 'step_id');
    }

    public function sendLog(): BelongsTo
    {
        return $this->belongsTo(SendLog::class, 'send_log_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopeSkipped(Builder $query): Builder
    {
        return $query->where('status', 'skipped');
    }

    public function scopeReadyToSend(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', Carbon::now());
    }

    public function scopeByVariant(Builder $query, string $variant): Builder
    {
        return $query->where('ab_variant_used', $variant);
    }

    // ── Status Transitions ───────────────────────────────────────────

    public function markPending(): self
    {
        $this->update(['status' => 'pending']);

        return $this;
    }

    public function markSent(?int $sendLogId = null): self
    {
        $data = [
            'status' => 'sent',
            'sent_at' => Carbon::now(),
        ];

        if ($sendLogId !== null) {
            $data['send_log_id'] = $sendLogId;
        }

        $this->update($data);

        return $this;
    }

    public function markFailed(string $message): self
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $message,
        ]);

        return $this;
    }

    public function markSkipped(string $reason): self
    {
        $this->update([
            'status' => 'skipped',
            'skip_reason' => $reason,
        ]);

        return $this;
    }

    // ── Status Checks ────────────────────────────────────────────────

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function wasOpened(): bool
    {
        return $this->sendLog && $this->sendLog->opened_at !== null;
    }

    public function wasClicked(): bool
    {
        return $this->sendLog && $this->sendLog->clicked_at !== null;
    }

    // ── Computed Attributes ──────────────────────────────────────────

    protected function statusColor(): Attribute
    {
        return Attribute::get(fn () => match ($this->status) {
            'sent' => 'success',
            'scheduled' => 'info',
            'pending' => 'warning',
            'failed' => 'danger',
            'skipped' => 'gray',
            default => 'secondary',
        });
    }

    protected function trackingData(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->sendLog) {
                return null;
            }

            return [
                'opened_at' => $this->sendLog->opened_at,
                'clicked_at' => $this->sendLog->clicked_at,
                'opens_count' => $this->sendLog->opens_count,
                'clicks_count' => $this->sendLog->clicks_count,
            ];
        });
    }

    protected function emailContent(): Attribute
    {
        return Attribute::get(function () {
            return $this->sendLog?->body_html;
        });
    }
}
