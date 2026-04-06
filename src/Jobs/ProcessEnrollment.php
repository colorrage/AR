<?php

namespace CmrManagement\Autoresponder\Jobs;

use CmrManagement\Autoresponder\Models\Enrollment;
use CmrManagement\Autoresponder\Services\AutoresponderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use function CmrManagement\Autoresponder\ar_log;
use function CmrManagement\Autoresponder\ar_queue;

class ProcessEnrollment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $enrollmentId,
    ) {
        $this->onQueue(ar_queue());
    }

    public function handle(AutoresponderService $autoresponderService): void
    {
        $enrollment = Enrollment::with('sequence')->find($this->enrollmentId);

        if (! $enrollment) {
            ar_log()->warning('ProcessEnrollment: enrollment not found', [
                'enrollment_id' => $this->enrollmentId,
            ]);

            return;
        }

        if (! $enrollment->isActive()) {
            ar_log()->debug('ProcessEnrollment: enrollment is not active', [
                'enrollment_id' => $enrollment->id,
                'state' => $enrollment->state,
            ]);

            return;
        }

        $sequence = $enrollment->sequence;

        if (! $sequence || ! $sequence->isActive()) {
            ar_log()->info('ProcessEnrollment: sequence inactive, cancelling enrollment', [
                'enrollment_id' => $enrollment->id,
                'sequence_id' => $enrollment->sequence_id,
            ]);
            $autoresponderService->cancelEnrollment($enrollment, 'Sequence is no longer active');

            return;
        }

        if ($sequence->isThrottled()) {
            $enrollment->update(['next_run_at' => now()->addMinute()]);
            ar_log()->debug('ProcessEnrollment: throttled, rescheduling +1 min', [
                'enrollment_id' => $enrollment->id,
            ]);

            return;
        }

        if ($sequence->isInQuietHours()) {
            $nextAllowed = $sequence->getNextAllowedTime();
            $enrollment->update(['next_run_at' => $nextAllowed]);
            ar_log()->debug('ProcessEnrollment: quiet hours, rescheduling', [
                'enrollment_id' => $enrollment->id,
                'next_run_at' => $nextAllowed->toDateTimeString(),
            ]);

            return;
        }

        $nextStep = $autoresponderService->getNextStep($enrollment);

        if (! $nextStep) {
            $autoresponderService->completeEnrollment($enrollment);
            ar_log()->info('ProcessEnrollment: no more steps, completing', [
                'enrollment_id' => $enrollment->id,
            ]);

            return;
        }

        if ($autoresponderService->checkStopOnEvent($nextStep, $enrollment)) {
            $autoresponderService->cancelEnrollment(
                $enrollment,
                "Stop event triggered: {$nextStep->stop_sequence_on_event}"
            );
            ar_log()->info('ProcessEnrollment: stop event triggered', [
                'enrollment_id' => $enrollment->id,
                'stop_event' => $nextStep->stop_sequence_on_event,
            ]);

            return;
        }

        SendStepEmail::dispatch($enrollment->id, $nextStep->id);

        ar_log()->debug('ProcessEnrollment: dispatched SendStepEmail', [
            'enrollment_id' => $enrollment->id,
            'step_id' => $nextStep->id,
            'step_number' => $nextStep->step_number,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        ar_log()->error('ProcessEnrollment job failed', [
            'enrollment_id' => $this->enrollmentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
