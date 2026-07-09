<?php

namespace ColorrageAR\Autoresponder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function ColorrageAR\Autoresponder\ar_table;

class Enrollment extends Model
{
    public function getTable(): string
    {
        return ar_table('enrollments');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'current_step_number' => 'integer',
            'next_run_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'last_step_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'paused_at' => 'datetime',
            'exited_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'trigger_data' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }

    public function subscriber(): BelongsTo
    {
        $model = config('autoresponder.subscriber_model', 'App\\Models\\User');
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->belongsTo($model, 'subscriber_id', $key);
    }

    public function stepLogs(): HasMany
    {
        return $this->hasMany(StepLog::class, 'enrollment_id');
    }

    public function sendLogs(): HasMany
    {
        return $this->hasMany(SendLog::class, 'subscriber_id', 'subscriber_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', 'active');
    }

    public function scopeReadyToProcess(Builder $query): Builder
    {
        return $query->where('state', 'active')
            ->where('next_run_at', '<=', Carbon::now());
    }

    public function scopeByState(Builder $query, string $state): Builder
    {
        return $query->where('state', $state);
    }

    public function scopeBySequence(Builder $query, int $sequenceId): Builder
    {
        return $query->where('sequence_id', $sequenceId);
    }

    // ── State Machine ────────────────────────────────────────────────

    public function advance(int $stepNumber, ?Carbon $nextRunAt = null): self
    {
        $this->update([
            'current_step_number' => $stepNumber,
            'last_step_sent_at' => Carbon::now(),
            'next_run_at' => $nextRunAt,
        ]);

        return $this;
    }

    public function complete(): self
    {
        $this->update([
            'state' => 'completed',
            'completed_at' => Carbon::now(),
            'next_run_at' => null,
        ]);

        return $this;
    }

    public function exit(string $reason): self
    {
        $this->update([
            'state' => 'exited',
            'exit_reason' => $reason,
            'exited_at' => Carbon::now(),
            'next_run_at' => null,
        ]);

        return $this;
    }

    public function pause(): self
    {
        $this->update([
            'state' => 'paused',
            'paused_at' => Carbon::now(),
        ]);

        return $this;
    }

    public function resume(): self
    {
        if (! $this->canResume()) {
            return $this;
        }

        $this->update([
            'state' => 'active',
            'paused_at' => null,
            'next_run_at' => Carbon::now(),
        ]);

        return $this;
    }

    public function markUnsubscribed(): self
    {
        $this->update([
            'state' => 'unsubscribed',
            'exit_reason' => 'Subscriber unsubscribed',
            'exited_at' => Carbon::now(),
            'next_run_at' => null,
        ]);

        return $this;
    }

    public function markFailed(string $reason): self
    {
        $this->update([
            'state' => 'failed',
            'exit_reason' => $reason,
            'exited_at' => Carbon::now(),
            'next_run_at' => null,
        ]);

        return $this;
    }

    // ── State Helpers ────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->state === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->state === 'completed';
    }

    public function isPaused(): bool
    {
        return $this->state === 'paused';
    }

    public function canResume(): bool
    {
        return in_array($this->state, ['paused', 'failed']);
    }

    public function getCurrentStep(): ?Step
    {
        return $this->sequence
            ->steps()
            ->where('step_number', $this->current_step_number)
            ->first();
    }

    public function getNextStep(): ?Step
    {
        return $this->sequence
            ->steps()
            ->where('step_number', '>', $this->current_step_number)
            ->orderBy('step_number')
            ->first();
    }

    public function hasCompletedAllSteps(): bool
    {
        $totalSteps = $this->sequence->steps()->active()->count();

        return $this->current_step_number >= $totalSteps;
    }

    public function hasSubscriberUnsubscribed(): bool
    {
        return Unsubscribe::where('email', $this->email)->exists();
    }

    // ── Computed Attributes ──────────────────────────────────────────

    protected function progressPercentage(): Attribute
    {
        return Attribute::get(function () {
            $totalSteps = $this->sequence->steps()->active()->count();
            if ($totalSteps === 0) {
                return 0;
            }

            return round(($this->current_step_number / $totalSteps) * 100, 1);
        });
    }

    protected function stateColor(): Attribute
    {
        return Attribute::get(fn () => match ($this->state) {
            'active' => 'success',
            'paused' => 'warning',
            'completed' => 'info',
            'exited' => 'danger',
            'unsubscribed' => 'gray',
            'failed' => 'danger',
            default => 'secondary',
        });
    }

    protected function stateDisplay(): Attribute
    {
        return Attribute::get(fn () => ucfirst($this->state ?? 'unknown'));
    }

    protected function sentEmailsCount(): Attribute
    {
        return Attribute::get(fn () => $this->stepLogs()->where('status', 'sent')->count());
    }
}
