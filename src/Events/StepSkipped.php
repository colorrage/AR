<?php

namespace ColorrageAR\Autoresponder\Events;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Step;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StepSkipped
{
    use Dispatchable, SerializesModels;

    public Enrollment $enrollment;
    public Step $step;
    public string $reason;

    public function __construct(Enrollment $enrollment, Step $step, string $reason)
    {
        $this->enrollment = $enrollment;
        $this->step = $step;
        $this->reason = $reason;
    }
}
