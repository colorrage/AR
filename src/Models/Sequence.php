<?php

namespace CmrManagement\Autoresponder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

use function CmrManagement\Autoresponder\ar_table;

class Sequence extends Model
{
    public function getTable(): string
    {
        return ar_table('sequences');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'entry_filter_config' => 'array',
            'priority' => 'integer',
            'enable_utm_tracking' => 'boolean',
            'throttle_per_minute' => 'integer',
            'quiet_hours_start' => 'datetime:H:i',
            'quiet_hours_end' => 'datetime:H:i',
            'test_mode' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $sequence) {
            if (empty($sequence->slug)) {
                $sequence->slug = Str::slug($sequence->name);
            }
        });
    }

    // ── Relationships ────────────────────────────────────────────────

    public function steps(): HasMany
    {
        return $this->hasMany(Step::class, 'sequence_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'sequence_id');
    }

    public function sendLogs(): HasMany
    {
        return $this->hasMany(SendLog::class, 'autoresponder_id');
    }

    public function creator(): BelongsTo
    {
        $model = config('autoresponder.subscriber_model', 'App\\Models\\User');
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->belongsTo($model, 'created_by', $key);
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeByTrigger(Builder $query, string $triggerType): Builder
    {
        return $query->where('trigger_type', $triggerType);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeOrderByPriority(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('priority', $direction);
    }

    // ── State Checks ─────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    // ── Quiet Hours & Throttle ───────────────────────────────────────

    public function isInQuietHours(): bool
    {
        if (! $this->quiet_hours_start || ! $this->quiet_hours_end) {
            return false;
        }

        $now = Carbon::now();
        $start = Carbon::today()->setTimeFromTimeString(
            $this->quiet_hours_start instanceof Carbon
                ? $this->quiet_hours_start->format('H:i')
                : $this->quiet_hours_start
        );
        $end = Carbon::today()->setTimeFromTimeString(
            $this->quiet_hours_end instanceof Carbon
                ? $this->quiet_hours_end->format('H:i')
                : $this->quiet_hours_end
        );

        if ($end->lessThan($start)) {
            return $now->greaterThanOrEqualTo($start) || $now->lessThan($end);
        }

        return $now->between($start, $end);
    }

    public function getNextAllowedTime(): Carbon
    {
        if (! $this->isInQuietHours()) {
            return Carbon::now();
        }

        $end = Carbon::today()->setTimeFromTimeString(
            $this->quiet_hours_end instanceof Carbon
                ? $this->quiet_hours_end->format('H:i')
                : $this->quiet_hours_end
        );

        if ($end->isPast()) {
            $end->addDay();
        }

        return $end;
    }

    public function isThrottled(): bool
    {
        if (! $this->throttle_per_minute || $this->throttle_per_minute <= 0) {
            return false;
        }

        $recentSends = StepLog::where('sequence_id', $this->id)
            ->where('status', 'sent')
            ->where('sent_at', '>=', Carbon::now()->subMinute())
            ->count();

        return $recentSends >= $this->throttle_per_minute;
    }

    // ── UTM ──────────────────────────────────────────────────────────

    public function getUtmParameters(): array
    {
        if (! $this->enable_utm_tracking) {
            return [];
        }

        return array_filter([
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
        ]);
    }

    // ── Computed Attributes ──────────────────────────────────────────

    protected function activeEnrollmentsCount(): Attribute
    {
        return Attribute::get(fn () => $this->enrollments()->where('state', 'active')->count());
    }

    protected function totalEnrollmentsCount(): Attribute
    {
        return Attribute::get(fn () => $this->enrollments()->count());
    }

    protected function stepsCount(): Attribute
    {
        return Attribute::get(fn () => $this->steps()->count());
    }

    protected function completionRate(): Attribute
    {
        return Attribute::get(function () {
            $total = $this->enrollments()->count();
            if ($total === 0) {
                return 0.0;
            }

            $completed = $this->enrollments()->where('state', 'completed')->count();

            return round(($completed / $total) * 100, 2);
        });
    }

    protected function openRate(): Attribute
    {
        return Attribute::get(function () {
            $total = $this->sendLogs()->where('status', 'sent')->count();
            if ($total === 0) {
                return 0.0;
            }

            $opened = $this->sendLogs()->where('status', 'sent')->whereNotNull('opened_at')->count();

            return round(($opened / $total) * 100, 2);
        });
    }

    protected function clickRate(): Attribute
    {
        return Attribute::get(function () {
            $total = $this->sendLogs()->where('status', 'sent')->count();
            if ($total === 0) {
                return 0.0;
            }

            $clicked = $this->sendLogs()->where('status', 'sent')->whereNotNull('clicked_at')->count();

            return round(($clicked / $total) * 100, 2);
        });
    }

    protected function triggerTypeDisplay(): Attribute
    {
        return Attribute::get(function () {
            $types = config('autoresponder.trigger_types', []);

            return $types[$this->trigger_type] ?? Str::headline($this->trigger_type ?? '');
        });
    }

    protected function statusColor(): Attribute
    {
        return Attribute::get(fn () => match ($this->status) {
            'active' => 'success',
            'paused' => 'warning',
            'draft' => 'gray',
            default => 'secondary',
        });
    }
}
