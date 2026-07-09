<?php

namespace ColorrageAR\Autoresponder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function ColorrageAR\Autoresponder\ar_table;

class Step extends Model
{
    public function getTable(): string
    {
        return ar_table('steps');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'step_number' => 'integer',
            'delay_value' => 'integer',
            'condition_config' => 'array',
            'ab_test_enabled' => 'boolean',
            'ab_weight_a' => 'integer',
            'ab_weight_b' => 'integer',
            'window_start' => 'datetime:H:i',
            'window_end' => 'datetime:H:i',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function abTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'ab_template_id');
    }

    public function stepLogs(): HasMany
    {
        return $this->hasMany(StepLog::class, 'step_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('step_number');
    }

    // ── Delay Calculations ───────────────────────────────────────────

    public function getDelayInSeconds(): int
    {
        return match ($this->delay_unit) {
            'minutes' => $this->delay_value * 60,
            'hours' => $this->delay_value * 3600,
            'days' => $this->delay_value * 86400,
            'weeks' => $this->delay_value * 604800,
            default => $this->delay_value * 60,
        };
    }

    public function calculateSendTime(Carbon $triggerTime, ?Carbon $prevStepSentAt = null): Carbon
    {
        $base = match ($this->delay_type) {
            'from_trigger' => $triggerTime,
            'from_prev_step' => $prevStepSentAt ?? $triggerTime,
            'fixed_time' => $triggerTime,
            default => $triggerTime,
        };

        $sendTime = $base->copy()->addSeconds($this->getDelayInSeconds());

        return $this->adjustForWindow($sendTime);
    }

    public function adjustForWindow(?Carbon $sendTime = null): Carbon
    {
        $sendTime = $sendTime ?? Carbon::now();

        if (! $this->window_start || ! $this->window_end) {
            return $sendTime;
        }

        $startStr = $this->window_start instanceof Carbon
            ? $this->window_start->format('H:i')
            : $this->window_start;
        $endStr = $this->window_end instanceof Carbon
            ? $this->window_end->format('H:i')
            : $this->window_end;

        $windowStart = $sendTime->copy()->setTimeFromTimeString($startStr);
        $windowEnd = $sendTime->copy()->setTimeFromTimeString($endStr);

        if ($sendTime->lessThan($windowStart)) {
            return $windowStart;
        }

        if ($sendTime->greaterThan($windowEnd)) {
            return $windowStart->addDay();
        }

        return $sendTime;
    }

    // ── Navigation ───────────────────────────────────────────────────

    public function isFirstStep(): bool
    {
        return $this->step_number === 1;
    }

    public function isLastStep(): bool
    {
        return ! $this->sequence
            ->steps()
            ->where('step_number', '>', $this->step_number)
            ->exists();
    }

    public function getNextStep(): ?self
    {
        return $this->sequence
            ->steps()
            ->where('step_number', '>', $this->step_number)
            ->orderBy('step_number')
            ->first();
    }

    public function getPreviousStep(): ?self
    {
        return $this->sequence
            ->steps()
            ->where('step_number', '<', $this->step_number)
            ->orderByDesc('step_number')
            ->first();
    }

    // ── A/B Testing ──────────────────────────────────────────────────

    public function selectVariant(Enrollment $enrollment): string
    {
        if (! $this->ab_test_enabled || ! $this->ab_template_id) {
            return 'A';
        }

        if ($enrollment->ab_variant_assigned) {
            return $enrollment->ab_variant_assigned;
        }

        $totalWeight = ($this->ab_weight_a ?? 50) + ($this->ab_weight_b ?? 50);
        $rand = random_int(1, $totalWeight);

        return $rand <= ($this->ab_weight_a ?? 50) ? 'A' : 'B';
    }

    public function getTemplateForVariant(string $variant): ?Template
    {
        if ($variant === 'B' && $this->ab_template_id) {
            return $this->abTemplate;
        }

        return $this->template;
    }

    // ── Conditions ───────────────────────────────────────────────────

    public function hasConditions(): bool
    {
        return $this->condition_type !== null && $this->condition_type !== 'none';
    }

    // ── Computed Attributes ──────────────────────────────────────────

    protected function delayDisplay(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->delay_value || ! $this->delay_unit) {
                return 'Immediately';
            }

            $unit = $this->delay_value === 1
                ? rtrim($this->delay_unit, 's')
                : $this->delay_unit;

            return "{$this->delay_value} {$unit} ({$this->delay_type})";
        });
    }

    protected function conditionDisplay(): Attribute
    {
        return Attribute::get(fn () => match ($this->condition_type) {
            'none', null => 'None',
            'opened_previous' => 'Opened previous email',
            'not_opened_previous' => 'Did NOT open previous email',
            'clicked_previous' => 'Clicked previous email',
            'not_clicked_previous' => 'Did NOT click previous email',
            'custom' => 'Custom: ' . ($this->condition_config['label'] ?? 'custom condition'),
            default => ucfirst(str_replace('_', ' ', $this->condition_type)),
        });
    }

    protected function stats(): Attribute
    {
        return Attribute::get(function () {
            $logs = $this->stepLogs();

            return [
                'sent' => (clone $logs)->where('status', 'sent')->count(),
                'skipped' => (clone $logs)->where('status', 'skipped')->count(),
                'failed' => (clone $logs)->where('status', 'failed')->count(),
                'scheduled' => (clone $logs)->where('status', 'scheduled')->count(),
            ];
        });
    }

    protected function abTestResults(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->ab_test_enabled) {
                return null;
            }

            $aLogs = $this->stepLogs()->where('ab_variant_used', 'A');
            $bLogs = $this->stepLogs()->where('ab_variant_used', 'B');

            return [
                'A' => [
                    'sent' => (clone $aLogs)->where('status', 'sent')->count(),
                    'opened' => (clone $aLogs)->where('status', 'sent')
                        ->whereHas('sendLog', fn ($q) => $q->whereNotNull('opened_at'))
                        ->count(),
                ],
                'B' => [
                    'sent' => (clone $bLogs)->where('status', 'sent')->count(),
                    'opened' => (clone $bLogs)->where('status', 'sent')
                        ->whereHas('sendLog', fn ($q) => $q->whereNotNull('opened_at'))
                        ->count(),
                ],
            ];
        });
    }
}
