<?php

namespace ColorrageAR\Autoresponder\Events;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Step;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EnrollmentCompleted
{
    use Dispatchable, SerializesModels;

    public Enrollment $enrollment;
    public Step $step;

    public function __construct(Enrollment $enrollment, Step $step)
    {
        $this->enrollment = $enrollment;
        $this->step = $step;
    }
}
