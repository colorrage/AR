<?php

namespace ColorrageAR\Autoresponder\Events;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Step;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StepSent
{
    use Dispatchable, SerializesModels;

    public Enrollment $enrollment;
    public Step $step;
    public SendLog $sendLog;

    public function __construct(Enrollment $enrollment, Step $step, SendLog $sendLog)
    {
        $this->enrollment = $enrollment;
        $this->step = $step;
        $this->sendLog = $sendLog;
    }
}
