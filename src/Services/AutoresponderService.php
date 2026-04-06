<?php

namespace CmrManagement\Autoresponder\Services;

use Carbon\Carbon;
use CmrManagement\Autoresponder\Contracts\Subscribable;
use CmrManagement\Autoresponder\Models\Enrollment;
use CmrManagement\Autoresponder\Models\Sequence;
use CmrManagement\Autoresponder\Models\Step;
use CmrManagement\Autoresponder\Models\StepLog;
use CmrManagement\Autoresponder\Models\Unsubscribe;
use Illuminate\Support\Collection;

use function CmrManagement\Autoresponder\ar_log;
use function CmrManagement\Autoresponder\ar_subscriber_model;
use function CmrManagement\Autoresponder\ar_subscriber_key;

class AutoresponderService
{
    // ── Enrollment ────────────────────────────────────────────────────

    /**
     * Enroll a subscriber in every matching sequence for a trigger type + locale.
     */
    public function enroll(string $triggerType, ?Subscribable $subscriber, array $triggerData = []): Collection
    {
        $enrollments = collect();

        $targetLocale = $triggerData['locale']
            ?? $subscriber?->getSubscribableLocale()
            ?? config('app.locale', 'en');

        $sequences = Sequence::active()
            ->byTrigger($triggerType)
            ->where('default_locale', $targetLocale)
            ->orderByPriority()
            ->get();

        foreach ($sequences as $sequence) {
            try {
                $enrollment = $this->enrollInSequence($sequence, $subscriber, $triggerData);
                if ($enrollment) {
                    $enrollments->push($enrollment);
                }
            } catch (\Throwable $e) {
                ar_log()->error('Failed to enroll in sequence', [
                    'sequence_id'  => $sequence->id,
                    'trigger_type' => $triggerType,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return $enrollments;
    }

    /**
     * Enroll a subscriber in a single sequence with full guard checks.
     */
    public function enrollInSequence(
        Sequence $sequence,
        ?Subscribable $subscriber,
        array $triggerData = [],
    ): ?Enrollment {
        $email = $triggerData['email'] ?? $subscriber?->getSubscribableEmail();

        if (! $email) {
            ar_log()->warning('Cannot enroll: no email address', [
                'sequence_id' => $sequence->id,
            ]);
            return null;
        }

        ar_log()->info('Attempting enrollment', [
            'sequence_id' => $sequence->id,
            'email'       => $email,
            'trigger'     => $triggerData['trigger_type'] ?? $sequence->trigger_type,
        ]);

        // Test-mode guard
        if ($sequence->test_mode) {
            $testEmail = config('autoresponder.mail.test_email');
            if (! $testEmail || $email !== $testEmail) {
                ar_log()->debug('Skipping enrollment — test mode active', [
                    'sequence_id' => $sequence->id,
                    'email'       => $email,
                ]);
                return null;
            }
        }

        // Dedup — already active
        if ($this->hasActiveEnrollment($sequence, $email)) {
            ar_log()->warning('Skipping: already enrolled in sequence', [
                'sequence_id' => $sequence->id,
                'email'       => $email,
            ]);
            return null;
        }

        // Unsubscribed
        if ($this->isUnsubscribed($email)) {
            ar_log()->debug('Email is unsubscribed', [
                'sequence_id' => $sequence->id,
                'email'       => $email,
            ]);
            return null;
        }

        // Entry filter — delegated to host via TriggerHandler if needed
        if ($subscriber && $sequence->entry_filter_type && $sequence->entry_filter_type !== 'all') {
            $handlerClass = config("autoresponder.trigger_handlers.{$sequence->trigger_type}");
            if ($handlerClass && class_exists($handlerClass)) {
                $handler = app($handlerClass);
                $eligible = $handler->getSubscribers($sequence);
                if (! $eligible->contains(fn ($s) => $s->getSubscribableEmail() === $email)) {
                    ar_log()->debug('Entry filter not passed', [
                        'sequence_id' => $sequence->id,
                        'email'       => $email,
                    ]);
                    return null;
                }
            }
        }

        // First active step
        $firstStep = $sequence->steps()
            ->where('status', 'active')
            ->orderBy('step_number')
            ->first();

        if (! $firstStep) {
            ar_log()->warning('Sequence has no active steps', [
                'sequence_id' => $sequence->id,
            ]);
            return null;
        }

        $enrolledAt = now();
        $nextRunAt  = $firstStep->calculateSendTime($enrolledAt, null);
        $nextRunAt  = $this->respectQuietHours($sequence, $nextRunAt);

        $abVariant = $this->assignAbVariant($sequence, $email);

        $dedupeKey = md5($sequence->id . '|' . $email . '|' . ($triggerData['trigger_type'] ?? $sequence->trigger_type));

        try {
            $enrollment = Enrollment::create([
                'sequence_id'        => $sequence->id,
                'subscriber_id'      => $subscriber?->getSubscribableId(),
                'email'              => $email,
                'current_step_number' => 0,
                'next_run_at'        => $nextRunAt,
                'state'              => 'active',
                'enrolled_at'        => $enrolledAt,
                'trigger_data'       => $triggerData,
                'ab_variant_assigned' => $abVariant,
                'dedupe_key'         => $dedupeKey,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            ar_log()->info('Duplicate enrollment prevented by dedupe_key', [
                'sequence_id' => $sequence->id,
                'email'       => $email,
                'dedupe_key'  => $dedupeKey,
            ]);
            return null;
        }

        ar_log()->info('Enrolled in autoresponder sequence', [
            'enrollment_id' => $enrollment->id,
            'sequence_id'   => $sequence->id,
            'email'         => $email,
            'next_run_at'   => $nextRunAt->toDateTimeString(),
        ]);

        return $enrollment;
    }

    /**
     * Manually enroll an email address in a sequence by ID.
     */
    public function manualEnroll(
        int $sequenceId,
        string $email,
        ?int $subscriberId = null,
        array $triggerData = [],
    ): ?Enrollment {
        $sequence = Sequence::find($sequenceId);
        if (! $sequence) {
            return null;
        }

        $subscriber = null;
        if ($subscriberId) {
            $modelClass = ar_subscriber_model();
            $subscriber = $modelClass::find($subscriberId);
        }

        $triggerData['email'] = $email;

        return $this->enrollInSequence($sequence, $subscriber, $triggerData);
    }

    public function hasActiveEnrollment(Sequence $sequence, string $email): bool
    {
        return Enrollment::where('sequence_id', $sequence->id)
            ->where('email', $email)
            ->where('state', 'active')
            ->exists();
    }

    public function isUnsubscribed(string $email): bool
    {
        return Unsubscribe::where('email', $email)->exists();
    }

    // ── Step Processing ───────────────────────────────────────────────

    public function getNextStep(Enrollment $enrollment): ?Step
    {
        return $enrollment->sequence->steps()
            ->where('step_number', '>', $enrollment->current_step_number)
            ->where('status', 'active')
            ->orderBy('step_number')
            ->first();
    }

    /**
     * Determine whether a step should actually be sent.
     */
    public function shouldSendStep(Step $step, Enrollment $enrollment): bool
    {
        if (! $enrollment->isActive()) {
            return false;
        }

        if (! $enrollment->sequence->isActive()) {
            return false;
        }

        if ($this->isUnsubscribed($enrollment->email)) {
            $enrollment->markUnsubscribed();
            return false;
        }

        if (! $this->checkStepCondition($step, $enrollment)) {
            return false;
        }

        return true;
    }

    /**
     * Evaluate step conditions — built-in engagement checks + custom via config.
     */
    public function checkStepCondition(Step $step, Enrollment $enrollment): bool
    {
        if (! $step->hasConditions() || $step->condition_type === 'none') {
            return true;
        }

        // Built-in engagement conditions against the previous sent step log
        $previousLog = $enrollment->stepLogs()
            ->where('step_id', '!=', $step->id)
            ->where('status', 'sent')
            ->latest()
            ->first();

        switch ($step->condition_type) {
            case 'opened_previous':
                return $previousLog?->wasOpened() ?? false;

            case 'not_opened_previous':
                return ! ($previousLog?->wasOpened() ?? true);

            case 'clicked_previous':
                return $previousLog?->wasClicked() ?? false;

            case 'not_clicked_previous':
                return ! ($previousLog?->wasClicked() ?? true);
        }

        // Custom condition checker from host config
        $checkerClass = config("autoresponder.conditions.{$step->condition_type}");
        if ($checkerClass && class_exists($checkerClass)) {
            return app($checkerClass)->check($step, $enrollment);
        }

        return true;
    }

    public function selectAbVariant(Step $step, Enrollment $enrollment): string
    {
        if (! $step->ab_test_enabled || ! $step->ab_template_id) {
            return 'A';
        }

        if ($enrollment->ab_variant_assigned) {
            return $enrollment->ab_variant_assigned;
        }

        return $step->selectVariant($enrollment);
    }

    // ── Stop Events ───────────────────────────────────────────────────

    /**
     * Check built-in stop events + custom stop-event checkers from config.
     */
    public function checkStopOnEvent(Step $step, Enrollment $enrollment): bool
    {
        if ($step->stop_sequence_on_event === 'none' || empty($step->stop_sequence_on_event)) {
            return false;
        }

        // Built-in stop events
        switch ($step->stop_sequence_on_event) {
            case 'unsubscribe':
                return $this->isUnsubscribed($enrollment->email);

            case 'click':
                return $enrollment->stepLogs()
                    ->where('status', 'sent')
                    ->get()
                    ->contains(fn (StepLog $log) => $log->wasClicked());
        }

        // Custom stop-event checker from host config
        $checkerClass = config("autoresponder.stop_events.{$step->stop_sequence_on_event}");
        if ($checkerClass && class_exists($checkerClass)) {
            return app($checkerClass)->shouldStop($step, $enrollment);
        }

        return false;
    }

    // ── Scheduling ────────────────────────────────────────────────────

    public function calculateNextRunAt(Step $step, Enrollment $enrollment): Carbon
    {
        $sendTime = $step->calculateSendTime(
            $enrollment->enrolled_at,
            $enrollment->last_step_sent_at,
        );

        return $this->respectQuietHours($enrollment->sequence, $sendTime);
    }

    public function respectQuietHours(Sequence $sequence, Carbon $scheduledTime): Carbon
    {
        if (! $sequence->quiet_hours_start || ! $sequence->quiet_hours_end) {
            return $scheduledTime;
        }

        $start = Carbon::createFromTimeString(
            $sequence->quiet_hours_start instanceof Carbon
                ? $sequence->quiet_hours_start->format('H:i:s')
                : $sequence->quiet_hours_start
        );
        $end = Carbon::createFromTimeString(
            $sequence->quiet_hours_end instanceof Carbon
                ? $sequence->quiet_hours_end->format('H:i:s')
                : $sequence->quiet_hours_end
        );

        $timeOfDay = Carbon::createFromTimeString($scheduledTime->format('H:i:s'));

        // Overnight quiet hours (e.g. 22:00–08:00)
        if ($start->greaterThan($end)) {
            if ($timeOfDay->greaterThanOrEqualTo($start)) {
                return $scheduledTime->copy()->addDay()->setTimeFrom($end);
            }
            if ($timeOfDay->lessThan($end)) {
                return $scheduledTime->copy()->setTimeFrom($end);
            }
        } else {
            // Same-day quiet hours (e.g. 12:00–14:00)
            if ($timeOfDay->between($start, $end)) {
                return $scheduledTime->copy()->setTimeFrom($end);
            }
        }

        return $scheduledTime;
    }

    public function isThrottled(Sequence $sequence): bool
    {
        return $sequence->isThrottled();
    }

    public function getNextAllowedTime(Sequence $sequence): Carbon
    {
        if ($sequence->isInQuietHours()) {
            return $sequence->getNextAllowedTime();
        }

        if ($sequence->isThrottled()) {
            return now()->addMinute();
        }

        return now();
    }

    public function scheduleNextStep(Enrollment $enrollment, Step $currentStep): void
    {
        $nextStep = $currentStep->getNextStep();

        if (! $nextStep) {
            $this->completeEnrollment($enrollment);
            return;
        }

        $nextRunAt = $this->calculateNextRunAt($nextStep, $enrollment);

        $enrollment->update([
            'next_run_at' => $nextRunAt,
        ]);

        ar_log()->debug('Scheduled next step', [
            'enrollment_id' => $enrollment->id,
            'current_step'  => $currentStep->step_number,
            'next_step'     => $nextStep->step_number,
            'next_run_at'   => $nextRunAt->toDateTimeString(),
        ]);
    }

    // ── Enrollment State Transitions ──────────────────────────────────

    public function pauseEnrollment(Enrollment $enrollment): void
    {
        $enrollment->pause();

        ar_log()->info('Enrollment paused', [
            'enrollment_id' => $enrollment->id,
            'sequence_id'   => $enrollment->sequence_id,
            'email'         => $enrollment->email,
        ]);
    }

    public function resumeEnrollment(Enrollment $enrollment): void
    {
        $enrollment->resume();

        ar_log()->info('Enrollment resumed', [
            'enrollment_id' => $enrollment->id,
            'sequence_id'   => $enrollment->sequence_id,
            'email'         => $enrollment->email,
        ]);
    }

    public function cancelEnrollment(Enrollment $enrollment, string $reason = 'manual'): void
    {
        $enrollment->exit($reason);

        ar_log()->info('Enrollment cancelled', [
            'enrollment_id' => $enrollment->id,
            'sequence_id'   => $enrollment->sequence_id,
            'email'         => $enrollment->email,
            'reason'        => $reason,
        ]);
    }

    public function completeEnrollment(Enrollment $enrollment): void
    {
        $enrollment->complete();

        ar_log()->info('Enrollment completed', [
            'enrollment_id' => $enrollment->id,
            'sequence_id'   => $enrollment->sequence_id,
            'email'         => $enrollment->email,
        ]);
    }

    // ── Step Log ──────────────────────────────────────────────────────

    /**
     * Create a step-log entry with deterministic send_key dedup.
     */
    public function createStepLog(
        Enrollment $enrollment,
        Step $step,
        string $status,
        ?Carbon $scheduledAt = null,
        ?string $skipReason = null,
        ?string $abVariant = null,
        ?int $sendLogId = null,
    ): StepLog {
        $sendKey = md5($enrollment->id . '|' . $step->id . '|' . ($abVariant ?? 'A'));

        try {
            return StepLog::create([
                'enrollment_id' => $enrollment->id,
                'sequence_id'   => $enrollment->sequence_id,
                'step_id'       => $step->id,
                'send_log_id'   => $sendLogId,
                'status'        => $status,
                'scheduled_at'  => $scheduledAt ?? now(),
                'skip_reason'   => $skipReason,
                'ab_variant_used' => $abVariant,
                'send_key'      => $sendKey,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            ar_log()->info('Duplicate step send prevented by send_key', [
                'enrollment_id' => $enrollment->id,
                'step_id'       => $step->id,
                'send_key'      => $sendKey,
            ]);

            return StepLog::where('send_key', $sendKey)->first()
                ?? StepLog::where('enrollment_id', $enrollment->id)
                    ->where('step_id', $step->id)
                    ->latest()
                    ->first();
        }
    }

    // ── Queries ───────────────────────────────────────────────────────

    public function getEnrollmentsReadyToProcess(int $limit = 100): Collection
    {
        return Enrollment::readyToProcess()
            ->with(['sequence', 'sequence.steps'])
            ->limit($limit)
            ->get();
    }

    public function getSequencesByTrigger(string $triggerType): Collection
    {
        return Sequence::active()
            ->byTrigger($triggerType)
            ->orderByPriority()
            ->get();
    }

    // ── Internal Helpers ──────────────────────────────────────────────

    protected function assignAbVariant(Sequence $sequence, string $email): ?string
    {
        $hasAbTesting = $sequence->steps()->where('ab_test_enabled', true)->exists();

        if (! $hasAbTesting) {
            return null;
        }

        $hash = crc32($email . $sequence->id);
        return abs($hash) % 100 < 50 ? 'A' : 'B';
    }
}
